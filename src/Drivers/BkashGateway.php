<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\SupportsPartialRefunds;
use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Data\RefundResult;
use ByteMinus\BdPayGate\Drivers\Concerns\HandlesRefunds;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\GatewayUnavailableException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * bKash Tokenized Checkout (URL based, without agreement).
 *
 * Tokenized V2 by default; set "version" to "v1.2" for merchants still on
 * v1.2.0-beta. Refunds use the V2 refund API on both, as bKash documents.
 *
 * Docs: https://developer.bka.sh/docs/tokenized-without-agreement
 */
class BkashGateway extends AbstractGateway implements SupportsPartialRefunds, SupportsSync
{
    use HandlesRefunds;

    public const DRIVER = 'bkash';

    public const SANDBOX_URL = 'https://tokenized.sandbox.bka.sh';

    public const LIVE_URL = 'https://tokenized.pay.bka.sh';

    /**
     * Grant and refresh together, per hour. One more and bKash blocks the
     * merchant's token calls for an hour.
     */
    public const TOKEN_CALLS_PER_HOUR = 2;

    /** bKash allows at most this many (partial) refunds per transaction. */
    public const MAX_REFUNDS = 10;

    private const V2_PATHS = [
        'grant' => '/v2/tokenized-checkout/auth/grant-token',
        'refresh' => '/v2/tokenized-checkout/auth/refresh-token',
        'create' => '/v2/tokenized-checkout/payment/create',
        'execute' => '/v2/tokenized-checkout/payment/execute',
        'query' => '/v2/tokenized-checkout/query/payment',
        'refund' => '/v2/tokenized-checkout/refund/payment/transaction',
        'refund_status' => '/v2/tokenized-checkout/refund/payment/status',
    ];

    private const V1_PATHS = [
        'grant' => '/v1.2.0-beta/tokenized/checkout/token/grant',
        'refresh' => '/v1.2.0-beta/tokenized/checkout/token/refresh',
        'create' => '/v1.2.0-beta/tokenized/checkout/create',
        'execute' => '/v1.2.0-beta/tokenized/checkout/execute',
        'query' => '/v1.2.0-beta/tokenized/checkout/payment/status',
        // bKash's v1.2.0-beta refund pages point at the V2 refund API.
        'refund' => '/v2/tokenized-checkout/refund/payment/transaction',
        'refund_status' => '/v2/tokenized-checkout/refund/payment/status',
    ];

    /**
     * Execute errors that are a definite decline. Anything else (2056 Invalid
     * Payment State, 2003, 2020, 503, HTTP 5xx...) is ambiguous and stays
     * pending. Every execute error is checked with Query first, so "already
     * completed" (2062, 2068, 2117) completes the payment.
     */
    private const DECLINED = [
        '2006', '2007', '2009', '2010', '2011', '2013', '2014', '2015', '2016', '2017', '2018', '2019',
        '2023', '2029', '2037', '2038', '2039', '2040', '2041', '2043', '2044', '2045', '2046', '2057', '2058',
    ];

    /** Query transactionStatus values that settle a payment as not paid. */
    private const UNPAID_STATES = [
        'Failed' => PaymentStatus::Failed,
        'Declined' => PaymentStatus::Failed,
        'Expired' => PaymentStatus::Failed,
        'Cancelled' => PaymentStatus::Cancelled,
    ];

    public function baseUrl(): string
    {
        return $this->endpoint(self::SANDBOX_URL, self::LIVE_URL);
    }

    /**
     * "v2" (Tokenized V2, the default) or "v1.2" (v1.2.0-beta).
     */
    public function version(): string
    {
        $version = strtolower((string) ($this->config['version'] ?? 'v2'));

        return str_contains($version, '1.2') ? 'v1.2' : 'v2';
    }

    protected function legacy(): bool
    {
        return $this->version() === 'v1.2';
    }

    protected function url(string $operation): string
    {
        return $this->baseUrl().($this->legacy() ? self::V1_PATHS : self::V2_PATHS)[$operation];
    }

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        if ($this->currency() !== 'BDT') {
            throw new GatewayException('bKash only accepts BDT.', $this->name());
        }

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        $payer = $this->clean($customer->phone) ?: $this->clean($reference) ?: $payment->gateway_reference;

