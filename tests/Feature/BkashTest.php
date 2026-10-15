<?php

/*
 * bKash specifics. The shared behaviour (verification, races, retries,
 * refunds, sync) is covered by ConformanceTest via tests/Conformance/BkashScenario.php.
 */

use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Drivers\BkashGateway;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\GatewayUnavailableException;
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Models\Payment;
use ByteMinus\BdPayGate\Tests\Conformance\BkashScenario;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const BKASH_V2 = BkashScenario::HOST;
const BKASH_V1 = 'tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout';

function bkashV2(array $stubs = []): void
{
    config()->set('bdpaygate.gateways.bkash', (new BkashScenario)->config());

    Http::fake($stubs + [
        BKASH_V2.'/auth/grant-token' => Http::response(BkashScenario::tokenResponse('ID-TOKEN', 'REFRESH-1')),
        BKASH_V2.'/auth/refresh-token' => Http::response(BkashScenario::tokenResponse('ID-TOKEN-2', 'REFRESH-2')),
        BKASH_V2.'/payment/create' => fn (Request $r) => Http::response(BkashScenario::created($r['merchantInvoiceNumber'], (string) $r['amount'], (string) $r['callbackURL'])),
    ]);
}

function bkashPayment(string|int $amount = 120, string $reference = 'INV-1'): Payment
{
    return BdPayGate::driver('bkash')->charge($amount, $reference, customer(), 'Invoice');
}

/**
 * @return array<string, string>
 */
function bkashReturn(Payment $payment, string $status = 'success'): array
{
    return ['paymentID' => (string) $payment->gateway_payment_id, 'status' => $status, 'signature' => BkashScenario::signature((string) $payment->gateway_payment_id)];
}

/**
 * @return array<string, mixed>
 */
function bkashCompleted(Payment $payment, array $extra = []): array
{
    return $extra + [
        'paymentId' => $payment->gateway_payment_id, 'trxId' => 'DIK20PG0H4', 'transactionStatus' => 'Completed',
        'amount' => $payment->amount, 'currency' => 'BDT', 'intent' => 'sale', 'merchantInvoiceNumber' => $payment->gateway_reference,
        'maxRefundableAmount' => $payment->amount,
    ];
}

function bkashCompletedPayment(string $amount = '500.00', array $executeExtra = []): Payment
{
    return Payment::query()->create([
        'gateway' => 'bkash', 'reference' => 'ORD-R1', 'gateway_reference' => 'BPREFUND0000000001', 'amount' => $amount, 'currency' => 'BDT',
        'status' => PaymentStatus::Completed, 'gateway_payment_id' => 'TR0011REFUND', 'gateway_transaction_id' => 'DIK80PG0SO', 'paid_at' => now(),
        'gateway_response' => [['step' => 'execute', 'at' => now()->toIso8601String(), 'payload' => $executeExtra + [
            'paymentId' => 'TR0011REFUND', 'trxId' => 'DIK80PG0SO', 'transactionStatus' => 'Completed', 'amount' => $amount, 'maxRefundableAmount' => $amount,
        ]]],
    ]);
}

function bkashTimeout(): Closure
{
    return fn (Request $r) => Create::rejectionFor(new ConnectException('Operation timed out', $r->toPsrRequest()));
}

it('creates a v2 payment with the documented headers and body', function () {
    bkashV2();

    $payment = bkashPayment(120, 'INV-1');

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gateway_payment_id)->toBe('TR0011'.$payment->gateway_reference)
        ->and($payment->checkout_url)->toContain('sandbox.payment.bkash.com');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://'.BKASH_V2.'/auth/grant-token'
        && $r->hasHeader('username', 'sandboxuser')
        && $r->hasHeader('password', 'sandboxpass')
        && $r->data() === ['app_key' => 'app-key', 'app_secret' => 'app-secret']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://'.BKASH_V2.'/payment/create'
        && $r->hasHeader('Authorization', 'ID-TOKEN')
        && $r->hasHeader('X-APP-Key', 'app-key')
        && $r->data() === [
            'payerReference' => '01711000000',
            'callbackURL' => route('bdpaygate.callback', ['gateway' => 'bkash', 'outcome' => 'callback']),
            'amount' => '120.00',
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $payment->gateway_reference,
        ]);
});

