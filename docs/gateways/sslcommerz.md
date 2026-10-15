# SSLCommerz

**Status:** stable · **Driver:** `sslcommerz` · **Refunds:** full and partial (asynchronous) · **Sync:** yes

SSLCommerz is a hosted checkout covering cards, internet banking and most mobile wallets. It is also how you accept wallets that have no public merchant API of their own: **Rocket (DBBL)**, **OK Wallet** and **IBBL / CellFin** are offered on the SSLCommerz checkout page.

## Setup

```dotenv
SSLCOMMERZ_STORE_ID=yourstore
SSLCOMMERZ_STORE_PASSWORD=yourstore@ssl
SSLCOMMERZ_SANDBOX=true
```

```php
// config/bdpaygate.php → gateways
'sslcommerz' => [
    'driver' => 'sslcommerz',
    'store_id' => env('SSLCOMMERZ_STORE_ID'),
    'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
    'sandbox' => env('SSLCOMMERZ_SANDBOX', true),
],
```

Sandbox stores are self-serve at <https://developer.sslcommerz.com/registration/>. Live refunds need your server's public IP registered with SSLCommerz.

## How a payment is verified

1. `charge()` creates a session (`/gwprocess/v4/api.php`) with this attempt's `gateway_reference` as `tran_id`, and your own reference in `value_b`.
2. SSLCommerz posts the IPN and redirects the customer (`/bdpaygate/sslcommerz/{success|fail|cancel|ipn}`).
3. A success is accepted only if the `verify_sign` signature matches **and** the Order Validation API confirms the `val_id`, the `tran_id` and the amount. For foreign-currency payments, `currency_type` and `currency_amount` are checked rather than the converted `amount`.
4. A fail or cancel is a claim anyone could send: the package asks the Transaction Query API first, and completes the payment if SSLCommerz says it was paid.
5. `risk_level = 1` completes the payment but sets `$payment->requires_review = true`. SSLCommerz asks you to hold the service and verify the customer before delivering.

## Refunds

```php
$result = BdPayGate::refund($payment, 500, 'Damaged item');

$result->accepted; // true: SSLCommerz initiated it
$result->pending;  // true: the bank settles it later
$result->refundId; // refund_ref_id

BdPayGate::for($payment)->refundStatus($result->refundId); // "refunded", "processing" or "cancelled"
```

A `processing` answer means that refund was already initiated, so it is not recorded a second time.

## Limits and quirks

- Amount must be 10.00 to 500000.00 BDT. Checked before the API call.
- `tran_id` is at most 30 characters; attempt ids are 20.
- The return to your site is a cross-site POST. The package's callback routes run without sessions, so customers are not logged out; your landing page receives a normal GET.
