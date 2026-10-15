<?php

use ByteMinus\BdPayGate\Drivers\FakeGateway;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('becomes the default gateway and completes without any network', function () {
    Event::fake();
    Http::fake(); // anything hitting the network would fail loudly

    $fake = BdPayGate::fake();

    expect($fake)->toBeInstanceOf(FakeGateway::class)
        ->and(config('bdpaygate.default'))->toBe('fake');

    $payment = BdPayGate::charge(300, 'ORD-F1', customer(), 'Fake order');

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->checkout_url)->toContain('/bdpaygate/fake/success?ref='.$payment->gateway_reference);

    $this->get($payment->checkout_url)->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
    Event::assertDispatched(PaymentCompleted::class);
    Http::assertNothingSent();
});

it('can fail, cancel and refund from code', function () {
    $fake = BdPayGate::fake();

    $a = $fake->charge(10, 'ORD-F2', customer());
    $b = $fake->charge(10, 'ORD-F3', customer());
    $c = $fake->charge(10, 'ORD-F4', customer());

    $fake->fail($a);
    $fake->cancel($b);
    $fake->complete($c);
    $refund = BdPayGate::refund($c, 4);

    expect($a->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($b->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($c->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($refund->accepted)->toBeTrue()
        ->and($c->refundableAmount())->toBe('6.00');
});

it('supports custom drivers through extend', function () {
    config()->set('bdpaygate.gateways.mystore', ['driver' => 'custom', 'token' => 'abc']);

    BdPayGate::extend('custom', fn ($app, array $config, string $name) => new FakeGateway($config, $name));

    expect(BdPayGate::driver('mystore')->name())->toBe('mystore');
});
