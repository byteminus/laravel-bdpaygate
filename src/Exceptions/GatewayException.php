<?php

namespace ByteMinus\BdPayGate\Exceptions;

use RuntimeException;

class GatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        string $message,
        public readonly string $gateway,
        public readonly array $response = [],
    ) {
        parent::__construct($message);
    }
}
