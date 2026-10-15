<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class MoneybagScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'moneybag';
    }

    public function config(): array
    {
        return [
            'driver' => 'moneybag',
            'api_key' => 'mb-merchant-key',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            'sandbox.api.moneybag.com.bd/api/v2/payments/checkout' => fn (Request $r) => Http::response([
                'success' => true,
                'data' => [
                    'checkout_url' => 'https://sandbox.payment.moneybag.com.bd/moneybag-landing?sessionId=ps'.$r['order_id'],
                    'session_id' => 'ps'.$r['order_id'],
                    'expires_at' => '2026-10-15T15:00:00Z',
                ],
                'message' => 'Checkout session created',
            ]),

            'sandbox.api.moneybag.com.bd/api/v2/payments/verify/*' => $this->verification(function (Request $r) {
                $transactionId = basename((string) parse_url($r->url(), PHP_URL_PATH));
                $payment = $this->payment('gateway_reference', substr($transactionId, 3));

                return Http::response($this->verified($payment, match ($this->state) {
                    'paid' => 'SUCCESS',
                    'failed' => 'FAILED',
                    default => 'PENDING',
                }));
            }),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/moneybag/success', [
            'ref' => $payment->gateway_reference,
            'transaction_id' => 'txn'.$payment->gateway_reference,
            'status' => 'SUCCESS',
        ]);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/moneybag/fail', [
            'ref' => $payment->gateway_reference,
            'transaction_id' => 'txn'.$payment->gateway_reference,
            'status' => 'FAILED',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function verified(Payment $payment, string $status): array
    {
        return [
            'success' => true,
            'data' => [
                'transaction_id' => 'txn'.$payment->gateway_reference,
                'order_id' => $payment->gateway_reference,
                'verified' => $status === 'SUCCESS',
                'status' => $status,
                'amount' => $this->reported($payment),
                'charge_amount' => '0.00',
                'currency' => 'BDT',
                'payment_method' => 'card',
                'payment_reference_id' => 'REF'.substr($payment->gateway_reference, 2, 8),
                'customer' => ['name' => 'Rahim Uddin', 'email' => 'rahim@example.com', 'phone' => '01711000000'],
            ],
            'message' => 'Payment verification details retrieved',
        ];
    }
}
