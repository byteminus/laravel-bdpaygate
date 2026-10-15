<?php

namespace ByteMinus\BdPayGate\Data;

use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Models\Payment;

/**
 * What a gateway concluded about an incoming callback, after verification.
 */
final class CallbackResult
{
    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentStatus $status,
        public readonly bool $changed,
    ) {}
}
