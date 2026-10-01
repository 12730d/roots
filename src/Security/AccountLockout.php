<?php

declare(strict_types=1);

namespace ROOTS\Security;

use mysqli;
use Exception;

/**
 * AccountLockout
 *
 * Manages account-level lockout after failed login attempts
 * Supports automatic unlocking and permanent lockout
 */
class AccountLockout
{
    private mysqli $db;
    private SecurityLogger $logger;

    /**
     * Default configuration
     */
    private int $maxFailedAttempts = 5;
    private int $lockoutDurationMinutes = 30;
    private int $permanentLockoutThreshold = 10;
    private bool $enableAccountLockout = true;

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
     * Check if account is locked
     *
     * @param string $username Username
     * @return array<string, mixed>
     */
    public function isLocked(string $username): array
    {
        // Clean up expired locks first
        $this->unlockExpiredAccounts();

        $stmt = $this->db->prepare(
            "SELECT locked_until, lockout_reason, is_permanently_locked, failed_attempts
            FROM account_lockouts
            WHERE username = ?
            AND (locked_until > NOW() OR is_permanently_locked = TRUE)
            LIMIT 1"
        );

        if (!$stmt) {
            return [
                'locked' => false,
                'locked_until' => null,
                'is_permanent' => false,
                'reason' => null
            ];
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $isPermanent = (bool) $row['is_permanently_locked'];
            $lockedUntil = $row['locked_until'];

            return [
                'locked' => true,
                'locked_until' => $lockedUntil,
                'is_permanent' => $isPermanent,
                'reason' => $row['lockout_reason'],
                'failed_attempts' => (int) $row['failed_attempts']
            ];
        }

        return [
            'locked' => false,
            'locked_until' => null,
            'is_permanent' => false,
            'reason' => null
        ];
    }

    /**
     * Record failed login attempt
     *
     * @param string $username Username
     * @param string|null $ipAddress IP address
     * @return array<string, mixed>
     */
    public function recordFailedAttempt(
        string $username,
        ?string $ipAddress = null
    ): array {
        $actualUserId = $this->getUserId($username);
        if (!$actualUserId) {
            // User does not exist, cannot be locked out
            return [
                'locked' => false,
                'attempts' => 0,
                'locked_until' => null,
                'is_permanent' => false,
                'max_attempts' => $this->maxFailedAttempts
            ];
        }
        $resolvedUserId = $actualUserId;

        $this->db->begin_transaction();

        try {
            $existing = $this->getExistingRecord($username);

            if ($existing) {
                $result = $this->handleExistingUser($existing, $username, $resolvedUserId, $ipAddress);
            } else {
                $failedAttempts = $this->handleNewUser($username, $resolvedUserId, $ipAddress);
                $result = [
                    'locked' => false,
                    'attempts' => $failedAttempts,
                    'locked_until' => null,
                    'is_permanent' => false,
                    'max_attempts' => $this->maxFailedAttempts
                ];
            }

            $this->db->commit();
            return $result;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("AccountLockout error: " . $e->getMessage());
            return [
                'locked' => false,
                'attempts' => 0,
                'locked_until' => null,
                'is_permanent' => false
            ];
        }
    }

