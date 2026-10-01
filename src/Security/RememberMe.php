<?php

declare(strict_types=1);

namespace ROOTS\Security;

use mysqli;
use Exception;

/**
 * RememberMe
 *
 * Implements secure "Remember Me" functionality using split-token authentication
 * Based on OWASP recommendations for persistent login cookies
 *
 * Security Features:
 * - Split token design (selector + validator)
 * - Hashed validator storage
 * - Token rotation on use
 * - Automatic expiration
 * - Device tracking
 */
class RememberMe
{
    private mysqli $db;
    private SecurityLogger $logger;

    /**
     * Default configuration
     */
    private int $tokenLifetimeDays = 30;
    private string $cookieName = "remember_me";
    private bool $enableRememberMe = true;

    /**
     * Constructor
     *
     * @param mysqli $db Database connection
     * @param SecurityLogger|null $logger Security logger
     */
    public function __construct(mysqli $db, ?SecurityLogger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? new SecurityLogger($db);

        // Load configuration from database if available
        $this->loadConfiguration();
    }

    /**
     * Create a remember me token for user
     *
     * @param int $userId User ID
     * @param string $username Username
     * @param string|null $ipAddress IP address
     * @param string|null $userAgent User agent
     * @return array<string, mixed>|null
     */
    public function createToken(
        int $userId,
        string $username,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ?array {
        if (!$this->enableRememberMe) {
            return null;
        }

        try {
            // Generate cryptographically secure random tokens
            $selector = bin2hex(random_bytes(16)); // 32 chars
            $validator = bin2hex(random_bytes(32)); // 64 chars

            // Hash the validator for storage (NEVER store plaintext)
            $hashedValidator = password_hash($validator, PASSWORD_BCRYPT, [
                "cost" => 12,
            ]);

            // Calculate expiration
            $expiresAt = date(
                "Y-m-d H:i:s",
                time() + $this->tokenLifetimeDays * 24 * 60 * 60,
            );

            // Store in database
            $stmt = $this->db->prepare(
                "INSERT INTO remember_tokens
                (user_id, username, selector, hashed_validator, ip_address, user_agent, expires_at, created_at, is_valid)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), TRUE)",
            );

            if (!$stmt) {
                throw new Exception("Failed to prepare insert statement");
            }

            $stmt->bind_param(
                "issssss",
                $userId,
                $username,
                $selector,
                $hashedValidator,
                $ipAddress,
                $userAgent,
                $expiresAt,
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    "Failed to execute insert: " . $stmt->error,
                );
            }

            $stmt->close();

            // Log token creation
            $this->logger->log(
                SecurityLogger::EVENT_TOKEN_CREATED,
                SecurityLogger::SEVERITY_LOW,
                $userId,
                $username,
                "Remember me token created",
                ["expires_at" => $expiresAt, "ip" => $ipAddress],
            );

            // Combine selector:validator for cookie
            $token = $selector . ":" . $validator;

