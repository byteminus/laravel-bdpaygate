<?php

/*
 * PortPos specifics. The shared behaviour (verification, races, retries,
 * refunds, sync) is covered by ConformanceTest via tests/Conformance/PortPosScenario.php.
 */

use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Drivers\PortPosGateway;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\PortPosScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function portpos(): PortPosScenario
{
    return (new PortPosScenario)->boot();
}

/**
 * Fake one PortPos invoice validation answer, plus a working create.
 *
 * @param  Closure(Payment): mixed  $validate
 */
function portposWith(Closure $validate): PortPosScenario
{
    $s = new PortPosScenario;
    config()->set('bdpaygate.gateways.portpos', $s->config());
    Http::fake([
        'api-sandbox.portpos.com/payment/v2/invoice/ipn/*' => fn () => $validate(Payment::query()->where('gateway', 'portpos')->latest()->firstOrFail()),
        'api-sandbox.portpos.com/payment/v2/invoice' => $s->stubs()['api-sandbox.portpos.com/payment/v2/invoice'],
    ]);

    return $s;
}

it('creates an invoice with a per-request bearer token and the attempt id as reference', function () {
    $this->freezeTime();
    portpos();

    $payment = BdPayGate::driver('portpos')->charge(1500, 'ORD-1001', customer(), 'Order #1001', ['zipcode' => '1209']);

    expect($payment->gateway_payment_id)->toBe('PP'.$payment->gateway_reference)
        ->and($payment->checkout_url)->toBe('https://ppv2.portpos.com/v2/invoice/PP'.$payment->gateway_reference);

    Http::assertSent(function (Request $r) use ($payment) {
        $expected = 'Bearer '.base64_encode('pp-app-key:'.md5('pp-secret-key'.now()->getTimestamp()));

        return $r->url() === 'https://api-sandbox.portpos.com/payment/v2/invoice'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', $expected)
            && $r['order']['amount'] === '1500.00'
            && $r['order']['currency'] === 'BDT'
            && $r['order']['reference'] === $payment->gateway_reference
            && $r['order']['redirect_url'] === url('/bdpaygate/portpos/callback').'?ref='.$payment->gateway_reference
            && $r['order']['ipn_url'] === url('/bdpaygate/portpos/ipn').'?ref='.$payment->gateway_reference
            && $r['product']['name'] === 'Order #1001'
            && $r['billing']['customer']['email'] === 'rahim@example.com'
            && $r['billing']['customer']['address']['street'] === 'House 12, Road 5, Dhanmondi'
            && $r['billing']['customer']['address']['zipcode'] === '1209'
            && $r['billing']['customer']['address']['country'] === 'BD';
    });
});

it('uses the live hosts when sandbox is off', function () {
    config()->set('bdpaygate.gateways.portpos', ['sandbox' => false] + (new PortPosScenario)->config());
    Http::fake(['api.portpos.com/payment/v2/invoice' => Http::response(['result' => 'success', 'data' => ['invoice_id' => 'INV1']], 201)]);

    $payment = BdPayGate::driver('portpos')->charge(100, 'ORD-LIVE', customer());

    expect($payment->checkout_url)->toBe('https://payment.portpos.com/v2/invoice/INV1');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.portpos.com/'));
});

it('throws with the error explanation when PortPos refuses the invoice', function () {
    config()->set('bdpaygate.gateways.portpos', (new PortPosScenario)->config());
    Http::fake(['api-sandbox.portpos.com/*' => Http::response([
        'result' => 'ERROR',
        'error' => ['cause' => 'Validation', 'explanation' => 'Phone number is invalid', 'property' => 'billing.customer.phone'],
    ], 400)]);

    BdPayGate::driver('portpos')->charge(100, 'ORD-1002', customer());
})->throws(GatewayException::class, 'Phone number is invalid (billing.customer.phone)');

it('refuses a country it cannot express as an ISO code before calling the gateway', function () {
    portpos();

    $customer = new Customer(name: 'Jane', phone: '01711000000', email: 'j@example.com', country: 'Narnia');

    expect(fn () => BdPayGate::driver('portpos')->charge(100, 'ORD-1003', $customer))
        ->toThrow(GatewayException::class, 'ISO 3166');
    Http::assertNothingSent();
});

