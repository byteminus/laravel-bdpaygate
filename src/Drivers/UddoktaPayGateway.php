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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * UddoktaPay checkout-v2. UddoktaPay is self-hosted software, not a licensed
 * gateway: each merchant runs it on their own domain, so "base_url" is the
 * merchant's install (only the public sandbox host is built in).
 *
 * UddoktaPay generates the invoice_id when the customer submits a payment;
 * nothing identifies the payment before that. Our attempt id travels in
 * "metadata", and the payment is found from the verify-payment response,
 * never from the callback body. The webhook only echoes the API key, so it
 * is verified with verify-payment too.
 *
 * No refunds: /api/refund-payment exists but its response is undocumented.
 *
 * Docs: https://uddoktapay.readme.io/reference/overview
 */
class UddoktaPayGateway extends AbstractGateway implements SupportsSync
{
    public const DRIVER = 'uddoktapay';

    public const SANDBOX_URL = 'https://sandbox.uddoktapay.com';

    public const API_KEY_HEADER = 'RT-UDDOKTAPAY-API-KEY';

    public function baseUrl(): string
    {
        $custom = $this->config['base_url'] ?? null;

        if (! $this->sandbox() && (! is_string($custom) || trim($custom) === '')) {
            throw new GatewayException(
                "Missing [base_url] in the [{$this->name()}] gateway config: UddoktaPay runs on your own domain.",
                $this->name(),
            );
        }

        // Accept the panel URL or a pasted API URL ("https://pay.example.com/api/checkout-v2").
        return (string) preg_replace('#/api(/.*)?$#i', '', $this->endpoint(self::SANDBOX_URL, self::SANDBOX_URL));
    }

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        if ($this->currency() !== 'BDT') {
            throw new GatewayException('UddoktaPay checkout-v2 accepts BDT only.', $this->name());
        }

        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        $response = $this->send($this->api(), 'POST', $this->baseUrl().'/api/checkout-v2', [
            'full_name' => $customer->name,
            'email' => $customer->email ?? 'noemail@example.com',
            'amount' => $payment->amount,
            'metadata' => [
                'gateway_reference' => $payment->gateway_reference,
                'reference' => $payment->reference,
                'payment_id' => $payment->id,
            ],
            // invoice_id is appended to redirect_url, so it carries no query of its own.
            'redirect_url' => $this->callbackUrl('success'),
            'return_type' => 'GET',
            // The cancel link is used as given, with no invoice_id: our reference rides along.
            'cancel_url' => $this->callbackUrl('cancel', ['ref' => $payment->gateway_reference]),
            'webhook_url' => $this->callbackUrl('ipn'),
        ]);

        $data = $this->decode($response);

        if (! $response->successful() || ($data['status'] ?? null) !== true || empty($data['payment_url'])) {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException(
                'UddoktaPay could not create the payment: '.($data['message'] ?? 'HTTP '.$response->status()),
                $this->name(),
                $data,
            );
        }

        $payment->checkout_url = (string) $data['payment_url'];
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        if ($outcome === 'ipn') {
            $this->assertWebhookKey($request);
        }

        $invoiceId = (string) $request->input('invoice_id', '');

        if ($outcome === 'cancel') {
            return $this->handleCancel($request, $invoiceId);
        }

        if ($invoiceId === '') {
            throw new InvalidCallbackException('UddoktaPay callback has no invoice_id.', $this->name(), $request->all());
        }

        $data = $this->verify($invoiceId);

