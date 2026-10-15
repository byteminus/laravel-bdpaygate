<?php

/*
 * EPS specifics. The shared behaviour (verification, races, retries, sync) is
 * covered by ConformanceTest via tests/Conformance/EpsScenario.php.
 */

use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\EpsScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const EPS_STATUS = 'sandboxpgapi.eps.com.bd/v1/EPSEngine/CheckMerchantTransactionStatus*';

function epsHash(string $value): string
{
    return base64_encode(hash_hmac('sha512', $value, 'eps-hash-key', true));
}

/**
 * Boot EPS with the scenario's token and init stubs, plus a custom status stub.
 */
function epsWithStatus(mixed $status): EpsScenario
{
    $s = new EpsScenario;
    config()->set('bdpaygate.gateways.eps', $s->config());

    $stubs = $s->stubs();
    $stubs[EPS_STATUS] = $status;
    Http::fake($stubs);

    return $s;
}

/**
 * @return array<string, mixed>
 */
function epsPaid(Payment $payment, array $overrides = []): array
{
    return $overrides + [
        'MerchantTransactionId' => $payment->gateway_reference,
        'EpsTransactionId' => 'C2549190401',
        'Status' => 'Success',
        'TotalAmount' => $payment->amount,
        'StoreAmount' => '146.95',
        'FinancialEntity' => 'BKash',
        'PaymentReferance' => null,
        'ErrorCode' => null,
        'ErrorMessage' => null,
    ];
}

it('signs every call with x-hash over the documented value', function () {
    (new EpsScenario)->boot();

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-1', customer(), 'Order #1');
    BdPayGate::sync($payment);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/Auth/GetToken')
        && $r->header('x-hash')[0] === epsHash('eps-user')
        && $r['userName'] === 'eps-user'
        && $r['password'] === 'eps-pass');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/EPSEngine/InitializeEPS')
        && $r->header('x-hash')[0] === epsHash($payment->gateway_reference)
        && $r->header('Authorization')[0] === 'Bearer eps.jwt.token'
        && $r['merchantTransactionId'] === $payment->gateway_reference
        && $r['merchantId'] === 'MID-1'
        && $r['storeId'] === 'SID-1'
        && $r['ValueB'] === 'ORD-EPS-1'
        && str_starts_with($r['CustomerOrderId'], 'ORD-EPS-1-')
        && (float) $r['totalAmount'] === 150.0
        && str_ends_with($r['successUrl'], '/bdpaygate/eps/success')
        && str_ends_with($r['failUrl'], '/bdpaygate/eps/fail')
        && str_ends_with($r['cancelUrl'], '/bdpaygate/eps/cancel'));

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_contains($r->url(), 'CheckMerchantTransactionStatus?merchantTransactionId='.$payment->gateway_reference)
        && $r->header('x-hash')[0] === epsHash($payment->gateway_reference));

    expect($payment->gateway_payment_id)->toBe('EPS'.$payment->gateway_reference)
        ->and($payment->gateway_reference)->toMatch('/^\d{20}$/');
});

it('computes x-hash as base64 HMAC-SHA512 of the value', function () {
    config()->set('bdpaygate.gateways.eps', (new EpsScenario)->config());

    expect(BdPayGate::driver('eps')->hash('20240117001'))
        ->toBe(base64_encode(hash_hmac('sha512', '20240117001', 'eps-hash-key', true)))
        ->toHaveLength(88);
});

it('caches the token across calls', function () {
    (new EpsScenario)->boot();

    BdPayGate::driver('eps')->charge(150, 'ORD-EPS-2', customer());
    BdPayGate::driver('eps')->charge(150, 'ORD-EPS-3', customer());

    Http::assertSentCount(3);
    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'GetToken')))->toHaveCount(1);
});

it('fetches a new token once when EPS answers 401', function () {
    $s = new EpsScenario;
    config()->set('bdpaygate.gateways.eps', $s->config());
    $stubs = $s->stubs();
    $stubs['sandboxpgapi.eps.com.bd/v1/EPSEngine/InitializeEPS'] = Http::sequence()
        ->push(['message' => 'Unauthorized'], 401)
        ->push(['TransactionId' => 'T1', 'RedirectURL' => 'https://sandboxpg.eps.com.bd/x', 'ErrorCode' => null, 'ErrorMessage' => null]);
    Http::fake($stubs);

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-401', customer());

    expect($payment->checkout_url)->toBe('https://sandboxpg.eps.com.bd/x');
    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'GetToken')))->toHaveCount(2);
});

it('throws on a token error that comes back with HTTP 200', function () {
    config()->set('bdpaygate.gateways.eps', (new EpsScenario)->config());
    Http::fake(['sandboxpgapi.eps.com.bd/*' => Http::response(['token' => null, 'expireDate' => null, 'errorMessage' => 'Invalid user', 'errorCode' => '401'])]);

    BdPayGate::driver('eps')->charge(150, 'ORD-EPS-4', customer());
})->throws(GatewayException::class, 'Invalid user');

it('throws when InitializeEPS refuses, with the error in a 200 body', function () {
    $s = new EpsScenario;
    config()->set('bdpaygate.gateways.eps', $s->config());
    $stubs = $s->stubs();
    $stubs['sandboxpgapi.eps.com.bd/v1/EPSEngine/InitializeEPS'] = Http::response(['TransactionId' => null, 'RedirectURL' => null, 'ErrorMessage' => 'Invalid store', 'ErrorCode' => '1001']);
    Http::fake($stubs);

    BdPayGate::driver('eps')->charge(150, 'ORD-EPS-5', customer());
})->throws(GatewayException::class, 'Invalid store');

