# PayStation

**Status:** stable · **Driver:** `paystation` · **Refunds:** no API (refund from the merchant panel) · **Sync:** yes

PayStation (Service Hub Ltd) is a hosted checkout covering cards (Visa, Mastercard) and the bKash, Nagad, Rocket and Upay wallets.

"Stable-beta" means the request and response shapes below were checked against PayStation's documentation and its public sandbox, but not against a live merchant account. See [Not yet verified](#not-yet-verified).

## Setup

```dotenv
PAYSTATION_MERCHANT_ID=104-1234567890
PAYSTATION_PASSWORD=your-store-password
PAYSTATION_SANDBOX=true
```

```php
// config/bdpaygate.php → gateways
'paystation' => [
    'driver' => 'paystation',
    'merchant_id' => env('PAYSTATION_MERCHANT_ID'),
    'password' => env('PAYSTATION_PASSWORD'),
    'sandbox' => env('PAYSTATION_SANDBOX', true),
    // 'pay_with_charge' => true, // sent as pay_with_charge=1, see below
],
```

PayStation issues separate merchant IDs for sandbox and live. Register at <https://merchant.paystation.com.bd/registration>; the docs are at <https://paystation.com.bd/documentation>.

| Environment | API host |
|---|---|
| Sandbox | `https://sandbox.paystation.com.bd` |
| Live | `https://api.paystation.com.bd` |

## How a payment is verified

1. `charge()` calls `POST /initiate-payment` (form fields) with `merchantId`, `password`, this attempt's `gateway_reference` as `invoice_number`, the amount as `payment_amount` (for example `"150.00"`), and your own reference in `reference`. The package's payment id goes in `opt_a`.
2. PayStation answers with `status_code: "200"` and a `payment_url`. Any other `status_code` is an error, even though the HTTP status is 200 (for example `1008` "Duplicate invoice number.", `1001` "Invalid Credential."). The package throws `GatewayException` with PayStation's message and code.
3. The customer comes back to `/bdpaygate/paystation/callback?status=Successful|Failed|Canceled&invoice_number=…&trx_id=…`. These query parameters are unsigned, so the package ignores everything except `invoice_number`.
4. The package asks `POST /transaction-status` (header `merchantId`, body `invoice_number`) and acts on `data.trx_status`:

| `trx_status` (any casing) | Result |
|---|---|
| `success` | Completed, once `invoice_number` and the amount match. `trx_id` is stored as `gateway_transaction_id`. |
| `failed` | Failed, or Cancelled if the customer's callback said `Canceled`. |
| `processing` | Stays pending (the customer has not finished paying). |
| `refund` | Stays pending, and the response is logged for you to review. |
| any `status_code` other than `"200"`, or a non-JSON body | Stays pending. `bdpaygate:sync` asks again later. |

A Failed or Cancelled payment still becomes Completed if a later status check says `success`.

### Amount check

The package compares `request_amount` (or `payment_amount` when `request_amount` is missing) with the order amount. If `pay_with_charge` is off, `payment_amount` must also match exactly.

Turn `pay_with_charge` on only if your PayStation account adds the processing charge to what the customer pays. The amount actually paid is then allowed to be higher than the order amount.

## Refunds

PayStation documents no refund API, so this driver does not implement `SupportsRefunds`, and `BdPayGate::refund()` throws `UnsupportedOperationException`. Refund from the PayStation merchant panel.

## Limits and quirks

- Business errors come back as **HTTP 200** with a string `status_code`. The driver never treats HTTP 200 alone as success.
- `trx_status` casing is inconsistent: the field description says `success`, but the samples show `Success` and `Failed`. The driver lowercases and trims it before comparing.
- The status endpoint is authenticated by the `merchantId` header alone, with no password and no token. That is how PayStation documents it. Treat what it returns as non-secret.
- `payment_amount` is documented as an integer, but the sandbox accepts and echoes two decimal places, and the driver always sends two decimals.
- `cust_email` is mandatory. If the customer has no email, the driver sends `noemail@example.com`.
- PayStation's own PHP sample turns off TLS certificate checks. This package never does.
- The public docs site sits behind Cloudflare and may block automated fetches. Read it in a browser.

## Not yet verified

- **IPN / webhook.** PayStation's current documentation describes only the browser redirect to `callback_url`. Any server-to-server notification is configured per merchant and its payload is undocumented. If PayStation sends one to `/bdpaygate/paystation/ipn` with an `invoice_number`, it is verified through `/transaction-status` exactly like the redirect. Run `bdpaygate:sync` on a schedule either way:

  ```php
  Schedule::command('bdpaygate:sync')->everyTenMinutes();
  ```

- **`pay_with_charge` semantics.** The documentation says only "Will the merchant bear the payment charge or not". Confirm with PayStation which direction `1` means for your account.
- **Live behaviour.** The shapes come from the documentation and the sandbox, not from a live account.
- **"Invalid Token."** One documented error mentions a token, which suggests an older token-based API exists. This driver uses only the documented `merchantId` and password endpoints.
- **Status v2.** `POST /v2/transaction-status` (by PayStation `trxId`) exists but is not used. Lookups are always by our own `invoice_number`.

## Sandbox run (2026-10-15)

Verified against the real PayStation sandbox through a Laravel 13 app: a 120 BDT payment paid with bKash inside PayStation's checkout, confirmed by `POST /transaction-status`. The live sandbox reports `trx_status: "successful"`, not the documented `"success"`; the driver accepts both. No refund API and no separate IPN exist (checked against the current docs): the callback to `callback_url` is the only notification, and it is never trusted on its own.
