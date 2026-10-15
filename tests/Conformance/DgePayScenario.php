<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Drivers\DgePay\DgePayCrypto;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class DgePayScenario extends GatewayScenario
{
    public const SECRET = 'dgepay-secret-16';

    public const API_KEY = 'dgepay-api-key';

    public function gateway(): string
    {
        return 'dgepay';
    }

    public function config(): array
    {
        return [
            'driver' => 'dgepay',
            'client_id' => 'dgepay-client',
            'client_secret' => self::SECRET,
            'api_key' => self::API_KEY,
            'sandbox' => true,
        ];
    }

    public function crypto(): DgePayCrypto
    {
        return new DgePayCrypto(self::SECRET, self::API_KEY);
    }

    public function stubs(): array
    {
        return [
            'api-uat.dgepay.net/dipon/v3/payment_gateway/authenticate' => Http::response([
                'status_code' => 200,
                'success' => 1,
                'data' => ['access_token' => 'dgepay.jwt.token', 'expiry_time' => now()->addHour()->format('Y-m-d H:i:s')],
                'error' => [],
            ]),

            'api-uat.dgepay.net/dipon/v3/payment_gateway/initiate_payment' => function (Request $r) {
                $body = $this->crypto()->decrypt($r->body()) ?? [];

                return Http::response([
                    'status_code' => 200,
                    'success' => 1,
                    'data' => [
                        'webview_url' => 'https://pay-uat.dgepay.net/checkout/'.($body['unique_txn_id'] ?? ''),
                        'unique_txn_id' => $body['unique_txn_id'] ?? null,
                    ],
                    'error' => [],
                ]);
            },

            'api-uat.dgepay.net/dipon/v3/payment_gateway/check_transaction_status' => $this->verification(function (Request $r) {
                $body = $this->crypto()->decrypt($r->body()) ?? [];
                $payment = $this->payment('gateway_reference', (string) ($body['unique_txn_id'] ?? ''));

                return Http::response([
                    'status_code' => 200,
                    'data' => [
                        'message' => match ($this->state) {
                            'paid' => 'TRANSACTION SUCCESSFUL',
                            'failed' => 'TRANSACTION FAILED',
                            default => 'TRANSACTION INITIATED',
                        },
                        'txn_id' => 'TXN'.$payment->gateway_reference,
                        'status_code' => match ($this->state) {
                            'paid' => '3',
                            'failed' => '4',
                            default => '1',
                        },
                        'amount' => (float) $this->reported($payment),
                        'created_date' => now()->getTimestamp(),
                        'txn_number' => '50534914',
                        'payment_method' => 'bKash',
                        'unique_txn_id' => $payment->gateway_reference,
                    ],
                ]);
            }),
        ];
    }

    /**
     * What DGePay appends to redirect_url: ?data=<encrypted JSON>.
     *
     * @param  array<string, mixed>  $fields
     */
    public function redirect(Payment $payment, array $fields = []): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/dgepay/callback', [
            'data' => $this->crypto()->encrypt($fields + [
                'status_code' => '3',
                'unique_txn_id' => $payment->gateway_reference,
                'txn_number' => '50534914',
                'txn_id' => 'TXN'.$payment->gateway_reference,
                'payment_method' => 'bKash',
                'amount' => (float) $payment->amount,
                'third_party_txn_number' => 'BK123',
            ]),
        ]);
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return $this->redirect($payment);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return $this->redirect($payment, ['status_code' => '8', 'message' => 'TRANSACTION CANCELLED']);
    }
}