it('surfaces the v2 error code when bKash refuses to create a payment', function () {
    bkashV2([BKASH_V2.'/payment/create' => Http::response(BkashScenario::error('2007', 'Invalid currency.', 'invalid_currency'), 400)]);

    bkashPayment();
})->throws(GatewayException::class, '2007: Invalid currency.');

it('refuses non-BDT payments before calling bKash', function () {
    config()->set('bdpaygate.currency', 'USD');
    bkashV2();

    expect(fn () => bkashPayment())->toThrow(GatewayException::class, 'only accepts BDT');
    Http::assertNothingSent();
});

it('strips characters bKash rejects from payerReference', function () {
    bkashV2();

    BdPayGate::driver('bkash')->charge(100, 'A<B>&C', new Customer(name: 'X', phone: ''));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/create') && $r['payerReference'] === 'ABC');
});

it('grants a token once and reuses it from cache', function () {
    bkashV2();

    bkashPayment(100, 'INV-1');
    bkashPayment(100, 'INV-2');

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/auth/'))->count())->toBe(1);
});

it('refreshes an expiring token with the refresh token instead of granting again', function () {
    bkashV2();
    bkashPayment(100, 'INV-1');

    $this->travel(51)->minutes();
    bkashPayment(100, 'INV-2');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/auth/refresh-token')
        && $r['refresh_token'] === 'REFRESH-1'
        && $r['app_key'] === 'app-key'
        && $r->hasHeader('username', 'sandboxuser'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/create') && $r->hasHeader('Authorization', 'ID-TOKEN-2'));
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/auth/grant-token'))->count())->toBe(1);
});

it('grants again when bKash refuses the refresh token', function () {
    bkashV2([
        BKASH_V2.'/auth/refresh-token' => Http::response(['statusCode' => '9999', 'statusMessage' => 'System error']),
        BKASH_V2.'/auth/grant-token' => Http::sequence()
            ->push(BkashScenario::tokenResponse('ID-TOKEN', 'REFRESH-1'))
            ->push(BkashScenario::tokenResponse('ID-TOKEN-3', 'REFRESH-3')),
    ]);
    bkashPayment(100, 'INV-1');

    $this->travel(2)->hours();
    bkashPayment(100, 'INV-2');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/create') && $r->hasHeader('Authorization', 'ID-TOKEN-3'));
});

it('drops a rejected token and retries exactly once', function () {
    bkashV2([
        BKASH_V2.'/payment/create' => Http::sequence()
            ->push(['message' => 'Unauthorized'], 401)
            ->push(BkashScenario::created('ANY', '100.00')),
    ]);

    $payment = bkashPayment(100);

    expect($payment->gateway_payment_id)->toBe('TR0011ANY');
    $creates = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/payment/create'))->values();
    expect($creates)->toHaveCount(2)
        ->and($creates[0][0]->header('Authorization'))->toBe(['ID-TOKEN'])
        ->and($creates[1][0]->header('Authorization'))->toBe(['ID-TOKEN-2']);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/auth/refresh-token'));
});

it('does not loop when the fresh token is rejected too', function () {
    bkashV2([BKASH_V2.'/payment/create' => Http::response(['message' => 'Unauthorized'], 401)]);

    expect(fn () => bkashPayment())->toThrow(GatewayException::class, 'HTTP 401');
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/payment/create')))->toHaveCount(2);
});

it('never makes a third token call within an hour', function () {
    bkashV2([BKASH_V2.'/payment/create' => Http::response(['message' => 'Unauthorized'], 401)]);

    // Grant, then refresh after the 401: two calls this hour.
    expect(fn () => bkashPayment(100, 'INV-1'))->toThrow(GatewayException::class);

    // The next 401 would need a third. Refused locally instead of getting blocked for an hour.
    expect(fn () => bkashPayment(100, 'INV-2'))->toThrow(GatewayUnavailableException::class, 'token calls per hour');
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/auth/')))->toHaveCount(2);
});

it('speaks v1.2.0-beta when configured, and refunds through the v2 refund API', function () {
    config()->set('bdpaygate.gateways.bkash', (new BkashScenario)->config() + ['version' => 'v1.2']);
    Http::fake([
        BKASH_V1.'/token/grant' => Http::response(BkashScenario::tokenResponse('V1-TOKEN', 'V1-REFRESH')),
        BKASH_V1.'/create' => fn (Request $r) => Http::response([
            'statusCode' => '0000', 'statusMessage' => 'Successful', 'paymentID' => 'TR0011V1', 'transactionStatus' => 'Initiated',
            'bkashURL' => 'https://sandbox.payment.bkash.com/?paymentId=TR0011V1&mode=0011&apiVersion=v1.2.0-beta/',
            'successCallbackURL' => $r['callbackURL'].'?paymentID=TR0011V1&status=success&signature=cm8HBfl65A',
            'amount' => $r['amount'], 'currency' => 'BDT', 'intent' => 'sale', 'merchantInvoiceNumber' => $r['merchantInvoiceNumber'],
        ]),
        BKASH_V1.'/execute' => fn () => Http::response([
            'statusCode' => '0000', 'statusMessage' => 'Successful', 'paymentID' => 'TR0011V1', 'customerMsisdn' => '01770618575',
            'trxID' => '6H7801QFYM', 'amount' => '150.00', 'transactionStatus' => 'Completed', 'currency' => 'BDT', 'intent' => 'sale',
        ]),
        BKASH_V2.'/refund/payment/transaction' => Http::response(['originalTrxId' => '6H7801QFYM', 'refundTrxId' => 'RFV1', 'refundTransactionStatus' => 'Completed', 'refundAmount' => '50.00']),
    ]);

    $payment = bkashPayment(150);

    $this->get('/bdpaygate/bkash/callback?paymentID=TR0011V1&status=success&signature=cm8HBfl65A')
        ->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_transaction_id)->toBe('6H7801QFYM');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1.2.0-beta/tokenized/checkout/create')
        && $r['mode'] === '0011' && $r->hasHeader('Authorization', 'V1-TOKEN'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1.2.0-beta/tokenized/checkout/execute') && $r->data() === ['paymentID' => 'TR0011V1']);

    $refund = BdPayGate::refund($payment, 50, 'Partial');

    expect($refund->accepted)->toBeTrue()->and($refund->refundId)->toBe('RFV1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/tokenized-checkout/refund/payment/transaction')
        && $r['paymentId'] === 'TR0011V1' && $r['trxId'] === '6H7801QFYM');
});

