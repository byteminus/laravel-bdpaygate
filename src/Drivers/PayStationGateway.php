<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Request;

/**
 * PayStation hosted checkout (Service Hub Ltd): cards, bKash, Nagad, Rocket, Upay.
 *
 * Business errors come back as HTTP 200 with a string "status_code" other
 * than "200". There is no refund API and no request signing: the browser
 * return is plain query parameters, so every outcome is confirmed with the
 * transaction status API, looked up by our invoice number.
 *
 * Docs: https://paystation.com.bd/documentation
 */
class PayStationGateway extends AbstractGateway implements SupportsSync
{
    public const DRIVER = 'paystation';

    public const SANDBOX_URL = 'https://sandbox.paystation.com.bd';

    public const LIVE_URL = 'https://api.paystation.com.bd';

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
        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        $body = [
            'merchantId' => $this->credential('merchant_id'),
            'password' => $this->credential('password'),
            'invoice_number' => $payment->gateway_reference,
            'currency' => $payment->currency,
            'payment_amount' => $payment->amount,
            'reference' => $payment->reference,
            'cust_name' => $customer->name,
            'cust_phone' => $customer->phone,
            'cust_email' => $customer->email ?? 'noemail@example.com',
            'cust_address' => $customer->address ?? $customer->city,
            'callback_url' => $this->callbackUrl('callback'),
            'checkout_items' => $description ?? $payment->reference,
            'opt_a' => $payment->id,
        ];

        if ($this->payWithCharge()) {
            $body['pay_with_charge'] = 1;
        }

        $response = $this->send($this->http()->asForm(), 'POST', $this->baseUrl().'/initiate-payment', $body);
        $data = $this->decode($response);

        if (! $response->successful() || (string) ($data['status_code'] ?? '') !== '200' || empty($data['payment_url'])) {
            $payment->logGatewayResponse('create', $data)->save();

            $reason = isset($data['message'])
                ? $data['message'].(isset($data['status_code']) ? " ({$data['status_code']})" : '')
                : 'HTTP '.$response->status();

            throw new GatewayException('PayStation could not create the payment: '.$reason, $this->name(), $data);
        }

        $payment->checkout_url = (string) $data['payment_url'];
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    /**
     * The customer comes back to callback_url with ?status=Successful|Failed|Canceled
     * &invoice_number=...&trx_id=... None of it is trusted: the status API decides.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $payment = $this->findByGatewayReference((string) $request->input('invoice_number', ''));
        $claim = strtolower((string) $request->input('status', ''));

        return $this->apply($payment, $this->transactionStatus($payment), $outcome, in_array($claim, ['canceled', 'cancelled'], true));
    }

    public function sync(Payment $payment): Payment
    {
        $this->apply($payment, $this->transactionStatus($payment), 'sync');

        return $payment->refresh();
    }

    /**
     * POST /transaction-status by invoice number. Authenticated by the
     * merchantId header only, as PayStation documents it.
     *
     * @return array<array-key, mixed>
     */
    public function transactionStatus(Payment $payment): array
    {
        $request = $this->http()->withHeaders(['merchantId' => $this->credential('merchant_id')]);

        return $this->decode($this->send($request, 'POST', $this->baseUrl().'/transaction-status', [
            'invoice_number' => $payment->gateway_reference,
        ]));
    }

    /**
     * @param  array<array-key, mixed>  $response  A transaction status response.
     */
    protected function apply(Payment $payment, array $response, string $step, bool $cancelled = false): CallbackResult
    {
        $data = $response['data'] ?? null;

        // "Transaction not found", "Invalid Token.", a non-JSON body: none of
        // these says the payment failed. Leave it pending and sync later.
        if ((string) ($response['status_code'] ?? '') !== '200' || ! is_array($data)) {
            return $this->stillPending($payment, $step, $response);
        }

        if ((string) ($data['invoice_number'] ?? '') !== $payment->gateway_reference) {
            throw new InvalidCallbackException('PayStation returned a different invoice_number.', $this->name(), $response);
        }

        // The docs list "success"/"failed" and the samples "Success"/"Failed", but
        // the live sandbox answers "successful" (seen 2026-10-15). Accept all.
        return match (strtolower(trim((string) ($data['trx_status'] ?? '')))) {
            'success', 'successful' => $this->complete($payment, $data, $step, $response),
            'failed', 'failure' => $this->transition(
                $payment,
                $cancelled ? PaymentStatus::Cancelled : PaymentStatus::Failed,
                ['failure_reason' => $cancelled ? 'Cancelled by customer' : 'PayStation reported the transaction failed'],
                $step,
                $response,
            ),
            // "processing": started, not finished. "refund": refunded at
            // PayStation before we saw it paid; logged for a human to look at.
            default => $this->stillPending($payment, $step, $response),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, mixed>  $response
     */
    protected function complete(Payment $payment, array $data, string $step, array $response): CallbackResult
    {
        // request_amount is what we asked for. When the customer does not bear
        // the charge, what was paid (payment_amount) must be exactly that too.
        $this->assertAmountMatches($payment, $data['request_amount'] ?? $data['payment_amount'] ?? null);

        if (! $this->payWithCharge() && isset($data['request_amount'], $data['payment_amount'])) {
            $this->assertAmountMatches($payment, $data['payment_amount']);
        }

        $trxId = (string) ($data['trx_id'] ?? '');

        return $this->transition($payment, PaymentStatus::Completed, [
            'gateway_transaction_id' => $trxId !== '' ? $trxId : null,
        ], $step, $response);
    }

    protected function payWithCharge(): bool
    {
        return (bool) ($this->config['pay_with_charge'] ?? false);
    }
}
