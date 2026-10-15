<?php

/*
 * aamarPay specifics. The shared behaviour (verification, races, retries,
 * sync) is covered by ConformanceTest via tests/Conformance/AamarPayScenario.php.
 */

use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\AamarPayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const AAMARPAY_SEARCH = 'sandbox.aamarpay.com/api/v1/trxcheck/request.php*';

/**
 * Fake aamarPay once per test: Http::fake() keeps the first stub registered for a URL.
 *
 * @param  array<string, mixed>  $overrides  Stubs replacing the scenario's for the same URL.
 */
function aamarpay(array $overrides = [], ?AamarPayScenario $s = null): AamarPayScenario
{
    $s ??= new AamarPayScenario;
    config()->set('bdpaygate.gateways.aamarpay', $s->config());
    Http::fake(array_merge($s->stubs(), $overrides));

    return $s;
}

/**
 * A Search Transaction answer for the tran_id in this request, with changes.
 *
 * @param  array<string, mixed>  $changes
 */
function aamarpaySearched(AamarPayScenario $s, Request $r, array $changes = []): mixed
{
    parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

    return Http::response($changes + $s->searched(Payment::query()->where('gateway_reference', $query['request_id'])->firstOrFail()));
}

function aamarpaySearches(): int
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), '/api/v1/trxcheck/request.php'))->count();
}

it('posts JSON with the attempt id as tran_id and stores the session track as pg_txnid', function () {
    aamarpay();

    $payment = BdPayGate::driver('aamarpay')->charge(1500, 'ORD-1001', customer(), 'Order #1001');

    expect($payment->gateway_payment_id)->toBe(AamarPayScenario::pgTxnId($payment->gateway_reference))
        ->and($payment->checkout_url)->toBe('https://sandbox.aamarpay.com/paynow.php?track='.$payment->gateway_payment_id);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox.aamarpay.com/jsonpost.php'
        && $r->isJson()
        && $r['store_id'] === 'aamarpaytest'
        && $r['signature_key'] === 'dbb74894e82415a2f7ff0ec3a97e4183'
        && $r['tran_id'] === $payment->gateway_reference
        && $r['amount'] === '1500.00'
        && $r['currency'] === 'BDT'
        && $r['desc'] === 'Order #1001'
        && $r['cus_email'] === 'rahim@example.com'
        && $r['cus_phone'] === '01711000000'
        && $r['opt_a'] === 'ORD-1001'
        && $r['type'] === 'json'
        && str_ends_with($r['success_url'], '/bdpaygate/aamarpay/success')
        && str_ends_with($r['fail_url'], '/bdpaygate/aamarpay/fail')
        && str_ends_with($r['cancel_url'], '/bdpaygate/aamarpay/cancel?tran_id='.$payment->gateway_reference));
});

it('searches by tran_id with the credentials in the query string', function () {
    aamarpay();
    $payment = BdPayGate::driver('aamarpay')->charge(100, 'ORD-Q', customer());

    BdPayGate::sync($payment);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_starts_with($r->url(), 'https://sandbox.aamarpay.com/api/v1/trxcheck/request.php?')
        && $r['request_id'] === $payment->gateway_reference
        && $r['store_id'] === 'aamarpaytest'
        && $r['type'] === 'json');
});

it('throws on result false with an errors list (HTTP 200)', function () {
    aamarpay(['sandbox.aamarpay.com/jsonpost.php' => Http::response(['result' => false, 'errors' => ['Invalid or missing cus_email.']])]);

    BdPayGate::driver('aamarpay')->charge(100, 'ORD-E1', customer());
})->throws(GatewayException::class, 'Invalid or missing cus_email.');

it('throws on result false with a message (HTTP 200)', function () {
    aamarpay(['sandbox.aamarpay.com/jsonpost.php' => Http::response(['result' => false, 'message' => 'Invalid Signature Key'])]);

    BdPayGate::driver('aamarpay')->charge(100, 'ORD-E2', customer());
})->throws(GatewayException::class, 'Invalid Signature Key');

it('throws on a plain-text answer to init', function () {
    aamarpay(['sandbox.aamarpay.com/jsonpost.php' => Http::response('Invalid Store ID', 200, ['Content-Type' => 'text/html'])]);

    BdPayGate::driver('aamarpay')->charge(100, 'ORD-E3', customer());
})->throws(GatewayException::class, 'Invalid Store ID');

it('refuses a payment_url that is not https', function () {
    aamarpay(['sandbox.aamarpay.com/jsonpost.php' => Http::response(['result' => 'true', 'payment_url' => 'http://sandbox.aamarpay.com/paynow.php?track=AAM1'])]);

    BdPayGate::driver('aamarpay')->charge(100, 'ORD-E4', customer());
})->throws(GatewayException::class);

