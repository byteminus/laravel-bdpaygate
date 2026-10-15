<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Response shapes follow PayStation's documentation and what its sandbox
 * returned on 2026-10-15 (string status_code, HTTP 200 for business errors,
 * "Success"/"Failed" capitalised in trx_status).
 */
class PayStationScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'paystation';
    }

    public function config(): array
    {
        return [
            'driver' => 'paystation',
            'merchant_id' => '104-0000000000',
            'password' => 'test-store-pass',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            'sandbox.paystation.com.bd/initiate-payment' => fn (Request $r) => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'message' => 'Payment Link Created Successfully.',
                'payment_amount' => $r['payment_amount'],
                'invoice_number' => $r['invoice_number'],
                'payment_url' => 'https://sandbox.paystation.com.bd/checkout/12117920422621191/'.$r['invoice_number'],
            ]),

            'sandbox.paystation.com.bd/transaction-status' => $this->verification(function (Request $r) {
                $payment = Payment::query()->where('gateway', $this->gateway())->where('gateway_reference', $r['invoice_number'])->first();

                if (! $payment) {
                    return Http::response(['status_code' => '2001', 'status' => 'failed', 'message' => 'Transaction not found in system']);
                }

                return Http::response([
                    'status_code' => '200',
                    'status' => 'success',
                    'message' => 'Transaction found',
                    'data' => $this->transaction($payment),
                ]);
            }),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/paystation/callback', [
            'status' => 'Successful',
            'invoice_number' => $payment->gateway_reference,
            'trx_id' => 'TRX'.substr($payment->gateway_reference, 2, 8),
        ]);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/paystation/callback', [
            'status' => 'Failed',
            'invoice_number' => $payment->gateway_reference,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction(Payment $payment, ?string $trxStatus = null): array
    {
        $paid = $this->state === 'paid';

        return [
            'invoice_number' => $payment->gateway_reference,
            'trx_status' => $trxStatus ?? match ($this->state) {
                'paid' => 'Success',
                'failed' => 'Failed',
                default => 'processing',
            },
            'trx_id' => $paid ? 'TRX'.substr($payment->gateway_reference, 2, 8) : '',
            'request_amount' => $this->reported($payment),
            'payment_amount' => $this->reported($payment),
            'order_date_time' => '2026-10-15 11:31:02',
            'payer_mobile_no' => $paid ? '01700000001' : '',
            'payment_method' => $paid ? 'bKash' : '',
            'reference' => $payment->reference,
            'checkout_items' => null,
            'opt_a' => $payment->id,
            'opt_b' => null,
            'opt_c' => null,
        ];
    }
}
