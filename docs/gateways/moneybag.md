# Moneybag

**Status:** stable · **Driver:** `moneybag` · **Refunds:** no public API · **Sync:** no public API (see below)

Moneybag is a Bangladeshi hosted checkout (cards, mobile wallets, EMI). Its public merchant API is small: create a checkout, and verify a transaction.

## Setup

```dotenv
MONEYBAG_API_KEY=your-merchant-api-key
MONEYBAG_SANDBOX=true
```

```php
// config/bdpaygate.php → gateways
'moneybag' => [
    'driver' => 'moneybag',
    'api_key' => env('MONEYBAG_API_KEY'),
    'sandbox' => env('MONEYBAG_SANDBOX', true),
    // 'base_url' => env('MONEYBAG_BASE_URL'), // override the API origin
],
```

| | API origin |
|---|---|
| Sandbox | `https://sandbox.api.moneybag.com.bd` |
| Live | `https://api.moneybag.com.bd` |

Sandbox accounts are self-serve: create one at <https://developers.moneybag.com.bd/sandbox> (or <https://sandbox.moneybag.com.bd>). The merchant API key is shown **once**, so store it straight away. Production keys need a separate review and approval by Moneybag. The public contract lists only the sandbox origin; the live origin above comes from Moneybag's own SDK and plugin releases. Confirm it with Moneybag at go-live, and set `base_url` if they give you another. Docs: <https://developers.moneybag.com.bd/docs>.

## How a payment is verified

1. `charge()` creates a checkout (`POST /api/v2/payments/checkout`, header `X-Merchant-API-Key`). This attempt's `gateway_reference` is sent as `order_id`, and your own reference goes in `metadata.reference`. The returned `session_id` is stored in `gateway_payment_id`, and the customer is sent to the returned `checkout_url`.
2. Moneybag returns the customer to `/bdpaygate/moneybag/{success|fail|cancel}?ref=…`, adding `transaction_id` and `status` (`SUCCESS`, `FAILED` or `CANCELLED`).
3. Neither parameter is trusted. The driver calls `GET /api/v2/payments/verify/{transaction_id}` and checks:
   - that the transaction's `order_id` is this attempt's `gateway_reference`;
   - for a payment, that `status` is `SUCCESS` **and** `verified` is `true`, and that `amount` and `currency` match.
4. A verified `SUCCESS` completes the payment, even on the fail or cancel URL. A verified `FAILED` or `CANCELLED` fails or cancels it. Anything else stays pending: an unknown transaction, a non-success answer, or `verified: false`.
5. The Moneybag `transaction_id` is stored in `gateway_transaction_id`.

## No sync, no refunds

The public API can only verify by `transaction_id`. There is no lookup by order or session, and no refund API: Moneybag lists refunds, payouts and transaction history as merchant-portal features outside the public API. So:

- The driver does not implement `SupportsSync` or `SupportsRefunds`, and `bdpaygate:sync` skips it. Refund from the Moneybag merchant portal.
- If a customer pays but never comes back, find the transaction id in the merchant portal and settle the payment yourself:

  ```php
  BdPayGate::for($payment)->verifyTransaction($payment, 'txn…');
  ```

- If the customer cancels before a transaction exists, the return has no `transaction_id`. The payment stays pending, and the customer lands on your `pending` page.

## Limits and quirks

- Amount must be 10.00 to 1000000.00 BDT. Checked before the API call.
- `order_id` must be 10 to 36 characters; attempt ids are 20.
- `customer.email` is required by Moneybag; the driver falls back to `noemail@example.com`. Pass `$meta['postcode']` to send a postcode.
- Validation errors are HTTP 422 with `{"detail":[{"loc","msg","type"}]}`; they are included in the exception message.
- **Webhooks are not handled yet.** Moneybag signs webhooks (`X-Webhook-Signature: sha256=HMAC(secret, "{timestamp}.{raw body}")`), but the payment event body is not in the public contract, and webhook endpoints are configured in the merchant portal. The driver sends no `ipn_url`.
- Some outcomes are **unverified until checked against a sandbox**:
  - The non-terminal `status` values returned by verify. Only `SUCCESS`, `FAILED` and `CANCELLED` are documented.
  - Whether `amount` or `charge_amount` carries the order amount for payments where the customer pays a fee. The driver checks `amount`.

## Hosts (checked 2026-10-15)

Both `https://sandbox.api.moneybag.com.bd` (this driver's sandbox default) and `https://staging.api.moneybag.com.bd` (the official SDK's default) are live and require authentication. `https://api.moneybag.com.bd` is production. Override any of them with `base_url`. A merchant API key is issued from the Moneybag panel; there is no public demo key.

## Sandbox run (2026-10-15)

Verified against the real Moneybag sandbox through a Laravel 13 app with a self-serve sandbox account (developer portal → sandbox onboarding issues a merchant ID and a one-time-display API key). A 120 BDT payment paid with bKash completed after `GET /api/v2/payments/verify/{transaction_id}`; a simulated failure was confirmed as `FAILED` and marked failed. The verify response reports the order amount in `amount` and Moneybag's service charge separately in `charge_amount` (the customer saw 121.20), so the amount check correctly compares `amount`.
