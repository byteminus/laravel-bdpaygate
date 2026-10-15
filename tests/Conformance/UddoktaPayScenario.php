<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A self-hosted UddoktaPay install at pay.merchant.test. Response shapes
 * follow uddoktapay.readme.io (Create Charge, Verify Payment) and what the
 * public sandbox returned on 2026-10-15 ("No Data Found" as status ERROR).
 *
 * UddoktaPay only reports COMPLETED, PENDING or ERROR, so there is no
 * verifiable failure callback: failureCallback() stays null.
 */
class UddoktaPayScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'uddoktapay';
    }

    public function config(): array
    {
        return [
            'driver' => 'uddoktapay',
            'api_key' => 'test-uddoktapay-key',
            'base_url' => 'https://pay.merchant.test',
            'sandbox' => false,
        ];
    }

    public function stubs(): array
    {
        return [
            'pay.merchant.test/api/checkout-v2' => fn (Request $r) => Http::response([
                'status' => true,
                'message' => 'Payment Url',
                'payment_url' => 'https://pay.merchant.test/payment/'.sha1((string) $r['metadata']['gateway_reference']),
            ]),

            'pay.merchant.test/api/verify-payment' => $this->verification(function (Request $r) {
                $payment = Payment::query()
                    ->where('gateway', $this->gateway())
                    ->where('gateway_reference', substr((string) $r['invoice_id'], 4))
                    ->first();

                if (! $payment || $this->state === 'failed') {
                    return Http::response(['status' => 'ERROR', 'message' => 'No Data Found']);
                }

                return Http::response($this->invoice($payment, $this->state === 'paid' ? 'COMPLETED' : 'PENDING'));
            }),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/uddoktapay/success', ['invoice_id' => $this->invoiceId($payment)]);
    }

    /**
     * Proposed GatewayScenario hook: the request that tells the driver about a
     * payment the gateway has registered but not settled, hit (with state
     * "unpaid") before the sync test. UddoktaPay only creates the invoice_id
     * when the customer submits a payment, so charge() alone gives sync
     * nothing to look up.
     */
    public function beforeSync(Payment $payment): ?CallbackRequest
    {
        return $this->successCallback($payment);
    }

    public function invoiceId(Payment $payment): string
    {
        return 'INV-'.$payment->gateway_reference;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(Payment $payment, string $status): array
    {
        return [
            'full_name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
            'amount' => $this->reported($payment),
            'fee' => '0.00',
            'charged_amount' => $this->reported($payment),
            'invoice_id' => $this->invoiceId($payment),
            'metadata' => ['gateway_reference' => $payment->gateway_reference, 'reference' => $payment->reference, 'payment_id' => $payment->id],
            'payment_method' => 'bkash',
            'sender_number' => '01311111111',
            'transaction_id' => 'TESTTRANS'.substr($payment->gateway_reference, 2, 6),
            'date' => '2026-10-15 14:00:50',
            'status' => $status,
        ];
    }
}
