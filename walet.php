<?php

declare(strict_types=1);

/**
 * Professional Wallet Page - Enhanced Upgrade
 * Features:
 * - Real-time Balance & Financial Stats
 * - Secure Point Transfer System
 * - Detailed Transaction History
 * - Bitcoin Mixer Placeholder
 */

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;
use ROOTS\Config\Database;

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
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://api.coingecko.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://api.coingecko.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
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

// 1. Setup Page
$pageData = PageController::setup("Premium Wallet", "./", []);

$con = $pageData["db"];
$user = $pageData["user"];

// 2. Fetch Detailed Stats & Recent Withdrawals
$total_earned = 0;
$total_spent = 0;
$transactions = [];
$recent_withdrawals = [];

if ($con && !empty($user["username"])) {
    // Financial Analytics
    $stats_query = "SELECT
                    SUM(CASE WHEN transaction_type IN ('sale', 'bonus', 'refund', 'transfer_in') THEN points_amount ELSE 0 END) as total_in,
                    SUM(CASE WHEN transaction_type IN ('purchase', 'transfer_out') THEN points_amount ELSE 0 END) as total_out
                    FROM transaction_history
                    WHERE user_id = ?";
    $stmt = $con->prepare($stats_query);
    if ($stmt) {
        $stmt->bind_param("s", $user["username"]);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $total_earned = (float) $row["total_in"];
            $total_spent = (float) $row["total_out"];
        }
        $stmt->close();
    }

    // Recent Transactions
    $tx_query = "SELECT transaction_type, points_amount, description, created_at
                 FROM transaction_history
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT 50";

    $stmt = $con->prepare($tx_query);
    if ($stmt) {
        $stmt->bind_param("s", $user["username"]);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $transactions[] = $row;
        }
        $stmt->close();
    }

    // Recent Withdrawals
    $withdraw_query = "SELECT amount_pts, btc_amount, status, created_at
                       FROM withdrawals
                       WHERE user_id = ?
                       ORDER BY created_at DESC
                       LIMIT 5";
    $stmt = $con->prepare($withdraw_query);
    $userIdStr = (string) $user["id"];
    $stmt->bind_param("s", $userIdStr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recent_withdrawals[] = $row;
    }
    $stmt->close();
}

