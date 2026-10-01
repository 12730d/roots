<?php

declare(strict_types=1);

namespace ROOTS\Security;

use mysqli;
use Exception;

/**
 * FailedLoginProtection
 *
 * Enhanced Brute Force Protection System
 *
 * Implements OWASP best practices for preventing credential stuffing and brute force attacks:
 * - Progressive delays between attempts
 * - Exponential backoff strategy
 * - Account-level and IP-level rate limiting
 * - Suspicious activity detection (multi-IP, rapid-fire attempts)
 * - CAPTCHA requirement escalation
 * - Security notifications and admin alerts
 * - Comprehensive audit logging
 */
class FailedLoginProtection
{
    private mysqli $db;
    private SecurityLogger $logger;

    // =========== Account Protection Settings ===========
    private int $maxFailedAttempts = 5;
    private int $lockoutDurationMinutes = 30;
    private int $permanentLockoutThreshold = 15;

    // =========== CAPTCHA Settings ===========
    private int $captchaRequiredAfterAttempts = 3;
    private bool $enableCaptchaProgression = true;

    // =========== Progressive Delay Settings ===========
    private int $baseDelayMilliseconds = 100;
    private float $delayMultiplier = 1.5;
    private int $maxDelaySeconds = 30;

    public function __construct(mysqli $db, ?SecurityLogger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? new SecurityLogger($db);
    }

