<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Drivers\Nagad\NagadCrypto;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Nagad Online Payment API (hosted checkout, API version v-0.2.0).
 *
 * BETA: built from public information and not yet verified against a merchant
 * sandbox. Nagad hands out its integration guide at onboarding; check this
 * driver against it.
 *
 * Flow: initialize (RSA-encrypted, signed) -> complete -> redirect the customer
 * -> unsigned browser callback -> GET verify/payment/{paymentRefId}. Nagad
 * publishes no refund API, so refunds go through the merchant portal.
 */
class NagadGateway extends AbstractGateway implements SupportsSync
{
    public const DRIVER = 'nagad';

    public const SANDBOX_URL = 'https://sandbox-ssl.mynagad.com/remote-payment-gateway-1.0/api/dfs';

    public const LIVE_URL = 'https://api.mynagad.com/api/dfs';

    public const API_VERSION = 'v-0.2.0';

    /** ISO 4217 numeric code for BDT, as Nagad expects it. */
    private const CURRENCY_CODE = '050';

    private const PAID = 'success';

    private const CANCELLED = ['cancelled', 'aborted'];

    private const FAILED = ['failed', 'fraud', 'invalidrequest', 'unknownfailed'];

    private ?NagadCrypto $crypto = null;

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
            throw new GatewayException('Nagad only accepts BDT.', $this->name());
        }

        $merchantId = $this->credential('merchant_id');
        $crypto = $this->crypto();
        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);
        $orderId = $payment->gateway_reference;

        // Nagad order ids: 5 to 20 letters and digits, unique per day.
        if (! preg_match('/^[A-Za-z0-9]{5,20}$/', $orderId)) {
            throw new GatewayException("Nagad order id [{$orderId}] must be 5 to 20 letters or digits.", $this->name());
        }

        $ip = $this->clientIp(is_string($meta['customer_ip'] ?? null) ? $meta['customer_ip'] : null);

        // Step 1: initialize. The sensitive JSON must stay small (RSA-2048 fits 245 bytes).
        $challenge = bin2hex(random_bytes(20));
        $dateTime = $this->dhakaTime();
        $sensitive = $this->json([
            'merchantId' => $merchantId,
            'datetime' => $dateTime,
            'orderId' => $orderId,
            'challenge' => $challenge,
        ]);

        $body = array_filter([
            'accountNumber' => $this->config['merchant_number'] ?? null,
            'dateTime' => $dateTime,
            'sensitiveData' => $crypto->encrypt($sensitive),
            'signature' => $crypto->sign($sensitive),
        ], fn ($value) => $value !== null && $value !== '');

        $locale = strtoupper((string) ($this->config['locale'] ?? 'EN')) === 'BN' ? 'BN' : 'EN';
        $response = $this->send($this->api($ip), 'POST', $this->baseUrl()."/check-out/initialize/{$merchantId}/{$orderId}?locale={$locale}", $body);
        $data = $this->decode($response);

        if (! $response->successful() || ! is_string($data['sensitiveData'] ?? null)) {
            $payment->logGatewayResponse('initialize', $data)->save();

            throw new GatewayException('Nagad could not initialize the payment: '.$this->errorMessage($data, $response->status()), $this->name(), $data);
        }

        $initialized = $this->open($data);
        $paymentRefId = (string) ($initialized['paymentReferenceId'] ?? '');

        if ($paymentRefId === '') {
            $payment->logGatewayResponse('initialize', $initialized)->save();

            throw new GatewayException('Nagad did not return a paymentReferenceId.', $this->name(), $initialized);
        }

        // Saved before completing: verify only works by paymentRefId, so a
        // lost response after this point can still be reconciled by sync.
        $payment->gateway_payment_id = $paymentRefId;
        $payment->logGatewayResponse('initialize', $initialized)->save();

        // Step 2: complete (place the order) with the challenge from step 1.
        $sensitive = $this->json([
            'merchantId' => $merchantId,
            'orderId' => $orderId,
            'amount' => $payment->amount,
            'currencyCode' => self::CURRENCY_CODE,
            'challenge' => (string) ($initialized['challenge'] ?? $challenge),
        ]);

        $additional = array_merge(
            is_array($meta['additional_merchant_info'] ?? null) ? $meta['additional_merchant_info'] : [],
            ['reference' => $payment->reference],
        );

        $response = $this->send($this->api($ip), 'POST', $this->baseUrl().'/check-out/complete/'.rawurlencode($paymentRefId), [
            'sensitiveData' => $crypto->encrypt($sensitive),
            'signature' => $crypto->sign($sensitive),
            'merchantCallbackURL' => $this->callbackUrl('callback'),
            'additionalMerchantInfo' => $additional,
        ]);
        $data = $this->decode($response);
        $checkoutUrl = $data['callBackUrl'] ?? null;

        if (! $response->successful() || strtolower((string) ($data['status'] ?? '')) !== 'success' || ! is_string($checkoutUrl) || $checkoutUrl === '') {
            $payment->logGatewayResponse('complete', $data)->save();

            throw new GatewayException('Nagad could not place the order: '.$this->errorMessage($data, $response->status()), $this->name(), $data);
        }

        $payment->checkout_url = $checkoutUrl;
        $payment->logGatewayResponse('complete', $data)->save();

        return $payment;
    }

    /**
     * Nagad returns the customer with a GET to merchantCallbackURL. The query
     * (status, payment_ref_id, ...) is unsigned, so it only says which payment
     * to look at; the verify API decides what happened.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $orderId = (string) $request->input('order_id', '');
        $paymentRefId = (string) $request->input('payment_ref_id', '');

        $payment = $orderId !== ''
            ? $this->findByGatewayReference($orderId)
            : $this->findByGatewayPaymentId($paymentRefId);

        if ($payment->isPaid()) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        return $this->verify($payment, $outcome, $paymentRefId, $request->ip());
    }

    public function sync(Payment $payment): Payment
    {
        if ($payment->gateway_payment_id) {
            $this->verify($payment, 'sync');
        }

        return $payment->refresh();
    }

    /**
     * Ask Nagad what happened to a payment and apply it. Anything that is not a
     * clear answer leaves the payment pending.
     */
    protected function verify(Payment $payment, string $step, string $claimedRefId = '', ?string $ip = null): CallbackResult
    {
        // Prefer the id we stored over whatever the callback claims.
        $refId = (string) ($payment->gateway_payment_id ?: $claimedRefId);

        if ($refId === '') {
            return $this->stillPending($payment, $step, ['reason' => 'No Nagad paymentRefId to verify yet.']);
        }

        $response = $this->send($this->api($this->clientIp($ip)), 'GET', $this->baseUrl().'/verify/payment/'.rawurlencode($refId));
        $data = $this->decode($response);

        // 404 + 16_0004_999: Nagad has no such payment (yet). Not a failure.
        if (! $response->successful() || ! isset($data['status'])) {
            return $this->stillPending($payment, $step, $data + ['http_status' => $response->status()]);
        }

        if ((string) ($data['orderId'] ?? '') !== $payment->gateway_reference) {
            throw new InvalidCallbackException('Nagad verify returned a different orderId.', $this->name(), $data);
        }

        if (isset($data['merchantId']) && (string) $data['merchantId'] !== $this->credential('merchant_id')) {
            throw new InvalidCallbackException('Nagad verify returned a different merchantId.', $this->name(), $data);
        }

        $status = strtolower((string) $data['status']);
        $attributes = ['gateway_payment_id' => $refId];

        if ($status === self::PAID) {
            $this->assertAmountMatches($payment, is_scalar($data['amount'] ?? null) ? (string) $data['amount'] : null);

            return $this->transition($payment, PaymentStatus::Completed, $attributes + [
                'gateway_transaction_id' => isset($data['issuerPaymentRefNo']) ? (string) $data['issuerPaymentRefNo'] : null,
            ], $step, $data);
        }

        $reason = mb_substr('Nagad: '.$data['status'].(isset($data['statusCode']) ? ' ('.$data['statusCode'].')' : ''), 0, 255);

        return match (true) {
            in_array($status, self::CANCELLED, true) => $this->transition($payment, PaymentStatus::Cancelled, $attributes + ['failure_reason' => $reason], $step, $data),
            in_array($status, self::FAILED, true) => $this->transition($payment, PaymentStatus::Failed, $attributes + ['failure_reason' => $reason], $step, $data),
            // OrderInitiated, Ready, InProgress, OtpSent, OtpVerified, PinGiven,
            // PartialCancelled, or anything new: not a settled answer.
            default => $this->stillPending($payment, $step, $data),
        };
    }

    /**
     * The RSA helper, built from config. Public so callers can reuse it (e.g. to
     * check a key pair during onboarding).
     */
    public function crypto(): NagadCrypto
    {
        return $this->crypto ??= new NagadCrypto(
            $this->credential('public_key'),
            $this->credential('private_key'),
            (string) ($this->config['signature_algorithm'] ?? 'sha256'),
            $this->name(),
        );
    }

    protected function api(string $ip): PendingRequest
    {
        return $this->http()->asJson()->withHeaders([
            'X-KM-Api-Version' => (string) ($this->config['api_version'] ?? self::API_VERSION),
            'X-KM-IP-V4' => $ip,
            'X-KM-Client-Type' => (string) ($this->config['client_type'] ?? 'PC_WEB'),
        ]);
    }

    /**
     * Decrypt (and optionally verify) an encrypted response envelope.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function open(array $data): array
    {
        $plain = $this->crypto()->decrypt((string) $data['sensitiveData']);

        if (($this->config['verify_response_signature'] ?? false)
            && ! $this->crypto()->verify($plain, (string) ($data['signature'] ?? ''))) {
            throw new GatewayException('Nagad response signature does not verify.', $this->name());
        }

        $decoded = json_decode($plain, true);

        if (! is_array($decoded)) {
            throw new GatewayException('Nagad response sensitiveData is not JSON.', $this->name());
        }

        return $decoded;
    }

    /**
     * X-KM-IP-V4 should be the customer's IPv4. Behind IPv6, a private address or no
     * request at all (queue, scheduler), fall back to the configured "ip".
     */
    protected function clientIp(?string $candidate = null): string
    {
        $candidate ??= request()->ip();
        $public = is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if ($public) {
            return (string) $candidate;
        }

        $configured = $this->config['ip'] ?? null;

        if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $configured;
        }

        return is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $candidate : '127.0.0.1';
    }

    /**
     * Nagad rejects timestamps outside a short window of Bangladesh time.
     */
    protected function dhakaTime(): string
    {
        return now('Asia/Dhaka')->format('YmdHis');
    }

    /**
     * @param  array<string, string>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function errorMessage(array $data, int $status): string
    {
        $message = $data['message'] ?? $data['devMessage'] ?? 'HTTP '.$status;
        $reason = isset($data['reason']) ? ' ['.$data['reason'].']' : '';

        return (is_scalar($message) ? (string) $message : 'HTTP '.$status).$reason;
    }
}
