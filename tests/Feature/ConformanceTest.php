<?php

/*
 * Every gateway must pass these. A gateway is "supported" when it does.
 * Scenarios live in tests/Conformance; see tests/Support/GatewayScenario.php.
 */

use ByteMinus\BdPayGate\Contracts\SupportsPartialRefunds;
use ByteMinus\BdPayGate\Contracts\SupportsRefunds;
use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Events\PaymentDuplicated;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Support\CallbackRequest;
use ByteMinus\BdPayGate\Tests\Support\GatewayScenario;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

// CONFORMANCE=Nagad,Upay vendor/bin/pest tests/Feature/ConformanceTest.php runs only those gateways.
dataset('gateways', function () {
    $only = array_filter(array_map('trim', explode(',', strtolower((string) getenv('CONFORMANCE')))));
    $scenarios = [];

    foreach (glob(__DIR__.'/../Conformance/*Scenario.php') ?: [] as $file) {
        $name = basename($file, 'Scenario.php');

        if ($only !== [] && ! in_array(strtolower($name), $only, true)) {
            continue;
        }

        $class = 'ByteMinus\\BdPayGate\\Tests\\Conformance\\'.$name.'Scenario';
        $scenarios[$name] = [fn () => new $class];
    }

    return $scenarios;
});

function hit(CallbackRequest $request): TestResponse
{
    $test = test();

    return match (true) {
        $request->method === 'GET' => $test->get($request->uri.($request->data ? '?'.http_build_query($request->data) : '')),
        $request->json => $test->postJson($request->uri, $request->data),
        default => $test->post($request->uri, $request->data),
    };
}

function chargeWith(GatewayScenario $s, string $reference = 'ORD-CONF-1'): Payment
{
    return BdPayGate::driver($s->gateway())->charge($s->chargeAmount(), $reference, customer(), 'Conformance order');
}

it('charges: returns a pending attempt with a checkout url', function (GatewayScenario $s) {
    $payment = chargeWith($s->boot());

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->checkout_url)->toBeString()->not->toBeEmpty()
        ->and($payment->gateway)->toBe($s->gateway())
        ->and($payment->amount)->toBe($s->chargeAmount());
})->with('gateways');

it('retries a reference as a new attempt with a fresh gateway reference', function (GatewayScenario $s) {
    $first = chargeWith($s->boot());
    $second = chargeWith($s);

    expect($second->gateway_reference)->not->toBe($first->gateway_reference)
        ->and($second->reference)->toBe($first->reference)
        ->and($first->fresh())->not->toBeNull();
})->with('gateways');

it('completes exactly once on a verified success callback, even when replayed', function (GatewayScenario $s) {
    Event::fake([PaymentCompleted::class, PaymentDuplicated::class]);
    $payment = chargeWith($s->boot());

    hit($s->successCallback($payment->fresh()));
    hit($s->successCallback($payment->fresh()));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->paid_at)->not->toBeNull();
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
})->with('gateways');

it('does not complete when the gateway says the payment is not paid', function (GatewayScenario $s) {
    Event::fake([PaymentCompleted::class]);
    $payment = chargeWith($s->boot());
    $s->state = 'unpaid';

    $response = hit($s->successCallback($payment->fresh()));

    expect($response->status())->toBeLessThan(500)
        ->and($payment->refresh()->status)->not->toBe(PaymentStatus::Completed);
    Event::assertNotDispatched(PaymentCompleted::class);
})->with('gateways');

it('does not complete when the gateway reports a different amount', function (GatewayScenario $s) {
    Event::fake([PaymentCompleted::class]);
    $payment = chargeWith($s->boot());
    $s->reportedAmount = '1.00';

    hit($s->successCallback($payment->fresh()));

    expect($payment->refresh()->status)->not->toBe(PaymentStatus::Completed);
    Event::assertNotDispatched(PaymentCompleted::class);
})->with('gateways');

it('stays pending, without an error page, when the gateway cannot be reached', function (GatewayScenario $s) {
    $payment = chargeWith($s->boot());
    $s->state = 'down';

    $response = hit($s->successCallback($payment->fresh()));

    expect($response->status())->toBeLessThan(500)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
})->with('gateways');

it('ignores a failure callback for a payment the gateway says is paid', function (GatewayScenario $s) {
    $payment = chargeWith($s->boot());
    $failure = $s->failureCallback($payment->fresh());

    if (! $failure) {
        $this->markTestSkipped('No distinct failure callback.');
    }

    hit($failure);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
})->with('gateways');

it('marks a confirmed failure, and still completes on a later confirmed success', function (GatewayScenario $s) {
    $payment = chargeWith($s->boot());
    $failure = $s->failureCallback($payment->fresh());

    if (! $failure) {
        $this->markTestSkipped('No distinct failure callback.');
    }

    $s->state = 'failed';
    hit($failure);
    expect($payment->refresh()->status)->toBeIn([PaymentStatus::Failed, PaymentStatus::Cancelled]);

    $s->state = 'paid';
    hit($s->successCallback($payment->fresh()));
    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
})->with('gateways');

it('rejects a callback for a payment it does not know', function (GatewayScenario $s) {
    $payment = chargeWith($s->boot());
    $request = $s->successCallback($payment->fresh());
    Payment::query()->whereKey($payment->id)->delete();

    expect(hit($request)->status())->toBeLessThan(500)
        ->and(Payment::query()->count())->toBe(0);
})->with('gateways');

it('syncs a pending payment from the status API', function (GatewayScenario $s) {
    $s->boot();

    if (! BdPayGate::driver($s->gateway()) instanceof SupportsSync) {
        $this->markTestSkipped('No status API.');
    }

    $payment = chargeWith($s);

    $s->state = 'unpaid';

    if ($before = $s->beforeSync($payment->fresh())) {
        hit($before);
    }

    expect(BdPayGate::sync($payment->refresh())->status)->toBe(PaymentStatus::Pending);

    $s->state = 'paid';
    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Completed);
})->with('gateways');

it('refunds a completed payment', function (GatewayScenario $s) {
    $s->boot();
    $gateway = BdPayGate::driver($s->gateway());

    if (! $gateway instanceof SupportsRefunds) {
        $this->markTestSkipped('No refund API.');
    }

    $payment = chargeWith($s);
    hit($s->successCallback($payment->fresh()));
    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);

    if ($gateway instanceof SupportsPartialRefunds) {
        $part = BdPayGate::refund($payment, '50.00', 'Partial');
        expect($part->accepted)->toBeTrue()
            ->and($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
    }

    $rest = BdPayGate::refund($payment->refresh(), null, 'Rest');

    expect($rest->accepted)->toBeTrue()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refundableAmount())->toBe('0.00');
})->with('gateways');

it('rejects malformed callbacks without an error page or a state change', function (GatewayScenario $s) {
    $payment = chargeWith($s->boot());
    $request = $s->successCallback($payment->fresh());
    $mangled = array_map(fn ($value) => [$value], $request->data);

    $response = hit(new CallbackRequest($request->method, $request->uri, $mangled, $request->json));

    expect($response->status())->toBeLessThan(500)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
})->with('gateways');
