<?php

/*
 * Moneybag specifics. The shared behaviour (verification, races, retries)
 * is covered by ConformanceTest via tests/Conformance/MoneybagScenario.php.
 */

use ByteMinus\BdPayGate\Contracts\SupportsRefunds;
use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Drivers\MoneybagGateway;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\MoneybagScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function moneybag(): MoneybagScenario
{
    return (new MoneybagScenario)->boot();
}

/**
 * Fake one Moneybag verification answer, plus a working checkout.
 *
 * @param  Closure(Payment): mixed  $verify
 */
function moneybagWith(Closure $verify): void
{
    $s = new MoneybagScenario;
    config()->set('bdpaygate.gateways.moneybag', $s->config());
    Http::fake([
        'sandbox.api.moneybag.com.bd/api/v2/payments/checkout' => $s->stubs()['sandbox.api.moneybag.com.bd/api/v2/payments/checkout'],
        'sandbox.api.moneybag.com.bd/api/v2/payments/verify/*' => fn () => $verify(Payment::query()->where('gateway', 'moneybag')->latest()->firstOrFail()),
    ]);
}

it('creates a checkout with the merchant key header and the attempt id as order_id', function () {
    moneybag();

    $payment = BdPayGate::driver('moneybag')->charge(1280, 'ORD-1001', customer(), 'Order #1001', ['postcode' => '1209']);

    expect($payment->gateway_payment_id)->toBe('ps'.$payment->gateway_reference)
        ->and($payment->checkout_url)->toStartWith('https://sandbox.payment.moneybag.com.bd/');

    $back = url('/bdpaygate/moneybag').'/%s?ref='.$payment->gateway_reference;

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox.api.moneybag.com.bd/api/v2/payments/checkout'
        && $r->hasHeader('X-Merchant-API-Key', 'mb-merchant-key')
        && $r['order_id'] === $payment->gateway_reference
        && $r['order_amount'] === '1280.00'
        && $r['currency'] === 'BDT'
        && $r['order_description'] === 'Order #1001'
        && $r['success_url'] === sprintf($back, 'success')
        && $r['fail_url'] === sprintf($back, 'fail')
        && $r['cancel_url'] === sprintf($back, 'cancel')
        && $r['customer']['postcode'] === '1209'
        && $r['metadata'] === ['reference' => 'ORD-1001']
        && ! isset($r['ipn_url']));
});

it('uses the live host when sandbox is off', function () {
    config()->set('bdpaygate.gateways.moneybag', ['sandbox' => false] + (new MoneybagScenario)->config());
    Http::fake(['api.moneybag.com.bd/api/v2/payments/checkout' => Http::response(['success' => true, 'data' => ['checkout_url' => 'https://pay.example/x', 'session_id' => 's1', 'expires_at' => 'x']])]);

    BdPayGate::driver('moneybag')->charge(100, 'ORD-LIVE', customer());

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.moneybag.com.bd/api/v2/payments/checkout');
});

it('refuses amounts outside 10 to 1000000 BDT before calling the gateway', function () {
    moneybag();

    expect(fn () => BdPayGate::driver('moneybag')->charge(5, 'ORD-1002', customer()))
        ->toThrow(GatewayException::class, '10.00 to 1000000.00');
    Http::assertNothingSent();
});

it('throws with the validation details when Moneybag refuses the checkout', function () {
    config()->set('bdpaygate.gateways.moneybag', (new MoneybagScenario)->config());
    Http::fake(['sandbox.api.moneybag.com.bd/*' => Http::response([
        'detail' => [['loc' => ['body', 'customer', 'email'], 'msg' => 'value is not a valid email address', 'type' => 'value_error']],
    ], 422)]);

    BdPayGate::driver('moneybag')->charge(100, 'ORD-1003', customer());
})->throws(GatewayException::class, 'body.customer.email value is not a valid email address');

it('rejects a transaction that belongs to a different order', function () {
    moneybagWith(function (Payment $p) {
        $answer = (new MoneybagScenario)->verified($p, 'SUCCESS');
        $answer['data']['order_id'] = 'SOMEONE-ELSES-ORDER';

        return Http::response($answer);
    });
    $payment = BdPayGate::driver('moneybag')->charge(150, 'ORD-2001', customer());

    $this->get('/bdpaygate/moneybag/success?ref='.$payment->gateway_reference.'&transaction_id=txnOTHER&status=SUCCESS')
        ->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('does not complete a SUCCESS that is not verified', function () {
    moneybagWith(function (Payment $p) {
        $answer = (new MoneybagScenario)->verified($p, 'SUCCESS');
        $answer['data']['verified'] = false;

        return Http::response($answer);
    });
    $payment = BdPayGate::driver('moneybag')->charge(150, 'ORD-2002', customer());

    $this->get('/bdpaygate/moneybag/success?ref='.$payment->gateway_reference.'&transaction_id=txn1&status=SUCCESS')
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('stays pending when the verify API does not know the transaction', function () {
    moneybagWith(fn () => Http::response(['detail' => 'Transaction not found'], 404));
    $payment = BdPayGate::driver('moneybag')->charge(150, 'ORD-2003', customer());

    $this->get('/bdpaygate/moneybag/success?ref='.$payment->gateway_reference.'&transaction_id=txnMADEUP&status=SUCCESS')
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('leaves a cancel without a transaction pending, without calling verify', function () {
    moneybag();
    $payment = BdPayGate::driver('moneybag')->charge(150, 'ORD-2004', customer());

    $this->get('/bdpaygate/moneybag/cancel?ref='.$payment->gateway_reference.'&status=CANCELLED')
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/payments/verify/'));
});

it('marks a verified cancellation cancelled and keeps the transaction id', function () {
    moneybagWith(fn (Payment $p) => Http::response((new MoneybagScenario)->verified($p, 'CANCELLED')));
    $payment = BdPayGate::driver('moneybag')->charge(150, 'ORD-2005', customer());

    $this->get('/bdpaygate/moneybag/cancel?ref='.$payment->gateway_reference.'&transaction_id=txn'.$payment->gateway_reference.'&status=CANCELLED')
        ->assertRedirect('/cart?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($payment->gateway_transaction_id)->toBe('txn'.$payment->gateway_reference);
});

it('settles a payment from a transaction id found later', function () {
    moneybag();
    $payment = BdPayGate::driver('moneybag')->charge(150, 'ORD-2006', customer());

    $gateway = BdPayGate::for($payment);
    expect($gateway)->toBeInstanceOf(MoneybagGateway::class);

    $settled = $gateway->verifyTransaction($payment, 'txn'.$payment->gateway_reference);

    expect($settled->status)->toBe(PaymentStatus::Completed)
        ->and($settled->gateway_transaction_id)->toBe('txn'.$payment->gateway_reference);
});

it('offers neither refunds nor sync, which the public API does not have', function () {
    moneybag();

    $gateway = BdPayGate::driver('moneybag');

    expect($gateway)->not->toBeInstanceOf(SupportsRefunds::class)
        ->and($gateway)->not->toBeInstanceOf(SupportsSync::class);
});
