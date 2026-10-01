<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Security\CsrfProtection;
use ROOTS\Exceptions\AdminException;
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

// Helper to safely escape output
if (!function_exists("h")) {
    function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, "UTF-8");
    }
}

// 1. Setup Admin Page
$pageData = PageController::setup("Admin: Withdrawals", "./", [], ["render_layout" => false]);
$db = $pageData["db"];

if (!$pageData["is_admin"]) {
    die("ACCESS_DENIED: ADMINISTRATIVE_PRIVILEGES_REQUIRED");
}

$success_message = null;
$error_message = null;

// 2. Handle POST Actions (Approve/Reject)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!CsrfProtection::verifyToken()) {
        $error_message = "SECURITY_FAILURE: CSRF_TOKEN_INVALID";
    } else {
        $withdrawal_id = isset($_POST["withdrawal_id"])
            ? (int) $_POST["withdrawal_id"]
            : 0;
        $action = $_POST["action"] ?? "";
        $tx_hash = trim($_POST["tx_hash"] ?? "");

        if (
            $withdrawal_id > 0 &&
            ($action === "approve" || $action === "reject" || $action === "delete")
        ) {
            mysqli_begin_transaction($db);
            try {
                if ($action === "delete") {
                    $delete_query = "DELETE FROM withdrawals WHERE id = ?";
                    $stmt = $db->prepare($delete_query);
                    $stmt->bind_param("i", $withdrawal_id);
                    $stmt->execute();
                    $stmt->close();
                    $success_message = "WITHDRAWAL_RECORD_DELETED: PURGED_FROM_SYSTEM";
                    mysqli_commit($db);
                } else {
                    // Fetch withdrawal data
                    $stmt = $db->prepare(
                        "SELECT w.*, l.username, l.id as user_numeric_id FROM withdrawals w JOIN login l ON w.user_id = l.id WHERE w.id = ? FOR UPDATE",
                    );
                    $stmt->bind_param("i", $withdrawal_id);
                    $stmt->execute();
                    $withdrawal = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$withdrawal) {
                        throw new AdminException(
                            "DATA_NOT_FOUND: WITHDRAWAL_ID_INVALID",
                        );
                    }
                    if ($withdrawal["status"] !== "pending") {
                        throw new AdminException(
                            "STATE_CONFLICT: WITHDRAWAL_ALREADY_PROCESSED",
                        );
                    }

                    if ($action === "approve") {
                        $update_query =
                            "UPDATE withdrawals SET status = 'completed', tx_hash = ?, created_at = created_at WHERE id = ?";
                        $stmt = $db->prepare($update_query);
                        $stmt->bind_param("si", $tx_hash, $withdrawal_id);
                        $stmt->execute();
                        $stmt->close();
                        $success_message =
                            "WITHDRAWAL_APPROVED: SEQUENCE_FINALIZED";
                    } else {
                        // Reject & Refund
                        $update_query =
                            "UPDATE withdrawals SET status = 'rejected' WHERE id = ?";
                        $stmt = $db->prepare($update_query);
                        $stmt->bind_param("i", $withdrawal_id);
                        $stmt->execute();
                        $stmt->close();

                        // Points Refund
                        $refund_query =
                            "UPDATE login SET points = points + ? WHERE id = ?";
                        $stmt = $db->prepare($refund_query);
                        $stmt->bind_param(
                            "ii",
                            $withdrawal["amount_pts"],
                            $withdrawal["user_numeric_id"],
                        );
                        $stmt->execute();
                        $stmt->close();

                        // Log Refund Transaction
                        $log_query =
                            "INSERT INTO transaction_history (user_id, transaction_type, amount, points_amount, description) VALUES (?, 'bonus', ?, ?, ?)";
                        $stmt = $db->prepare($log_query);
                        $desc = "Withdrawal Refund (ID: $withdrawal_id) - Request Rejected";
                        $stmt->bind_param(
                            "siis",
                            $withdrawal["username"],
                            $withdrawal["amount_pts"],
                            $withdrawal["amount_pts"],
                            $desc,
                        );
                        $stmt->execute();
                        $stmt->close();

                        $success_message =
                            "WITHDRAWAL_REJECTED: POINTS_REFUNDED_TO_USER";
                    }

                    mysqli_commit($db);
                }
            } catch (AdminException $e) {
                mysqli_rollback($db);
                $error_message = "LOGIC_ERROR: " . $e->getMessage();
            } catch (\Exception $e) {
                mysqli_rollback($db);
                error_log("[WITHDRAWALS] System Error: " . $e->getMessage());
                $error_message = "SYSTEM_ERROR: UNABLE_TO_PROCESS_REQUEST";
            }
        }
    }
}

