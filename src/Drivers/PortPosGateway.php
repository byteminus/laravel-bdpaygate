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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * PortPos (formerly PortWallet) hosted invoice checkout, API v2: cards,
 * mobile wallets, net banking and EMI.
 *
 * The browser return and the IPN are unsigned, so every one is confirmed with
 * the IPN validate API, which takes the invoice id and the expected amount.
 *
 * Docs: https://developer.portpos.com/documentation-v2.php
 */
class PortPosGateway extends AbstractGateway implements SupportsPartialRefunds, SupportsSync
{
    use HandlesRefunds;

    public const DRIVER = 'portpos';

    public const SANDBOX_URL = 'https://api-sandbox.portpos.com';

    public const LIVE_URL = 'https://api.portpos.com';

    public const SANDBOX_PAYMENT_URL = 'https://ppv2.portpos.com';

    public const LIVE_PAYMENT_URL = 'https://payment.portpos.com';

    /**
     * Invoice states that mean the customer paid. The refund states only
     * follow an accepted payment, so they prove the money moved too.
     */
    private const PAID = ['ACCEPTED', 'REFUND_PENDING', 'REFUND_PROCESSING'];

    public function baseUrl(): string
    {
        return $this->endpoint(self::SANDBOX_URL, self::LIVE_URL);
    }

