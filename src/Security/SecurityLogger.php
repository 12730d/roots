<?php

declare(strict_types=1);

namespace ROOTS\Security;

use mysqli;
use Exception;

/**
 * SecurityLogger
 *
 * Handles logging of security events to database and system logs
 * Supports multiple severity levels and event types
 */
class SecurityLogger
{
    private mysqli $db;
    private bool $logToDatabase = true;
    private bool $logToSyslog = true;

    /**
     * Event types
     */
    public const EVENT_LOGIN_SUCCESS = "login_success";
    public const EVENT_LOGIN_FAILED = "login_failed";
    public const EVENT_LOGIN_BLOCKED = "login_blocked";
    public const EVENT_ACCOUNT_LOCKED = "account_locked";
    public const EVENT_ACCOUNT_UNLOCKED = "account_unlocked";
    public const EVENT_PASSWORD_CHANGED = "password_changed";
    public const EVENT_EMAIL_CHANGED = "email_changed";
    public const EVENT_SUSPICIOUS_ACTIVITY = "suspicious_activity";
    public const EVENT_TOKEN_CREATED = "token_created";
    public const EVENT_TOKEN_REVOKED = "token_revoked";
    public const EVENT_SESSION_HIJACK = "session_hijack_attempt";
    public const EVENT_CSRF_VIOLATION = "csrf_violation";
    public const EVENT_RATE_LIMIT_EXCEEDED = "rate_limit_exceeded";

    /**
     * Severity levels
     */
    public const SEVERITY_LOW = "low";
    public const SEVERITY_MEDIUM = "medium";
    public const SEVERITY_HIGH = "high";
    public const SEVERITY_CRITICAL = "critical";

    /**
     * Constructor
     *
     * @param mysqli $db Database connection
     * @param bool $logToDatabase Enable database logging
     * @param bool $logToSyslog Enable syslog logging
     */
    public function __construct(
        mysqli $db,
        bool $logToDatabase = true,
        bool $logToSyslog = true,
    ) {
        $this->db = $db;
        $this->logToDatabase = $logToDatabase;
        $this->logToSyslog = $logToSyslog;
    }

    /**
     * Log a security event
     *
     * @param string $eventType Event type (use class constants)
     * @param string $severity Severity level
     * @param int|null $userId User ID (optional)
     * @param string|null $username Username (optional)
     * @param string|null $description Event description
     * @param array<string, mixed>|null $metadata Additional metadata
     * @return bool Success status
     */
    public function log(
        string $eventType,
        string $severity = self::SEVERITY_MEDIUM,
        ?int $userId = null,
        ?string $username = null,
        ?string $description = null,
        ?array $metadata = null,
    ): bool {
        $success = true;

        // Log to database
        if ($this->logToDatabase) {
            try {
                if (!$this->logToDatabase(
                    $eventType,
                    $severity,
                    $userId,
                    $username,
                    $description,
                    $metadata,
                )) {
                    $success = false;
                }
            } catch (Exception $e) {
                error_log("SecurityLogger DB Error: " . $e->getMessage());
                $success = false;
            }
        }

        // Log to syslog
        if ($this->logToSyslog) {
            $this->logToSyslog(
                $eventType,
                $severity,
                $userId,
                $username,
                $description,
            );
        }

        return $success;
    }

    /**
     * Log login attempt
     *
     * @param string $username Username
     * @param string $ipAddress IP address
     * @param bool $success Success status
     * @param string|null $failureReason Failure reason
     * @param string|null $userAgent User agent
     * @return bool
     */
    public function logLoginAttempt(
        string $username,
        string $ipAddress,
        bool $success,
        ?string $failureReason = null,
        ?string $userAgent = null,
    ): bool {
        $attemptType = $success ? "success" : "failed";

        $stmt = $this->db->prepare(
            "INSERT INTO login_attempts
            (username, ip_address, user_agent, attempt_type, failure_reason, attempted_at)
            VALUES (?, ?, ?, ?, ?, NOW())",
        );

        if (!$stmt) {
            error_log(
                "Failed to prepare login_attempts insert: " . $this->db->error,
            );
            return false;
        }

        $stmt->bind_param(
            "sssss",
            $username,
            $ipAddress,
            $userAgent,
            $attemptType,
            $failureReason,
        );
        $result = $stmt->execute();
        $stmt->close();

        // Also log as security event if failed
        if (!$success) {
            $this->log(
                self::EVENT_LOGIN_FAILED,
                self::SEVERITY_MEDIUM,
                null,
                $username,
                "Failed login from IP: $ipAddress. Reason: $failureReason",
                ["ip" => $ipAddress, "user_agent" => $userAgent],
            );
        }

        return $result;
    }

    /**
     * Log successful login
     *
     * @param int $userId User ID
     * @param string $username Username
     * @param string $ipAddress IP address
     * @param string|null $userAgent User agent
     * @return bool
     */
    public function logSuccessfulLogin(
        int $userId,
        string $username,
        string $ipAddress,
        ?string $userAgent = null,
    ): bool {
        // Log to login_attempts
        $this->logLoginAttempt($username, $ipAddress, true, null, $userAgent);

        // Log to security_events
        return $this->log(
            self::EVENT_LOGIN_SUCCESS,
            self::SEVERITY_LOW,
            $userId,
            $username,
            "Successful login from IP: $ipAddress",
            ["ip" => $ipAddress, "user_agent" => $userAgent],
        );
    }

