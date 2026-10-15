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
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * ShurjoPay hosted checkout (API v2): token grant, secret-pay and verification.
 *
 * Cards (Visa, Mastercard, Amex, UnionPay, Nexus), bKash, Nagad, Rocket, Upay
 * and several smaller wallets on one checkout page. No refund API.
 *
 * Docs: https://shurjopay.com.bd/frontend-developers/direct-integration-with-api
 */
class ShurjoPayGateway extends AbstractGateway implements SupportsSync
{
    public const DRIVER = 'shurjopay';

    public const SANDBOX_URL = 'https://sandbox.shurjopayment.com';

    public const LIVE_URL = 'https://engine.shurjopayment.com';

    /** Verification sp_code values. 1068 and 1064 are undocumented but returned by the API. */
    public const PAID = '1000';

    public const DECLINED = '1001';

    public const CANCELLED = '1002';

    public const INITIATED = '1068';

    public const UNAUTHORIZED = '1064';

    /** The docs and the sandbox agree on 15 minutes; expires_in is not trusted beyond that. */
    private const TOKEN_TTL = 900;

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
        if (! in_array($this->currency(), ['BDT', 'USD'], true)) {
            throw new GatewayException('ShurjoPay accepts BDT or USD only.', $this->name());
        }

        $prefix = $this->credential('prefix');
        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        $data = $this->authorised(fn (array $auth) => $this->send($this->http()->withToken($auth['token']), 'POST', $this->baseUrl().'/api/secret-pay', array_filter([
            'prefix' => $prefix,
            'token' => $auth['token'],
            'store_id' => $auth['store_id'],
            'return_url' => $this->callbackUrl('success'),
            'cancel_url' => $this->callbackUrl('cancel'),
            'amount' => $payment->amount,
            'order_id' => $payment->gateway_reference,
            'currency' => $payment->currency,
            'customer_name' => $customer->name,
            'customer_address' => $customer->address ?? $customer->city,
            'customer_email' => $customer->email ?? 'noemail@example.com',
            'customer_phone' => $customer->phone,
            'customer_city' => $customer->city,
            'customer_country' => $customer->country,
            'client_ip' => $meta['client_ip'] ?? request()->ip(),
            'value1' => $payment->reference,
            'value2' => $payment->id,
        ], fn ($value) => $value !== null && $value !== '')));

        if (empty($data['checkout_url']) || empty($data['sp_order_id'])) {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException('ShurjoPay could not create the payment: '.$this->message($data), $this->name(), $data);
        }

        if ((string) ($data['customer_order_id'] ?? $payment->gateway_reference) !== $payment->gateway_reference) {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException('ShurjoPay echoed a different order_id.', $this->name(), $data);
        }

