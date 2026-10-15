<?php

namespace ByteMinus\BdPayGate;

use ByteMinus\BdPayGate\Contracts\Gateway;
use ByteMinus\BdPayGate\Contracts\SupportsRefunds;
use ByteMinus\BdPayGate\Contracts\SupportsSync;
use ByteMinus\BdPayGate\Data\RefundResult;
use ByteMinus\BdPayGate\Drivers\AamarPayGateway;
use ByteMinus\BdPayGate\Drivers\BkashGateway;
use ByteMinus\BdPayGate\Drivers\DgePayGateway;
use ByteMinus\BdPayGate\Drivers\EpsGateway;
use ByteMinus\BdPayGate\Drivers\FakeGateway;
use ByteMinus\BdPayGate\Drivers\MoneybagGateway;
use ByteMinus\BdPayGate\Drivers\NagadGateway;
use ByteMinus\BdPayGate\Drivers\PayStationGateway;
use ByteMinus\BdPayGate\Drivers\PortPosGateway;
use ByteMinus\BdPayGate\Drivers\ShurjoPayGateway;
use ByteMinus\BdPayGate\Drivers\SslCommerzGateway;
use ByteMinus\BdPayGate\Drivers\UddoktaPayGateway;
use ByteMinus\BdPayGate\Drivers\UpayGateway;
use ByteMinus\BdPayGate\Exceptions\GatewayException;
use ByteMinus\BdPayGate\Exceptions\UnsupportedOperationException;
use ByteMinus\BdPayGate\Models\Payment;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * @method Gateway driver(?string $driver = null)
 * @method \ByteMinus\BdPayGate\Models\Payment charge(float|int|string $amount, string $reference, \ByteMinus\BdPayGate\Data\Customer $customer, ?string $description = null, array $meta = [])
 * @method \ByteMinus\BdPayGate\Data\CallbackResult handleCallback(\Illuminate\Http\Request $request, string $outcome)
 */
class BdPayGateManager extends Manager
{
    /**
     * Built-in drivers, by the "driver" key used in config.
     *
     * @var array<string, class-string<Gateway>>
     */
    public const DRIVERS = [
        'bkash' => BkashGateway::class,
        'sslcommerz' => SslCommerzGateway::class,
        'nagad' => NagadGateway::class,
        'upay' => UpayGateway::class,
        'shurjopay' => ShurjoPayGateway::class,
        'aamarpay' => AamarPayGateway::class,
        'portpos' => PortPosGateway::class,
        'paystation' => PayStationGateway::class,
        'uddoktapay' => UddoktaPayGateway::class,
        'moneybag' => MoneybagGateway::class,
        'eps' => EpsGateway::class,
        'dgepay' => DgePayGateway::class,
        'fake' => FakeGateway::class,
    ];

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('bdpaygate.default', 'sslcommerz');
    }

    /**
     * Resolve a gateway by the name used in config('bdpaygate.gateways').
     * The "driver" key inside that entry decides which class is built,
     * so you can register two SSLCommerz stores under different names.
     */
    public function gateway(?string $name = null): Gateway
    {
        return $this->driver($name);
    }

    /**
     * The gateway a payment was made with.
     */
    public function for(Payment $payment): Gateway
    {
        return $this->driver($payment->gateway);
    }

    /**
     * Ask the payment's gateway for its current state.
     */
    public function sync(Payment $payment): Payment
    {
        $gateway = $this->for($payment);

        if (! $gateway instanceof SupportsSync) {
            throw new UnsupportedOperationException("{$gateway->name()} has no status API.", $gateway->name());
        }

        return $gateway->sync($payment);
    }

    /**
     * Refund through the payment's gateway. Null refunds everything still refundable.
     */
    public function refund(Payment $payment, float|int|string|null $amount = null, ?string $reason = null): RefundResult
    {
        $gateway = $this->for($payment);

        if (! $gateway instanceof SupportsRefunds) {
            throw new UnsupportedOperationException("{$gateway->name()} has no refund API. Refund from the merchant panel.", $gateway->name());
        }

        return $gateway->refund($payment, $amount, $reason);
    }

    /**
     * Swap the default gateway for the fake one, for tests.
     */
    public function fake(): FakeGateway
    {
        $this->config->set('bdpaygate.default', 'fake');

        if (! is_array($this->config->get('bdpaygate.gateways.fake'))) {
            $this->config->set('bdpaygate.gateways.fake', ['driver' => 'fake']);
        }

        $gateway = $this->driver('fake');

        if (! $gateway instanceof FakeGateway) {
            throw new GatewayException('The fake gateway is not registered.', 'fake');
        }

        return $gateway;
    }

    protected function createDriver($driver): Gateway
    {
        $config = $this->config->get("bdpaygate.gateways.{$driver}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Gateway [{$driver}] is not configured in config/bdpaygate.php.");
        }

        $type = (string) ($config['driver'] ?? $driver);

        // BdPayGate::extend('mygateway', fn ($app, array $config, string $name) => new MyGateway($config, $name));
        if (isset($this->customCreators[$type])) {
            $gateway = $this->customCreators[$type]($this->container, $config, $driver);

            if (! $gateway instanceof Gateway) {
                throw new InvalidArgumentException("Custom gateway [{$type}] must implement ".Gateway::class.'.');
            }

            return $gateway;
        }

        $class = self::DRIVERS[$type] ?? throw new InvalidArgumentException("Gateway driver [{$type}] is not supported.");

        return new $class($config, $driver);
    }
}
