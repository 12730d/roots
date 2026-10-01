<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Controllers\PageControllerFacade;
use ROOTS\Layout\MasterLayout;
use ROOTS\Security\Csrf;
use ROOTS\Services\ValidationService;
use ROOTS\Exceptions\AdminException;
use ROOTS\Auth\BanSystem;

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
// Detect if running on Onion service
$isOnionService =
    (isset($_SERVER["HTTP_HOST"]) &&
        preg_match('/\.onion$/i', $_SERVER["HTTP_HOST"])) ||
    (isset($_SERVER["SERVER_NAME"]) &&
        preg_match('/\.onion$/i', $_SERVER["SERVER_NAME"]));

// ============================================================================
// SECURITY HEADERS - ONION SERVICE COMPATIBLE
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Set Content-Security-Policy based on connection type with enhanced XSS protection
if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
}

// Set HSTS header only for non-Onion HTTPS connections
if (
    !$isOnionService &&
    isset($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] === "on"
) {
    header(
        "Strict-Transport-Security: max-age=31536000; includeSubDomains; preload",
    );
}

// Initialize page with security requirements
$pageData = PageController::setup(
    "Admin Console: AI Guard",
    "./",
    [],
    [
        "csrf_protection" => true,
        "render_layout" => false,
        "require_auth" => true,
    ],
);

$db = $pageData["db"];
/** @var array<string, mixed> $user */
$user = $pageData["user"];

// Enforce admin permission
PageControllerFacade::requireAdmin($user, "/");

// Authorized: ensure CSRF token/cookie exist before output, then render layout
/** Ensure CSRF token & cookie are set before any HTML output */
\ROOTS\Security\Csrf::generateToken();
$layout = MasterLayout::createDefault();
$layout->renderPageStart("Admin Console: AI Guard", "./", [], true);

/**
 * h - Clean output for HTML context
 * @deprecated Use MasterLayout::h() or htmlspecialchars directly
 */
if (!function_exists("h")) {
    function h(mixed $s): string
    {
        return htmlspecialchars((string) ($s ?? ""), ENT_QUOTES, "UTF-8");
    }
}

