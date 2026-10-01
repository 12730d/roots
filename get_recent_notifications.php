<?php
declare(strict_types=1);

/**
 * Get Recent Notifications API Endpoint
 * Returns recent payment notifications for the logged-in user
 */

require_once __DIR__ . "/vendor/autoload.php";
use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Exceptions\DatabaseException;

// Set JSON response header first
header('Content-Type: application/json; charset=utf-8');
// Start session with proper error handling
try {
    Session::start();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Session initialization failed",
        "notifications" => [],
        "pending_count" => 0,
    ]);
    exit();
}

$markRead = isset($_GET["mark_read"]) && (string) $_GET["mark_read"] === "1";

// Check if user is logged in - return JSON error instead of redirecting
if (!Session::isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        "error" => "Unauthorized - Please log in to view notifications",
        "notifications" => [],
        "pending_count" => 0,
    ]);
    exit();
}

// Validate username exists in session
if (!isset($_SESSION["username"]) || empty($_SESSION["username"])) {
    http_response_code(401);
    echo json_encode([
        "error" => "Session invalid - username not found",
        "notifications" => [],
        "pending_count" => 0,
    ]);
    exit();
}

// Sanitize username
$username = trim($_SESSION["username"]);
if (empty($username)) {
    http_response_code(401);
    echo json_encode([
        "error" => "Invalid username in session",
        "notifications" => [],
        "pending_count" => 0,
    ]);
    exit();
}

// Get database connection
try {
    $db = Database::getConnection();

    if (!$db || !($db instanceof mysqli)) {
        http_response_code(500);
        echo json_encode([
            "error" => "Database connection failed",
            "notifications" => [],
            "pending_count" => 0,
        ]);
        exit();
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" =>
            "Database connection error: " .
            htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8"),
        "notifications" => [],
        "pending_count" => 0,
    ]);
    exit();
}

// Ensure admin notifications table exists
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

// Resolve user id (prefer session, fallback to DB)
$userId = (int) ($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
    $uidStmt = null;
    try {
        $uidStmt = $db->prepare(
            "SELECT id FROM login WHERE username = ? LIMIT 1",
        );
        if ($uidStmt) {
            $uidStmt->bind_param("s", $username);
            if ($uidStmt->execute()) {
                $uidRes = $uidStmt->get_result();
                if ($uidRes && ($uidRow = $uidRes->fetch_assoc())) {
                    $userId = (int) ($uidRow["id"] ?? 0);
                }
            }
        }
    } catch (Exception $e) {
        error_log(
            "Failed to resolve user ID in get_recent_notifications.php: " .
                $e->getMessage(),
        );
    } finally {
        if ($uidStmt instanceof mysqli_stmt) {
            $uidStmt->close();
        }
    }
}

// Get limit from request, default 5, max 30
$limit = isset($_GET["limit"]) ? (int) $_GET["limit"] : 5;
if ($limit < 1) {
    $limit = 5;
}
if ($limit > 30) {
    $limit = 30;
}

// Get recent payment status changes (last X)
$query = "(SELECT pt.id,
pt.points_purchased as points,
pt.status,
pt.created_at,
CASE
WHEN pt.status = 'completed' THEN 'Your payment request has been approved! Points have been added to your account.'
WHEN pt.status = 'failed' THEN 'Your payment request has been rejected. Please contact support.'
ELSE 'Your payment request is still pending admin approval.'
END as notification_message,
'payment' as source
FROM payment_transactions pt
WHERE pt.username = ?)
UNION ALL
(SELECT th.id,
th.points_amount as points,
'completed' as status,
th.created_at,
th.description as notification_message,
'history' as source
FROM transaction_history th
WHERE th.user_id = ? AND th.transaction_type IN ('sale', 'purchase'))
UNION ALL
(SELECT un.id,
0 as points,
'completed' as status,
un.created_at,
un.message as notification_message,
'admin' as source
FROM user_notifications un
WHERE un.user_id = ?)
ORDER BY created_at DESC
LIMIT {$limit}";

$stmt = null;
$notifications = [];
$formattedNotifications = [];

