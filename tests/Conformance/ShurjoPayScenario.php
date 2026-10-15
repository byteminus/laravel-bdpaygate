<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Response shapes follow the ShurjoPay sandbox (token, secret-pay, verification).
 */
class ShurjoPayScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'shurjopay';
    }

    public function config(): array
    {
        return [
            'driver' => 'shurjopay',
            'username' => 'sp_sandbox',
            'password' => 'pyyk97hu&6u6',
            'prefix' => 'sp',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            'sandbox.shurjopayment.com/api/get_token' => Http::response([
                'token' => 'sandbox-token',
                'store_id' => 1,
                'execute_url' => 'https://sandbox.shurjopayment.com/api/secret-pay',
                'token_type' => 'Bearer',
                'sp_code' => '200',
                'message' => 'Ok',
                'token_create_time' => '2026-10-15 11:29:54am',
                'expires_in' => 900,
            ]),

            'sandbox.shurjopayment.com/api/secret-pay' => fn (Request $r) => Http::response([
                'checkout_url' => 'https://sandbox.securepay.shurjopayment.com/spaycheckout/?token=t&order_id='.self::spOrderId($r['order_id']),
                'amount' => $r['amount'],
                'currency' => $r['currency'],
                'sp_order_id' => self::spOrderId($r['order_id']),
                'customer_order_id' => $r['order_id'],
                'customer_name' => $r['customer_name'],
                'intent' => 'sale',
                'transactionStatus' => 'Initiated',
            ]),

            'sandbox.shurjopayment.com/api/verification' => $this->verification(function (Request $r) {
                $payment = $this->payment('gateway_payment_id', $r['order_id']);

                return Http::response([$this->verified($payment)]);
            }),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/shurjopay/success', ['order_id' => $payment->gateway_payment_id]);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/shurjopay/cancel', ['order_id' => $payment->gateway_payment_id]);
    }

    public static function spOrderId(string $orderId): string
    {
        return 'sp'.substr(md5($orderId), 0, 13);
    }

    /**
     * @return array<string, mixed>
     */
    public function verified(Payment $payment): array
    {
        [$code, $message] = match ($this->state) {
            'paid' => ['1000', 'Success'],
            'failed' => ['1002', 'Cancel'],
            default => ['1068', 'initiated'],
        };

        return [
            'id' => 142332,
            'order_id' => $payment->gateway_payment_id,
            'currency' => 'BDT',
            'amount' => number_format((float) $this->reported($payment), 4, '.', ''),
            'payable_amount' => number_format((float) $this->reported($payment), 4, '.', ''),
            'recived_amount' => $this->state === 'paid' ? $this->reported($payment) : null,
            'bank_trx_id' => $this->state === 'paid' ? 'BTX'.substr($payment->gateway_reference, 2, 8) : null,
            'customer_order_id' => $payment->gateway_reference,
            'sp_code' => $code,
            'sp_massage' => $message,
            'sp_message' => $message,
            'method' => $this->state === 'paid' ? 'bKash' : null,
            'value1' => $payment->reference,
            'value4' => '2627-8588620331',
        ];
    }
}
