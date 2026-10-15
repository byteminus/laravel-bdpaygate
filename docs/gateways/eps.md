# EPS (Easy Payment System)

**Status:** experimental · **Driver:** `eps` · **Refunds:** no (use the EPS merchant panel) · **Sync:** yes

EPS is a hosted checkout covering cards (Visa, Mastercard, Amex), internet banking and most wallets (bKash, Nagad, Rocket, OK Wallet and others).

> **Experimental.** EPS does not publish its API reference. This driver is built from the provider's published SDKs. Request the integration guide and sandbox credentials from EPS (integration@eps.com.bd), and verify the driver against the sandbox before going live.

## Setup

```dotenv
EPS_USERNAME=
EPS_PASSWORD=
EPS_HASH_KEY=
EPS_MERCHANT_ID=
EPS_STORE_ID=
EPS_SANDBOX=true
```

```php
// config/bdpaygate.php → gateways
'eps' => [
    'driver' => 'eps',
    'username' => env('EPS_USERNAME'),
    'password' => env('EPS_PASSWORD'),
    'hash_key' => env('EPS_HASH_KEY'),
    'merchant_id' => env('EPS_MERCHANT_ID'),
    'store_id' => env('EPS_STORE_ID'),
    'sandbox' => env('EPS_SANDBOX', true),

    // Optional:
    // 'device_type_id' => env('EPS_DEVICE_TYPE_ID'), // sent only if set
    // 'status_attempts' => 3,       // status API tries when EPS answers 404
    // 'status_retry_delay' => 1500, // milliseconds between those tries
    // 'ipn_window' => 120,          // minutes of pending payments an IPN re-checks
    // 'ipn_sweep_limit' => 10,      // at most this many per IPN
    // 'token_ttl' => 300,           // seconds, if EPS gives no token expiry
],
```

All five credentials come from EPS after merchant registration and KYC. There are no public test credentials.

| Environment | API host |
|---|---|
| Sandbox | `https://sandboxpgapi.eps.com.bd` |
| Live | `https://pgapi.eps.com.bd` |

## Capabilities

| | |
|---|---|
| Charge (hosted checkout) | yes |
| Callback verification | status API, every time |
| Sync (`BdPayGate::sync`, `bdpaygate:sync`) | yes |
| Refunds | no. EPS's refund policy says refunds can be requested "through the API or by email", but no refund endpoint has been published. |
| Currency | BDT only |

## How a payment is verified

1. `charge()` fetches a token (`/v1/Auth/GetToken`), caches it until it expires, and calls `/v1/EPSEngine/InitializeEPS`.
   - `merchantTransactionId` is this attempt's `gateway_reference`: 20 digits, new for every attempt.
   - `CustomerOrderId` is your reference plus a per-attempt suffix.
   - `ValueA` and `ValueB` carry the payment id and your reference.
   - EPS's `TransactionId` is stored in `gateway_payment_id`.
2. Every request carries `x-hash` = base64(HMAC-SHA512(hash key, value)). The signed value depends on the endpoint:
   - GetToken signs the username.
   - InitializeEPS signs the merchant transaction id.
   - CheckMerchantTransactionStatus signs the merchant transaction id.
3. EPS returns the customer with a GET to `/bdpaygate/eps/{success|fail|cancel}`, adding `?Status=…&MerchantTransactionId=…&EPSTransactionId=…`. The redirect is not signed, so the package ignores `Status` and calls `CheckMerchantTransactionStatus` to get the result.
4. A payment is completed only when the status API says it was paid **and** `TotalAmount` matches. EPS's transaction id goes into `gateway_payment_id` and `gateway_transaction_id`.
5. If the status API says failed or cancelled, the payment is marked that way. If it says pending, gives an unknown status, or has no answer, the payment stays pending. Use `bdpaygate:sync` to settle it later.

### The 404 after payment

Right after a payment, EPS's status API can answer 404 for a short time. The driver retries 404s (`status_attempts`, `status_retry_delay`). If it still gets 404 after that, the payment stays **pending**. It is never marked failed because of a 404.

### IPN

EPS's IPN body is encrypted with AES-256-CBC (`{"Data": "<iv>:<ciphertext>"}`), and the key format is not documented, so the driver does not decrypt it. It treats any POST to `/bdpaygate/eps/ipn` as a signal:

- If the body names the merchant transaction id in plain text, that payment is checked.
- Otherwise the driver checks this gateway's pending payments from the last `ipn_window` minutes, up to `ipn_sweep_limit` of them, against the status API.

An IPN body is never treated as proof of payment. Ask EPS support to register `https://your-app/bdpaygate/eps/ipn` as your IPN URL.

## Limits and quirks

- **Errors come back with HTTP 200.** For example, a bad login gives `token: null` and an `errorMessage`. The driver reads the body, not the status code.
- **Mixed key casing and literal `null`s.** Requests mix camelCase and PascalCase. Responses are read case-insensitively, and `null` or `""` counts as missing.
- **Escaped return parameters.** EPS can HTML-escape the separators in the return URL (`&amp;MerchantTransactionId=`). The driver strips the `amp;` prefix. The callback URLs it gives EPS have no query string.
- **The status API is GET.** POST was reported to return 405.
- **Rate limit.** EPS was seen to send `X-Rate-Limit-Limit: 40`. The time window is not known. Keep `ipn_sweep_limit` small.
- **Origin/IP checks.** EPS may only accept calls from your registered domain or IP. If your servers have no fixed IP, use the `proxy` setting.

## Unverified

These points come from EPS's SDKs and a production write-up, not from an official specification:

- The status vocabulary. The driver accepts `Success`, `Successful`, `Completed` as paid, `Failed` and `Failure` as failed, `Cancelled` and `Aborted` as cancelled. Anything else is pending.
- Whether `deviceTypeId` and `transactionDate` are required. `transactionDate` is always sent; `deviceTypeId` only when configured.
- Limits on the format and length of `merchantTransactionId`. EPS's SDKs use digits only, so the driver does too.
- How long a token lasts. The driver uses `expireDate` or the JWT `exp` claim, and falls back to `token_ttl`.
- How IPNs are registered, how often they are retried, and what reply EPS expects. EPS's IPN page asks for `{"status":"OK"}`, but the package answers `{"ok":true,...}`.
- Whether there is a refund API.