try {
    $stmt = $db->prepare($query);

    if (!$stmt) {
        throw new DatabaseException(
            "Prepare failed for notifications query: " . $db->error,
        );
    }

    $bindResult = $stmt->bind_param("sii", $username, $userId, $userId);

    if (!$bindResult) {
        throw new DatabaseException(
            "Bind failed for notifications query: " . $stmt->error,
        );
    }

    if (!$stmt->execute()) {
        throw new DatabaseException(
            "Execute failed for notifications query: " . $stmt->error,
        );
    }

    $result = $stmt->get_result();

    if ($result) {
        $notifications = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

    $stmt->close();
} catch (Exception $e) {
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    http_response_code(500);
    echo json_encode([
        "error" =>
            "Failed to fetch notifications: " .
            htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8"),
        "notifications" => [],
        "pending_count" => 0,
    ]);
    exit();
}

// Optionally mark admin notifications as read when explicitly requested
if ($markRead && $userId > 0) {
    $markStmt = null;
    try {
        $markStmt = $db->prepare(
            "UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
        );
        if ($markStmt) {
            $markStmt->bind_param("i", $userId);
            if (!$markStmt->execute()) {
                error_log("Failed to mark notifications as read: " . $markStmt->error);
            }
        }
    } catch (Exception $e) {
        error_log(
            "Failed to mark notifications as read in get_recent_notifications.php: " .
                $e->getMessage(),
        );
    } finally {
        if ($markStmt instanceof mysqli_stmt) {
            $markStmt->close();
        }
    }
}

// Format notifications for frontend with error handling
foreach ($notifications as $notification) {
    try {
        $points = isset($notification["points"])
            ? (int) $notification["points"]
            : 0;
        $createdAt = $notification["created_at"] ?? null;

        // Format date safely
        $formattedDate = "N/A";
        if (!empty($createdAt)) {
            try {
                $dateObj = new DateTime($createdAt);
                $formattedDate = $dateObj->format("M j, H:i");
            } catch (Exception $e) {
                // If date parsing fails, use original value or fallback
                $formattedDate = date("M j, H:i", strtotime($createdAt));
            }
        }

        $formattedNotifications[] = [
            "id" => isset($notification["id"]) ? (int) $notification["id"] : 0,
            "message" =>
                $notification["notification_message"] ??
                "Notification message not available",
            "points" => number_format($points),
            "status" => $notification["status"] ?? "unknown",
            "date" => $formattedDate,
            "raw_date" => $createdAt,
        ];
    } catch (Exception $e) {
        // Skip malformed notifications but continue processing others
        error_log("Error formatting notification: " . $e->getMessage());
        continue;
    }
}

// Get pending count for badge
$pendingCount = 0;
$pendingStmt = null;

try {
    $pendingQuery = "SELECT COUNT(*) as pending_count
FROM payment_transactions
WHERE username = ? AND status = 'pending'";

    $pendingStmt = $db->prepare($pendingQuery);

    if (!$pendingStmt) {
        throw new DatabaseException(
            "Prepare failed for pending count query: " . $db->error,
        );
    }

    $bindPendingResult = $pendingStmt->bind_param("s", $username);

    if (!$bindPendingResult) {
        throw new DatabaseException(
            "Bind failed for pending count query: " . $pendingStmt->error,
        );
    }

    if (!$pendingStmt->execute()) {
        throw new DatabaseException(
            "Execute failed for pending count query: " . $pendingStmt->error,
        );
    }

    $pendingResult = $pendingStmt->get_result();

    if ($pendingResult) {
        $pendingRow = $pendingResult->fetch_assoc();
        $pendingCount = isset($pendingRow["pending_count"])
            ? (int) $pendingRow["pending_count"]
            : 0;
        $pendingResult->free();
    }

    $pendingStmt->close();
} catch (Exception $e) {
    if ($pendingStmt instanceof mysqli_stmt) {
        $pendingStmt->close();
    }

    // Log error but don't fail the entire request - just return 0 for pending count
    error_log("Error fetching pending count: " . $e->getMessage());
    $pendingCount = 0;
}

// Add unread admin notifications to badge count
if ($userId > 0) {
    $unreadStmt = null;
    try {
        $unreadStmt = $db->prepare(
            "SELECT COUNT(*) as c FROM user_notifications WHERE user_id = ? AND is_read = 0",
        );
        if ($unreadStmt) {
            $unreadStmt->bind_param("i", $userId);
            if ($unreadStmt->execute()) {
                $unreadRes = $unreadStmt->get_result();
                if ($unreadRes && ($unreadRow = $unreadRes->fetch_assoc())) {
                    $pendingCount += (int) ($unreadRow["c"] ?? 0);
                }
            }
        }
    } catch (Exception $e) {
        error_log(
            "Failed to fetch unread admin notifications count in get_recent_notifications.php: " .
                $e->getMessage(),
        );
    } finally {
        if ($unreadStmt instanceof mysqli_stmt) {
            $unreadStmt->close();
        }
    }
}

// Database::getConnection returns shared connection, do not close it
// mysqli_close($db);

// Return JSON response
echo json_encode(
    [
        "notifications" => $formattedNotifications,
        "pending_count" => $pendingCount,
        "count" => $pendingCount, // Alias for backward compatibility with JavaScript
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
);

exit();
