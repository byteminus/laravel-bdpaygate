<?php

/*
 * DGePay specifics. The shared behaviour (verification, races, retries, sync)
 * is covered by ConformanceTest via tests/Conformance/DgePayScenario.php.
 */

use ByteMinus\BdPayGate\Drivers\DgePay\DgePayCrypto;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\DgePayScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const DGEPAY_STATUS = 'api-uat.dgepay.net/dipon/v3/payment_gateway/check_transaction_status';

function dgepayCrypto(): DgePayCrypto
{
    return new DgePayCrypto(DgePayScenario::SECRET, DgePayScenario::API_KEY);
}

/**
 * Boot DGePay with the scenario's auth and initiate stubs, plus a custom status stub.
 */
function dgepayWithStatus(mixed $status): DgePayScenario
{
    $s = new DgePayScenario;
    config()->set('bdpaygate.gateways.dgepay', $s->config());

    $stubs = $s->stubs();
    $stubs[DGEPAY_STATUS] = $status;
    Http::fake($stubs);

    return $s;
}

function dgepayStatus(string $code, ?float $amount = null): Closure
{
    return function () use ($code, $amount) {
        $payment = Payment::query()->where('gateway', 'dgepay')->firstOrFail();

        return Http::response(['status_code' => 200, 'data' => [
            'message' => 'STATUS '.$code,
            'txn_id' => 'edfba864bd7e402ebc5ca7fc3230246b',
            'status_code' => $code,
            'amount' => $amount ?? (float) $payment->amount,
            'txn_number' => '50534914',
            'payment_method' => 'bKash',
            'unique_txn_id' => $payment->gateway_reference,
        ]]);
    };
}

it('builds the canonical signature string the way the SDK does', function () {
    $payload = [
        'unique_txn_id' => 'BPX1',
        'redirect_url' => 'https://shop.test/bdpaygate/dgepay/callback',
        'note' => 'Order #1, blue',
        'amount' => 150.0,
        'customer_token' => null,
        'payee_information' => ['phone_number' => '01711000000', 'dial_code' => '+88'],
    ];

    expect(dgepayCrypto()->canonical($payload))->toBe(
        'amount150.0customer_tokennullnoteOrder#1bluepayee_informationdial_code+88phone_number01711000000'
        .'redirect_urlhttps//shop.test/bdpaygate/dgepay/callbackunique_txn_idBPX1'
    );

    expect(dgepayCrypto()->canonical(['amount' => 150.5]))->toBe('amount150.5')
        ->and(dgepayCrypto()->sign(['unique_txn_id' => 'BPX1']))
        ->toBe(base64_encode(hash_hmac('sha256', 'unique_txn_idBPX1', DgePayScenario::API_KEY, true)));
});

it('encrypts with AES-128-ECB under the 16-byte client secret, and decrypts back', function () {
    $payload = ['unique_txn_id' => 'BPX1', 'amount' => 150.0];
    $cipher = dgepayCrypto()->encrypt($payload);

    expect(openssl_decrypt(base64_decode($cipher), 'aes-128-ecb', DgePayScenario::SECRET, OPENSSL_RAW_DATA))
        ->toBe('{"amount":150.0,"unique_txn_id":"BPX1"}')
        ->and(dgepayCrypto()->decrypt($cipher))->toBe(['amount' => 150.0, 'unique_txn_id' => 'BPX1']);
});

it('pads a short client secret with "0" to 16 bytes, as the SDK does', function () {
    $crypto = new DgePayCrypto('short', 'k');
    $cipher = $crypto->encrypt(['a' => 'b']);

    expect(openssl_decrypt(base64_decode($cipher), 'aes-128-ecb', 'short00000000000', OPENSSL_RAW_DATA))->toBe('{"a":"b"}');
});

it('sends an encrypted, signed body with the bearer token', function () {
    (new DgePayScenario)->boot();

    $payment = BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-1', customer(), 'Order #1');

    Http::assertSent(function (Request $r) {
        return str_ends_with($r->url(), '/payment_gateway/authenticate')
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('dgepay-client:'.DgePayScenario::SECRET)
            && $r['client_id'] === 'dgepay-client';
    });

    Http::assertSent(function (Request $r) use ($payment) {
        if (! str_ends_with($r->url(), '/payment_gateway/initiate_payment')) {
            return false;
        }

        $body = dgepayCrypto()->decrypt($r->body());

        return $body === [
            'amount' => 150.0,
            'customer_token' => null,
            'note' => 'Order #1',
            'payee_information' => ['dial_code' => '+88', 'phone_number' => '01711000000'],
            'payment_method' => null,
            'redirect_url' => route('bdpaygate.callback', ['gateway' => 'dgepay', 'outcome' => 'callback']),
            'unique_txn_id' => $payment->gateway_reference,
        ]
            && $r->header('Signature')[0] === dgepayCrypto()->sign($body)
            && $r->header('Authorization')[0] === 'Bearer dgepay.jwt.token'
            && $r->header('Content-Type')[0] === 'application/json';
    });

    expect($payment->checkout_url)->toBe('https://pay-uat.dgepay.net/checkout/'.$payment->gateway_reference);
});

it('caches the token across calls', function () {
    (new DgePayScenario)->boot();

    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-2', customer());
    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-3', customer());

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'authenticate')))->toHaveCount(1);
});

