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
use Illuminate\Http\Request;

/**
 * aamarPay hosted checkout (JSON init) with the Search Transaction API.
 *
 * Cards (Visa, Mastercard, UnionPay), bKash, Nagad, Rocket, Upay, Tap,
 * OK Wallet, SureCash and net banking on one checkout page. No refund API.
 *
 * Docs: https://aamarpay.readme.io/reference/initiate-payment-json
 */
class AamarPayGateway extends AbstractGateway implements SupportsSync
{
    public const DRIVER = 'aamarpay';

    public const SANDBOX_URL = 'https://sandbox.aamarpay.com';

    public const LIVE_URL = 'https://secure.aamarpay.com';

    /** status_code values from the docs. */
    public const INITIATED = '0';

    public const SUCCESSFUL = '2';

    public const EXPIRED = '3';

    public const FAILED = '7';

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
            throw new GatewayException('aamarPay accepts BDT or USD only.', $this->name());
        }

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        // tran_id is a fresh attempt id: aamarPay accepts a reused tran_id and then
        // only reports the newest session for it.
        $response = $this->send($this->http()->asJson(), 'POST', $this->baseUrl().'/jsonpost.php', array_filter([
            'store_id' => $this->credential('store_id'),
            'signature_key' => $this->credential('signature_key'),
            'tran_id' => $payment->gateway_reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'desc' => $description ?? $payment->reference,
            'cus_name' => $customer->name,
            'cus_email' => $customer->email ?? 'noemail@example.com',
            'cus_phone' => $customer->phone,
            'cus_add1' => $customer->address,
            'cus_city' => $customer->city,
            'cus_country' => $customer->country,
            'success_url' => $this->callbackUrl('success'),
            'fail_url' => $this->callbackUrl('fail'),
            // The cancel return carries no documented payload, so it names the attempt itself.
            'cancel_url' => $this->callbackUrl('cancel', ['tran_id' => $payment->gateway_reference]),
            'opt_a' => $payment->reference,
            'opt_b' => $payment->id,
            'type' => 'json',
        ], fn ($value) => $value !== null && $value !== ''));

        $data = $this->decode($response);
        $url = is_string($data['payment_url'] ?? null) ? $data['payment_url'] : '';

        // Success is result "true" (a string); errors are result false with
        // "errors" or "message", or plain text, all with HTTP 200.
        if (! in_array($data['result'] ?? null, [true, 'true'], true) || ! str_starts_with($url, 'https://')) {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException('aamarPay could not create the payment: '.$this->message($data), $this->name(), $data);
        }

        // The checkout "track" is the session's pg_txnid.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $track = $query['track'] ?? null;

        $payment->gateway_payment_id = is_string($track) && $track !== '' ? $track : null;
        $payment->checkout_url = $url;
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    /**
     * The browser POSTs to success_url / fail_url, the IPN posts JSON, cancel
     * is a plain return. All are claims: Search Transaction decides.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $tranId = $this->value($request->input('mer_txnid')) ?? $this->value($request->query('tran_id')) ?? '';
        $payment = $this->findByGatewayReference($tranId);

        $claimed = $this->value($request->input('pg_txnid'));

        if ($claimed !== null && $payment->gateway_payment_id && $claimed !== $payment->gateway_payment_id) {
            throw new InvalidCallbackException('aamarPay callback pg_txnid does not belong to this attempt.', $this->name(), $request->all());
        }

        if ($payment->isPaid()) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        return $this->settle($payment, $outcome);
    }

    public function sync(Payment $payment): Payment
    {
        if (! $payment->isPaid()) {
            $this->settle($payment, 'sync');
        }

        return $payment->refresh();
    }

    /**
     * Apply what Search Transaction says. Only a confirmed failure or expiry
     * fails the payment; anything else leaves it pending.
     */
    protected function settle(Payment $payment, string $step): CallbackResult
    {
        $data = $this->search($payment);
        $code = (string) ($data['status_code'] ?? '');
        $pgTxnId = $this->value($data['pg_txnid'] ?? null);
        $reason = $this->value($data['status_title'] ?? null) ?? $this->value($data['pay_status'] ?? null);

        return match ($code) {
            self::SUCCESSFUL => $this->complete($payment, $data, $step),
            self::FAILED, self::EXPIRED => $this->transition($payment, PaymentStatus::Failed, [
                'gateway_payment_id' => $payment->gateway_payment_id ?? $pgTxnId,
                'failure_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            ], $step, $data),
            default => $this->stillPending($payment, $step, $data),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function complete(Payment $payment, array $data, string $step): CallbackResult
    {
        $payStatus = $this->value($data['pay_status'] ?? null);

        if ($payStatus !== null && strcasecmp($payStatus, 'Successful') !== 0) {
            // status_code and pay_status disagree: do not decide either way.
            return $this->stillPending($payment, $step, $data);
        }

        // "amount" is in BDT; for a USD payment the original amount is amount_currency.
        $this->assertAmountMatches(
            $payment,
            $payment->currency === 'BDT' ? ($data['amount'] ?? null) : ($data['amount_currency'] ?? null),
            $this->value($data['currency_merchant'] ?? null) ?? $this->value($data['currency'] ?? null),
        );

        $risk = $this->value($data['risk_level'] ?? null);

        return $this->transition($payment, PaymentStatus::Completed, [
            'gateway_payment_id' => $payment->gateway_payment_id ?? $this->value($data['pg_txnid'] ?? null),
            'gateway_transaction_id' => $this->value($data['bank_trxid'] ?? null),
            'requires_review' => $risk !== null && is_numeric($risk) && (int) $risk > 0,
        ], $step, $data);
    }

    /**
     * Search Transaction by tran_id (aamarPay cannot look up by pg_txnid).
     *
     * @return array<array-key, mixed>
     */
    public function search(Payment $payment): array
    {
        $data = $this->decode($this->send($this->http(), 'GET', $this->baseUrl().'/api/v1/trxcheck/request.php', [
            'request_id' => $payment->gateway_reference,
            'store_id' => $this->credential('store_id'),
            'signature_key' => $this->credential('signature_key'),
            'type' => 'json',
        ]));

        if (isset($data['body']) && ! isset($data['status_code'])) {
            // Plain text such as "Store_id & signature key not matched". The
            // payment may be paid; we cannot tell. Leave it pending.
            throw new GatewayUnavailableException('aamarPay search failed: '.$this->message($data), $this->name(), $data);
        }

        $merTxnId = $this->value($data['mer_txnid'] ?? null);

        if ($merTxnId !== null && $merTxnId !== $payment->gateway_reference) {
            throw new InvalidCallbackException('aamarPay search returned a different mer_txnid.', $this->name(), $data);
        }

        // A reused tran_id would make search report the newest session only.
        // Attempt ids are never reused, so a different pg_txnid means the answer
        // is not about the session this payment opened.
        $pgTxnId = $this->value($data['pg_txnid'] ?? null);

        if ($pgTxnId !== null && $payment->gateway_payment_id && $pgTxnId !== $payment->gateway_payment_id) {
            throw new InvalidCallbackException('aamarPay search returned a different pg_txnid.', $this->name(), $data);
        }

        return $data;
    }

    /**
     * A field's value, with aamarPay's "Not-Available" placeholders as null.
     */
    protected function value(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || in_array(strtolower($value), ['not-available', 'not available'], true) ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function message(array $data): string
    {
        if (isset($data['errors']) && is_array($data['errors'])) {
            return implode(' ', array_filter($data['errors'], 'is_scalar'));
        }

        foreach (['message', 'status', 'body'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return 'unexpected response';
    }
}
