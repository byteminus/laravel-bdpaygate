<?php

/*
 * PayStation specifics. The shared behaviour (verification, races, retries,
 * sync) is covered by ConformanceTest via tests/Conformance/PayStationScenario.php.
 */

use ByteMinus\BdPayGate\Contracts\SupportsRefunds;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\PayStationScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function paystation(): PayStationScenario
{
    return (new PayStationScenario)->boot();
}

/**
 * Fake initiate-payment normally and answer transaction-status with $status
 * (a raw body when it is a string).
 *
 * @param  string|array<string, mixed>|Closure(Payment): array<string, mixed>  $status
 */
function paystationStatus(string|array|Closure $status): PayStationScenario
{
    $s = new PayStationScenario;
    config()->set('bdpaygate.gateways.paystation', $s->config());

    Http::fake([
        'sandbox.paystation.com.bd/initiate-payment' => $s->stubs()['sandbox.paystation.com.bd/initiate-payment'],
        'sandbox.paystation.com.bd/transaction-status' => function () use ($status) {
            if (is_string($status)) {
                return Http::response($status, 200, ['Content-Type' => 'text/html']);
            }

            $body = $status instanceof Closure ? $status(Payment::query()->latest('id')->firstOrFail()) : $status;

            return Http::response($body);
        },
    ]);

    return $s;
}

function paystationCharge(int|string $amount = 150): Payment
{
    return BdPayGate::driver('paystation')->charge($amount, 'ORD-PS-1', customer(), 'Order PS-1');
}

it('sends credentials as form fields, the attempt id as invoice_number and the merchant reference alongside', function () {
    paystation();

    $payment = paystationCharge('1250.5');

    expect($payment->checkout_url)->toStartWith('https://sandbox.paystation.com.bd/checkout/')
        ->and($payment->gateway_reference)->toStartWith('BP');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/initiate-payment')
        && $r->isForm()
        && $r['merchantId'] === '104-0000000000'
        && $r['password'] === 'test-store-pass'
        && $r['invoice_number'] === $payment->gateway_reference
        && $r['payment_amount'] === '1250.50'
        && $r['currency'] === 'BDT'
        && $r['reference'] === 'ORD-PS-1'
        && $r['opt_a'] === $payment->id
        && $r['cust_phone'] === '01711000000'
        && $r['cust_email'] === 'rahim@example.com'
        && str_ends_with($r['callback_url'], '/bdpaygate/paystation/callback')
        && ! isset($r['pay_with_charge']));
});

it('throws on a business error returned with HTTP 200', function () {
    config()->set('bdpaygate.gateways.paystation', (new PayStationScenario)->config());
    Http::fake(['sandbox.paystation.com.bd/initiate-payment' => Http::response(['status_code' => '1008', 'status' => 'failed', 'message' => 'Duplicate invoice number.'])]);

    expect(fn () => paystationCharge())->toThrow(GatewayException::class, 'Duplicate invoice number. (1008)');
    expect(Payment::query()->firstOrFail()->gatewayResponse('create'))->toMatchArray(['status_code' => '1008']);
});

it('throws on a non-JSON response to initiate-payment', function () {
    config()->set('bdpaygate.gateways.paystation', (new PayStationScenario)->config());
    Http::fake(['sandbox.paystation.com.bd/initiate-payment' => Http::response('<html>Just a moment...</html>', 403)]);

    paystationCharge();
})->throws(GatewayException::class, 'HTTP 403');

it('uses the live host when sandbox is off', function () {
    config()->set('bdpaygate.gateways.paystation', ['sandbox' => false] + (new PayStationScenario)->config());
    Http::fake(['api.paystation.com.bd/initiate-payment' => Http::response(['status_code' => '200', 'status' => 'success', 'payment_url' => 'https://api.paystation.com.bd/checkout/1/x'])]);

    expect(paystationCharge()->checkout_url)->toBe('https://api.paystation.com.bd/checkout/1/x');
});

it('asks transaction-status with the merchantId header and the invoice number, never the password', function () {
    $s = paystation();
    $payment = paystationCharge();

    $this->get('/bdpaygate/paystation/callback?status=Successful&invoice_number='.$payment->gateway_reference)
        ->assertRedirect('/thanks?payment='.$payment->id);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction-status')
        && $r->hasHeader('merchantId', '104-0000000000')
        && $r['invoice_number'] === $payment->gateway_reference
        && ! isset($r['password']));

    expect($payment->refresh()->gateway_transaction_id)->toBe('TRX'.substr($payment->gateway_reference, 2, 8));
});

