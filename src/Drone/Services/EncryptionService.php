<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use Exception;

/**
 * EncryptionService - Handles all encryption and security operations
 *
 * This service provides:
 * - AES-256-GCM encryption for data at rest and in transit
 * - Secure key generation and management
 * - Data integrity verification
 * - GDPR-compliant data protection
 */
class EncryptionService
{
    private string $encryptionKey;
    private string $hmacKey;
    private string $algorithm = 'aes-256-gcm';

    public function __construct()
    {
        // Load encryption keys from environment
        $this->encryptionKey = $this->loadEncryptionKey();
        $this->hmacKey = $this->loadHmacKey();

        if (strlen($this->encryptionKey) !== 32) {
            throw new DroneServiceException("Invalid encryption key length. Must be 32 bytes for AES-256.");
        }
    }

    /**
     * Encrypt data using AES-256-GCM
     *
     * @param string $plaintext Data to encrypt
     * @return array<string, mixed> Encrypted data with metadata
     */
    public function encrypt(string $plaintext): array
    {
        try {
            // Generate random IV
            $iv = random_bytes(12); // GCM recommended IV length

            // Encrypt
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                $this->algorithm,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($ciphertext === false) {
                throw new DroneServiceException("Encryption failed: " . openssl_error_string());
            }

            // Generate HMAC for integrity
            $hmac = $this->generateHmac($ciphertext . $iv . $tag);

            return [
                'success' => true,
                'ciphertext' => base64_encode($ciphertext),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag ?? ''),
                'hmac' => $hmac,
                'algorithm' => $this->algorithm,
                'timestamp' => time()
            ];

        } catch (Exception $e) {
            error_log("Encryption error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Encryption failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Decrypt data
     *
     * @param string $ciphertext Encrypted data (base64)
     * @param string $iv Initialization vector (base64)
     * @param string $tag Authentication tag (base64)
     * @param string $hmac HMAC for integrity
     * @return array<string, mixed> Decrypted data
     */
    public function decrypt(string $ciphertext, string $iv, string $tag, string $hmac): array
    {
        try {
            // Decode from base64
            $ciphertextDecoded = base64_decode($ciphertext);
            $ivDecoded = base64_decode($iv);
            $tagDecoded = base64_decode($tag);

            // Verify HMAC for integrity
            if (!$this->verifyHmac($ciphertextDecoded . $ivDecoded . $tagDecoded, $hmac)) {
                throw new DroneServiceException("HMAC verification failed - data may be tampered");
            }

            // Decrypt
            $plaintext = openssl_decrypt(
                $ciphertextDecoded,
                $this->algorithm,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $ivDecoded,
                $tagDecoded
            );

            if ($plaintext === false) {
                throw new DroneServiceException("Decryption failed: " . openssl_error_string());
            }

            return [
                'success' => true,
                'plaintext' => $plaintext,
                'verified' => true
            ];

        } catch (Exception $e) {
            error_log("Decryption error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Decryption failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Encrypt image data
     *
     * @param string $imageData Binary image data
     * @return array<string, mixed> Encrypted image data
     */
    public function encryptImage(string $imageData): array
    {
        try {
            // Compress image first if needed
            $compressedData = $this->compressData($imageData);

            // Encrypt compressed data
            $encrypted = $this->encrypt($compressedData);

            if (!$encrypted['success']) {
                return $encrypted;
            }

            // Calculate image hash
            $imageHash = hash('sha256', $imageData);

            return [
                'success' => true,
                'encrypted_data' => $encrypted,
                'image_hash' => $imageHash,
                'original_size' => strlen($imageData),
                'compressed_size' => strlen($compressedData),
                'compression_ratio' => round((1 - strlen($compressedData) / strlen($imageData)) * 100, 2)
            ];

        } catch (Exception $e) {
            error_log("Image encryption error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Image encryption failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Decrypt image data
     *
     * @param array<string, mixed> $encryptedData Encrypted image data
     * @return array<string, mixed> Decrypted image data
     */
    public function decryptImage(array $encryptedData): array
    {
        try {
            if (!isset($encryptedData['encrypted_data'])) {
                throw new DroneServiceException("Missing encrypted data");
            }

            $enc = $encryptedData['encrypted_data'];

            // Decrypt
            $decrypted = $this->decrypt(
                $enc['ciphertext'],
                $enc['iv'],
                $enc['tag'],
                $enc['hmac']
            );

            if (!$decrypted['success']) {
                return $decrypted;
            }

            // Verify hash if provided
            if (isset($encryptedData['image_hash'])) {
                $calculatedHash = hash('sha256', $decrypted['plaintext']);
                if ($calculatedHash !== $encryptedData['image_hash']) {
                    throw new DroneServiceException("Image hash verification failed");
                }
            }

            return [
                'success' => true,
                'image_data' => $decrypted['plaintext'],
                'verified' => true
            ];

        } catch (Exception $e) {
            error_log("Image decryption error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Image decryption failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate secure random key
     *
     * @param int $length Key length in bytes
     * @return string Random key
     */
    public static function generateSecureKey(int $length = 32): string
    {
        if ($length < 1) {
            throw new DroneServiceException("Key length must be at least 1");
        }
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate API key
     *
     * @param string $prefix Key prefix
     * @return string API key
     */
    public static function generateApiKey(string $prefix = 'drone'): string
    {
        $random = bin2hex(random_bytes(24));
        return $prefix . '_' . $random;
    }

    /**
     * Hash password securely
     *
     * @param string $password Plain text password
     * @return string Hashed password
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }

    /**
     * Verify password
     *
     * @param string $password Plain text password
     * @param string $hash Hashed password
     * @return bool Verification result
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate HMAC for data integrity
     *
     * @param string $data Data to sign
     * @return string HMAC signature
     */
    private function generateHmac(string $data): string
    {
        return hash_hmac('sha256', $data, $this->hmacKey);
    }

    /**
     * Verify HMAC
     *
     * @param string $data Data to verify
     * @param string $hmac HMAC to verify against
     * @return bool Verification result
     */
    private function verifyHmac(string $data, string $hmac): bool
    {
        return hash_equals($this->generateHmac($data), $hmac);
    }

    /**
     * Compress data using gzip
     *
     * @param string $data Data to compress
     * @return string Compressed data
     */
    private function compressData(string $data): string
    {
        // Only compress if data is larger than 1KB
        if (strlen($data) < 1024) {
            return $data;
        }

        $compressed = gzcompress($data, 6);

        // Only use compressed data if it's actually smaller
        if ($compressed !== false && strlen($compressed) < strlen($data)) {
            return $compressed;
        }

        return $data;
    }

    /**
     * Load encryption key from environment
     *
     * @return string Encryption key
     */
    private function loadEncryptionKey(): string
    {
        $key = $_ENV['DRONE_ENCRYPTION_KEY'] ?? '';

        if (empty($key)) {
            // Generate a new key if not set (for development)
            $key = bin2hex(random_bytes(32));
            error_log("Generated new encryption key. Please set DRONE_ENCRYPTION_KEY in environment variables.");
        }

        // Ensure key is exactly 32 bytes (hex encoded = 64 characters)
        if (strlen($key) === 64) {
            return hex2bin($key) ?: $key;
        } elseif (strlen($key) === 32) {
            return $key;
        } else {
            // Hash to get correct length
            return hash('sha256', $key, true);
        }
    }

    /**
     * Load HMAC key from environment
     *
     * @return string HMAC key
     */
    private function loadHmacKey(): string
    {
        $key = $_ENV['DRONE_HMAC_KEY'] ?? $this->encryptionKey;

        if (strlen($key) === 64) {
            return hex2bin($key) ?: $key;
        } elseif (strlen($key) === 32) {
            return $key;
        } else {
            return hash('sha256', $key, true);
        }
    }

    /**
     * Sanitize data for GDPR compliance
     *
     * @param string $data Personal data
     * @return string Sanitized data
     */
    public static function sanitizePersonalData(string $data): string
    {
        // Remove PII patterns (basic implementation)
        $patterns = [
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', // Email
            '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', // Phone
            '/\b\d{3}-\d{2}-\d{4}\b/', // SSN-like
            '/\b(?:0[1-9]|[12][0-9]|3[01])[-.](?:0[1-9]|1[0-2])[-.]\d{4}\b/' // Date
        ];

        $sanitized = $data;
        foreach ($patterns as $pattern) {
            $sanitized = preg_replace($pattern, '[REDACTED]', $sanitized) ?? $sanitized;
        }

        return $sanitized;
    }

    /**
     * Generate secure token
     *
     * @param int $length Token length
     * @return string Secure token
     */
    public static function generateSecureToken(int $length = 32): string
    {
        if ($length < 1) {
            throw new DroneServiceException("Token length must be at least 1");
        }
        return bin2hex(random_bytes($length));
    }

    /**
     * Validate data integrity
     *
     * @param string $data Original data
     * @param string $hash Hash to verify
     * @return bool Validation result
     */
    public static function validateIntegrity(string $data, string $hash): bool
    {
        $calculatedHash = hash('sha256', $data);
        return hash_equals($calculatedHash, $hash);
    }

    /**
     * Securely wipe sensitive data from memory
     *
     * @param string &$data Data to wipe (passed by reference)
     */
    public static function secureWipe(string &$data): void
    {
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $data[$i] = "\0";
        }
        unset($data);
    }

    /**
     * Encrypt sensitive field for database storage
     *
     * @param string $value Sensitive value
     * @return string Encrypted value
     */
    public function encryptField(string $value): string
    {
        $encrypted = $this->encrypt($value);
        if ($encrypted['success']) {
            $result = json_encode($encrypted);
            return $result !== false ? $result : '';
        }
        return '';
    }

    /**
     * Decrypt sensitive field from database
     *
     * @param string $encryptedValue Encrypted field value
     * @return string Decrypted value
     */
    public function decryptField(string $encryptedValue): string
    {
        try {
            $encryptedData = json_decode($encryptedValue, true);
            if (!$encryptedData) {
                return '';
            }

            $decrypted = $this->decrypt(
                $encryptedData['ciphertext'],
                $encryptedData['iv'],
                $encryptedData['tag'],
                $encryptedData['hmac']
            );

            return $decrypted['success'] ? $decrypted['plaintext'] : '';
        } catch (Exception $e) {
            return '';
        }
    }
}
