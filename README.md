# BDPayGate: Bangladesh payment gateways for Laravel

Website: **https://bdpaygate.github.io**

Using Node.js, Next.js, Express or NestJS? The same gateways are in the npm package [`bdpaygate`](https://github.com/byteminus/bdpaygate-js) ([docs](https://bdpaygate.github.io/node/)), and it can share this package's payments table.

Every Bangladeshi payment gateway behind one Laravel API: **bKash, Nagad, SSLCommerz, Upay, ShurjoPay, aamarPay, PortPos, PayStation, UddoktaPay, Moneybag, EPS and DGePay**. Rocket, OK Wallet and IBBL/CellFin are covered through SSLCommerz, ShurjoPay and aamarPay.

```php
use ByteMinus\BdPayGate\Facades\BdPayGate;
use ByteMinus\BdPayGate\Data\Customer;

$payment = BdPayGate::driver('bkash')->charge(
    amount: 1500,
    reference: 'ORD-1001',
    customer: new Customer(name: 'Rahim Uddin', phone: '01711000000', email: 'rahim@example.com'),
    description: 'Order #1001',
);

return redirect($payment->checkout_url);
```

That is the whole integration. The package owns the callback URLs, confirms every payment with the gateway's own API before trusting it, stores it, fires an event once, and sends the customer back to your app.

## Why this one

- **No callback is trusted.** Redirects, IPNs and webhooks are claims. Every gateway confirms the payment server-to-server and checks the amount and currency before anything changes. A forged "failed" or "cancelled" can't stick either: if the gateway later confirms the money moved, the payment completes.
- **Exactly once.** Status changes are atomic, so an IPN and a redirect arriving together can't both fire `PaymentCompleted`.
- **A timeout is not a failure.** When a gateway doesn't answer, the payment stays pending and `bdpaygate:sync` settles it later. Customers who paid are never marked failed.
- **Retries are safe.** Each retry is a new attempt with a fresh gateway id; earlier attempts are kept, and if a customer pays two of them you get a `PaymentDuplicated` event instead of a second fulfilment.
- **One conformance suite for every gateway.** Forged callbacks, amount mismatches, replays, races, timeouts, late successes, malformed input, sync and refunds are tested the same way for all of them.
- Laravel 11, 12 and 13 · PHP 8.2 to 8.5 · Pest, PHPStan, Pint.

## Gateways

| Gateway | Status | Real sandbox run | Refunds | Sync | Docs |
|---|---|---|---|---|---|
| SSLCommerz (cards, banks, wallets incl. Rocket, OK Wallet, CellFin) | Stable | ✅ paid, risk-flagged, partial refund, sync | Full and partial | Yes | [sslcommerz](docs/gateways/sslcommerz.md) |
| ShurjoPay | Stable | ✅ paid, declined | Merchant panel | Yes | [shurjopay](docs/gateways/shurjopay.md) |
| aamarPay | Stable | ✅ paid, declined | Merchant panel | Yes | [aamarpay](docs/gateways/aamarpay.md) |
| UddoktaPay (self-hosted, read the risk note) | Stable | ✅ paid, forged invoice rejected | Merchant panel | Yes | [uddoktapay](docs/gateways/uddoktapay.md) |
| bKash (Tokenized V2, v1.2 supported) | Stable | ✅ paid (V2), two partial refunds, refund status, replayed and forged callbacks rejected; v1.2 create verified | Full and partial | Yes | [bkash](docs/gateways/bkash.md) |
| PayStation | Stable | ✅ paid via bKash, settled through the status API | Merchant panel | Yes | [paystation](docs/gateways/paystation.md) |
| Nagad | Beta | Needs merchant sandbox keys | Merchant panel | Yes | [nagad](docs/gateways/nagad.md) |
| Upay | Beta | Needs merchant sandbox keys | Full | Yes | [upay](docs/gateways/upay.md) |
| PortPos (formerly PortWallet) | Beta | Needs merchant sandbox keys | Full and partial | Yes | [portpos](docs/gateways/portpos.md) |
| Moneybag | Stable | ✅ paid via bKash, simulated failure | Merchant panel | Via transaction id | [moneybag](docs/gateways/moneybag.md) |
| EPS | Experimental | Needs docs and keys | Merchant panel | Yes | [eps](docs/gateways/eps.md) |
| DGePay | Experimental | Needs docs and keys | Merchant panel | Yes | [dgepay](docs/gateways/dgepay.md) |

"Real sandbox run" means a payment was made in the gateway's own sandbox through a Laravel 13 app and settled by this package (2026-10-15). "Merchant panel" means the gateway publishes no refund API; refund from its dashboard.

**Stable**: built from the gateway's public documentation, request and response shapes checked against it or its sandbox. **Beta**: built from public information; not yet run against a merchant sandbox. **Experimental**: the gateway's docs are only available on request; built from its published SDKs. Every gateway page lists what is not yet verified. Test with your own sandbox account before going live.

## Install

```bash
composer require byteminus/laravel-bdpaygate
php artisan vendor:publish --tag=bdpaygate-config
php artisan migrate
```

Add credentials for the gateways you use to `.env` (each gateway page lists its variables), and where to send customers afterwards:

```dotenv
BDPAYGATE_GATEWAY=bkash

BDPAYGATE_REDIRECT_SUCCESS=/checkout/thanks
BDPAYGATE_REDIRECT_FAILED=/checkout/failed
BDPAYGATE_REDIRECT_CANCELLED=/cart
BDPAYGATE_REDIRECT_PENDING=/checkout/processing
```

Redirect values can be a URL or a route name. The customer arrives with `?payment={id}`. Schedule the sync command so payments whose callback never arrived get settled:

```php
// routes/console.php
Schedule::command('bdpaygate:sync')->everyTenMinutes();
```

## The things you will actually do

### Take a payment

```php
$payment = BdPayGate::charge(1500, 'ORD-1001', $customer, 'Order #1001', ['order_id' => 1001]);

return redirect($payment->checkout_url);
```

`BdPayGate::charge()` uses the default gateway; `BdPayGate::driver('nagad')` picks one. `reference` is your order id. Calling `charge()` again with the same reference (the customer retried) creates a new attempt. Anything in `meta` is stored with the payment.

### React when it is paid

```php
use ByteMinus\BdPayGate\Events\PaymentCompleted;

Event::listen(PaymentCompleted::class, function (PaymentCompleted $event) {
    Order::find($event->payment->meta['order_id'])->markPaid($event->payment->gateway_transaction_id);
});
```

Do your business logic in listeners, never on the thank-you page: the customer may close the browser before reaching it, and the IPN or `bdpaygate:sync` will still complete the payment.

| Event | When |
|---|---|
| `PaymentCompleted` | The gateway confirmed the payment. Fires once per reference. |
| `PaymentFailed` / `PaymentCancelled` | The gateway confirmed it did not go through. |
| `PaymentDuplicated` | Another attempt for an already-paid reference was also paid. Refund it. |
| `RefundProcessed` | A refund was accepted (`$event->result->pending` if the gateway settles it later). |

If `$payment->requires_review` is true, the gateway flagged the payment as risky (e.g. SSLCommerz `risk_level=1`): hold delivery until you have checked it.

### Show the result

```php
$payment = \ByteMinus\BdPayGate\Models\Payment::findOrFail(request('payment'));

$payment->isPaid();
$payment->gateway;                  // "bkash"
$payment->gateway_transaction_id;   // the gateway's transaction id
$payment->failure_reason;           // e.g. "2023: Insufficient Balance"
```

### Refund

```php
$result = BdPayGate::refund($payment);                         // everything left
$result = BdPayGate::refund($payment, 500, 'Damaged item');    // partial, where supported

$result->accepted;  // the gateway took it
$result->pending;   // it settles later
```

Gateways without a refund API throw `UnsupportedOperationException`; refund those from the merchant panel. Check ahead of time with `BdPayGate::for($payment) instanceof SupportsRefunds`.

### Test your app without the network

```php
$fake = BdPayGate::fake();                  // becomes the default gateway

$payment = BdPayGate::charge(300, 'ORD-1', $customer);
$this->get($payment->checkout_url);         // completes it, like a real redirect

$fake->fail($payment);                      // or drive it from code
$fake->complete($payment);
BdPayGate::refund($payment, 100);
```

## AI assistants

The package ships guidance for AI coding assistants. With [Laravel Boost](https://laravel.com/docs/boost), run `php artisan boost:install` after installing BDPayGate and your assistant gets the package's rules (fulfil orders in `PaymentCompleted`, never on the redirect page), the API, and an integration skill with every gateway's `.env` keys and troubleshooting.

For other tools, the docs are published for AI readers at [bdpaygate.github.io/llms.txt](https://bdpaygate.github.io/llms.txt), with everything in one file at [llms-full.txt](https://bdpaygate.github.io/llms-full.txt).

## Callback URLs

```
/bdpaygate/{gateway}/{success|fail|cancel|ipn|callback}
```

These routes run without sessions or CSRF: several gateways return the customer with a cross-site POST, which would otherwise log them out. Register `/bdpaygate/{gateway}/ipn` with gateways that need an IPN URL. In sandbox, expose your machine with a tunnel and set `APP_URL` to it.

Nagad and Upay only accept calls from whitelisted server IPs. If your servers have no fixed IP, set `BDPAYGATE_PROXY` (or a per-gateway `proxy`).

## Two stores, one gateway

Each config key is a gateway name, and `driver` picks the implementation:

```php
'gateways' => [
    'sslcommerz_retail' => ['driver' => 'sslcommerz', 'store_id' => ..., 'store_password' => ...],
    'sslcommerz_b2b' => ['driver' => 'sslcommerz', 'store_id' => ..., 'store_password' => ...],
],
```

Payments, routes and credentials stay separate.

## Your own gateway

```php
BdPayGate::extend('mygateway', fn ($app, array $config, string $name) => new MyGateway($config, $name));
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for writing a driver and running it through the conformance suite.

## Development

```bash
composer install
composer test       # Pest
composer analyse    # PHPStan
composer format     # Pint
CONFORMANCE=bkash,nagad vendor/bin/pest tests/Feature/ConformanceTest.php
```

## License

MIT
