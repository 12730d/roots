<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Security\CsrfProtection;

PageController::setup('SYSTEM_TERMINAL: Crypto_Payment_History', './');

// Initialize session
Session::start();

$db = Database::getConnection();
if (!$db) {
    die("CRITICAL_SYSTEM_ERROR: Connection failed");
}

$username = $_SESSION["username"];

// Pagination logic
$items_per_page = 8;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

$total_items = 0;
$count_query = "SELECT COUNT(*) as total FROM payment_transactions WHERE username = ?";
$count_stmt = mysqli_prepare($db, $count_query);
if ($count_stmt) {
    mysqli_stmt_bind_param($count_stmt, "s", $username);
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    if ($count_result) {
        $count_data = mysqli_fetch_assoc($count_result);
        $total_items = (int) ($count_data['total'] ?? 0);
    }
}
$total_pages = max(1, (int) ceil($total_items / $items_per_page));
$current_page = min($current_page, max(1, $total_pages));

// Fetch transactions
$payments = [];
$query = "SELECT * FROM payment_transactions WHERE username = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($db, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "sii", $username, $items_per_page, $offset);
    mysqli_stmt_execute($stmt);
    $pay_result = mysqli_stmt_get_result($stmt);
    if ($pay_result) {
        $payments = mysqli_fetch_all($pay_result, MYSQLI_ASSOC);
    }
}

// Fetch points
$user_points = 0;
$query = "SELECT points FROM login WHERE username = ?";
$stmt = mysqli_prepare($db, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $pt_result = mysqli_stmt_get_result($stmt);
    if ($pt_result) {
        $pt_data = mysqli_fetch_assoc($pt_result);
        $user_points = (int) ($pt_data['points'] ?? 0);
    }
}

mysqli_close($db);
?>

<style>
    :root {
        --term-green: #00ff41;
        --term-bg: #0a0a0a;
        --term-cyan: #00f3ff;
        --term-amber: #ffb100;
        --term-red: #ff003c;
    }

    body {
        background-color: var(--term-bg) !important;
        font-family: 'Courier New', Courier, monospace !important;
        color: var(--term-green) !important;
    }

    .terminal-card {
        background: rgba(0, 20, 0, 0.9);
        border: 1px solid var(--term-green);
        box-shadow: 0 0 15px rgba(0, 255, 65, 0.2);
        position: relative;
        overflow: hidden;
    }

    .terminal-card::before {
        content: " ";
        display: block;
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        right: 0;
        background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%),
            linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
        z-index: 2;
        background-size: 100% 2px, 3px 100%;
        pointer-events: none;
    }

    .status-completed {
        color: var(--term-cyan);
        border: 1px solid var(--term-cyan);
    }

    .status-pending {
        color: var(--term-amber);
        border: 1px solid var(--term-amber);
    }

    .status-failed {
        color: var(--term-red);
        border: 1px solid var(--term-red);
    }

    .btn-terminal {
        background: transparent;
        border: 1px solid var(--term-green);
        color: var(--term-green);
        text-transform: uppercase;
        letter-spacing: 2px;
        transition: 0.3s;
    }

    .btn-terminal:hover {
        background: var(--term-green);
        color: black;
        box-shadow: 0 0 20px var(--term-green);
    }

    .points-display {
        font-size: 1.5rem;
        border-bottom: 2px dashed var(--term-green);
        padding-bottom: 10px;
    }
</style>

<div class="container-fluid pt-4 px-4">
    <!-- Info Banner for Purchased Files -->
    <div class="alert alert-info terminal-card mb-4"
        style="border: 2px solid var(--term-cyan); background: rgba(0, 243, 255, 0.1);">
        <div class="d-flex align-items-center">
            <i class="fas fa-info-circle me-3" style="font-size: 1.5rem; color: var(--term-cyan);"></i>
            <div style="flex: 1;">
                <strong style="color: var(--term-cyan);">▸ LOOKING FOR DOWNLOADED FILES?</strong>
                <p class="mb-0 mt-2" style="color: #00d4ff;">
                    This page shows cryptocurrency payment transactions for buying points.
                    <br>
                    To view and download your <strong>purchased files</strong>, visit:
                    <a href="post_mysite/my_purchases" class="btn-terminal btn-sm ms-2"
                        style="display: inline-block; padding: 5px 15px; text-decoration: none;">
                        <i class="fas fa-download"></i> MY PURCHASED FILES
                    </a>
                </p>
            </div>
        </div>
    </div>

    <div class="terminal-card p-4 rounded">
        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-success pb-3">
            <h2 class="mb-0">[ LOG_QUERY: CRYPTO_PAYMENT_HISTORY ]</h2>
            <div>
                <span class="text-white small">USER: <?php echo strtoupper($username); ?></span>
            </div>
        </div>

        <div class="points-display mb-4">
            <span class="text-warning">> CURRENT_BALANCE:</span>
            <?php echo number_format((float) $user_points); ?> <span class="small">PTS</span>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-hover border-success">
                <thead>
                    <tr class="text-success">
                        <th>ID</th>
                        <th>TIMESTAMP</th>
                        <th>ASSET</th>
                        <th>VOLUME</th>
                        <th>STATUS</th>
                        <th>ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-danger">NO RECORDS FOUND IN DATABASE</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>#<?php echo $payment['id']; ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($payment['created_at'])); ?></td>
                                <td><?php echo strtoupper($payment['crypto_type']); ?></td>
                                <td class="text-info"><?php echo number_format($payment['total_points']); ?> PTS</td>
                                <td>
                                    <span class="badge status-<?php echo $payment['status']; ?>">
                                        <?php echo strtoupper($payment['status']); ?>
                                    </span>
                                </td>
                                <td class="d-flex" style="gap:8px;">
                                    <form method="post" action="cancel_purchase.php"
                                        onsubmit="return confirm('Cancel this purchase?');" style="display:inline; margin:0;">
                                        <input type="hidden" name="id"
                                            value="<?php echo htmlspecialchars($payment['id'], ENT_QUOTES); ?>">
                                        <?php echo CsrfProtection::tokenField(); ?>
                                        <button type="submit" class="btn btn-terminal btn-sm" title="Cancel Purchase">
                                            <i class="fas fa-times-circle"></i> CANCEL
                                        </button>
                                    </form>

                                    <form method="post" action="delete_purchase"
                                        onsubmit="return confirm('Delete this purchase?');" style="display:inline; margin:0;">
                                        <input type="hidden" name="id"
                                            value="<?php echo htmlspecialchars($payment['id'], ENT_QUOTES); ?>">
                                        <?php echo CsrfProtection::tokenField(); ?>
                                        <button type="submit" class="btn btn-terminal btn-sm"
                                            style="border-color:#ff3333;color:#ff3333;">
                                            <i class="fas fa-trash"></i> DELETE
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                        <a class="page-link bg-dark text-success border-success"
                            href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
</div>

<?php

PageController::end('./');
