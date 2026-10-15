<?php

namespace ByteMinus\BdPayGate\Http\Controllers;

use ByteMinus\BdPayGate\BdPayGateManager;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\GatewayUnavailableException;
use ErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use TypeError;
use ValueError;

/**
 * The only URL a gateway ever talks to. It verifies, records, fires events,
 * then sends the customer on to your app.
 *
 * These routes run without sessions or CSRF: several gateways return the
 * customer with a cross-site POST, which would otherwise start a fresh
 * session and log the customer out. Your landing pages get a plain GET.
 */
class CallbackController
{
    public function __invoke(Request $request, BdPayGateManager $manager, string $gateway, string $outcome): RedirectResponse|JsonResponse
    {
        try {
            $driver = $manager->driver($gateway);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        try {
            $result = $driver->handleCallback($request, $outcome);
        } catch (GatewayUnavailableException $e) {
            // We could not confirm either way. The payment stays pending; the
            // gateway retries its IPN, and bdpaygate:sync settles the rest.
            Log::warning('BdPayGate could not reach the gateway to confirm a callback', [
                'gateway' => $gateway,
                'outcome' => $outcome,
                'reason' => $e->getMessage(),
            ]);

            return $outcome === 'ipn'
                ? response()->json(['ok' => false, 'reason' => 'gateway unavailable'], 503)
                : redirect()->to($this->destination('pending'));
        } catch (GatewayException $e) {
            Log::warning('BdPayGate rejected a callback', [
                'gateway' => $gateway,
                'outcome' => $outcome,
                'reason' => $e->getMessage(),
                'payload' => $e->response,
            ]);

            return $outcome === 'ipn'
                ? response()->json(['ok' => false, 'reason' => $e->getMessage()], 400)
                : redirect()->to($this->destination('failed'));
        } catch (ErrorException|TypeError|ValueError $e) {
            // Malformed input (e.g. ?invoice_id[]=x) reaching a string cast. Nothing
            // was changed: state only moves through the gateway's own answer.
            Log::warning('BdPayGate rejected a malformed callback', [
                'gateway' => $gateway,
                'outcome' => $outcome,
                'reason' => $e->getMessage(),
            ]);

            return $outcome === 'ipn'
                ? response()->json(['ok' => false, 'reason' => 'malformed callback'], 400)
                : redirect()->to($this->destination('failed'));
        }

        if ($outcome === 'ipn') {
            return response()->json(['ok' => true, 'status' => $result->status->value]);
        }

        $key = match ($result->status) {
            PaymentStatus::Completed, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded => 'success',
            PaymentStatus::Cancelled => 'cancelled',
            PaymentStatus::Pending => 'pending',
            PaymentStatus::Failed => 'failed',
        };

        return redirect()->to($this->destination($key, $result->payment->id));
    }

    protected function destination(string $key, ?string $paymentId = null): string
    {
        $target = (string) config("bdpaygate.redirects.{$key}", config('bdpaygate.redirects.failed', '/'));

        if (app('router')->has($target)) {
            return route($target, $paymentId ? ['payment' => $paymentId] : []);
        }

        if ($paymentId) {
            $target .= (str_contains($target, '?') ? '&' : '?').'payment='.$paymentId;
        }

        return $target;
    }
}
