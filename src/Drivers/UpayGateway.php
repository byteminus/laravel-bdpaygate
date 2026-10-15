<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\SupportsRefunds;
use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Data\RefundResult;
use ByteMinus\BdPayGate\Drivers\Concerns\HandlesRefunds;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Upay (UCB Fintech) merchant payment gateway.
 *
 * BETA: built from public information and not yet verified against a merchant
 * sandbox. Upay hands out its API document at onboarding; check this driver
 * against it.
 *
 * Flow: merchant-auth (JWT) -> merchant-payment-init -> redirect to
 * gateway_url -> unsigned browser redirect with status and invoice_id ->
 * single-payment-status. Refunds go through the bulk refund endpoint, full
 * amount only, and may be answered "under process".
 */
class UpayGateway extends AbstractGateway implements SupportsRefunds, SupportsSync
{
    use HandlesRefunds;

    public const DRIVER = 'upay';

    public const SANDBOX_URL = 'https://uat-pg.upay.systems';

    public const LIVE_URL = 'https://pg.upaysystem.com';

    private const CANCELLED = ['cancelled', 'canceled'];

    private const FAILED = ['failed', 'expired'];

    public function baseUrl(): string
    {
        return $this->endpoint(self::SANDBOX_URL, self::LIVE_URL);
    }

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        if ($this->currency() !== 'BDT') {
            throw new GatewayException('Upay only accepts BDT.', $this->name());
        }

        $merchant = [
            'merchant_id' => $this->credential('merchant_id'),
            'merchant_name' => $this->credential('merchant_name'),
            'merchant_code' => $this->credential('merchant_code'),
            'merchant_country_code' => (string) ($this->config['merchant_country_code'] ?? 'BD'),
            'merchant_city' => (string) ($this->config['merchant_city'] ?? 'Dhaka'),
            'merchant_category_code' => $this->credential('merchant_category_code'),
            'merchant_mobile' => $this->credential('merchant_mobile'),
        ];

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        // Upay sends the customer back with only invoice_id, and looks payments
        // up by txn_id: use the per-attempt id for both. The "txn" query param
        // gives Upay's appended "&status=..&invoice_id=.." a query to join.
        $response = $this->call('POST', '/payment/merchant-payment-init/', [
            'date' => now('Asia/Dhaka')->format('Y-m-d'),
            'txn_id' => $payment->gateway_reference,
            'invoice_id' => $payment->gateway_reference,
            'amount' => $payment->amount,
            'transaction_currency_code' => 'BDT',
            'redirect_url' => $this->callbackUrl('callback', ['txn' => $payment->gateway_reference]),
            'additional_info' => array_filter([
                'reference' => $payment->reference,
                'description' => $description,
            ], fn ($value) => $value !== null),
        ] + $merchant);

        $data = $this->decode($response);
        $body = is_array($data['data'] ?? null) ? $data['data'] : [];
        $gatewayUrl = $body['gateway_url'] ?? null;

        if (! $response->successful() || ! is_string($gatewayUrl) || $gatewayUrl === '') {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException('Upay could not create the payment: '.$this->errorMessage($data, $response->status()), $this->name(), $data);
        }

        $payment->gateway_payment_id = isset($body['trx_id']) ? (string) $body['trx_id'] : null;
        $payment->checkout_url = $gatewayUrl;
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    /**
     * The redirect carries "status" and "invoice_id", nothing signed. It only
     * says which payment to look at; single-payment-status decides.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $params = $this->callbackParams($request);
        $invoiceId = is_string($params['invoice_id'] ?? null) && $params['invoice_id'] !== ''
            ? $params['invoice_id']
            : (string) (is_string($params['txn'] ?? null) ? $params['txn'] : '');

        $payment = $this->findByGatewayReference($invoiceId);

        if ($payment->isPaid()) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        return $this->query($payment, $outcome);
    }

    public function sync(Payment $payment): Payment
    {
        $this->query($payment, 'sync');

        return $payment->refresh();
    }

    /**
     * Ask Upay for the payment's status and apply it. Anything that is not a
     * clear answer leaves the payment pending.
     */
    protected function query(Payment $payment, string $step): CallbackResult
    {
        $response = $this->call('GET', '/payment/single-payment-status/'.rawurlencode($payment->gateway_reference).'/');
        $data = $this->decode($response);
        $body = is_array($data['data'] ?? null) ? $data['data'] : [];

        // Not found (PNF4012), an error envelope, or no status: not an answer.
        if (! $response->successful() || ! isset($body['status'])) {
            return $this->stillPending($payment, $step, $data + ['http_status' => $response->status()]);
        }

        if ((string) ($body['txn_id'] ?? '') !== $payment->gateway_reference) {
            throw new InvalidCallbackException('Upay status returned a different txn_id.', $this->name(), $data);
        }

        $status = strtolower((string) $body['status']);
        $attributes = isset($body['trx_id']) ? ['gateway_payment_id' => (string) $body['trx_id']] : [];

        if ($status === 'success') {
            $this->assertAmountMatches($payment, is_scalar($body['amount'] ?? null) ? (string) $body['amount'] : null);

            return $this->transition($payment, PaymentStatus::Completed, $attributes + [
                'gateway_transaction_id' => isset($body['trx_id']) ? (string) $body['trx_id'] : null,
            ], $step, $data);
        }

        $reason = 'Upay: '.$status;

        return match (true) {
            in_array($status, self::CANCELLED, true) => $this->transition($payment, PaymentStatus::Cancelled, $attributes + ['failure_reason' => $reason], $step, $data),
            in_array($status, self::FAILED, true) => $this->transition($payment, PaymentStatus::Failed, $attributes + ['failure_reason' => $reason], $step, $data),
            default => $this->stillPending($payment, $step, $data),
        };
    }