    /**
     * Log account lockout
     *
     * @param int $userId User ID
     * @param string $username Username
     * @param int $failedAttempts Number of failed attempts
     * @param string $lockedUntil Locked until datetime
     * @param string $ipAddress IP address
     * @return bool
     */
    public function logAccountLockout(
        int $userId,
        string $username,
        int $failedAttempts,
        string $lockedUntil,
        string $ipAddress,
    ): bool {
        return $this->log(
            self::EVENT_ACCOUNT_LOCKED,
            self::SEVERITY_HIGH,
            $userId,
            $username,
            "Account locked until $lockedUntil after $failedAttempts failed attempts",
            [
                "failed_attempts" => $failedAttempts,
                "locked_until" => $lockedUntil,
                "ip" => $ipAddress,
            ],
        );
    }

    /**
     * Log suspicious activity
     *
     * @param string $description Description
     * @param string|null $username Username
     * @param array<string, mixed>|null $metadata Metadata
     * @return bool
     */
    public function logSuspiciousActivity(
        string $description,
        ?string $username = null,
        ?array $metadata = null,
    ): bool {
        return $this->log(
            self::EVENT_SUSPICIOUS_ACTIVITY,
            self::SEVERITY_HIGH,
            null,
            $username,
            $description,
            $metadata,
        );
    }

    /**
     * Log rate limit exceeded
     *
     * @param string $ipAddress IP address
     * @param int $attempts Number of attempts
     * @param string|null $username Username
     * @return bool
     */
    public function logRateLimitExceeded(
        string $ipAddress,
        int $attempts,
        ?string $username = null,
    ): bool {
        return $this->log(
            self::EVENT_RATE_LIMIT_EXCEEDED,
            self::SEVERITY_HIGH,
            null,
            $username,
            "Rate limit exceeded from IP: $ipAddress ($attempts attempts)",
            ["ip" => $ipAddress, "attempts" => $attempts],
        );
    }

    /**
     * Get recent failed login attempts for user
     *
     * @param string $username Username
     * @param int $minutes Time window in minutes
     * @return int Number of failed attempts
     */
    public function getRecentFailedAttempts(
        string $username,
        int $minutes = 30,
    ): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM login_attempts
            WHERE username = ?
            AND attempt_type = 'failed'
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        );

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("si", $username, $minutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return (int) ($row["count"] ?? 0);

    }

    /**
     * Get failed attempts from IP
     *
     * @param string $ipAddress IP address
     * @param int $minutes Time window in minutes
     * @return int Number of attempts
     */
    public function getIpFailedAttempts(
        string $ipAddress,
        int $minutes = 60,
    ): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM login_attempts
            WHERE ip_address = ?
            AND attempt_type IN ('failed', 'blocked')
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        );

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("si", $ipAddress, $minutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return (int) ($row["count"] ?? 0);
    }

    /**
     * Clear old login attempts
     *
     * @param int $days Number of days to keep
     * @return bool
     */
    public function cleanupOldAttempts(int $days = 90): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("i", $days);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Log to database
     *
     * @param string $eventType Event type
     * @param string $severity Severity
     * @param int|null $userId User ID
     * @param string|null $username Username
     * @param string|null $description Description
     * @param array<string, mixed>|null $metadata Metadata
     * @return bool
     */
    private function logToDatabase(
        string $eventType,
        string $severity,
        ?int $userId,
        ?string $username,
        ?string $description,
        ?array $metadata,
    ): bool {
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? null;
        $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? null;

        // Sanitize user agent
        if ($userAgent) {
            $userAgent = substr($userAgent, 0, 500);
        }

        $metadataJson = null;
        if ($metadata !== null) {
            $metadataJson = json_encode($metadata);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO security_events
            (user_id, username, event_type, severity, ip_address, user_agent, description, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        );

        if (!$stmt) {
            error_log(
                "Failed to prepare security_events insert: " . $this->db->error,
            );
            return false;
        }

        $stmt->bind_param(
            "isssssss",
            $userId,
            $username,
            $eventType,
            $severity,
            $ipAddress,
            $userAgent,
            $description,
            $metadataJson,
        );

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Log to syslog
     *
     * @param string $eventType Event type
     * @param string $severity Severity
     * @param int|null $userId User ID
     * @param string|null $username Username
     * @param string|null $description Description
     * @return void
     */
    private function logToSyslog(
        string $eventType,
        string $severity,
        ?int $userId,
        ?string $username,
        ?string $description,
    ): void {
        if (!function_exists("syslog")) {
            return;
        }

        $priority = match ($severity) {
            self::SEVERITY_CRITICAL => LOG_CRIT,
            self::SEVERITY_HIGH => LOG_WARNING,
            self::SEVERITY_MEDIUM => LOG_NOTICE,
            self::SEVERITY_LOW => LOG_INFO,
            default => LOG_INFO,
        };

        $message = sprintf(
            "[SECURITY] %s | Type: %s | User: %s (ID: %s) | IP: %s | %s",
            strtoupper($severity),
            $eventType,
            $username ?? "N/A",
            $userId ?? "N/A",
            $_SERVER["REMOTE_ADDR"] ?? "N/A",
            $description ?? "",
        );

        openlog("ROOTS_security", LOG_PID | LOG_PERROR, LOG_AUTH);
        syslog($priority, $message);
        closelog();
    }
}