it('caches the token until its JWT expiry', function () {
    $s = new DgePayScenario;
    config()->set('bdpaygate.gateways.dgepay', $s->config());
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.'.rtrim(strtr(base64_encode(json_encode(['exp' => time() + 120])), '+/', '-_'), '=').'.sig';
    $stubs = $s->stubs();
    $stubs['api-uat.dgepay.net/dipon/v3/payment_gateway/authenticate'] = Http::response(['status_code' => 200, 'success' => 1, 'data' => ['access_token' => $jwt]]);
    Http::fake($stubs);

    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-4', customer());
    $this->travel(70)->seconds();
    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-5', customer());

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'authenticate')))->toHaveCount(2);
});

it('throws when authentication is refused with HTTP 200', function () {
    config()->set('bdpaygate.gateways.dgepay', (new DgePayScenario)->config());
    Http::fake(['api-uat.dgepay.net/*' => Http::response(['status_code' => 401, 'success' => 0, 'data' => null, 'error' => ['Credentials are not valid']])]);

    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-6', customer());
})->throws(GatewayException::class, 'Credentials are not valid');

it('throws when initiate_payment answers success 0', function () {
    $s = new DgePayScenario;
    config()->set('bdpaygate.gateways.dgepay', $s->config());
    $stubs = $s->stubs();
    $stubs['api-uat.dgepay.net/dipon/v3/payment_gateway/initiate_payment'] = Http::response(['status_code' => 200, 'success' => 0, 'data' => null, 'error' => ['REQUESTED_PARAMETERS_ARE_MISMATCHED']]);
    Http::fake($stubs);

    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-7', customer());
})->throws(GatewayException::class, 'REQUESTED_PARAMETERS_ARE_MISMATCHED');

it('restores "+" that query parsing turned into spaces before decrypting', function () {
    Event::fake([PaymentCompleted::class]);
    $s = dgepayWithStatus(dgepayStatus('3'));
    $payment = BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-8', customer());

    // Find a payload whose ciphertext contains "+", then send it unencoded, as DGePay does.
    for ($i = 0, $cipher = ''; ! str_contains($cipher, '+'); $i++) {
        $cipher = dgepayCrypto()->encrypt(['status_code' => '3', 'unique_txn_id' => $payment->gateway_reference, 'n' => $i]);
    }

    $this->get('/bdpaygate/dgepay/callback?data='.$cipher)->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_payment_id)->toBe('edfba864bd7e402ebc5ca7fc3230246b')
        ->and($payment->gateway_transaction_id)->toBe('50534914');
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

it('maps status codes: 3 paid, 8 cancelled, 4 failed, anything else pending', function (string $code, PaymentStatus $expected) {
    dgepayWithStatus(dgepayStatus($code));
    $payment = BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-9', customer());

    expect(BdPayGate::sync($payment)->status)->toBe($expected);
})->with([
    ['3', PaymentStatus::Completed],
    ['8', PaymentStatus::Cancelled],
    ['4', PaymentStatus::Failed],
    ['1', PaymentStatus::Pending],
    ['2', PaymentStatus::Pending],
    ['', PaymentStatus::Pending],
]);

it('lets the failure codes be configured', function () {
    dgepayWithStatus(dgepayStatus('4'));
    config()->set('bdpaygate.gateways.dgepay.failed_status_codes', ['5']);
    $payment = BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-10', customer());

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Pending);
});

it('never trusts the status_code inside the redirect payload', function () {
    Event::fake([PaymentCompleted::class]);
    $s = dgepayWithStatus(dgepayStatus('1'));
    $payment = BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-11', customer());

    $this->get('/bdpaygate/dgepay/callback?'.http_build_query(['data' => dgepayCrypto()->encrypt(['status_code' => '3', 'unique_txn_id' => $payment->gateway_reference, 'amount' => 150.0])]))
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentCompleted::class);
});

it('stays pending when the status API has no data for the transaction', function () {
    dgepayWithStatus(Http::response(['status_code' => 200, 'success' => 0, 'data' => null, 'error' => ['Transaction not found']]));
    $payment = BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-12', customer());

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Pending);
});

it('rejects a redirect whose data does not decrypt', function () {
    (new DgePayScenario)->boot();
    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-13', customer());

    $this->get('/bdpaygate/dgepay/callback?data='.base64_encode('not a ciphertext'))->assertRedirect('/failed');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'check_transaction_status'));
});

it('only accepts BDT', function () {
    config()->set('bdpaygate.currency', 'USD');
    (new DgePayScenario)->boot();

    BdPayGate::driver('dgepay')->charge(150, 'ORD-DG-14', customer());
})->throws(GatewayException::class, 'only accepts BDT');

it('uses the live host when sandbox is off, and base_url for DEV', function () {
    config()->set('bdpaygate.gateways.dgepay', ['sandbox' => false] + (new DgePayScenario)->config());
    expect(BdPayGate::driver('dgepay')->baseUrl())->toBe('https://apiv2.dgepay.net/dipon/v3');

    config()->set('bdpaygate.gateways.dgepay-dev', ['base_url' => 'https://api-dev.dgepay.net/dipon/v3'] + (new DgePayScenario)->config());
    expect(BdPayGate::driver('dgepay-dev')->baseUrl())->toBe('https://api-dev.dgepay.net/dipon/v3');
});
