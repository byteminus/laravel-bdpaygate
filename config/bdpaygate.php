<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | Used when you call BdPayGate::charge() without choosing a gateway.
    |
    */

    'default' => env('BDPAYGATE_GATEWAY', 'sslcommerz'),

    'currency' => 'BDT',

    /*
    |--------------------------------------------------------------------------
    | Where to send the customer after the gateway calls back
    |--------------------------------------------------------------------------
    |
    | The package owns the gateway-facing callback URLs (/bdpaygate/{gateway}/...).
    | After it has verified the payment with the gateway, it redirects the
    | customer to one of these routes in your app, with ?payment={id}.
    | "pending" is used when the gateway could not confirm either way yet.
    | Use a route name or a URL.
    |
    */

    'redirects' => [
        'success' => env('BDPAYGATE_REDIRECT_SUCCESS', '/'),
        'failed' => env('BDPAYGATE_REDIRECT_FAILED', '/'),
        'cancelled' => env('BDPAYGATE_REDIRECT_CANCELLED', '/'),
        'pending' => env('BDPAYGATE_REDIRECT_PENDING', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package routes
    |--------------------------------------------------------------------------
    |
    | No "web" middleware on purpose: gateways return customers with a
    | cross-site POST, and a session there logs the customer out.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'bdpaygate',
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP
    |--------------------------------------------------------------------------
    |
    | Some gateways (Nagad, Upay) only accept calls from whitelisted IPs. If
    | your app servers don't have a fixed IP, send gateway traffic through a
    | proxy that does. Each gateway can override "proxy" and "timeout".
    |
    */

    'proxy' => env('BDPAYGATE_PROXY'),

    'timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Each key is a gateway name; "driver" picks the implementation, so you can
    | register two stores of the same gateway under different names. Every
    | gateway also accepts "base_url" to override the API host.
    |
    */

    'gateways' => [

        // Stable ----------------------------------------------------------

        'bkash' => [
            'driver' => 'bkash',
            'app_key' => env('BKASH_APP_KEY'),
            'app_secret' => env('BKASH_APP_SECRET'),
            'username' => env('BKASH_USERNAME'),
            'password' => env('BKASH_PASSWORD'),
            'sandbox' => env('BKASH_SANDBOX', true),
            'version' => env('BKASH_VERSION', 'v2'), // "v2" or "v1.2" (v1.2.0-beta)
            'mode' => '0011',                        // v1.2 only
            'verify_signature' => true,
            'execute_on_sync' => true,
        ],

        // Cards, banks and wallets, incl. Rocket, OK Wallet and IBBL/CellFin.
        'sslcommerz' => [
            'driver' => 'sslcommerz',
            'store_id' => env('SSLCOMMERZ_STORE_ID'),
            'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            'sandbox' => env('SSLCOMMERZ_SANDBOX', true),
        ],

        'shurjopay' => [
            'driver' => 'shurjopay',
            'username' => env('SHURJOPAY_USERNAME'),
            'password' => env('SHURJOPAY_PASSWORD'),
            'prefix' => env('SHURJOPAY_PREFIX'),
            'sandbox' => env('SHURJOPAY_SANDBOX', true),
        ],

        'aamarpay' => [
            'driver' => 'aamarpay',
            'store_id' => env('AAMARPAY_STORE_ID'),
            'signature_key' => env('AAMARPAY_SIGNATURE_KEY'),
            'sandbox' => env('AAMARPAY_SANDBOX', true),
        ],

        // Beta: built from public information, not yet verified on a merchant sandbox.

        'nagad' => [
            'driver' => 'nagad',
            'merchant_id' => env('NAGAD_MERCHANT_ID'),
            'merchant_number' => env('NAGAD_MERCHANT_NUMBER'),
            'public_key' => env('NAGAD_PUBLIC_KEY'),
            'private_key' => env('NAGAD_PRIVATE_KEY'),
            'sandbox' => env('NAGAD_SANDBOX', true),
            'base_url' => env('NAGAD_BASE_URL'),
            'signature_algorithm' => env('NAGAD_SIGNATURE_ALGORITHM', 'sha256'),
            'verify_response_signature' => env('NAGAD_VERIFY_RESPONSE_SIGNATURE', false),
            'ip' => env('NAGAD_IP'),
            'proxy' => env('NAGAD_PROXY'),
        ],

        'upay' => [
            'driver' => 'upay',
            'merchant_id' => env('UPAY_MERCHANT_ID'),
            'merchant_key' => env('UPAY_MERCHANT_KEY'),
            'merchant_code' => env('UPAY_MERCHANT_CODE'),
            'merchant_name' => env('UPAY_MERCHANT_NAME'),
            'merchant_mobile' => env('UPAY_MERCHANT_MOBILE'),
            'merchant_city' => env('UPAY_MERCHANT_CITY', 'Dhaka'),
            'merchant_category_code' => env('UPAY_MERCHANT_CATEGORY_CODE'),
            'sandbox' => env('UPAY_SANDBOX', true),
            'base_url' => env('UPAY_BASE_URL'),
            'proxy' => env('UPAY_PROXY'),
        ],

        'portpos' => [
            'driver' => 'portpos',
            'app_key' => env('PORTPOS_APP_KEY'),
            'secret_key' => env('PORTPOS_SECRET_KEY'),
            'sandbox' => env('PORTPOS_SANDBOX', true),
        ],

        'moneybag' => [
            'driver' => 'moneybag',
            'api_key' => env('MONEYBAG_API_KEY'),
            'sandbox' => env('MONEYBAG_SANDBOX', true),
            'base_url' => env('MONEYBAG_BASE_URL'),
        ],

        'paystation' => [
            'driver' => 'paystation',
            'merchant_id' => env('PAYSTATION_MERCHANT_ID'),
            'password' => env('PAYSTATION_PASSWORD'),
            'sandbox' => env('PAYSTATION_SANDBOX', true),
        ],

        // Self-hosted: customers pay into the merchant's own wallets. See docs/gateways/uddoktapay.md.
        'uddoktapay' => [
            'driver' => 'uddoktapay',
            'base_url' => env('UDDOKTAPAY_BASE_URL'),
            'api_key' => env('UDDOKTAPAY_API_KEY'),
            'sandbox' => env('UDDOKTAPAY_SANDBOX', false),
        ],

        // Experimental: built from the provider's published SDKs. Request the
        // integration guide and test before going live.

        'eps' => [
            'driver' => 'eps',
            'username' => env('EPS_USERNAME'),
            'password' => env('EPS_PASSWORD'),
            'hash_key' => env('EPS_HASH_KEY'),
            'merchant_id' => env('EPS_MERCHANT_ID'),
            'store_id' => env('EPS_STORE_ID'),
            'sandbox' => env('EPS_SANDBOX', true),
        ],

        'dgepay' => [
            'driver' => 'dgepay',
            'client_id' => env('DGEPAY_CLIENT_ID'),
            'client_secret' => env('DGEPAY_CLIENT_SECRET'),
            'api_key' => env('DGEPAY_API_KEY'),
            'sandbox' => env('DGEPAY_SANDBOX', true), // true = UAT
        ],

        'fake' => [
            'driver' => 'fake',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'table' => 'bdpaygate_payments',

];
