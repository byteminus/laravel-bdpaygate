<?php

namespace ByteMinus\BdPayGate\Tests\Conformance;

use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class PortPosScenario extends GatewayScenario
{
    public function gateway(): string
    {
        return 'portpos';
    }

    public function config(): array
    {
        return [
            'driver' => 'portpos',
            'app_key' => 'pp-app-key',
            'secret_key' => 'pp-secret-key',
            'sandbox' => true,
        ];
    }

    public function stubs(): array
    {
        return [
            // Order matters: the first matching pattern wins.
            'api-sandbox.portpos.com/payment/v2/invoice/ipn/*' => $this->verification(function (Request $r) {
                $payment = $this->payment('gateway_payment_id', $this->segment($r, -2));

                return Http::response($this->invoice($payment, match ($this->state) {
                    'paid' => 'ACCEPTED',
                    'failed' => 'CANCELLED',
                    default => 'PENDING',
                }));
            }),

            'api-sandbox.portpos.com/payment/v2/invoice/refund/*' => fn (Request $r) => Http::response([
                'data' => [
                    'order' => ['invoice' => $this->segment($r, -1), 'amount' => '150.00', 'currency' => 'BDT', 'status' => 'REFUND_PENDING'],
                    'refund' => ['amount' => (float) $r['refund']['amount'], 'status' => 'APPROVED'],
                ],
                'result' => 'SUCCESS',
            ], 202),

            'api-sandbox.portpos.com/payment/v2/invoice' => fn (Request $r) => Http::response([
                'result' => 'success',
                'data' => [
                    'invoice_id' => 'PP'.$r['order']['reference'],
                    'reference' => $r['order']['reference'],
                    'order' => ['amount' => $r['order']['amount'], 'currency' => 'BDT', 'status' => 'PENDING'],
                    'action' => ['type' => 'redirect', 'url' => 'https://ppv2.portpos.com/v2/invoice/PP'.$r['order']['reference'], 'payload' => null, 'method' => 'GET'],
                ],
            ], 201),
        ];
    }

    /**
     * The customer's return: PortPos's own query parameters are undocumented,
     * and the driver only needs the ?ref= it registered.
     */
    public function successCallback(Payment $payment): CallbackRequest
    {
        return CallbackRequest::get('/bdpaygate/portpos/callback', ['ref' => $payment->gateway_reference]);
    }

    /**
     * PortPos posts the IPN on failure too.
     */
    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return CallbackRequest::post('/bdpaygate/portpos/ipn?ref='.$payment->gateway_reference, [
            'invoice' => $payment->gateway_payment_id,
            'amount' => $payment->amount,
            'status' => 'CANCELLED',
            'reference' => $payment->gateway_reference,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(Payment $payment, string $status): array
    {
        return [
            'result' => 'success',
            'data' => [
                'invoice_id' => $payment->gateway_payment_id,
                'reference' => $payment->gateway_reference,
                'order' => [
                    'amount' => $this->reported($payment),
                    'currency' => 'BDT',
                    'type' => 'purchase',
                    'status' => $status,
                ],
                'billing' => [
                    'gateway' => ['category' => 'card', 'network' => 'visa', 'status' => 'APPROVED', 'approve_code' => 'AC'.substr($payment->gateway_reference, 2, 8)],
                ],
                'transactions' => [
                    ['amount' => $this->reported($payment), 'currency' => 'BDT', 'status' => $status, 'type' => 'purchase'],
                ],
            ],
        ];
    }

    protected function segment(Request $request, int $index): string
    {
        $parts = explode('/', (string) parse_url($request->url(), PHP_URL_PATH));

        return rawurldecode((string) array_slice($parts, $index, 1)[0]);
    }
}
