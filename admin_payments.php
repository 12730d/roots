<?php

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;
use ROOTS\Security\CsrfProtection;
use ROOTS\Exceptions\DatabaseException;
use ROOTS\Exceptions\PaymentException;
use ROOTS\Exceptions\DeletionException;
use ROOTS\Config\Database;
use ROOTS\Layout\MasterLayout;

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

// ============================================================================
// BAN STATUS CHECKING
// ============================================================================
$isBlockedUser = false;
try {
    if (class_exists('ROOTS\Auth\Session')) {
        \ROOTS\Auth\Session::start();
        if (\ROOTS\Auth\Session::isLoggedIn() && !empty($_SESSION["user_id"])) {
            $dbCheck = null;
            try {
                $dbCheck = Database::getConnection();
            } catch (Exception $e) {
                $dbCheck = null;
            }
            if ($dbCheck instanceof \mysqli) {
                $stmt = $dbCheck->prepare(
                    "SELECT ban_until, suspended FROM user_security_guard WHERE user_id = ? LIMIT 1",
                );
                if ($stmt) {
                    $uid = (int) $_SESSION["user_id"];
                    $stmt->bind_param("i", $uid);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        if (is_array($row)) {
                            if (!empty($row["suspended"])) {
                                $isBlockedUser = true;
                            } else {
                                $banUntil = $row["ban_until"] ?? null;
                                if (
                                    !empty($banUntil) &&
                                    strtotime((string) $banUntil) > time()
                                ) {
                                    $isBlockedUser = true;
                                }
                            }
                        }
                    } else {
                        error_log("Failed to execute ban check query: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    error_log("Failed to prepare ban check query: " . $dbCheck->error);
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error checking user ban status: " . $e->getMessage());
}

// Redirect blocked users
if ($isBlockedUser) {
    header("Location: blocked");
    exit();
}

// Setup page environment
// Note: We disable automatic CSRF protection in setup to handle it manually with specific logging
$env = PageController::setup(
    "Admin Console: Payments",
    "./",
    [],
    ["csrf_protection" => false, "render_layout" => false],
);

$db = $env["db"];
$currentUser = $env["user"];

// SECURITY: Enforce Admin Access
PageController::requireAdmin();

// Handle payment approval/rejection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // SECURITY: Verify CSRF token using the dedicated class
    if (!CsrfProtection::verifyToken()) {
        $error_message = "SECURITY ALERT: CSRF VALIDATION FAILED.";
        // Log the security event with IP context
        syslog(
            LOG_WARNING,
            "CSRF token validation failed for admin payments POST from IP: " .
                ($_SERVER["REMOTE_ADDR"] ?? "unknown"),
        );
    } else {
        // SECURITY: Strict input typing
        $transaction_id = filter_input(
            INPUT_POST,
            "transaction_id",
            FILTER_VALIDATE_INT,
        );
        $action = filter_input(INPUT_POST, "action", FILTER_SANITIZE_STRING);

        syslog(
            LOG_INFO,
            "Payment processing attempt - Transaction ID: " .
                ($transaction_id ?? "NULL") .
                ", Action: " .
                ($action ?? "NULL"),
        );

        if (
            $transaction_id &&
            ($action === "approve" || $action === "reject" || $action === "delete")
        ) {
            if ($action === "delete") {
                try {
                    $delete_query = "DELETE FROM payment_transactions WHERE id = ?";
                    $delete_stmt = $db->prepare($delete_query);
                    $delete_stmt->bind_param("i", $transaction_id);
                    if ($delete_stmt->execute()) {
                        $success_message = "SUCCESS: TRANSACTION RECORD PURGED FROM DATABASE";
                        syslog(LOG_INFO, "Payment record deleted - ID: $transaction_id by Admin");
                    } else {
                        throw new DeletionException($delete_stmt->error);
                    }
                    $delete_stmt->close();
                } catch (Exception $e) {
                    $error_message = "CRITICAL ERROR: Deletion failed.";
                    error_log("Payment deletion error (TxID: $transaction_id): " . $e->getMessage());
                }
            } else {
                $status = $action === "approve" ? "completed" : "failed";
                // SECURITY: Begin Transaction for Atomicity
                $db->begin_transaction();
                try {
                    // SECURITY: Row Locking (FOR UPDATE) to prevent race conditions (double spending)
                    $check_query =
                        "SELECT id, status, username, points_purchased, bonus_points FROM payment_transactions WHERE id = ? FOR UPDATE";
                    $check_stmt = $db->prepare($check_query);
                    if (!$check_stmt) {
                        throw new DatabaseException(
                            "DB PREPARE FAILED: " . $db->error,
                        );
                    }
                    $check_stmt->bind_param("i", $transaction_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $transaction_data = $check_result->fetch_assoc();
                    $check_stmt->close();

                    if (!$transaction_data) {
                        throw new PaymentException("ERROR: TRANSACTION_NOT_FOUND");
                    }

                    // SECURITY: Check status is stricly pending
                    if ($transaction_data["status"] !== "pending") {
                        $current_status = $transaction_data["status"];
                        $success_message =
                            "NOTICE: TRANSACTION ALREADY PROCESSED AS [" .
                            strtoupper($current_status) .
                            "]";
                        syslog(
                            LOG_INFO,
                            "Attempted duplicate process - ID: $transaction_id",
                        );
                        // We commit here to release the lock, but take no action
                        $db->commit();
                    } else {
                        // Logic Checks for Approval
                        if ($action === "approve") {
                            if ($transaction_data["points_purchased"] <= 0) {
                                throw new PaymentException(
                                    "ERROR: INVALID_DATA_INTEGRITY (Zero Points)",
                                );
                            }

                            // Check User Existence
                            $user_check_query =
                                "SELECT username FROM login WHERE username = ?";
                            $user_check_stmt = $db->prepare($user_check_query);
                            $user_check_stmt->bind_param(
                                "s",
                                $transaction_data["username"],
                            );
                            $user_check_stmt->execute();
                            $user_exists =
                                $user_check_stmt->get_result()->num_rows > 0;
                            $user_check_stmt->close();

                            if (!$user_exists) {
                                throw new PaymentException(
                                    "ERROR: USER_TARGET_MISSING (" .
                                        htmlspecialchars(
                                            $transaction_data["username"],
                                        ) .
                                        ")",
                                );
                            }
                        }

                        // 1. Update Transaction Status
                        $update_query =
                            "UPDATE payment_transactions SET status = ?, updated_at = NOW() WHERE id = ?";
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bind_param("si", $status, $transaction_id);
                        if (!$update_stmt->execute()) {
                            throw new PaymentException(
                                "DB WRITE FAILED: STATUS UPDATE",
                            );
                        }
                        $update_stmt->close();

                        // 2. Allocate Points (if approved)
                        if ($action === "approve") {
                            // We do this logically: Update points = points + new_points
                            $total_points =
                                $transaction_data["points_purchased"] +
                                $transaction_data["bonus_points"];

                            // SECURITY: Use atomic update query
                            $points_query =
                                "UPDATE login SET previous_points = points, points = points + ? WHERE username = ?";
                            $points_stmt = $db->prepare($points_query);
                            $points_stmt->bind_param(
                                "is",
                                $total_points,
                                $transaction_data["username"],
                            );

                            if (!$points_stmt->execute()) {
                                throw new PaymentException(
                                    "DB WRITE FAILED: POINT ALLOCATION",
                                );
                            }

                            if ($points_stmt->affected_rows === 0) {
                                throw new PaymentException(
                                    "DB WRITE FAILED: USER NOT UPDATED",
                                );
                            }
                            $points_stmt->close();

                            syslog(
                                LOG_INFO,
                                "Payment approved - ID: $transaction_id, User: {$transaction_data["username"]}, Points: $total_points",
                            );
                        } else {
                            syslog(
                                LOG_INFO,
                                "Payment rejected - ID: $transaction_id, User: {$transaction_data["username"]}",
                            );
                        }

                        $db->commit();
                        $success_message =
                            $action === "approve"
                                ? "SUCCESS: TRANSACTION AUTHORIZED"
                                : "SUCCESS: TRANSACTION TERMINATED";
                    }
                } catch (Exception $e) {
                    $db->rollback();
                    // SECURITY: Generic error for user, specific for log
                    $error_message =
                        "CRITICAL ERROR: Processing failed. Reference logs.";
                    error_log(
                        "Payment processing error (TxID: $transaction_id): " .
                            $e->getMessage(),
                    );
                }
            }
        } else {
            $error_message = "INVALID INPUT PARAMETERS";
        }
    }
}

// Pagination logic
$items_per_page = 8;
$page_input = filter_input(INPUT_GET, "page", FILTER_VALIDATE_INT);
$current_page = $page_input ? max(1, $page_input) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Count Query
$count_query = "SELECT COUNT(*) as total FROM payment_transactions";
$count_result = $db->query($count_query);
$total_items = $count_result->fetch_assoc()["total"];
$total_pages = ceil($total_items / $items_per_page);
$current_page = min($current_page, max(1, $total_pages));

// Data Fetch Query
// SECURITY: Using prepared statements for limit/offset
$query = "SELECT pt.*, l.email
    FROM payment_transactions pt
    LEFT JOIN login l ON pt.username = l.username
    ORDER BY pt.created_at DESC
    LIMIT ? OFFSET ?";

$stmt = $db->prepare($query);
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Close connection early if we are done with DB (though PageController::end might close it too)
// We leave it open for PageController logic if needed, but it's safe to use results.

$layout = MasterLayout::createDefault();
$layout->renderPageStart("Admin Console: Payments", "./");
?>

<style>
    /* --- TERMINAL CORE --- */
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

    /* CRT Overlay */
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

    /* --- COMPONENTS --- */
    .bg-secondary {
        background-color: #000 !important;
        border: 1px dashed var(--term-dim) !important;
    }

    .bg-dark {
        background-color: rgba(0, 20, 0, 0.8) !important;
        border: 1px solid var(--term-dim) !important;
    }

    /* Stats Panel */
    .stat-block {
        font-family: var(--term-font);
        border-left: 2px solid var(--term-green);
        padding-left: 10px;
        margin-left: 10px;
    }

    /* Inputs */
    .form-control,
    .form-select {
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

    /* Buttons */
    .btn {
        font-family: var(--term-font) !important;
        text-transform: uppercase;
        border-radius: 0 !important;
        transition: all 0.2s;
        border: 1px solid transparent;
    }

    .btn-primary {
        background: transparent !important;
        border-color: var(--term-green) !important;
        color: var(--term-green) !important;
    }

    .btn-primary:hover {
        background: var(--term-green) !important;
        color: #000 !important;
    }

    .btn-success {
        background: transparent !important;
        border-color: var(--term-green) !important;
        color: var(--term-green) !important;
    }

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

    /* Transaction Card */
    .transaction-card {
        transition: all 0.3s;
        border-left: 4px solid var(--term-dim) !important;
        position: relative;
    }

    .transaction-card:hover {
        border-left-color: var(--term-green) !important;
        background: rgba(0, 40, 0, 0.9) !important;
        transform: translateX(5px);
    }

    .transaction-card.pending {
        border-left-color: var(--term-yellow) !important;
    }

    .transaction-card.completed {
        border-left-color: var(--term-green) !important;
    }

    .transaction-card.failed {
        border-left-color: var(--term-red) !important;
    }

    /* Badges */
    .badge {
        font-family: var(--term-font);
        border-radius: 0 !important;
        border: 1px solid currentColor;
        background: transparent !important;
    }

    .bg-warning {
        color: var(--term-yellow) !important;
    }

    .bg-success {
        color: var(--term-green) !important;
    }

    .bg-danger {
        color: var(--term-red) !important;
    }

    .bg-info {
        color: var(--term-blue) !important;
    }

    .bg-secondary {
        color: var(--term-dim) !important;
        border-color: var(--term-dim) !important;
    }

    /* Alerts */
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

    /* Modal */
    .modal-content {
        background: #000 !important;
        border: 2px solid var(--term-green) !important;
        color: var(--term-green) !important;
        font-family: var(--term-font);
        box-shadow: 0 0 20px rgba(51, 255, 0, 0.2);
    }

    .modal-header,
    .modal-footer {
        border-color: var(--term-dim) !important;
    }

    .btn-close {
        filter: invert(1) grayscale(100%) sepia(100%) hue-rotate(50deg) saturate(500%);
    }

    /* Pagination */
    .page-link {
        background: #000 !important;
        border: 1px solid var(--term-dim) !important;
        color: var(--term-green) !important;
    }

    .page-item.active .page-link {
        background: var(--term-green) !important;
        color: #000 !important;
        border-color: var(--term-green) !important;
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="bg-secondary rounded p-4">

                <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-dark pb-3">
                    <h3 class="mb-0">
                        <i class="fas fa-terminal me-2"></i> SYSTEM.ADMIN_PAYMENTS
                    </h3>
                    <div class="d-flex text-uppercase small">
                        <div class="stat-block">
                            <span class="text-muted">PENDING:</span>
                            <span
                                class="text-warning fw-bold"><?php echo count(
                                    array_filter(
                                        $transactions,
                                        fn($t) => $t["status"] === "pending",
                                    ),
                                ); ?></span>
                        </div>
                        <div class="stat-block">
                            <span class="text-muted">COMPLETED:</span>
                            <span
                                class="text-success fw-bold"><?php echo count(
                                    array_filter(
                                        $transactions,
                                        fn($t) => $t["status"] === "completed",
                                    ),
                                ); ?></span>
                        </div>
                        <div class="stat-block">
                            <span class="text-muted">FAILED:</span>
                            <span
                                class="text-danger fw-bold"><?php echo count(
                                    array_filter(
                                        $transactions,
                                        fn($t) => $t["status"] === "failed",
                                    ),
                                ); ?></span>
                        </div>
                    </div>
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success mb-4" role="alert">
                        <i class="fas fa-check-square me-2"></i> <?php echo htmlspecialchars(
                            $success_message,
                        ); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger mb-4" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars(
                            $error_message,
                        ); ?>
                    </div>
                <?php endif; ?>

                <div class="row mb-4 g-2">
                    <div class="col-md-2">
                        <label class="small text-muted mb-1" for="statusFilter">> FILTER_STATUS</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">*</option>
                            <option value="pending">PENDING</option>
                            <option value="completed">COMPLETED</option>
                            <option value="failed">REJECTED</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small text-muted mb-1" for="cryptoFilter">> FILTER_CURRENCY</label>
                        <select class="form-select" id="cryptoFilter">
                            <option value="">*</option>
                            <option value="bitcoin">BTC</option>
                            <option value="ethereum">ETH</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small text-muted mb-1" for="dateFilter">> FILTER_DATE</label>
                        <input type="date" class="form-control" id="dateFilter">
                    </div>
                    <div class="col-md-6">
                        <label class="small text-muted mb-1" for="searchInput">> SEARCH_QUERY</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-success">></span>
                            <input type="text" class="form-control" id="searchInput"
                                placeholder="user / email / hash...">
                            <button class="btn btn-primary" type="button" onclick="filterTransactions()">[ SCAN
                                ]</button>
                            <button class="btn btn-outline-secondary" type="button" onclick="clearFilters()">[ RESET
                                ]</button>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <?php if (empty($transactions)): ?>
                        <div class="col-12">
                            <div class="alert alert-info text-center p-5">
                                <i class="fas fa-search me-2"></i> NO DATA FOUND IN CURRENT BUFFER.
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($transactions as $row): ?>
                            <div class="col-12 mb-3">
                                <div class="bg-dark p-3 transaction-card <?php echo htmlspecialchars(
                                    $row["status"],
                                ); ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-3 border-end border-secondary">
                                            <div class="small text-muted">ID: #<?php echo htmlspecialchars(
                                                $row["id"],
                                            ); ?></div>
                                            <h5 class="mb-0 text-white"><?php echo htmlspecialchars(
                                                $row["username"],
                                            ); ?></h5>
                                            <small
                                                class="text-muted d-block text-truncate"><?php echo htmlspecialchars(
                                                    $row["email"] ?: "NULL",
                                                ); ?></small>
                                            <span
                                                class="badge bg-secondary mt-1"><?php echo strtoupper(
                                                    htmlspecialchars(
                                                        $row["crypto_type"],
                                                    ),
                                                ); ?></span>
                                        </div>

                                        <div class="col-md-3 border-end border-secondary">
                                            <div class="d-flex flex-column">
                                                <span class="text-info">
                                                    BASE: <?php echo number_format(
                                                        $row[
                                                            "points_purchased"
                                                        ],
                                                    ); ?> PTS
                                                </span>
                                                <?php if (
                                                    $row["bonus_points"] > 0
                                                ): ?>
                                                    <small class="text-success">
                                                        BONUS: +<?php echo number_format(
                                                            $row[
                                                                "bonus_points"
                                                            ],
                                                        ); ?>
                                                    </small>
                                                <?php endif; ?>
                                                <small class="text-warning border-top border-secondary mt-1 pt-1">
                                                    TOTAL:
                                                    <?php echo number_format(
                                                        $row[
                                                            "points_purchased"
                                                        ] +
                                                            $row[
                                                                "bonus_points"
                                                            ],
                                                    ); ?>
                                                </small>
                                            </div>
                                        </div>

                                        <div class="col-md-2 border-end border-secondary text-center">
                                            <div class="mb-1">
                                                <?php if (
                                                    $row["status"] === "pending"
                                                ): ?>
                                                    <span class="badge bg-warning">[ PENDING ]</span>
                                                <?php elseif (
                                                    $row["status"] ===
                                                    "completed"
                                                ): ?>
                                                    <span class="badge bg-success">[ COMPLETED ]</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">[ REJECTED ]</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted d-block">
                                                <?php echo date(
                                                    "Y-m-d",
                                                    strtotime(
                                                        $row["created_at"],
                                                    ),
                                                ); ?>
                                            </small>
                                            <small class="text-muted">
                                                <?php echo date(
                                                    "H:i:s",
                                                    strtotime(
                                                        $row["created_at"],
                                                    ),
                                                ); ?>
                                            </small>
                                        </div>

                                        <div class="col-md-4 text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <?php if ($row["status"] === "pending"): ?>
                                                    <form method="POST" class="d-flex gap-2"
                                                        id="transactionForm<?php echo htmlspecialchars($row["id"]); ?>">
                                                        <input type="hidden" name="transaction_id"
                                                            value="<?php echo htmlspecialchars($row["id"]); ?>">
                                                        <?php echo CsrfProtection::tokenField(); ?>

                                                        <button type="button" value="approve" class="btn btn-success btn-sm"
                                                            onclick="confirmAction(this, <?php echo htmlspecialchars($row["id"]); ?>)">
                                                            [ APPROVE ]
                                                        </button>
                                                        <button type="button" value="reject" class="btn btn-warning btn-sm"
                                                            onclick="confirmAction(this, <?php echo htmlspecialchars($row["id"]); ?>)">
                                                            [ REJECT ]
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal"
                                                        data-bs-target="#transactionDetails<?php echo htmlspecialchars($row["id"]); ?>">
                                                        [ VIEW_LOGS ]
                                                    </button>
                                                <?php endif; ?>

                                                <form method="POST" onsubmit="return confirm('Delete this transaction?');">
                                                    <input type="hidden" name="transaction_id"
                                                        value="<?php echo htmlspecialchars($row["id"]); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <?php echo CsrfProtection::tokenField(); ?>
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        [ DELETE ]
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal fade" id="transactionDetails<?php echo htmlspecialchars(
                                $row["id"],
                            ); ?>"
                                tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">> LOG_ENTRY_#<?php echo htmlspecialchars(
                                                $row["id"],
                                            ); ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="p-2 border border-secondary mb-3">
                                                <div class="row mb-2">
                                                    <div class="col-4 text-muted">USER:</div>
                                                    <div class="col-8 text-end">
                                                        <?php echo htmlspecialchars(
                                                            $row["username"],
                                                        ); ?>
                                                    </div>
                                                </div>
                                                <div class="row mb-2">
                                                    <div class="col-4 text-muted">EMAIL:</div>
                                                    <div class="col-8 text-end">
                                                        <?php echo htmlspecialchars(
                                                            $row["email"] ?:
                                                            "NULL",
                                                        ); ?>
                                                    </div>
                                                </div>
                                                <div class="row mb-2">
                                                    <div class="col-4 text-muted">CRYPTO:</div>
                                                    <div class="col-8 text-end">
                                                        <?php echo strtoupper(
                                                            htmlspecialchars(
                                                                $row[
                                                                    "crypto_type"
                                                                ],
                                                            ),
                                                        ); ?>
                                                    </div>
                                                </div>
                                                <div class="row mb-2">
                                                    <div class="col-4 text-muted">HASH:</div>
                                                    <div class="col-8 text-end text-break small font-monospace">
                                                        <?php echo htmlspecialchars(
                                                            $row[
                                                                "transaction_hash"
                                                            ],
                                                        ); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="p-2 border border-secondary">
                                                <div class="row mb-2">
                                                    <div class="col-4 text-muted">AMOUNT:</div>
                                                    <div class="col-8 text-end text-info">
                                                        <?php echo number_format(
                                                            $row[
                                                                "points_purchased"
                                                            ],
                                                        ); ?>
                                                    </div>
                                                </div>
                                                <div class="row mb-2">
                                                    <div class="col-4 text-muted">BONUS:</div>
                                                    <div class="col-8 text-end text-success">
                                                        <?php echo number_format(
                                                            $row[
                                                                "bonus_points"
                                                            ],
                                                        ); ?>
                                                    </div>
                                                </div>
                                                <div class="row border-top border-secondary pt-2 mt-2">
                                                    <div class="col-4 text-muted">STATUS:</div>
                                                    <div class="col-8 text-end">
                                                        <?php echo strtoupper(
                                                            htmlspecialchars(
                                                                $row["status"],
                                                            ),
                                                        ); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top border-dark">
                        <div class="small text-muted">
                            DISPLAYING RANGE: <?php echo $offset + 1; ?> -
                            <?php echo min(
                                $offset + $items_per_page,
                                $total_items,
                            ); ?> / <?php echo $total_items; ?>
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($current_page > 1): ?>
                                    <li class="page-item"><a class="page-link"
                                            href="?page=<?php echo $current_page -
                                                1; ?>">&laquo; PREV</a></li>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $current_page - 2);
                                $end = min($total_pages, $current_page + 2);
                                if ($start > 1) {
                                    echo '<li class="page-item"><span class="page-link">...</span></li>';
                                }
                                for ($i = $start; $i <= $end; $i++) {
                                    $active =
                                        $i == $current_page ? "active" : "";
                                    echo "<li class='page-item $active'><a class='page-link' href='?page=$i'>[$i]</a></li>";
                                }
                                if ($end < $total_pages) {
                                    echo '<li class="page-item"><span class="page-link">...</span></li>';
                                }
                                ?>

                                <?php if ($current_page < $total_pages): ?>
                                    <li class="page-item"><a class="page-link"
                                            href="?page=<?php echo $current_page +
                                                1; ?>">NEXT &raquo;</a></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Theme SweetAlert
    const termSwal = Swal.mixin({
        customClass: {
            popup: 'bg-black border border-success text-success font-monospace rounded-0',
            title: 'text-success text-uppercase',
            content: 'text-success',
            confirmButton: 'btn btn-success rounded-0 mx-1',
            cancelButton: 'btn btn-danger rounded-0 mx-1'
        },
        buttonsStyling: false,
        background: '#000',
        color: '#33ff00'
    });

    function confirmDelete(transactionId) {
        termSwal.fire({
            title: 'PURGE RECORD?',
            text: 'This action will permanently delete the transaction record from the database.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '[ DELETE ]',
            cancelButtonText: '[ ABORT ]',
            confirmButtonColor: '#ff3333'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteForm' + transactionId).submit();
            }
        });
    }

    function confirmAction(button, transactionId) {
        const action = button.value;
        const title = action === 'approve' ? 'INITIATE TRANSFER?' : 'TERMINATE REQUEST?';
        const text = action === 'approve' ?
            'Confirming will allocate points to user database.' :
            'Confirming will permanently reject this transaction.';

        if (event) event.preventDefault();

        termSwal.fire({
            title: title,
            text: text,
            icon: action === 'approve' ? 'question' : 'warning',
            showCancelButton: true,
            confirmButtonText: '[ CONFIRM ]',
            cancelButtonText: '[ ABORT ]'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.getElementById('transactionForm' + transactionId);
                if (form) {
                    // Inject action hidden field
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = action;
                    form.appendChild(actionInput);
                    form.submit();
                }
            }
        });
        return false;
    }

    // Filter Logic
    const statusFilter = document.getElementById('statusFilter');
    const cryptoFilter = document.getElementById('cryptoFilter');
    const dateFilter = document.getElementById('dateFilter');
    const searchInput = document.getElementById('searchInput');
    const transactionCards = document.querySelectorAll('.transaction-card');

    function filterTransactions() {
        const status = statusFilter.value.toLowerCase();
        const crypto = cryptoFilter.value.toLowerCase();
        const date = dateFilter.value;
        const search = searchInput.value.toLowerCase();

        transactionCards.forEach(card => {
            // Safe class check for status
            let cardStatus = '';
            if (card.classList.contains('pending')) cardStatus = 'pending';
            else if (card.classList.contains('completed')) cardStatus = 'completed';
            else if (card.classList.contains('failed')) cardStatus = 'failed';

            const cardText = card.innerText.toLowerCase();
            // Basic date check - assumes date is present in text
            const dateMatch = !date || cardText.includes(date);

            const isVisible =
                (!status || cardStatus === status) &&
                (!crypto || cardText.includes(crypto)) &&
                (!search || cardText.includes(search)) &&
                dateMatch;

            card.closest('.col-12').style.display = isVisible ? 'block' : 'none';
        });
    }

    function clearFilters() {
        statusFilter.value = '';
        cryptoFilter.value = '';
        dateFilter.value = '';
        searchInput.value = '';
        filterTransactions();
    }

    // Event Listeners
    statusFilter.addEventListener('change', filterTransactions);
    cryptoFilter.addEventListener('change', filterTransactions);
    dateFilter.addEventListener('change', filterTransactions);
    searchInput.addEventListener('input', filterTransactions);
</script>
<?php PageController::end("./", ["js/main.js"]);
?>
