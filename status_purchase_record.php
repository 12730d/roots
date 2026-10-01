<?php
declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;

// 1. Setup Page
$pageData = PageController::setup(
    "User Analytics Hub",
    "./",
    [],
    ["require_login" => true, "render_layout" => true],
);

$con = $pageData["db"];
$user = $pageData["user"];
$username = $user["username"];

// Date format constant
const DATE_FORMAT = "y.m.d H:i";

// 2. Fetch Aggregated Stats
$total_sales_pts = 0;
$total_purchase_pts = 0;
$purchase_count = 0;
$sales_count = 0;
$submission_count = 0;

if ($con) {
    // Stats for Sales
    $sales_query =
        "SELECT COUNT(*) as cnt, SUM(points_amount) as total FROM transaction_history WHERE user_id = ? AND transaction_type = 'sale'";
    $stmt = $con->prepare($sales_query);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $sales_count = (int) ($row["cnt"] ?? 0);
            $total_sales_pts = (float) ($row["total"] ?? 0);
        }
        $stmt->close();
    }

    // Stats for Purchases
    $purchase_stats_query =
        "SELECT COUNT(*) as cnt, SUM(points_spent) as total FROM user_purchases WHERE user_id = ?";
    $stmt = $con->prepare($purchase_stats_query);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $purchase_count = (int) ($row["cnt"] ?? 0);
            $total_purchase_pts = (float) ($row["total"] ?? 0);
        }
        $stmt->close();
    }

    // Recent Purchases
    $recent_purchases = [];
    $p_query = "SELECT up.id, up.record_id, up.points_spent, up.purchased_at, up.original_data
                FROM user_purchases up
                WHERE up.user_id = ?
                ORDER BY up.purchased_at DESC
                LIMIT 10";
    $stmt = $con->prepare($p_query);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recent_purchases[] = $row;
        }
        $stmt->close();
    }

    // Recent Sales
    $recent_sales = [];
    $s_query = "SELECT points_amount, description, created_at
                FROM transaction_history
                WHERE user_id = ? AND transaction_type = 'sale'
                ORDER BY created_at DESC
                LIMIT 10";
    $stmt = $con->prepare($s_query);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recent_sales[] = $row;
        }
        $stmt->close();
    }

    // UPDATED: Fetch User's Data Submissions from 'pending_records' table
    $my_submissions = [];
    $submission_count = 0;
    $u_query =
        "SELECT * FROM pending_records WHERE submitted_by = ? ORDER BY id DESC";
    $stmt = $con->prepare($u_query);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $my_submissions[] = $row;
        }
        $submission_count = count($my_submissions);
        $stmt->close();
    }
}

// 3. UI Styling
?>
<style>
    :root {
        --term-green: #00ff41;
        --term-amber: #ffb000;
        --term-red: #ff3333;
        --term-bg: #050505;
        --term-panel: rgba(10, 15, 10, 0.95);
        --term-border: rgba(0, 255, 65, 0.2);
        --glow: 0 0 10px rgba(0, 255, 65, 0.3);
        --term-blue: #00f3ff;
    }

    body.theme-legacy-green {
        background-color: var(--term-bg) !important;
        color: var(--term-green);
        font-family: 'Share Tech Mono', monospace;
    }

    .main-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        background: transparent !important;
    }

    .terminal-header {
        border: 1px solid var(--term-green);
        background: var(--term-panel);
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: var(--glow);
        position: relative;
    }

    .terminal-header::before {
        content: "[ SYSTEM_ANALYTICS_v2.7 ]";
        position: absolute;
        top: -10px;
        left: 20px;
        background: var(--term-bg);
        padding: 0 10px;
        font-size: 0.7rem;
        color: var(--term-green);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }

    .stat-card {
        background: var(--term-panel);
        border: 1px solid var(--term-border);
        padding: 20px;
        text-align: center;
        transition: 0.3s;
        position: relative;
    }

    .stat-card:hover {
        border-color: var(--term-green);
        box-shadow: var(--glow);
        transform: translateY(-5px);
    }

    .stat-label {
        font-size: 0.7rem;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 2px;
        margin-bottom: 10px;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        text-shadow: var(--glow);
    }

    .stat-value.amber { color: var(--term-amber); text-shadow: 0 0 10px rgba(255, 176, 0, 0.3); }

    .data-section {
        margin-bottom: 40px;
    }

    .section-title {
        font-size: 1.2rem;
        border-left: 4px solid var(--term-green);
        padding-left: 15px;
        margin-bottom: 20px;
        text-transform: uppercase;
        letter-spacing: 2px;
    }

    .terminal-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--term-panel);
        border: 1px solid var(--term-border);
        font-size: 0.85rem;
    }

    .terminal-table th {
        text-align: left;
        padding: 15px;
        border-bottom: 2px solid var(--term-green);
        color: var(--term-green);
        text-transform: uppercase;
    }

    .terminal-table td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--term-border);
        color: rgba(0, 255, 65, 0.8);
    }

    .terminal-table tr:hover td {
        background: rgba(0, 255, 65, 0.05);
        color: var(--term-green);
    }

    .badge-status {
        padding: 2px 8px;
        border: 1px solid currentColor;
        font-size: 0.7rem;
        text-transform: uppercase;
    }

    .status-pending { color: var(--term-amber); border-color: rgba(255, 176, 0, 0.4); }
    .status-approved { color: var(--term-green); border-color: rgba(0, 255, 65, 0.4); }
    .status-rejected { color: var(--term-red); border-color: rgba(255, 51, 51, 0.4); }

    @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');