function ensureUserGuardTableExistsLocal(mysqli $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS user_security_guard (
        user_id INT NOT NULL PRIMARY KEY,
        error_window_started_at DATETIME NULL,
        error_window_count INT NOT NULL DEFAULT 0,
        ban_until DATETIME NULL,
        ban_count INT NOT NULL DEFAULT 0,
        suspended TINYINT(1) NOT NULL DEFAULT 0,
        ban_reason TEXT NULL,
        last_activity DATETIME NULL,
        risk_score INT NOT NULL DEFAULT 0,
        auto_ban_enabled TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_security_guard_ban_until (ban_until),
        INDEX idx_user_security_guard_suspended (suspended),
        INDEX idx_user_security_guard_risk_score (risk_score),
        INDEX idx_user_security_guard_last_activity (last_activity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->query($sql);

    // Add new columns if they don't exist (for backwards compatibility)
    // Check column existence and add if missing (MySQL < 8.0.16 compatibility)
    $columnsToAdd = [
        'ban_reason' => 'TEXT NULL',
        'last_activity' => 'DATETIME NULL',
        'risk_score' => 'INT NOT NULL DEFAULT 0',
        'auto_ban_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1'
    ];

    foreach ($columnsToAdd as $colName => $colDef) {
        $checkSql = "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'user_security_guard'
                     AND COLUMN_NAME = '{$colName}'";
        $result = $db->query($checkSql);
        if ($result instanceof mysqli_result && $result->num_rows === 0) {
            $alterResult = $db->query("ALTER TABLE user_security_guard ADD COLUMN {$colName} {$colDef}");
            if (!$alterResult) {
                error_log("Failed to add column {$colName}: " . $db->error);
            }
        }
    }

    // Add indexes if they don't exist
    $indexesToAdd = [
        'idx_user_security_guard_risk_score' => 'risk_score',
        'idx_user_security_guard_last_activity' => 'last_activity'
    ];

    foreach ($indexesToAdd as $idxName => $colName) {
        $checkSql = "SELECT 1 FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'user_security_guard'
                     AND INDEX_NAME = '{$idxName}'";
        $result = $db->query($checkSql);
        if ($result instanceof mysqli_result && $result->num_rows === 0) {
            $alterResult = $db->query("ALTER TABLE user_security_guard ADD INDEX {$idxName} ({$colName})");
            if (!$alterResult) {
                error_log("Failed to add index {$idxName}: " . $db->error);
            }
        }
    }
}

ensureUserGuardTableExistsLocal($db);

// Ensure admin messages notifications table exists
$createNotificationsTableSql = "CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_notifications_user (user_id),
    INDEX idx_user_notifications_read (user_id, is_read),
    INDEX idx_user_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$result = $db->query($createNotificationsTableSql);
if (!$result) {
    error_log("Failed to create user_notifications table: " . $db->error);
}

// Ensure admin events log table exists
$createEventsTableSql = "CREATE TABLE IF NOT EXISTS admin_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    target_user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    session_id VARCHAR(255) NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_events_admin (admin_user_id),
    INDEX idx_admin_events_target (target_user_id),
    INDEX idx_admin_events_action (action),
    INDEX idx_admin_events_severity (severity),
    INDEX idx_admin_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$result = $db->query($createEventsTableSql);
if (!$result) {
    error_log("Failed to create admin_events table: " . $db->error);
}

// Ensure admin sessions table exists for better security tracking
$createAdminSessionsTableSql = "CREATE TABLE IF NOT EXISTS admin_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    session_id VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_sessions_user (admin_user_id),
    INDEX idx_admin_sessions_session (session_id),
    INDEX idx_admin_sessions_active (is_active),
    INDEX idx_admin_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$result = $db->query($createAdminSessionsTableSql);
if (!$result) {
    error_log("Failed to create admin_sessions table: " . $db->error);
}

$success_message = null;
$error_message = null;

$lookup = trim((string) ($_GET["user"] ?? ""));
$listQuery = trim((string) ($_GET["q"] ?? ""));
$statusFilter = strtolower(trim((string) ($_GET["status"] ?? "all")));
$sortBy = strtolower(trim((string) ($_GET["sort"] ?? "error_count")));
$sortOrder = strtoupper(trim((string) ($_GET["order"] ?? "DESC"))) === "ASC" ? "ASC" : "DESC";
$dateFilter = trim((string) ($_GET["date_filter"] ?? ""));
$banFilter = strtolower(trim((string) ($_GET["ban_filter"] ?? "all")));
$accountDateFilter = trim((string) ($_GET["account_date_filter"] ?? ""));
$page = isset($_GET["page"]) ? (int) $_GET["page"] : 1;
if ($page < 1) {
    $page = 1;
}
$perPage = 30;
$offset = ($page - 1) * $perPage;

$foundUser = null;
$recentAdminMessages = [];
$usersList = [];
$totalUsers = 0;
$totalPages = 1;

function logAdminEvent(mysqli $db, int $adminUserId, ?int $targetUserId, string $action, ?string $details = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $db->prepare(
        "INSERT INTO admin_events (admin_user_id, target_user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param("iissss", $adminUserId, $targetUserId, $action, $details, $ip, $userAgent);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    /** @var string $action Can be 'ban', 'unban', 'reset_rate_limit', 'reset_full', 'send_message', 'change_subscription', 'change_points', 'unblock_guest', 'clean_database' */
    $action = (string) ($_POST["action"] ?? "");
    $targetUserId = (int) ($_POST["target_user_id"] ?? 0);
    $redirectUser = trim((string) ($_POST["redirect_user"] ?? ""));

    if ($redirectUser !== "") {
        $lookup = $redirectUser;
    }

    try {
        $guestActions = ["unblock_guest"];
        if ($targetUserId <= 0 && !in_array($action, $guestActions, true)) {
            throw new AdminException("INVALID INPUT PARAMETERS");
        }

        $validActions = ["ban", "unban", "reset_full", "send_message", "change_subscription", "change_points", "unblock_guest", "reset_rate_limit", "clean_database"];
        if (!in_array($action, $validActions, true)) {
            throw new AdminException("INVALID ACTION");
        }

        $ok = false;

        if ($action === "ban") {
            $stmt = $db->prepare(
                "INSERT INTO user_security_guard (user_id, error_window_started_at, error_window_count, ban_until, ban_count, suspended)
                 VALUES (?, NULL, 0, DATE_ADD(NOW(), INTERVAL 2 DAY), 1, 0)
                 ON DUPLICATE KEY UPDATE
                    ban_until = DATE_ADD(NOW(), INTERVAL 2 DAY),
                    suspended = 0,
                    ban_count = ban_count + 1",
            );
            if ($stmt) {
                $stmt->bind_param("i", $targetUserId);
                $ok = $stmt->execute();
                $stmt->close();
            }
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), $targetUserId, 'BAN', 'User banned for 2 days');
            }
        } elseif ($action === "reset_rate_limit") {
            // Clear all guest rate limit data
            $ok = $db->query("DELETE FROM guest_rate_limit");
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), null, 'RESET_RATE_LIMIT', "All guest rate limits cleared");
            }
        } elseif ($action === "unban") {
            $stmt = $db->prepare(
                "UPDATE user_security_guard SET ban_until = NULL, suspended = 0 WHERE user_id = ?",
            );
            if ($stmt) {
                $stmt->bind_param("i", $targetUserId);
                $ok = $stmt->execute();
                $stmt->close();
            }
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), $targetUserId, 'UNBAN', 'User unbanned by admin');
            }
        } elseif ($action === "reset_full") {
            $stmt = $db->prepare(
                "UPDATE user_security_guard SET error_window_started_at = NULL, error_window_count = 0, ban_until = NULL, ban_count = 0, suspended = 0 WHERE user_id = ?",
            );
            if ($stmt) {
                $stmt->bind_param("i", $targetUserId);
                $ok = $stmt->execute();
                $stmt->close();
            }
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), $targetUserId, 'RESET', 'Full security reset performed');
            }
        } elseif ($action === "send_message") {
            $msgRaw = (string) ($_POST["message"] ?? "");
            $msg = ValidationService::sanitizeString($msgRaw, 2000);

            if ($msg === "") {
                throw new AdminException("MESSAGE_EMPTY");
            }

            $createdBy = (string) ($user["username"] ?? "admin");
            $stmt = $db->prepare(
                "INSERT INTO user_notifications (user_id, message, is_read, created_by) VALUES (?, ?, 0, ?)",
            );
            if ($stmt) {
                $stmt->bind_param("iss", $targetUserId, $msg, $createdBy);
                $ok = $stmt->execute();
                $stmt->close();
            }
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), $targetUserId, 'MESSAGE', 'Admin message sent: ' . substr($msg, 0, 100));
            }
        } elseif ($action === "change_subscription") {
            $newSubscription = trim((string) ($_POST["new_subscription"] ?? ""));
            $validSubscriptions = ["free", "basic", "pro", "premium", "vip", "admin"];

            if (!in_array($newSubscription, $validSubscriptions, true)) {
                throw new AdminException("INVALID_SUBSCRIPTION_TYPE");
            }

            $stmt = $db->prepare(
                "UPDATE login SET subscription = ? WHERE id = ?",
            );
            if ($stmt) {
                $stmt->bind_param("si", $newSubscription, $targetUserId);
                $ok = $stmt->execute();
                $stmt->close();
            }
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), $targetUserId, 'CHANGE_SUBSCRIPTION', "Subscription changed to: {$newSubscription}");
            }
        } elseif ($action === "change_points") {
            $newPoints = trim((string) ($_POST["new_points"] ?? ""));

            if (!is_numeric($newPoints) || (int)$newPoints < 0) {
                throw new AdminException("INVALID_POINTS_VALUE");
            }

            $points = (int)$newPoints;

            $stmt = $db->prepare(
                "UPDATE login SET points = ? WHERE id = ?",
            );
            if ($stmt) {
                $stmt->bind_param("ii", $points, $targetUserId);
                $ok = $stmt->execute();
                $stmt->close();
            }
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), $targetUserId, 'CHANGE_POINTS', "Points changed to: {$points}");
            }
        } elseif ($action === "unblock_guest") {
            $identifier = trim((string) ($_POST["guest_identifier"] ?? ""));
            if ($identifier === "") {
                throw new AdminException("INVALID_GUEST_IDENTIFIER");
            }

            $ok = BanSystem::unblockGuestBrowser($identifier);
            if ($ok) {
                logAdminEvent($db, (int)($user['id'] ?? 0), null, 'UNBLOCK_GUEST', "Guest browser unblocked: {$identifier}");
            }
        } elseif ($action === "clean_database") {
            // Clean all security-related tables
            $tablesCleaned = [];

            // Clean guest rate limit
            $result = $db->query("DELETE FROM guest_rate_limit");
            if ($result) {
                $tablesCleaned[] = "guest_rate_limit";
            }

            // Clean IP blocks
            $result = $db->query("DELETE FROM ip_blocks");
            if ($result) {
                $tablesCleaned[] = "ip_blocks";
            }

            // Clean blocked navigation
            $result = $db->query("DELETE FROM blocked_nav");
            if ($result) {
                $tablesCleaned[] = "blocked_nav";
            }

            // Clean blocked navigation guard
            $result = $db->query("DELETE FROM blocked_navigation_guard");
            if ($result) {
                $tablesCleaned[] = "blocked_navigation_guard";
            }

            // Clean old login attempts (older than 7 days)
            $result = $db->query("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            if ($result) {
                $tablesCleaned[] = "old_login_attempts";
            }

            // Clean old security events (older than 30 days)
            $result = $db->query("DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            if ($result) {
                $tablesCleaned[] = "old_security_events";
            }

            if (!empty($tablesCleaned)) {
                $ok = true;
                $details = "Database cleaned: " . implode(", ", $tablesCleaned);
                logAdminEvent($db, (int)($user['id'] ?? 0), null, 'CLEAN_DATABASE', $details);
                $success_message = "SUCCESS: DATABASE CLEANED (" . count($tablesCleaned) . " tables)";
            } else {
                $ok = false;
                throw new AdminException("DATABASE_CLEAN_FAILED");
            }
        }

        if ($ok) {
            $success_message = "SUCCESS: OPERATION COMPLETED";
        } else {
            throw new AdminException("DB WRITE FAILED");
        }
    } catch (AdminException $e) {
        $error_message = $e->getMessage();
    } catch (\Exception $e) {
        error_log("[ADMIN_GUARD] Unexpected error: " . $e->getMessage());
        $error_message = "SYSTEM ERROR: UNABLE TO COMPLETE ACTION";
    }
}

