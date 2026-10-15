<?php

namespace ByteMinus\BdPayGate\Drivers\DgePay;

use ByteMinus\BdPayGate\Exceptions\GatewayException;

/**
 * DGePay's request envelope, as its published SDK builds it.
 *
 * - Body: the JSON payload (keys sorted), AES-128-ECB with PKCS#7 padding,
 *   base64. The key is the client secret padded with "0" (or cut) to 16 bytes.
 * - Signature header: base64(HMAC-SHA256(api key, canonical)), where
 *   canonical is that same sorted JSON with { } " : , and spaces removed,
 *   i.e. key1value1key2value2..., nested objects inline, nulls as "null"
 *   and numbers as JSON prints them (150.0, 150.5).
 *
 * ECB is DGePay's choice, not ours; it is only used because the API requires it.
 */
final class DgePayCrypto
{
    public function __construct(
        private readonly string $clientSecret,
        private readonly string $apiKey,
        private readonly string $gateway = 'dgepay',
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function json(array $payload): string
    {
        $json = json_encode(self::sorted($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            throw new GatewayException('Could not encode the DGePay payload as JSON.', $this->gateway);
        }

        return $json;
    }

    /**
     * The string the Signature header is computed over.
     *
     * @param  array<string, mixed>  $payload
     */
    public function canonical(array $payload): string
    {
        return str_replace(['{', '}', '"', ':', ',', ' '], '', $this->json($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        return base64_encode(hash_hmac('sha256', $this->canonical($payload), $this->apiKey, true));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encrypt(array $payload): string
    {
        $cipher = openssl_encrypt($this->json($payload), 'aes-128-ecb', $this->key(), OPENSSL_RAW_DATA);

        if ($cipher === false) {
            throw new GatewayException('Could not encrypt the DGePay payload.', $this->gateway);
        }

        return base64_encode($cipher);
    }

    /**
     * Decrypt a base64 blob (e.g. the "data" DGePay appends to the redirect URL)
     * into an array. Null when it does not decrypt to a JSON object.
     *
     * Query-string parsing turns "+" into spaces; they are restored here.
     *
     * @return array<string, mixed>|null
     */
    public function decrypt(string $encoded): ?array
    {
        $encoded = str_replace(' ', '+', trim($encoded));

        if (str_contains($encoded, '%')) {
            $encoded = rawurldecode($encoded);
        }

        $encoded = strtr($encoded, '-_', '+/');
        $raw = base64_decode($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        if ($raw === false || $raw === '') {
            return null;
        }

        $plain = openssl_decrypt($raw, 'aes-128-ecb', $this->key(), OPENSSL_RAW_DATA);
        $data = is_string($plain) ? json_decode($plain, true) : null;

        return is_array($data) ? $data : null;
    }

    private function key(): string
    {
        return substr(str_pad($this->clientSecret, 16, '0'), 0, 16);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sorted(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sorted($value);
            }
        }

        return $data;
    }
}
