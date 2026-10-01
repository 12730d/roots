<?php

declare(strict_types=1)

;

namespace ROOTS\Services;

use ROOTS\Config\EnvLoader;
use RuntimeException;

class SensitiveDataService
{
    private const VERSION = 'v1';
    private const GCM_CIPHER = 'aes-256-gcm';
    private const CBC_CIPHER = 'aes-256-cbc';

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        $key = self::getKey();
        $cipher = self::getPreferredCipher();
        $ivLength = openssl_cipher_iv_length($cipher);
        if ($ivLength === false || $ivLength < 1) {
            throw new RuntimeException('Failed to get cipher IV length.');
        }
        $iv = random_bytes($ivLength);
        $tagOrMac = '';

        if ($cipher === self::GCM_CIPHER) {
            $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tagOrMac);
        } else {
            $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
            if ($ciphertext !== false) {
                $tagOrMac = hash_hmac('sha256', $ciphertext, $key, true);
            }
        }

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return implode(':', [
            self::VERSION,
            $cipher,
            base64_encode($iv),
            base64_encode($tagOrMac),
            base64_encode($ciphertext)
        ]);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }

        if (strpos($payload, self::VERSION . ':') !== 0) {
            return $payload;
        }

        $parts = explode(':', $payload, 5);
        if (count($parts) !== 5) {
            return $payload;
        }

        [$version, $cipher, $ivB64, $tagB64, $cipherB64] = $parts;
        if ($version !== self::VERSION) {
            return $payload;
        }

        if (!in_array($cipher, openssl_get_cipher_methods(true), true)) {
            return $payload;
        }

        $iv = base64_decode($ivB64, true);
        $tagOrMac = base64_decode($tagB64, true);
        $ciphertext = base64_decode($cipherB64, true);

        if ($iv === false || $tagOrMac === false || $ciphertext === false) {
            return null;
        }

        $key = self::getKey();

        if ($cipher === self::GCM_CIPHER) {
            $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tagOrMac);
        } else {
            $expectedMac = hash_hmac('sha256', $ciphertext, $key, true);
            if (!hash_equals($expectedMac, $tagOrMac)) {
                return null;
            }
            $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        }

        return $plaintext === false ? null : $plaintext;
    }

    private static function getKey(): string
    {
        EnvLoader::load();

        $rawKey = $_ENV['DATA_ENCRYPTION_KEY']
            ?? $_ENV['APP_KEY']
            ?? $_ENV['SECRET']
            ?? '';

        if ($rawKey === '') {
            throw new RuntimeException('Missing encryption key.');
        }

        return hash('sha256', $rawKey, true);
    }

    private static function getPreferredCipher(): string
    {
        $ciphers = openssl_get_cipher_methods(true);
        if (in_array(self::GCM_CIPHER, $ciphers, true)) {
            return self::GCM_CIPHER;
        }

        if (in_array(self::CBC_CIPHER, $ciphers, true)) {
            return self::CBC_CIPHER;
        }

        throw new RuntimeException('No supported encryption cipher available.');
    }
}
