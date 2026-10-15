<?php

namespace ByteMinus\BdPayGate\Tests\Support;

use ByteMinus\BdPayGate\Models\Payment;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Teaches the conformance suite (tests/Feature/ConformanceTest.php) how one
 * gateway behaves. Put a subclass named <Something>Scenario in tests/Conformance
 * and every conformance test runs against it.
 *
 * Stubs must be lazy: the tests flip $state and $reportedAmount between
 * requests, so read them inside the stub closures, not when building the array.
 */
abstract class GatewayScenario
{
    /**
     * What the gateway says when asked about a payment:
     * "paid", "unpaid" (not paid yet / still initiated), "failed" (declined or
     * cancelled), or "down" (no response at all: wrap those stubs with verification()).
     */
    public string $state = 'paid';

    /**
     * The amount the gateway reports for the payment. Null means the payment's own amount.
     */
    public ?string $reportedAmount = null;

    /**
     * The gateway name used in config and routes.
     */
    abstract public function gateway(): string;

    /**
     * The gateway's config entry, including "driver".
     *
     * @return array<string, mixed>
     */
    abstract public function config(): array;

    /**
     * Http::fake() stubs for every endpoint the driver calls: create/init, the
     * status/verify API, token grants, refunds. Verification endpoints must
     * honour $state and $reportedAmount.
     *
     * @return array<string, mixed>
     */
    abstract public function stubs(): array;

    /**
     * What the gateway sends when the customer returns after paying (or
     * claims to have paid: the body must not be trusted by the driver).
     */
    abstract public function successCallback(Payment $payment): CallbackRequest;

    /**
     * What the gateway sends for a failed or cancelled payment. Null if the
     * gateway reports failures through the same callback with no distinct shape.
     */
    public function failureCallback(Payment $payment): ?CallbackRequest
    {
        return null;
    }

    /**
     * A callback to deliver before syncing, for gateways that only assign a
     * lookup id once the customer has acted (UddoktaPay's invoice_id).
     */
    public function beforeSync(Payment $payment): ?CallbackRequest
    {
        return null;
    }

    /**
     * Amount the charge in the tests uses. Override if the gateway has a minimum.
     */
    public function chargeAmount(): string
    {
        return '150.00';
    }

    public function boot(): static
    {
        config()->set("bdpaygate.gateways.{$this->gateway()}", $this->config());
        Http::fake($this->stubs());

        return $this;
    }

    /**
     * Wrap a verification stub so state "down" simulates a timeout.
     *
     * @param  Closure(Request): mixed  $respond
     * @return Closure(Request): mixed
     */
    protected function verification(Closure $respond): Closure
    {
        return function (Request $request) use ($respond) {
            if ($this->state === 'down') {
                return Create::rejectionFor(new ConnectException('Operation timed out', $request->toPsrRequest()));
            }

            return $respond($request);
        };
    }

    /**
     * The amount the gateway should report for a payment.
     */
    protected function reported(Payment $payment): string
    {
        return $this->reportedAmount ?? $payment->amount;
    }

    /**
     * Find the payment a stubbed request is about.
     */
    protected function payment(string $column, string $value): Payment
    {
        return Payment::query()->where('gateway', $this->gateway())->where($column, $value)->firstOrFail();
    }

    /**
     * The most recently created payment for this gateway.
     */
    protected function latestPayment(): Payment
    {
        return Payment::query()->where('gateway', $this->gateway())->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    }
}