it('leaves the payment pending when search answers in plain text', function () {
    aamarpay([AAMARPAY_SEARCH => Http::response('Store_id & signature key not matched', 200, ['Content-Type' => 'text/html'])]);
    $payment = BdPayGate::driver('aamarpay')->charge(100, 'ORD-TXT', customer());
    $callback = (new AamarPayScenario)->successCallback($payment);

    $this->post($callback->uri, $callback->data)->assertRedirect('/pending');
    $this->postJson('/bdpaygate/aamarpay/ipn', $callback->data)->assertStatus(503);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('maps status_code 0, 2, 3 and 7', function (string $code, string $payStatus, PaymentStatus $expected) {
    $s = new AamarPayScenario;
    aamarpay([AAMARPAY_SEARCH => fn (Request $r) => aamarpaySearched($s, $r, [
        'status_code' => $code,
        'pay_status' => $payStatus,
        'status_title' => $payStatus.' Transaction',
    ])], $s);
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-'.$code, customer());

    BdPayGate::sync($payment);

    expect($payment->refresh()->status)->toBe($expected);

    if ($expected === PaymentStatus::Failed) {
        expect($payment->failure_reason)->toBe($payStatus.' Transaction');
    }
})->with([
    'initiated' => ['0', 'Not-Available', PaymentStatus::Pending],
    'successful' => ['2', 'Successful', PaymentStatus::Completed],
    'expired' => ['3', 'Expired', PaymentStatus::Failed],
    'failed' => ['7', 'Failed', PaymentStatus::Failed],
    'undocumented' => ['5', 'Something', PaymentStatus::Pending],
]);

it('stores bank_trxid as the transaction id', function () {
    aamarpay();
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-BK', customer());

    BdPayGate::sync($payment);

    expect($payment->refresh()->gateway_transaction_id)->toBe('BK'.substr($payment->gateway_reference, 2, 8))
        ->and($payment->requires_review)->toBeFalse();
});

it('treats Not-Available as empty', function () {
    $s = new AamarPayScenario;
    aamarpay([AAMARPAY_SEARCH => fn (Request $r) => aamarpaySearched($s, $r, ['bank_trxid' => 'Not-Available'])], $s);
    $first = BdPayGate::driver('aamarpay')->charge(150, 'ORD-NA', customer());

    BdPayGate::sync($first);

    expect($first->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($first->gateway_transaction_id)->toBeNull();
});

it('does not complete when status_code and pay_status disagree', function () {
    $s = new AamarPayScenario;
    aamarpay([AAMARPAY_SEARCH => fn (Request $r) => aamarpaySearched($s, $r, ['pay_status' => 'Failed'])], $s);
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-DIS', customer());

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Pending);
});

it('rejects a callback whose pg_txnid belongs to another session, without searching', function () {
    Event::fake([PaymentCompleted::class]);
    $s = aamarpay();
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-PG', customer());

    $data = $s->successCallback($payment)->data;
    $data['pg_txnid'] = 'AAM0000000000000';

    $this->post('/bdpaygate/aamarpay/success', $data)->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and(aamarpaySearches())->toBe(0);
    Event::assertNotDispatched(PaymentCompleted::class);
});

it('does not complete when search reports a newer session for the same tran_id', function () {
    Event::fake([PaymentCompleted::class]);
    $s = new AamarPayScenario;
    aamarpay([AAMARPAY_SEARCH => fn (Request $r) => aamarpaySearched($s, $r, ['pg_txnid' => 'AAM9999999999999'])], $s);
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-NEW', customer());

    $callback = $s->successCallback($payment);
    $this->post($callback->uri, $callback->data)->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentCompleted::class);
});

it('does not complete when search is about a different mer_txnid', function () {
    $s = new AamarPayScenario;
    aamarpay([AAMARPAY_SEARCH => fn (Request $r) => aamarpaySearched($s, $r, ['mer_txnid' => 'BPOTHER'])], $s);
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-MER', customer());

    expect(fn () => BdPayGate::sync($payment))->toThrow(GatewayException::class, 'different mer_txnid');
    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('keeps an unknown tran_id (Invalid-Data) pending', function () {
    aamarpay([AAMARPAY_SEARCH => fn (Request $r) => Http::response(['request_id' => $r['request_id'], 'store_id' => 'aamarpaytest', 'status' => 'Invalid-Data'])]);
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-INV', customer());

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Pending)
        ->and($payment->refresh()->gatewayResponse('sync')['status'])->toBe('Invalid-Data');
});

it('accepts the cross-site success POST without CSRF and redirects with a GET', function () {
    $s = aamarpay();
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-CSRF', customer());

    $this->post('/bdpaygate/aamarpay/success', $s->successCallback($payment)->data)
        ->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('settles a JSON IPN', function () {
    $s = aamarpay();
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-IPN', customer());

    $this->postJson('/bdpaygate/aamarpay/ipn', $s->searched($payment))
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'completed']);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('identifies the cancel return by the tran_id it was given, and confirms it', function () {
    $s = aamarpay();
    $payment = BdPayGate::driver('aamarpay')->charge(150, 'ORD-CAN', customer());

    $s->state = 'unpaid';
    $this->get('/bdpaygate/aamarpay/cancel?tran_id='.$payment->gateway_reference)->assertRedirect('/pending?payment='.$payment->id);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);

    $s->state = 'failed';
    $this->get('/bdpaygate/aamarpay/cancel?tran_id='.$payment->gateway_reference)->assertRedirect('/failed?payment='.$payment->id);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);
});

it('checks amount_currency and currency_merchant for USD payments', function () {
    config()->set('bdpaygate.currency', 'USD');
    $s = new AamarPayScenario;
    aamarpay([AAMARPAY_SEARCH => fn (Request $r) => aamarpaySearched($s, $r, [
        'amount' => '4382.80', 'currency' => 'BDT', 'currency_merchant' => 'USD', 'amount_currency' => '40.00',
    ])], $s);
    $payment = BdPayGate::driver('aamarpay')->charge(40, 'ORD-USD', customer());

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Completed);
});

it('has no refund API', function () {
    aamarpay();
    $payment = BdPayGate::driver('aamarpay')->charge(100, 'ORD-REF', customer());

    BdPayGate::refund($payment);
})->throws(UnsupportedOperationException::class, 'Refund from the merchant panel');