it('reads v1.2.0-beta errors from statusCode and statusMessage', function () {
    config()->set('bdpaygate.gateways.bkash', (new BkashScenario)->config() + ['version' => 'v1.2']);
    Http::fake([
        BKASH_V1.'/token/grant' => Http::response(BkashScenario::tokenResponse('V1-TOKEN', 'V1-REFRESH')),
        BKASH_V1.'/create' => Http::response(['statusCode' => '2065', 'statusMessage' => 'Mendatory field missing']),
    ]);

    bkashPayment();
})->throws(GatewayException::class, '2065: Mendatory field missing');

it('executes on a success callback and records the trxId', function () {
    Event::fake([PaymentCompleted::class]);
    bkashV2([BKASH_V2.'/payment/execute' => fn (Request $r) => Http::response(bkashCompleted(Payment::query()->firstOrFail()))]);
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment)))->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_transaction_id)->toBe('DIK20PG0H4');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/execute') && $r->data() === ['paymentId' => $payment->gateway_payment_id]);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

it('keeps the payment pending on 2056 Invalid Payment State', function () {
    bkashV2([
        BKASH_V2.'/payment/execute' => Http::response(BkashScenario::error('2056', 'Invalid Payment State', 'invalid_payment_state')),
        BKASH_V2.'/query/payment' => fn () => Http::response(['paymentId' => Payment::query()->firstOrFail()->gateway_payment_id, 'transactionStatus' => 'Initiated', 'amount' => '120.00']),
    ]);
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment)))->assertRedirect('/pending?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gatewayResponse('query')['execute_error'])->toBe(['code' => '2056', 'message' => 'Invalid Payment State']);
});

it('fails a definite decline and keeps the bKash code', function (string $code, string $message) {
    bkashV2([
        BKASH_V2.'/payment/execute' => Http::response(BkashScenario::error($code, $message)),
        BKASH_V2.'/query/payment' => fn () => Http::response(['paymentId' => Payment::query()->firstOrFail()->gateway_payment_id, 'transactionStatus' => 'Initiated', 'amount' => '120.00']),
    ]);
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment)))->assertRedirect('/failed?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe("{$code}: {$message}");
})->with([
    'insufficient balance' => ['2023', 'Insufficient Balance'],
    'duplicate' => ['2029', 'Duplicate for all transactions'],
]);

