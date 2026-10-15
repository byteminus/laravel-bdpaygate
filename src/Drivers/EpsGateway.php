<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\GatewayUnavailableException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EPS (Easy Payment System) hosted checkout: cards, internet banking and most
 * wallets (bKash, Nagad, Rocket, OK Wallet, ...).
 *
 * EXPERIMENTAL. EPS publishes no API reference; this driver is built from
 * EPS's own SDKs on github.com/EPS-PG. Every call carries an "x-hash" header,
 * base64(HMAC-SHA512(hash key, value)), where the signed value depends on the
 * endpoint: the username for GetToken, the merchant transaction id for
 * InitializeEPS and CheckMerchantTransactionStatus.
 *
 * Nothing EPS sends to the browser is signed, and its IPN is encrypted with a
 * key whose format is undocumented. Every callback is therefore only a reason
 * to ask CheckMerchantTransactionStatus, which is the source of truth.
 */
class EpsGateway extends AbstractGateway implements SupportsSync
{
    public const DRIVER = 'eps';

    public const SANDBOX_URL = 'https://sandboxpgapi.eps.com.bd';

    public const LIVE_URL = 'https://pgapi.eps.com.bd';

    /** Status words EPS and its SDKs use, compared lowercase. */
    private const PAID = ['success', 'successful', 'succeeded', 'completed', 'complete', 'paid'];

    private const FAILED = ['failed', 'failure', 'fail', 'declined', 'rejected'];

    private const CANCELLED = ['cancel', 'canceled', 'cancelled', 'aborted', 'abort'];

    public function baseUrl(): string
    {
        return $this->endpoint(self::SANDBOX_URL, self::LIVE_URL);
    }