    /**
     * Upay's bulk refund endpoint with a single entry. A refund can be answered
     * "under process": it was taken and will settle later, so it counts as
     * accepted and pending. Upay documents no refund status endpoint.
     */
    protected function requestRefund(Payment $payment, string $amount, ?string $reason): RefundResult
    {
        $txnId = $payment->gateway_reference;
        $response = $this->call('POST', '/payment/bulk/refund/', json: [['txn_id' => $txnId, 'refund_amount' => $amount]]);
        $data = $this->decode($response);
        $body = is_array($data['data'] ?? null) ? $data['data'] : [];

        foreach ((array) ($body['success_list'] ?? []) as $item) {
            if ($this->entryTxnId($item) === $txnId) {
                return new RefundResult($payment, accepted: true, amount: $amount, message: $this->errorMessage($data, $response->status()), raw: $data);
            }
        }

        $message = $this->failureFor($body['failed_list'] ?? null, $txnId) ?? $this->errorMessage($data, $response->status());
        $underProcess = str_contains(strtolower($message), 'under process');

        return new RefundResult(
            payment: $payment,
            accepted: $underProcess,
            amount: $amount,
            message: $message,
            raw: $data,
            pending: $underProcess,
        );
    }

    /**
     * Send an authorised request, re-authenticating once if the token was refused.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $json  A JSON array body (bulk endpoints).
     */
    protected function call(string $method, string $path, array $data = [], ?array $json = null): Response
    {
        $attempt = function () use ($method, $path, $data, $json): Response {
            $request = $this->http()->asJson()->withHeaders(['Authorization' => 'UPAY '.$this->token()]);

            if ($json !== null) {
                $request = $request->withBody((string) json_encode($json, JSON_UNESCAPED_SLASHES), 'application/json');
            }

            return $this->send($request, $method, $this->baseUrl().$path, $data);
        };

        $response = $attempt();

        if (in_array($response->status(), [401, 403], true)) {
            $this->forgetToken($this->tokenKey());
            $response = $attempt();
        }

        return $response;
    }

    /**
     * The merchant-auth JWT. Upay documents no expiry, so it is cached for
     * "token_ttl" seconds (default 10 minutes) and refreshed on a 401/403.
     */
    protected function token(): string
    {
        return $this->rememberToken($this->tokenKey(), function (): array {
            $response = $this->send($this->http()->asJson(), 'POST', $this->baseUrl().'/payment/merchant-auth/', [
                'merchant_id' => $this->credential('merchant_id'),
                'merchant_key' => $this->credential('merchant_key'),
            ]);

            $data = $this->decode($response);
            $token = is_array($data['data'] ?? null) ? ($data['data']['token'] ?? null) : null;

            if (! $response->successful() || ! is_string($token) || $token === '') {
                // Never log the response: a token carries the merchant key.
                throw new GatewayException('Upay merchant authorization failed: '.$this->errorMessage($data, $response->status()), $this->name());
            }

            return [$token, (int) ($this->config['token_ttl'] ?? 600)];
        });
    }

    protected function tokenKey(): string
    {
        return 'upay:'.$this->credential('merchant_id');
    }

    /**
     * The redirect's query, read leniently. Upay appends "&status=..&invoice_id=.."
     * to redirect_url; if it appends "?..." to a URL that already has a query,
     * PHP would fold everything into the first value, so split on both.
     *
     * @return array<array-key, mixed>
     */
    protected function callbackParams(Request $request): array
    {
        parse_str(str_replace('?', '&', (string) $request->server('QUERY_STRING', '')), $query);

        return $query + $request->all();
    }

    private function entryTxnId(mixed $item): ?string
    {
        if (is_array($item)) {
            $item = $item['txn_id'] ?? null;
        }

        return is_scalar($item) ? (string) $item : null;
    }

    /**
     * failed_list is keyed by txn_id with bilingual {en, bn} messages; accept
     * a list of entries carrying txn_id too.
     */
    private function failureFor(mixed $failed, string $txnId): ?string
    {
        if (! is_array($failed)) {
            return null;
        }

        $entry = $failed[$txnId] ?? null;

        if ($entry === null) {
            foreach ($failed as $item) {
                if ($this->entryTxnId($item) === $txnId) {
                    $entry = $item;
                    break;
                }
            }
        }

        if (is_array($entry)) {
            $entry = $entry['en'] ?? $entry['message'] ?? $entry['reason'] ?? null;

            if (is_array($entry)) {
                $entry = $entry['en'] ?? null;
            }
        }

        return is_scalar($entry) ? (string) $entry : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function errorMessage(array $data, int $status): string
    {
        $message = $data['message'] ?? null;

        if (is_array($message)) {
            $message = $message['en'] ?? null;
        }

        $code = isset($data['code']) && is_scalar($data['code']) ? ' ['.$data['code'].']' : '';

        return (is_scalar($message) && (string) $message !== '' ? (string) $message : 'HTTP '.$status).$code;
    }
}
