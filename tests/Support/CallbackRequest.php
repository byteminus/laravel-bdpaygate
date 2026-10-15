<?php

namespace ByteMinus\BdPayGate\Tests\Support;

/**
 * A request a gateway sends to the package's callback URL.
 */
final class CallbackRequest
{
    /**
     * @param  'GET'|'POST'  $method
     * @param  array<string, mixed>  $data  Query string for GET, form body for POST (JSON body when $json).
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $data = [],
        public readonly bool $json = false,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function get(string $uri, array $query = []): self
    {
        return new self('GET', $uri, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function post(string $uri, array $data = [], bool $json = false): self
    {
        return new self('POST', $uri, $data, $json);
    }
}
