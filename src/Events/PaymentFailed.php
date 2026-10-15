<?php

namespace ByteMinus\BdPayGate\Events;

use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Payment $payment) {}
}
