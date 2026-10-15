# bKash

**Status:** stable · **Driver:** `bkash` · **Refunds:** full and partial (up to 10 per transaction) · **Sync:** yes

bKash Tokenized Checkout, URL based, without agreement: the customer is sent to bKash's page, enters wallet number, OTP and PIN, and comes back to your site. The driver speaks **Tokenized V2** by default, which has been bKash's default documentation since 2026-08-30. Merchants still provisioned on **v1.2.0-beta** set `'version' => 'v1.2'`.

## Setup

```dotenv
BKASH_APP_KEY=your-app-key
BKASH_APP_SECRET=your-app-secret
BKASH_USERNAME=your-username
BKASH_PASSWORD=your-password
BKASH_SANDBOX=true
BKASH_VERSION=v2
```

```php
// config/bdpaygate.php → gateways
'bkash' => [
    'driver' => 'bkash',
    'app_key' => env('BKASH_APP_KEY'),
    'app_secret' => env('BKASH_APP_SECRET'),
    'username' => env('BKASH_USERNAME'),
    'password' => env('BKASH_PASSWORD'),
    'sandbox' => env('BKASH_SANDBOX', true),
    'version' => env('BKASH_VERSION', 'v2'), // "v2" or "v1.2" (v1.2.0-beta)
    'mode' => '0011',                        // v1.2 only: URL-based checkout
    'verify_signature' => true,              // compare the callback signature with the one bKash issued
    'execute_on_sync' => true,               // sync() executes a verified payment whose callback was lost
],
```

Hosts are `https://tokenized.sandbox.bka.sh` (sandbox) and `https://tokenized.pay.bka.sh` (live). `base_url` overrides the **host only**: the driver adds `/v2/tokenized-checkout/...` or `/v1.2.0-beta/tokenized/checkout/...` itself.

Sandbox credentials are issued by bKash (developer.bka.sh). Live credentials come with merchant onboarding.

### Which version am I on?

| | V2 (default) | v1.2.0-beta |
|---|---|---|
| Paths | `/v2/tokenized-checkout/auth/grant-token`, `/auth/refresh-token`, `/payment/create`, `/payment/execute`, `/query/payment` | `/v1.2.0-beta/tokenized/checkout/token/grant`, `/token/refresh`, `/create`, `/execute`, `/payment/status` |
| Field casing | `paymentId`, `trxId` | `paymentID`, `trxID` |
| Success body | no `statusCode` | `statusCode: "0000"` |
| Errors | `externalCode`, `errorMessageEn` | `statusCode`/`statusMessage` or `errorCode`/`errorMessage` |
| `mode` on create | not sent | `0011` |
| Refunds | `/v2/tokenized-checkout/refund/payment/transaction` | the same V2 endpoint, as bKash's v1.2.0-beta refund pages document |

The driver reads both shapes, so switching is a config change. Payments created under one version must be executed and queried under the same version.

## How a payment is verified

1. `charge()` calls Create Payment with this attempt's `gateway_reference` as `merchantInvoiceNumber`, the customer's phone (or your reference) as `payerReference`, and `callbackURL` = `/bdpaygate/bkash/callback`. bKash's `paymentId` is stored in `gateway_payment_id`; the customer goes to `checkout_url` (`bkashURL`).
2. bKash sends the customer back to `/bdpaygate/bkash/callback?paymentID=…&status=success|failure|cancel&signature=…`.
3. The `signature` must match the one bKash put in that outcome's callback URL in the Create Payment response. A mismatch is rejected before any API call.
4. **success** means "execute now". The driver calls Execute Payment once, which is the step that moves the money:
   - `Completed` with the right `paymentId`, amount and currency: the payment completes and `trxId` goes to `gateway_transaction_id`.
   - Any error, or a timeout: the driver asks Query Payment. `Completed` completes the payment. A definite decline (for example `2023` Insufficient Balance, `2029` Duplicate for all transactions, wrong PIN, invalid wallet) marks it **Failed**, with the code in `failure_reason` (`"2023: Insufficient Balance"`). Anything ambiguous (`2056` Invalid Payment State, `2003`, `503`, HTTP 5xx, a timeout) leaves it **Pending**.
   - Completed for a different amount: not completed, `requires_review` is set, and the response is logged. Look at it by hand.
5. **failure** and **cancel** are claims. The driver asks Query Payment first. Only if bKash does not report `Completed` does the payment become Failed or Cancelled. Both can still become Completed later if bKash confirms payment.
6. A second success callback (refresh, back button, concurrent request) never executes again. bKash forbids reusing a paymentID after any execute attempt, so the driver queries instead.

### sync()

`BdPayGate::sync($payment)` (and `bdpaygate:sync`) calls Query Payment:

