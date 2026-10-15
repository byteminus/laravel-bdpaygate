<?php

/*
 * Upay specifics. The shared behaviour (verification, races, retries, sync,
 * refunds) is covered by ConformanceTest via tests/Conformance/UpayScenario.php.
 */

use ByteMinus\BdPayGate\Contracts\SupportsPartialRefunds;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\RefundProcessed;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\UpayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Boot the simulated Upay. Overrides take precedence over the default stubs.
 *
 * @param  array<string, mixed>  $overrides
 */
function upayScenario(array $config = [], array $overrides = []): UpayScenario
{
    $s = new UpayScenario;
    config()->set('bdpaygate.gateways.upay', array_merge($s->config(), $config));
    Http::fake($overrides + $s->stubs());

    return $s;
}

function upayPaid(UpayScenario $s, string $reference = 'ORD-PAID'): Payment
{
    $payment = BdPayGate::driver('upay')->charge(150, $reference, customer());
    test()->get('/bdpaygate/upay/callback?'.http_build_query(['txn' => $payment->gateway_reference, 'status' => 'success', 'invoice_id' => $payment->gateway_reference]));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);

    return $payment;
}

it('authorizes, then initiates with txn_id = invoice_id and the UPAY token', function () {
    upayScenario();
    $this->travelTo(now('UTC')->setDate(2026, 10, 15)->setTime(20, 0, 0));

    $payment = BdPayGate::driver('upay')->charge(150, 'ORD-1', customer(), 'Order 1');

    expect($payment->checkout_url)->toBe('https://uat-pg.upay.systems/gateway/SESS'.$payment->gateway_reference.'/')
        ->and($payment->gateway_payment_id)->toBe('01JUPAY'.$payment->gateway_reference);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://uat-pg.upay.systems/payment/merchant-auth/'
        && $r->method() === 'POST'
        && $r->data() === ['merchant_id' => '1110101010000001', 'merchant_key' => 'test-merchant-key']
        && ! $r->hasHeader('Authorization'));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://uat-pg.upay.systems/payment/merchant-payment-init/'
        && $r->hasHeader('Authorization', 'UPAY JWT-TEST-TOKEN')
        && $r['txn_id'] === $payment->gateway_reference
        && $r['invoice_id'] === $payment->gateway_reference
        && $r['amount'] === '150.00'
        && $r['date'] === '2026-10-16' // Asia/Dhaka is already the next day
        && $r['transaction_currency_code'] === 'BDT'
        && $r['merchant_id'] === '1110101010000001'
        && $r['merchant_name'] === 'Test Shop'
        && $r['merchant_code'] === '1234'
        && $r['merchant_category_code'] === '5999'
        && $r['merchant_mobile'] === '01700000000'
        && $r['merchant_city'] === 'Dhaka'
        && $r['merchant_country_code'] === 'BD'
        && $r['additional_info'] === ['reference' => 'ORD-1', 'description' => 'Order 1']
        && str_contains($r['redirect_url'], '/bdpaygate/upay/callback?txn='.$payment->gateway_reference)
        && ! array_key_exists('merchant_key', $r->data()));
});

it('caches the token across calls', function () {
    upayScenario();

    BdPayGate::driver('upay')->charge(100, 'ORD-A', customer());
    BdPayGate::driver('upay')->charge(100, 'ORD-B', customer());

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'merchant-auth'))->count())->toBe(1)
        ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), 'merchant-payment-init'))->count())->toBe(2);
});