if ($lookup !== "") {
    $byId = ctype_digit($lookup);
    if ($byId) {
        $uid = (int) $lookup;
        $stmt = $db->prepare(
            'SELECT l.id, l.username, l.display_name, l.email, l.points, l.subscription, l.role,
             g.error_window_count, g.ban_until, g.ban_count, g.suspended, g.updated_at
             FROM login l
             LEFT JOIN user_security_guard g ON g.user_id = l.id
             WHERE l.id = ?
             LIMIT 1',
        );
        if ($stmt) {
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            $foundUser = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }
    } else {
        $uname = $lookup;
        $stmt = $db->prepare(
            'SELECT l.id, l.username, l.display_name, l.email, l.points, l.subscription, l.role,
             g.error_window_count, g.ban_until, g.ban_count, g.suspended, g.updated_at
             FROM login l
             LEFT JOIN user_security_guard g ON g.user_id = l.id
             WHERE l.username = ?
             LIMIT 1',
        );
        if ($stmt) {
            $stmt->bind_param("s", $uname);
            $stmt->execute();
            $res = $stmt->get_result();
            $foundUser = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }
    }
}

if ($foundUser && !empty($foundUser["id"])) {
    $uid = (int) $foundUser["id"];
    $msgStmt = $db->prepare(
        "SELECT id, message, is_read, created_by, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
    );
    if ($msgStmt) {
        $msgStmt->bind_param("i", $uid);
        if ($msgStmt->execute()) {
            $res = $msgStmt->get_result();
            if ($res) {
                $recentAdminMessages = $res->fetch_all(MYSQLI_ASSOC);
            }
        }
        $msgStmt->close();
    }
}

$where = [];
$types = "";
$params = [];

