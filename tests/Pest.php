<?php

use ByteMinus\BdPayGate\Data\Customer;
use ByteMinus\BdPayGate\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function customer(): Customer
{
    return new Customer(
        name: 'Rahim Uddin',
        phone: '01711000000',
        email: 'rahim@example.com',
        address: 'House 12, Road 5, Dhanmondi',
        city: 'Dhaka',
    );
}

/**
 * Build a callback payload signed the way SSLCommerz signs it.
 *
 * @param  array<string, string>  $fields
 * @return array<string, string>
 */
function sslcommerzSigned(array $fields, string $storePassword = 'teststore@ssl'): array
{
    $keys = array_keys($fields);
    $hash = $fields;
    $hash['store_passwd'] = md5($storePassword);
    ksort($hash);

    $pairs = [];
    foreach ($hash as $k => $v) {
        $pairs[] = $k.'='.$v;
    }

    return $fields + [
        'verify_key' => implode(',', $keys),
        'verify_sign' => md5(implode('&', $pairs)),
    ];
}