    /**
     * Every call carries a fresh token: base64("app_key:" . md5(secret_key . unix time)).
     * Keep the server clock NTP-synced; a skewed clock is rejected with 401.
     */
    protected function request(): PendingRequest
    {
        $token = base64_encode($this->credential('app_key').':'.md5($this->credential('secret_key').now()->getTimestamp()));

        return $this->http()->withToken($token);
    }

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        $country = $this->countryCode($customer->country);
        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);
        $product = $this->atLeastThree($description, 'Order '.$payment->reference);

        $order = [
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'redirect_url' => $this->callbackUrl('callback', ['ref' => $payment->gateway_reference]),
            'ipn_url' => $this->callbackUrl('ipn', ['ref' => $payment->gateway_reference]),
            'reference' => $payment->gateway_reference,
        ];

        if (isset($this->config['validity']) && is_numeric($this->config['validity'])) {
            $order['validity'] = (int) $this->config['validity'];
        }

        $response = $this->send($this->request(), 'POST', $this->baseUrl().'/payment/v2/invoice', [
            'order' => $order,
            'product' => [
                'name' => mb_substr($product, 0, 150),
                'description' => mb_substr($product, 0, 300),
            ],
            'billing' => [
                'customer' => [
                    'name' => mb_substr($customer->name, 0, 50),
                    'email' => $customer->email ?? 'noemail@example.com',
                    'phone' => $customer->phone,
                    'address' => [
                        'street' => mb_substr($customer->address ?? $customer->city, 0, 200),
                        'city' => mb_substr($customer->city, 0, 200),
                        'state' => mb_substr((string) ($customer->state ?? $meta['state'] ?? $customer->city), 0, 50),
                        'zipcode' => (string) ($customer->postcode ?? $meta['zipcode'] ?? $this->config['zipcode'] ?? '1000'),
                        'country' => $country,
                    ],
                ],
            ],
        ]);

        $data = $this->decode($response);
        $invoiceId = (string) ($data['data']['invoice_id'] ?? '');

        if (! $response->successful() || ! $this->succeeded($data) || $invoiceId === '') {
            $payment->logGatewayResponse('create', $data)->save();

            throw new GatewayException('PortPos could not create the invoice: '.$this->errorMessage($data, $response), $this->name(), $data);
        }

        $url = $data['data']['action']['url'] ?? null;

        $payment->gateway_payment_id = $invoiceId;
        $payment->checkout_url = is_string($url) && $url !== ''
            ? $url
            : ($this->sandbox() ? self::SANDBOX_PAYMENT_URL : self::LIVE_PAYMENT_URL).'/v2/invoice/'.rawurlencode($invoiceId);
        $payment->logGatewayResponse('create', $data)->save();

        return $payment;
    }

    /**
     * The customer's return ("callback") and the IPN are handled alike: both
     * are claims, and only the IPN validate API decides.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        return $this->confirm($this->findPayment($request), $outcome);
    }

    /**
     * Our own ?ref= on the URLs we registered; the IPN body's reference
     * (which is ours) or invoice id as a fallback. Whichever finds the
     * payment, verification uses the invoice id and amount we stored.
     */
    protected function findPayment(Request $request): Payment
    {
        $ref = (string) ($request->query('ref') ?: $request->input('reference', ''));

        if ($ref !== '') {
            return $this->findByGatewayReference($ref);
        }

        return $this->findByGatewayPaymentId((string) $request->input('invoice', ''));
    }

    /**
     * Ask PortPos for the invoice and apply what it says. Anything other than
     * a clear answer leaves the payment as it is.
     */
    protected function confirm(Payment $payment, string $step): CallbackResult
    {
        $data = $this->validateInvoice($payment);
        $invoice = $data['data'] ?? null;

        if (! is_array($invoice) || ! $this->succeeded($data)) {
            return $this->stillPending($payment, $step, $data);
        }

        if ((string) ($invoice['invoice_id'] ?? '') !== $payment->gateway_payment_id) {
            throw new InvalidCallbackException('PortPos validation returned a different invoice.', $this->name(), $data);
        }

        $status = strtoupper((string) ($invoice['order']['status'] ?? ''));

        if (in_array($status, self::PAID, true)) {
            $this->assertAmountMatches($payment, $invoice['order']['amount'] ?? null, $invoice['order']['currency'] ?? null);

            return $this->transition($payment, PaymentStatus::Completed, [
                // PortPos has no transaction id; the processor's approval code is the closest thing.
                'gateway_transaction_id' => $invoice['billing']['gateway']['approve_code'] ?? null,
            ], $step, $data);
        }

        return match ($status) {
            'CANCELLED' => $this->transition($payment, PaymentStatus::Cancelled, ['failure_reason' => 'Invoice cancelled'], $step, $data),
            'EXPIRED' => $this->transition($payment, PaymentStatus::Failed, ['failure_reason' => 'Invoice expired'], $step, $data),
            // PENDING, or a state PortPos has not documented: the customer may still pay.
            default => $this->stillPending($payment, $step, $data),
        };
    }

    /**
     * GET /payment/v2/invoice/ipn/{invoice_id}/{amount}, with the amount we
     * charged, never the one in the request.
     *
     * @return array<array-key, mixed>
     */
    public function validateInvoice(Payment $payment): array
    {
        if (! $payment->gateway_payment_id) {
            throw new InvalidCallbackException("Payment [{$payment->gateway_reference}] has no PortPos invoice id.", $this->name());
        }

        return $this->decode($this->send(
            $this->request(),
            'GET',
            $this->baseUrl().'/payment/v2/invoice/ipn/'.rawurlencode($payment->gateway_payment_id).'/'.$this->amount($payment->amount),
        ));
    }

    public function sync(Payment $payment): Payment
    {
        if ($payment->gateway_payment_id) {
            $this->confirm($payment, 'sync');
        }

        return $payment->refresh();
    }

    /**
     * PortPos takes up to five refunds per invoice, settles them
     * asynchronously, and returns no refund id.
     */
    protected function requestRefund(Payment $payment, string $amount, ?string $reason): RefundResult
    {
        if (! $payment->gateway_payment_id) {
            throw new GatewayException("Payment [{$payment->reference}] has no PortPos invoice id.", $this->name());
        }

        $response = $this->send($this->request(), 'POST', $this->baseUrl().'/payment/v2/invoice/refund/'.rawurlencode($payment->gateway_payment_id), [
            'refund' => ['amount' => $amount, 'currency' => $payment->currency],
        ]);

        $data = $this->decode($response);
        $accepted = $response->successful()
            && $this->succeeded($data)
            && strtoupper((string) ($data['data']['refund']['status'] ?? '')) === 'APPROVED';

        return new RefundResult(
            payment: $payment,
            accepted: $accepted,
            amount: $amount,
            refundId: null,
            message: $accepted ? (string) ($data['data']['order']['status'] ?? 'REFUND_PENDING') : $this->errorMessage($data, $response),
            raw: $data,
            pending: $accepted,
        );
    }

    /**
     * The invoice's current state, lowercased: "refund_pending",
     * "refund_processing", "accepted", ... PortPos has no per-refund status
     * endpoint; this reads the invoice.
     */
    public function refundStatus(Payment $payment): string
    {
        if (! $payment->gateway_payment_id) {
            return 'unknown';
        }

        $data = $this->decode($this->send($this->request(), 'GET', $this->baseUrl().'/payment/v2/invoice/'.rawurlencode($payment->gateway_payment_id)));

        return strtolower((string) ($data['data']['order']['status'] ?? 'unknown'));
    }

    /**
     * "result" is "success" on reads and "SUCCESS" on refunds.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function succeeded(array $data): bool
    {
        return strtolower((string) ($data['result'] ?? '')) === 'success';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function errorMessage(array $data, Response $response): string
    {
        $error = $data['error'] ?? null;

        if (is_array($error)) {
            $message = $error['explanation'] ?? $error['cause'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message.(isset($error['property']) && is_string($error['property']) ? " ({$error['property']})" : '');
            }
        }

        return 'HTTP '.$response->status();
    }

    /**
     * PortPos wants an ISO 3166 alpha-2 or alpha-3 code.
     */
    protected function countryCode(string $country): string
    {
        $country = trim($country);

        if (preg_match('/^[A-Za-z]{2,3}$/', $country) === 1) {
            return strtoupper($country);
        }

        if (strcasecmp($country, 'Bangladesh') === 0) {
            return 'BD';
        }

        throw new GatewayException("PortPos needs an ISO 3166 country code, got [{$country}].", $this->name());
    }

    /**
     * Product name and description must be at least 3 characters.
     */
    protected function atLeastThree(?string $value, string $fallback): string
    {
        return $value !== null && mb_strlen(trim($value)) >= 3 ? $value : $fallback;
    }
}