if ($listQuery !== "") {
    $like = "%" . $listQuery . "%";
    if (ctype_digit($listQuery)) {
        $where[] =
            "(l.username LIKE ? OR l.email LIKE ? OR l.display_name LIKE ? OR l.id = ?)";
        $types .= "sssi";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = (int) $listQuery;
    } else {
        $where[] =
            "(l.username LIKE ? OR l.email LIKE ? OR l.display_name LIKE ?)";
        $types .= "sss";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
}

if ($statusFilter === "suspended") {
    $where[] = "(g.suspended = 1)";
} elseif ($statusFilter === "temp_banned") {
    $where[] =
        "(COALESCE(g.suspended, 0) = 0 AND g.ban_until IS NOT NULL AND g.ban_until > NOW())";
} elseif ($statusFilter === "active") {
    $where[] =
        "(COALESCE(g.suspended, 0) = 0 AND (g.ban_until IS NULL OR g.ban_until <= NOW()))";
} elseif ($statusFilter === "suspicious") {
    $where[] =
        "(COALESCE(g.error_window_count, 0) > 0 OR COALESCE(g.ban_count, 0) > 0)";
} else {
    $statusFilter = "all";
}

if ($banFilter === "never_banned") {
    $where[] = "(COALESCE(g.ban_count, 0) = 0)";
} elseif ($banFilter === "banned_once") {
    $where[] = "(COALESCE(g.ban_count, 0) = 1)";
} elseif ($banFilter === "multiple_bans") {
    $where[] = "(COALESCE(g.ban_count, 0) > 1)";
}

if ($dateFilter !== "") {
    switch ($dateFilter) {
        case "today":
            $where[] = "(DATE(COALESCE(g.updated_at, l.created_at)) = CURDATE())";
            break;
        case "week":
            $where[] = "(COALESCE(g.updated_at, l.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY))";
            break;
        case "month":
            $where[] = "(COALESCE(g.updated_at, l.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
            break;
        default:
            // No additional date filtering for unknown or default values
            break;
    }
}

if ($accountDateFilter !== "") {
    switch ($accountDateFilter) {
        case "today":
            $where[] = "(DATE(l.created_at) = CURDATE())";
            break;
        case "week":
            $where[] = "(l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))";
            break;
        case "month":
            $where[] = "(l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
            break;
        case "3months":
            $where[] = "(l.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))";
            break;
        case "6months":
            $where[] = "(l.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY))";
            break;
        case "year":
            $where[] = "(l.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR))";
            break;
        default:
            // No additional account date filtering for unknown or default values
            break;
    }
}

$whereSql = "1";
if (!empty($where)) {
    $whereSql = implode(" AND ", $where);
}

$countSql =
    "SELECT COUNT(*) as c FROM login l LEFT JOIN user_security_guard g ON g.user_id = l.id WHERE " .
    $whereSql;
$countStmt = $db->prepare($countSql);
if ($countStmt) {
    if ($types !== "") {
        $countStmt->bind_param($types, ...$params);
    }
    if ($countStmt->execute()) {
        $r = $countStmt->get_result();
        if ($r && ($row = $r->fetch_assoc())) {
            $totalUsers = (int) ($row["c"] ?? 0);
        }
    }
    $countStmt->close();
}

if ($totalUsers < 0) {
    $totalUsers = 0;
}
$totalPages = (int) ceil($totalUsers / $perPage);
if ($totalPages < 1) {
    $totalPages = 1;
}
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sortColumnMap = [
    'id' => 'l.id',
    'username' => 'l.username',
    'points' => 'l.points',
    'ban_count' => 'COALESCE(g.ban_count, 0)',
    'error_count' => 'COALESCE(g.error_window_count, 0)',
    'updated' => 'COALESCE(g.updated_at, l.id)',
    'ban_until' => 'g.ban_until'
];

$sortColumn = $sortColumnMap[$sortBy] ?? 'COALESCE(g.updated_at, l.id)';

$listSql =
    'SELECT l.id, l.username, l.display_name, l.email, l.points, l.subscription, l.created_at,
            COALESCE(g.error_window_count, 0) AS error_window_count,
            COALESCE(g.ban_count, 0) AS ban_count,
            g.ban_until, COALESCE(g.suspended, 0) AS suspended, g.updated_at,
            CASE
               WHEN COALESCE(g.suspended, 0) = 1 THEN \'SUSPENDED\'
               WHEN g.ban_until IS NOT NULL AND g.ban_until > NOW() THEN \'TEMP_BANNED\'
               ELSE \'ACTIVE\'
            END AS guard_status
            FROM login l
            LEFT JOIN user_security_guard g ON g.user_id = l.id
            WHERE ' .
    $whereSql .
    '
            ORDER BY
              CASE
                WHEN COALESCE(g.suspended, 0) = 1 THEN 1
                WHEN g.ban_until IS NOT NULL AND g.ban_until > NOW() THEN 2
                ELSE 3
              END,
              ' . $sortColumn . ' ' . $sortOrder . ',
              l.id DESC
            LIMIT ? OFFSET ?';

$listStmt = $db->prepare($listSql);
if ($listStmt) {
    $listTypes = $types . "ii";
    $listParams = $params;
    $listParams[] = (int) $perPage;
    $listParams[] = (int) $offset;
    $listStmt->bind_param($listTypes, ...$listParams);
    if ($listStmt->execute()) {
        $r = $listStmt->get_result();
        if ($r) {
            $usersList = $r->fetch_all(MYSQLI_ASSOC);
        }
    }
    $listStmt->close();
}

$listStateParams = [];
if ($listQuery !== "") {
    $listStateParams["q"] = $listQuery;
}
if ($statusFilter !== "all") {
    $listStateParams["status"] = $statusFilter;
}
if ($sortBy !== "updated") {
    $listStateParams["sort"] = $sortBy;
}
if ($sortOrder !== "DESC") {
    $listStateParams["order"] = $sortOrder;
}
if ($dateFilter !== "") {
    $listStateParams["date_filter"] = $dateFilter;
}
if ($banFilter !== "all") {
    $listStateParams["ban_filter"] = $banFilter;
}
if ($accountDateFilter !== "") {
    $listStateParams["account_date_filter"] = $accountDateFilter;
}
if ($page > 1) {
    $listStateParams["page"] = $page;
}
$listStateQuery = http_build_query($listStateParams);
$listFormAction =
    "/admin_security_guard" .
    ($listStateQuery !== "" ? "?" . $listStateQuery : "");

$blockedGuests = BanSystem::getBlockedGuestBrowsers();
$activeTab = trim((string) ($_GET["tab"] ?? "users"));
?>
<style>
    :root {
        --term-green: #33ff00;
        --term-dim: #1a8000;
        --term-red: #ff3333;
        --term-yellow: #ffff33;
        --term-blue: #00ccff;
        --term-bg: #050505;
        --term-font: 'Courier New', Courier, monospace;
        --term-glow: 0 0 10px rgba(51, 255, 0, 0.5);
    }

    body {
        background-color: var(--term-bg) !important;
        color: var(--term-green) !important;
        font-family: var(--term-font) !important;
        overflow-x: hidden;
    }

    body::before {
        content: " ";
        display: block;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        right: 0;
        background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
        z-index: 9998;
        background-size: 100% 2px, 3px 100%;
        pointer-events: none;
    }

    h3,
    h4,
    h5 {
        font-family: var(--term-font);
        text-transform: uppercase;
        letter-spacing: 2px;
        color: var(--term-green);
        text-shadow: var(--term-glow);
    }

    .bg-secondary {
        background-color: #000 !important;
        border: 1px dashed var(--term-dim) !important;
    }

    .bg-dark {
        background-color: rgba(0, 20, 0, 0.8) !important;
        border: 1px solid var(--term-dim) !important;
    }

    .stat-block {
        font-family: var(--term-font);
        border-left: 2px solid var(--term-green);
        padding-left: 10px;
        margin-left: 10px;
    }

    .form-control,
    .form-select,
    textarea.form-control {
        background-color: #000 !important;
        border: 1px solid var(--term-green) !important;
        color: var(--term-green) !important;
        font-family: var(--term-font) !important;
        border-radius: 0 !important;
    }

    .form-control:focus,
    .form-select:focus {
        box-shadow: 0 0 10px var(--term-dim) !important;
    }

    .btn {
        font-family: var(--term-font) !important;
        text-transform: uppercase;
        border-radius: 0 !important;
        border: 1px solid transparent;
    }

    .btn-primary,
    .btn-success {
        background: transparent !important;
        border-color: var(--term-green) !important;
        color: var(--term-green) !important;
    }

    .btn-primary:hover,
    .btn-success:hover {
        background: var(--term-green) !important;
        color: #000 !important;
    }

    .btn-danger {
        background: transparent !important;
        border-color: var(--term-red) !important;
        color: var(--term-red) !important;
    }

    .btn-danger:hover {
        background: var(--term-red) !important;
        color: #000 !important;
    }

    .btn-warning {
        background: transparent !important;
        border-color: var(--term-yellow) !important;
        color: var(--term-yellow) !important;
    }

    .btn-warning:hover {
        background: var(--term-yellow) !important;
        color: #000 !important;
    }

    .badge {
        font-family: var(--term-font);
        border-radius: 0 !important;
        border: 1px solid currentColor;
        background: transparent !important;
    }

    .alert {
        background: transparent !important;
        border-radius: 0 !important;
        border: 1px solid;
        font-family: var(--term-font);
    }

    .alert-success {
        border-color: var(--term-green);
        color: var(--term-green);
    }

    .alert-danger {
        border-color: var(--term-red);
        color: var(--term-red);
    }

    .alert-info {
        border-color: var(--term-blue);
        color: var(--term-blue);
    }

    .guard-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.75);
        z-index: 99990;
        display: none;
    }

    .guard-modal {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: min(820px, calc(100vw - 24px));
        background: #000;
        border: 1px solid var(--term-dim);
        box-shadow: 0 0 14px rgba(51, 255, 0, 0.15);
        z-index: 100000;
    }

    .guard-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        border-bottom: 1px solid rgba(51, 255, 0, 0.2);
    }

    .guard-modal-title {
        font-family: var(--term-font);
        color: var(--term-green);
        text-shadow: var(--term-glow);
        letter-spacing: 2px;
        text-transform: uppercase;
        font-size: 14px;
        margin: 0;
    }

    .guard-modal-body {
        padding: 12px;
        font-family: var(--term-font);
        font-size: 13px;
    }

    .guard-modal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    @media (max-width: 720px) {
        .guard-modal-grid {
            grid-template-columns: 1fr;
        }
    }

    .guard-modal-kv {
        border: 1px solid rgba(51, 255, 0, 0.2);
        padding: 10px;
        background: rgba(255, 255, 255, 0.01);
    }

    .guard-modal-kv .k {
        color: rgba(255, 255, 255, 0.55);
        display: block;
        font-size: 12px;
        margin-bottom: 6px;
    }

    .guard-modal-kv .v {
        color: var(--term-green);
        font-weight: 700;
        word-break: break-word;
    }

    .guard-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .guard-table {
        width: 100%;
        min-width: 980px;
        font-family: var(--term-font);
        color: var(--term-green);
        background: #000;
        border: 1px solid rgba(51, 255, 0, 0.2);
    }

    .guard-table thead th {
        color: rgba(255, 255, 255, 0.75);
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 12px;
        border-bottom: 1px solid rgba(51, 255, 0, 0.2) !important;
        white-space: nowrap;
    }

    .guard-table td {
        font-size: 12px;
        border-top: 1px solid rgba(51, 255, 0, 0.12) !important;
        vertical-align: middle;
        white-space: nowrap;
    }

    .guard-table .td-truncate {
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="bg-secondary rounded p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-dark pb-3">
                    <h3 class="mb-0"><i class="fas fa-terminal me-2"></i> SYSTEM.ADMIN_GUARD</h3>
                    <div class="stat-block"><span class="text-muted">AI:</span> <span
                            class="text-success fw-bold">ACTIVE</span></div>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success mb-4"><?php echo h(
                        $success_message,
                    ); ?></div><?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert alert-danger mb-4"><?php echo h(
                        $error_message,
                    ); ?></div><?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <ul class="nav nav-tabs border-dark mb-0" id="securityTabs">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab === 'users' ? 'active' : ''; ?> text-success border-dark"
                               href="?tab=users" style="background: <?php echo $activeTab === 'users' ? 'rgba(51, 255, 0, 0.1)' : 'transparent'; ?>;">
                               [ USER_MANAGEMENT ]
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab === 'guests' ? 'active' : ''; ?> text-success border-dark"
                               href="?tab=guests" style="background: <?php echo $activeTab === 'guests' ? 'rgba(51, 255, 0, 0.1)' : 'transparent'; ?>;">
                               [ GUEST_BLOCKS ]
                               <?php if(count($blockedGuests) > 0): ?>
                                 <span class="badge bg-danger ms-1"><?php echo count($blockedGuests); ?></span>
                               <?php endif; ?>
                            </a>
                        </li>
                    </ul>

                    <form method="POST" class="d-inline-block">
                        <input type="hidden" name="action" value="clean_database">
                        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('⚠️ WARNING: This will clean ALL security-related database tables. Are you sure?');">
                            <i class="fas fa-database me-1"></i> CLEAN DATABASE
                        </button>
                    </form>
                </div>

                <div class="tab-content">
                    <!-- Tab 1: Users -->
                    <div class="tab-pane fade <?php echo $activeTab === 'users' ? 'show active' : ''; ?>">
                        <div class="row mb-4 g-2">
                    <div class="col-md-6">
                        <label class="small text-muted mb-1" for="lookup-input">> VIEW_USER</label>
                        <form method="GET">
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-success">></span>
                                <input type="text" class="form-control" name="user" id="lookup-input"
                                    value="<?php echo h(
                                        $lookup,
                                    ); ?>" placeholder="user_id / username">
                                <button class="btn btn-primary" type="submit">[ VIEW ]</button>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <label class="small text-muted mb-1" for="query-input">> USERS_FILTER</label>
                        <form method="GET">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="input-group">
                                        <span class="input-group-text bg-dark border-secondary text-success">></span>
                                        <input type="text" class="form-control" name="q" id="query-input"
                                            value="<?php echo h(
                                                $listQuery,
                                            ); ?>" placeholder="search...">
                                    </div>
                                </div>
                                <div class="col-6">
                                    <select class="form-select w-100" name="status">
                                        <option value="all" <?php echo $statusFilter ===
                                        "all"
                                            ? "selected"
                                            : ""; ?>>ALL
                                        </option>
                                        <option value="active" <?php echo $statusFilter ===
                                        "active"
                                            ? "selected"
                                            : ""; ?>>
                                            ACTIVE
                                        </option>
                                        <option value="suspicious" <?php echo $statusFilter ===
                                        "suspicious"
                                            ? "selected"
                                            : ""; ?>>
                                            SUSPICIOUS
                                        </option>
                                        <option value="temp_banned" <?php echo $statusFilter ===
                                        "temp_banned"
                                            ? "selected"
                                            : ""; ?>>TEMP_BANNED</option>
                                        <option value="suspended" <?php echo $statusFilter ===
                                        "suspended"
                                            ? "selected"
                                            : ""; ?>>
                                            SUSPENDED</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select class="form-select w-100" name="account_date_filter">
                                        <option value="" <?php echo $accountDateFilter ===
                                        ""
                                            ? "selected"
                                            : ""; ?>>ALL_DATES
                                        </option>
                                        <option value="today" <?php echo $accountDateFilter ===
                                        "today"
                                            ? "selected"
                                            : ""; ?>>TODAY
                                        </option>
                                        <option value="week" <?php echo $accountDateFilter ===
                                        "week"
                                            ? "selected"
                                            : ""; ?>>LAST_WEEK
                                        </option>
                                        <option value="month" <?php echo $accountDateFilter ===
                                        "month"
                                            ? "selected"
                                            : ""; ?>>LAST_MONTH
                                        </option>
                                        <option value="3months" <?php echo $accountDateFilter ===
                                        "3months"
                                            ? "selected"
                                            : ""; ?>>LAST_3_MONTHS
                                        </option>
                                        <option value="6months" <?php echo $accountDateFilter ===
                                        "6months"
                                            ? "selected"
                                            : ""; ?>>LAST_6_MONTHS
                                        </option>
                                        <option value="year" <?php echo $accountDateFilter ===
                                        "year"
                                            ? "selected"
                                            : ""; ?>>LAST_YEAR
                                        </option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary w-100" type="submit">[ APPLY ]</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="bg-dark p-3">
                    <h5 class="mb-3">> USERS_LIST <span class="small text-muted">(TOTAL:
                            <?php echo $totalUsers; ?>)</span></h5>
                    <?php if (empty($usersList)): ?>
                        <div class="alert alert-info text-center">NO USERS FOUND.</div>
                    <?php else: ?>
                        <div class="guard-table-wrap">
                            <table class="table table-dark guard-table mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Bans</th>
                                        <th>Err</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Until</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usersList as $u): ?>
                                        <tr>
                                            <td class="text-info">#<?php echo h(
                                                $u["id"],
                                            ); ?></td>
                                            <td class="td-truncate text-white">@<?php echo h(
                                                $u["username"],
                                            ); ?></td>
                                            <td class="text-warning"><?php echo number_format(
                                                $u["ban_count"],
                                            ); ?></td>
                                            <td class="text-warning"><?php echo number_format(
                                                $u["error_window_count"],
                                            ); ?></td>
                                            <td>
                                                <?php if (
                                                    $u["guard_status"] ===
                                                    "ACTIVE"
                                                ): ?><span
                                                        class="badge bg-success">ACTIVE</span>
                                                <?php elseif (
                                                    $u["guard_status"] ===
                                                    "TEMP_BANNED"
                                                ): ?><span
                                                        class="badge bg-warning">TEMP_BANNED</span>
                                                <?php else: ?><span class="badge bg-danger">SUSPENDED</span><?php endif; ?>
                                            </td>
                                            <td class="td-truncate text-info"><?php echo h($u["created_at"] ?? "-"); ?></td>
                                            <td class="td-truncate text-danger"><?php echo h($u["ban_until"] ?? "-"); ?></td>
                                            <td class="text-end">
                                                <div class="d-inline-flex gap-2">
                                                    <button type="button" class="btn btn-primary btn-sm js-user-modal"
                                                        data-user-id="<?php echo h(
                                                            $u["id"],
                                                        ); ?>"
                                                        data-username="<?php echo h(
                                                            $u["username"],
                                                        ); ?>"
                                                        data-display-name="<?php echo h(
                                                            $u["display_name"],
                                                        ); ?>"
                                                        data-email="<?php echo h(
                                                            $u["email"],
                                                        ); ?>"
                                                        data-subscription="<?php echo h(
                                                            $u["subscription"],
                                                        ); ?>"
                                                        data-points="<?php echo h(
                                                            $u["points"],
                                                        ); ?>"
                                                        data-ban-count="<?php echo h(
                                                            $u["ban_count"],
                                                        ); ?>"
                                                        data-error-count="<?php echo h(
                                                            $u[
                                                                "error_window_count"
                                                            ],
                                                        ); ?>"
                                                        data-status="<?php echo h(
                                                            $u["guard_status"],
                                                        ); ?>"
                                                        data-ban-until="<?php echo h(
                                                            $u["ban_until"] ??
                                                                "",
                                                        ); ?>">[ VIEW
                                                        ]</button>
                                                    <form method="POST" action="<?php echo h(
                                                        $listFormAction,
                                                    ); ?>"
                                                        class="d-inline-flex gap-2">
                                                        <?php echo Csrf::getTokenField(); ?>
                                                        <input type="hidden" name="target_user_id"
                                                            value="<?php echo h(
                                                                $u["id"],
                                                            ); ?>">
                                                        <?php if (
                                                            $u[
                                                                "guard_status"
                                                            ] !== "ACTIVE"
                                                        ): ?>
                                                            <button type="submit" name="action" value="unban"
                                                                class="btn btn-warning btn-sm">[ UNBAN ]</button>
                                                        <?php else: ?>
                                                            <button type="submit" name="action" value="ban"
                                                                class="btn btn-danger btn-sm">[ BAN ]</button>
                                                        <?php endif; ?>
                                                        <button type="submit" name="action" value="reset_full"
                                                            class="btn btn-primary btn-sm">[ RESET ]</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPages > 1): ?>
                            <div class="d-flex justify-content-center mt-3 gap-2">
                                <?php for (
                                    $p = max(1, $page - 3);
                                    $p <= min($totalPages, $page + 3);
                                    $p++
                                ): ?>
                                    <a class="btn btn-sm <?php echo $p === $page
                                        ? "btn-success"
                                        : "btn-primary"; ?>"
                                        href="?<?php echo http_build_query(
                                            array_merge($_GET, ["page" => $p]),
                                        ); ?>">[
                                        <?php echo $p; ?> ]</a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab 2: Guest Blocks -->
            <div class="tab-pane fade <?php echo $activeTab === 'guests' ? 'show active' : ''; ?>">
                <div class="bg-dark p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">> BLOCKED_GUESTS <span class="small text-muted">(TOTAL: <?php echo count($blockedGuests); ?>)</span></h5>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to reset all rate limit counters?');">
                            <?php echo Csrf::getTokenField(); ?>
                            <button type="submit" name="action" value="reset_rate_limit" class="btn btn-warning btn-sm">
                                <i class="fas fa-trash-alt me-1"></i> [ RESET ALL RATE_LIMITS ]
                            </button>
                        </form>
                    </div>
                    <?php if (empty($blockedGuests)): ?>
                        <div class="alert alert-info text-center">NO BLOCKED GUESTS FOUND.</div>
                    <?php else: ?>
                        <div class="guard-table-wrap">
                            <table class="table table-dark guard-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Identifier</th>
                                        <th>Blocked Until</th>
                                        <th>Reason</th>
                                        <th>Created At</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blockedGuests as $guest):
                                                $isExpired = strtotime((string)$guest["blocked_until"]) < time();
                                            ?>
                                                <tr style="<?php echo $isExpired ? 'opacity: 0.5;' : ''; ?>">
                                                    <td class="text-info td-truncate" title="<?php echo h($guest["ip_address"]); ?>">
                                                        <?php if (str_starts_with($guest["ip_address"], 'ua_')): ?>
                                                            <i class="fas fa-fingerprint me-1" title="Browser Fingerprint"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-network-wired me-1" title="IP Address"></i>
                                                        <?php endif; ?>
                                                        <?php echo h($guest["ip_address"]); ?>
                                                    </td>
                                                    <td class="<?php echo $isExpired ? 'text-muted' : 'text-danger'; ?>">
                                                        <?php echo h($guest["blocked_until"]); ?>
                                                        <?php if ($isExpired): ?> <span class="badge bg-secondary ms-1">EXPIRED</span> <?php endif; ?>
                                                    </td>
                                                    <td class="td-truncate text-warning" title="<?php echo h($guest["reason"]); ?>">
                                                        <?php echo h($guest["reason"]); ?>
                                                    </td>
                                                    <td class="text-muted"><?php echo h($guest["created_at"]); ?></td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-2">
                                                            <button type="button" class="btn btn-primary btn-sm js-guest-modal"
                                                                data-id="<?php echo h($guest["id"]); ?>"
                                                                data-identifier="<?php echo h($guest["ip_address"]); ?>"
                                                                data-until="<?php echo h($guest["blocked_until"]); ?>"
                                                                data-reason="<?php echo h($guest["reason"]); ?>"
                                                                data-created="<?php echo h($guest["created_at"]); ?>"
                                                                data-expired="<?php echo $isExpired ? '1' : '0'; ?>">[ VIEW ]</button>
                                                            <form method="POST">
                                                                <?php echo Csrf::getTokenField(); ?>
                                                                <input type="hidden" name="action" value="unblock_guest">
                                                                <input type="hidden" name="guest_identifier" value="<?php echo h($guest["ip_address"]); ?>">
                                                                <button type="submit" class="btn <?php echo $isExpired ? 'btn-outline-secondary' : 'btn-warning'; ?> btn-sm">[ <?php echo $isExpired ? 'DELETE' : 'UNBLOCK'; ?> ]</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3 small text-muted">
                        * Guest blocks are based on browser fingerprints (User-Agent) to prevent bypasses.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</div>

