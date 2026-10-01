<?php

namespace ROOTS\Auth;

use ROOTS\Config\Database;

class BanSystem
{
    /**
     * Security and Ban Management System
     * Enhanced with caching and anti-enumeration measures
     */
    
    private const string DEFAULT_IP = '0.0.0.0';
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const int CACHE_TTL = 300; // 5 minutes cache
    private const int ENUMERATION_THRESHOLD = 10; // Block after 10 enumeration attempts
    private const int ENUMERATION_WINDOW = 60; // 60 seconds window
    private const string BLOCK_IP_QUERY = "INSERT INTO ip_blocks (ip_address, blocked_until, reason) VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?) ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until), reason = VALUES(reason)";
    
    // Pages allowed for banned users
    // Note: massage/index handles its own internal routing/files, so we allow 'massage' directory in logic
    /** @var array<string> */
    private static array $allowedScripts = [
        "blocked",
        "test_css_report",
        "submit_signup",
        "signup",
        "about",
        "vip_page",
        "botworm",
        "blocked_403",
        "add",
        "add.php",
        "logout",
        "login",
        "login.php",
        "moment.min.js",
        "tempusdominus-bootstrap-4.js",
        "tempusdominus-bootstrap-4.min.js",
        "massage/index",
        "terminal_api",
        "admin_security_guard",
        "admin_security_guard.php",
        "get_transaction_history",
        "get_transaction_history.php",
        "get_recent_notifications",
        "get_recent_notifications.php",
        "api_stats",
        "update_points",
        "process_payment",
    ];

    /** @var array<string> */
    private static array $allowedDirs = [
        "massage", // Chat system
        "auth", // If any auth scripts exist here
        "dir", // Public info directory
    ];

    // Pages that require a paid subscription (Premium, VIP, Elite, Admin)
    /** @var array<string> */
    private static array $premiumScripts = [
        "change_password",
        "recovery_options",
        "security_audit",
        "two_factor_auth",
    ];

    /**
     * Check if a user is currently banned or suspended
     *
     * @param int $userId
     * @return bool
     */
    public static function isBanned(int $userId): bool
    {
        $isBanned = false;
        $db = Database::getConnection();
        if ($db) {
            $stmt = $db->prepare(
                "SELECT suspended, ban_until FROM user_security_guard WHERE user_id = ? LIMIT 1",
            );
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res !== false ? $res->fetch_assoc() : null;
                $stmt->close();

                if ($row) {
                    $isSuspended = !empty($row["suspended"]) && $row["suspended"] == 1;
                    $isTempBanned = !empty($row["ban_until"]) && strtotime((string)$row["ban_until"]) > time();
                    $isBanned = $isSuspended || $isTempBanned;
                }
            }
        }

