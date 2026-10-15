# Nagad

**Status:** beta · **Driver:** `nagad` · **Refunds:** no API (refund from the merchant portal) · **Sync:** yes

> **Beta.** This driver was built from public information and has not yet been verified against a Nagad merchant sandbox. Get the official integration guide from Nagad during onboarding and check the behaviour described here against it. Please report anything that differs.

Nagad's Online Payment API is a hosted checkout. The customer pays with their Nagad wallet (number, OTP, PIN) on Nagad's page. Access is not self-serve: you need a Nagad merchant account, and Nagad's key account team issues sandbox credentials by email.

**Regulatory note.** Nagad is operating, but as of 2026 it runs under an interim Bangladesh Bank approval and a Bangladesh Bank appointed administrator. Its licence status after that interim period was not publicly confirmed when this driver was written. That is a business-continuity risk rather than a technical one. If Nagad matters to your checkout, consider an aggregator (ShurjoPay, aamarPay) as a fallback.

## Setup

```dotenv
NAGAD_MERCHANT_ID=
NAGAD_MERCHANT_NUMBER=
NAGAD_PUBLIC_KEY=
NAGAD_PRIVATE_KEY=
NAGAD_SANDBOX=true
NAGAD_BASE_URL=
NAGAD_SIGNATURE_ALGORITHM=sha256
NAGAD_IP=
NAGAD_PROXY=
```

```php
// config/bdpaygate.php → gateways
'nagad' => [
    'driver' => 'nagad',
    'merchant_id' => env('NAGAD_MERCHANT_ID'),
    'merchant_number' => env('NAGAD_MERCHANT_NUMBER'), // optional, your 11-digit merchant wallet
    'public_key' => env('NAGAD_PUBLIC_KEY'),           // Nagad's payment gateway public key
    'private_key' => env('NAGAD_PRIVATE_KEY'),         // your merchant private key
    'sandbox' => env('NAGAD_SANDBOX', true),
    'base_url' => env('NAGAD_BASE_URL'),               // use the exact URL from your onboarding email
    'signature_algorithm' => env('NAGAD_SIGNATURE_ALGORITHM', 'sha256'),
    'verify_response_signature' => env('NAGAD_VERIFY_RESPONSE_SIGNATURE', false),
    'ip' => env('NAGAD_IP'),                           // fallback for the X-KM-IP-V4 header
    'proxy' => env('NAGAD_PROXY'),                     // fixed, whitelisted egress IP
    // 'api_version' => 'v-0.2.0',
    // 'client_type' => 'PC_WEB',  // PC_WEB, MOBILE_WEB, MOBILE_APP, WALLET_WEB_VIEW
    // 'locale' => 'EN',           // EN or BN
],
```

### Keys

There are two different keys, and mixing them up is the most common Nagad integration problem:

| Key | Config | Used for |
|---|---|---|
| Nagad's payment gateway **public** key | `public_key` | Encrypting what you send, and checking Nagad's signatures |
| Your merchant **private** key | `private_key` | Signing what you send, and decrypting Nagad's answers |