<div class="guard-modal-backdrop" id="guardUserModalBackdrop"></div>
<dialog class="guard-modal" id="guardUserModal" aria-labelledby="guardUserModalTitle">
    <div class="guard-modal-header">
        <h4 class="guard-modal-title" id="guardUserModalTitle">USER_DETAILS</h4>
        <button type="button" class="btn btn-danger btn-sm" id="guardUserModalClose">[ CLOSE ]</button>
    </div>
    <div class="guard-modal-body">
        <div class="guard-modal-grid">
            <div class="guard-modal-kv"><span class="k">USER ID</span><span class="v" id="gm_user_id">-</span></div>
            <div class="guard-modal-kv"><span class="k">USERNAME</span><span class="v" id="gm_username">-</span></div>
            <div class="guard-modal-kv"><span class="k">DISPLAY NAME</span><span class="v" id="gm_display_name">-</span>
            </div>
            <div class="guard-modal-kv"><span class="k">EMAIL</span><span class="v" id="gm_email">-</span></div>
            <div class="guard-modal-kv"><span class="k">SUBSCRIPTION</span><span class="v" id="gm_subscription">-</span>
            </div>
            <div class="guard-modal-kv"><span class="k">BALANCE</span><span class="v" id="gm_points">-</span></div>
            <div class="guard-modal-kv"><span class="k">STATUS</span><span class="v" id="gm_status">-</span></div>
            <div class="guard-modal-kv"><span class="k">BAN UNTIL</span><span class="v" id="gm_ban_until">-</span></div>
        </div>

        <div class="mt-3 pt-3" style="border-top: 1px solid rgba(51, 255, 0, 0.2);">
            <form method="POST" id="changeSubscriptionForm">
                <?php echo Csrf::getTokenField(); ?>
                <input type="hidden" name="target_user_id" id="gm_target_user_id" value="">
                <input type="hidden" name="action" value="change_subscription">
                <input type="hidden" name="redirect_user" id="gm_redirect_user" value="">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="small text-muted mb-1" for="gm_new_subscription">> CHANGE_SUBSCRIPTION</label>
                        <select class="form-select" name="new_subscription" id="gm_new_subscription">
                            <option value="free">FREE</option>
                            <option value="basic">BASIC</option>
                            <option value="pro">PRO</option>
                            <option value="premium">PREMIUM</option>
                            <option value="vip">VIP</option>
                            <option value="admin">ADMIN</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary w-100">[ UPDATE ]</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="mt-3 pt-3" style="border-top: 1px solid rgba(51, 255, 0, 0.2);">
            <form method="POST" id="changePointsForm">
                <?php echo Csrf::getTokenField(); ?>
                <input type="hidden" name="target_user_id" id="gm_target_user_id_points" value="">
                <input type="hidden" name="action" value="change_points">
                <input type="hidden" name="redirect_user" id="gm_redirect_user_points" value="">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="small text-muted mb-1" for="gm_new_points">> CHANGE_POINTS</label>
                        <input type="number" class="form-control" name="new_points" id="gm_new_points" min="0" step="1" placeholder="Enter points">
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary w-100">[ UPDATE ]</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</dialog>

