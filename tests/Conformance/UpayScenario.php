<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class UpayScenario extends GatewayScenario
{
    public const BASE = 'uat-pg.upay.systems';

    /**
     * How the simulated Upay answers a refund: "success", "under_process" or "rejected".
     */
    public string $refund = 'success';

    public function gateway(): string
    {
        return 'upay';
    }

    public function config(): array
    {
        return [
            'driver' => 'upay',
            'merchant_id' => '1110101010000001',
            'merchant_key' => 'test-merchant-key',
            'merchant_code' => '1234',
            'merchant_name' => 'Test Shop',
            'merchant_mobile' => '01700000000',
            'merchant_city' => 'Dhaka',
            'merchant_category_code' => '5999',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            self::BASE.'/payment/merchant-auth/' => Http::response([
                'code' => 'MAS2001',
                'message' => 'Merchant authorization successful',
                'lang' => 'en',
                'data' => ['token' => 'JWT-TEST-TOKEN'],
            ]),

            self::BASE.'/payment/merchant-payment-init/' => fn (Request $r) => Http::response([
                'code' => 'MPIS2002',
                'message' => 'Merchant payment initiated successfully',
                'lang' => 'en',
                'data' => [
                    'session_id' => 'SESS'.$r['txn_id'],
                    'txn_id' => $r['txn_id'],
                    'trx_id' => '01JUPAY'.$r['txn_id'],
                    'invoice_id' => $r['invoice_id'],
                    'merchant_id' => $r['merchant_id'],
                    'gateway_url' => 'https://'.self::BASE.'/gateway/SESS'.$r['txn_id'].'/',
                ],
            ]),

            self::BASE.'/payment/single-payment-status/*' => $this->verification(function (Request $r) {
                $txnId = basename((string) parse_url($r->url(), PHP_URL_PATH));
                $payment = Payment::query()->where('gateway', 'upay')->where('gateway_reference', $txnId)->first();

                if (! $payment) {
                    return Http::response(['code' => 'PNF4012', 'message' => 'Payment not found', 'lang' => 'en', 'data' => null], 404);
                }

                return Http::response([
                    'code' => 'PS2005',
                    'message' => 'Payment status',
                    'lang' => 'en',
                    'data' => [
                        'txn_id' => $payment->gateway_reference,
                        'trx_id' => '01JUPAY'.$payment->gateway_reference,
                        'invoice_id' => $payment->gateway_reference,
                        'session_id' => 'SESS'.$payment->gateway_reference,
                        'status' => match ($this->state) {
                            'paid' => 'success',
                            'failed' => 'failed',
                            default => 'pending',
                        },
                        // A JSON number, as Upay sends it.
                        'amount' => (float) $this->reported($payment),
                        'merchant_name' => 'Test Shop',
                        'customer_wallet' => '017****0000',
                        'date' => '2026-10-15',
                    ],
                ]);
            }),

            self::BASE.'/payment/bulk/refund/' => function (Request $r) {
                $txnId = (string) ($r->data()[0]['txn_id'] ?? '');

                return Http::response([
                    'code' => 'MPR_200',
                    'message' => 'Refund request processed',
                    'lang' => 'en',
                    'data' => match ($this->refund) {
                        'success' => ['success_list' => [$txnId], 'failed_list' => (object) []],
                        'under_process' => ['success_list' => [], 'failed_list' => [$txnId => [
                            'en' => 'Your request is under process. Please, wait for confirmation',
                            'bn' => '...',
                        ]]],
                        default => ['success_list' => [], 'failed_list' => [$txnId => ['en' => 'Invalid amount', 'bn' => '...']]],
                    },
                ]);
            },
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/upay/callback', [
            'txn' => $payment->gateway_reference,
            'status' => 'success',
            'invoice_id' => $payment->gateway_reference,
        ]);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/upay/callback', [
            'txn' => $payment->gateway_reference,
            'status' => 'failed',
            'invoice_id' => $payment->gateway_reference,
        ]);
    }
}
