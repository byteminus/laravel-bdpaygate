<?php

namespace ByteMinus\BdPayGate\Drivers;

use ByteMinus\BdPayGate\Contracts\Gateway;
use ByteMinus\BdPayGate\Data\CallbackResult;
use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Enums\PaymentStatus;
use ByteMinus\BdPayGate\Events\PaymentCancelled;
use ByteMinus\BdPayGate\Events\PaymentCompleted;
use ByteMinus\BdPayGate\Events\PaymentDuplicated;
use ByteMinus\BdPayGate\Events\PaymentFailed;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\GatewayUnavailableException;
use ByteMinus\BdPayGate\Exceptions\InvalidCallbackException;
use ByteMinus\BdPayGate\Models\Payment;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

abstract class AbstractGateway implements Gateway
{
    /**
     * The driver type, as used in a gateway's "driver" config key.
     */
    public const DRIVER = '';

    /**
     * @param  array<string, mixed>  $config
     * @param  string|null  $name  The config key this gateway was resolved under. Two stores of the
     *                             same driver get different names, routes and payments.
     */
    public function __construct(protected array $config, protected ?string $name = null) {}

    public function name(): string
    {
        return $this->name ?? static::DRIVER;
    }

    public function sandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? true);
    }

    /**
     * The API base URL: an explicit "base_url" in config wins, otherwise sandbox or live.
     */
    protected function endpoint(string $sandbox, string $live): string
    {
        $override = $this->config['base_url'] ?? null;

        return rtrim(is_string($override) && $override !== '' ? $override : ($this->sandbox() ? $sandbox : $live), '/');
    }

    protected function currency(): string
    {
        return (string) config('bdpaygate.currency', 'BDT');
    }

    /**
     * No automatic retries: a gateway POST that times out may still have gone
     * through, and each driver knows how to query instead of re-sending.
     * Some gateways only accept whitelisted IPs; route through a proxy with
     * the gateway's (or global) "proxy" setting.
     */
    protected function http(): PendingRequest
    {
        $request = Http::acceptJson()->timeout((int) ($this->config['timeout'] ?? config('bdpaygate.timeout', 30)));

        $proxy = $this->config['proxy'] ?? config('bdpaygate.proxy');

        if (is_string($proxy) && $proxy !== '') {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    /**
     * Send a request, turning a dropped connection or timeout into GatewayUnavailableException.
     *
     * @param  array<string, mixed>  $data  Query string for GET, body otherwise.
     */
    protected function send(PendingRequest $request, string $method, string $url, array $data = []): Response
    {
        try {
            return match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                default => throw new InvalidArgumentException("Unsupported HTTP method [{$method}]."),
            };
        } catch (ConnectionException $e) {
            throw new GatewayUnavailableException("{$this->name()} did not respond: {$e->getMessage()}", $this->name());
        }
    }

    /**
     * A response body as an array, whatever the gateway sent. Many Bangladeshi
     * gateways answer errors with HTTP 200 and a body that is not JSON at all.
     *
     * @return array<array-key, mixed>
     */
    protected function decode(Response $response): array
    {
        $data = $response->json();

        if (is_array($data)) {
            return $data;
        }

        return ['http_status' => $response->status(), 'body' => mb_substr($response->body(), 0, 2000)];
    }

    /**
     * Normalise any amount to a "1234.50" string: gateways are picky and floats drift.
     */
    protected function amount(float|int|string $amount): string
    {
        $value = round((float) $amount, 2);

        if ($value <= 0) {
            throw new GatewayException('Amount must be greater than zero.', $this->name());
        }

        return number_format($value, 2, '.', '');
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function callbackUrl(string $outcome, array $query = []): string
    {
        return route('bdpaygate.callback', ['gateway' => $this->name(), 'outcome' => $outcome] + $query);
    }

    /**
     * A fresh id for each attempt. Gateways reject or mishandle a reused one
     * (Nagad, Upay, aamarPay, SSLCommerz), and old checkout pages stay payable,
     * so a retry must never reuse the previous attempt's id. 20 uppercase
     * alphanumerics fits every gateway's limits.
     */
    protected function newGatewayReference(): string
    {
        return 'BP'.strtoupper(Str::random(18));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function createPendingPayment(
        float|int|string $amount,
        string $reference,
        Customer $customer,
        ?string $description,
        array $meta,
    ): Payment {
        if ($reference === '' || mb_strlen($reference) > 64) {
            throw new GatewayException('Reference must be 1 to 64 characters.', $this->name());
        }

        $paid = Payment::query()
            ->where('gateway', $this->name())
            ->where('reference', $reference)
            ->whereIn('status', PaymentStatus::paid())
            ->exists();

        if ($paid) {
            throw new GatewayException("Reference [{$reference}] has already been paid.", $this->name());
        }

        return Payment::query()->create([
            'gateway' => $this->name(),
            'reference' => $reference,
            'gateway_reference' => $this->newGatewayReference(),
            'amount' => $this->amount($amount),
            'currency' => $this->currency(),
            'status' => PaymentStatus::Pending,
            'description' => $description,
            'customer' => $customer->toArray(),
            'meta' => $meta,
        ]);
    }

    protected function findByGatewayReference(string $gatewayReference): Payment
    {
        $payment = $gatewayReference === '' ? null : Payment::query()
            ->where('gateway', $this->name())
            ->where('gateway_reference', $gatewayReference)
            ->first();

        if (! $payment) {
            throw new InvalidCallbackException("Unknown payment [{$gatewayReference}].", $this->name());
        }

        return $payment;
    }

    protected function findByGatewayPaymentId(string $id): Payment
    {
        $payment = $id === '' ? null : Payment::query()
            ->where('gateway', $this->name())
            ->where('gateway_payment_id', $id)
            ->first();

        if (! $payment) {
            throw new InvalidCallbackException("Unknown gateway payment id [{$id}].", $this->name());
        }

        return $payment;
    }

    protected function assertAmountMatches(Payment $payment, float|int|string|null $reported, ?string $currency = null): void
    {
        $reported = (string) $reported;
        $matches = is_numeric($reported)
            && (float) $reported > 0
            && $this->amount($reported) === $this->amount($payment->amount);

        if (! $matches) {
            throw new InvalidCallbackException(
                "Amount mismatch for [{$payment->gateway_reference}]: expected {$payment->amount}, gateway reported {$reported}.",
                $this->name(),
            );
        }

        if ($currency !== null && $currency !== '' && strtoupper($currency) !== strtoupper($payment->currency)) {
            throw new InvalidCallbackException(
                "Currency mismatch for [{$payment->gateway_reference}]: expected {$payment->currency}, gateway reported {$currency}.",
                $this->name(),
            );
        }
    }

    /**
     * Move a payment to a new state at most once, and fire the matching event.
     *
     * The update is conditional on the status in the database, so two requests
     * racing (an IPN and the customer's redirect arriving together) cannot both
     * win: exactly one fires the event. Pending can become anything; Failed and
     * Cancelled can still become Completed when the gateway confirms payment.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<array-key, mixed>  $raw
     */
    protected function transition(Payment $payment, PaymentStatus $status, array $attributes = [], string $step = 'callback', array $raw = []): CallbackResult
    {
        $from = array_values(array_filter(PaymentStatus::cases(), fn (PaymentStatus $s) => $s->canMoveTo($status)));

        if ($from === []) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        $payment->fill($attributes);
        $payment->status = $status;

        if ($status === PaymentStatus::Completed && ! $payment->paid_at) {
            $payment->paid_at = now();
        }

        if ($raw !== []) {
            $payment->logGatewayResponse($step, $raw);
        }

        $updated = Payment::query()
            ->whereKey($payment->getKey())
            ->whereIn('status', array_map(fn (PaymentStatus $s) => $s->value, $from))
            ->update($payment->getDirty());

        $payment->refresh();

        if ($updated === 0) {
            return new CallbackResult($payment, $payment->status, changed: false);
        }

        match ($status) {
            PaymentStatus::Completed => $this->alreadyPaidElsewhere($payment)
                ? PaymentDuplicated::dispatch($payment)
                : PaymentCompleted::dispatch($payment),
            PaymentStatus::Failed => PaymentFailed::dispatch($payment),
            PaymentStatus::Cancelled => PaymentCancelled::dispatch($payment),
            default => null,
        };

        return new CallbackResult($payment, $status, changed: true);
    }

    /**
     * Record what the gateway said without changing state: it could not
     * confirm either way yet. Sync later.
     *
     * @param  array<array-key, mixed>  $raw
     */
    protected function stillPending(Payment $payment, string $step, array $raw = []): CallbackResult
    {
        if ($raw !== []) {
            $payment->logGatewayResponse($step, $raw)->save();
        }

        return new CallbackResult($payment, $payment->status, changed: false);
    }

    protected function alreadyPaidElsewhere(Payment $payment): bool
    {
        return Payment::query()
            ->where('gateway', $payment->gateway)
            ->where('reference', $payment->reference)
            ->whereKeyNot($payment->getKey())
            ->whereIn('status', PaymentStatus::paid())
            ->exists();
    }

    /**
     * Cache an access token, letting only one process fetch a new one. Some
     * gateways (bKash) lock the merchant out for an hour after a few grants.
     *
     * @param  Closure(): array{0: string, 1: int}  $fetch  Returns [token, ttl in seconds].
     */
    protected function rememberToken(string $key, Closure $fetch): string
    {
        $key = $this->tokenCacheKey($key);
        $token = Cache::get($key);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return Cache::lock($key.':lock', 30)->block(20, function () use ($key, $fetch): string {
            $token = Cache::get($key);

            if (is_string($token) && $token !== '') {
                return $token;
            }

            [$token, $ttl] = $fetch();
            Cache::put($key, $token, max(30, $ttl));

            return $token;
        });
    }

    protected function forgetToken(string $key): void
    {
        Cache::forget($this->tokenCacheKey($key));
    }

    protected function tokenCacheKey(string $key): string
    {
        return 'bdpaygate.token.'.$this->name().'.'.md5($key.($this->sandbox() ? ':sandbox' : ':live'));
    }

    /**
     * A config value that must be set.
     */
    protected function credential(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_scalar($value) || (string) $value === '') {
            throw new GatewayException("Missing [{$key}] in the [{$this->name()}] gateway config.", $this->name());
        }

        return (string) $value;
    }
}