it('queries after an execute timeout and completes if bKash says Completed', function () {
    bkashV2([
        BKASH_V2.'/payment/execute' => bkashTimeout(),
        BKASH_V2.'/query/payment' => fn () => Http::response(bkashCompleted(Payment::query()->firstOrFail())),
    ]);
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment)))->assertRedirect('/thanks?payment='.$payment->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('queries instead of executing a paymentID twice', function () {
    bkashV2([
        BKASH_V2.'/payment/execute' => Http::response(BkashScenario::error('2056', 'Invalid Payment State')),
        BKASH_V2.'/query/payment' => Http::sequence()
            ->push(['transactionStatus' => 'Initiated', 'amount' => '120.00'])
            ->push(['transactionStatus' => 'Completed', 'trxId' => 'LATE1', 'amount' => '120.00', 'currency' => 'BDT']),
    ]);
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment)));
    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment)));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Completed)
        ->and($payment->gateway_transaction_id)->toBe('LATE1');
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/payment/execute')))->toHaveCount(1);
});

it('checks cancel and failure callbacks with Query Payment before believing them', function (string $status, PaymentStatus $expected) {
    bkashV2([BKASH_V2.'/query/payment' => Http::response(['transactionStatus' => 'Initiated', 'amount' => '120.00'])]);
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment, $status)));

    expect($payment->refresh()->status)->toBe($expected);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/query/payment'));
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/payment/execute'));
})->with([
    'cancel' => ['cancel', PaymentStatus::Cancelled],
    'failure' => ['failure', PaymentStatus::Failed],
]);

it('rejects a callback whose signature does not match the one bKash issued', function () {
    bkashV2();
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?paymentID='.$payment->gateway_payment_id.'&status=cancel&signature=forged')
        ->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/query/payment') || str_contains($r->url(), '/payment/execute'));
});

it('flags an execute that completed for a different amount', function () {
    bkashV2([BKASH_V2.'/payment/execute' => fn () => Http::response(bkashCompleted(Payment::query()->firstOrFail(), ['amount' => '1.00']))]);
    $payment = bkashPayment();

    $this->get('/bdpaygate/bkash/callback?'.http_build_query(bkashReturn($payment)))->assertRedirect('/failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->requires_review)->toBeTrue();
});

it('executes on sync when the customer verified but the callback never arrived', function () {
    bkashV2([
        BKASH_V2.'/query/payment' => fn () => Http::response(['paymentId' => Payment::query()->firstOrFail()->gateway_payment_id, 'transactionStatus' => 'Initiated', 'verificationStatus' => 'Complete', 'amount' => '120.00']),
        BKASH_V2.'/payment/execute' => fn () => Http::response(bkashCompleted(Payment::query()->firstOrFail())),
    ]);
    $payment = bkashPayment();

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Completed);
});

it('fails a payment still Initiated after its 24 hour lifetime', function () {
    bkashV2([BKASH_V2.'/query/payment' => Http::response(['transactionStatus' => 'Initiated', 'verificationStatus' => 'Incomplete', 'amount' => '120.00'])]);
    $payment = bkashPayment();

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Pending);

    $this->travel(25)->hours();

    expect(BdPayGate::sync($payment)->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toContain('Expired');
});

it('maps a definite query status on sync', function (string $state, PaymentStatus $expected) {
    bkashV2([BKASH_V2.'/query/payment' => Http::response(['transactionStatus' => $state, 'amount' => '120.00'])]);

    expect(BdPayGate::sync(bkashPayment())->status)->toBe($expected);
})->with([
    'Cancelled' => ['Cancelled', PaymentStatus::Cancelled],
    'Failed' => ['Failed', PaymentStatus::Failed],
    'Expired' => ['Expired', PaymentStatus::Failed],
]);

