<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use RuntimeException;

final class Crypto
{
    public function __construct(private string $key)
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Crypto key must be exactly 32 bytes.');
        }
    }

    public function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function hmac(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose . "\0" . $value, $this->key);
    }

    public function encrypt(string $plaintext): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1.' . $this->base64Url($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
        }
        if (function_exists('openssl_encrypt')) {
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
            if ($ciphertext === false) {
                throw new RuntimeException('Encryption failed.');
            }
            return 'o1.' . $this->base64Url($nonce . $tag . $ciphertext);
        }
        throw new RuntimeException('Install ext-sodium or ext-openssl.');
    }

    public function decrypt(string $envelope): string
    {
        [$version, $encoded] = array_pad(explode('.', $envelope, 2), 2, '');
        $payload = $this->base64UrlDecode($encoded);
        if ($version === 's1' && function_exists('sodium_crypto_secretbox_open')) {
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plaintext = sodium_crypto_secretbox_open(substr($payload, $nonceLength), substr($payload, 0, $nonceLength), $this->key);
        } elseif ($version === 'o1' && function_exists('openssl_decrypt')) {
            $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        } else {
            throw new RuntimeException('Unsupported encryption envelope.');
        }
        if (!is_string($plaintext)) {
            throw new RuntimeException('Encrypted value could not be authenticated.');
        }
        return $plaintext;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid encrypted value encoding.');
        }
        return $decoded;
    }
}
