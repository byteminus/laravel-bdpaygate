<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Moneybag hosted checkout (public API v2): checkout, then verify.
 *
 * The public contract has no lookup by order or session and no refund API,
 * so this driver has no sync() or refund(). A payment is settled from the
 * transaction_id Moneybag appends to the return URL, or later with
 * verifyTransaction() and an id from the merchant portal.
 *
 * Docs: https://developers.moneybag.com.bd/docs
 */
class MoneybagGateway extends AbstractGateway
{
    public const DRIVER = 'moneybag';

    public const SANDBOX_URL = 'https://sandbox.api.moneybag.com.bd';

    public const LIVE_URL = 'https://api.moneybag.com.bd';

    public function baseUrl(): string
    {
        return $this->endpoint(self::SANDBOX_URL, self::LIVE_URL);
    }

    protected function request(): PendingRequest
    {
        return $this->http()->withHeaders(['X-Merchant-API-Key' => $this->credential('api_key')]);
    }

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        if ($this->currency() === 'BDT' && ((float) $amount < 10 || (float) $amount > 1000000)) {
            throw new GatewayException('Moneybag accepts 10.00 to 1000000.00 BDT.', $this->name());
        }

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);
        $ref = ['ref' => $payment->gateway_reference];

        $response = $this->send($this->request(), 'POST', $this->baseUrl().'/api/v2/payments/checkout', [
            'order_id' => $payment->gateway_reference,
            'order_amount' => $payment->amount,
            'currency' => $payment->currency,
            'order_description' => mb_substr($description ?? 'Order '.$payment->reference, 0, 255),
            'success_url' => $this->callbackUrl('success', $ref),
            'fail_url' => $this->callbackUrl('fail', $ref),
            'cancel_url' => $this->callbackUrl('cancel', $ref),
            'customer' => array_filter([
                'name' => mb_substr($customer->name, 0, 255),
                'email' => $customer->email ?? 'noemail@example.com',
                'phone' => $customer->phone,
                'address' => $customer->address !== null ? mb_substr($customer->address, 0, 200) : null,
                'city' => mb_substr($customer->city, 0, 50),
                'country' => mb_substr($customer->country, 0, 50),
                'postcode' => ($postcode = $customer->postcode ?? $meta['postcode'] ?? null) !== null ? mb_substr((string) $postcode, 0, 20) : null,
            ], fn ($value) => $value !== null),
            'metadata' => ['reference' => $payment->reference],
        ]);

        $data = $this->decode($response);
        $url = $data['data']['checkout_url'] ?? null;

        if (! $response->successful() || ($data['success'] ?? null) !== true || ! is_string($url) || $url === '') {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException('Moneybag could not create the checkout: '.$this->errorMessage($data, $response), $this->name(), $data);
        }

        $payment->gateway_payment_id = isset($data['data']['session_id']) ? (string) $data['data']['session_id'] : null;
        $payment->checkout_url = $url;
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    /**
     * Moneybag returns the customer with ?transaction_id=...&status=... added
     * to our URL. Neither is trusted: the transaction is verified, and must
     * belong to this order.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        $payment = $this->findByGatewayReference((string) $request->query('ref', ''));
        $transactionId = (string) $request->query('transaction_id', '');

        // A checkout abandoned before a transaction existed: nothing to verify.
        if ($transactionId === '') {
            return $this->stillPending($payment, $outcome, $request->query->all());
        }

        return $this->confirm($payment, $transactionId, $outcome);
    }

    /**
     * Settle a payment from a Moneybag transaction id, e.g. one found in the
     * merchant portal after the customer never came back.
     */
    public function verifyTransaction(Payment $payment, string $transactionId): Payment
    {
        $this->confirm($payment, $transactionId, 'verify');

        return $payment->refresh();
    }

    protected function confirm(Payment $payment, string $transactionId, string $step): CallbackResult
    {
        $response = $this->send($this->request(), 'GET', $this->baseUrl().'/api/v2/payments/verify/'.rawurlencode($transactionId));
        $data = $this->decode($response);
        $transaction = $data['data'] ?? null;

        // Unknown or expired transaction, validation error, or no clear answer.
        if (! $response->successful() || ($data['success'] ?? null) !== true || ! is_array($transaction)) {
            return $this->stillPending($payment, $step, $data);
        }

        if ((string) ($transaction['order_id'] ?? '') !== $payment->gateway_reference) {
            throw new InvalidCallbackException('Moneybag transaction belongs to a different order.', $this->name(), $data);
        }

        $status = strtoupper((string) ($transaction['status'] ?? ''));
        $attributes = ['gateway_transaction_id' => (string) ($transaction['transaction_id'] ?? $transactionId)];

        if ($status === 'SUCCESS' && ($transaction['verified'] ?? null) === true) {
            $this->assertAmountMatches($payment, $transaction['amount'] ?? null, isset($transaction['currency']) ? (string) $transaction['currency'] : null);

            return $this->transition($payment, PaymentStatus::Completed, $attributes, $step, $data);
        }

        return match ($status) {
            'FAILED' => $this->transition($payment, PaymentStatus::Failed, $attributes + ['failure_reason' => 'Payment failed'], $step, $data),
            'CANCELLED' => $this->transition($payment, PaymentStatus::Cancelled, $attributes, $step, $data),
            default => $this->stillPending($payment, $step, $data),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function errorMessage(array $data, Response $response): string
    {
        if (isset($data['message']) && is_string($data['message']) && $data['message'] !== '') {
            return $data['message'];
        }

        // 422: {"detail": [{"loc": [...], "msg": "...", "type": "..."}]}
        if (isset($data['detail']) && is_array($data['detail'])) {
            $messages = [];

            foreach ($data['detail'] as $item) {
                if (is_array($item) && isset($item['msg'])) {
                    $field = is_array($item['loc'] ?? null) ? implode('.', array_map('strval', $item['loc'])) : '';
                    $messages[] = trim($field.' '.$item['msg']);
                }
            }

            if ($messages !== []) {
                return implode('; ', $messages);
            }
        }

        return 'HTTP '.$response->status();
    }
}