it('re-authorizes once when the cached token is refused', function () {
    $s = new UpayScenario;
    $tokens = ['OLD-TOKEN', 'NEW-TOKEN'];
    upayScenario([], [
        UpayScenario::BASE.'/payment/merchant-auth/' => function () use (&$tokens) {
            return Http::response(['code' => 'MAS2001', 'data' => ['token' => array_shift($tokens)]]);
        },
        UpayScenario::BASE.'/payment/merchant-payment-init/' => function (Request $r) use ($s) {
            if (! $r->hasHeader('Authorization', 'UPAY NEW-TOKEN')) {
                return Http::response(['code' => 'MAF4001', 'message' => 'Invalid token'], 401);
            }

            return $s->stubs()[UpayScenario::BASE.'/payment/merchant-payment-init/']($r);
        },
    ]);

    $payment = BdPayGate::driver('upay')->charge(100, 'ORD-REAUTH', customer());

    expect($payment->checkout_url)->not->toBeEmpty()
        ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), 'merchant-auth'))->count())->toBe(2);
});

it('throws when authorization fails, without leaking the key', function () {
    upayScenario([], [UpayScenario::BASE.'/payment/merchant-auth/' => Http::response(['code' => 'MAF4003', 'message' => 'Merchant not found'], 400)]);

    try {
        BdPayGate::driver('upay')->charge(100, 'ORD-AUTH', customer());
        $this->fail('Expected an exception.');
    } catch (GatewayException $e) {
        expect($e->getMessage())->toContain('Merchant not found [MAF4003]')
            ->and(json_encode($e->response))->not->toContain('test-merchant-key');
    }
});

it('surfaces init errors such as a duplicate txn_id', function () {
    upayScenario([], [UpayScenario::BASE.'/payment/merchant-payment-init/' => Http::response(['code' => 'DTP4003', 'message' => 'Duplicate Transaction id provided'], 400)]);

    expect(fn () => BdPayGate::driver('upay')->charge(100, 'ORD-DUP', customer()))
        ->toThrow(GatewayException::class, 'Duplicate Transaction id provided [DTP4003]');
});

it('queries status by txn_id with a trailing slash', function () {
    $s = upayScenario();
    $payment = upayPaid($s);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->url() === 'https://uat-pg.upay.systems/payment/single-payment-status/'.$payment->gateway_reference.'/'
        && $r->hasHeader('Authorization', 'UPAY JWT-TEST-TOKEN'));

    expect($payment->gateway_transaction_id)->toBe('01JUPAY'.$payment->gateway_reference);
});

it('reads the redirect leniently', function (string $query) {
    upayScenario();
    $payment = BdPayGate::driver('upay')->charge(150, 'ORD-Q', customer());

    $this->get('/bdpaygate/upay/callback?'.str_replace('REF', $payment->gateway_reference, $query))
        ->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
})->with([
    'appended with &' => ['txn=REF&status=success&invoice_id=REF'],
    'appended with a second ?' => ['txn=REF?status=success&invoice_id=REF'],
    'invoice_id only' => ['status=success&invoice_id=REF'],
    'txn only' => ['txn=REF'],
]);

