<?php

/*
 * ShurjoPay specifics. The shared behaviour (verification, races, retries,
 * sync) is covered by ConformanceTest via tests/Conformance/ShurjoPayScenario.php.
 */

use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\ShurjoPayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Fake ShurjoPay once per test: Http::fake() keeps the first stub registered for a URL.
 *
 * @param  array<string, mixed>  $overrides  Stubs replacing the scenario's for the same URL.
 */
function shurjopay(array $overrides = [], ?ShurjoPayScenario $s = null): ShurjoPayScenario
{
    $s ??= new ShurjoPayScenario;
    config()->set('bdpaygate.gateways.shurjopay', $s->config());
    Http::fake(array_merge($s->stubs(), $overrides));

    return $s;
}

/**
 * What the scenario's verification API would answer for the order in this request.
 *
 * @return array<string, mixed>
 */
function shurjopayVerified(ShurjoPayScenario $s, Request $r): array
{
    return $s->verified(Payment::query()->where('gateway_payment_id', $r['order_id'])->firstOrFail());
}

function shurjopayVerifications(): int
{
    return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/verification'))->count();
}

it('gets a token, then sends the attempt id as order_id with the token, store_id and prefix', function () {
    shurjopay();

    $payment = BdPayGate::driver('shurjopay')->charge(1500, 'ORD-1001', customer(), 'Order #1001');

    expect($payment->gateway_payment_id)->toBe(ShurjoPayScenario::spOrderId($payment->gateway_reference))
        ->and($payment->checkout_url)->toStartWith('https://sandbox.securepay.shurjopayment.com/');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox.shurjopayment.com/api/get_token'
        && $r->method() === 'POST'
        && $r['username'] === 'sp_sandbox'
        && $r['password'] === 'pyyk97hu&6u6');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox.shurjopayment.com/api/secret-pay'
        && $r->hasHeader('Authorization', 'Bearer sandbox-token')
        && $r->isJson()
        && $r['token'] === 'sandbox-token'
        && $r['store_id'] === '1'
        && $r['prefix'] === 'sp'
        && $r['order_id'] === $payment->gateway_reference
        && $r['amount'] === '1500.00'
        && $r['currency'] === 'BDT'
        && $r['value1'] === 'ORD-1001'
        && $r['customer_email'] === 'rahim@example.com'
        && $r['customer_address'] === 'House 12, Road 5, Dhanmondi'
        && str_ends_with($r['return_url'], '/bdpaygate/shurjopay/success')
        && str_ends_with($r['cancel_url'], '/bdpaygate/shurjopay/cancel'));
});

it('caches the token across charges and callbacks', function () {
    shurjopay();

    $first = BdPayGate::driver('shurjopay')->charge(100, 'ORD-1', customer());
    BdPayGate::driver('shurjopay')->charge(100, 'ORD-2', customer());
    $this->get('/bdpaygate/shurjopay/success?order_id='.$first->gateway_payment_id);

    expect($first->refresh()->status)->toBe(PaymentStatus::Completed);
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/get_token')))->toHaveCount(1);
});

it('fetches a new token and retries once when ShurjoPay answers 1064 Unauthorized', function () {
    $s = new ShurjoPayScenario;
    $calls = 0;
    shurjopay(['sandbox.shurjopayment.com/api/verification' => function (Request $r) use ($s, &$calls) {
        return ++$calls === 1
            ? Http::response(['sp_code' => '1064', 'message' => 'Unauthorized'])
            : Http::response([shurjopayVerified($s, $r)]);
    }], $s);

    $payment = BdPayGate::driver('shurjopay')->charge(100, 'ORD-1064', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$payment->gateway_payment_id)
        ->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/get_token')))->toHaveCount(2)
        ->and(shurjopayVerifications())->toBe(2);
});

