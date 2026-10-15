<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\SupportsPartialRefunds;
use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Data\RefundResult;
use ByteMinus\BdPayGate\Drivers\Concerns\HandlesRefunds;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Request;

/**
 * No network. Use it in local development and in your app's own tests:
 * the checkout URL points straight back at the success callback, and you
 * can complete, fail or cancel a payment from code.
 *
 * Its callbacks trust the URL, so they refuse to run in production unless
 * "allow_in_production" is set.
 */
class FakeGateway extends AbstractGateway implements SupportsPartialRefunds, SupportsSync
{
    use HandlesRefunds;

    public const DRIVER = 'fake';

    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment {
        $payment = $this->createPendingPayment($amount, $reference, $customer, $description, $meta);

        $payment->gateway_payment_id = 'FAKE-'.$payment->gateway_reference;
        $payment->checkout_url = $this->callbackUrl('success', ['ref' => $payment->gateway_reference]);
        $payment->save();

        return $payment;
    }

    public function handleCallback(Request $request, string $outcome): CallbackResult
    {
        if (app()->environment('production') && ! ($this->config['allow_in_production'] ?? false)) {
            throw new InvalidCallbackException('The fake gateway is disabled in production.', $this->name());
        }

        $payment = $this->findByGatewayReference((string) $request->input('ref', ''));

        return match ($outcome) {
            'success', 'ipn' => $this->complete($payment),
            'cancel' => $this->cancel($payment),
            default => $this->fail($payment),
        };
    }

    public function complete(Payment $payment): CallbackResult
    {
        return $this->transition($payment, PaymentStatus::Completed, [
            'gateway_transaction_id' => 'FAKETRX'.substr($payment->gateway_reference, 2, 8),
        ], 'fake', ['outcome' => 'success']);
    }

    public function fail(Payment $payment): CallbackResult
    {
        return $this->transition($payment, PaymentStatus::Failed, [], 'fake', ['outcome' => 'fail']);
    }

    public function cancel(Payment $payment): CallbackResult
    {
        return $this->transition($payment, PaymentStatus::Cancelled, [], 'fake', ['outcome' => 'cancel']);
    }

    public function sync(Payment $payment): Payment
    {
        return $payment->refresh();
    }

    protected function requestRefund(Payment $payment, string $amount, ?string $reason): RefundResult
    {
        return new RefundResult(
            payment: $payment,
            accepted: true,
            amount: $amount,
            refundId: 'FAKEREF'.strtoupper(substr(md5($payment->gateway_reference.$amount.microtime()), 0, 8)),
            message: $reason,
            raw: ['outcome' => 'refunded', 'amount' => $amount],
        );
    }
}