        return $this->apply($this->paymentFor($data, $invoiceId), $invoiceId, $data, $outcome);
    }

    /**
     * The customer left the checkout page. UddoktaPay has no invoice for a
     * payment nobody submitted, so there is nothing to confirm a cancel
     * against: the attempt stays pending unless a known invoice says it was paid.
     */
    protected function handleCancel(Request $request, string $invoiceId): CallbackResult
    {
        $payment = $this->findByGatewayReference((string) $request->query('ref', ''));
        $invoiceId = $invoiceId !== '' ? $invoiceId : (string) $payment->gateway_payment_id;

        if ($invoiceId === '') {
            return $this->stillPending($payment, 'cancel', ['note' => 'Cancelled before any payment was submitted; nothing to verify.']);
        }

        $data = $this->verify($invoiceId);

        if ($this->paymentFor($data, $invoiceId)->isNot($payment)) {
            throw new InvalidCallbackException('UddoktaPay invoice belongs to a different payment.', $this->name(), $data);
        }

        return $this->apply($payment, $invoiceId, $data, 'cancel');
    }

    public function sync(Payment $payment): Payment
    {
        // No invoice_id yet: the customer has not submitted a payment, and
        // UddoktaPay has nothing to look up.
        if (! $payment->gateway_payment_id) {
            return $payment->refresh();
        }

        $data = $this->verify($payment->gateway_payment_id);

        if ($this->paymentFor($data, $payment->gateway_payment_id)->isNot($payment)) {
            throw new InvalidCallbackException('UddoktaPay invoice belongs to a different payment.', $this->name(), $data);
        }

        $this->apply($payment, $payment->gateway_payment_id, $data, 'sync');

        return $payment->refresh();
    }

    /**
     * POST /api/verify-payment. Returns the payment, or status "ERROR" with a
     * message ("No Data Found") for an unknown invoice.
     *
     * @return array<array-key, mixed>
     */
    public function verify(string $invoiceId): array
    {
        $response = $this->send($this->api(), 'POST', $this->baseUrl().'/api/verify-payment', ['invoice_id' => $invoiceId]);
        $data = $this->decode($response);

        if ($response->serverError()) {
            throw new GatewayUnavailableException("UddoktaPay verify-payment failed with HTTP {$response->status()}.", $this->name(), $data);
        }

        return $data;
    }

    /**
     * The payment a verified invoice belongs to, from the metadata we sent.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function paymentFor(array $data, string $invoiceId): Payment
    {
        $status = strtoupper((string) ($data['status'] ?? ''));

        if (! in_array($status, ['COMPLETED', 'PENDING'], true)) {
            throw new InvalidCallbackException(
                "UddoktaPay has no payment for invoice [{$invoiceId}]: ".($data['message'] ?? ($status ?: 'no status')),
                $this->name(),
                $data,
            );
        }

        if (isset($data['invoice_id']) && (string) $data['invoice_id'] !== $invoiceId) {
            throw new InvalidCallbackException('UddoktaPay verified a different invoice_id.', $this->name(), $data);
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return $this->findByGatewayReference((string) ($metadata['gateway_reference'] ?? ''));
    }

    /**
     * @param  array<array-key, mixed>  $data  A verify-payment response for this payment.
     */
    protected function apply(Payment $payment, string $invoiceId, array $data, string $step): CallbackResult
    {
        if (strtoupper((string) ($data['status'] ?? '')) === 'COMPLETED') {
            $this->assertAmountMatches($payment, $data['amount'] ?? null);

            $transactionId = (string) ($data['transaction_id'] ?? '');

            return $this->transition($payment, PaymentStatus::Completed, [
                'gateway_payment_id' => $invoiceId,
                'gateway_transaction_id' => $transactionId !== '' ? $transactionId : null,
            ], $step, $data);
        }

        // PENDING: submitted, waiting for the merchant (or the software) to
        // match it. Keep the invoice_id so sync can ask again.
        if ($payment->isPending()) {
            $payment->gateway_payment_id = $invoiceId;
        }

        return $this->stillPending($payment, $step, $data);
    }

    /**
     * The webhook carries our own API key in a header, nothing more. A
     * mismatch is rejected; a match still goes through verify-payment.
     */
    protected function assertWebhookKey(Request $request): void
    {
        $key = (string) $request->header(self::API_KEY_HEADER, '');

        if ($key === '' || ! hash_equals($this->credential('api_key'), $key)) {
            throw new InvalidCallbackException('UddoktaPay webhook API key does not match.', $this->name());
        }
    }

    protected function api(): PendingRequest
    {
        return $this->http()->asJson()->withHeaders([self::API_KEY_HEADER => $this->credential('api_key')]);
    }
}
