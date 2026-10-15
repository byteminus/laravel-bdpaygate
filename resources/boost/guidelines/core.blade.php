## BDPayGate (byteminus/laravel-bdpaygate)

One Laravel API for Bangladeshi payment gateways: bKash, Nagad, SSLCommerz, Upay, ShurjoPay, aamarPay, PortPos, PayStation, UddoktaPay, Moneybag, EPS, DGePay. Rocket, OK Wallet and CellFin are paid through SSLCommerz, ShurjoPay or aamarPay. Facade: `ByteMinus\BdPayGate\Facades\BdPayGate`.

### Rules

- Never mark an order paid on the redirect or thank-you page. Fulfil orders only in a `PaymentCompleted` listener: it fires once, after the package confirmed the payment with the gateway's own API.
- Do not write your own routes or controllers for gateway callbacks. The package owns `/bdpaygate/{gateway}/{success|fail|cancel|ipn|callback}` (no session, no CSRF) and verifies every callback.
- `reference` is the app's own order id. Charging the same reference again (a retry) creates a new attempt; a reference that is already paid throws `GatewayException`.
- Credentials go in `.env` and `config/bdpaygate.php` (`php artisan vendor:publish --tag=bdpaygate-config`), never in code.
- Schedule `bdpaygate:sync` so payments whose callback never arrived are settled.
- In tests, use `BdPayGate::fake()`. Never call real gateways from tests.

### Take a payment

@verbatim
<code-snippet name="Charge and redirect to the gateway" lang="php">
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Facades\BdPayGate;

$payment = BdPayGate::driver('bkash')->charge(
    amount: $order->total,
    reference: $order->number,
    customer: new Customer(name: $user->name, phone: $user->phone, email: $user->email),
    description: "Order {$order->number}",
    meta: ['order_id' => $order->id],
);

return redirect($payment->checkout_url);
</code-snippet>
@endverbatim

`BdPayGate::charge(...)` uses the default gateway (`BDPAYGATE_GATEWAY`). Gateway names: `bkash`, `nagad`, `sslcommerz`, `upay`, `shurjopay`, `aamarpay`, `portpos`, `paystation`, `uddoktapay`, `moneybag`, `eps`, `dgepay`.

### Fulfil the order

@verbatim
<code-snippet name="Listen for confirmed payments" lang="php">
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(PaymentCompleted::class, function (PaymentCompleted $event) {
    $payment = $event->payment; // ByteMinus\BdPayGate\Models\Payment

    if ($payment->requires_review) {
        // The gateway flagged it as risky (e.g. SSLCommerz risk_level=1): hold delivery.
    }

    Order::find($payment->meta['order_id'])->markPaid($payment->gateway_transaction_id);
});
</code-snippet>
@endverbatim

Other events: `PaymentFailed`, `PaymentCancelled`, `PaymentDuplicated` (a second attempt for an already-paid reference was also paid: refund it), `RefundProcessed`.

After the gateway returns, the customer lands on `BDPAYGATE_REDIRECT_SUCCESS`, `_FAILED`, `_CANCELLED` or `_PENDING` with `?payment={id}`. Show status there; do not change order state there.

### Refund and sync

@verbatim
<code-snippet name="Refund through the payment's gateway" lang="php">
use ByteMinus\BdPayGate\Contracts\SupportsRefunds;
use ByteMinus\BdPayGate\Facades\BdPayGate;

if (BdPayGate::for($payment) instanceof SupportsRefunds) {
    $result = BdPayGate::refund($payment, 500, 'Damaged item'); // null amount = everything left
    // $result->accepted, $result->pending (settles later), $result->refundId
}

BdPayGate::sync($payment); // ask the gateway for the current state
</code-snippet>
@endverbatim

Refund APIs exist only for bKash, SSLCommerz, PortPos (partial) and Upay (full). Others throw `UnsupportedOperationException`: refund from the gateway's merchant panel.

### Test

@verbatim
<code-snippet name="Fake gateway in feature tests" lang="php">
use ByteMinus\BdPayGate\Facades\BdPayGate;

$fake = BdPayGate::fake();
$payment = BdPayGate::charge(300, 'ORD-1', $customer);

$this->get($payment->checkout_url);   // completes it like a real redirect
$fake->fail($payment);                 // or drive it from code
</code-snippet>
@endverbatim
