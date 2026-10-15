<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class SslCommerzScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'sslcommerz';
    }

    public function config(): array
    {
        return [
            'driver' => 'sslcommerz',
            'store_id' => 'teststore',
            'store_password' => 'teststore@ssl',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            'sandbox.sslcommerz.com/gwprocess/v4/api.php' => fn (Request $r) => Http::response([
                'status' => 'SUCCESS',
                'sessionkey' => 'SESS'.$r['tran_id'],
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/testcde'.$r['tran_id'],
            ]),

            'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => $this->verification(function (Request $r) {
                $payment = $this->payment('gateway_reference', substr($this->query($r)['val_id'], 4));

                return Http::response($this->state === 'paid'
                    ? $this->paidElement($payment)
                    : ['status' => 'INVALID_TRANSACTION']);
            }),

            'sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php*' => $this->verification(function (Request $r) {
                $query = $this->query($r);

                if (isset($query['refund_trans_id'])) {
                    return Http::response(['APIConnect' => 'DONE', 'status' => 'success', 'refund_ref_id' => 'REF'.$query['refund_trans_id'], 'bank_tran_id' => $query['bank_tran_id']]);
                }

                $payment = $this->payment('gateway_reference', $query['tran_id']);

                return Http::response(match ($this->state) {
                    'paid' => ['APIConnect' => 'DONE', 'no_of_trans_found' => 1, 'element' => [$this->paidElement($payment)]],
                    'failed' => ['APIConnect' => 'DONE', 'no_of_trans_found' => 1, 'element' => [['status' => 'FAILED', 'tran_id' => $payment->gateway_reference, 'amount' => $payment->amount]]],
                    default => ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []],
                });
            }),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::post('/bdpaygate/sslcommerz/success', sslcommerzSigned([
            'tran_id' => $payment->gateway_reference,
            'val_id' => 'VAL-'.$payment->gateway_reference,
            'amount' => $payment->amount,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]));
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::post('/bdpaygate/sslcommerz/fail', [
            'tran_id' => $payment->gateway_reference,
            'status' => 'FAILED',
            'error' => 'Declined by bank',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function paidElement(Payment $payment): array
    {
        return [
            'status' => 'VALID',
            'tran_id' => $payment->gateway_reference,
            'val_id' => 'VAL-'.$payment->gateway_reference,
            'amount' => $this->reported($payment),
            'currency' => 'BDT',
            'bank_tran_id' => 'BANK'.substr($payment->gateway_reference, 2, 8),
            'risk_level' => '0',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return array_map('strval', $query);
    }
}
