<?php

namespace ByteMinus\BdPayGate\Models;

use ByteMinus\BdPayGate\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One attempt to collect a payment for your reference.
 *
 * @property string $id
 * @property string $gateway
 * @property string $reference Your order/invoice reference.
 * @property string $gateway_reference The unique id this attempt was sent to the gateway with.
 * @property string|null $gateway_payment_id
 * @property string|null $gateway_transaction_id
 * @property string $amount
 * @property string $refunded_amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property bool $requires_review The gateway flagged the payment (e.g. SSLCommerz risk_level=1). Hold the service until checked.
 * @property string|null $failure_reason
 * @property string|null $description
 * @property string|null $checkout_url
 * @property array<string, mixed>|null $customer
 * @property array<string, mixed>|null $meta
 * @property list<array{step: string, at: string, payload: array<string, mixed>}>|null $gateway_response
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Payment extends Model
{
    use HasUlids;

    /** How many gateway responses to keep per payment. */
    public const LOG_LIMIT = 50;

    protected $guarded = [];

    protected $attributes = [
        'refunded_amount' => '0.00',
        'requires_review' => false,
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'customer' => 'array',
        'meta' => 'array',
        'gateway_response' => 'array',
        'requires_review' => 'boolean',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
    ];

    public function getTable(): string
    {
        return config('bdpaygate.table', 'bdpaygate_payments');
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function refundableAmount(): string
    {
        return number_format((float) $this->amount - (float) $this->refunded_amount, 2, '.', '');
    }

    /**
     * Every attempt made for the same reference on the same gateway, this one included.
     *
     * @return Builder<static>
     */
    public function attempts(): Builder
    {
        return static::query()
            ->where('gateway', $this->gateway)
            ->where('reference', $this->reference)
            ->orderBy('created_at');
    }

    /**
     * Append a gateway payload to the stored response log.
     *
     * @param  array<string, mixed>  $payload
     */
    public function logGatewayResponse(string $step, array $payload): static
    {
        $log = $this->gateway_response ?? [];
        $log[] = ['step' => $step, 'at' => now()->toIso8601String(), 'payload' => $payload];
        $this->gateway_response = array_slice($log, -self::LOG_LIMIT);

        return $this;
    }

    /**
     * The most recent logged payload for a step, if any.
     *
     * @return array<string, mixed>|null
     */
    public function gatewayResponse(string $step): ?array
    {
        foreach (array_reverse($this->gateway_response ?? []) as $entry) {
            if ($entry['step'] === $step) {
                return $entry['payload'];
            }
        }

        return null;
    }
}
