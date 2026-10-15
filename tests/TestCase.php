<?php

namespace ByteMinus\BdPayGate\Tests;

use ByteMinus\BdPayGate\BdPayGateServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [BdPayGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://shop.test');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('bdpaygate.gateways.sslcommerz', [
            'driver' => 'sslcommerz',
            'store_id' => 'teststore',
            'store_password' => 'teststore@ssl',
            'sandbox' => true,
            'validate_with_api' => true,
        ]);

        $app['config']->set('bdpaygate.gateways.bkash', [
            'driver' => 'bkash',
            'app_key' => 'app-key',
            'app_secret' => 'app-secret',
            'username' => 'sandboxuser',
            'password' => 'sandboxpass',
            'sandbox' => true,
            'mode' => '0011',
        ]);

        $app['config']->set('bdpaygate.redirects', [
            'success' => '/thanks',
            'failed' => '/failed',
            'cancelled' => '/cart',
            'pending' => '/pending',
        ]);
    }
}