// 3. UI Styling
?>
<style>
    /* Professional Terminal Theme - V2.0 Enhanced */
    :root {
        --term-bg: #000000;
        --term-panel: rgba(10, 15, 10, 0.8);
        --term-green: #00ff41;
        --term-green-dim: rgba(0, 255, 65, 0.1);
        --term-green-glow: rgba(0, 255, 65, 0.4);
        --term-accent: #fcee0a;
        --term-danger: #ff003c;
        --term-blue: #00f3ff;
    }

    /* Base Reset for Fullscreen */
    html,
    body {
        margin: 0;
        background-color: var(--term-bg);
    }
        width: 100%;
        overflow-x: hidden;
    }

    /* Force Navbar to be flush */
    .navbar,
    nav,
    .bc-nav {
        margin-top: 0 !important;
        margin-bottom: 0 !important;
        top: 0 !important;
    }

    .content,
    .main-container {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }

    --glass-bg: rgba(0, 20, 0, 0.3);
    --glass-border: rgba(0, 255, 65, 0.15);
    }

    body {
        background-color: var(--term-bg) !important;
        font-family: 'Courier New', monospace !important;
        color: var(--term-green) !important;
        background-image:
            radial-gradient(circle at 50% 50%, rgba(0, 255, 65, 0.05) 0%, transparent 60%);
        background-attachment: fixed;
    }

    .text-muted {
        color: var(--term-green) !important;
        opacity: 0.5;
    }

    /* CRT Background effect - Improved */
    body::after {
        content: " ";
        display: block;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        right: 0;
        background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.15) 50%),
            linear-gradient(90deg, rgba(255, 0, 0, 0.03), rgba(0, 255, 0, 0.01), rgba(0, 0, 255, 0.03));
        z-index: 9999;
        background-size: 100% 3px, 3px 100%;
        pointer-events: none;
        opacity: 0.2;
    }

    .terminal-card {
        background: var(--term-panel);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.8);
        position: relative;
        overflow: hidden;
        transition: all 0.01s cubic-bezier(0.165, 0.84, 0.44, 1);
        margin-bottom: 1.5rem;
    }

    /* Grid Background Pattern */
    .terminal-card::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-image:
            linear-gradient(var(--term-green-dim) 1px, transparent 1px),
            linear-gradient(90deg, var(--term-green-dim) 1px, transparent 1px);
        background-size: 20px 20px;
        opacity: 0.1;
        z-index: -1;
        pointer-events: none;
    }

    .terminal-card:hover {
        border-color: rgba(0, 255, 65, 0.4);
        box-shadow: 0 0 30px rgba(0, 255, 65, 0.1);
    }

    .terminal-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--term-green), transparent);
        box-shadow: 0 0 15px var(--term-green);
        opacity: 0.6;
        z-index: 1;
    }

    .corner-accent {
        position: absolute;
        width: 12px;
        height: 12px;
        border: 2px solid var(--term-green);
        z-index: 2;
        opacity: 0.7;
    }

    .tr {
        top: 0;
        right: 0;
        border-left: none;
        border-bottom: none;
    }

    .bl {
        bottom: 0;
        left: 0;
        border-right: none;
        border-top: none;
    }

    .glow-text {
        text-shadow: 0 0 2px var(--term-green-glow);
    }

    .stats-card h6 {
        font-size: 0.7rem;
        color: var(--term-green);
        opacity: 0.6;
        letter-spacing: 2px;
        margin-bottom: 12px;
    }

    .stats-card .value {
        font-size: 1.6rem;
        font-weight: 800;
        font-family: 'Orbitron', sans-serif;
        letter-spacing: 1px;
    }

    .txs-table {
        --bs-table-bg: transparent;
        background-color: transparent !important;
        color: var(--term-green);
        font-size: 0.85rem;
    }

    .txs-table th {
        border-bottom: 1px solid rgba(0, 255, 65, 0.2) !important;
        color: var(--term-green) !important;
        font-weight: 700;
        letter-spacing: 1px;
        background: rgba(0, 255, 65, 0.05) !important;
    }

    .txs-table td {
        border-bottom: 1px solid rgba(0, 255, 65, 0.05) !important;
        vertical-align: middle;
    }

    .txs-table tr:hover {
        background: rgba(0, 255, 65, 0.08) !important;
    }

    /* Transfer Form & Inputs */
    .terminal-input {
        background: rgba(0, 0, 0, 0.6) !important;
        backdrop-filter: blur(4px);
        border: 1px solid var(--glass-border) !important;
        color: var(--term-green) !important;
        border-radius: 4px !important;
        font-family: 'Consolas', monospace;
        padding: 0.75rem 1rem !important;
        transition: all 0.1s ease;
    }

    .terminal-input:focus {
        border-color: var(--term-green) !important;
        box-shadow: 0 0 15px var(--term-green-dim), inset 0 0 10px rgba(0, 255, 65, 0.05) !important;
        background: rgba(0, 10, 0, 0.8) !important;
    }

    .terminal-input::placeholder {
        color: var(--term-green);
        opacity: 0.3;
    }

    .btn-terminal {
        background: rgba(0, 255, 65, 0.05);
        border: 1px solid var(--term-green);
        color: var(--term-green);
        border-radius: 4px;
        padding: 0.75rem 1.5rem;
        font-weight: 700;
        letter-spacing: 1.5px;
        position: relative;
        overflow: hidden;
    }

    .btn-terminal::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(0, 255, 65, 0.2), transparent);
        transition: 0.1s;
    }

    .btn-terminal:hover {
        background: var(--term-green);
        color: #000;
        box-shadow: 0 0 10px var(--term-green-glow);
    }

    .btn-terminal:hover::before {
        left: 100%;
    }

    /* Mixer Stats Enhancement */
    .mixer-stats-row {
        background: rgba(0, 255, 65, 0.03);
        border-radius: 8px;
        padding: 12px;
        border: 1px dashed rgba(0, 255, 65, 0.1);
    }

    /* Status Badges */
    .badge-tx {
        font-size: 0.65rem;
        padding: 4px 8px;
        border: 1px solid;
        border-radius: 4px;
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .badge-in {
        background: rgba(0, 255, 65, 0.1);
        color: var(--term-green);
        border-color: var(--term-green);
    }

    .badge-out {
        background: rgba(255, 0, 60, 0.1);
        color: var(--term-danger);
        border-color: var(--term-danger);
        opacity: 0.9;
    }

    /* Custom Scrollbar for Terminal */
    ::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    ::-webkit-scrollbar-track {
        background: #000;
    }

    ::-webkit-scrollbar-thumb {
        background: var(--term-green-dim);
        border-radius: 3px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: var(--term-green);
    }

    @media (max-width: 768px) {
        .stats-card .value {
            font-size: 1.2rem;
        }
    }
</style>

<div class="container-fluid pt-4 px-4">
    <!-- Header Row -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="terminal-card stats-card rounded p-3 text-center">
                <div class="corner-accent tr"></div>
                <div class="corner-accent bl"></div>
                <h6><i class="fas fa-wallet me-2"></i>AVAIL_BALANCE</h6>
                <div class="value glow-text" style="color: var(--term-green);">
                    <?= number_format((float) $user["points"]) ?> <small
                        style="font-size: 0.5em; color: var(--term-green);">PTS</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="terminal-card stats-card rounded p-3 text-center">
                <div class="corner-accent tr"></div>
                <div class="corner-accent bl"></div>
                <h6><i class="fas fa-arrow-circle-up me-2"></i>TOTAL_EARNED</h6>
                <div class="value" style="color: var(--term-green);">+<?= number_format(
                    (float) $total_earned,
                ) ?>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="terminal-card stats-card rounded p-3 text-center">
                <div class="corner-accent tr"></div>
                <div class="corner-accent bl"></div>
                <h6><i class="fas fa-arrow-circle-down me-2"></i>TOTAL_SPENT</h6>
                <div class="value" style="color: var(--term-green); opacity: 0.8;">
                    -<?= number_format((float) $total_spent) ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="terminal-card stats-card rounded p-3 text-center">
                <div class="corner-accent tr"></div>
                <div class="corner-accent bl"></div>
                <h6><i class="fas fa-fingerprint me-2"></i>ACCOUNT_ID</h6>
                <div class="value" style="color: var(--term-green);">
                    #<?= str_pad(
                        (string) $user["id"],
                        6,
                        "0",
                        STR_PAD_LEFT,
                    ) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Transfer & Mixer Column -->
        <div class="col-lg-4">
            <!-- Transfer Module -->
            <div class="terminal-card rounded p-4 mb-4">
                <div class="corner-accent tr"></div>
                <div class="corner-accent bl"></div>
                <h5 class="mb-3 text-uppercase" style="color: var(--term-green);"><i
                        class="fas fa-exchange-alt me-2"></i>TRAN_SEQUENCE</h5>
                <form id="transferForm">
                    <div class="mb-3">
                        <label class="form-label small" for="target_id">> TARGET_IDENTIFIER (ID/NAME)</label>
                        <input type="text" id="target_id" class="form-control terminal-input"
                            placeholder="Enter User ID or Username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="transfer_amount">> POINT_AMOUNT (MIN: 1,000 | MAX: 1,000,000)</label>
                        <input type="number" id="transfer_amount" class="form-control terminal-input" placeholder="1000"
                            min="1000" max="1000000" required>
                    </div>
                    <button type="submit" class="btn btn-terminal w-100">
                        <i class="fas fa-shield-alt me-2"></i>EXECUTE_TRANSFER
                    </button>
                    <div id="transferStatus" class="mt-3 small" style="display: none;"></div>
                </form>
            </div>

            <!-- Mixer Module -->
            <div class="terminal-card rounded p-4">
                <div class="corner-accent tr"></div>
                <div class="corner-accent bl"></div>
                <h5 class="mb-3" style="color: var(--term-green);"><i class="fab fa-bitcoin me-2"></i>BTC_MIX_NODE</h5>
                <form id="withdrawalForm">
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="withdraw_pts">> WITHDRAWAL_AMOUNT (PTS)</label>
                        <input type="number" id="withdraw_pts" class="form-control terminal-input"
                            placeholder="Min 1,000 PTS" min="1000" required>
                        <div id="btc_estimation" class="mt-1 small text-muted">> EST: 0.00000000 BTC</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="btc_address">> TARGET_BTC_ADDRESS</label>
                        <input type="text" id="btc_address" list="saved_wallets" class="form-control terminal-input"
                            placeholder="bc1q..." required>
                        <datalist id="saved_wallets">
                            <?php if (!empty($user["wallet_address"])): ?>
                                <option value="<?= htmlspecialchars(
                                    $user["wallet_address"],
                                ) ?>">Saved Wallet</option>
                            <?php endif; ?>
                        </datalist>
                    </div>

                    <button type="submit" id="initWithdrawBtn" class="btn btn-terminal w-100">
                        INITIATE_MIX_SEQUENCE
                    </button>
                    <div id="withdrawalStatus" class="mt-3 small" style="display: none;"></div>
                </form>

                <div class="mt-4 pt-3 border-top border-secondary">
                    <h6 class="mb-2" style="font-size: 0.7rem;">MIXER_HISTORY</h6>
                    <?php if (empty($recent_withdrawals)): ?>
                        <div class="small text-muted opacity-50">> NO_RECENT_MIXES</div>
                    <?php else: ?>
                        <?php foreach ($recent_withdrawals as $w): ?>
                            <div class="d-flex justify-content-between x-small mb-1" style="font-size: 0.65rem;">
                                <span><?= date(
                                    "m/d",
                                    strtotime($w["created_at"]),
                                ) ?> |
                                    <?= number_format(
                                        (float) ($w["amount_pts"] ?? 0),
                                    ) ?>
                                    pts</span>
                                <span
                                    class="<?= $w["status"] === "completed"
                                        ? "text-success"
                                        : "text-warning" ?>"><?= strtoupper(
    $w["status"],
) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="mt-4 pt-3 border-top border-secondary opacity-75">
                    <h6 class="mb-3" style="font-size: 0.7rem; letter-spacing: 1px;">MIXER_STATS_ENGINE</h6>
                    <div class="mixer-stats-row">
                        <div class="d-flex justify-content-between small mb-2">
                            <span class="text-muted">Network Status:</span>
                            <span class="text-success fw-bold" id="btc_net_status"><i
                                    class="fas fa-circle-notch fa-spin me-1"></i>Pinging...</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-2">
                            <span class="text-muted">Mixing Fee:</span>
                            <span class="text-info">2.5% <small class="opacity-50">(NODE_COST)</small></span>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">1k PTS Value:</span>
                            <span class="text-accent fw-bold" id="pts_rate">Calculating...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- History Column -->
        <div class="col-lg-8">
            <div class="terminal-card rounded p-4 h-100" style="background: black;">
                <div class="corner-accent tr"></div>
                <div class="corner-accent bl"></div>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0"><i class="fas fa-stream me-2"></i>TX_BUFFER_LOGS</h5>
                    <span class="badge"
                        style="background: var(--term-green-dim); color: var(--term-green); border: 1px solid var(--term-green);">STATUS:
                        OK</span>
                </div>
                <div class="table-responsive">
                    <table class="table txs-table borderless">
                        <thead>
                            <tr>
                                <th>TIMESTAMP</th>
                                <th>TYPE</th>
                                <th>DELTA</th>
                                <th>MEMO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5 opacity-50">> NO_LOG_DATA_AVAILABLE</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $tx): ?>
                                    <?php
                                    $in = in_array($tx["transaction_type"], [
                                        "sale",
                                        "bonus",
                                        "refund",
                                        "transfer_in",
                                    ]);
                                    $color = $in
                                        ? "var(--term-green)"
                                        : "var(--term-danger)";
                                    $badge = $in ? "badge-in" : "badge-out";
                                    $symbol = $in ? "+" : "-";
                                    ?>
                                    <tr>
                                        <td class="text-muted" style="white-space: nowrap; font-size: 0.7rem;">
                                            <?= date(
                                                "y-m-d / H:i",
                                                strtotime($tx["created_at"]),
                                            ) ?>
                                        </td>
                                        <td><span class="badge-tx <?= $badge ?>"><?= $tx[
    "transaction_type"
] ?></span></td>
                                        <td style="color: <?= $color ?>; font-weight: bold;">
                                            <?= $symbol .
                                                number_format(
                                                    (float) ($tx[
                                                        "points_amount"
                                                    ] ?? 0),
                                                ) ?>
                                        </td>
                                        <td>
                                            <div
                                                style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.7rem; color: var(--term-green);">
                                                <?= htmlspecialchars(
                                                    $tx["description"],
                                                ) ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // --- Point Transfer Logic ---
    document.getElementById('transferForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const statusEl = document.getElementById('transferStatus');
        const targetId = document.getElementById('target_id').value;
        const amount = document.getElementById('transfer_amount').value;
        const btn = this.querySelector('button');

        statusEl.style.display = 'block';
        statusEl.className = 'mt-3 small text-info';
        statusEl.innerHTML = '> INITIATING_REQUEST...';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('target_id', targetId);
        formData.append('amount', amount);

        const csrfToken = '<?= $pageData["csrf_token"] ?? "" ?>';
        if (csrfToken) formData.append('csrf_token', csrfToken);

        fetch('transfer_points', {
            method: 'POST',
            body: formData
        })
            .then(async res => {
                const ct = res.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    const txt = await res.text();
                    throw new Error('Invalid JSON response: ' + txt.slice(0,200));
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    statusEl.className = 'mt-3 small text-success';
                    statusEl.innerHTML = `> SUCCESS: ${data.message}`;
                    setTimeout(() => location.reload(), 1500);
                } else {
                    statusEl.className = 'mt-3 small text-danger';
                    statusEl.innerHTML = `> ERROR: ${data.message}`;
                    btn.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                statusEl.className = 'mt-3 small text-danger';
                statusEl.innerHTML = '> SYSTEM_FAILURE: Unable to reach endpoint';
                btn.disabled = false;
            });
    });

    // --- Bitcoin Mixer Logic ---
    let btcPrice = 0;
    const ptsPerUsd = 1000; // Assumption based on common site patterns, 1k pts = 1 usd

    async function fetchBtcPrice() {
        try {
            const res = await fetch('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd');
            const data = await res.json();
            if (data.bitcoin && data.bitcoin.usd) {
                btcPrice = data.bitcoin.usd;
                document.getElementById('btc_net_status').textContent = 'Stable';
                updateEstimation();
            }
        } catch (e) {
            document.getElementById('btc_net_status').textContent = 'Degraded';
            console.error('Failed to fetch BTC price');
        }
    }

    function updateEstimation() {
        const pts = parseFloat(document.getElementById('withdraw_pts').value) || 0;
        const estimationEl = document.getElementById('btc_estimation');
        const ptsRateEl = document.getElementById('pts_rate');

        if (btcPrice > 0) {
            const usdValue = pts / ptsPerUsd;
            const btcValue = usdValue / btcPrice;
            const btcAfterFee = btcValue * 0.975; // 2.5% fee
            estimationEl.textContent = `> EST: ${btcAfterFee.toFixed(8)} BTC (Net)`;

            const kPtsBtc = (1000 / ptsPerUsd) / btcPrice;
            ptsRateEl.textContent = `${kPtsBtc.toFixed(8)} BTC`;
        }
    }

    document.getElementById('withdraw_pts').addEventListener('input', updateEstimation);

    document.getElementById('withdrawalForm').addEventListener('submit', function (e) {
        e.preventDefault();
        if (btcPrice <= 0) {
            alert('Wait for BTC price to sync...');
            return;
        }

        const pts = document.getElementById('withdraw_pts').value;
        const addr = document.getElementById('btc_address').value;
        const statusEl = document.getElementById('withdrawalStatus');
        const btn = document.getElementById('initWithdrawBtn');

        const btcAmount = (pts / ptsPerUsd / btcPrice) * 0.975;

        statusEl.style.display = 'block';
        statusEl.className = 'mt-3 small text-info';
        statusEl.innerHTML = '> ESTABLISHING_SECURE_NODE...';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('btc_address', addr);
        formData.append('amount_pts', pts);
        formData.append('btc_amount', btcAmount.toFixed(8));

        const csrfToken = '<?= $pageData["csrf_token"] ?? "" ?>';
        if (csrfToken) formData.append('csrf_token', csrfToken);

        fetch('request_withdrawal', {
            method: 'POST',
            body: formData
        })
            .then(async res => {
                const ct = res.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    const txt = await res.text();
                    throw new Error('Invalid JSON response: ' + txt.slice(0,200));
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    statusEl.className = 'mt-3 small text-success';
                    statusEl.innerHTML = `> SEQUENCE_ACCEPTED: ${data.message}`;
                    setTimeout(() => location.reload(), 2000);
                } else {
                    statusEl.className = 'mt-3 small text-danger';
                    statusEl.innerHTML = `> ABORTED: ${data.message}`;
                    btn.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                statusEl.className = 'mt-3 small text-danger';
                statusEl.innerHTML = '> CRITICAL_FAILURE: Node unreachable';
                btn.disabled = false;
            });
    });

    fetchBtcPrice();
    setInterval(fetchBtcPrice, 60000); // Sync every minute
</script>

<?php PageController::end("./", [
    "lib/waypoints/waypoints.min.js",
    "js/main.js",
]);