            return [
                "selector" => $selector,
                "validator" => $validator,
                "token" => $token,
                "expires_at" => $expiresAt,
            ];
        } catch (Exception $e) {
            error_log("RememberMe createToken error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate and authenticate using remember me token
     *
     * @param string $token Token from cookie (selector:validator)
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token): ?array
    {
        if (!$this->enableRememberMe) {
            return null;
        }

        // Clean up expired tokens first
        $this->cleanupExpiredTokens();

        // Split token into selector and validator
        $parts = explode(":", $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$selector, $validator] = $parts;

        // Validate format
        if (strlen($selector) !== 32 || strlen($validator) !== 64) {
            return null;
        }

        try {
            // Fetch token from database
            $stmt = $this->db->prepare(
                "SELECT id, user_id, username, hashed_validator, ip_address, user_agent, expires_at
                FROM remember_tokens
                WHERE selector = ?
                AND is_valid = TRUE
                AND expires_at > NOW()
                LIMIT 1",
            );

            if (!$stmt) {
                return null;
            }

            $stmt->bind_param("s", $selector);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result !== false ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                // Token not found or expired
                $this->logger->logSuspiciousActivity(
                    "Invalid remember me token attempted",
                    null,
                    ["selector" => $selector],
                );
                return null;
            }

            // Verify validator using timing-safe comparison
            if (!password_verify($validator, (string)$row["hashed_validator"])) {
                // Invalid validator - possible token theft!
                $this->revokeAllUserTokens((int) $row["user_id"]);

                $this->logger->log(
                    SecurityLogger::EVENT_SUSPICIOUS_ACTIVITY,
                    SecurityLogger::SEVERITY_CRITICAL,
                    (int) $row["user_id"],
                    (string)$row["username"],
                    "Remember me token validation failed - possible theft detected!",
                    [
                        "selector" => $selector,
                        "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
                        "stored_ip" => $row["ip_address"],
                    ],
                );

                return null;
            }

            // Token is valid - update last used
            $this->updateLastUsed($selector);

            // Optional: Check IP/User-Agent for additional security
            $currentIp = $_SERVER["REMOTE_ADDR"] ?? null;
            $currentUserAgent = $_SERVER["HTTP_USER_AGENT"] ?? null;

            if (
                $row["ip_address"] &&
                $currentIp &&
                $row["ip_address"] !== $currentIp
            ) {
                $this->logger->log(
                    SecurityLogger::EVENT_SUSPICIOUS_ACTIVITY,
                    SecurityLogger::SEVERITY_MEDIUM,
                    (int) $row["user_id"],
                    (string)$row["username"],
                    "Remember me token used from different IP",
                    [
                        "stored_ip" => $row["ip_address"],
                        "current_ip" => $currentIp,
                    ],
                );
            }

            return [
                "user_id" => (int) $row["user_id"],
                "username" => $row["username"],
                "token_id" => (int) $row["id"],
            ];
        } catch (Exception $e) {
            error_log("RememberMe validateToken error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Rotate token (create new, revoke old) - recommended after each use
     *
     * @param string $oldToken Old token
     * @param int $userId User ID
     * @param string $username Username
     * @return array<string, mixed>|null
     */
    public function rotateToken(
        string $oldToken,
        int $userId,
        string $username,
    ): ?array {
        // Revoke old token
        $this->revokeToken($oldToken);

        // Create new token
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? null;
        $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? null;

        return $this->createToken($userId, $username, $ipAddress, $userAgent);
    }

    /**
     * Revoke a specific token
     *
     * @param string $token Token to revoke (selector:validator or just selector)
     * @return bool Success status
     */
    public function revokeToken(string $token): bool
    {
        // Extract selector
        $selector =
            strpos($token, ":") !== false ? explode(":", $token)[0] : $token;

        $stmt = $this->db->prepare(
            "UPDATE remember_tokens SET is_valid = FALSE WHERE selector = ?",
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $selector);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->logger->log(
                SecurityLogger::EVENT_TOKEN_REVOKED,
                SecurityLogger::SEVERITY_LOW,
                null,
                null,
                "Remember me token revoked",
                ["selector" => $selector],
            );
        }

        return $result;
    }

    /**
     * Revoke all tokens for a user
     *
     * @param int $userId User ID
     * @return bool Success status
     */
    public function revokeAllUserTokens(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE remember_tokens SET is_valid = FALSE WHERE user_id = ?",
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("i", $userId);
        $result = $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($result && $affectedRows > 0) {
            $this->logger->log(
                SecurityLogger::EVENT_TOKEN_REVOKED,
                SecurityLogger::SEVERITY_MEDIUM,
                $userId,
                null,
                "All remember me tokens revoked for user",
                ["tokens_revoked" => $affectedRows],
            );
        }

        return $result;
    }

    /**
     * Set remember me cookie
     *
     * @param string $token Token value (selector:validator)
     * @param int|null $expiresTimestamp Expiration timestamp (optional)
     * @return bool Success status
     */
    public function setCookie(
        string $token,
        ?int $expiresTimestamp = null,
    ): bool {
        if ($expiresTimestamp === null) {
            $expiresTimestamp =
                time() + $this->tokenLifetimeDays * 24 * 60 * 60;
        }

        // Cookie options for security
        $options = [
            "expires" => $expiresTimestamp,
            "path" => "/",
            "domain" => "", // Current domain
            "secure" => isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on",
            "httponly" => true,
            "samesite" => "Strict",
        ];

        return setcookie($this->cookieName, $token, $options);
    }

    /**
     * Get remember me token from cookie
     *
     * @return string|null Token or null if not found
     */
    public function getCookie(): ?string
    {
        return $_COOKIE[$this->cookieName] ?? null;
    }

    /**
     * Delete remember me cookie
     *
     * @return bool Success status
     */
    public function deleteCookie(): bool
    {
        if (isset($_COOKIE[$this->cookieName])) {
            $options = [
                "expires" => time() - 3600,
                "path" => "/",
                "domain" => "",
                "secure" =>
                    isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on",
                "httponly" => true,
                "samesite" => "Strict",
            ];

            setcookie($this->cookieName, "", $options);
            unset($_COOKIE[$this->cookieName]);
            return true;
        }

        return false;
    }

    /**
     * Get all active tokens for user
     *
     * @param int $userId User ID
     * @return array<int, array<string, mixed>>
     */
    public function getUserTokens(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, selector, ip_address, user_agent, created_at, last_used, expires_at
            FROM remember_tokens
            WHERE user_id = ?
            AND is_valid = TRUE
            AND expires_at > NOW()
            ORDER BY last_used DESC",
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $tokens = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
            $tokens[] = [
                "id" => (int) $row["id"],
                "selector" => $row["selector"],
                "ip_address" => $row["ip_address"],
                "user_agent" => $row["user_agent"],
                "created_at" => $row["created_at"],
                "last_used" => $row["last_used"],
                "expires_at" => $row["expires_at"],
                "is_current" => $this->isCurrentDevice(
                    (string)$row["ip_address"],
                    (string)$row["user_agent"],
                ),
            ];
        }
        }

        $stmt->close();

        return $tokens;
    }

    /**
     * Update last used timestamp
     *
     * @param string $selector Token selector
     * @return bool Success status
     */
    private function updateLastUsed(string $selector): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE remember_tokens SET last_used = NOW() WHERE selector = ?",
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $selector);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Clean up expired tokens
     *
     * @return int Number of tokens deleted
     */
    public function cleanupExpiredTokens(): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM remember_tokens WHERE expires_at < NOW()",
        );

        if (!$stmt) {
            return 0;
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return (int)$affected;
    }

    /**
     * Check if token is from current device
     *
     * @param string|null $storedIp Stored IP address
     * @param string|null $storedUserAgent Stored user agent
     * @return bool True if current device
     */
    private function isCurrentDevice(
        ?string $storedIp,
        ?string $storedUserAgent,
    ): bool {
        $currentIp = $_SERVER["REMOTE_ADDR"] ?? null;
        $currentUserAgent = $_SERVER["HTTP_USER_AGENT"] ?? null;

        return $storedIp === $currentIp &&
            $storedUserAgent === $currentUserAgent;
    }

    /**
     * Load configuration from database
     *
     * @return void
     */
    private function loadConfiguration(): void
    {
        $stmt = $this->db->prepare(
            "SELECT config_key, config_value FROM security_config
            WHERE config_key IN ('remember_me_duration_days', 'enable_remember_me')",
        );

        if (!$stmt) {
            return;
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
            $key = $row["config_key"];
            $value = $row["config_value"];

            if ($key === "remember_me_duration_days") {
                $this->tokenLifetimeDays = (int) $value;
            } elseif ($key === "enable_remember_me") {
                $this->enableRememberMe = $value === "true";
            }
        }
        }

        $stmt->close();
    }

    /**
     * Set configuration
     *
     * @param int $tokenLifetimeDays Token lifetime in days
     * @param string $cookieName Cookie name
     * @return void
     */
    public function setConfiguration(
        int $tokenLifetimeDays,
        string $cookieName = "remember_me",
    ): void {
        $this->tokenLifetimeDays = $tokenLifetimeDays;
        $this->cookieName = $cookieName;
    }

    /**
     * Get configuration
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return [
            "token_lifetime_days" => $this->tokenLifetimeDays,
            "cookie_name" => $this->cookieName,
            "enable_remember_me" => $this->enableRememberMe,
        ];
    }

    /**
     * Check if remember me is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enableRememberMe;
    }
}
