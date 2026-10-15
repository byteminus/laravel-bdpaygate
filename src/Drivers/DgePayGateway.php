<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Drivers\DgePay\DgePayCrypto;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\GatewayUnavailableException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * DGePay hosted checkout (bKash, Nagad and other wallets).
 *
 * EXPERIMENTAL. DGePay's API guide is only given to merchants; this driver is
 * built from DGePay's published SDK. Requests are authenticated with a JWT
 * (Basic auth on /payment_gateway/authenticate), their JSON body is
 * AES-128-ECB encrypted with the client secret, and a "Signature" header
 * carries an HMAC-SHA256 of the canonical payload (see DgePayCrypto).
 *
 * The customer comes back to redirect_url?data=<encrypted JSON>. That blob is
 * only decrypted to learn which payment it is about; the outcome always comes
 * from check_transaction_status.
 */
class DgePayGateway extends AbstractGateway implements SupportsSync
{
    public const DRIVER = 'dgepay';

    public const SANDBOX_URL = 'https://api-uat.dgepay.net/dipon/v3';

    public const LIVE_URL = 'https://apiv2.dgepay.net/dipon/v3';

    /** DGePay's development host; set it as "base_url" if DGePay gives you DEV credentials. */
    public const DEV_URL = 'https://api-dev.dgepay.net/dipon/v3';

    public const STATUS_SUCCESS = '3';

    public const STATUS_CANCELLED = '8';

    public function baseUrl(): string
    {
        return $this->endpoint(self::SANDBOX_URL, self::LIVE_URL);
    }

    public function crypto(): DgePayCrypto
    {
        return new DgePayCrypto($this->credential('client_secret'), $this->credential('api_key'), $this->name());
    }

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        if ($this->currency() !== 'BDT') {
            throw new GatewayException('DGePay only accepts BDT.', $this->name());
        }

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        $payload = [
            'amount' => (float) $payment->amount,
            'customer_token' => null,
            'note' => mb_substr($description ?? $payment->reference, 0, 100),
            'payment_method' => isset($meta['payment_method']) ? (string) $meta['payment_method'] : null,
            'redirect_url' => $this->callbackUrl('callback'),
            'unique_txn_id' => $payment->gateway_reference,
        ];

        if ($phone = $this->localPhone($customer->phone)) {
            $payload['payee_information'] = ['dial_code' => '+88', 'phone_number' => $phone];
        }

        $response = $this->api('/payment_gateway/initiate_payment', $payload);
        $data = $this->decode($response);
        $url = is_array($data['data'] ?? null) ? ($data['data']['webview_url'] ?? null) : null;

        if (! $response->successful() || ! $this->ok($data) || ! is_string($url) || $url === '') {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException('DGePay could not initiate the payment: '.$this->error($data, $response), $this->name(), $data);
        }

        $payment->checkout_url = $url;
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $encoded = $request->input('data');
        $claim = is_string($encoded) && $encoded !== '' ? $this->crypto()->decrypt($encoded) : null;

        if ($claim === null) {
            throw new InvalidCallbackException('DGePay callback has no data that decrypts with this client secret.', $this->name(), $request->all());
        }

        $payment = $this->findByGatewayReference(is_scalar($claim['unique_txn_id'] ?? null) ? (string) $claim['unique_txn_id'] : '');

