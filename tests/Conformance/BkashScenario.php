<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * bKash Tokenized Checkout V2. Shapes follow the samples on developer.bka.sh:
 * no statusCode on success, paymentId/trxId casing, externalCode/errorMessageEn errors.
 */
class BkashScenario extends GatewayScenario
{
    public const HOST = 'tokenized.sandbox.bka.sh/v2/tokenized-checkout';

    public function gateway(): string
    {
        return 'bkash';
    }

    public function config(): array
    {
        return [
            'driver' => 'bkash',
            'app_key' => 'app-key',
            'app_secret' => 'app-secret',
            'username' => 'sandboxuser',
            'password' => 'sandboxpass',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            self::HOST.'/auth/grant-token' => Http::response(self::tokenResponse('ID-TOKEN', 'REFRESH-1')),
            self::HOST.'/auth/refresh-token' => Http::response(self::tokenResponse('ID-TOKEN-2', 'REFRESH-2')),

            self::HOST.'/payment/create' => fn (Request $r) => Http::response(self::created($r['merchantInvoiceNumber'], (string) $r['amount'], (string) $r['callbackURL'])),

            self::HOST.'/payment/execute' => $this->verification(function (Request $r) {
                $payment = $this->payment('gateway_payment_id', $r['paymentId']);

                return Http::response($this->state === 'paid'
                    ? $this->completed($payment)
                    : self::error('2056', 'Invalid Payment State', 'invalid_payment_state'));
            }),

            self::HOST.'/query/payment' => $this->verification(function (Request $r) {
                $payment = $this->payment('gateway_payment_id', $r['paymentId']);

                return Http::response($this->state === 'paid'
                    ? $this->completed($payment) + ['verificationStatus' => 'Complete']
                    : [
                        'paymentId' => $payment->gateway_payment_id,
                        'transactionStatus' => 'Initiated',
                        'verificationStatus' => 'Incomplete',
                        'amount' => $payment->amount,
                        'currency' => 'BDT',
                        'intent' => 'sale',
                        'merchantInvoice' => $payment->gateway_reference,
                    ]);
            }),

            self::HOST.'/refund/payment/transaction' => fn (Request $r) => Http::response([
                'originalTrxId' => $r['trxId'],
                'refundTrxId' => 'RF'.substr(md5($r['trxId'].$r['refundAmount'].microtime()), 0, 8),
                'refundTransactionStatus' => 'Completed',
                'originalTrxAmount' => '150.00',
                'refundAmount' => $r['refundAmount'],
                'currency' => 'BDT',
                'completedTime' => '2026-09-20T14:47:03:555 GMT+0600',
                'sku' => $r['sku'],
                'reason' => $r['reason'],
            ]),
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/bkash/callback', [
            'paymentID' => $payment->gateway_payment_id,
            'status' => 'success',
            'signature' => self::signature((string) $payment->gateway_payment_id),
        ]);
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/bkash/callback', [
            'paymentID' => $payment->gateway_payment_id,
            'status' => 'cancel',
            'signature' => self::signature((string) $payment->gateway_payment_id),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function tokenResponse(string $idToken, string $refreshToken, int $expiresIn = 3600): array
    {
        return [
            'statusCode' => '0000',
            'statusMessage' => 'Successful',
            'token_type' => 'Bearer',
            'id_token' => $idToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * A V2 Create Payment response, with callback URLs carrying the signature.
     *
     * @return array<string, mixed>
     */
    public static function created(string $invoice, string $amount, string $callbackUrl = 'https://shop.test/bdpaygate/bkash/callback'): array
    {
        $paymentId = 'TR0011'.$invoice;
        $query = fn (string $status) => $callbackUrl.'?paymentID='.$paymentId.'&status='.$status.'&signature='.self::signature($paymentId);

        return [
            'paymentId' => $paymentId,
            'paymentCreateTime' => '2026-09-20T12:31:28:970 GMT+0600',
            'transactionStatus' => 'Initiated',
            'amount' => $amount,
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $invoice,
            'bkashURL' => 'https://sandbox.payment.bkash.com/?paymentId='.$paymentId.'&hash=abc&mode=0011&apiVersion=v2',
            'callbackURL' => $callbackUrl,
            'successCallbackURL' => $query('success'),
            'failureCallbackURL' => $query('failure'),
            'cancelledCallbackURL' => $query('cancel'),
            'signature' => self::signature($paymentId),
        ];
    }

    public static function signature(string $paymentId): string
    {
        return 'SIG'.substr(md5($paymentId), 0, 8);
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(string $code, string $message, string $internal = 'error'): array
    {
        return ['internalCode' => $internal, 'externalCode' => $code, 'errorMessageEn' => $message, 'errorMessageBn' => null];
    }

    /**
     * @return array<string, mixed>
     */
    protected function completed(Payment $payment): array
    {
        return [
            'paymentId' => $payment->gateway_payment_id,
            'trxId' => 'TRX'.substr((string) $payment->gateway_reference, 2, 7),
            'transactionStatus' => 'Completed',
            'amount' => $this->reported($payment),
            'currency' => 'BDT',
            'intent' => 'sale',
            'paymentExecuteTime' => '2026-09-20T12:34:11:929 GMT+0600',
            'merchantInvoiceNumber' => $payment->gateway_reference,
            'payerReference' => '01711000000',
            'payerAccount' => '01711000000',
            'maxRefundableAmount' => $this->reported($payment),
        ];
    }
}