        $payment->gateway_payment_id = (string) $data['sp_order_id'];
        $payment->checkout_url = (string) $data['checkout_url'];
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    /**
     * ShurjoPay returns the customer (return_url / cancel_url) and pings the IPN
     * with only ?order_id=<sp_order_id>: no status, no signature. Everything is
     * decided by the verification API.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $orderId = (string) $request->input('order_id', '');

        if ($orderId === '') {
            throw new InvalidCallbackException('ShurjoPay callback has no order_id.', $this->name(), $request->all());
        }

        $payment = $this->findByGatewayPaymentId($orderId);

        if ($payment->isPaid()) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        return $this->settle($payment, $outcome);
    }

    public function sync(Payment $payment): Payment
    {
        if ($payment->gateway_payment_id && ! $payment->isPaid()) {
            $this->settle($payment, 'sync');
        }

        return $payment->refresh();
    }

    /**
     * Apply what the verification API says. Only a confirmed decline or
     * cancellation fails the payment; anything else leaves it pending.
     */
    protected function settle(Payment $payment, string $step): CallbackResult
    {
        $data = $this->verify($payment);
        $code = (string) ($data['sp_code'] ?? '');

        return match ($code) {
            self::PAID => $this->complete($payment, $data, $step),
            self::DECLINED => $this->transition($payment, PaymentStatus::Failed, [
                'failure_reason' => mb_substr($this->message($data), 0, 255),
            ], $step, $data),
            self::CANCELLED => $this->transition($payment, PaymentStatus::Cancelled, [
                'failure_reason' => mb_substr($this->message($data), 0, 255),
            ], $step, $data),
            default => $this->stillPending($payment, $step, $data),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function complete(Payment $payment, array $data, string $step): CallbackResult
    {
        $this->assertAmountMatches($payment, $data['amount'] ?? null, isset($data['currency']) ? (string) $data['currency'] : null);

        return $this->transition($payment, PaymentStatus::Completed, [
            'gateway_transaction_id' => $data['bank_trx_id'] ?? null,
        ], $step, $data);
    }

    /**
     * Server-to-server verification by sp_order_id (it cannot look up your order_id).
     *
     * @return array<array-key, mixed>
     */
    public function verify(Payment $payment): array
    {
        if (! $payment->gateway_payment_id) {
            throw new GatewayException('ShurjoPay payment has no sp_order_id to verify.', $this->name());
        }

        $data = $this->authorised(fn (array $auth) => $this->send($this->http()->withToken($auth['token']), 'POST', $this->baseUrl().'/api/verification', [
            'order_id' => $payment->gateway_payment_id,
        ]));

        // Unpaid and unknown orders also carry an order_id; cross-check whatever came back.
        if (isset($data['order_id']) && (string) $data['order_id'] !== $payment->gateway_payment_id) {
            throw new InvalidCallbackException('ShurjoPay verification returned a different order_id.', $this->name(), $data);
        }

        if (isset($data['customer_order_id']) && (string) $data['customer_order_id'] !== $payment->gateway_reference) {
            throw new InvalidCallbackException('ShurjoPay verification returned a different customer_order_id.', $this->name(), $data);
        }

        return $data;
    }

    /**
     * Run a call with a cached token. On "1064 Unauthorized" (expired or revoked
     * token) fetch a new token and try once more.
     *
     * @param  Closure(array{token: string, store_id: string}): Response  $call
     * @return array<array-key, mixed>
     */
    protected function authorised(Closure $call): array
    {
        for ($attempt = 1; ; $attempt++) {
            $data = $this->normalise($this->decode($call($this->auth())));

            if ((string) ($data['sp_code'] ?? '') !== self::UNAUTHORIZED) {
                return $data;
            }

            $this->forgetToken('auth');

            if ($attempt >= 2) {
                // The payment may well be paid; we just cannot ask. Leave it pending.
                throw new GatewayUnavailableException('ShurjoPay rejected the token twice (1064 Unauthorized). Check the username and password.', $this->name(), $data);
            }
        }
    }

    /**
     * Token and store_id from /api/get_token, cached together.
     *
     * @return array{token: string, store_id: string}
     */
    public function auth(): array
    {
        $cached = $this->rememberToken('auth', function (): array {
            $data = $this->normalise($this->decode($this->send($this->http(), 'POST', $this->baseUrl().'/api/get_token', [
                'username' => $this->credential('username'),
                'password' => $this->credential('password'),
            ])));

            if ((string) ($data['sp_code'] ?? '') !== '200' || empty($data['token']) || ! isset($data['store_id'])) {
                throw new GatewayException('ShurjoPay token request failed: '.$this->message($data), $this->name(), $data);
            }

            $ttl = min(self::TOKEN_TTL, (int) ($data['expires_in'] ?? self::TOKEN_TTL) ?: self::TOKEN_TTL) - 60;

            return [(string) json_encode(['token' => (string) $data['token'], 'store_id' => (string) $data['store_id']]), $ttl];
        });

        $auth = json_decode($cached, true);

        if (! is_array($auth) || ! is_string($auth['token'] ?? null) || ! is_string($auth['store_id'] ?? null)) {
            $this->forgetToken('auth');

            throw new GatewayException('Cached ShurjoPay token is unreadable.', $this->name());
        }

        return ['token' => $auth['token'], 'store_id' => $auth['store_id']];
    }

    /**
     * Verification answers with a one-element array; some errors are a bare object.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function normalise(array $data): array
    {
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function message(array $data): string
    {
        foreach (['sp_message', 'sp_massage', 'message', 'body'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                $code = isset($data['sp_code']) && is_scalar($data['sp_code']) ? $data['sp_code'].' ' : '';

                return $code.$data[$key];
            }
        }

        // Validation errors come back as {"field": "The field is required."}.
        $errors = array_filter($data, 'is_string');

        return $errors !== [] ? implode(' ', $errors) : 'unexpected response';
    }
}
