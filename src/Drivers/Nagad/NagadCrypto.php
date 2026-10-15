<?php

namespace ByteMinus\BdPayGate\Drivers\Nagad;

use ByteMinus\BdPayGate\Exceptions\GatewayException;
use OpenSSLAsymmetricKey;

/**
 * The RSA envelope Nagad wraps sensitive request and response fields in.
 *
 * Outbound: the plain JSON is encrypted with Nagad's public key (PKCS#1 v1.5)
 * and signed with the merchant's private key. Inbound: Nagad encrypts with the
 * merchant's public key, so the merchant private key decrypts, and Nagad's
 * public key verifies Nagad's signature.
 *
 * Keys may be PEM text (PKCS#1 or PKCS#8, with literal "\n" escapes as often
 * found in .env files), a bare base64 body, or a path to a PEM file.
 */
final class NagadCrypto
{
    /** @var array<string, int> */
    private const ALGORITHMS = [
        'sha256' => OPENSSL_ALGO_SHA256,
        'sha1' => OPENSSL_ALGO_SHA1,
    ];

    private ?OpenSSLAsymmetricKey $gatewayKey = null;

    private ?OpenSSLAsymmetricKey $merchantKey = null;

    private readonly int $algorithm;

    public function __construct(
        private readonly string $gatewayPublicKey,
        private readonly string $merchantPrivateKey,
        string $algorithm = 'sha256',
        private readonly string $gateway = 'nagad',
    ) {
        $this->algorithm = self::algorithm($algorithm, $gateway);
    }

    /**
     * "sha256" (default, what working integrations use) or "sha1". Also accepts
     * the Java-style names "SHA256withRSA" / "SHA1withRSA".
     */
    public static function algorithm(string $name, string $gateway = 'nagad'): int
    {
        $key = strtolower(str_replace(['withrsa', '-', '_'], '', strtolower($name)));

        return self::ALGORITHMS[$key]
            ?? throw new GatewayException("Unsupported Nagad signature algorithm [{$name}]; use sha256 or sha1.", $gateway);
    }

    /**
     * base64(RSA-encrypt(plain, Nagad public key, PKCS#1 v1.5)).
     */
    public function encrypt(string $plain): string
    {
        if (! openssl_public_encrypt($plain, $encrypted, $this->gatewayKey(), OPENSSL_PKCS1_PADDING)) {
            throw new GatewayException('Could not encrypt the Nagad payload (too long for the key, or a bad Nagad public key).', $this->gateway);
        }

        return base64_encode($encrypted);
    }

    /**
     * base64(RSA-sign(plain, merchant private key)).
     */
    public function sign(string $plain): string
    {
        if (! openssl_sign($plain, $signature, $this->merchantKey(), $this->algorithm)) {
            throw new GatewayException('Could not sign the Nagad payload with the merchant private key.', $this->gateway);
        }

        return base64_encode($signature);
    }

    /**
     * Decrypt a base64 field Nagad encrypted with the merchant public key.
     */
    public function decrypt(string $encrypted): string
    {
        $raw = base64_decode($encrypted, true);

        if ($raw === false || $raw === '' || ! openssl_private_decrypt($raw, $plain, $this->merchantKey(), OPENSSL_PKCS1_PADDING)) {
            throw new GatewayException('Could not decrypt the Nagad response with the merchant private key.', $this->gateway);
        }

        return $plain;
    }

    /**
     * Check a signature Nagad made over $plain with its private key.
     */
    public function verify(string $plain, string $signature): bool
    {
        $raw = base64_decode($signature, true);

        return $raw !== false && $raw !== '' && openssl_verify($plain, $raw, $this->gatewayKey(), $this->algorithm) === 1;
    }

    private function gatewayKey(): OpenSSLAsymmetricKey
    {
        return $this->gatewayKey ??= self::load($this->gatewayPublicKey, public: true, gateway: $this->gateway);
    }

    private function merchantKey(): OpenSSLAsymmetricKey
    {
        return $this->merchantKey ??= self::load($this->merchantPrivateKey, public: false, gateway: $this->gateway);
    }

    private static function load(string $key, bool $public, string $gateway): OpenSSLAsymmetricKey
    {
        $labels = $public ? ['PUBLIC KEY', 'RSA PUBLIC KEY'] : ['PRIVATE KEY', 'RSA PRIVATE KEY'];

        foreach (self::candidates($key, $labels) as $pem) {
            $loaded = $public ? openssl_pkey_get_public($pem) : openssl_pkey_get_private($pem);

            if ($loaded !== false) {
                return $loaded;
            }
        }

        throw new GatewayException(
            'Could not read the '.($public ? 'Nagad public key' : 'merchant private key').'. Use PEM text, a bare base64 key, or a path to a PEM file.',
            $gateway,
        );
    }

    /**
     * @param  list<string>  $labels
     * @return list<string>
     */
    private static function candidates(string $key, array $labels): array
    {
        $key = trim(str_replace(['\\r\\n', '\\n'], "\n", $key));

        if ($key !== '' && ! str_contains($key, '-----BEGIN') && strlen($key) < 4096 && @is_file($key)) {
            $key = trim((string) file_get_contents($key));
        }

        if (str_contains($key, '-----BEGIN')) {
            return [$key];
        }

        $body = chunk_split((string) preg_replace('/\s+/', '', $key), 64, "\n");

        return array_map(fn (string $label) => "-----BEGIN {$label}-----\n{$body}-----END {$label}-----", $labels);
    }
}
