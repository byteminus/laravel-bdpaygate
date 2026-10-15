# Upay

**Status:** beta · **Driver:** `upay` · **Refunds:** full amount only, may be asynchronous · **Sync:** yes

> **Beta.** This driver was built from public information and has not yet been verified against an Upay merchant sandbox. Get the official API document from Upay during onboarding and check the behaviour described here against it. Please report anything that differs.

Upay is the mobile financial service of UCB Fintech. Its merchant payment gateway is a hosted checkout where the customer pays with their Upay wallet. Access is not self-serve: Upay issues the API document and credentials during merchant onboarding.

## Setup

```dotenv
UPAY_MERCHANT_ID=
UPAY_MERCHANT_KEY=
UPAY_MERCHANT_CODE=
UPAY_MERCHANT_NAME=
UPAY_MERCHANT_MOBILE=
UPAY_MERCHANT_CITY=Dhaka
UPAY_MERCHANT_CATEGORY_CODE=
UPAY_SANDBOX=true
UPAY_BASE_URL=
UPAY_PROXY=
```

```php
// config/bdpaygate.php → gateways
'upay' => [
    'driver' => 'upay',
    'merchant_id' => env('UPAY_MERCHANT_ID'),
    'merchant_key' => env('UPAY_MERCHANT_KEY'),
    'merchant_code' => env('UPAY_MERCHANT_CODE'),
    'merchant_name' => env('UPAY_MERCHANT_NAME'),
    'merchant_mobile' => env('UPAY_MERCHANT_MOBILE'),
    'merchant_city' => env('UPAY_MERCHANT_CITY', 'Dhaka'),
    'merchant_category_code' => env('UPAY_MERCHANT_CATEGORY_CODE'),
    'sandbox' => env('UPAY_SANDBOX', true),
    'base_url' => env('UPAY_BASE_URL'),
    'proxy' => env('UPAY_PROXY'),          // fixed, whitelisted egress IP
    // 'merchant_country_code' => 'BD',
    // 'token_ttl' => 600,                 // seconds to cache the auth token
],
```

- All of these come from Upay. The merchant code and category code may arrive only with the live credentials. Every merchant field is sent with each payment.
- Hosts: `https://uat-pg.upay.systems` (UAT) and `https://pg.upaysystem.com` (live). The live host is not stated in Upay's document; confirm it with Upay and override with `base_url` if it differs.
- Currency must be `BDT`.

## How a payment is verified

1. The driver gets a token from `POST /payment/merchant-auth/` with your merchant id and key, and sends it as `Authorization: UPAY {token}` on every later call. Upay does not document an expiry, so the token is cached for `token_ttl` seconds (10 minutes by default). A 401 or 403 drops the cached token and retries once with a fresh one.
2. `charge()` calls `POST /payment/merchant-payment-init/`. The attempt's `gateway_reference` is sent as **both** `txn_id` and `invoice_id`, because the redirect only carries `invoice_id` and the status API only takes `txn_id`. Your own reference goes in `additional_info.reference`. Upay's `trx_id` is stored as `gateway_payment_id`, and the customer is sent to `gateway_url`.
3. Upay redirects the customer's browser to `/bdpaygate/upay/callback` with `status` and `invoice_id`. **This is not signed.** The package only uses it to find the payment.
4. The package calls `GET /payment/single-payment-status/{txn_id}/`. The answer must carry the same `txn_id`. Then `status` decides:

   | `status` | Result |
   |---|---|
   | `success` | Completed, once the amount matches (`trx_id` stored as `gateway_transaction_id`) |
   | `failed`, `expired` | Failed |
   | `cancelled` | Cancelled |
   | `pending`, anything else | Stays pending |
   | Not found (`PNF4012`) or an error | Stays pending |

   A failure redirect for a payment Upay reports as paid completes it.
5. If Upay cannot be reached, the payment stays pending and the customer goes to your `pending` page. `bdpaygate:sync` settles it later.

## Refunds

```php
$result = BdPayGate::refund($payment);   // full amount only

$result->accepted; // Upay took it
$result->pending;  // true when Upay answered "under process"
```

- Refunds go through Upay's bulk refund endpoint (`POST /payment/bulk/refund/`) with one entry: the payment's `txn_id` and the amount as a string.
- A `txn_id` in the answer's success list is an accepted refund.
- A failure entry reading "Your request is under process" means Upay took the request and will settle it later. It is reported as accepted with `pending: true`, and counts against the refundable amount. Upay documents no refund status endpoint, so confirm the outcome in the merchant panel.
- Any other failure entry (for example "Invalid amount" or "already refunded") is reported as not accepted, with Upay's English message in `$result->message`.
- **Full refunds only.** The API takes a per-transaction amount, but partial refunds are not publicly confirmed, so `BdPayGate::refund($payment, '50.00')` throws `UnsupportedOperationException`. Make partial refunds from the merchant panel.
- If the refund call times out, nothing is recorded. Check the merchant panel before you retry.

## Limits and quirks

- **IP whitelisting.** Upay's live gateway is IPv4 only and expects calls from whitelisted server IPs. If your app servers have no fixed IP, set `proxy` (or the global `BDPAYGATE_PROXY`) to a proxy with a fixed, whitelisted IPv4 egress address.
- **Trailing slashes.** Every Upay path ends in `/`. Without it, the server may redirect and drop the POST body.
- **The redirect URL format.** Upay may append `&status=…&invoice_id=…` to the redirect URL instead of starting a query string. The package's redirect URL already carries a query parameter (`?txn=<gateway_reference>`), so the appended values still parse, and the callback also handles a second `?`. If `invoice_id` is missing, `txn` identifies the payment. Either way, the status API decides.
- **Duplicate `txn_id`s are rejected** (`DTP4003`). Each attempt gets a fresh 20-character id, so a retry never reuses one.
- **Bangladesh time.** The init `date` is today's date in Asia/Dhaka, whatever your app timezone is.
- **The token carries your merchant key** in readable form. The driver never logs auth responses. Keep outbound request logging away from it too.
- **Amounts** come back as JSON numbers (`150.0`) and are compared numerically.