it('does not believe the redirect status', function () {
    $s = upayScenario();
    $payment = BdPayGate::driver('upay')->charge(150, 'ORD-LIE', customer());
    $s->state = 'unpaid';

    $this->get('/bdpaygate/upay/callback?status=success&invoice_id='.$payment->gateway_reference)->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('rejects a status response for another txn_id', function () {
    upayScenario([], [UpayScenario::BASE.'/payment/single-payment-status/*' => Http::response([
        'code' => 'PS2005', 'data' => ['txn_id' => 'BPSOMEONEELSE0000000', 'status' => 'success', 'amount' => 150.0],
    ])]);
    $payment = BdPayGate::driver('upay')->charge(150, 'ORD-X', customer());

    $this->get('/bdpaygate/upay/callback?invoice_id='.$payment->gateway_reference)->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('maps upay statuses', function (string $status, PaymentStatus $expected) {
    upayScenario([], [UpayScenario::BASE.'/payment/single-payment-status/*' => function () use ($status) {
        $payment = Payment::query()->where('gateway', 'upay')->firstOrFail();

        return Http::response(['code' => 'PS2005', 'data' => ['txn_id' => $payment->gateway_reference, 'status' => $status, 'amount' => 150.0]]);
    }]);
    $payment = BdPayGate::driver('upay')->charge(150, 'ORD-MAP', customer());

    expect(BdPayGate::sync($payment)->status)->toBe($expected);
})->with([
    ['success', PaymentStatus::Completed],
    ['SUCCESS', PaymentStatus::Completed],
    ['failed', PaymentStatus::Failed],
    ['expired', PaymentStatus::Failed],
    ['cancelled', PaymentStatus::Cancelled],
    ['pending', PaymentStatus::Pending],
    ['something-new', PaymentStatus::Pending],
]);

it('stays pending when upay does not know the payment (PNF4012)', function () {
    upayScenario([], [UpayScenario::BASE.'/payment/single-payment-status/*' => Http::response(['code' => 'PNF4012', 'message' => 'Payment not found'], 404)]);
    $payment = BdPayGate::driver('upay')->charge(150, 'ORD-404', customer());

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Pending)
        ->and($payment->refresh()->gatewayResponse('sync'))->toMatchArray(['code' => 'PNF4012']);
});

it('refunds the full amount through the bulk endpoint', function () {
    Event::fake([RefundProcessed::class]);
    $s = upayScenario();
    $payment = upayPaid($s);

    $result = BdPayGate::refund($payment, null, 'Customer request');

    expect($result->accepted)->toBeTrue()
        ->and($result->pending)->toBeFalse()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Refunded);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://uat-pg.upay.systems/payment/bulk/refund/'
        && $r->method() === 'POST'
        && $r->hasHeader('Authorization', 'UPAY JWT-TEST-TOKEN')
        && $r->body() === json_encode([['txn_id' => $payment->gateway_reference, 'refund_amount' => '150.00']]));
    Event::assertDispatched(RefundProcessed::class);
});

it('treats an "under process" refund as accepted and pending', function () {
    $s = upayScenario();
    $s->refund = 'under_process';
    $payment = upayPaid($s);

    $result = BdPayGate::refund($payment);

    expect($result->accepted)->toBeTrue()
        ->and($result->pending)->toBeTrue()
        ->and($result->message)->toContain('under process')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Refunded);
});

it('reports a rejected refund without touching the payment', function () {
    $s = upayScenario();
    $s->refund = 'rejected';
    $payment = upayPaid($s);

    $result = BdPayGate::refund($payment);

    expect($result->accepted)->toBeFalse()
        ->and($result->message)->toBe('Invalid amount')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->refunded_amount)->toBe('0.00');
});

it('only refunds the full amount', function () {
    $s = upayScenario();
    $payment = upayPaid($s);

    expect(BdPayGate::driver('upay'))->not->toBeInstanceOf(SupportsPartialRefunds::class)
        ->and(fn () => BdPayGate::refund($payment, '50.00'))->toThrow(UnsupportedOperationException::class);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'refund'));
});

it('uses the live host and base_url override', function () {
    upayScenario(['sandbox' => false], ['pg.upaysystem.com/*' => Http::response(['code' => 'X'], 500)]);
    expect(fn () => BdPayGate::driver('upay')->charge(100, 'ORD-LIVE', customer()))->toThrow(GatewayException::class);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://pg.upaysystem.com/payment/merchant-auth/');

    BdPayGate::forgetDrivers();
    upayScenario(['base_url' => 'https://upay.proxy.test/'], ['upay.proxy.test/*' => Http::response(['code' => 'X'], 500)]);
    expect(fn () => BdPayGate::driver('upay')->charge(100, 'ORD-PROXY', customer()))->toThrow(GatewayException::class);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://upay.proxy.test/payment/merchant-auth/');
});

it('refuses non-BDT charges', function () {
    upayScenario();
    config()->set('bdpaygate.currency', 'USD');

    expect(fn () => BdPayGate::driver('upay')->charge(100, 'ORD-USD', customer()))->toThrow(GatewayException::class, 'only accepts BDT');
});