it('leaves the payment pending when the token is rejected twice', function () {
    shurjopay(['sandbox.shurjopayment.com/api/verification' => Http::response(['sp_code' => '1064', 'message' => 'Unauthorized'])]);
    $payment = BdPayGate::driver('shurjopay')->charge(100, 'ORD-1064B', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$payment->gateway_payment_id)->assertRedirect('/pending');
    $this->post('/bdpaygate/shurjopay/ipn', ['order_id' => $payment->gateway_payment_id])->assertStatus(503);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('treats 1068 "initiated" as not paid yet, even on the cancel return', function () {
    $s = shurjopay();
    $payment = BdPayGate::driver('shurjopay')->charge(100, 'ORD-1068', customer());
    $s->state = 'unpaid';

    $this->get('/bdpaygate/shurjopay/cancel?order_id='.$payment->gateway_payment_id)->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gatewayResponse('cancel')['sp_code'])->toBe('1068');
});

it('fails on 1001 (declined by the bank) and cancels on 1002', function () {
    shurjopay(['sandbox.shurjopayment.com/api/verification' => fn (Request $r) => Http::response([[
        'order_id' => $r['order_id'],
        'sp_code' => str_contains(Payment::query()->where('gateway_payment_id', $r['order_id'])->value('reference'), '1001') ? 1001 : 1002, // integer, as the docs show it
        'sp_message' => str_contains(Payment::query()->where('gateway_payment_id', $r['order_id'])->value('reference'), '1001') ? 'Bank transaction failed' : 'Cancel',
        'amount' => '100.0000',
    ]])]);
    $declined = BdPayGate::driver('shurjopay')->charge(100, 'ORD-1001D', customer());
    $cancelled = BdPayGate::driver('shurjopay')->charge(100, 'ORD-1002C', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$declined->gateway_payment_id)->assertRedirect('/failed?payment='.$declined->id);
    $this->get('/bdpaygate/shurjopay/cancel?order_id='.$cancelled->gateway_payment_id)->assertRedirect('/cart?payment='.$cancelled->id);

    expect($declined->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($declined->failure_reason)->toBe('1001 Bank transaction failed')
        ->and($cancelled->refresh()->status)->toBe(PaymentStatus::Cancelled);
});

it('accepts a verification answer that is a bare object instead of an array', function () {
    $s = new ShurjoPayScenario;
    shurjopay(['sandbox.shurjopayment.com/api/verification' => fn (Request $r) => Http::response(shurjopayVerified($s, $r))], $s);
    $payment = BdPayGate::driver('shurjopay')->charge(250, 'ORD-OBJ', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$payment->gateway_payment_id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_transaction_id)->toBe('BTX'.substr($payment->gateway_reference, 2, 8));
});

it('does not complete when verification is about a different order', function () {
    Event::fake([PaymentCompleted::class]);
    $s = new ShurjoPayScenario;
    shurjopay(['sandbox.shurjopayment.com/api/verification' => fn (Request $r) => Http::response([
        ['customer_order_id' => 'BPSOMEONEELSE'] + shurjopayVerified($s, $r),
    ])], $s);
    $payment = BdPayGate::driver('shurjopay')->charge(250, 'ORD-X', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$payment->gateway_payment_id)->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentCompleted::class);
});

it('rejects a currency mismatch in verification', function () {
    $s = new ShurjoPayScenario;
    shurjopay(['sandbox.shurjopayment.com/api/verification' => fn (Request $r) => Http::response([
        ['currency' => 'USD'] + shurjopayVerified($s, $r),
    ])], $s);
    $payment = BdPayGate::driver('shurjopay')->charge(250, 'ORD-CUR', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$payment->gateway_payment_id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('throws on an HTTP 200 validation error from secret-pay and keeps the attempt', function () {
    shurjopay(['sandbox.shurjopayment.com/api/secret-pay' => Http::response([
        'return_url' => 'The return url field is required.',
        'customer_city' => 'The customer city field is required.',
    ])]);

    expect(fn () => BdPayGate::driver('shurjopay')->charge(100, 'ORD-VAL', customer()))
        ->toThrow(GatewayException::class, 'The return url field is required.');

    $this->assertDatabaseHas('bdpaygate_payments', ['reference' => 'ORD-VAL', 'status' => 'pending', 'checkout_url' => null]);
});

it('throws when the token request is refused', function () {
    shurjopay(['sandbox.shurjopayment.com/api/get_token' => Http::response(['sp_code' => '1064', 'message' => 'Unauthorized'])]);

    BdPayGate::driver('shurjopay')->charge(100, 'ORD-TOK', customer());
})->throws(GatewayException::class, 'token request failed: 1064 Unauthorized');

it('settles an IPN POST without CSRF or session', function () {
    shurjopay();
    $payment = BdPayGate::driver('shurjopay')->charge(100, 'ORD-IPN', customer());

    $this->post('/bdpaygate/shurjopay/ipn', ['order_id' => $payment->gateway_payment_id])
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'completed']);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('looks callbacks up by sp_order_id, not by the attempt id', function () {
    shurjopay();
    $payment = BdPayGate::driver('shurjopay')->charge(100, 'ORD-LOOK', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$payment->gateway_reference)->assertRedirect('/failed');
    $this->get('/bdpaygate/shurjopay/success')->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and(shurjopayVerifications())->toBe(0);
});

it('does not verify again once a payment is paid', function () {
    shurjopay();
    $payment = BdPayGate::driver('shurjopay')->charge(100, 'ORD-ONCE', customer());

    $this->get('/bdpaygate/shurjopay/success?order_id='.$payment->gateway_payment_id);
    $this->post('/bdpaygate/shurjopay/ipn', ['order_id' => $payment->gateway_payment_id])->assertOk();

    expect(shurjopayVerifications())->toBe(1);
});

it('refuses currencies other than BDT and USD', function () {
    config()->set('bdpaygate.currency', 'EUR');
    shurjopay();

    BdPayGate::driver('shurjopay')->charge(100, 'ORD-EUR', customer());
})->throws(GatewayException::class, 'BDT or USD');

it('has no refund API', function () {
    shurjopay();
    $payment = BdPayGate::driver('shurjopay')->charge(100, 'ORD-REF', customer());

    BdPayGate::refund($payment);
})->throws(UnsupportedOperationException::class, 'Refund from the merchant panel');