        return $this->confirm($payment, $outcome, $claim);
    }

    public function sync(Payment $payment): Payment
    {
        $this->confirm($payment, 'sync');

        return $payment->refresh();
    }

    /**
     * Ask check_transaction_status and apply the answer. Anything other than
     * a known success, cancel or failure code leaves the payment pending.
     *
     * @param  array<string, mixed>  $claim  The decrypted redirect payload, kept for the log.
     */
    protected function confirm(Payment $payment, string $step, array $claim = []): CallbackResult
    {
        if ($payment->isPaid()) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        $response = $this->api('/payment_gateway/check_transaction_status', ['unique_txn_id' => $payment->gateway_reference]);
        $body = $this->decode($response);
        $raw = $claim === [] ? $body : $body + ['callback' => $claim];

        if ($response->serverError()) {
            throw new GatewayUnavailableException("DGePay status API answered HTTP {$response->status()}.", $this->name(), $raw);
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : null;

        if (! $response->successful() || $data === null) {
            return $this->stillPending($payment, $step, $raw);
        }

        if (isset($data['unique_txn_id']) && (string) $data['unique_txn_id'] !== $payment->gateway_reference) {
            throw new InvalidCallbackException('DGePay status API returned a different unique_txn_id.', $this->name(), $raw);
        }

        $code = is_scalar($data['status_code'] ?? null) ? (string) $data['status_code'] : '';
        $failed = array_map('strval', (array) ($this->config['failed_status_codes'] ?? ['4']));

        if ($code === self::STATUS_SUCCESS) {
            $this->assertAmountMatches($payment, is_scalar($data['amount'] ?? null) ? (string) $data['amount'] : null);

            return $this->transition($payment, PaymentStatus::Completed, array_filter([
                'gateway_payment_id' => is_scalar($data['txn_id'] ?? null) ? (string) $data['txn_id'] : null,
                'gateway_transaction_id' => is_scalar($data['txn_number'] ?? null) ? (string) $data['txn_number'] : null,
            ]), $step, $raw);
        }

        if ($code === self::STATUS_CANCELLED || in_array($code, $failed, true)) {
            $message = $data['message'] ?? null;

            return $this->transition(
                $payment,
                $code === self::STATUS_CANCELLED ? PaymentStatus::Cancelled : PaymentStatus::Failed,
                ['failure_reason' => is_scalar($message) ? mb_substr((string) $message, 0, 255) : null],
                $step,
                $raw,
            );
        }

        return $this->stillPending($payment, $step, $raw);
    }

    /**
     * An authenticated, signed, encrypted call. A 401 means the cached token
     * expired: fetch a new one and try once more (the request was rejected, not run).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function api(string $path, array $payload): Response
    {
        $crypto = $this->crypto();
        $body = $crypto->encrypt($payload);
        $signature = $crypto->sign($payload);

        $call = fn (): Response => $this->send(
            $this->http()
                ->withHeaders(['Authorization' => 'Bearer '.$this->token(), 'Signature' => $signature])
                ->withBody($body, 'application/json'),
            'POST',
            $this->baseUrl().$path,
        );

        $response = $call();

        if ($response->status() === 401) {
            $this->forgetToken($this->tokenKey());
            $response = $call();
        }

        return $response;
    }

    /**
     * A JWT from /payment_gateway/authenticate, cached until it expires.
     */
    public function token(): string
    {
        return $this->rememberToken($this->tokenKey(), function (): array {
            $id = $this->credential('client_id');
            $secret = $this->credential('client_secret');

            $response = $this->send(
                $this->http()->asJson()->withBasicAuth($id, $secret),
                'POST',
                $this->baseUrl().'/payment_gateway/authenticate',
                ['client_id' => $id, 'client_secret' => $secret],
            );

            $data = $this->decode($response);
            $token = is_array($data['data'] ?? null) ? ($data['data']['access_token'] ?? null) : null;

            if (! $response->successful() || ! $this->ok($data) || ! is_string($token) || $token === '') {
                throw new GatewayException('DGePay authentication failed: '.$this->error($data, $response), $this->name(), $data);
            }

            return [$token, $this->tokenTtl($token)];
        });
    }

    protected function tokenKey(): string
    {
        return 'dgepay:'.($this->config['client_id'] ?? '');
    }

    /**
     * Seconds to cache a token: until the JWT's exp claim, less a minute.
     */
    protected function tokenTtl(string $token): int
    {
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token.'..')[1], '-_', '+/')), true);
        $expires = is_array($claims) && is_numeric($claims['exp'] ?? null) ? (int) $claims['exp'] : null;

        return $expires === null ? (int) ($this->config['token_ttl'] ?? 300) : max(30, $expires - time() - 60);
    }

    /**
     * Errors come back with HTTP 200, "success": 0 and an "error" list.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function ok(array $data): bool
    {
        if (array_key_exists('success', $data) && (int) $data['success'] !== 1) {
            return false;
        }

        return ! isset($data['status_code']) || (int) $data['status_code'] === 200;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function error(array $data, Response $response): string
    {
        $error = $data['error'] ?? null;
        $first = is_array($error) ? reset($error) : $error;

        foreach ([$first, $data['message'] ?? null] as $message) {
            if (is_scalar($message) && (string) $message !== '') {
                return (string) $message;
            }
        }

        return 'HTTP '.$response->status();
    }

    /**
     * "01711000000" from "+8801711000000", "8801711000000" or "01711000000"; null otherwise.
     */
    protected function localPhone(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 13 && str_starts_with($digits, '88')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^01\d{9}$/', $digits) ? $digits : null;
    }
}
