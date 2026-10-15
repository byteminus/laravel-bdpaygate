# PortPos (formerly PortWallet)

**Status:** beta (built from the public v2 docs, not yet run against a sandbox) · **Driver:** `portpos` · **Refunds:** full and partial, up to 5 per invoice (asynchronous) · **Sync:** yes

PortPos is Portonics' hosted invoice checkout: local and international cards, mobile wallets, net banking and EMI. **PortWallet rebranded to PortPos**: `portwallet.com` and `developer.portwallet.com` redirect to `portpos.com`, and the old API hosts (`api.portwallet.com`, `api-sandbox.portwallet.com`) no longer work. This driver only talks to `*.portpos.com`. Many older SDKs and plugins still hard-code the dead hosts.

## Setup

```dotenv
PORTPOS_APP_KEY=your-app-key
PORTPOS_SECRET_KEY=your-secret-key
PORTPOS_SANDBOX=true
```

```php
// config/bdpaygate.php → gateways
'portpos' => [
    'driver' => 'portpos',
    'app_key' => env('PORTPOS_APP_KEY'),
    'secret_key' => env('PORTPOS_SECRET_KEY'),
    'sandbox' => env('PORTPOS_SANDBOX', true),
    // 'validity' => 3600,   // optional: invoice lifetime in seconds (60 to 31536000)
    // 'zipcode' => '1000',  // optional: billing zipcode used when $meta['zipcode'] is not given
],
```

| | Sandbox | Live |
|---|---|---|
| API | `https://api-sandbox.portpos.com/payment/v2/` | `https://api.portpos.com/payment/v2/` |
| Payment page | `https://ppv2.portpos.com/v2/invoice/{invoice_id}` | `https://payment.portpos.com/v2/invoice/{invoice_id}` |
| Merchant panel | `merchant-sandbox.portpos.com` | `merchant.portpos.com` |

App keys are generated in the PortPos merchant panel, and sandbox and live keys can be active at the same time. It is not clear whether the sandbox panel lets you sign up yourself; ask PortPos for a sandbox merchant account if it does not. Docs: <https://developer.portpos.com/documentation-v2.php>.

PortPos only accepts TLS 1.2. The auth token embeds the current Unix time, so keep the server clock NTP-synced.

## How a payment is verified

1. `charge()` creates an invoice (`POST /payment/v2/invoice`) with this attempt's `gateway_reference` as `order.reference`. The invoice id is stored in `gateway_payment_id`, and the customer is sent to the `action.url` PortPos returns.
2. Every call is authenticated with `Authorization: Bearer base64(app_key:md5(secret_key . unix_time))`, built fresh for each request.
3. PortPos sends the customer back to `/bdpaygate/portpos/callback?ref=…` and posts an IPN (`invoice`, `amount`, `status`, `reference`) to `/bdpaygate/portpos/ipn?ref=…`, for successes **and** failures. Neither is signed.
4. Every return, IPN and sync is confirmed with the IPN validate API, `GET /payment/v2/invoice/ipn/{invoice_id}/{amount}`. It is always called with the invoice id and amount **stored on the payment**, never the ones in the request. The returned `order.amount` and `order.currency` are checked as well.
5. The returned `order.status` decides the outcome:
   - `ACCEPTED`, `REFUND_PENDING` or `REFUND_PROCESSING` → completed. This holds even if the request claimed a failure.
   - `CANCELLED` → cancelled.
   - `EXPIRED` → failed.
   - `PENDING`, any other value, or an error answer → stays pending. A failed card attempt usually leaves the invoice payable.
6. PortPos has no transaction id. The processor's `billing.gateway.approve_code` is stored in `gateway_transaction_id`.

## Refunds

```php
$result = BdPayGate::refund($payment, 500);   // partial; null refunds the rest

$result->accepted; // true: PortPos approved the request
$result->pending;  // true: processed asynchronously
$result->refundId; // null: PortPos returns no refund id

BdPayGate::for($payment)->refundStatus($payment); // the invoice state: "refund_pending", "refund_processing", "accepted", ...
```

PortPos allows up to 5 refunds per invoice, and in total no more than the invoice amount. The v2 API takes no refund reason, so `$reason` is not sent. There is no per-refund status endpoint; `refundStatus()` reads the invoice's `order.status`.

## Limits and quirks

- Required customer fields, as PortPos validates them:
  - **Email.** The driver falls back to `noemail@example.com` when the customer has none.
  - **Phone**, 9 to 15 characters.
  - **Street.** The customer's address, or the city when there is none.
  - **City.**
  - **State.** `$meta['state']`, or the city.
  - **Zipcode**, 3 to 8 characters. `$meta['zipcode']`, else the config `zipcode`, else `1000`.
  - **Country**, as an ISO 3166 alpha-2 or alpha-3 code. `Bangladesh` is sent as `BD`. Any other country must already be given as a code, otherwise `charge()` throws before calling PortPos.
- Product name and description must be at least 3 characters; the driver uses `Order {reference}` when your description is shorter.
- `result` is `success` on reads and `SUCCESS` on refunds. Errors come back as `{"result":"ERROR","error":{"cause","explanation","property"}}` with a 4xx status, and the explanation is used in exception messages.
- Several things are **unverified until checked against a sandbox**:
  - The full `order.status` vocabulary. The docs list PENDING, CANCELLED, EXPIRED, ACCEPTED, REFUND_PENDING and REFUND_PROCESSING.
  - What the IPN validate API returns when the amount does not match.
  - Whether the path amount must be `150.00` or `150`. The driver sends two decimals.
  - The query parameters PortPos adds to the return URL. The driver needs none of them.