- In sandbox, Nagad emails you both. In live, you generate your key pair in the Nagad merchant portal and upload the public half there. Every "generate" creates a new pair and invalidates the old one.
- Keys can be given as PEM text (PKCS#1 or PKCS#8), as a bare base64 body with the header lines removed, or as an absolute path to a PEM file. PEM text in `.env` with literal `\n` sequences works too.
- Never commit keys. The tests generate throwaway pairs on the fly.

### Hosts

The sandbox address differs between sources, so set `base_url` to whatever your onboarding email says, including the path up to `/api/dfs`.

- Default sandbox: `https://sandbox-ssl.mynagad.com/remote-payment-gateway-1.0/api/dfs`. Other addresses seen in the wild include `http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs` and `http://sandbox.mynagad.com:30001/api/dfs`.
- Default live: `https://api.mynagad.com/api/dfs`.

### Signature algorithm

The algorithm used to sign requests is configurable because public sources disagree. Integrations that work in production sign with RSA-SHA256, and a signature error (`16_0006_058`) is commonly fixed by switching to it, so `sha256` is the default. Set `signature_algorithm` to `sha1` only if Nagad tells you to. `SHA256withRSA` and `SHA1withRSA` are accepted as aliases.

`verify_response_signature` checks the signature Nagad puts on its encrypted answers, using the same algorithm. It is off by default because the algorithm Nagad uses for responses is not publicly confirmed. Turn it on once you have confirmed it in the sandbox.

### Callback URL

The package sends its callback URL with every order:

```
https://your-app.com/bdpaygate/nagad/callback
```

In live, Nagad also asks for the callback URL in the merchant portal and whitelists the domain. Register this URL there.

## How a payment is verified

1. `charge()` calls **initialize** (`POST /check-out/initialize/{merchantId}/{orderId}`). It sends `merchantId`, `datetime`, `orderId` and a random 40-character `challenge` as JSON encrypted with Nagad's public key and signed with your private key. Nagad's answer is decrypted with your private key and gives a `paymentReferenceId`. That id is saved as `gateway_payment_id` **before** the next step, because Nagad's verify API only works by this id.
2. `charge()` then calls **complete** (`POST /check-out/complete/{paymentReferenceId}`) with the encrypted `merchantId`, `orderId`, `amount`, `currencyCode` (`050`) and the challenge, plus `merchantCallbackURL` and `additionalMerchantInfo` (your own reference is stored there as `reference`; add more with `$meta['additional_merchant_info']`). Nagad answers with the checkout URL, stored as `checkout_url`.
3. After paying, Nagad redirects the customer's browser to `/bdpaygate/nagad/callback` with `order_id`, `payment_ref_id`, `status`, `status_code` and more. **None of this is signed.** The package only uses `order_id` to find the payment.
4. The package calls **verify** (`GET /verify/payment/{paymentRefId}`) with the `paymentReferenceId` it stored, never one from the callback. The answer must carry the same `orderId` and `merchantId`. Then `status` decides:

   | `status` | Result |
   |---|---|
   | `Success` | Completed, once the amount matches (`issuerPaymentRefNo` stored as `gateway_transaction_id`) |
   | `Cancelled`, `Aborted` | Cancelled |
   | `Failed`, `Fraud`, `InvalidRequest`, `UnKnownFailed` | Failed |
   | `OrderInitiated`, `Ready`, `InProgress`, `OtpSent`, `OtpVerified`, `PinGiven`, `PartialCancelled`, anything else | Stays pending |
   | HTTP 404 with reason `16_0004_999` (no such payment yet) | Stays pending |

   `status` is used rather than `statusCode`, whose format varies. A failure callback for a payment Nagad reports as paid completes it.
5. If Nagad cannot be reached, the payment stays pending and the customer goes to your `pending` page.

Nagad returns `amount` as a string with no fixed format (`"130"`, `"130.00"`). It is compared numerically.

The callback is a browser redirect, so it is lost when the customer closes the tab. Schedule `bdpaygate:sync` to settle those payments.

## Refunds

Nagad publishes no refund API. Refund from the Nagad merchant portal or through Nagad. `BdPayGate::refund()` throws `UnsupportedOperationException` for this gateway. If Nagad gives you a refund specification during onboarding, please open an issue.

## Limits and quirks

- **IP whitelisting.** In live, Nagad only accepts calls from server IPs it has whitelisted, which especially matters for servers outside Bangladesh. A rejected call typically returns an HTML page saying "The requested URL was rejected". If your app servers have no fixed IP, set `proxy` (or the global `BDPAYGATE_PROXY`) to a proxy with a fixed, whitelisted egress IP.
- **Bangladesh time.** Request timestamps are sent in Asia/Dhaka time (`yyyyMMddHHmmss`) whatever your app timezone is. Nagad rejects timestamps outside a short window (`16_0006_076`), so keep the server clock synced with NTP.
- **Order ids.** Nagad accepts 5 to 20 letters and digits, and rejects a reused id on the same day (`16_0006_083`). Each attempt gets a fresh 20-character uppercase id (`gateway_reference`), so a retry never reuses one. The driver refuses to send an id outside those rules.
- **`X-KM-IP-V4`** must be the customer's IPv4 address. The driver sends the request IP or `$meta['customer_ip']` when it is a public IPv4 address. Otherwise (IPv6, private addresses, local development, queued jobs and `bdpaygate:sync`) it sends `ip` from the config, so set that to your server's public IPv4.
- **Small encrypted payload.** RSA-2048 can encrypt at most 245 bytes, so the encrypted JSON holds only the fields Nagad requires.
- **Currency** must be `BDT`.
- **Error envelope.** Errors come as `{"reason": "16_0006_xxx", "message": "..."}`. Both are included in the exception message, and the body is logged on the payment.