        $body = [
            'payerReference' => $payer,
            'callbackURL' => $this->callbackUrl('callback'),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'intent' => 'sale',
            'merchantInvoiceNumber' => $payment->gateway_reference,
        ];

        if ($this->legacy()) {
            $body = ['mode' => (string) ($this->config['mode'] ?? '0011')] + $body;
        }

        [$response, $data] = $this->call('create', $body);
        $paymentId = $this->field($data, 'paymentId', 'paymentID');
        $problem = $this->problem($response, $data);

        $payment->logGatewayResponse('create', $data);

        if ($problem || $paymentId === null || empty($data['bkashURL'])) {
            $payment->save();

            throw new GatewayException(
                'bKash could not create the payment: '.($problem ? $this->describe($problem) : 'unexpected response'),
                $this->name(),
                $data,
            );
        }

        $payment->gateway_payment_id = $paymentId;
        $payment->checkout_url = (string) $data['bkashURL'];
        $payment->save();

        return $payment;
    }

    /**
     * bKash sends the customer back with ?paymentID=&status=success|failure|cancel&signature=.
     * None of it is trusted: success means "execute now", the rest are claims
     * checked with Query Payment first.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $params = $request->all();
        $paymentId = (string) ($params['paymentID'] ?? $params['paymentId'] ?? '');

        if ($paymentId === '') {
            throw new InvalidCallbackException('bKash callback has no paymentID.', $this->name(), $params);
        }

        $payment = $this->findByGatewayPaymentId($paymentId);
        $status = strtolower((string) ($params['status'] ?? ''));

        $this->verifySignature($payment, $status, $params);

        if ($payment->isPaid()) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        if ($status === 'success') {
            return $this->execute($payment);
        }

        $settled = $this->settle($payment, $this->queryPayment($payment));

        if ($settled->changed || ! $settled->payment->isPending()) {
            return $settled;
        }

        $cancelled = $status === 'cancel';

        return $this->transition(
            $payment,
            $cancelled ? PaymentStatus::Cancelled : PaymentStatus::Failed,
            ['failure_reason' => $cancelled ? 'Cancelled by the customer on bKash' : 'bKash reported the payment as failed'],
            'callback',
            $params,
        );
    }

    /**
     * Compare the callback's signature with the one bKash issued for this
     * outcome in the Create Payment response. Skipped when none was issued.
     *
     * @param  array<array-key, mixed>  $params
     */
    protected function verifySignature(Payment $payment, string $status, array $params): void
    {
        if (! ($this->config['verify_signature'] ?? true)) {
            return;
        }

        $expected = $this->expectedSignature($payment, $status);

        if ($expected !== null && ! hash_equals($expected, (string) ($params['signature'] ?? ''))) {
            throw new InvalidCallbackException('bKash callback signature does not match the one issued for this payment.', $this->name(), $params);
        }
    }

    protected function expectedSignature(Payment $payment, string $status): ?string
    {
        $create = $payment->gatewayResponse('create') ?? [];
        $key = match ($status) {
            'success' => 'successCallbackURL',
            'cancel' => 'cancelledCallbackURL',
            default => 'failureCallbackURL',
        };

        if (is_string($create[$key] ?? null)) {
            parse_str((string) parse_url($create[$key], PHP_URL_QUERY), $query);

            if (is_string($query['signature'] ?? null) && $query['signature'] !== '') {
                return $query['signature'];
            }
        }

        return is_string($create['signature'] ?? null) && $create['signature'] !== '' ? $create['signature'] : null;
    }

    /**
     * Execute the payment the customer authorised: this is what moves the
     * money. A paymentID can be executed once, whatever the result, so a
     * second callback (refresh, back button, a concurrent request) queries.
     */
    protected function execute(Payment $payment): CallbackResult
    {
        $lock = Cache::lock('bdpaygate.bkash.execute.'.$payment->getKey(), 60);

        if ($this->executeAttempted($payment) || ! $lock->get()) {
            return $this->settle($payment, $this->queryPayment($payment));
        }

        try {
            $payment->logGatewayResponse('execute_attempt', ['paymentId' => $payment->gateway_payment_id])->save();

            try {
                [$response, $data] = $this->call('execute', [$this->paymentIdKey() => $payment->gateway_payment_id]);
            } catch (GatewayUnavailableException) {
                // "A timeout isn't a failure": ask Query. If that is down too,
                // the exception reaches the controller and the payment stays pending.
                return $this->settle($payment, $this->queryPayment($payment));
            }

            $problem = $this->problem($response, $data);

            if ($problem === null && ($data['transactionStatus'] ?? null) === 'Completed') {
                return $this->complete($payment, $data, 'execute');
            }

            $payment->logGatewayResponse('execute', $data)->save();

            return $this->settle($payment, $this->queryPayment($payment), $problem);
        } finally {
            $lock->release();
        }
    }

    protected function executeAttempted(Payment $payment): bool
    {
        return $payment->gatewayResponse('execute_attempt') !== null;
    }

    /**
     * @return array{response: Response, data: array<array-key, mixed>}
     */
    protected function queryPayment(Payment $payment): array
    {
        if (! $payment->gateway_payment_id) {
            throw new GatewayException('This bKash payment was never created at bKash; nothing to query.', $this->name());
        }

        [$response, $data] = $this->call('query', [$this->paymentIdKey() => $payment->gateway_payment_id]);

        return ['response' => $response, 'data' => $data];
    }

    /**
     * Apply what Query Payment said. Completed completes; a definite decline
     * (from Query, or from the execute call before it) fails; anything else,
     * including "Initiated" right after an execute attempt, stays pending.
     *
     * @param  array{response: Response, data: array<array-key, mixed>}  $query
     * @param  array{code: string, message: string}|null  $executeProblem
     */
    protected function settle(Payment $payment, array $query, ?array $executeProblem = null): CallbackResult
    {
        $data = $query['data'];
        $state = $this->problem($query['response'], $data) === null ? (string) ($data['transactionStatus'] ?? '') : '';

        if ($state === 'Completed') {
            return $this->complete($payment, $data, 'query');
        }

        if (isset(self::UNPAID_STATES[$state])) {
            return $this->transition($payment, self::UNPAID_STATES[$state], ['failure_reason' => 'bKash: '.$state], 'query', $data);
        }

        if ($executeProblem !== null && in_array($executeProblem['code'], self::DECLINED, true)) {
            return $this->transition($payment, PaymentStatus::Failed, ['failure_reason' => $this->describe($executeProblem)], 'query', $data);
        }

        // A paymentID lives 24 hours. Still not Completed after that, it never will be.
        if ($state === 'Initiated' && ! $this->withinLifetime($payment, minutes: 5)) {
            return $this->transition($payment, PaymentStatus::Failed, ['failure_reason' => 'bKash: Expired (paymentID older than 24 hours)'], 'query', $data);
        }

        if ($executeProblem !== null) {
            $data += ['execute_error' => $executeProblem];
        }

        return $this->stillPending($payment, 'query', $data);
    }

    /**
     * A paymentID can be executed for 24 hours after Create Payment.
     */
    protected function withinLifetime(Payment $payment, int $minutes = 0): bool
    {
        $created = $payment->getAttribute($payment->getCreatedAtColumn());

        return ! $created instanceof CarbonInterface || $created->gt(now()->subHours(24)->subMinutes($minutes));
    }

    /**
     * @param  array<array-key, mixed>  $data  An execute or query response saying Completed.
     */
    protected function complete(Payment $payment, array $data, string $step): CallbackResult
    {
        $reportedId = $this->field($data, 'paymentId', 'paymentID');

        if ($reportedId !== null && $reportedId !== $payment->gateway_payment_id) {
            throw new InvalidCallbackException('bKash answered about a different paymentID.', $this->name(), $data);
        }

        try {
            $this->assertAmountMatches($payment, $data['amount'] ?? null, isset($data['currency']) ? (string) $data['currency'] : null);
        } catch (InvalidCallbackException $e) {
            // bKash says the money moved, but not the amount we asked for. Keep it for a human.
            $payment->requires_review = true;
            $payment->logGatewayResponse($step.'_mismatch', $data)->save();

            throw $e;
        }

        return $this->transition($payment, PaymentStatus::Completed, [
            'gateway_transaction_id' => $this->field($data, 'trxId', 'trxID'),
            'failure_reason' => null,
        ], $step, $data);
    }

    /**
     * Ask bKash for the current state. A pending payment the customer verified
     * but whose callback never arrived is executed here (within its 24 hours),
     * unless "execute_on_sync" is false.
     */
    public function sync(Payment $payment): Payment
    {
        if (! $payment->gateway_payment_id || $payment->isPaid()) {
            return $payment;
        }

        $query = $this->queryPayment($payment);
        $data = $query['data'];

        $verifiedButNotExecuted = $payment->isPending()
            && ($this->config['execute_on_sync'] ?? true)
            && ! $this->executeAttempted($payment)
            && $this->problem($query['response'], $data) === null
            && ($data['transactionStatus'] ?? null) === 'Initiated'
            && ($data['verificationStatus'] ?? $data['userVerificationStatus'] ?? null) === 'Complete'
            && $this->withinLifetime($payment);

        $verifiedButNotExecuted ? $this->execute($payment) : $this->settle($payment, $query);

        return $payment->refresh();
    }

    protected function requestRefund(Payment $payment, string $amount, ?string $reason): RefundResult
    {
        if (! $payment->gateway_transaction_id || ! $payment->gateway_payment_id) {
            throw new GatewayException('bKash needs the paymentID and trxID to refund; sync the payment first.', $this->name());
        }

        $refunds = count(array_filter($payment->gateway_response ?? [], fn (array $entry) => $entry['step'] === 'refund'));

        if ($refunds >= self::MAX_REFUNDS) {
            throw new GatewayException('bKash allows at most '.self::MAX_REFUNDS.' refunds per transaction.', $this->name());
        }

        $max = $this->maxRefundable($payment);

        if ($max !== null && (float) $payment->refunded_amount + (float) $amount > (float) $max + 0.001) {
            throw new GatewayException(
                "bKash will refund at most {$max} for this payment (coupon or discount excluded); {$payment->refunded_amount} already refunded.",
                $this->name(),
            );
        }

        $body = [
            'paymentId' => $payment->gateway_payment_id,
            'trxId' => $payment->gateway_transaction_id,
            'refundAmount' => $amount,
            'sku' => mb_substr($this->clean($payment->reference) ?: $payment->gateway_reference, 0, 255),
            'reason' => mb_substr($this->clean($reason ?? '') ?: 'Refund for '.$this->clean($payment->reference), 0, 255),
        ];

        try {
            [$response, $data] = $this->call('refund', $body);
        } catch (GatewayUnavailableException $e) {
            // It may have gone through. Counted as pending so it is not sent
            // twice (bKash also rejects a duplicate within 10 minutes); check
            // refundStatus() to see whether it landed.
            return new RefundResult(
                payment: $payment,
                accepted: true,
                amount: $amount,
                message: 'bKash did not answer in time. Check refundStatus() before refunding again.',
                raw: ['request' => $body, 'timeout' => $e->getMessage()],
                pending: true,
            );
        }

        $problem = $this->problem($response, $data);
        $refundId = $this->field($data, 'refundTrxId', 'refundTrxID');
        $status = (string) ($data['refundTransactionStatus'] ?? $data['transactionStatus'] ?? '');
        $accepted = $problem === null && $refundId !== null;

        return new RefundResult(
            payment: $payment,
            accepted: $accepted,
            amount: $amount,
            refundId: $refundId,
            message: $problem ? $this->describe($problem) : ($status !== '' ? $status : null),
            raw: $data,
            pending: $accepted && $status !== 'Completed',
        );
    }

    /**
     * Every refund bKash holds for this payment, from the Refund Status API.
     * Call it after a refund timed out, before refunding again.
     *
     * @return array{original_trx_id: string|null, refunds: list<array{refund_trx_id: string|null, status: string|null, amount: string|null, completed_at: string|null}>, raw: array<array-key, mixed>}
     */
    public function refundStatus(Payment $payment): array
    {
        if (! $payment->gateway_transaction_id || ! $payment->gateway_payment_id) {
            throw new GatewayException('bKash needs the paymentID and trxID for a refund status.', $this->name());
        }

        [$response, $data] = $this->call('refund_status', [
            'paymentId' => $payment->gateway_payment_id,
            'trxId' => $payment->gateway_transaction_id,
        ]);

        if ($problem = $this->problem($response, $data)) {
            throw new GatewayException('bKash refund status failed: '.$this->describe($problem), $this->name(), $data);
        }

        $refunds = [];

        foreach ((array) ($data['refundTransactions'] ?? []) as $refund) {
            if (is_array($refund)) {
                $refunds[] = [
                    'refund_trx_id' => $this->field($refund, 'refundTrxId', 'refundTrxID'),
                    'status' => $this->field($refund, 'refundTransactionStatus', 'transactionStatus'),
                    'amount' => $this->field($refund, 'refundAmount', 'amount'),
                    'completed_at' => $this->field($refund, 'completedTime'),
                ];
            }
        }

        return [
            'original_trx_id' => $this->field($data, 'originalTrxId', 'originalTrxID'),
            'refunds' => $refunds,
            'raw' => $data,
        ];
    }

    /**
     * maxRefundableAmount from the Completed execute/query response. Lower
     * than the amount when a coupon was applied.
     */
    protected function maxRefundable(Payment $payment): ?string
    {
        foreach (array_reverse($payment->gateway_response ?? []) as $entry) {
            $payload = $entry['payload'];

            if (($payload['transactionStatus'] ?? null) === 'Completed' && is_numeric($payload['maxRefundableAmount'] ?? null)) {
                return number_format((float) $payload['maxRefundableAmount'], 2, '.', '');
            }
        }

        return null;
    }

    /**
     * An authenticated API call. If bKash rejects the token, it is dropped
     * and the call is retried once with a fresh one.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: Response, 1: array<array-key, mixed>}
     */
    protected function call(string $operation, array $body, bool $retry = true): array
    {
        $token = $this->token();

        $response = $this->send($this->http()->withHeaders([
            'Authorization' => $token,
            'X-APP-Key' => $this->credential('app_key'),
        ]), 'POST', $this->url($operation), $body);

        $data = $this->decode($response);

        if ($retry && $this->isAuthError($response, $data)) {
            // Only drop it if nobody has replaced it meanwhile.
            if (Cache::get($this->tokenCacheKey($this->tokenKey('id_token'))) === $token) {
                $this->forgetToken($this->tokenKey('id_token'));
            }

            return $this->call($operation, $body, false);
        }

        return [$response, $data];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function isAuthError(Response $response, array $data): bool
    {
        if (in_array($response->status(), [401, 403], true)) {
            return true;
        }

        $code = $this->problem($response, $data)['code'] ?? null;

        if ($code === '2079') {
            return true;
        }

        $message = strtolower((string) ($data['message'] ?? $data['Message'] ?? ''));

        return $code === null && $message !== '' && (str_contains($message, 'unauthorized') || str_contains($message, 'token'));
    }

    /**
     * The id_token, from cache. When it has expired the refresh token is used,
     * and only without one (or if bKash refuses it) is a new token granted.
     */
    public function token(): string
    {
        return $this->rememberToken($this->tokenKey('id_token'), function (): array {
            $refreshKey = $this->tokenCacheKey($this->tokenKey('refresh_token'));
            $refreshToken = Cache::get($refreshKey);
            $data = null;

            if (is_string($refreshToken) && $refreshToken !== '') {
                try {
                    $data = $this->tokenRequest('refresh', ['refresh_token' => $refreshToken]);
                } catch (GatewayUnavailableException $e) {
                    throw $e;
                } catch (GatewayException) {
                    Cache::forget($refreshKey);
                }
            }

            $data ??= $this->tokenRequest('grant');

            if (is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '') {
                // Valid 30 days on V2, 28 on v1.2.0-beta.
                Cache::put($refreshKey, $data['refresh_token'], now()->addDays(27));
            }

            $expiresIn = (int) ($data['expires_in'] ?? 3600);

            // bKash asks for a refresh around minute 50 of the hour.
            return [(string) $data['id_token'], $expiresIn > 1200 ? $expiresIn - 600 : intdiv($expiresIn, 2)];
        });
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<array-key, mixed>
     */
    protected function tokenRequest(string $operation, array $extra = []): array
    {
        $this->countTokenCall();

        $response = $this->send($this->http()->withHeaders([
            'username' => $this->credential('username'),
            'password' => $this->credential('password'),
        ]), 'POST', $this->url($operation), [
            'app_key' => $this->credential('app_key'),
            'app_secret' => $this->credential('app_secret'),
        ] + $extra);

        $data = $this->decode($response);
        $problem = $this->problem($response, $data);

        if ($problem !== null || ! is_string($data['id_token'] ?? null) || $data['id_token'] === '') {
            throw new GatewayException(
                "bKash token {$operation} failed: ".($problem ? $this->describe($problem) : 'no id_token in the response'),
                $this->name(),
                $data,
            );
        }

        return $data;
    }

    /**
     * Refuse a third grant/refresh within an hour rather than get the merchant
     * blocked for one. Treated as "unavailable": payments stay pending.
     */
    protected function countTokenCall(): void
    {
        $key = $this->tokenCacheKey($this->tokenKey('calls'));
        $now = now()->getTimestamp();
        $calls = array_values(array_filter((array) Cache::get($key, []), fn ($at) => is_int($at) && $at > $now - 3600));

        if (count($calls) >= self::TOKEN_CALLS_PER_HOUR) {
            $retryAt = now()->setTimestamp(min($calls) + 3600)->toDateTimeString();

            throw new GatewayUnavailableException(
                'bKash allows '.self::TOKEN_CALLS_PER_HOUR." token calls per hour and a third blocks the merchant for an hour. Not calling again before {$retryAt}.",
                $this->name(),
            );
        }

        $calls[] = $now;
        Cache::put($key, $calls, 3600);
    }

    protected function tokenKey(string $kind): string
    {
        return $kind.':'.$this->version().':'.($this->config['app_key'] ?? '');
    }

    protected function paymentIdKey(): string
    {
        return $this->legacy() ? 'paymentID' : 'paymentId';
    }

    /**
     * The error in a response, in either version's shape: V2 errors carry
     * externalCode/errorMessageEn and successes have no statusCode; v1.2.0-beta
     * successes have statusCode "0000" and errors statusCode/statusMessage or
     * errorCode/errorMessage.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{code: string, message: string}|null
     */
    protected function problem(Response $response, array $data): ?array
    {
        $code = $this->field($data, 'externalCode', 'errorCode');

        if ($code === null && isset($data['statusCode']) && (string) $data['statusCode'] !== '0000') {
            $code = (string) $data['statusCode'];
        }

        if ($code === null && ! $response->successful()) {
            $code = 'HTTP '.$response->status();
        }

        if ($code === null) {
            return null;
        }

        return [
            'code' => $code,
            'message' => $this->field($data, 'errorMessageEn', 'errorMessage', 'statusMessage', 'message', 'Message') ?? 'no message',
        ];
    }

    /**
     * @param  array{code: string, message: string}  $problem
     */
    protected function describe(array $problem): string
    {
        return mb_substr($problem['code'].': '.$problem['message'], 0, 255);
    }

    /**
     * The first non-empty scalar among the given keys, as a string.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function field(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /**
     * bKash rejects <, > and & in references, and anything over 255 characters.
     */
    protected function clean(string $value): string
    {
        return mb_substr(trim(str_replace(['<', '>', '&'], '', $value)), 0, 255);
    }
}