        return $isBanned;
    }

    /**
     * Check if user has a paid subscription (Premium, VIP, Elite, or Admin)
     */
    public static function hasSubscription(int $userId): bool
    {
        $hasSubscription = false;
        $paidPlans = ["premium", "vip", "elite", "admin"];

        // Check session first for performance
        if (isset($_SESSION["subscription"])) {
            $sub = strtolower(trim((string)$_SESSION["subscription"]));
            $hasSubscription = in_array($sub, $paidPlans);
        }

        // Fallback to database check
        if (!$hasSubscription) {
            $db = Database::getConnection();
            if ($db) {
                $stmt = $db->prepare(
                    "SELECT subscription FROM login WHERE id = ? LIMIT 1",
                );
                if ($stmt) {
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res !== false ? $res->fetch_assoc() : null;
                    $stmt->close();

                    if ($row) {
                        $subscription = strtolower(trim((string)($row["subscription"] ?? "")));
                        // Update session if we found it
                        $_SESSION["subscription"] = $row["subscription"] ?? "Basic";
                        $hasSubscription = in_array($subscription, $paidPlans);
                    }
                }
            }
        }

        return $hasSubscription;
    }

    /**
     * Enforce premium access. Redirect to vip_page if non-subscriber tries to access premium script.
     * This only applies to direct script access, not button clicks (which are handled by JS).
     *
     * @param int $userId
     * @param string $safePath The path from the router (e.g. "v7k9m2p4q1")
     */
    public static function enforcePremiumAccess(int $userId, string $safePath): void
    {
        // 1. Check if the current path is a premium script
        $path = $safePath;
        if (str_ends_with($path, '.php')) {
            $path = substr($path, 0, -4);
        }

        if (!in_array($path, self::$premiumScripts)) {
            return; // Not a premium page
        }

        // 2. Check if user is a subscriber
        if (self::hasSubscription($userId)) {
            return; // Subscriber has access
        }

        // 3. Not a subscriber - Redirect to vip_page
        header("Location: /vip_page");
        exit();
    }

    /**
     * Get ban details for display (with caching)
     * @return array<string, mixed>
     */
    public static function getBanDetails(int $userId): array
    {
        $cacheKey = "ban_details:$userId";
        
        // Try to get from cache first
        $cached = self::getFromCache($cacheKey);
        if ($cached !== null) {
            return is_array($cached) ? $cached : [];
        }

        $db = Database::getConnection();
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT suspended, ban_until, error_window_count FROM user_security_guard WHERE user_id = ? LIMIT 1",
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = $res !== false ? $res->fetch_assoc() : null;
        $stmt->close();

        $result = $data ?: [];
        
        // Cache the result
        self::setCache($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Enforce the global ban. Call this on every page load.
     *
     * @param int $userId
     */
    public static function enforceGlobalBan(int $userId): void
    {
        if (!self::isBanned($userId)) {
            return;
        }

        $currentScript = basename($_SERVER["SCRIPT_NAME"]);

        // Check allowed scripts
        if (in_array($currentScript, self::$allowedScripts)) {
            return;
        }

        // Check allowed directories
        $uri = $_SERVER["REQUEST_URI"];
        foreach (self::$allowedDirs as $dir) {
            if (strpos($uri, "/$dir/") !== false) {
                return;
            }
        }

        // If we are here, the user is banned and trying to access a restricted page.
        // Redirect to blocked without recording violation
        header("Location: /blocked");
        exit();
    }

    /**
     * Get violation count for a user
     *
     * @param int $userId
     * @return int
     */
    public static function getViolationCount(int $userId): int
    {
        $db = Database::getConnection();
        if (!$db) {
            return 0;
        }

        self::ensureTablesExist($db);

        $stmt = $db->prepare(
            "SELECT COUNT(*) as cnt FROM blocked_nav WHERE user_id = ?",
        );
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res !== false ? $res->fetch_assoc() : null;
        $stmt->close();

        return (int) ($row["cnt"] ?? 0);
    }

    /**
     * Ensure necessary tables exist (optimized with request_count column)
     *
     * @param \mysqli $db
     */
    public static function ensureTablesExist(\mysqli $db): void
    {
        // 1. Guest Rate Limiting (optimized with request_count for single-query operations)
        $db->query("CREATE TABLE IF NOT EXISTS guest_rate_limit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(100) NOT NULL UNIQUE,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            request_count INT NOT NULL DEFAULT 1,
            INDEX idx_ip_timestamp (ip_address, timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Migration: Add request_count column if table exists but doesn't have it
        $result = $db->query("SHOW COLUMNS FROM guest_rate_limit LIKE 'request_count'");
        if ($result !== false && $result instanceof \mysqli_result && $result->num_rows === 0) {
            $db->query("ALTER TABLE guest_rate_limit ADD COLUMN request_count INT NOT NULL DEFAULT 1");
        }

        // 2. IP/Browser Blocks (updated to support User-Agent blocking)
        $db->query("CREATE TABLE IF NOT EXISTS ip_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(100) NOT NULL UNIQUE,
            blocked_until TIMESTAMP NOT NULL,
            reason VARCHAR(500) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_blocked (ip_address, blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3. User Security Guard (Strikes and Suspension)
        $db->query("CREATE TABLE IF NOT EXISTS user_security_guard (
            user_id INT NOT NULL PRIMARY KEY,
            error_window_started_at DATETIME NULL,
            error_window_count INT NOT NULL DEFAULT 0,
            ban_until DATETIME NULL,
            ban_count INT NOT NULL DEFAULT 0,
            suspended TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_security_guard_ban_until (ban_until),
            INDEX idx_user_security_guard_suspended (suspended)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4. Blocked Navigation Log
        $db->query("CREATE TABLE IF NOT EXISTS blocked_nav (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            path VARCHAR(255) NULL,
            ip VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_blocked_nav_user (user_id),
            INDEX idx_blocked_nav_created (created_at),
            INDEX idx_blocked_nav_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 5. Blocked Navigation Guard (Alerting)
        $db->query("CREATE TABLE IF NOT EXISTS blocked_navigation_guard (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            identifier VARCHAR(100) NULL,
            window_started_at DATETIME NULL,
            window_count INT NOT NULL DEFAULT 0,
            last_path VARCHAR(255) NULL,
            last_ip VARCHAR(64) NULL,
            last_alerted_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_guard_user (user_id),
            INDEX idx_guard_identifier (identifier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 6. User Notifications
        $db->query("CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_notifications_user (user_id),
            INDEX idx_user_notifications_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 7. Enumeration Attempts Log (NEW - for anti-enumeration)
        $db->query("CREATE TABLE IF NOT EXISTS enumeration_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(100) NOT NULL,
            attempted_path VARCHAR(255) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 1,
            first_attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_blocked TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_enum_identifier (identifier),
            INDEX idx_enum_blocked (is_blocked),
            INDEX idx_enum_time (last_attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Notify administrators about security events
     */
    public static function notifyAdmins(string $message): void
    {
        $db = Database::getConnection();
        if (!$db) {
            return;
        }

        self::ensureTablesExist($db);

        $adminIds = [];
        $res = $db->query("SELECT id FROM login WHERE subscription = 'admin' OR role = 'admin'");
        if (is_object($res)) {
            while ($row = $res->fetch_assoc()) {
                $adminIds[] = (int)$row['id'];
            }
        }

        if (empty($adminIds)) {
            return;
        }

        $stmt = $db->prepare("INSERT INTO user_notifications (user_id, message, created_by) VALUES (?, ?, 'system_guard')");
        if ($stmt) {
            foreach ($adminIds as $adminId) {
                $stmt->bind_param("is", $adminId, $message);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    /**
     * Get a unique identifier based on User-Agent for browser-based blocking
     * This is more reliable than IP when all traffic comes from 127.0.0.1
     */
    public static function getBrowserIdentifier(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? self::DEFAULT_IP;

        // If User-Agent is empty or suspicious, fall back to IP
        if (empty($userAgent) || strlen($userAgent) < 10) {
            return 'ip_' . substr($ip, 0, 64);
        }

        // Create a unique hash based on User-Agent
        // Include IP as salt to prevent same UA from different sources colliding
        return 'ua_' . substr(hash('sha256', $userAgent . $ip), 0, 40);
    }

    /**
     * Get the client IP (kept for backward compatibility and logging)
     * No longer used for blocking - use getBrowserIdentifier() instead
     */
    public static function getClientIp(): string
    {
        $ip = (string) ($_SERVER["REMOTE_ADDR"] ?? self::DEFAULT_IP);
        if ($ip === "" || $ip === "unknown") {
            $ip = (string) ($_SERVER["HTTP_X_FORWARDED_FOR"] ?? self::DEFAULT_IP);
        }

        // Localhost handling - return as-is without session differentiation
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return '127.0.0.1'; // Always return localhost IP
        }

        return substr($ip, 0, 64);
    }

    /**
     * Check if the current browser (User-Agent fingerprint) is blocked
     * @return array<string, mixed>
     */
    public static function isBrowserBlocked(): array
    {
        $result = ["is_blocked" => false];
        $db = Database::getConnection();
        if ($db) {
            $identifier = self::getBrowserIdentifier();

            $stmt = $db->prepare("SELECT blocked_until, reason FROM ip_blocks WHERE ip_address = ? AND blocked_until > NOW() LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $identifier);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res !== false ? $res->fetch_assoc() : null;
                $stmt->close();

                if ($row) {
                    $result = [
                        "is_blocked" => true,
                        "until" => $row["blocked_until"],
                        "reason" => $row["reason"],
                        "identifier" => $identifier
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Check guest rate limiting based on User-Agent (optimized with single query)
     *
     * @param int $maxRequests
     * @param int $window
     * @return array<string, mixed> ['current' => int, 'max' => int, 'is_blocked' => bool]
     */
    public static function checkGuestRateLimit(int $maxRequests = 50, int $window = 30, bool $increment = true): array
    {
        // Initialize default result
        $result = ["current" => 0, "max" => $maxRequests, "is_blocked" => false, "type" => "user_agent"];

        $db = Database::getConnection();
        if (!$db) {
            return $result;
        }

        self::ensureTablesExist($db);

        // 1. Check if already blocked in ip_blocks
        $browserBlock = self::isBrowserBlocked();
        if ($browserBlock['is_blocked']) {
            return [
                "current" => $maxRequests,
                "max" => $maxRequests,
                "is_blocked" => true,
                "until" => $browserBlock['until'],
                "reason" => $browserBlock['reason'],
                "type" => "user_agent"
            ];
        }

        $identifier = self::getBrowserIdentifier();

        // Check if request_count column exists
        $hasRequestCount = false;
        $colResult = $db->query("SHOW COLUMNS FROM guest_rate_limit LIKE 'request_count'");
        if ($colResult && is_object($colResult) && $colResult->num_rows > 0) {
            $hasRequestCount = true;
        }

        // 2. Optimized: Use single query with INSERT ... ON DUPLICATE KEY UPDATE
        // This combines count check, cleanup, and increment in one operation
        if ($increment) {
            if ($hasRequestCount) {
                $stmt = $db->prepare("
                    INSERT INTO guest_rate_limit (ip_address, timestamp, request_count)
                    VALUES (?, NOW(), 1)
                    ON DUPLICATE KEY UPDATE
                        request_count = request_count + 1,
                        timestamp = IF(timestamp < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), timestamp)
                ");
                if ($stmt) {
                    $stmt->bind_param("si", $identifier, $window);
                    $stmt->execute();
                    $stmt->close();
                }

                // Get current count
                $stmt = $db->prepare("SELECT request_count FROM guest_rate_limit WHERE ip_address = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $identifier);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res !== false ? $res->fetch_assoc() : null;
                    $currentRate = (int) ($row["request_count"] ?? 0);
                    $stmt->close();
                }
            } else {
                // Fallback to old method without request_count
                $stmt = $db->prepare("DELETE FROM guest_rate_limit WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? SECOND)");
                if ($stmt) {
                    $stmt->bind_param("i", $window);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $db->prepare("INSERT INTO guest_rate_limit (ip_address, timestamp) VALUES (?, NOW())");
                if ($stmt) {
                    $stmt->bind_param("s", $identifier);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $db->prepare("SELECT COUNT(*) as count FROM guest_rate_limit WHERE ip_address = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $identifier);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res !== false ? $res->fetch_assoc() : null;
                    $currentRate = (int) ($row["count"] ?? 0);
                    $stmt->close();
                }
            }
        } else {
            // Just check count without incrementing
            if ($hasRequestCount) {
                $stmt = $db->prepare("SELECT request_count FROM guest_rate_limit WHERE ip_address = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $identifier);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res !== false ? $res->fetch_assoc() : null;
                    $currentRate = (int) ($row["request_count"] ?? 0);
                    $stmt->close();
                }
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM guest_rate_limit WHERE ip_address = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $identifier);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res !== false ? $res->fetch_assoc() : null;
                    $currentRate = (int) ($row["count"] ?? 0);
                    $stmt->close();
                }
            }
        }

        // 3. Check if we should block this User-Agent
        if (isset($currentRate) && $currentRate >= $maxRequests) {
            $blockTime = 1800; // 30 minutes
            $blockUntil = date(self::DATETIME_FORMAT, time() + $blockTime);
            $fullUA = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $reason = "RATE_LIMIT_EXCEEDED: Excessive requests ({$currentRate}/{$maxRequests}) | Browser: {$fullUA}";
            $reason = substr($reason, 0, 990); // Safety truncation

            $block = $db->prepare("INSERT INTO ip_blocks (ip_address, blocked_until, reason) VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?) ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until), reason = VALUES(reason)");
            if ($block) {
                $block->bind_param("sis", $identifier, $blockTime, $reason);
                $block->execute();
                $block->close();
            }

            // Log the blocking for security analysis
            error_log("[RATE_LIMIT] User-Agent blocked: " . substr($fullUA, 0, 200) . " | Requests: {$currentRate}/{$maxRequests}");

            $result = ["current" => $currentRate, "max" => $maxRequests, "is_blocked" => true, "until" => $blockUntil, "reason" => $reason];
        } else {
            $result["current"] = $currentRate ?? 0;
        }

        return $result;
    }

    /**
     * Get list of currently blocked guest browsers (User-Agents)
     * @return array<int, array<string, mixed>>
     */
    public static function getBlockedGuestBrowsers(): array
    {
        $db = Database::getConnection();
        if (!$db) {
            return [];
        }

        // Show all blocks (active and recently expired) for better visibility in admin panel
        $stmt = $db->query("SELECT id, ip_address, blocked_until, reason, created_at FROM ip_blocks ORDER BY created_at DESC LIMIT 100");
        return is_object($stmt) ? $stmt->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Unblock a guest browser by its identifier
     */
    public static function unblockGuestBrowser(string $identifier): bool
    {
        $db = Database::getConnection();
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("DELETE FROM ip_blocks WHERE ip_address = ?");
        $ok = false;
        if ($stmt) {
            $stmt->bind_param("s", $identifier);
            $ok = $stmt->execute();
            $stmt->close();
        }

        // Also clean rate limit history for this identifier to prevent immediate re-block
        $stmt = $db->prepare("DELETE FROM guest_rate_limit WHERE ip_address = ?");
        if ($stmt) {
            $stmt->bind_param("s", $identifier);
            $stmt->execute();
            $stmt->close();
        }

        return $ok;
    }

    /**
     * Check and increment the strike system count.
     * Reached 100 strikes = Permanent Suspension.
     * @return array<string, mixed>
     */
    public static function checkStrikeSystem(int $userId): array
    {
        $db = Database::getConnection();
        if (!$db) {
            return ["status" => "error", "count" => 0];
        }

        self::ensureTablesExist($db);

        // 1. Get current stats
        $stmt = $db->prepare("SELECT error_window_count FROM user_security_guard WHERE user_id = ?");
        if (!$stmt) {
            return ["status" => "error", "count" => 0];
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res !== false ? $res->fetch_assoc() : null;
        $stmt->close();

        $currentCount = $row ? (int)$row['error_window_count'] : 0;
        $newCount = $currentCount + 1;
        $maxStrikes = 100;
        $status = ($newCount > $maxStrikes) ? "frozen" : "ok";

        // 2. Update status
        $stmt = $db->prepare("INSERT INTO user_security_guard (user_id, error_window_count, suspended)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE error_window_count = ?,
                    suspended = IF(? > ?, 1, suspended)");

        $isSuspended = ($status === "frozen" ? 1 : 0);
        if ($stmt) {
            $stmt->bind_param("iiiiii", $userId, $newCount, $isSuspended, $newCount, $newCount, $maxStrikes);
            $stmt->execute();
            $stmt->close();
        }

        return ["status" => $status, "count" => $newCount, "max" => $maxStrikes];
    }

    /**
     * Record a blocked navigation attempt and handle alerting (with anti-enumeration)
     *
     * @param int|null $userId
     * @param string $path
     * @param int $severity 1 for minor (404), 3 for major (403/Security)
     */
    public static function recordBlockedViolation(?int $userId, string $path, int $severity = 1): void
    {
        $db = Database::getConnection();
        if (!$db) {
            return;
        }

        self::ensureTablesExist($db);

        $identifier = self::getBrowserIdentifier();
        $path = substr($path, 0, 255);
        
        // Check for enumeration attempts
        if (self::isEnumerationAttempt($db, $identifier, $path)) {
            self::handleEnumerationBlock($identifier, $userId);
            return;
        }
        
        self::logViolation($db, $userId, $path, $identifier);
        $shouldBlock = self::checkAndUpdateViolationState($db, $identifier, $userId, $path, $severity);
        
        if ($shouldBlock) {
            self::handleSecurityBlock($identifier, $userId, $path, $severity);
        }
    }

    /**
     * Log individual violation to database
     */
    private static function logViolation(\mysqli $db, ?int $userId, string $path, string $identifier): void
    {
        $stmt = $db->prepare("INSERT INTO blocked_nav (user_id, path, ip) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iss", $userId, $path, $identifier);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Check and update violation state, return whether blocking is needed
     */
    private static function checkAndUpdateViolationState(\mysqli $db, string $identifier, ?int $userId, string $path, int $severity): bool
    {
        $window = 900;
        $threshold = 100; // Increased threshold to reduce false positives
        $now = time();

        // Get current violation state
        $stmt = $db->prepare("SELECT window_count, window_started_at, last_alerted_at FROM blocked_navigation_guard WHERE identifier = ? LIMIT 1");
        $state = null;
        if ($stmt) {
            $stmt->bind_param("s", $identifier);
            $stmt->execute();
            $res = $stmt->get_result();
            $state = $res !== false ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        // Calculate new violation count and start time
        if ($state) {
            $startedAt = strtotime((string)$state['window_started_at']);
            if ($startedAt + $window < $now) {
                $count = $severity;
                $startedAt = $now;
            } else {
                $count = (int)$state['window_count'] + $severity;
            }
        } else {
            $count = $severity;
            $startedAt = $now;
        }
        
        $shouldBlock = ($count >= $threshold);
        $previousAlert = $state !== null ? ($state['last_alerted_at'] ?? null) : null;
        
        if ($shouldBlock) {
            $alertedAtStr = date(self::DATETIME_FORMAT, $now);
        } elseif ($previousAlert !== null) {
            $alertedAtStr = (string)$previousAlert;
        } else {
            $alertedAtStr = null;
        }
        
        $startedAtStr = date(self::DATETIME_FORMAT, $startedAt !== false ? $startedAt : $now);

        $violationState = [
            'count' => $count,
            'startedAtStr' => $startedAtStr,
            'alertedAtStr' => $alertedAtStr
        ];
        self::updateViolationState($db, $identifier, $userId, $path, $violationState, $state !== null);
        
        return $shouldBlock;
    }

    /**
     * Update violation state in database
     * @param array{startedAtStr: string, count: int, alertedAtStr: ?string} $state
     */
    private static function updateViolationState(\mysqli $db, string $identifier, ?int $userId, string $path, array $state, bool $exists): void
    {
        $startedAtStr = $state['startedAtStr'];
        $count = $state['count'];
        $alertedAtStr = $state['alertedAtStr'];

        if ($exists) {
            $stmt = $db->prepare("UPDATE blocked_navigation_guard SET
                user_id = ?,
                window_started_at = ?,
                window_count = ?,
                last_path = ?,
                last_ip = ?,
                last_alerted_at = ?
                WHERE identifier = ?");
            if ($stmt) {
                $null = null;
                $alertedAtValue = !empty($alertedAtStr) ? $alertedAtStr : $null;
                $stmt->bind_param("issssss", $userId, $startedAtStr, $count, $path, $identifier, $alertedAtValue, $identifier);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $db->prepare("INSERT INTO blocked_navigation_guard (user_id, identifier, window_started_at, window_count, last_path, last_ip, last_alerted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $null = null;
                $alertedAtValue = $alertedAtStr ?? $null;
                $stmt->bind_param("issssss", $userId, $identifier, $startedAtStr, $count, $path, $identifier, $alertedAtValue);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /**
     * Handle security blocking when threshold is exceeded
     */
    private static function handleSecurityBlock(string $identifier, ?int $userId, string $path, int $severity): void
    {
        $username = $_SESSION['username'] ?? ($userId ? "ID:$userId" : 'Guest');
        $blockTime = 3600;
        $fullUA = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $type = ($severity >= 3) ? "CRITICAL_VIOLATION" : "REPEATED_ERRORS";
        $reason = "SECURITY_VIOLATION ($type): Multiple unauthorized access attempts (User: $username) | Last Path: $path | Browser: {$fullUA}";
        $reason = substr($reason, 0, 990);

        // Block the browser identifier
        $db = Database::getConnection();
        if ($db) {
            $block = $db->prepare(self::BLOCK_IP_QUERY);
            if ($block) {
                $block->bind_param("sis", $identifier, $blockTime, $reason);
                $block->execute();
                $block->close();
            }
        }
        
        self::notifyAdmins("CRITICAL_SECURITY: Browser/Session for $username has been BLOCKED for 1 hour due to $type.");

        if ($userId) {
            Session::destroy();
        }

        header("Location: /blocked_403?code=403&reason=SECURITY_VIOLATION");
        exit();
    }

    /**
     * Check if this is an enumeration attempt (file/directory guessing)
     */
    private static function isEnumerationAttempt(\mysqli $db, string $identifier, string $path): bool
    {
        // Check if this identifier has made multiple 404 requests recently
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM blocked_nav
            WHERE ip = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND path LIKE '%.php'
        ");
        if ($stmt) {
            $window = self::ENUMERATION_WINDOW;
            $stmt->bind_param("si", $identifier, $window);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res !== false ? $res->fetch_assoc() : null;
            $stmt->close();

            $count = (int) ($row["count"] ?? 0);
            if ($count >= self::ENUMERATION_THRESHOLD) {
                return true;
            }
        }

        // Check enumeration_attempts table
        $stmt = $db->prepare("
            SELECT attempt_count, is_blocked
            FROM enumeration_attempts
            WHERE identifier = ?
            AND last_attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        if ($stmt) {
            $window = self::ENUMERATION_WINDOW;
            $stmt->bind_param("si", $identifier, $window);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res !== false ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($row) {
                $attemptCount = (int) $row["attempt_count"];
                $isBlocked = (bool) $row["is_blocked"];

                if ($isBlocked || $attemptCount >= self::ENUMERATION_THRESHOLD) {
                    return true;
                }
            }
        }

        // Log this enumeration attempt
        $stmt = $db->prepare("
            INSERT INTO enumeration_attempts (identifier, attempted_path, attempt_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                attempt_count = attempt_count + 1,
                last_attempted_at = NOW()
        ");
        if ($stmt) {
            $stmt->bind_param("ss", $identifier, $path);
            $stmt->execute();
            $stmt->close();
        }

        return false;
    }

    /**
     * Handle enumeration blocking
     */
    private static function handleEnumerationBlock(string $identifier, ?int $userId, string $path): void
    {
        $username = $_SESSION['username'] ?? ($userId ? "ID:$userId" : 'Guest');
        $blockTime = 7200; // 2 hours for enumeration
        $fullUA = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $reason = "ENUMERATION_BLOCK: File/directory enumeration detected (User: $username) | Pattern: Multiple 404 requests | Browser: {$fullUA}";
        $reason = substr($reason, 0, 990);

        // Block the browser identifier
        $db = Database::getConnection();
        if ($db) {
            // Block in ip_blocks
            $block = $db->prepare(self::BLOCK_IP_QUERY);
            if ($block) {
                $block->bind_param("sis", $identifier, $blockTime, $reason);
                $block->execute();
                $block->close();
            }

            // Mark as blocked in enumeration_attempts
            $mark = $db->prepare("UPDATE enumeration_attempts SET is_blocked = 1 WHERE identifier = ?");
            if ($mark) {
                $mark->bind_param("s", $identifier);
                $mark->execute();
                $mark->close();
            }
        }
        
        self::notifyAdmins("SECURITY_ALERT: Browser/Session for $username has been BLOCKED for 2 hours due to FILE_ENUMERATION attack.");

        if ($userId) {
            Session::destroy();
        }

        header("Location: /blocked_403?code=403&reason=ENUMERATION_BLOCKED");
        exit();
    }

    /**
     * Simple cache implementation using APCu or fallback to static array
     */
    private static function getFromCache(string $key): mixed
    {
        // Try APCu first
        if (function_exists('apcu_fetch')) {
            $value = apcu_fetch($key);
            if ($value !== false) {
                return $value;
            }
        }

        // Fallback to static cache (per-request only)
        static $staticCache = [];
        return $staticCache[$key] ?? null;
    }

    /**
     * Set value in cache
     */
    private static function setCache(string $key, mixed $value, int $ttl): void
    {
        // Try APCu first
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);
        }

        // Fallback to static cache (per-request only)
        static $staticCache = [];
        $staticCache[$key] = $value;
    }

    /**
     * Clear cache for a specific key
     */
    private static function clearCache(string $key): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete($key);
        }

        static $staticCache = [];
        unset($staticCache[$key]);
    }

    /**
     * Clear all ban-related cache for a user
     */
    public static function clearUserBanCache(int $userId): void
    {
        self::clearCache("ban_status:$userId");
        self::clearCache("ban_details:$userId");
    }
}

