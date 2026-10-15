<?php

namespace ByteMinus\BdPayGate\Contracts;

use ByteMinus\BdPayGate\Data\RefundResult;
use ByteMinus\BdPayGate\Models\Payment;

/**
 * The gateway has a refund API. Without SupportsPartialRefunds, only the full
 * remaining amount can be refunded.
 */
interface SupportsRefunds
{
    public function refund(Payment $payment, float|int|string|null $amount = null, ?string $reason = null): RefundResult;
}
