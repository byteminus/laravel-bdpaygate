# ShurjoPay

**Status:** stable · **Driver:** `shurjopay` · **Refunds:** no API (refund from the merchant panel) · **Sync:** yes

ShurjoPay is a hosted checkout from a Bangladesh Bank licensed payment system operator. One integration covers:

- **Cards:** Visa, Mastercard, American Express, UnionPay, Nexus Pay, Diners.
- **Mobile wallets:** bKash, Nagad, **Rocket (DBBL)**, Upay, Tap, mCash, Pathao Pay, Pocket, Meghna Pay, CellFin and others ShurjoPay lists on its checkout.
- **EMI** on supported cards.

That makes it a way to accept Rocket and the smaller wallets that have no public merchant API of their own. The verification response's `method` field (stored in the payment's gateway log) tells you which one the customer used.

## Setup

```dotenv
SHURJOPAY_USERNAME=sp_sandbox
SHURJOPAY_PASSWORD=pyyk97hu&6u6
SHURJOPAY_PREFIX=sp
SHURJOPAY_SANDBOX=true
```

```php
// config/bdpaygate.php → gateways
'shurjopay' => [
    'driver' => 'shurjopay',
    'username' => env('SHURJOPAY_USERNAME'),
    'password' => env('SHURJOPAY_PASSWORD'),
    'prefix' => env('SHURJOPAY_PREFIX'),
    'sandbox' => env('SHURJOPAY_SANDBOX', true),
],
```

- `prefix` is the short order prefix (at most 5 characters) agreed with ShurjoPay. ShurjoPay builds its own order id (`sp_order_id`) from it.
- The values above are ShurjoPay's public sandbox credentials. Live credentials come from ShurjoPay during merchant onboarding. Sandbox tokens do not work on live.
- Hosts: `https://sandbox.shurjopayment.com` (sandbox) and `https://engine.shurjopayment.com` (live). Override with `base_url` (host only, without `/api`).
- Currency must be `BDT` or `USD`.

### IPN

ShurjoPay can also notify a server URL about payments whose browser return was lost. Ask ShurjoPay support to register:

```
https://your-app.com/bdpaygate/shurjopay/ipn
```

The IPN is optional: `bdpaygate:sync` settles payments whose return never arrived.

## How a payment is verified

1. `charge()` gets an access token (`/api/get_token`), then creates the payment (`/api/secret-pay`). The attempt's `gateway_reference` is sent as `order_id`, and your own reference goes in `value1`. ShurjoPay's `sp_order_id` is stored as `gateway_payment_id`.
2. ShurjoPay sends the customer back to `/bdpaygate/shurjopay/success` or `/cancel` with only `?order_id=<sp_order_id>`. The return carries no status and no signature. The IPN carries the same single field.
3. Whatever the URL, the package calls the Verification API (`/api/verification`) with the `sp_order_id`. It checks that the answer is about the same `sp_order_id` and `customer_order_id`, and that the amount and currency match. Only then does `sp_code` decide:

   | `sp_code` | Meaning | Result |
   |---|---|---|
   | `1000` | Paid | Completed (`bank_trx_id` stored as `gateway_transaction_id`) |
   | `1001` | Declined by the issuing bank | Failed |
   | `1002` | Cancelled by the customer | Cancelled |
   | `1068` | Initiated, not paid yet (undocumented) | Stays pending |
   | anything else | Unknown | Stays pending |

   A cancel return for a payment ShurjoPay reports as paid completes it. A cancel return for a payment that is still "initiated" leaves it pending, because anyone can open that URL.
4. If ShurjoPay cannot be reached, the payment stays pending and the customer goes to your `pending` page. `bdpaygate:sync` settles it later.

## Refunds

ShurjoPay has no public refund API. Refund from the ShurjoPay merchant panel or through ShurjoPay support. `BdPayGate::refund()` throws `UnsupportedOperationException` for this gateway.

## Limits and quirks

- **Tokens last 15 minutes.** The docs sample says `expires_in: 3600` "milliseconds", but the API returns `900` seconds. The token is cached for at most 14 minutes, shared across processes, together with the `store_id` the token call returns.
- **`1064 Unauthorized`** (undocumented) means the token expired or was revoked. The package fetches a new token and retries once. If ShurjoPay still refuses, the payment stays pending and the error is logged: check the username and password.
- **Errors come with HTTP 200.** Verification answers are a one-element JSON array, but errors are sometimes a bare object, and `secret-pay` validation errors are an object of field messages. All of these are handled.
- **`sp_code`** is a string in practice and an integer in the docs; both are accepted.
- **Verification only knows ShurjoPay's id.** Asking with your own `order_id` returns `1011`. Callbacks are therefore looked up by `gateway_payment_id`, and a payment whose `secret-pay` call timed out before returning an `sp_order_id` cannot be synced.
- **Amounts come back with four decimals** (`"150.0000"`). They are compared after rounding to two.
- **`value4` is overwritten by ShurjoPay** in sandbox, so the package does not use it.
- **Customer email** is optional at ShurjoPay; when the customer has none, a placeholder is sent.
- **Return URLs are GET requests**, and IPNs are POSTs. The package's callback routes accept both, without sessions or CSRF.

## Sandbox

Use the public sandbox credentials above with `SHURJOPAY_SANDBOX=true`. The sandbox checkout lives at `sandbox.securepay.shurjopayment.com`.
