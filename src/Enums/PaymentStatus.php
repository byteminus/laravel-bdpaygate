<?php

namespace ByteMinus\BdPayGate\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function isPaid(): bool
    {
        return in_array($this, self::paid(), true);
    }

    /**
     * Failed and cancelled are not dead ends: when the gateway later confirms
     * the money moved (a late IPN, a sync), the payment becomes Completed.
     * Nothing ever leaves a paid state through a callback.
     */
    public function canMoveTo(self $to): bool
    {
        return match ($to) {
            self::Completed => in_array($this, [self::Pending, self::Failed, self::Cancelled], true),
            self::Failed, self::Cancelled => $this === self::Pending,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function paid(): array
    {
        return [self::Completed, self::PartiallyRefunded, self::Refunded];
    }
}