// 3. Fetch Data for Display
$withdrawals = [];
$stats = ["pending" => 0, "completed" => 0, "rejected" => 0];

$query =
    "SELECT w.*, l.username, l.email FROM withdrawals w LEFT JOIN login l ON w.user_id = l.id ORDER BY w.created_at DESC LIMIT 100";
$result = mysqli_query($db, $query);
if ($result instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $withdrawals[] = $row;
        $status = (string) ($row["status"] ?? '');
        if (in_array($status, ['pending', 'completed', 'rejected'], true)) {
            $stats[$status]++;
        }
    }
}

$layout = MasterLayout::createDefault();
$layout->renderPageStart("Admin: Withdrawals", "./");
?>

<style>
    :root {
        --term-green: #33ff00;
        --term-red: #ff3333;
        --term-yellow: #ffff33;
        --term-bg: #050505;
        --term-panel: #0a0f0a;
    }

    body {
        background-color: var(--term-bg) !important;
        color: var(--term-green) !important;
        font-family: 'Courier New', Courier, monospace !important;
    }

    .admin-card {
        background: var(--term-panel);
        border: 1px solid rgba(51, 255, 0, 0.2);
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
    }

    .terminal-header {
        border-bottom: 2px solid var(--term-green);
        text-transform: uppercase;
        letter-spacing: 2px;
        text-shadow: 0 0 8px var(--term-green);
    }

    .stat-pill {
        border: 1px solid currentColor;
        padding: 2px 10px;
        font-size: 0.7rem;
        margin-left: 10px;
    }

    .withdrawal-item {
        background: rgba(0, 20, 0, 0.4);
        border-left: 3px solid var(--term-green);
        transition: 0.3s;
    }

    .withdrawal-item:hover {
        background: rgba(0, 50, 0, 0.6);
        transform: scale(1.005);
    }

    .status-pending {
        border-left-color: var(--term-yellow);
    }

    .status-completed {
        border-left-color: var(--term-green);
    }

    .status-rejected {
        border-left-color: var(--term-red);
    }

    .btn-term {
        border-radius: 0;
        text-transform: uppercase;
        font-size: 0.75rem;
        border: 1px solid currentColor;
        background: transparent;
    }

    .btn-term-success {
        color: var(--term-green);
    }

    .btn-term-success:hover {
        background: var(--term-green);
        color: #000;
    }

    .btn-term-danger {
        color: var(--term-red);
    }

    .btn-term-danger:hover {
        background: var(--term-red);
        color: #000;
    }
</style>