    /**
     * Get existing lockout record
     *
     * @return array<string, mixed>|null
     */
    private function getExistingRecord(string $username): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, failed_attempts, total_lockouts, is_permanently_locked
            FROM account_lockouts
            WHERE username = ?
            LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception("Failed to prepare select statement");
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return $existing ?: null;
    }

    /**
     * Handle attempt for existing user
     *
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function handleExistingUser(array $existing, string $username, ?int $userId, ?string $ipAddress): array
    {
        if ($existing['is_permanently_locked']) {
            return [
                'locked' => true,
                'attempts' => (int)$existing['failed_attempts'],
                'locked_until' => null,
                'is_permanent' => true
            ];
        }

        $failedAttempts = (int)$existing['failed_attempts'] + 1;
        $totalLockouts = (int)$existing['total_lockouts'];

        if ($failedAttempts >= $this->maxFailedAttempts) {
            return $this->processLockout($username, $failedAttempts, $totalLockouts, $userId, $ipAddress);
        }

        $this->incrementAttempts($username, $failedAttempts);
        return [
            'locked' => false,
            'attempts' => $failedAttempts,
            'locked_until' => null,
            'is_permanent' => false,
            'max_attempts' => $this->maxFailedAttempts
        ];
    }

    /**
     * Handle attempt for new user
     */
    private function handleNewUser(string $username, ?int $userId, ?string $ipAddress): int
    {
        if (!$userId) {
            $userId = $this->getUserId($username);
        }

        $insertStmt = $this->db->prepare(
            "INSERT INTO account_lockouts
            (user_id, username, failed_attempts, locked_by_ip, first_failed_attempt, last_failed_attempt)
            VALUES (?, ?, 1, ?, NOW(), NOW())"
        );

        if (!$insertStmt) {
            throw new Exception("Failed to prepare insert statement");
        }

        $insertStmt->bind_param('iss', $userId, $username, $ipAddress);
        $insertStmt->execute();
        $insertStmt->close();

        return 1;
    }

    /**
     * Process account lockout
     *
     * @return array<string, mixed>
     */
    private function processLockout(string $username, int $failedAttempts, int $totalLockouts, ?int $userId, ?string $ipAddress): array
    {
        $totalLockouts++;
        $isPermanent = ($totalLockouts >= $this->permanentLockoutThreshold);

        if ($isPermanent) {
            return $this->applyPermanentLockout($username, $failedAttempts, $totalLockouts, $userId, $ipAddress);
        }

        return $this->applyTemporaryLockout($username, $failedAttempts, $totalLockouts, $userId, $ipAddress);
    }

    /**
     * Apply permanent lockout
     *
     * @return array<string, mixed>
     */
    private function applyPermanentLockout(string $username, int $failedAttempts, int $totalLockouts, ?int $userId, ?string $ipAddress): array
    {
        $updateStmt = $this->db->prepare(
            "UPDATE account_lockouts
            SET failed_attempts = ?,
                total_lockouts = ?,
                is_permanently_locked = TRUE,
                locked_by_ip = ?,
                lockout_reason = 'security_breach',
                last_failed_attempt = NOW()
            WHERE username = ?"
        );

        if (!$updateStmt) {
            throw new Exception("Failed to prepare permanent update statement");
        }

        $updateStmt->bind_param('iiss', $failedAttempts, $totalLockouts, $ipAddress, $username);
        $updateStmt->execute();
        $updateStmt->close();

        $this->logLockout($userId, $username, $failedAttempts, 'permanent', $ipAddress, $totalLockouts);

        return [
            'locked' => true,
            'attempts' => $failedAttempts,
            'locked_until' => null,
            'is_permanent' => true,
            'max_attempts' => $this->maxFailedAttempts
        ];
    }

    /**
     * Apply temporary lockout
     *
     * @return array<string, mixed>
     */
    private function applyTemporaryLockout(string $username, int $failedAttempts, int $totalLockouts, ?int $userId, ?string $ipAddress): array
    {
        $lockedUntil = date('Y-m-d H:i:s', time() + ($this->lockoutDurationMinutes * 60));

        $updateStmt = $this->db->prepare(
            "UPDATE account_lockouts
            SET failed_attempts = ?,
                locked_until = ?,
                total_lockouts = ?,
                locked_by_ip = ?,
                lockout_reason = 'failed_login',
                last_failed_attempt = NOW()
            WHERE username = ?"
        );

        if (!$updateStmt) {
            throw new Exception("Failed to prepare temporary update statement");
        }

        $updateStmt->bind_param('isiss', $failedAttempts, $lockedUntil, $totalLockouts, $ipAddress, $username);
        $updateStmt->execute();
        $updateStmt->close();

        $this->logLockout($userId, $username, $failedAttempts, $lockedUntil, $ipAddress, $totalLockouts);

        return [
            'locked' => true,
            'attempts' => $failedAttempts,
            'locked_until' => $lockedUntil,
            'is_permanent' => false,
            'max_attempts' => $this->maxFailedAttempts
        ];
    }

    /**
     * Increment failed attempts count
     */
    private function incrementAttempts(string $username, int $failedAttempts): void
    {
        $updateStmt = $this->db->prepare(
            "UPDATE account_lockouts
            SET failed_attempts = ?,
                last_failed_attempt = NOW()
            WHERE username = ?"
        );

        if (!$updateStmt) {
            throw new Exception("Failed to prepare increment update statement");
        }

        $updateStmt->bind_param('is', $failedAttempts, $username);
        $updateStmt->execute();
        $updateStmt->close();
    }

    /**
     * Get user ID by username
     */
    private function getUserId(string $username): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM login WHERE username = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    }

    /**
     * Log lockout events
     */
    private function logLockout(?int $userId, string $username, int $failedAttempts, string $lockedUntil, ?string $ipAddress, int $totalLockouts = 0): void
    {
        if ($userId) {
            $this->logger->logAccountLockout(
                $userId,
                $username,
                $failedAttempts,
                $lockedUntil,
                $ipAddress ?? 'unknown'
            );
        }

        if ($lockedUntil === 'permanent') {
            $this->logger->log(
                SecurityLogger::EVENT_ACCOUNT_LOCKED,
                SecurityLogger::SEVERITY_CRITICAL,
                $userId,
                $username,
                "Account permanently locked after $totalLockouts lockouts",
                ['ip' => $ipAddress, 'total_lockouts' => $totalLockouts]
            );
        }
    }

    /**
     * Reset failed attempts (after successful login)
     *
     * @param string $username Username
     * @return bool Success status
     */
    public function resetAttempts(string $username): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE account_lockouts
            SET failed_attempts = 0,
                locked_until = NULL
            WHERE username = ?
            AND is_permanently_locked = FALSE"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $username);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Manually unlock account
     *
     * @param string $username Username
     * @param bool $resetTotalLockouts Reset total lockouts counter
     * @return bool Success status
     */
    public function unlockAccount(string $username, bool $resetTotalLockouts = false): bool
    {
        if ($resetTotalLockouts) {
            $stmt = $this->db->prepare(
                "UPDATE account_lockouts
                SET failed_attempts = 0,
                    locked_until = NULL,
                    is_permanently_locked = FALSE,
                    total_lockouts = 0
                WHERE username = ?"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE account_lockouts
                SET failed_attempts = 0,
                    locked_until = NULL,
                    is_permanently_locked = FALSE
                WHERE username = ?"
            );
        }

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $username);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->logger->log(
                SecurityLogger::EVENT_ACCOUNT_UNLOCKED,
                SecurityLogger::SEVERITY_MEDIUM,
                null,
                $username,
                "Account manually unlocked by administrator"
            );
        }

        return $result;
    }

    /**
     * Get remaining attempts before lockout
     *
     * @param string $username Username
     * @return int Remaining attempts
     */
    public function getRemainingAttempts(string $username): int
    {
        $stmt = $this->db->prepare(
            "SELECT failed_attempts FROM account_lockouts
            WHERE username = ?
            AND (locked_until IS NULL OR locked_until < NOW())
            AND is_permanently_locked = FALSE
            LIMIT 1"
        );

        if (!$stmt) {
            return $this->maxFailedAttempts;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $failedAttempts = (int) $row['failed_attempts'];
            return max(0, $this->maxFailedAttempts - $failedAttempts);
        }

        return $this->maxFailedAttempts;
    }

    /**
     * Get lockout statistics for account
     *
     * @param string $username Username
     * @return array<string, mixed>
     */
    public function getStatistics(string $username): array
    {
        $stmt = $this->db->prepare(
            "SELECT failed_attempts, locked_until, total_lockouts, lockout_reason,
                    is_permanently_locked, first_failed_attempt, last_failed_attempt
            FROM account_lockouts
            WHERE username = ?
            LIMIT 1"
        );

        if (!$stmt) {
            return [
                'failed_attempts' => 0,
                'remaining_attempts' => $this->maxFailedAttempts,
                'total_lockouts' => 0,
                'is_locked' => false,
                'is_permanent' => false
            ];
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $isPermanent = (bool) $row['is_permanently_locked'];
            $isLocked = $isPermanent || ($row['locked_until'] && strtotime((string)$row['locked_until']) > time());
            $failedAttempts = (int) $row['failed_attempts'];

            return [
                'failed_attempts' => $failedAttempts,
                'remaining_attempts' => max(0, $this->maxFailedAttempts - $failedAttempts),
                'total_lockouts' => (int) $row['total_lockouts'],
                'is_locked' => $isLocked,
                'is_permanent' => $isPermanent,
                'locked_until' => $row['locked_until'],
                'lockout_reason' => $row['lockout_reason'],
                'first_failed_attempt' => $row['first_failed_attempt'],
                'last_failed_attempt' => $row['last_failed_attempt']
            ];
        }

        return [
            'failed_attempts' => 0,
            'remaining_attempts' => $this->maxFailedAttempts,
            'total_lockouts' => 0,
            'is_locked' => false,
            'is_permanent' => false
        ];
    }

    /**
     * Unlock expired accounts
     *
     * @return int Number of unlocked accounts
     */
    private function unlockExpiredAccounts(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE account_lockouts
            SET failed_attempts = 0, locked_until = NULL
            WHERE locked_until IS NOT NULL
            AND locked_until < NOW()
            AND is_permanently_locked = FALSE"
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
     * Load configuration from database
     *
     * @return void
     */
    private function loadConfiguration(): void
    {
        $stmt = $this->db->prepare(
            "SELECT config_key, config_value FROM security_config
            WHERE config_key IN (
                'max_failed_attempts',
                'lockout_duration_minutes',
                'permanent_lockout_threshold',
                'enable_account_lockout'
            )"
        );

        if (!$stmt) {
            return;
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $key = $row['config_key'];
                $value = $row['config_value'];

                switch ($key) {
                    case 'max_failed_attempts':
                        $this->maxFailedAttempts = (int) $value;
                        break;
                    case 'lockout_duration_minutes':
                        $this->lockoutDurationMinutes = (int) $value;
                        break;
                    case 'permanent_lockout_threshold':
                        $this->permanentLockoutThreshold = (int) $value;
                        break;
                    case 'enable_account_lockout':
                        $this->enableAccountLockout = $value === 'true';
                        break;
                    default:
                        break;
                }
            }
        }

        $stmt->close();
    }

    /**
     * Set configuration
     *
     * @param int $maxFailedAttempts Maximum failed attempts
     * @param int $lockoutDurationMinutes Lockout duration in minutes
     * @param int $permanentLockoutThreshold Permanent lockout threshold
     * @return void
     */
    public function setConfiguration(
        int $maxFailedAttempts,
        int $lockoutDurationMinutes,
        int $permanentLockoutThreshold
    ): void {
        $this->maxFailedAttempts = $maxFailedAttempts;
        $this->lockoutDurationMinutes = $lockoutDurationMinutes;
        $this->permanentLockoutThreshold = $permanentLockoutThreshold;
    }

    /**
     * Get configuration
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return [
            'max_failed_attempts' => $this->maxFailedAttempts,
            'lockout_duration_minutes' => $this->lockoutDurationMinutes,
            'permanent_lockout_threshold' => $this->permanentLockoutThreshold,
            'enable_account_lockout' => $this->enableAccountLockout
        ];
    }

    /**
     * Check if account lockout is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enableAccountLockout;
    }
}
