<?php

/*
 * SSLCommerz specifics. The shared behaviour (verification, races, retries,
 * refunds, sync) is covered by ConformanceTest via tests/Conformance/SslCommerzScenario.php.
 */

use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\SslCommerzScenario;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function sslcommerz(): SslCommerzScenario
{
    return (new SslCommerzScenario)->boot();
}

it('sends the attempt id as tran_id and the merchant reference alongside', function () {
    sslcommerz();

    $payment = BdPayGate::driver('sslcommerz')->charge(1500, 'ORD-1001', customer(), 'Order #1001', ['order_id' => 1001]);

    expect($payment->gateway_payment_id)->toBe('SESS'.$payment->gateway_reference)
        ->and($payment->meta)->toBe(['order_id' => 1001]);

    Http::assertSent(fn ($r) => $r['tran_id'] === $payment->gateway_reference
        && $r['value_b'] === 'ORD-1001'
        && $r['total_amount'] === '1500.00'
        && $r['store_id'] === 'teststore'
        && str_contains($r['success_url'], '/bdpaygate/sslcommerz/success')
        && str_contains($r['ipn_url'], '/bdpaygate/sslcommerz/ipn'));
});

it('throws when sslcommerz refuses the session', function () {
    config()->set('bdpaygate.gateways.sslcommerz', (new SslCommerzScenario)->config());
    Http::fake(['sandbox.sslcommerz.com/*' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active'])]);

    BdPayGate::driver('sslcommerz')->charge(100, 'ORD-1002', customer());
})->throws(GatewayException::class, 'Store Credential Error');

it('refuses amounts outside 10 to 500000 BDT before calling the gateway', function () {
    sslcommerz();

    BdPayGate::driver('sslcommerz')->charge(5, 'ORD-1003', customer());
})->throws(GatewayException::class, '10.00 to 500000.00');

it('rejects a success callback with a tampered signature without asking the API', function () {
    Event::fake([PaymentCompleted::class]);
    $s = sslcommerz();
    $payment = BdPayGate::driver('sslcommerz')->charge(250, 'ORD-2002', customer());

    $payload = $s->successCallback($payment)->data;
    $payload['amount'] = '1.00'; // edited after signing

    $this->post('/bdpaygate/sslcommerz/success', $payload)->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'validationserverAPI'));
});

it('checks currency_type and currency_amount for foreign currency payments', function () {
    config()->set('bdpaygate.currency', 'USD');
    config()->set('bdpaygate.gateways.sslcommerz', (new SslCommerzScenario)->config());
    Http::fake([
        'sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'SUCCESS', 'sessionkey' => 'S', 'GatewayPageURL' => 'https://x']),
        'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => function () {
            $p = Payment::query()->firstOrFail();

            return Http::response(['status' => 'VALID', 'tran_id' => $p->gateway_reference, 'amount' => '12000.00', 'currency_type' => 'USD', 'currency_amount' => '100.00', 'bank_tran_id' => 'B1']);
        },
    ]);

    $payment = BdPayGate::driver('sslcommerz')->charge(100, 'ORD-USD', customer());

    $this->post('/bdpaygate/sslcommerz/ipn', sslcommerzSigned(['tran_id' => $payment->gateway_reference, 'val_id' => 'V1', 'amount' => '12000.00', 'status' => 'VALID']))
        ->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('flags risky payments for review', function () {
    config()->set('bdpaygate.gateways.sslcommerz', (new SslCommerzScenario)->config());
    Http::fake([
        'sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'SUCCESS', 'sessionkey' => 'S', 'GatewayPageURL' => 'https://x']),
        'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => function () {
            $p = Payment::query()->firstOrFail();

            return Http::response(['status' => 'VALID', 'tran_id' => $p->gateway_reference, 'amount' => '100.00', 'bank_tran_id' => 'B1', 'risk_level' => '1', 'risk_title' => 'Unusual card']);
        },
    ]);

    $payment = BdPayGate::driver('sslcommerz')->charge(100, 'ORD-RISK', customer());
    $this->post('/bdpaygate/sslcommerz/ipn', sslcommerzSigned(['tran_id' => $payment->gateway_reference, 'val_id' => 'V1', 'amount' => '100.00', 'status' => 'VALID']));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->requires_review)->toBeTrue();
});

it('sends refund_trans_id and does not count a "processing" duplicate as a refund', function () {
    config()->set('bdpaygate.gateways.sslcommerz', (new SslCommerzScenario)->config());
    Http::fake(['sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php*' => Http::sequence()
        ->push(['APIConnect' => 'DONE', 'status' => 'success', 'refund_ref_id' => 'REF1'])
        ->push(['APIConnect' => 'DONE', 'status' => 'processing'])]);

    $payment = Payment::query()->create([
        'gateway' => 'sslcommerz', 'reference' => 'ORD-5001', 'gateway_reference' => 'BPREF5001', 'amount' => '500.00', 'currency' => 'BDT',
        'status' => PaymentStatus::Completed, 'gateway_transaction_id' => 'B5', 'paid_at' => now(),
    ]);

    $first = BdPayGate::refund($payment, 200, 'Damaged item');
    $second = BdPayGate::refund($payment, 200, 'Damaged item');

    expect($first->accepted)->toBeTrue()
        ->and($first->pending)->toBeTrue()
        ->and($first->refundId)->toBe('REF1')
        ->and($second->accepted)->toBeFalse()
        ->and($payment->refresh()->refunded_amount)->toBe('200.00');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'refund_trans_id=RF') && str_contains($r->url(), 'bank_tran_id=B5'));
});