<div class="container-fluid pt-4 px-4">
    <div class="admin-card rounded p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-4 pb-2 terminal-header">
            <h4 class="mb-0"><i class="fas fa-shield-alt me-2"></i>ADMIN.MIXER_WITHDRAWALS</h4>
            <div class="d-flex align-items-center">
                <span class="stat-pill text-warning">PENDING:
                    <?= $stats["pending"] ?>
                </span>
                <span class="stat-pill text-success">DONE:
                    <?= $stats["completed"] ?>
                </span>
                <span class="stat-pill text-danger">REJECTED:
                    <?= $stats["rejected"] ?>
                </span>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success bg-transparent border-success text-success rounded-0 mb-4">
                >>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger bg-transparent border-danger text-danger rounded-0 mb-4">
                >> CRITICAL_ERROR:
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <?php if (empty($withdrawals)): ?>
                <div class="col-12 text-center py-5 opacity-50">
                    > NO_WITHDRAWAL_RECORDS_IN_BUFFER
                </div>
            <?php else: ?>
                <?php foreach ($withdrawals as $w): ?>
                    <?php $statusCls = match ($w["status"] ?? "pending") {
                        "pending" => "warning",
                        "completed" => "success",
                        default => "danger",
                    }; ?>
                    <div class="col-12">
                        <div class="withdrawal-item p-3 rounded status-<?= h(
                            $w["status"] ?? "pending",
                        ) ?>">
                            <div class="row align-items-center">
                                <div class="col-md-2">
                                    <div class="small text-muted">USER_ID: #
                                        <?= $w["user_id"] ?>
                                    </div>
                                    <div class="fw-bold text-white">
                                        <?= htmlspecialchars(
                                            (string) ($w["username"] ?? "UNKNOWN"),
                                        ) ?>
                                    </div>
                                    <div class="x-small text-muted" style="font-size: 0.6rem;">
                                        <?= htmlspecialchars(
                                            (string) ($w["email"] ?? ""),
                                        ) ?>
                                    </div>
                                </div>
                                <div class="col-md-2 text-center">
                                    <div class="text-success fw-bold"><?= number_format(
                                        (float) ($w["amount_pts"] ?? 0),
                                    ) ?> PTS
                                    </div>
                                    <div class="small text-muted">BTC:
                                        <?= $w["btc_amount"] ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="small text-muted">TARGET_BTC_ADDRESS:</div>
                                    <code class="text-info" style="font-size: 0.75rem;"><?= $w[
                                        "btc_address"
                                    ] ?></code>
                                </div>
                                <div class="col-md-2 text-center">
                                    <span class="badge bg-transparent border-<?= $statusCls ?> text-<?= $statusCls ?>">
                                        <?= strtoupper(
                                            h($w["status"] ?? "UNKNOWN"),
                                        ) ?>
                                    </span>
                                    <div class="x-small text-muted mt-1" style="font-size: 0.6rem;">
                                        <?php
                                        $ts = strtotime((string) ($w["created_at"] ?? ''));
                                        echo $ts !== false ? date(
                                            "Y-m-d H:i",
                                            $ts,
                                        ) : '-';
                                        ?>
                                    </div>
                                </div>
                                <div class="col-md-2 text-end">
                                    <div class="d-flex gap-2 justify-content-end align-items-center">
                                        <?php if ($w["status"] === "pending"): ?>
                                            <button class="btn btn-term btn-term-success btn-sm" data-bs-toggle="modal"
                                                data-bs-target="#approveModal<?= $w[
                                                    "id"
                                                ] ?>">[ OK ]</button>
                                            <form method="POST" onsubmit="return confirm('TERMINATE REQUEST AND REFUND USER?');">
                                                <?= CsrfProtection::tokenField() ?>
                                                <input type="hidden" name="withdrawal_id" value="<?= $w[
                                                    "id"
                                                ] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="btn btn-term btn-term-danger btn-sm">[ X ]</button>
                                            </form>
                                        <?php elseif (
                                            $w["status"] === "completed"
                                        ): ?>
                                            <div class="x-small text-muted text-truncate" style="max-width: 100px;" title="<?= $w[
                                                "tx_hash"
                                            ] ?>">
                                                HASH:
                                                <?= substr(
                                                    (string) ($w["tx_hash"] ?? "N/A"),
                                                    0,
                                                    10,
                                                ) ?>...
                                            </div>
                                        <?php endif; ?>

                                        <form method="POST" onsubmit="return confirm('Delete this withdrawal?');">
                                            <?= CsrfProtection::tokenField() ?>
                                            <input type="hidden" name="withdrawal_id" value="<?= $w["id"] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-term btn-term-danger btn-sm" title="Delete Record">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Approval Modal -->
                    <div class="modal fade" id="approveModal<?= $w[
                        "id"
                    ] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content bg-black border-success text-success rounded-0">
                                <form method="POST">
                                    <?= CsrfProtection::tokenField() ?>
                                    <input type="hidden" name="withdrawal_id" value="<?= $w[
                                        "id"
                                    ] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <div class="modal-header border-success">
                                        <h5 class="modal-title">APPROVE_WITHDRAWAL_#
                                            <?= $w["id"] ?>
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white"
                                            data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>> ATTEMPTING TO FINALIZE MIX_SEQUENCE FOR: <strong>
                                                <?= $w["btc_amount"] ?> BTC
                                            </strong></p>
                                        <div class="mb-3">
                                            <label class="form-label small">
                                                > TRANSACTION_HASH_ID (TXID)
                                                <input type="text" name="tx_hash"
                                                    class="form-control bg-dark border-success text-success rounded-0"
                                                    placeholder="0x..." required>
                                            </label>
                                        </div>
                                        <div class="terminal-alert small border border-warning p-2 text-warning mb-0">
                                            NOTICE: Clicking [CONFIRM] will mark this request as completed. Points have already
                                            been deducted from user.
                                        </div>
                                    </div>
                                    <div class="modal-footer border-success">
                                        <button type="button" class="btn btn-secondary rounded-0"
                                            data-bs-dismiss="modal">ABORT</button>
                                        <button type="submit" class="btn btn-success rounded-0 px-4">CONFIRM_LINK</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function confirmDelete(id) {
        if (confirm("CRITICAL_ACTION: PERMANENTLY PURGE WITHDRAWAL RECORD #" + id + "?\n\nThis action cannot be undone.")) {
            document.getElementById('deleteForm' + id).submit();
        }
    }
</script>

<?php PageController::end("./"); ?>
