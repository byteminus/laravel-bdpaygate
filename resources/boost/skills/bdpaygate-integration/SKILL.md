---
name: bdpaygate-integration
description: Set up, configure and troubleshoot Bangladeshi payment gateways (bKash, Nagad, SSLCommerz, Upay, ShurjoPay, aamarPay, PortPos, PayStation, UddoktaPay, Moneybag, EPS, DGePay) with the byteminus/laravel-bdpaygate package. Use when adding a gateway, writing payment or refund code, handling callbacks, or debugging a payment stuck in pending.
---

# BDPayGate integration

## Install

```bash
composer require byteminus/laravel-bdpaygate
php artisan vendor:publish --tag=bdpaygate-config
php artisan migrate
```

Schedule the settlement command:

```php
// routes/console.php
Schedule::command('bdpaygate:sync')->everyTenMinutes();
```

## .env per gateway

| Gateway | Variables |
|---|---|
| bKash | `BKASH_APP_KEY`, `BKASH_APP_SECRET`, `BKASH_USERNAME`, `BKASH_PASSWORD`, `BKASH_SANDBOX`, `BKASH_VERSION` (`v2` or `v1.2`) |
| SSLCommerz | `SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD`, `SSLCOMMERZ_SANDBOX` |
| ShurjoPay | `SHURJOPAY_USERNAME`, `SHURJOPAY_PASSWORD`, `SHURJOPAY_PREFIX`, `SHURJOPAY_SANDBOX` |
| aamarPay | `AAMARPAY_STORE_ID`, `AAMARPAY_SIGNATURE_KEY`, `AAMARPAY_SANDBOX` |
| Nagad | `NAGAD_MERCHANT_ID`, `NAGAD_MERCHANT_NUMBER`, `NAGAD_PUBLIC_KEY`, `NAGAD_PRIVATE_KEY`, `NAGAD_SANDBOX`, `NAGAD_BASE_URL`, `NAGAD_PROXY` |
| Upay | `UPAY_MERCHANT_ID`, `UPAY_MERCHANT_KEY`, `UPAY_MERCHANT_CODE`, `UPAY_MERCHANT_NAME`, `UPAY_MERCHANT_MOBILE`, `UPAY_SANDBOX`, `UPAY_PROXY` |
| PortPos | `PORTPOS_APP_KEY`, `PORTPOS_SECRET_KEY`, `PORTPOS_SANDBOX` |
| PayStation | `PAYSTATION_MERCHANT_ID`, `PAYSTATION_PASSWORD`, `PAYSTATION_SANDBOX` |
| UddoktaPay | `UDDOKTAPAY_BASE_URL`, `UDDOKTAPAY_API_KEY` |
| Moneybag | `MONEYBAG_API_KEY`, `MONEYBAG_SANDBOX` |
| EPS | `EPS_USERNAME`, `EPS_PASSWORD`, `EPS_HASH_KEY`, `EPS_MERCHANT_ID`, `EPS_STORE_ID`, `EPS_SANDBOX` |
| DGePay | `DGEPAY_CLIENT_ID`, `DGEPAY_CLIENT_SECRET`, `DGEPAY_API_KEY`, `DGEPAY_SANDBOX` |

Global: `BDPAYGATE_GATEWAY` (default gateway), `BDPAYGATE_REDIRECT_SUCCESS|FAILED|CANCELLED|PENDING` (route name or URL), `BDPAYGATE_PROXY` (fixed-IP egress for gateways that whitelist server IPs).

## Capabilities

| Gateway | Refunds | Status sync |
|---|---|---|
| bKash | Full and partial (up to 10) | Yes |
| SSLCommerz | Full and partial (asynchronous) | Yes |
| PortPos | Full and partial (asynchronous) | Yes |
| Upay | Full only | Yes |
| ShurjoPay, aamarPay, PayStation, UddoktaPay, Nagad, EPS, DGePay | Merchant panel only | Yes |
| Moneybag | Merchant panel only | No (use `verifyTransaction($payment, $transactionId)`) |

Check in code with `instanceof ByteMinus\BdPayGate\Contracts\SupportsRefunds`, `SupportsPartialRefunds`, `SupportsSync`.

## Payment lifecycle

1. `charge()` creates a pending `Payment` row (one per attempt, with a fresh `gateway_reference`) and returns `checkout_url`.
2. The gateway calls `/bdpaygate/{gateway}/...`. The package ignores what the callback claims and asks the gateway's API.
3. Confirmed paid and amount matches: `Completed`, `PaymentCompleted` fires once. Confirmed declined: `Failed` or `Cancelled` (these can still become `Completed` if the gateway later confirms payment). No answer: stays `Pending` until `bdpaygate:sync`.

`Payment` fields to use: `status` (`PaymentStatus` enum), `reference`, `amount`, `gateway_transaction_id`, `failure_reason`, `requires_review`, `refunded_amount`, `meta`, `paid_at`. `$payment->attempts()` lists every attempt for the same reference.

## Several stores of one gateway

```php
// config/bdpaygate.php
'gateways' => [
    'sslcommerz_retail' => ['driver' => 'sslcommerz', 'store_id' => env('RETAIL_STORE_ID'), 'store_password' => env('RETAIL_STORE_PASSWORD')],
    'sslcommerz_b2b' => ['driver' => 'sslcommerz', 'store_id' => env('B2B_STORE_ID'), 'store_password' => env('B2B_STORE_PASSWORD')],
],
```

## Troubleshooting

- **Payment stuck in pending:** the gateway did not answer when the callback arrived. Run `php artisan bdpaygate:sync`, or `BdPayGate::sync($payment)`.
- **Customer logged out after paying:** the app added `web` middleware to the package routes. Keep `bdpaygate.routes.middleware` empty.
- **"Amount mismatch":** the gateway reported a different amount than was charged; the payment is not completed. Check for coupons or currency settings.
- **Nagad or Upay rejects calls in production:** they whitelist server IPs. Use a fixed-IP server or set `NAGAD_PROXY` / `UPAY_PROXY`.
- **Testing UddoktaPay locally:** its sandbox drops custom ports from the return URL; use a tunnel instead of `localhost:8000`.
- **Writing a custom gateway:** extend `ByteMinus\BdPayGate\Drivers\AbstractGateway`, register with `BdPayGate::extend('name', fn ($app, array $config, string $name) => new MyGateway($config, $name))`.
