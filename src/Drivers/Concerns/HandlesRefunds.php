<?php

namespace ByteMinus\BdPayGate\Drivers\Concerns;

use ByteMinus\BdPayGate\Contracts\SupportsPartialRefunds;
use ByteMinus\BdPayGate\Data\RefundResult;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\RefundProcessed;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Support\Facades\Cache;

/**
 * Refund bookkeeping for drivers with a refund API. One refund per payment
 * runs at a time, the amount is checked against what is left, and the result
 * is recorded once. Drivers only implement requestRefund().
 */
trait HandlesRefunds
{
    /**
     * Ask the gateway to refund. $amount is already validated and normalised.
     */
    abstract protected function requestRefund(Payment $payment, string $amount, ?string $reason): RefundResult;

    public function refund(Payment $payment, float|int|string|null $amount = null, ?string $reason = null): RefundResult
    {
        return Cache::lock('bdpaygate.refund.'.$payment->getKey(), 60)->block(15, function () use ($payment, $amount, $reason): RefundResult {
            $payment->refresh();
            $amount = $this->assertRefundable($payment, $amount);

            return $this->recordRefund($payment, $this->requestRefund($payment, $amount, $reason));
        });
    }

    protected function assertRefundable(Payment $payment, float|int|string|null $amount): string
    {
        if (! $payment->isPaid()) {
            throw new GatewayException("Payment [{$payment->reference}] is not paid, nothing to refund.", $this->name());
        }

        $refundable = $payment->refundableAmount();
        $amount = $this->amount($amount ?? $refundable);

        if ((float) $amount > (float) $refundable) {
            throw new GatewayException("Refund of {$amount} exceeds the refundable {$refundable}.", $this->name());
        }

        $partial = (float) $amount < (float) $payment->amount;

        if ($partial && ! $this instanceof SupportsPartialRefunds) {
            throw new UnsupportedOperationException("{$this->name()} only refunds the full amount.", $this->name());
        }

        return $amount;
    }

    protected function recordRefund(Payment $payment, RefundResult $result): RefundResult
    {
        if ($result->accepted) {
            $payment->refunded_amount = number_format((float) $payment->refunded_amount + (float) $result->amount, 2, '.', '');
            $payment->status = (float) $payment->refunded_amount >= (float) $payment->amount
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;
        }

        $payment->logGatewayResponse($result->accepted ? 'refund' : 'refund_rejected', $result->raw)->save();

        if ($result->accepted) {
            RefundProcessed::dispatch($payment, $result);
        }

        return $result;
    }
}