<div class="guard-modal-backdrop" id="guardGuestModalBackdrop"></div>
<dialog class="guard-modal" id="guardGuestModal" aria-labelledby="guardGuestModalTitle">
    <div class="guard-modal-header">
        <h4 class="guard-modal-title" id="guardGuestModalTitle">GUEST_BLOCK_DETAILS</h4>
        <button type="button" class="btn btn-danger btn-sm" id="guardGuestModalClose">[ CLOSE ]</button>
    </div>
    <div class="guard-modal-body">
        <div class="guard-modal-grid mb-3">
            <div class="guard-modal-kv"><span class="k">BLOCK ID</span><span class="v" id="gm_g_id">-</span></div>
            <div class="guard-modal-kv"><span class="k">IDENTIFIER</span><span class="v" id="gm_g_identifier">-</span></div>
            <div class="guard-modal-kv"><span class="k">STATUS</span><span class="v" id="gm_g_status">-</span></div>
            <div class="guard-modal-kv"><span class="k">CREATED AT</span><span class="v" id="gm_g_created">-</span></div>
            <div class="guard-modal-kv" style="grid-column: span 2;"><span class="k">BLOCKED UNTIL</span><span class="v" id="gm_g_until">-</span></div>
        </div>
        <div class="bg-dark p-2 border border-dark">
            <span class="k text-muted small d-block mb-1">> FULL_REASON_LOG</span>
            <div id="gm_g_reason" class="v text-warning" style="word-break: break-all; font-family: var(--term-font); font-size: 12px; max-height: 200px; overflow-y: auto;">-</div>
        </div>
    </div>
