<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A simulated Nagad. Key pairs are generated per test run with openssl and
 * thrown away; no real Nagad keys are used or stored.
 */
class NagadScenario extends GatewayScenario
{
    public const MERCHANT_ID = '683002007104225';

    public const BASE = 'sandbox-ssl.mynagad.com/remote-payment-gateway-1.0/api/dfs';

    /** @var array{merchant_private: string, merchant_public: string, nagad_private: string, nagad_public: string}|null */
    private static ?array $keys = null;

    /** The signature algorithm the simulated Nagad verifies requests with. */
    public int $algorithm = OPENSSL_ALGO_SHA256;

    public function gateway(): string
    {
        return 'nagad';
    }

    public function config(): array
    {
        return [
            'driver' => 'nagad',
            'merchant_id' => self::MERCHANT_ID,
            'public_key' => self::keys()['nagad_public'],
            'private_key' => self::keys()['merchant_private'],
            'sandbox' => true,
            'verify_response_signature' => true,
        ];
    }

    /**
     * Two throwaway RSA-2048 pairs: the merchant's and "Nagad's".
     *
     * @return array{merchant_private: string, merchant_public: string, nagad_private: string, nagad_public: string}
     */
    public static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $pair = function (): array {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

            if ($key === false || ! openssl_pkey_export($key, $private)) {
                throw new RuntimeException('openssl could not generate a test key pair.');
            }

            return [$private, openssl_pkey_get_details($key)['key']];
        };

        [$merchantPrivate, $merchantPublic] = $pair();
        [$nagadPrivate, $nagadPublic] = $pair();

        return self::$keys = [
            'merchant_private' => $merchantPrivate,
            'merchant_public' => $merchantPublic,
            'nagad_private' => $nagadPrivate,
            'nagad_public' => $nagadPublic,
        ];
    }

    /**
     * What Nagad does with a request's sensitiveData: decrypt with its private key
     * and check the merchant's signature with the merchant public key.
     *
     * @return array<string, mixed>
     */
    public function readSensitive(Request $request): array
    {
        openssl_private_decrypt(base64_decode($request['sensitiveData']), $plain, self::keys()['nagad_private'], OPENSSL_PKCS1_PADDING);

        if (openssl_verify($plain, base64_decode($request['signature']), self::keys()['merchant_public'], $this->algorithm) !== 1) {
            throw new RuntimeException('Simulated Nagad: request signature does not verify.');
        }

        return json_decode($plain, true);
    }

    /**
     * Encrypt a response for the merchant and sign it as Nagad.
     *
     * @param  array<string, mixed>  $data
     * @return array{sensitiveData: string, signature: string}
     */
    public static function seal(array $data, int $algorithm = OPENSSL_ALGO_SHA256): array
    {
        $plain = json_encode($data);
        openssl_public_encrypt($plain, $encrypted, self::keys()['merchant_public'], OPENSSL_PKCS1_PADDING);
        openssl_sign($plain, $signature, self::keys()['nagad_private'], $algorithm);

        return ['sensitiveData' => base64_encode($encrypted), 'signature' => base64_encode($signature)];
    }

    public function stubs(): array
    {
        return [
            self::BASE.'/check-out/initialize/*' => function (Request $r) {
                $plain = $this->readSensitive($r);

                return Http::response(self::seal([
                    'paymentReferenceId' => 'MDREF'.$plain['orderId'],
                    'challenge' => $plain['challenge'],
                    'acceptDateTime' => $plain['datetime'],
                ], $this->algorithm));
            },

            self::BASE.'/check-out/complete/*' => function (Request $r) {
                $this->readSensitive($r);

                return Http::response([
                    'callBackUrl' => 'https://sandbox-ssl.mynagad.com:10061/check-out/'.basename(parse_url($r->url(), PHP_URL_PATH)),
                    'status' => 'Success',
                ]);
            },

            self::BASE.'/verify/payment/*' => $this->verification(function (Request $r) {
                $refId = basename((string) parse_url($r->url(), PHP_URL_PATH));
                $payment = Payment::query()->where('gateway', 'nagad')->where('gateway_payment_id', $refId)->first();

                if (! $payment) {
                    return Http::response(['reason' => '16_0004_999', 'message' => 'Please verify provided data and try again'], 404);
                }

                return Http::response($this->verifyBody($payment, match ($this->state) {
                    'paid' => 'Success',
                    'failed' => 'Aborted',
                    default => 'OtpSent',
                }));
            }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyBody(Payment $payment, string $status): array
    {
        return [
            'merchantId' => self::MERCHANT_ID,
            'orderId' => $payment->gateway_reference,
            'paymentRefId' => $payment->gateway_payment_id,
            // Nagad sends amounts as strings with no fixed format ("150", "150.00").
            'amount' => preg_replace('/\.00$/', '', $this->reported($payment)),
            'clientMobileNo' => '017****0000',
            'merchantMobileNo' => '01700000000',
            'orderDateTime' => '2026-10-15 12:00:00.0',
            'issuerPaymentDateTime' => $status === 'Success' ? '2026-10-15 12:01:00.0' : null,
            'issuerPaymentRefNo' => $status === 'Success' ? 'ISS'.substr($payment->gateway_reference, 2, 8) : null,
            'additionalMerchantInfo' => ['reference' => $payment->reference],
            'status' => $status,
            'statusCode' => $status === 'Success' ? '000' : '001',
        ];
    }

    public function successCallback(Payment $payment): CallbackRequest
    {
        return $this->callback($payment, 'Success', '00_0000_000', 'Successful Transaction');
    }

    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return $this->callback($payment, 'Aborted', '01_0000_000', 'Aborted by user');
    }

    protected function callback(Payment $payment, string $status, string $code, string $message): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/nagad/callback', [
            'merchant' => self::MERCHANT_ID,
            'order_id' => $payment->gateway_reference,
            'payment_ref_id' => (string) $payment->gateway_payment_id,
            'status' => $status,
            'status_code' => $code,
            'message' => $message,
            'payment_dt' => '20261015120100',
            'issuer_payment_ref' => 'ISS'.substr($payment->gateway_reference, 2, 8),
        ]);
    }
}
