<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Response shapes follow the aamarPay sandbox (jsonpost.php and Search Transaction).
 */
class AamarPayScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'aamarpay';
    }

    public function config(): array
    {
        return [
            'driver' => 'aamarpay',
            'store_id' => 'aamarpaytest',
            'signature_key' => 'dbb74894e82415a2f7ff0ec3a97e4183',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            'sandbox.aamarpay.com/jsonpost.php' => fn (Request $r) => Http::response([
                'result' => 'true',
                'payment_url' => 'https://sandbox.aamarpay.com/paynow.php?track='.self::pgTxnId($r['tran_id']),
            ]),

            'sandbox.aamarpay.com/api/v1/trxcheck/request.php*' => $this->verification(function (Request $r) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
                $payment = $this->payment('gateway_reference', (string) $query['request_id']);

                return Http::response($this->searched($payment));
            }),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::post('/bdpaygate/aamarpay/success', [
            'pay_status' => 'Successful',
            'status_code' => '2',
            'mer_txnid' => $payment->gateway_reference,
            'pg_txnid' => self::pgTxnId($payment->gateway_reference),
            'epw_txnid' => self::pgTxnId($payment->gateway_reference),
            'amount' => $payment->amount,
            'currency' => 'BDT',
            'currency_merchant' => 'BDT',
            'card_type' => 'bKash-bKash',
        ]);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::post('/bdpaygate/aamarpay/fail', [
            'pay_status' => 'Failed',
            'status_code' => '7',
            'mer_txnid' => $payment->gateway_reference,
            'pg_txnid' => self::pgTxnId($payment->gateway_reference),
            'amount' => $payment->amount,
            'currency' => 'BDT',
            'reason' => 'Declined',
        ]);
    }

    public static function pgTxnId(string $tranId): string
    {
        return 'AAM'.sprintf('%u', crc32($tranId));
    }

    /**
     * @return array<string, mixed>
     */
    public function searched(Payment $payment): array
    {
        [$code, $payStatus, $title] = match ($this->state) {
            'paid' => ['2', 'Successful', 'Successful Transaction'],
            'failed' => ['7', 'Failed', 'Failed Transaction'],
            default => ['0', 'Not-Available', 'Payment Not Yet Received'],
        };

        return [
            'pg_txnid' => self::pgTxnId($payment->gateway_reference),
            'mer_txnid' => $payment->gateway_reference,
            'risk_title' => 'Safe',
            'risk_level' => '0',
            'store_id' => 'aamarpaytest',
            'amount' => $this->reported($payment),
            'amount_bdt' => $this->reported($payment),
            'amount_original' => $this->reported($payment),
            'amount_currency' => $this->reported($payment),
            'pay_status' => $payStatus,
            'status_code' => $code,
            'status_title' => $title,
            'bank_trxid' => $this->state === 'paid' ? 'BK'.substr($payment->gateway_reference, 2, 8) : 'Not-Available',
            'payment_type' => $this->state === 'paid' ? 'bKash' : 'Not-Available',
            'error_code' => $this->state === 'paid' ? '0000' : 'Not-Available',
            'currency' => 'BDT',
            'currency_merchant' => 'BDT',
            'opt_a' => $payment->reference,
            'checkout_status' => $this->state === 'paid' ? 'Paid' : 'Not-Paid-Yet',
        ];
    }
}
