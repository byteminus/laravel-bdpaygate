# Changelog

## Unreleased

### Gateways
- bKash Tokenized Checkout V2 (v1.2.0-beta still supported): refresh tokens, a guard against the 2-grants-per-hour lockout, callback signature check, execute-once, partial refunds (up to 10) and refund status, sync that recovers lost callbacks.
- SSLCommerz: currency checks for foreign-currency payments, `risk_level` review flag, mandatory `refund_trans_id`, refund status.
- New: Nagad, Upay, ShurjoPay, aamarPay, PortPos, PayStation, UddoktaPay, Moneybag (beta/stable, see README), EPS and DGePay (experimental).

### Core
- Every callback (success, fail, cancel, IPN) is confirmed with the gateway before the payment changes state.
- Atomic status transitions: events fire exactly once under concurrent callbacks.
- Failed and cancelled payments still complete when the gateway later confirms payment.
- Timeouts leave payments pending (`GatewayUnavailableException`); `bdpaygate:sync` settles them.
- One row per attempt with a fresh `gateway_reference`; `PaymentDuplicated` when two attempts for one reference are paid.
- Optional capabilities: `SupportsSync`, `SupportsRefunds`, `SupportsPartialRefunds`; `BdPayGate::refund()` and `BdPayGate::sync()` route by the payment's gateway.
- Gateway name is the config key, so several stores of one gateway work side by side.
- Callback routes run without sessions or CSRF; malformed callbacks are rejected without a 500.
- `requires_review` and `failure_reason` columns; `Customer` gains `postcode` and `state`.
- Outbound `proxy` and `timeout` settings, per gateway or global.
- Conformance suite every gateway must pass.
- Laravel 13 and PHP 8.5 support.
- Package renamed to `byteminus/laravel-bdpaygate`, namespace `ByteMinus\BdPayGate`.
