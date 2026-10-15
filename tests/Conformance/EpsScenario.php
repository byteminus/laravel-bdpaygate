<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class EpsScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'eps';
    }

    public function config(): array
    {
        return [
            'driver' => 'eps',
            'username' => 'eps-user',
            'password' => 'eps-pass',
            'hash_key' => 'eps-hash-key',
            'merchant_id' => 'MID-1',
            'store_id' => 'SID-1',
            'sandbox' => true,
            'status_retry_delay' => 0,
        ];
    }

    public function stubs(): array
    {
        return [
            'sandboxpgapi.eps.com.bd/v1/Auth/GetToken' => Http::response([
                'token' => 'eps.jwt.token',
                'expireDate' => now()->addHour()->toIso8601String(),
                'errorMessage' => null,
                'errorCode' => null,
            ]),

            'sandboxpgapi.eps.com.bd/v1/EPSEngine/InitializeEPS' => fn (Request $r) => Http::response([
                'TransactionId' => 'EPS'.$r['merchantTransactionId'],
                'RedirectURL' => 'https://sandboxpg.eps.com.bd/PG?data='.$r['merchantTransactionId'],
                'ErrorMessage' => null,
                'ErrorCode' => null,
                'FinancialEntityList' => null,
            ]),

            'sandboxpgapi.eps.com.bd/v1/EPSEngine/CheckMerchantTransactionStatus*' => $this->verification(function (Request $r) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
                $payment = $this->payment('gateway_reference', (string) $query['merchantTransactionId']);

                return Http::response([
                    'MerchantTransactionId' => $payment->gateway_reference,
                    'EpsTransactionId' => 'EPS'.$payment->gateway_reference,
                    'Status' => match ($this->state) {
                        'paid' => 'Success',
                        'failed' => 'Failed',
                        default => 'Pending',
                    },
                    'TotalAmount' => $this->reported($payment),
                    'StoreAmount' => null,
                    'FinancialEntity' => 'BKash',
                    'PaymentReferance' => null,
                    'ErrorCode' => null,
                    'ErrorMessage' => null,
                ]);
            }),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/eps/success', [
            'Status' => 'Success',
            'MerchantTransactionId' => $payment->gateway_reference,
            'ErrorCode' => '',
            'EPSTransactionId' => 'EPS'.$payment->gateway_reference,
        ]);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/eps/fail', [
            'Status' => 'Failed',
            'MerchantTransactionId' => $payment->gateway_reference,
            'ErrorCode' => '101',
            'Message' => 'Declined by bank',
        ]);
    }
}
