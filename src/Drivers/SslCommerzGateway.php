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
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * SSLCommerz hosted checkout (v4), with the validation, transaction query and refund APIs.
 *
 * Cards, internet banking and most wallets, including Rocket (DBBL), OK Wallet
 * and IBBL/CellFin, which have no public API of their own.
 *
 * Docs: https://developer.sslcommerz.com/doc/v4/
 */
class SslCommerzGateway extends AbstractGateway implements SupportsPartialRefunds, SupportsSync
{
    use HandlesRefunds;

    public const DRIVER = 'sslcommerz';

    public const SANDBOX_URL = 'https://sandbox.sslcommerz.com';

    public const LIVE_URL = 'https://securepay.sslcommerz.com';

    private const PAID = ['VALID', 'VALIDATED'];

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
        if ($this->currency() === 'BDT' && ((float) $amount < 10 || (float) $amount > 500000)) {
            throw new GatewayException('SSLCommerz accepts 10.00 to 500000.00 BDT.', $this->name());
        }

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        $response = $this->send($this->http()->asForm(), 'POST', $this->baseUrl().'/gwprocess/v4/api.php', [
            'store_id' => $this->credential('store_id'),
            'store_passwd' => $this->credential('store_password'),
            'total_amount' => $payment->amount,
            'currency' => $payment->currency,
            'tran_id' => $payment->gateway_reference,
            'success_url' => $this->callbackUrl('success'),
            'fail_url' => $this->callbackUrl('fail'),
            'cancel_url' => $this->callbackUrl('cancel'),
            'ipn_url' => $this->callbackUrl('ipn'),
            'cus_name' => $customer->name,
            'cus_email' => $customer->email ?? 'noemail@example.com',
            'cus_add1' => $customer->address ?? $customer->city,
            'cus_city' => $customer->city,
            'cus_country' => $customer->country,
            'cus_phone' => $customer->phone,
            'shipping_method' => 'NO',
            'product_name' => $description ?? $payment->reference,
            'product_category' => $meta['category'] ?? 'general',
            'product_profile' => $meta['profile'] ?? 'general',
            'value_a' => $payment->id,
            'value_b' => $payment->reference,
        ]);

        $data = $this->decode($response);

        if (! $response->successful() || ($data['status'] ?? null) !== 'SUCCESS' || empty($data['GatewayPageURL'])) {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException(
                'SSLCommerz could not create the session: '.($data['failedreason'] ?? 'HTTP '.$response->status()),
                $this->name(),
                $data,
            );
        }

        $payment->gateway_payment_id = $data['sessionkey'] ?? null;
        $payment->checkout_url = $data['GatewayPageURL'];
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $post = $request->all();
        $payment = $this->findByGatewayReference((string) ($post['tran_id'] ?? ''));
        $status = strtoupper((string) ($post['status'] ?? ''));

        if (in_array($outcome, ['success', 'ipn'], true) && in_array($status, self::PAID, true)) {
            return $this->confirmSuccess($payment, $post, $outcome);
        }

        // A fail or cancel is only a claim: anyone who knows the tran_id can
        // send one. Ask SSLCommerz whether the money moved before believing it.
        $confirmed = $this->query($payment);

        if ($confirmed !== null) {
            return $confirmed;
        }

        $cancelled = $outcome === 'cancel' || $status === 'CANCELLED';