- `Completed`: completes.
- `Failed` / `Declined` / `Expired`: Failed. `Cancelled`: Cancelled.
- `Initiated`: stays pending. Once the paymentID is more than 24 hours old it can never complete, so it is marked Failed with `failure_reason` "Expired".
- `Initiated` with `verificationStatus: Complete`, never executed, and under 24 hours old: the customer authorised but the callback was lost. The driver executes it (turn off with `execute_on_sync => false`).

## Refunds

```php
$result = BdPayGate::refund($payment, 500, 'Damaged item');

$result->accepted; // true: bKash refunded it
$result->refundId; // refundTrxId
$result->message;  // "Completed", or "2072: Refund amount not valid" on rejection

BdPayGate::for($payment)->refundStatus($payment);
// ['original_trx_id' => 'DIK80PG0SO', 'refunds' => [['refund_trx_id' => …, 'status' => 'Completed', 'amount' => '10.00', 'completed_at' => …]], 'raw' => [...]]
```

- Sends `paymentId`, `trxId`, `refundAmount`, `sku` (your reference) and `reason` to the V2 refund API.
- Up to 10 refunds per transaction, within 60 days. The driver refuses an 11th.
- **Timeouts.** If the refund call times out, the result is `accepted: true, pending: true` and it counts against the refundable amount, so it isn't sent twice. bKash also rejects a duplicate within 10 minutes. Call `refundStatus()` to see whether it landed before refunding again.
- **Coupons.** When a coupon was applied, bKash's `maxRefundableAmount` is lower than the payment amount (amount 20.00 with a 4.00 coupon refunds at most 16.00). The driver reads it from the execute/query response and refuses a refund above it, rather than letting bKash answer `2072`.

## Tokens

- Grant Token is called once and the `id_token` is cached (locked, so concurrent requests don't each grant) for about 50 minutes of its 60.
- After that the stored `refresh_token` (valid 30 days on V2, 28 on v1.2.0-beta) is used. A new grant happens only when there is no refresh token or bKash refuses it.
- **bKash allows two grant/refresh calls per hour. A third blocks the merchant for an hour.** The driver counts them and refuses a third locally with `GatewayUnavailableException`. Callbacks then land on your pending page and sync settles them later.
- When bKash rejects a token (HTTP 401/403, `2079` Invalid app Token), the driver drops it, gets a fresh one and retries the call **once**.
- Tokens live in your cache store. On several servers, use a shared cache (Redis, database). With `file` or `array` caches, each server grants its own tokens and can hit the hourly limit. Avoid `cache:clear` on deploy for the same reason.

## Limits and quirks

- BDT only. `charge()` refuses other currencies before calling bKash.
- A paymentID is valid for 24 hours and can be executed **once**, whatever the result.
- A customer authorising on bKash's page moves no money. Only Execute does. Pending payments are recovered by `sync()` within those 24 hours.
- `payerReference`, `merchantInvoiceNumber`, `sku` and `reason` may not contain `<`, `>` or `&` and are at most 255 characters. The driver strips and trims them.
- `2029` (Duplicate for all transactions): the same wallet paying the same amount to you again within a few minutes is refused at execute. It is recorded as Failed with the code.
- Webhooks (AWS SNS signed) are configured with bKash support and are not handled by this driver. Use `bdpaygate:sync` on a schedule.

## Sandbox notes

- Keep `BKASH_SANDBOX=true` with sandbox credentials. Sandbox and live tokens are cached separately.
- The token rules apply in the sandbox too. Parallel test runs against the real sandbox can use up the two calls per hour.
- To see an ambiguous result, open the callback URL with `status=success` before finishing on bKash's page. Execute then fails (typically `2056` Invalid Payment State), Query says `Initiated`, and the payment stays pending.
- Automated tests don't need the sandbox: fake the HTTP calls (see `tests/Conformance/BkashScenario.php` for V2 response shapes).

## Not verified against a live account

These points come from bKash's documentation but haven't been tested against a live account. The driver accepts every documented variant:

- V2 Query Payment documents only `Initiated` and `Completed`. `Failed`, `Declined`, `Cancelled` and `Expired` are handled in case they appear.
- Its sample uses `verificationStatus`; v1.2.0-beta's table calls it `userVerificationStatus`. Both are read.
- The shape of an expired-token rejection isn't documented. The driver treats HTTP 401/403, `2079`, or a bare `message` mentioning "Unauthorized" or "token" as one.
- V2 grant and refresh responses carry `statusCode: "0000"` like v1.2.0-beta. A token response is accepted whenever it has an `id_token` and no error code.
- The callback `signature` is documented only for v1.2.0-beta. If your V2 Create Payment response carries no signature, the check is skipped.
- Refund Status takes `trxId`, which the V2 page describes ambiguously. The driver sends the original payment's `trxId`.

## Sandbox run (2026-10-15)

Verified against the real bKash sandbox through a Laravel 13 app: token grant on V2 and v1.2, create, execute after wallet/OTP/PIN, two partial refunds through the V2 refund API, and refund status. A replayed success callback and a forged cancel were both rejected without changing the payment. Refund Status can take a few seconds to list a refund that was just made.