it('sends partial refunds with the v2 body and counts them', function () {
    bkashV2([BKASH_V2.'/refund/payment/transaction' => Http::sequence()
        ->push(['originalTrxId' => 'DIK80PG0SO', 'refundTrxId' => 'DIK50PG0VX', 'refundTransactionStatus' => 'Completed', 'refundAmount' => '100.00'])
        ->push(['originalTrxId' => 'DIK80PG0SO', 'refundTrxId' => 'DIK90PG0TJ', 'refundTransactionStatus' => 'Completed', 'refundAmount' => '150.00'])]);
    $payment = bkashCompletedPayment('500.00');

    $first = BdPayGate::refund($payment, 100, 'Damaged item');
    $second = BdPayGate::refund($payment, 150, 'Late delivery');

    expect($first->accepted)->toBeTrue()
        ->and($first->pending)->toBeFalse()
        ->and($first->refundId)->toBe('DIK50PG0VX')
        ->and($second->refundId)->toBe('DIK90PG0TJ')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->refunded_amount)->toBe('250.00');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund/payment/transaction') && $r->data() === [
        'paymentId' => 'TR0011REFUND', 'trxId' => 'DIK80PG0SO', 'refundAmount' => '100.00', 'sku' => 'ORD-R1', 'reason' => 'Damaged item',
    ]);
});

it('records a rejected refund with the bKash code and does not count it', function () {
    bkashV2([BKASH_V2.'/refund/payment/transaction' => Http::response(BkashScenario::error('2072', 'Refund amount not valid', 'refund_amount_exceed_payment_amount'), 400)]);
    $payment = bkashCompletedPayment();

    $result = BdPayGate::refund($payment, 100);

    expect($result->accepted)->toBeFalse()
        ->and($result->message)->toBe('2072: Refund amount not valid')
        ->and($payment->refresh()->refunded_amount)->toBe('0.00');
});

it('treats a refund timeout as pending and exposes the refund status', function () {
    bkashV2([
        BKASH_V2.'/refund/payment/transaction' => bkashTimeout(),
        BKASH_V2.'/refund/payment/status' => Http::response([
            'originalTrxId' => 'DIK80PG0SO', 'originalTrxAmount' => '500', 'originalTrxCompletedTime' => '2026-09-20T14:39:02:266 GMT+0600',
            'refundTransactions' => [['refundTrxId' => 'DIK50PG0VX', 'refundTransactionStatus' => 'Completed', 'refundAmount' => '100.00', 'completedTime' => '2026-09-20T14:47:02:000']],
        ]),
    ]);
    $payment = bkashCompletedPayment();

    $result = BdPayGate::refund($payment, 100);

    expect($result->accepted)->toBeTrue()
        ->and($result->pending)->toBeTrue()
        ->and($result->message)->toContain('refundStatus()')
        ->and($payment->refresh()->refunded_amount)->toBe('100.00');

    /** @var BkashGateway $gateway */
    $gateway = BdPayGate::for($payment);
    $status = $gateway->refundStatus($payment);

    expect($status['original_trx_id'])->toBe('DIK80PG0SO')
        ->and($status['refunds'])->toBe([['refund_trx_id' => 'DIK50PG0VX', 'status' => 'Completed', 'amount' => '100.00', 'completed_at' => '2026-09-20T14:47:02:000']]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund/payment/status') && $r->data() === ['paymentId' => 'TR0011REFUND', 'trxId' => 'DIK80PG0SO']);
});

it('caps refunds at maxRefundableAmount when a coupon was applied', function () {
    bkashV2();
    $payment = bkashCompletedPayment('20.00', ['couponAmount' => '4.00', 'maxRefundableAmount' => '16.0']);

    expect(fn () => BdPayGate::refund($payment))->toThrow(GatewayException::class, 'at most 16.00');
    Http::assertNothingSent();
});

it('stops at ten refunds per transaction', function () {
    bkashV2([BKASH_V2.'/refund/payment/transaction' => fn (Request $r) => Http::response(['refundTrxId' => 'RF'.uniqid(), 'refundTransactionStatus' => 'Completed', 'refundAmount' => $r['refundAmount']])]);
    $payment = bkashCompletedPayment('500.00');

    for ($i = 0; $i < 10; $i++) {
        BdPayGate::refund($payment, 10);
    }

    expect(fn () => BdPayGate::refund($payment, 10))->toThrow(GatewayException::class, 'at most 10 refunds');
});