it('validates with the stored invoice id and amount, never the ones posted', function () {
    portpos();
    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-2001', customer());

    $this->post('/bdpaygate/portpos/ipn?ref='.$payment->gateway_reference, [
        'invoice' => 'SOMEONE-ELSES',
        'amount' => '1.00',
        'status' => 'ACCEPTED',
        'reference' => $payment->gateway_reference,
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_transaction_id)->toBe('AC'.substr($payment->gateway_reference, 2, 8));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-sandbox.portpos.com/payment/v2/invoice/ipn/PP'.$payment->gateway_reference.'/150.00');
});

it('finds the payment from an IPN body when the ref query is missing', function () {
    portpos();
    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-2002', customer());

    $this->post('/bdpaygate/portpos/ipn', ['invoice' => $payment->gateway_payment_id, 'amount' => '150.00', 'status' => 'ACCEPTED'])
        ->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('marks an expired invoice failed', function () {
    portposWith(fn (Payment $p) => Http::response((new PortPosScenario)->invoice($p, 'EXPIRED')));
    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-3001', customer());

    $this->get('/bdpaygate/portpos/callback?ref='.$payment->gateway_reference)->assertRedirect('/failed?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('Invoice expired');
});

it('stays pending when the validation API answers with an error', function () {
    Event::fake([PaymentCompleted::class]);
    portposWith(fn () => Http::response(['result' => 'ERROR', 'error' => ['cause' => 'Unauthorized', 'explanation' => 'Invalid token']], 401));
    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-3002', customer());

    $this->get('/bdpaygate/portpos/callback?ref='.$payment->gateway_reference)->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gatewayResponse('callback'))->toMatchArray(['result' => 'ERROR']);
    Event::assertNotDispatched(PaymentCompleted::class);
});

it('rejects a validation answer for a different invoice', function () {
    portposWith(function (Payment $p) {
        $invoice = (new PortPosScenario)->invoice($p, 'ACCEPTED');
        $invoice['data']['invoice_id'] = 'OTHER';

        return Http::response($invoice);
    });
    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-3003', customer());

    $this->post('/bdpaygate/portpos/ipn?ref='.$payment->gateway_reference)->assertStatus(400);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('records an accepted refund as pending, without a refund id', function () {
    portpos();
    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-4001', customer());
    $this->get('/bdpaygate/portpos/callback?ref='.$payment->gateway_reference);

    $result = BdPayGate::refund($payment->refresh(), 40);

    expect($result->accepted)->toBeTrue()
        ->and($result->pending)->toBeTrue()
        ->and($result->refundId)->toBeNull()
        ->and($result->message)->toBe('REFUND_PENDING')
        ->and($payment->refresh()->refunded_amount)->toBe('40.00');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-sandbox.portpos.com/payment/v2/invoice/refund/'.$payment->gateway_payment_id
        && $r['refund'] === ['amount' => '40.00', 'currency' => 'BDT']);
});

it('does not count a refused refund', function () {
    $s = new PortPosScenario;
    config()->set('bdpaygate.gateways.portpos', $s->config());
    Http::fake([
        'api-sandbox.portpos.com/payment/v2/invoice/refund/*' => Http::response([
            'result' => 'ERROR',
            'error' => ['cause' => 'Refund', 'explanation' => 'Maximum refund attempts exceeded'],
        ], 400),
    ] + $s->stubs());

    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-4002', customer());
    $this->get('/bdpaygate/portpos/callback?ref='.$payment->gateway_reference);

    $result = BdPayGate::refund($payment->refresh(), 40);

    expect($result->accepted)->toBeFalse()
        ->and($result->message)->toBe('Maximum refund attempts exceeded')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->refunded_amount)->toBe('0.00');
});

it('reads the invoice state as the refund status', function () {
    portpos();
    $payment = BdPayGate::driver('portpos')->charge(150, 'ORD-4003', customer());

    Http::fake(['api-sandbox.portpos.com/payment/v2/invoice/*' => Http::response(['result' => 'success', 'data' => ['order' => ['status' => 'REFUND_PROCESSING']]])]);

    $gateway = BdPayGate::for($payment);

    expect($gateway)->toBeInstanceOf(PortPosGateway::class)
        ->and($gateway->refundStatus($payment))->toBe('refund_processing');
});
