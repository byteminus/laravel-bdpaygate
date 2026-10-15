<?php

namespace ByteMinus\BdPayGate\Contracts;

use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Request;

/**
 * What every gateway can do. Refunds and status sync are optional: check for
 * SupportsRefunds, SupportsPartialRefunds and SupportsSync.
 */
interface Gateway
{
    /**
     * The gateway's name as used in config and routes ("sslcommerz", "bkash", ...).
     */
    public function name(): string;

    /**
     * Create a payment at the gateway and return a pending Payment whose
     * checkout_url the customer should be redirected to.
     *
     * @param  array<string, mixed>  $meta
     */
    public function charge(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description = null,
        array $meta = [],
    ): Payment;

    /**
     * Handle the customer being sent back from the gateway, or a server-to-server
     * notification. Nothing in the request is trusted: the outcome is always
     * confirmed with the gateway before the payment changes state.
     */
    public function handleCallback(Request $request, string $outcome): CallbackResult;
}
