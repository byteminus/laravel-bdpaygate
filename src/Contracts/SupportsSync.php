<?php

namespace ByteMinus\BdPayGate\Contracts;

use ByteMinus\BdPayGate\Models\Payment;

/**
 * The gateway can be asked for the current state of a payment.
 */
interface SupportsSync
{
    /**
     * Ask the gateway for the current state of a payment and apply it.
     */
    public function sync(Payment $payment): Payment;
}
