<?php

/*
 * UddoktaPay specifics. The shared behaviour (verification, races, retries)
 * is covered by ConformanceTest via tests/Conformance/UddoktaPayScenario.php.
 */

use ByteMinus\BdPayGate\Contracts\SupportsRefunds;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\UddoktaPayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function uddoktapay(): UddoktaPayScenario
{
    return (new UddoktaPayScenario)->boot();
}

function uddoktapayCharge(int|string $amount = 150): Payment
{
    return BdPayGate::driver('uddoktapay')->charge($amount, 'ORD-UP-1', customer(), 'Order UP-1');
}

function uddoktapayVerifyCalls(): int
{
    return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/verify-payment'))->count();
}

it('creates a checkout-v2 charge with the API key header and our ids in metadata', function () {
    uddoktapay();

    $payment = uddoktapayCharge('99.9');

    expect($payment->checkout_url)->toStartWith('https://pay.merchant.test/payment/')
        ->and($payment->gateway_payment_id)->toBeNull();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://pay.merchant.test/api/checkout-v2'
        && $r->isJson()
        && $r->hasHeader('RT-UDDOKTAPAY-API-KEY', 'test-uddoktapay-key')
        && $r['amount'] === '99.90'
        && $r['full_name'] === 'Rahim Uddin'
        && $r['email'] === 'rahim@example.com'
        && $r['metadata'] === ['gateway_reference' => $payment->gateway_reference, 'reference' => 'ORD-UP-1', 'payment_id' => $payment->id]
        && $r['return_type'] === 'GET'
        && str_ends_with($r['redirect_url'], '/bdpaygate/uddoktapay/success')
        && str_ends_with($r['cancel_url'], '/bdpaygate/uddoktapay/cancel?ref='.$payment->gateway_reference)
        && str_ends_with($r['webhook_url'], '/bdpaygate/uddoktapay/ipn'));
});

it('requires base_url outside the sandbox', function () {
    config()->set('bdpaygate.gateways.uddoktapay', ['driver' => 'uddoktapay', 'api_key' => 'k', 'sandbox' => false]);
    Http::fake();

    expect(fn () => uddoktapayCharge())->toThrow(GatewayException::class, 'Missing [base_url]');
    Http::assertNothingSent();
});

it('uses the public sandbox host when sandbox is on and no base_url is set', function () {
    config()->set('bdpaygate.gateways.uddoktapay', ['driver' => 'uddoktapay', 'api_key' => 'k', 'sandbox' => true]);
    Http::fake(['sandbox.uddoktapay.com/api/checkout-v2' => Http::response(['status' => true, 'message' => 'Payment Url', 'payment_url' => 'https://sandbox.uddoktapay.com/payment/abc'])]);

    expect(uddoktapayCharge()->checkout_url)->toBe('https://sandbox.uddoktapay.com/payment/abc');
});

it('accepts a pasted API URL as base_url', function (string $baseUrl) {
    config()->set('bdpaygate.gateways.uddoktapay', ['base_url' => $baseUrl] + (new UddoktaPayScenario)->config());
    Http::fake(['pay.merchant.test/*' => Http::response(['status' => true, 'payment_url' => 'https://pay.merchant.test/payment/abc'])]);

    uddoktapayCharge();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://pay.merchant.test/api/checkout-v2');
})->with(['https://pay.merchant.test/', 'https://pay.merchant.test/api/checkout-v2', 'https://pay.merchant.test/api']);

it('throws with the gateway message when the charge is refused', function () {
    config()->set('bdpaygate.gateways.uddoktapay', (new UddoktaPayScenario)->config());
    Http::fake(['pay.merchant.test/*' => Http::response(['status' => false, 'message' => 'Invalid API Key'])]);

    uddoktapayCharge();
})->throws(GatewayException::class, 'Invalid API Key');

it('only takes BDT', function () {
    config()->set('bdpaygate.currency', 'USD');
    uddoktapay();

    uddoktapayCharge();
})->throws(GatewayException::class, 'BDT only');

it('finds the payment from the verified metadata, not the callback', function () {
    $s = uddoktapay();
    $payment = uddoktapayCharge();

    $this->get('/bdpaygate/uddoktapay/success?invoice_id='.$s->invoiceId($payment))
        ->assertRedirect('/thanks?payment='.$payment->id);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_payment_id)->toBe($s->invoiceId($payment))
        ->and($payment->gateway_transaction_id)->toStartWith('TESTTRANS');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/verify-payment')
        && $r['invoice_id'] === $s->invoiceId($payment)
        && $r->hasHeader('RT-UDDOKTAPAY-API-KEY', 'test-uddoktapay-key'));
});

it('accepts invoice_id as a POST field (return_type POST)', function () {
    $s = uddoktapay();
    $payment = uddoktapayCharge();

    $this->post('/bdpaygate/uddoktapay/success', ['invoice_id' => $s->invoiceId($payment)])->assertRedirect('/thanks?payment='.$payment->id);
});

it('keeps a PENDING invoice pending and settles it on sync', function () {
    $s = uddoktapay();
    $s->state = 'unpaid';
    $payment = uddoktapayCharge();

    $this->get('/bdpaygate/uddoktapay/success?invoice_id='.$s->invoiceId($payment))
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gateway_payment_id)->toBe($s->invoiceId($payment));

    $s->state = 'paid';

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Completed);
});