    /**
     * Evaluate a failed login attempt.
     *
     * @param string $username Username attempted.
     * @param string $ipAddress Client IP address.
     * @param string|null $userAgent Browser/client user agent.
     * @param int|null $userId User ID if known.
     * @return array<string, mixed> Analysis result with action recommendations.
     */
    public function evaluateFailedAttempt(
        string $username,
        string $ipAddress,
        ?string $userAgent = null,
        ?int $userId = null
    ): array {
        try {
            if ($userId === null) {
                $stmt = $this->db->prepare("SELECT id FROM login WHERE username = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        $userId = (int)$row['id'];
                    }
                    $stmt->close();
                }
            }

            // 1. Check account status
            $accountStatus = $this->checkAccountStatus($username);
            if ($accountStatus['is_locked']) {
                return [
                    'should_block' => true,
                    'reason' => 'account_locked',
                    'locked_until' => $accountStatus['locked_until'],
                    'is_permanent' => $accountStatus['is_permanent'],
                    'delay_seconds' => 0,
                    'requires_captcha' => false,
                    'attempts_remaining' => 0,
                    'is_suspicious' => false,
                    'account_attempts' => 0,
                    'ip_attempts_15m' => 0,
                    'message' => $accountStatus['is_permanent']
                        ? 'Account permanently locked. Contact support.'
                        : 'Account temporarily locked. Try again later.'
                ];
            }

            // 2. Check IP status
            $ipStatus = $this->checkIpStatus($ipAddress);
            if ($ipStatus['is_blocked']) {
                $this->logger->log(
                    'login_blocked',
                    SecurityLogger::SEVERITY_HIGH,
                    $userId,
                    $username,
                    'Login attempt from blocked IP (rate limit exceeded)',
                    ['ip' => $ipAddress, 'blocked_until' => $ipStatus['blocked_until']]
                );

                return [
                    'should_block' => true,
                    'reason' => 'ip_blocked',
                    'blocked_until' => $ipStatus['blocked_until'],
                    'delay_seconds' => 0,
                    'requires_captcha' => false,
                    'attempts_remaining' => 0,
                    'is_suspicious' => false,
                    'account_attempts' => 0,
                    'ip_attempts_15m' => (int)$ipStatus['attempts_15m'],
                    'message' => 'Too many failed attempts from this IP. Please try again later.'
                ];
            }

            // 3. Detect suspicious patterns
            $suspiciousCheck = $this->detectSuspiciousActivity($username, $ipAddress, $userAgent);
            if ($suspiciousCheck['is_suspicious']) {
                $this->triggerSecurityAlert($username, $userId, $ipAddress, (string)$suspiciousCheck['reason']);
            }

            // 4. Record the failed attempt
            $attemptData = $this->recordFailedAttempt(
                $username,
                $ipAddress,
                $userAgent,
                $userId,
                (bool)$suspiciousCheck['is_suspicious']
            );

            // 5. Calculate progressive delay
            $delay = $this->calculateProgressiveDelay((int)$attemptData['account_attempts']);

            // 6. Determine if CAPTCHA is required
            $requiresCaptcha = $this->shouldRequireCaptcha((int)$attemptData['account_attempts']);

            // 7. Check if account should be locked
            $shouldLockAccount = $this->shouldLockAccount(
                (int)$attemptData['account_attempts'],
                (int)$attemptData['total_lockouts']
            );

            if ($shouldLockAccount['should_lock']) {
                $this->lockAccount(
                    $username,
                    $userId,
                    (bool)$shouldLockAccount['is_permanent'],
                    $ipAddress
                );
            }

            return [
                'should_block' => $shouldLockAccount['should_lock'],
                'reason' => $shouldLockAccount['should_lock'] ? 'account_locked_after_attempts' : 'continue',
                'delay_seconds' => $delay,
                'requires_captcha' => $requiresCaptcha,
                'attempts_remaining' => max(0, $this->maxFailedAttempts - (int)$attemptData['account_attempts']),
                'is_suspicious' => $suspiciousCheck['is_suspicious'],
                'account_attempts' => $attemptData['account_attempts'],
                'ip_attempts_15m' => $ipStatus['attempts_15m'],
                'message' => 'Invalid credentials. Please try again.'
            ];

        } catch (Exception $e) {
            $this->logger->log(
                'suspicious_activity',
                SecurityLogger::SEVERITY_HIGH,
                $userId,
                $username,
                'Error evaluating failed login attempt: ' . $e->getMessage(),
                ['error' => $e->getMessage(), 'ip' => $ipAddress]
            );

            return [
                'should_block' => true,
                'reason' => 'evaluation_error',
                'delay_seconds' => 0,
                'requires_captcha' => false,
                'attempts_remaining' => 0,
                'is_suspicious' => false,
                'account_attempts' => 0,
                'ip_attempts_15m' => 0,
                'message' => 'Security check failed. Please try again later.'
            ];
        }
    }

    /**
     * Check if account is locked
     *
     * @return array<string, mixed>
     */
    private function checkAccountStatus(string $username): array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, failed_attempts, locked_until, is_permanently_locked,
                    total_lockouts, last_failed_attempt
             FROM account_lockouts
             WHERE username = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return ['is_locked' => false, 'locked_until' => null, 'is_permanent' => false];
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return ['is_locked' => false, 'locked_until' => null, 'is_permanent' => false];
        }

        // Check if lockout period has expired
        if ($row['locked_until'] && strtotime((string)$row['locked_until']) > time()) {
            return [
                'is_locked' => true,
                'locked_until' => $row['locked_until'],
                'is_permanent' => (bool)$row['is_permanently_locked']
            ];
        }

        if ($row['is_permanently_locked']) {
            return [
                'is_locked' => true,
                'locked_until' => null,
                'is_permanent' => true
            ];
        }

        return ['is_locked' => false, 'locked_until' => null, 'is_permanent' => false];
    }

    /**
     * Check if IP is blocked
     *
     * @return array<string, mixed>
     */
    private function checkIpStatus(string $ipAddress): array
    {
        $this->cleanupExpiredBlocks();

        $stmt = $this->db->prepare(
            "SELECT id, failed_attempts, blocked_until, total_blocks,
                    last_attempt, first_attempt
             FROM ip_rate_limits
             WHERE ip_address = ?
             AND (blocked_until IS NULL OR blocked_until > NOW())
             LIMIT 1"
        );

        if (!$stmt) {
            return [
                'is_blocked' => false,
                'blocked_until' => null,
                'attempts_15m' => 0,
                'attempts_5m' => 0
            ];
        }

        $stmt->bind_param('s', $ipAddress);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return [
                'is_blocked' => false,
                'blocked_until' => null,
                'attempts_15m' => 0,
                'attempts_5m' => 0
            ];
        }

        $isBlocked = $row['blocked_until'] && strtotime((string)$row['blocked_until']) > time();

        return [
            'is_blocked' => $isBlocked,
            'blocked_until' => $row['blocked_until'],
            'attempts_15m' => (int)$row['failed_attempts'],
            'attempts_5m' => $this->getAttemptCountLastNMinutes($ipAddress, 5)
        ];
    }

    /**
     * Detect suspicious activity patterns
     *
     * @return array<string, mixed>
     */
    private function detectSuspiciousActivity(
        string $username,
        string $ipAddress,
        ?string $userAgent = null
    ): array {
        try {
            // Pattern 1: Multiple IPs targeting same username in short time
            $multiIpAttempts = $this->getMultiIpAttemptsLastNMinutes($username, 5);
            if ($multiIpAttempts > 3) {
                return [
                    'is_suspicious' => true,
                    'reason' => 'multi_ip_attacks',
                    'details' => "Username targeted from {$multiIpAttempts} different IPs in 5 minutes (credential stuffing)"
                ];
            }

            // Pattern 2: Rapid-fire attempts from same IP+username
            $fastAttempts = $this->getAttemptCountLastNMinutes($ipAddress, 2, $username);
            if ($fastAttempts > 2) {
                return [
                    'is_suspicious' => true,
                    'reason' => 'rapid_attempts',
                    'details' => "Fast login attempts: {$fastAttempts} in 2 minutes"
                ];
            }

            // Pattern 3: Known bot/scanner patterns
            if ($userAgent) {
                $botCheck = $this->detectBotPatterns($userAgent);
                if ($botCheck['is_bot']) {
                    return [
                        'is_suspicious' => true,
                        'reason' => 'scanner_detected',
                        'details' => 'Automated tool detected: ' . $botCheck['bot_type']
                    ];
                }
            }

            return [
                'is_suspicious' => false,
                'reason' => null,
                'details' => null
            ];

        } catch (Exception $e) {
            $this->logger->log(
                'suspicious_activity',
                SecurityLogger::SEVERITY_LOW,
                null,
                $username,
                'Error detecting suspicious activity: ' . $e->getMessage(),
                ['ip' => $ipAddress]
            );
            return [
                'is_suspicious' => false,
                'reason' => null,
                'details' => null
            ];
        }
    }

    /**
     * Calculate progressive delay (in seconds)
     *
     * Implements exponential backoff: 100ms, 150ms, 225ms, 337ms, etc.
     */
    private function calculateProgressiveDelay(int $attemptCount): int
    {
        if ($attemptCount <= 0) {
            return 0;
        }

        $delayMs = $this->baseDelayMilliseconds * pow($this->delayMultiplier, $attemptCount - 1);
        $delaySeconds = (int)ceil($delayMs / 1000);

        return min($delaySeconds, $this->maxDelaySeconds);
    }

    /**
     * Determine if CAPTCHA should be required
     */
    private function shouldRequireCaptcha(int $attemptCount): bool
    {
        return $this->enableCaptchaProgression && $attemptCount >= $this->captchaRequiredAfterAttempts;
    }

    /**
     * Determine if account should be locked
     *
     * @return array<string, mixed>
     */
    private function shouldLockAccount(
        int $currentAttempts,
        int $totalLockouts
    ): array {
        // Permanent lock if threshold exceeded
        if ($totalLockouts >= $this->permanentLockoutThreshold) {
            return ['should_lock' => true, 'is_permanent' => true];
        }

        // Temporary lock if attempts threshold reached
        if ($currentAttempts >= $this->maxFailedAttempts) {
            return ['should_lock' => true, 'is_permanent' => false];
        }

        return ['should_lock' => false, 'is_permanent' => false];
    }

    /**
     * Record a failed login attempt
     *
     * @return array<string, mixed>
     */
    private function recordFailedAttempt(
        string $username,
        string $ipAddress,
        ?string $userAgent,
        ?int $userId,
        bool $isSuspicious
    ): array {
        $this->db->begin_transaction();

        try {
            $totalLockouts = 0;
            $accountAttempts = 0;

            if ($userId !== null) {
                // Update account record
                $stmt = $this->db->prepare(
                    "SELECT id, failed_attempts, total_lockouts FROM account_lockouts
                     WHERE username = ? LIMIT 1"
                );
                if ($stmt) {
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result !== false ? $result->fetch_assoc() : null;
                    $stmt->close();

                    if ($row) {
                        $attempts = (int)$row['failed_attempts'] + 1;
                        $updateStmt = $this->db->prepare(
                            "UPDATE account_lockouts
                             SET failed_attempts = ?, last_failed_attempt = NOW()
                             WHERE username = ?"
                        );
                        if ($updateStmt) {
                            $updateStmt->bind_param('is', $attempts, $username);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }

                        $totalLockouts = (int)$row['total_lockouts'];
                        $accountAttempts = $attempts;
                    } else {
                        // Create new record
                        $insertStmt = $this->db->prepare(
                            "INSERT INTO account_lockouts
                             (user_id, username, failed_attempts, locked_by_ip, first_failed_attempt, last_failed_attempt)
                             VALUES (?, ?, 1, ?, NOW(), NOW())"
                        );
                        if ($insertStmt) {
                            $insertStmt->bind_param('iss', $userId, $username, $ipAddress);
                            $insertStmt->execute();
                            $insertStmt->close();
                        }

                        $totalLockouts = 0;
                        $accountAttempts = 1;
                    }
                }
            }

            // Record in login_attempts table
            $insertAttemptStmt = $this->db->prepare(
                "INSERT INTO login_attempts (username, ip_address, user_agent, attempt_type, failure_reason, attempted_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($insertAttemptStmt) {
                $attemptType = $isSuspicious ? 'blocked' : 'failed';
                $failureReason = 'failed_login_attempt';
                $insertAttemptStmt->bind_param('sssss', $username, $ipAddress, $userAgent, $attemptType, $failureReason);
                $insertAttemptStmt->execute();
                $insertAttemptStmt->close();
            }

            // Log the event
            $this->logger->log(
                'login_failed',
                SecurityLogger::SEVERITY_MEDIUM,
                $userId,
                $username,
                'Failed login attempt recorded',
                [
                    'ip' => $ipAddress,
                    'user_agent' => substr($userAgent ?? '', 0, 255),
                    'is_suspicious' => $isSuspicious,
                    'attempt_number' => $accountAttempts
                ]
            );

            $this->db->commit();

            return [
                'account_attempts' => $accountAttempts,
                'total_lockouts' => $totalLockouts,
                'ip_recorded' => true
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Lock an account (temporary or permanent)
     */
    private function lockAccount(
        string $username,
        ?int $userId,
        bool $isPermanent,
        string $ipAddress
    ): void {
        if ($isPermanent) {
            $stmt = $this->db->prepare(
                "UPDATE account_lockouts
                 SET is_permanently_locked = TRUE, lockout_reason = 'multiple_lockouts', locked_by_ip = ?
                 WHERE username = ?"
            );
            if ($stmt) {
                $stmt->bind_param('ss', $ipAddress, $username);
                $stmt->execute();
                $stmt->close();
            }

            $this->logger->log(
                'account_locked',
                SecurityLogger::SEVERITY_CRITICAL,
                $userId,
                $username,
                'Account permanently locked - multiple lockout threshold exceeded',
                ['ip' => $ipAddress]
            );

            $this->sendSecurityNotification(
                $username,
                $userId,
                'account_permanently_locked',
                ['reason' => 'Multiple failed login attempts from different locations']
            );
        } else {
            $lockedUntil = date('Y-m-d H:i:s', time() + ($this->lockoutDurationMinutes * 60));
            $stmt = $this->db->prepare(
                "UPDATE account_lockouts
                 SET locked_until = ?, lockout_reason = 'max_failed_attempts', locked_by_ip = ?, total_lockouts = total_lockouts + 1
                 WHERE username = ?"
            );
            if ($stmt) {
                $stmt->bind_param('sss', $lockedUntil, $ipAddress, $username);
                $stmt->execute();
                $stmt->close();
            }

            $this->logger->log(
                'account_locked',
                SecurityLogger::SEVERITY_HIGH,
                $userId,
                $username,
                'Account temporarily locked',
                ['ip' => $ipAddress, 'locked_until' => $lockedUntil]
            );

            $this->sendSecurityNotification(
                $username,
                $userId,
                'account_locked',
                ['locked_until' => $lockedUntil]
            );
        }
    }

    /**
     * Send security notification to user
     *
     * @param array<string, mixed> $data
     */
    private function sendSecurityNotification(
        string $username,
        ?int $userId,
        string $type,
        array $data
    ): void {
        try {
            $message = $this->buildNotificationMessage($type, $data);

            $stmt = $this->db->prepare(
                "INSERT INTO security_notifications (user_id, username, type, message, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('isss', $userId, $username, $type, $message);
                $stmt->execute();
                $stmt->close();
            }

        } catch (Exception $e) {
            error_log("Failed to send security notification: " . $e->getMessage());
            $this->logger->log(
                'system_error',
                SecurityLogger::SEVERITY_MEDIUM,
                $userId,
                $username,
                "Failed to send security notification: " . $e->getMessage()
            );
        }
    }

    /**
     * Trigger security alert for administrators
     */
    private function triggerSecurityAlert(
        string $username,
        ?int $userId,
        string $ipAddress,
        string $reason
    ): void {
        try {
            $alertType = 'suspicious_login_activity';
            $stmt = $this->db->prepare(
                "INSERT INTO security_alerts (user_id, username, ip_address, alert_type, reason, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('issss', $userId, $username, $ipAddress, $alertType, $reason);
                $stmt->execute();
                $stmt->close();
            }

            $this->logger->log(
                'suspicious_activity',
                SecurityLogger::SEVERITY_HIGH,
                $userId,
                $username,
                "Suspicious activity: {$reason}",
                ['ip' => $ipAddress]
            );
        } catch (Exception $e) {
            error_log("Failed to trigger security alert: " . $e->getMessage());
            $this->logger->log(
                'system_error',
                SecurityLogger::SEVERITY_HIGH,
                $userId,
                $username,
                "Failed to trigger security alert: " . $e->getMessage(),
                ['ip' => $ipAddress, 'reason' => $reason]
            );
        }
    }

    /**
     * Detect known bot/scanner patterns
     *
     * @return array<string, mixed>
     */
    private function detectBotPatterns(string $userAgent): array
    {
        $suspiciousBotPatterns = [
            'sqlmap' => 'SQL Injection Scanner',
            'nmap' => 'Network Scanner',
            'nikto' => 'Web Server Scanner',
            'masscan' => 'Network Scanner',
            'metasploit' => 'Penetration Testing',
            'aircrack' => 'Wireless Cracker',
            'hashcat' => 'Hash Cracker',
            'hydra' => 'Credential Brute Force Tool',
            'wireshark' => 'Network Sniffer',
            'burp' => 'Web Proxy Scanner',
            'zaproxy' => 'Security Scanner',
        ];

        $userAgentLower = strtolower($userAgent);
        foreach ($suspiciousBotPatterns as $pattern => $botType) {
            if (stripos($userAgentLower, $pattern) !== false) {
                return ['is_bot' => true, 'bot_type' => $botType];
            }
        }

        return ['is_bot' => false, 'bot_type' => null];
    }

    /**
     * Build notification message
     *
     * @param array<string, mixed> $data
     */
    private function buildNotificationMessage(string $type, array $data): string
    {
        return match($type) {
            'account_locked' => 'Your account has been temporarily locked due to multiple failed login attempts. '
                . 'It will be automatically unlocked at ' . ($data['locked_until'] ?? 'a later time') . '.',
            'account_permanently_locked' => 'Your account has been permanently locked due to security concerns. '
                . 'Please contact support to restore access.',
            'suspicious_activity' => 'Suspicious login activity detected on your account. '
                . 'If this was not you, please change your password immediately.',
            default => 'Security alert triggered on your account.'
        };
    }

    // ============ Helper Methods ============

    private function getAttemptCountLastNMinutes(
        string $ipAddress,
        int $minutes,
        ?string $username = null
    ): int {
        $query = "SELECT COUNT(*) as count FROM login_attempts
                  WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)";

        if ($username) {
            $query .= " AND username = ?";
        }

        $stmt = $this->db->prepare($query);
        if (!$stmt) return 0;

        if ($username) {
            $stmt->bind_param('sis', $ipAddress, $minutes, $username);
        } else {
            $stmt->bind_param('si', $ipAddress, $minutes);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    private function getMultiIpAttemptsLastNMinutes(string $username, int $minutes): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT ip_address) as count FROM login_attempts
             WHERE username = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        if (!$stmt) return 0;

        $stmt->bind_param('si', $username, $minutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    private function cleanupExpiredBlocks(): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ip_rate_limits
             SET failed_attempts = 0, blocked_until = NULL
             WHERE blocked_until IS NOT NULL AND blocked_until < NOW()"
        );
        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }
    }
}
