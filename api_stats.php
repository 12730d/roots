<?php

// api_stats.php - API endpoint for fetching statistics
function loadEnv(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // skip comments
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // split only on first '='
        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        // remove surrounding quotes
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // add escape support
        $value = str_replace(['\n', '\r', '\t'], ["\n", "\r", "\t"], $value);

        $_ENV[$key] = $value;
        putenv("$key=$value");
    }

    $loaded = true;
}

loadEnv(__DIR__ . '/.env');
// Use proper session initialization matching the application
require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Controllers\PageController;

const JSON_HEADER = "Content-Type: application/json";
const HEADER_CONN_CLOSE = 'Connection: close';
const HEADER_CONTENT_LENGTH = 'Content-Length: ';

// Initialize page with proper session handling
$pageData = PageController::setup("API_Stats", "./", [], ["require_auth" => true, "render_layout" => false]);

$username = (string) ($pageData["user"]["username"] ?? "");
$db = $pageData["db"] ?? null;

// Check authentication
if (empty($username)) {
    header(JSON_HEADER);
    header(HEADER_CONN_CLOSE);
    $response = json_encode(['success' => false, 'error' => 'Unauthorized']);
    if ($response !== false) {
        header(HEADER_CONTENT_LENGTH . strlen($response));
        echo $response;
    }
    exit();
}

// Check database connection
if (!$db) {
    header(JSON_HEADER);
    header(HEADER_CONN_CLOSE);
    $response = json_encode(['success' => false, 'error' => 'Database connection failed']);
    if ($response !== false) {
        header(HEADER_CONTENT_LENGTH . strlen($response));
        echo $response;
    }
    exit();
}

try {
    // Handle different actions
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // Check pending purchases limit
    if ($action === 'check_pending_purchases') {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) as pending_count 
                FROM purchases 
                WHERE username = ? AND status = 'pending'
            ");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $pending_count = $result->fetch_assoc()['pending_count'] ?? 0;
            
            $response = json_encode([
                'success' => true,
                'pending_count' => $pending_count
            ]);
            header(JSON_HEADER);
            header(HEADER_CONN_CLOSE);
            if ($response !== false) {
                header(HEADER_CONTENT_LENGTH . strlen($response));
                echo $response;
            }
        } catch (Exception $e) {
            $response = json_encode([
                'success' => false,
                'error' => 'Failed to check pending purchases: ' . $e->getMessage()
            ]);
            header(JSON_HEADER);
            header(HEADER_CONN_CLOSE);
            if ($response !== false) {
                header(HEADER_CONTENT_LENGTH . strlen($response));
                echo $response;
            }
        }
        exit();
    }

    // Fetch statistics
    $stats = [];

    // Total account checks (last 30 days)
    try {
        $stmt_checks = $db->query(
            "SELECT COUNT(*) as total_checks FROM api_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        );
        $stats["account_checks"] = $stmt_checks->fetch_assoc()["total_checks"] ?? 0;
    } catch (Exception $e) {
        $stats["account_checks"] = 0;
    }

    // Detect if login.verified column exists before querying to avoid schema errors
    $hasVerifiedColumn = false;
    try {
        $stmtCol = $db->query(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login' AND COLUMN_NAME = 'verified' LIMIT 1",
        );
        $hasVerifiedColumn = $stmtCol && $stmtCol->num_rows > 0;
    } catch (Exception $e) {
        $hasVerifiedColumn = false;
    }

    if ($hasVerifiedColumn) {
        // Verified accounts
        try {
            $stmt_verified = $db->query(
                "SELECT COUNT(*) as verified_count FROM login WHERE verified = 1",
            );
            $stats["verified_accounts"] =
                $stmt_verified->fetch_assoc()["verified_count"] ?? 0;
        } catch (Exception $e) {
            $stats["verified_accounts"] = 0;
        }

        // Unknown accounts
        try {
            $stmt_unknown = $db->query(
                "SELECT COUNT(*) as unknown_count FROM login WHERE verified = 0 OR verified IS NULL",
            );
            $stats["unknown_accounts"] =
                $stmt_unknown->fetch_assoc()["unknown_count"] ?? 0;
        } catch (Exception $e) {
            $stats["unknown_accounts"] = 0;
        }
    } else {
        $stats["verified_accounts"] = 0;
        $stats["unknown_accounts"] = 0;
    }

    // Check if breached_passwords table exists before querying
    $hasBreachedTable = false;
    try {
        $stmtTable = $db->query("SHOW TABLES LIKE 'breached_passwords'");
        $hasBreachedTable = $stmtTable && $stmtTable->num_rows > 0;
    } catch (Exception $e) {
        $hasBreachedTable = false;
    }

    if ($hasBreachedTable) {
        // Breached passwords (last 30 days)
        try {
            $stmt_breached = $db->query(
                "SELECT COUNT(*) as breached_count FROM breached_passwords WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            );
            $stats["breached_passwords"] =
                $stmt_breached->fetch_assoc()["breached_count"] ?? 0;
        } catch (Exception $e) {
            $stats["breached_passwords"] = 0;
        }
    } else {
        // Table doesn't exist, set breached_passwords to 0
        $stats["breached_passwords"] = 0;
    }

    $response = json_encode(array_merge([
        "success" => true,
        "timestamp" => date("Y-m-d H:i:s"),
    ], $stats));
    header(JSON_HEADER);
    header(HEADER_CONN_CLOSE);
    if ($response !== false) {
        header(HEADER_CONTENT_LENGTH . strlen($response));
        echo $response;
    }
} catch (Exception $e) {
    $response = json_encode([
        "success" => false,
        "error" => "Database connection failed: " . $e->getMessage(),
    ]);
    header(JSON_HEADER);
    header(HEADER_CONN_CLOSE);
    if ($response !== false) {
        header(HEADER_CONTENT_LENGTH . strlen($response));
        echo $response;
    }
}

PageController::end("./");