    /**
     * base64(HMAC-SHA512(hash key, value)): the x-hash header.
     */
    public function hash(string $value): string
    {
        return base64_encode(hash_hmac('sha512', $value, $this->credential('hash_key'), true));
    }

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        if ($this->currency() !== 'BDT') {
            throw new GatewayException('EPS only accepts BDT.', $this->name());
        }

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);
        $transactionId = $payment->gateway_reference;

        $body = array_filter([
            'merchantId' => $this->credential('merchant_id'),
            'storeId' => $this->credential('store_id'),
            'deviceTypeId' => $this->config['device_type_id'] ?? null,
            // EPS's own plugin suffixes the order id per attempt; a retried reference must not collide.
            'CustomerOrderId' => mb_substr($payment->reference, 0, 40).'-'.substr($transactionId, -6),
            'merchantTransactionId' => $transactionId,
            'transactionTypeId' => 1,
            'financialEntityId' => 0,
            'transitionStatusId' => 0,
            'totalAmount' => (float) $payment->amount,
            'ipAddress' => (string) ($meta['ip_address'] ?? request()->ip() ?? '0.0.0.0'),
            'version' => '1',
            'transactionDate' => now()->toIso8601String(),
            'successUrl' => $this->callbackUrl('success'),
            'failUrl' => $this->callbackUrl('fail'),
            'cancelUrl' => $this->callbackUrl('cancel'),
            'customerName' => $customer->name,
            'customerEmail' => $customer->email ?? 'noemail@example.com',
            'CustomerAddress' => $customer->address ?? $customer->city,
            'CustomerAddress2' => '',
            'CustomerCity' => $customer->city,
            'CustomerState' => (string) ($meta['state'] ?? $customer->city),
            'CustomerPostcode' => (string) ($customer->postcode ?: ($meta['postcode'] ?? '1000')),
            'CustomerCountry' => 'BD',
            'CustomerPhone' => $customer->phone,
            'ValueA' => $payment->id,
            'ValueB' => $payment->reference,
            'ShippingMethod' => 'NO',
            'NoOfItem' => '1',
            'ProductName' => $description ?? $payment->reference,
            'ProductProfile' => (string) ($meta['profile'] ?? 'general'),
            'ProductCategory' => (string) ($meta['category'] ?? 'general'),
            'ProductList' => [],
        ], fn ($value) => $value !== null);

        $response = $this->api('POST', '/v1/EPSEngine/InitializeEPS', $transactionId, $body);
        $data = $this->decode($response);
        $redirect = $this->field($data, 'RedirectURL');

        if (! $response->successful() || ! is_string($redirect) || $redirect === '' || $this->field($data, 'ErrorCode') !== null) {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException(
                'EPS could not initialise the payment: '.($this->field($data, 'ErrorMessage') ?? 'HTTP '.$response->status()),
                $this->name(),
                $data,
            );
        }

        $epsId = $this->field($data, 'TransactionId');
        $payment->gateway_payment_id = is_scalar($epsId) ? (string) $epsId : null;
        $payment->checkout_url = $redirect;
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $input = $this->normaliseKeys($request->all());
        $transactionId = $this->field($input, 'MerchantTransactionId', 'merchant_transaction_id');

        if ($outcome === 'ipn' && ! is_scalar($transactionId)) {
            return $this->sweep($input);
        }

        $payment = $this->findByGatewayReference(is_scalar($transactionId) ? (string) $transactionId : '');

        return $this->confirm($payment, $outcome, $input);
    }

    public function sync(Payment $payment): Payment
    {
        $this->confirm($payment, 'sync');

        return $payment->refresh();
    }

    /**
     * Ask EPS what happened and apply it. The redirect (or IPN) only decides
     * whether a confirmed non-payment reads as cancelled or failed.
     *
     * @param  array<string, mixed>  $claim  What the browser or IPN said, kept for the log.
     */
    protected function confirm(Payment $payment, string $step, array $claim = [], bool $retry = true): CallbackResult
    {
        if ($payment->isPaid()) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        [$state, $data] = $this->status($payment, $retry);
        $data += $claim === [] ? [] : ['callback' => $claim];

        return match ($state) {
            'paid' => $this->complete($payment, $data, $step),
            'failed', 'cancelled' => $this->transition(
                $payment,
                $state === 'cancelled' || $step === 'cancel' ? PaymentStatus::Cancelled : PaymentStatus::Failed,
                ['failure_reason' => $this->reason($data)],
                $step,
                $data,
            ),
            default => $this->stillPending($payment, $step, $data),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function complete(Payment $payment, array $data, string $step): CallbackResult
    {
        $amount = $this->field($data, 'TotalAmount', 'totalAmount', 'amount');
        $this->assertAmountMatches($payment, is_scalar($amount) ? (string) $amount : null);

        $epsId = $this->field($data, 'EpsTransactionId', 'EPSTransactionId', 'TransactionId');

        return $this->transition($payment, PaymentStatus::Completed, array_filter([
            'gateway_payment_id' => is_scalar($epsId) ? (string) $epsId : null,
            'gateway_transaction_id' => is_scalar($epsId) ? (string) $epsId : null,
        ]), $step, $data);
    }

    /**
     * CheckMerchantTransactionStatus by our merchant transaction id. EPS answers
     * 404 for a short while after a payment, so 404 is retried and then read as
     * "not known yet": pending, never failed.
     *
     * @return array{0: 'paid'|'failed'|'cancelled'|'pending', 1: array<array-key, mixed>}
     */
    protected function status(Payment $payment, bool $retry = true): array
    {
        $attempts = $retry ? max(1, (int) ($this->config['status_attempts'] ?? 3)) : 1;
        $delay = max(0, (int) ($this->config['status_retry_delay'] ?? 1500));

        $ask = fn (): Response => $this->api('GET', '/v1/EPSEngine/CheckMerchantTransactionStatus', $payment->gateway_reference, [
            'merchantTransactionId' => $payment->gateway_reference,
        ]);

        $response = $ask();

        for ($attempt = 2; $attempt <= $attempts && $response->status() === 404; $attempt++) {
            if ($delay > 0) {
                usleep($delay * 1000);
            }

            $response = $ask();
        }

        $data = $this->decode($response);

        if ($response->serverError()) {
            throw new GatewayUnavailableException("EPS status API answered HTTP {$response->status()}.", $this->name(), $data);
        }

        if (! $response->successful()) {
            return ['pending', $data + ['http_status' => $response->status()]];
        }

        // Some EPS samples nest the result under "data".
        $nested = $this->field($data, 'data');
        $fields = is_array($nested) ? $data + $nested : $data;

        $reported = $this->field($fields, 'MerchantTransactionId', 'merchantTransactionId');

        if (is_scalar($reported) && (string) $reported !== $payment->gateway_reference) {
            throw new InvalidCallbackException('EPS status API returned a different merchant transaction id.', $this->name(), $data);
        }

        $status = strtolower(trim((string) ($this->field($fields, 'Status', 'transactionStatus') ?? '')));

        return [match (true) {
            in_array($status, self::PAID, true) => 'paid',
            in_array($status, self::CANCELLED, true) => 'cancelled',
            in_array($status, self::FAILED, true) => 'failed',
            default => 'pending',
        }, $fields];
    }

    /**
     * An IPN that does not name the transaction in plain text (EPS encrypts it,
     * and we do not decrypt it). Use it as a nudge: ask EPS about this
     * gateway's recent pending payments.
     *
     * @param  array<string, mixed>  $input
     */
    protected function sweep(array $input): CallbackResult
    {
        $pending = Payment::query()
            ->where('gateway', $this->name())
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '>=', now()->subMinutes((int) ($this->config['ipn_window'] ?? 120)))
            ->latest()
            ->limit(max(1, (int) ($this->config['ipn_sweep_limit'] ?? 10)))
            ->get();

        if ($pending->isEmpty()) {
            throw new InvalidCallbackException('EPS IPN received, but no recent pending EPS payment to confirm.', $this->name(), $input);
        }

        // The IPN URL is public and this IPN is unauthenticated: sweep at most
        // once a minute, without 404 retries, so it cannot be used to make us
        // hammer EPS or hold workers. Anything missed is settled by bdpaygate:sync.
        if (! Cache::add('bdpaygate.eps.sweep.'.$this->name(), true, 60)) {
            return new CallbackResult($pending->first(), PaymentStatus::Pending, changed: false);
        }

        $results = [];

        foreach ($pending as $payment) {
            try {
                $results[] = $this->confirm($payment, 'ipn', [], retry: false);
            } catch (GatewayUnavailableException $e) {
                throw $e;
            } catch (GatewayException $e) {
                // One bad answer (an amount mismatch, say) must not stop the others.
                Log::warning('BdPayGate could not confirm an EPS payment during an IPN sweep', [
                    'gateway' => $this->name(),
                    'gateway_reference' => $payment->gateway_reference,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        foreach ($results as $result) {
            if ($result->changed && $result->status === PaymentStatus::Completed) {
                return $result;
            }
        }

        foreach ($results as $result) {
            if ($result->changed) {
                return $result;
            }
        }

        return $results[0] ?? new CallbackResult($pending->first(), PaymentStatus::Pending, changed: false);
    }

    /**
     * A signed, authenticated EPS call. A 401 means the cached token expired:
     * fetch a new one and try once more (the request was rejected, not run).
     *
     * @param  array<string, mixed>  $data
     */
    protected function api(string $method, string $path, string $signed, array $data): Response
    {
        $call = fn (): Response => $this->send(
            $this->http()->asJson()->withHeaders([
                'x-hash' => $this->hash($signed),
                'Authorization' => 'Bearer '.$this->token(),
            ]),
            $method,
            $this->baseUrl().$path,
            $data,
        );

        $response = $call();

        if ($response->status() === 401) {
            $this->forgetToken($this->tokenKey());
            $response = $call();
        }

        return $response;
    }

    /**
     * A JWT from /v1/Auth/GetToken (x-hash signs the username), cached until it expires.
     */
    public function token(): string
    {
        return $this->rememberToken($this->tokenKey(), function (): array {
            $username = $this->credential('username');

            $response = $this->send(
                $this->http()->asJson()->withHeaders(['x-hash' => $this->hash($username)]),
                'POST',
                $this->baseUrl().'/v1/Auth/GetToken',
                ['userName' => $username, 'password' => $this->credential('password')],
            );

            $data = $this->decode($response);
            $token = $this->field($data, 'token');

            if (! is_string($token) || $token === '') {
                throw new GatewayException(
                    'EPS token request failed: '.($this->field($data, 'errorMessage') ?? 'HTTP '.$response->status()),
                    $this->name(),
                    $data,
                );
            }

            return [$token, $this->tokenTtl($token, $this->field($data, 'expireDate'))];
        });
    }

    protected function tokenKey(): string
    {
        return 'eps:'.($this->config['username'] ?? '').':'.($this->config['merchant_id'] ?? '');
    }

    /**
     * Seconds to cache a token: until expireDate (or the JWT's exp), less a minute.
     */
    protected function tokenTtl(string $token, mixed $expireDate): int
    {
        $expires = null;

        if (is_string($expireDate) && $expireDate !== '') {
            try {
                $expires = Carbon::parse($expireDate)->getTimestamp();
            } catch (Throwable) {
                $expires = null;
            }
        }

        if ($expires === null) {
            $claims = json_decode((string) base64_decode(strtr(explode('.', $token.'..')[1], '-_', '+/')), true);
            $expires = is_array($claims) && is_numeric($claims['exp'] ?? null) ? (int) $claims['exp'] : null;
        }

        $fallback = (int) ($this->config['token_ttl'] ?? 300);

        return $expires === null ? $fallback : max(30, $expires - time() - 60);
    }

    /**
     * EPS's SDKs send merchant transaction ids as digits only (at least 10).
     * Use 20 digits: a timestamp plus 8 random digits.
     */
    protected function newGatewayReference(): string
    {
        return now()->format('ymdHis').str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * A value by any of the given keys, ignoring case; null and "" count as missing.
     * EPS mixes PascalCase and camelCase and sends literal nulls.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function field(array $data, string ...$names): mixed
    {
        $lower = array_change_key_case($data, CASE_LOWER);

        foreach ($names as $name) {
            $value = $lower[strtolower($name)] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * EPS sometimes HTML-escapes the separators it appends to return URLs,
     * leaving keys such as "amp;MerchantTransactionId".
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normaliseKeys(array $input): array
    {
        $clean = [];

        foreach ($input as $key => $value) {
            $clean[(string) preg_replace('/^(amp;)+/i', '', (string) $key)] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function reason(array $data): ?string
    {
        $callback = is_array($data['callback'] ?? null) ? $data['callback'] : [];
        $reason = $this->field($data, 'ErrorMessage', 'Message') ?? $this->field($callback, 'Message', 'ErrorMessage', 'Status');

        return is_scalar($reason) ? mb_substr((string) $reason, 0, 255) : null;
    }
}