it('retries the transient 404 right after payment, then completes', function () {
    Event::fake([PaymentCompleted::class]);
    $s = epsWithStatus(function () {
        static $calls = 0;
        $payment = Payment::query()->where('gateway', 'eps')->firstOrFail();

        return ++$calls < 3 ? Http::response(['title' => 'Not Found'], 404) : Http::response(epsPaid($payment));
    });

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-6', customer());
    $this->get('/bdpaygate/eps/success?Status=Success&MerchantTransactionId='.$payment->gateway_reference)->assertRedirect();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_transaction_id)->toBe('C2549190401');
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

it('leaves the payment pending, not failed, when the status API keeps answering 404', function (string $outcome) {
    epsWithStatus(Http::response(['title' => 'Not Found'], 404));

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-7', customer());
    $this->get("/bdpaygate/eps/{$outcome}?Status=Failed&MerchantTransactionId=".$payment->gateway_reference)
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertSentCount(5); // token, init, 3 status attempts
})->with(['success', 'fail', 'cancel']);

it('never trusts Status=Success in the return URL', function () {
    Event::fake([PaymentCompleted::class]);
    epsWithStatus(fn () => Http::response(epsPaid(Payment::query()->firstOrFail(), ['Status' => 'Pending'])));

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-8', customer());
    $this->get('/bdpaygate/eps/success?Status=Success&amount=150&MerchantTransactionId='.$payment->gateway_reference)
        ->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentCompleted::class);
});

it('maps EPS status words case-insensitively', function (string $word, PaymentStatus $expected) {
    epsWithStatus(fn () => Http::response(epsPaid(Payment::query()->firstOrFail(), ['Status' => $word])));

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-9', customer());

    expect(BdPayGate::sync($payment)->status)->toBe($expected);
})->with([
    ['Success', PaymentStatus::Completed],
    ['SUCCESSFUL', PaymentStatus::Completed],
    ['completed', PaymentStatus::Completed],
    ['Failed', PaymentStatus::Failed],
    ['FAILURE', PaymentStatus::Failed],
    ['Cancelled', PaymentStatus::Cancelled],
    ['Aborted', PaymentStatus::Cancelled],
    ['Pending', PaymentStatus::Pending],
    ['Initiated', PaymentStatus::Pending],
    ['', PaymentStatus::Pending],
]);

it('reads camelCase or nested status responses with literal nulls', function () {
    epsWithStatus(function () {
        $payment = Payment::query()->firstOrFail();

        return Http::response(['data' => ['merchantTransactionId' => $payment->gateway_reference, 'status' => 'SUCCESS', 'totalAmount' => '150.00', 'ErrorMessage' => null]]);
    });

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-10', customer());

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Completed);
});

it('reads return parameters whose separators EPS HTML-escaped', function () {
    epsWithStatus(fn () => Http::response(epsPaid(Payment::query()->firstOrFail())));

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-11', customer());
    $this->get('/bdpaygate/eps/success?Status=Success&amp;MerchantTransactionId='.$payment->gateway_reference)->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('treats an encrypted IPN as a nudge to check recent pending payments', function () {
    epsWithStatus(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
        $payment = Payment::query()->where('gateway_reference', $query['merchantTransactionId'])->firstOrFail();

        return Http::response(epsPaid($payment, ['Status' => $payment->reference === 'ORD-EPS-PAID' ? 'Success' : 'Pending']));
    });

    $paid = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-PAID', customer());
    $open = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-OPEN', customer());

    $this->postJson('/bdpaygate/eps/ipn', ['Data' => base64_encode(random_bytes(16)).':'.base64_encode(random_bytes(64))])
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'completed']);

    expect($paid->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($open->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('sweeps at most once a minute, without 404 retries, so a flood of IPNs cannot amplify', function () {
    $calls = 0;
    epsWithStatus(function () use (&$calls) {
        $calls++;

        return Http::response(['message' => 'Not found'], 404);
    });

    BdPayGate::driver('eps')->charge(150, 'ORD-EPS-F1', customer());
    BdPayGate::driver('eps')->charge(150, 'ORD-EPS-F2', customer());

    foreach (range(1, 5) as $i) {
        $this->postJson('/bdpaygate/eps/ipn', ['Data' => 'aXY=:Y2lwaGVy'])->assertOk();
    }

    expect($calls)->toBe(2); // one status call per pending payment, once
});

it('rejects an IPN when there is nothing pending to confirm', function () {
    (new EpsScenario)->boot();

    $this->postJson('/bdpaygate/eps/ipn', ['Data' => 'aXY=:Y2lwaGVy'])->assertStatus(400);
});

it('refuses a status answer about a different transaction', function () {
    epsWithStatus(fn () => Http::response(epsPaid(Payment::query()->firstOrFail(), ['MerchantTransactionId' => '99999999999999999999'])));

    $payment = BdPayGate::driver('eps')->charge(150, 'ORD-EPS-12', customer());
    $this->get('/bdpaygate/eps/success?MerchantTransactionId='.$payment->gateway_reference)->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('only accepts BDT', function () {
    config()->set('bdpaygate.currency', 'USD');
    (new EpsScenario)->boot();

    BdPayGate::driver('eps')->charge(150, 'ORD-EPS-13', customer());
})->throws(GatewayException::class, 'only accepts BDT');

it('uses the live host when sandbox is off', function () {
    config()->set('bdpaygate.gateways.eps', ['sandbox' => false] + (new EpsScenario)->config());

    expect(BdPayGate::driver('eps')->baseUrl())->toBe('https://pgapi.eps.com.bd');
});
