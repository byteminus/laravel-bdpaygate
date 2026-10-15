# Contributing

Issues and pull requests are welcome. The ground rules are what make this package worth using:

- **No test hits a real gateway.** Every test uses `Http::fake()`.
- **Never trust a callback.** Redirects, IPNs and webhooks are claims. A driver confirms the payment with the gateway's own API, checks the amount, and only then changes state.
- **Uncertain means pending.** A timeout or an ambiguous answer leaves the payment pending; `bdpaygate:sync` settles it later. Only a definite answer from the gateway marks a payment failed.
- Run `composer check` (Pint, PHPStan, Pest) before opening a PR.

## Adding a gateway

1. Write `src/Drivers/YourGateway.php`: extend `AbstractGateway`, set `public const DRIVER`, implement `charge()` and `handleCallback()`. Add `SupportsSync` if the gateway has a status API, and `SupportsRefunds` (with the `HandlesRefunds` trait) or `SupportsPartialRefunds` if it has a refund API.
2. Use the base class: `send()` and `decode()` for HTTP, `createPendingPayment()`, `findByGatewayReference()`, `assertAmountMatches()`, `transition()` to change state, `stillPending()` when the gateway can't confirm, `rememberToken()` for access tokens.
3. Send `$payment->gateway_reference` to the gateway as the merchant order id. It is unique per attempt; `$payment->reference` is the merchant's own and repeats across retries.
4. Register it in `BdPayGateManager::DRIVERS` and add its block to `config/bdpaygate.php`.
5. Add `tests/Conformance/YourGatewayScenario.php` (see `tests/Support/GatewayScenario.php`). The shared suite in `tests/Feature/ConformanceTest.php` then checks it for forged callbacks, amount mismatches, replayed IPNs, races, timeouts, late successes, sync and refunds. **A gateway is supported when it passes.**
6. Add gateway-specific tests in `tests/Feature/YourGatewayTest.php` and a page in `docs/gateways/`.

`src/Drivers/SslCommerzGateway.php` with `tests/Conformance/SslCommerzScenario.php` is the reference.