it('reads trx_status in any casing', function (string $trxStatus, PaymentStatus $expected) {
    $s = paystationStatus(fn (Payment $p) => ['status_code' => '200', 'status' => 'success', 'data' => (new PayStationScenario)->transaction($p, $trxStatus)]);
    $payment = paystationCharge();

    $this->get('/bdpaygate/paystation/callback?status=Failed&invoice_number='.$payment->gateway_reference);

    expect($payment->refresh()->status)->toBe($expected);
})->with([
    ['Success', PaymentStatus::Completed],
    ['SUCCESS', PaymentStatus::Completed],
    [' success ', PaymentStatus::Completed],
    ['successful', PaymentStatus::Completed], // what the live sandbox actually returns
    ['Successful', PaymentStatus::Completed],
    ['Failed', PaymentStatus::Failed],
    ['FAILED', PaymentStatus::Failed],
    ['Processing', PaymentStatus::Pending],
    ['Refund', PaymentStatus::Pending],
]);

it('marks a verified failure as cancelled when the customer cancelled', function () {
    paystation()->state = 'failed';
    $payment = paystationCharge();

    $this->get('/bdpaygate/paystation/callback?status=Canceled&invoice_number='.$payment->gateway_reference)
        ->assertRedirect('/cart?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled);
});

it('trusts the status API over a "Successful" claim in the query string', function () {
    Event::fake([PaymentCompleted::class]);
    paystation()->state = 'unpaid';
    $payment = paystationCharge();

    $this->get('/bdpaygate/paystation/callback?status=Successful&trx_id=FORGED&invoice_number='.$payment->gateway_reference)
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gateway_transaction_id)->toBeNull();
    Event::assertNotDispatched(PaymentCompleted::class);
});

it('keeps the payment pending on a status-API business error', function (array|string $body) {
    paystationStatus($body);
    $payment = paystationCharge();

    $this->get('/bdpaygate/paystation/callback?status=Failed&invoice_number='.$payment->gateway_reference)
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gatewayResponse('callback'))->not->toBeNull();
})->with([
    'not found' => [['status_code' => '2001', 'status' => 'failed', 'message' => 'Transaction not found in system']],
    'invalid token' => [['status_code' => '2001', 'status' => 'failed', 'message' => 'Invalid Token.']],
    'not json' => ['<html>Just a moment...</html>'],
]);

it('rejects a status response for a different invoice', function () {
    paystationStatus(fn (Payment $p) => ['status_code' => '200', 'data' => ['invoice_number' => 'BPSOMEONEELSE', 'trx_status' => 'success', 'payment_amount' => $p->amount]]);
    $payment = paystationCharge();

    $this->get('/bdpaygate/paystation/callback?status=Successful&invoice_number='.$payment->gateway_reference)
        ->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('requires payment_amount to match unless the customer bears the charge', function (bool $payWithCharge, PaymentStatus $expected) {
    paystationStatus(fn (Payment $p) => ['status_code' => '200', 'data' => [
        'invoice_number' => $p->gateway_reference, 'trx_status' => 'success', 'trx_id' => 'T1',
        'request_amount' => '150.00', 'payment_amount' => '152.78',
    ]]);
    config()->set('bdpaygate.gateways.paystation.pay_with_charge', $payWithCharge);
    $payment = paystationCharge();

    $this->get('/bdpaygate/paystation/callback?status=Successful&invoice_number='.$payment->gateway_reference);

    expect($payment->refresh()->status)->toBe($expected);

    if ($payWithCharge) {
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/initiate-payment') && (string) $r['pay_with_charge'] === '1');
    }
})->with([
    'merchant bears the charge' => [false, PaymentStatus::Pending],
    'customer bears the charge' => [true, PaymentStatus::Completed],
]);

it('has no refund API', function () {
    paystation();
    $payment = paystationCharge();
    Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Completed->value]);

    expect(BdPayGate::driver('paystation'))->not->toBeInstanceOf(SupportsRefunds::class);
    BdPayGate::refund($payment->refresh());
})->throws(UnsupportedOperationException::class);
