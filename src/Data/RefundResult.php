<?php

namespace ByteMinus\BdPayGate\Data;

use ByteMinus\BdPayGate\Models\Payment;

final class RefundResult
{
    /**
     * @param  bool  $accepted  The gateway took the refund. It counts against the refundable amount.
     * @param  bool  $pending  Accepted but processed asynchronously; the money has not necessarily moved yet.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly Payment $payment,
        public readonly bool $accepted,
        public readonly string $amount,
        public readonly ?string $refundId = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
        public readonly bool $pending = false,
    ) {}
}