</style>

<div class="terminal-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h1 class="mb-1"><i class="fas fa-chart-bar me-5"></i>USER_ANALYTICS</h1>
            <p class="text-muted small mb-0">> Logged In: <?= htmlspecialchars(
                $username,
            ) ?> | Status: Operational</p>
        </div>

    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Available Balance</div>
        <div class="stat-value"><?= number_format(
            (float) $user["points"],
        ) ?> <small style="font-size: 0.8rem;">PTS</small></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sales Earnings</div>
        <div class="stat-value amber"><?= number_format(
            (float) $total_sales_pts,
        ) ?> <small style="font-size: 0.8rem;">PTS</small></div>
        <div class="small mt-2" style="color: #666;">Transactions: <?= $sales_count ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Purchase Expenditure</div>
        <div class="stat-value" style="color: var(--term-red); text-shadow: 0 0 10px rgba(255, 51, 51, 0.3);">
            <?= number_format(
                (float) $total_purchase_pts,
            ) ?> <small style="font-size: 0.8rem;">PTS</small>
        </div>
        <div class="small mt-2" style="color: #666;">Items: <?= $purchase_count ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Queued Submissions</div>
        <div class="stat-value" style="color: var(--term-blue); text-shadow: 0 0 10px rgba(0, 243, 255, 0.3);">
            <?= number_format(
                (float) $submission_count,
            ) ?> <small style="font-size: 0.8rem;">REQS</small>
        </div>
        <div class="small mt-2" style="color: #666;">Status: Processing</div>
    </div>
</div>

<div class="row">
    <!-- Submissions Queue Section -->
    <div class="col-12 data-section">
        <h2 class="section-title">Data Submission Queue (Pending Records)</h2>
        <div class="table-responsive">
            <table class="terminal-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Designation</th>
                        <th>Signal Ref</th>
                        <th>Comms Link</th>
                        <th>Valuation</th>
                        <th>State</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_submissions)): ?>
                        <tr><td colspan="7" class="text-center py-4 opacity-50">> NO_PENDING_SUBMISSIONS_DETECTED</td></tr>
                    <?php else: ?>
                        <?php foreach ($my_submissions as $sub): ?>
                            <tr>
                                <td>#<?= $sub["id"] ?></td>
                                <td><?= htmlspecialchars(
                                    (string) ($sub["n"] ?? "N/A"),
                                ) ?></td>
                                <td><?= htmlspecialchars(
                                    (string) ($sub["e"] ?? "N/A"),
                                ) ?></td>
                                <td><?= htmlspecialchars(
                                    (string) ($sub["t"] ?? "N/A"),
                                ) ?></td>
                                <td class="text-success"><?= number_format(
                                    (float) ($sub["points"] ?? 0),
                                ) ?> PTS</td>
                                <td>
                                    <?php
                                    $status = $sub["status"] ?? "pending";
                                    $statusClass = "status-" . $status;
                                    ?>
                                    <span class="badge-status <?= $statusClass ?>"><?= strtoupper(
    $status,
) ?></span>
                                </td>
                                <td class="small opacity-75"><?= date(
                                    DATE_FORMAT,
                                    strtotime($sub["created_at"] ?? "now"),
                                ) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Purchases Section -->
    <div class="col-lg-7 data-section">
        <h2 class="section-title">Recent Data Purchases</h2>
        <div class="table-responsive">
            <table class="terminal-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Record</th>
                        <th>Paid</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_purchases)): ?>
                        <tr><td colspan="4" class="text-center py-4 opacity-50">> NO_PURCHASE_HISTORY_AVAILABLE</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_purchases as $p): ?>
                            <?php $data = json_decode(
                                $p["original_data"],
                                true,
                            ); ?>
                            <tr>
                                <td>#<?= $p["record_id"] ?></td>
                                <td><?= htmlspecialchars(
                                    $data["n"] ?? "Unknown Entity",
                                ) ?></td>
                                <td class="text-danger">-<?= number_format(
                                    (float) $p["points_spent"],
                                ) ?></td>
                                <td class="small opacity-75"><?= date(
                                    DATE_FORMAT,
                                    strtotime($p["purchased_at"]),
                                ) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Sales Section -->
    <div class="col-lg-5 data-section">
        <h2 class="section-title">Commission Logs</h2>
        <div class="table-responsive">
            <table class="terminal-table">
                <thead>
                    <tr>
                        <th>Earnings</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_sales)): ?>
                        <tr><td colspan="2" class="text-center py-4 opacity-50">> NO_COMMISSION_DATA</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_sales as $s): ?>
                            <tr>
                                <td class="text-success">+<?= number_format(
                                    (float) $s["points_amount"],
                                ) ?> PTS</td>
                                <td class="small opacity-75"><?= date(
                                    DATE_FORMAT,
                                    strtotime($s["created_at"]),
                                ) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php PageController::end("./");
?>