it('does not call the gateway on sync before an invoice exists', function () {
    uddoktapay();
    $payment = uddoktapayCharge();

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Pending)
        ->and(uddoktapayVerifyCalls())->toBe(0);
});

it('rejects an invoice UddoktaPay does not know', function () {
    uddoktapay();
    $payment = uddoktapayCharge();

    $this->get('/bdpaygate/uddoktapay/success?invoice_id=INV-NOPE')->assertRedirect('/failed');
    $this->get('/bdpaygate/uddoktapay/success')->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('stays pending when verify-payment answers with a server error', function () {
    config()->set('bdpaygate.gateways.uddoktapay', (new UddoktaPayScenario)->config());
    Http::fake([
        'pay.merchant.test/api/checkout-v2' => Http::response(['status' => true, 'payment_url' => 'https://pay.merchant.test/payment/x']),
        'pay.merchant.test/api/verify-payment' => Http::response('<h1>502 Bad Gateway</h1>', 502),
    ]);
    $payment = uddoktapayCharge();

    $this->get('/bdpaygate/uddoktapay/success?invoice_id=INV-'.$payment->gateway_reference)->assertRedirect('/pending');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

describe('webhook', function () {
    it('rejects a webhook without our API key, without asking the gateway', function (?string $key) {
        Event::fake([PaymentCompleted::class]);
        $s = uddoktapay();
        $payment = uddoktapayCharge();

        $this->postJson('/bdpaygate/uddoktapay/ipn', $s->invoice($payment, 'COMPLETED'), $key ? ['RT-UDDOKTAPAY-API-KEY' => $key] : [])
            ->assertStatus(400);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
            ->and(uddoktapayVerifyCalls())->toBe(0);
        Event::assertNotDispatched(PaymentCompleted::class);
    })->with(['missing' => [null], 'wrong' => ['982d381360a69d419689740d9f2e26ce36fb7a50']]);

    it('re-verifies a webhook carrying the right key', function () {
        $s = uddoktapay();
        $s->state = 'unpaid';
        $payment = uddoktapayCharge();

        // The body says COMPLETED; verify-payment says PENDING. The body loses.
        $this->postJson('/bdpaygate/uddoktapay/ipn', $s->invoice($payment, 'COMPLETED'), ['RT-UDDOKTAPAY-API-KEY' => 'test-uddoktapay-key'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'pending']);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
            ->and(uddoktapayVerifyCalls())->toBe(1);

        $s->state = 'paid';
        $this->postJson('/bdpaygate/uddoktapay/ipn', $s->invoice($payment, 'COMPLETED'), ['RT-UDDOKTAPAY-API-KEY' => 'test-uddoktapay-key'])
            ->assertJson(['ok' => true, 'status' => 'completed']);
    });

    it('rejects a verified amount that differs from the order', function () {
        $s = uddoktapay();
        $s->reportedAmount = '15.00';
        $payment = uddoktapayCharge();

        $this->postJson('/bdpaygate/uddoktapay/ipn', ['invoice_id' => $s->invoiceId($payment)], ['RT-UDDOKTAPAY-API-KEY' => 'test-uddoktapay-key'])
            ->assertStatus(400);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    });
});

describe('cancel', function () {
    it('leaves the payment pending when the customer left before paying', function () {
        uddoktapay();
        $payment = uddoktapayCharge();

        $this->get('/bdpaygate/uddoktapay/cancel?ref='.$payment->gateway_reference)
            ->assertRedirect('/pending?payment='.$payment->id);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
            ->and(uddoktapayVerifyCalls())->toBe(0)
            ->and($payment->gatewayResponse('cancel'))->not->toBeNull();
    });

    it('completes a cancel for an invoice UddoktaPay says is paid', function () {
        $s = uddoktapay();
        $payment = uddoktapayCharge();
        Payment::query()->whereKey($payment->id)->update(['gateway_payment_id' => $s->invoiceId($payment)]);

        $this->get('/bdpaygate/uddoktapay/cancel?ref='.$payment->gateway_reference)
            ->assertRedirect('/thanks?payment='.$payment->id);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
    });

    it('rejects a cancel pointing at another payment\'s invoice', function () {
        $s = uddoktapay();
        $mine = uddoktapayCharge();
        $theirs = BdPayGate::driver('uddoktapay')->charge(150, 'ORD-UP-2', customer());

        $this->get('/bdpaygate/uddoktapay/cancel?ref='.$mine->gateway_reference.'&invoice_id='.$s->invoiceId($theirs))
            ->assertRedirect('/failed');

        expect($mine->refresh()->status)->toBe(PaymentStatus::Pending)
            ->and($theirs->refresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('rejects a cancel for an unknown reference', function () {
        uddoktapay();

        $this->get('/bdpaygate/uddoktapay/cancel?ref=BPNOTOURS')->assertRedirect('/failed');
    });
});

it('has no refund API (the refund endpoint\'s response is undocumented)', function () {
    uddoktapay();
    $payment = uddoktapayCharge();
    Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Completed->value]);

    expect(BdPayGate::driver('uddoktapay'))->not->toBeInstanceOf(SupportsRefunds::class);
    BdPayGate::refund($payment->refresh());
})->throws(UnsupportedOperationException::class);
