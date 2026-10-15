<?php

namespace ByteMinus\BdPayGate\Events;

use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A second attempt for a reference that was already paid has also been paid,
 * e.g. the customer paid an old checkout page after retrying. The money moved,
 * so the payment is Completed, but PaymentCompleted is not fired again.
 * Refund it or credit the customer.
 */
class PaymentDuplicated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Payment $payment) {}
}
