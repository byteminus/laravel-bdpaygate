<?php

use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Drivers\AbstractGateway;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Events\PaymentDuplicated;
use ByteMinus\BdPayGate\Events\PaymentFailed;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

it('fires PaymentCompleted once when two requests race to complete the same payment', function () {
    Event::fake([PaymentCompleted::class]);
    $fake = BdPayGate::fake();
    $payment = $fake->charge(100, 'RACE-1', customer());

    // Two requests loaded the payment before either saved.
    $a = Payment::query()->find($payment->id);
    $b = Payment::query()->find($payment->id);

    $first = $fake->complete($a);
    $second = $fake->complete($b);

    expect($first->changed)->toBeTrue()
        ->and($second->changed)->toBeFalse()
        ->and($second->status)->toBe(PaymentStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

it('lets a confirmed payment override an earlier failure, but never the reverse', function () {
    Event::fake([PaymentCompleted::class, PaymentFailed::class]);
    $fake = BdPayGate::fake();
    $payment = $fake->charge(100, 'LATE-1', customer());

    $fake->fail($payment);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);

    $fake->complete($payment);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);

    $result = $fake->fail($payment);
    expect($result->changed)->toBeFalse()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('keeps earlier attempts and reports a second paid attempt as a duplicate', function () {
    Event::fake([PaymentCompleted::class, PaymentDuplicated::class]);
    $fake = BdPayGate::fake();

    $old = $fake->charge(100, 'DUP-1', customer());
    $new = $fake->charge(100, 'DUP-1', customer());

    expect($old->gateway_reference)->not->toBe($new->gateway_reference)
        ->and($new->attempts()->count())->toBe(2);

    $fake->complete($new);
    $fake->complete($old); // the customer also paid the old checkout page

    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
    Event::assertDispatched(PaymentDuplicated::class, fn ($e) => $e->payment->is($old));
});

it('refuses to charge a reference that is already paid', function () {
    $fake = BdPayGate::fake();
    $fake->complete($fake->charge(100, 'PAID-1', customer()));

    $fake->charge(100, 'PAID-1', customer());
})->throws(GatewayException::class, 'already been paid');

it('refuses refunds above what is left', function () {
    $fake = BdPayGate::fake();
    $payment = $fake->charge(100, 'REF-1', customer());
    $fake->complete($payment);

    BdPayGate::refund($payment, 150);
})->throws(GatewayException::class, 'exceeds');

it('refuses refunds on unpaid payments', function () {
    $payment = BdPayGate::fake()->charge(100, 'REF-2', customer());

    BdPayGate::refund($payment);
})->throws(GatewayException::class, 'not paid');

it('says clearly when a gateway has no refund API', function () {
    BdPayGate::extend('norefund', fn ($app, array $config, string $name) => new class($config, $name) extends AbstractGateway
    {
        public const DRIVER = 'norefund';

        public function charge(float|int|string $amount, string $reference, Customer $customer, ?string $description = null, array $meta = []): Payment
        {
            return $this->createPendingPayment($amount, $reference, $customer, $description, $meta);
        }

        public function handleCallback(Request $request, string $outcome): CallbackResult
        {
            throw new LogicException('unused');
        }
    });
    config()->set('bdpaygate.gateways.cod', ['driver' => 'norefund']);

    $payment = BdPayGate::driver('cod')->charge(100, 'COD-1', customer());
    $payment->forceFill(['status' => PaymentStatus::Completed])->save();

    BdPayGate::refund($payment);
})->throws(UnsupportedOperationException::class, 'no refund API');

it('uses the config key as the gateway name, so two stores of one driver stay apart', function () {
    config()->set('bdpaygate.gateways.shop_a', ['driver' => 'fake']);
    config()->set('bdpaygate.gateways.shop_b', ['driver' => 'fake']);

    $a = BdPayGate::driver('shop_a')->charge(10, 'SAME-REF', customer());
    $b = BdPayGate::driver('shop_b')->charge(10, 'SAME-REF', customer());

    expect($a->gateway)->toBe('shop_a')
        ->and($b->gateway)->toBe('shop_b')
        ->and($a->checkout_url)->toContain('/bdpaygate/shop_a/success')
        ->and(BdPayGate::for($b)->name())->toBe('shop_b');
});

it('sync command settles stale pending payments', function () {
    $fake = BdPayGate::fake();
    $payment = $fake->charge(10, 'SYNC-1', customer());
    Payment::query()->whereKey($payment->id)->update(['created_at' => now()->subHour()]);

    $this->artisan('bdpaygate:sync')->assertSuccessful();
});

it('refuses fake callbacks in production', function () {
    $fake = BdPayGate::fake();
    $payment = $fake->charge(10, 'PROD-1', customer());
    app()->detectEnvironment(fn () => 'production');

    $this->get($payment->checkout_url)->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});