</dialog>

<script>
    (function () {
        const userModal = document.getElementById('guardUserModal');
        const userBackdrop = document.getElementById('guardUserModalBackdrop');
        const guestModal = document.getElementById('guardGuestModal');
        const guestBackdrop = document.getElementById('guardGuestModalBackdrop');

        function setText(id, val) {
            const el = document.getElementById(id);
            if (el) el.textContent = val || 'NULL';
        }

        document.addEventListener('click', e => {
            const t = e.target;

            // User Modal Logic
            if (t.classList.contains('js-user-modal')) {
                const d = t.dataset;
                setText('gm_user_id', d.userId);
                setText('gm_username', d.username);
                setText('gm_display_name', d.displayName);
                setText('gm_email', d.email);
                setText('gm_subscription', d.subscription);
                setText('gm_points', d.points);
                setText('gm_status', d.status);
                setText('gm_ban_until', d.banUntil);

                // Populate form fields
                document.getElementById('gm_target_user_id').value = d.userId;
                document.getElementById('gm_redirect_user').value = d.username;
                document.getElementById('gm_new_subscription').value = d.subscription || 'free';

                // Populate points form fields
                document.getElementById('gm_target_user_id_points').value = d.userId;
                document.getElementById('gm_redirect_user_points').value = d.username;
                document.getElementById('gm_new_points').value = d.points || '0';

                if (typeof userModal.showModal === 'function') {
                    userModal.showModal();
                } else {
                    userModal.show();
                }
                userBackdrop.style.display = 'block';
            }
            if (t.id === 'guardUserModalClose' || t.id === 'guardUserModalBackdrop') {
                userModal.close();
                userBackdrop.style.display = 'none';
            }

            // Guest Modal Logic
            if (t.classList.contains('js-guest-modal')) {
                const d = t.dataset;
                setText('gm_g_id', '#' + d.id);
                setText('gm_g_identifier', d.identifier);
                setText('gm_g_until', d.until);
                setText('gm_g_created', d.created);
                setText('gm_g_reason', d.reason);
                setText('gm_g_status', d.expired === '1' ? 'EXPIRED' : 'ACTIVE');

                const statusEl = document.getElementById('gm_g_status');
                if (statusEl) {
                    statusEl.className = 'v ' + (d.expired === '1' ? 'text-muted' : 'text-danger');
                }

                if (typeof guestModal.showModal === 'function') {
                    guestModal.showModal();
                } else {
                    guestModal.show();
                }
                guestBackdrop.style.display = 'block';
            }
            if (t.id === 'guardGuestModalClose' || t.id === 'guardGuestModalBackdrop') {
                guestModal.close();
                guestBackdrop.style.display = 'none';
            }
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                userModal.close();
                userBackdrop.style.display = 'none';
                guestModal.close();
                guestBackdrop.style.display = 'none';
            }
        });
    })();
</script>

<?php PageController::end("./"); ?>