        return $this->transition(
            $payment,
            $cancelled ? PaymentStatus::Cancelled : PaymentStatus::Failed,
            ['failure_reason' => isset($post['error']) ? mb_substr((string) $post['error'], 0, 255) : null],
            $outcome,
            $post,
        );
    }

    /**
     * @param  array<string, mixed>  $post
     */
    protected function confirmSuccess(Payment $payment, array $post, string $step): CallbackResult
    {
        if (isset($post['verify_sign'], $post['verify_key']) && ! $this->verifySignature($post)) {
            throw new InvalidCallbackException('SSLCommerz signature does not match.', $this->name(), $post);
        }

        // Signed or not, the validation API is the source of truth.
        $validated = $this->validate((string) ($post['val_id'] ?? ''), $payment);

        return $this->complete($payment, $validated, $step);
    }

    /**
     * SSLCommerz's own hash scheme: md5 of "key=value&..." over the keys listed in
     * verify_key plus store_passwd=md5(store password), keys sorted.
     *
     * @param  array<string, mixed>  $post
     */
    public function verifySignature(array $post): bool
    {
        $fields = [];

        foreach (array_filter(explode(',', (string) $post['verify_key'])) as $key) {
            $fields[$key] = (string) ($post[$key] ?? '');
        }

        $fields['store_passwd'] = md5($this->credential('store_password'));
        ksort($fields);

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return hash_equals(md5(implode('&', $pairs)), (string) $post['verify_sign']);
    }

    /**
     * Server-to-server validation of a val_id. Never trust the redirect alone.
     *
     * @return array<string, mixed>
     */
    public function validate(string $valId, Payment $payment): array
    {
        if ($valId === '') {
            throw new InvalidCallbackException('SSLCommerz callback has no val_id to validate.', $this->name());
        }

        $data = $this->decode($this->send($this->http(), 'GET', $this->baseUrl().'/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => $this->credential('store_id'),
            'store_passwd' => $this->credential('store_password'),
            'v' => 1,
            'format' => 'json',
        ]));

        $status = strtoupper((string) ($data['status'] ?? ''));

        if (! in_array($status, self::PAID, true)) {
            throw new InvalidCallbackException('SSLCommerz validation API rejected val_id '.$valId.' ('.($status ?: 'no status').').', $this->name(), $data);
        }

        if ((string) ($data['tran_id'] ?? '') !== $payment->gateway_reference) {
            throw new InvalidCallbackException('SSLCommerz validation returned a different tran_id.', $this->name(), $data);
        }

        return $data;
    }

    /**
     * Ask SSLCommerz about every transaction under this tran_id. Completes the
     * payment if one was paid; null when nothing paid was found.
     */
    protected function query(Payment $payment): ?CallbackResult
    {
        $data = $this->decode($this->send($this->http(), 'GET', $this->baseUrl().'/validator/api/merchantTransIDvalidationAPI.php', [
            'tran_id' => $payment->gateway_reference,
            'store_id' => $this->credential('store_id'),
            'store_passwd' => $this->credential('store_password'),
            'v' => 1,
            'format' => 'json',
        ]));

        foreach ((array) ($data['element'] ?? []) as $element) {
            if (is_array($element) && in_array(strtoupper((string) ($element['status'] ?? '')), self::PAID, true)) {
                return $this->complete($payment, $element, 'query');
            }
        }

        $payment->logGatewayResponse('query', $data)->save();

        return null;
    }

    /**
     * @param  array<string, mixed>  $data  A validation API response or a query element.
     */
    protected function complete(Payment $payment, array $data, string $step): CallbackResult
    {
        // "amount" is converted to BDT for foreign-currency payments; the
        // original currency and amount are what must match.
        if (! empty($data['currency_type'])) {
            $this->assertAmountMatches($payment, $data['currency_amount'] ?? null, (string) $data['currency_type']);
        } else {
            $this->assertAmountMatches($payment, $data['amount'] ?? null);
        }

        return $this->transition($payment, PaymentStatus::Completed, [
            'gateway_transaction_id' => $data['bank_tran_id'] ?? null,
            // risk_level 1: SSLCommerz asks you to hold the service and verify the customer.
            'requires_review' => (string) ($data['risk_level'] ?? '0') === '1',
        ], $step, $data);
    }

    public function sync(Payment $payment): Payment
    {
        $this->query($payment);

        return $payment->refresh();
    }

    protected function requestRefund(Payment $payment, string $amount, ?string $reason): RefundResult
    {
        if (! $payment->gateway_transaction_id) {
            throw new GatewayException('SSLCommerz needs bank_tran_id to refund; sync the payment first.', $this->name());
        }

        $refundTransId = 'RF'.strtoupper(Str::random(18));

        $data = $this->decode($this->send($this->http(), 'GET', $this->baseUrl().'/validator/api/merchantTransIDvalidationAPI.php', [
            'bank_tran_id' => $payment->gateway_transaction_id,
            'refund_trans_id' => $refundTransId,
            'refund_amount' => $amount,
            'refund_remarks' => $reason ?? 'Refund for '.$payment->reference,
            'refe_id' => $payment->gateway_reference,
            'store_id' => $this->credential('store_id'),
            'store_passwd' => $this->credential('store_password'),
            'v' => 1,
            'format' => 'json',
        ]));

        // "success" means initiated; the bank settles it later. "processing"
        // means this refund was already initiated, so it is not a new one.
        $status = strtolower((string) ($data['status'] ?? ''));
        $accepted = ($data['APIConnect'] ?? null) === 'DONE' && $status === 'success';

        return new RefundResult(
            payment: $payment,
            accepted: $accepted,
            amount: $amount,
            refundId: $data['refund_ref_id'] ?? null,
            message: $data['errorReason'] ?? ($status === 'processing' ? 'Refund already initiated' : ($data['status'] ?? null)),
            raw: $data + ['refund_trans_id' => $refundTransId],
            pending: $accepted,
        );
    }

    /**
     * Status of a refund previously initiated: refunded, processing or cancelled.
     */
    public function refundStatus(string $refundRefId): string
    {
        $data = $this->decode($this->send($this->http(), 'GET', $this->baseUrl().'/validator/api/merchantTransIDvalidationAPI.php', [
            'refund_ref_id' => $refundRefId,
            'store_id' => $this->credential('store_id'),
            'store_passwd' => $this->credential('store_password'),
            'format' => 'json',
        ]));

        return strtolower((string) ($data['status'] ?? 'unknown'));
    }
}
