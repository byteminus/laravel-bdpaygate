# UddoktaPay

**Status:** stable · **Driver:** `uddoktapay` · **Refunds:** no (see below) · **Sync:** yes, once the customer has submitted a payment

> [!WARNING]
> **UddoktaPay is not a licensed payment gateway.** It is self-hosted software that you install on your own server. Customers pay straight into mobile-wallet or bank accounts that you own, often personal bKash, Nagad, Rocket or Upay accounts, and the software then detects those payments. There is no acquirer, no payment service provider and no settlement in between.
>
> Collecting business payments through personal wallet accounts may breach your wallet provider's terms and Bangladesh Bank rules. You alone are responsible for your accounts, KYC, tax and regulatory compliance, disputes and refunds. This package only talks to the API your installation exposes and makes no claim that using it is permitted. If you need a licensed gateway, use SSLCommerz, bKash, Nagad, PayStation or another regulated provider.

## Setup

```dotenv
UDDOKTAPAY_BASE_URL=https://pay.yourdomain.com
UDDOKTAPAY_API_KEY=your-api-key
UDDOKTAPAY_SANDBOX=false
```

```php
// config/bdpaygate.php → gateways
'uddoktapay' => [
    'driver' => 'uddoktapay',
    'base_url' => env('UDDOKTAPAY_BASE_URL'),
    'api_key' => env('UDDOKTAPAY_API_KEY'),
    'sandbox' => env('UDDOKTAPAY_SANDBOX', false),
],
```

- `base_url` is **required** for live use. Use your UddoktaPay installation's own domain. You can paste the panel URL or a full API URL: anything from `/api` onwards is stripped, so `https://pay.yourdomain.com/api/checkout-v2` also works.
- `api_key` comes from your UddoktaPay dashboard. It is sent in the `RT-UDDOKTAPAY-API-KEY` header.
- With `sandbox` on and no `base_url`, the driver uses UddoktaPay's public sandbox, `https://sandbox.uddoktapay.com`. Its shared test API key is published on UddoktaPay's [API information](https://uddoktapay.readme.io/reference/api-information) page. With `sandbox` off and no `base_url`, `charge()` throws.
- Only BDT is supported, through the `checkout-v2` API. UddoktaPay's `checkout-v2/global` variant for other currencies is not wired in.

Docs: <https://uddoktapay.readme.io/reference/overview>

## How a payment is verified

UddoktaPay creates its `invoice_id` only after the customer submits a payment on the checkout page. Before that, the payment has no id that UddoktaPay can be asked about.

1. `charge()` calls `POST {base_url}/api/checkout-v2` with the customer's name and email, the amount as a string, and `metadata` holding this attempt's `gateway_reference`, your own reference and the package's payment id. It also sends:
   - `redirect_url`: `/bdpaygate/uddoktapay/success`, with `return_type: GET`
   - `cancel_url`: `/bdpaygate/uddoktapay/cancel?ref=…`
   - `webhook_url`: `/bdpaygate/uddoktapay/ipn`

   The customer is sent to the returned `payment_url`.
2. On success, UddoktaPay returns the customer with an `invoice_id`. It arrives as a query parameter, or as a POST field if your installation posts it; both are accepted.
3. The package calls `POST {base_url}/api/verify-payment` with that `invoice_id`. It finds the payment **from the `metadata` in the verified response**, never from the callback.
   - **`COMPLETED`:** completes the payment once the amount matches. `invoice_id` is stored as `gateway_payment_id` and `transaction_id` as `gateway_transaction_id`.
   - **`PENDING`:** the customer submitted a payment that has not been matched yet, for example while you approve it manually. The payment stays pending and the `invoice_id` is kept, so `BdPayGate::sync()` and `bdpaygate:sync` can settle it later.
   - **`ERROR`** (for example "No Data Found"): the callback is rejected and the payment is unchanged.
4. **Webhook (`/ipn`):** UddoktaPay's only webhook check is that the `RT-UDDOKTAPAY-API-KEY` header equals your API key. There is no signature, timestamp or replay protection.
   - A missing or wrong key is rejected with HTTP 400 before any API call.
   - A correct key is still not trusted: the posted `invoice_id` goes through `verify-payment` exactly like a redirect, and the webhook body is ignored.
5. **Cancel:** UddoktaPay's cancel link is your `cancel_url` as given, with no `invoice_id`, and verify-payment cannot report a failed or cancelled state. So a cancel cannot be confirmed:
   - If the payment already has an `invoice_id`, the package verifies it and completes the payment when UddoktaPay says it is paid.
   - Otherwise the payment stays pending and the customer is sent to your `pending` page. Show a "try again" option there. `charge()` with the same reference creates a fresh attempt.

## Refunds

Not supported. UddoktaPay documents `POST /api/refund-payment`, but both its success and error responses are documented as an empty object `{}`. There is no way to tell whether a refund was accepted. Money also sits in your own wallet accounts, so refund it from there.

`BdPayGate::refund()` throws `UnsupportedOperationException`.

## Limits and quirks

- `amount` is sent as a string with two decimals.
- `email` is mandatory. If the customer has none, the driver sends `noemail@example.com`.
- The amount check uses `amount` from verify-payment, the invoice amount. `charged_amount` can differ when your installation adds a fee.
- `sync()` on a payment with no `invoice_id` returns it unchanged without calling UddoktaPay.
- UddoktaPay's documentation says `redirect_url` is used to build the success, failure and cancel URLs, but does not show the exact form. This driver keeps `redirect_url` free of query parameters so an appended `?invoice_id=` cannot break it.
- The webhook is documented as an "IPN callback for manual data submission from the admin dashboard". Do not rely on it arriving for every payment. Schedule `bdpaygate:sync`:

  ```php
  Schedule::command('bdpaygate:sync')->everyTenMinutes();
  ```

## Sandbox

The public sandbox (`https://sandbox.uddoktapay.com`, shared API key on the API information page) accepts `checkout-v2` and `verify-payment` calls. Its checkout page asks the customer to type in a wallet transaction id.

Because the sandbox is shared, other people's test invoices live there too. Use it only to try the flow, never for anything real.

## Local testing note

In a sandbox run (2026-10-15) the UddoktaPay sandbox dropped the port from the return URL (`http://127.0.0.1:8123/...` came back as `http://127.0.0.1/...`). Use a URL without a custom port (a tunnel, or port 80) when testing locally. Production URLs are unaffected.
