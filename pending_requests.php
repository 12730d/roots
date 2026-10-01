<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Controllers\PageController;

$pageData = PageController::setup(
    'Request Management Terminal',
    './',
    [],
    ['require_login' => true, 'render_layout' => true]
);

$con = $pageData['db'];
$user_data = $pageData['user'];
$username = $user_data['username'];
$is_admin = $user_data['is_admin'];

// Fetch pending requests
$pending_requests = [];
if ($is_admin) {
    $query = "SELECT * FROM pending_records WHERE status = 'pending' ORDER BY created_at DESC";
    $stmt = mysqli_prepare($con, $query);
} else {
    $query = "SELECT * FROM pending_records WHERE submitted_by = ? AND status = 'pending' ORDER BY created_at DESC";
    $stmt = mysqli_prepare($con, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $username);
    }
}

if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $pending_requests = mysqli_fetch_all($result, MYSQLI_ASSOC);
    }
    mysqli_stmt_close($stmt);
}
?>

<style>
    :root {
        --term-green: #0f0;
        --term-amber: #ffb000;
        --term-red: #ff3333;
        --term-bg: #050505;
        --term-panel: rgba(10, 10, 10, 0.9);
        --term-border: rgba(0, 255, 0, 0.2);
        --glow: 0 0 10px rgba(0, 255, 0, 0.4);
        --scanline: rgba(0, 255, 0, 0.05);
    }

    body.theme-legacy-green {
        background-color: var(--term-bg) !important;
        background-image:
            linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%),
            linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
        background-size: 100% 2px, 3px 100%;
        color: var(--term-green);
        font-family: 'Share Tech Mono', monospace;
    }

    .main-container {
        max-width: 1000px;
        margin: 20px auto;
        padding: 0;
        background: transparent !important;
    }

    div.crt-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
        z-index: 9999;
        pointer-events: none;
        opacity: 0.8;
        animation: flicker 0.15s infinite;
    }

    @keyframes flicker {
        0% {
            opacity: 0.9;
        }

        5% {
            opacity: 0.85;
        }

        10% {
            opacity: 0.9;
        }

        100% {
            opacity: 0.9;
        }
    }

    .terminal-header {
        border: 2px solid var(--term-green);
        background: var(--term-panel);
        padding: 30px;
        margin-bottom: 30px;
        position: relative;
        box-shadow: var(--glow);
        backdrop-filter: blur(10px);
    }

    .terminal-header h1 {
        color: var(--term-green);
        font-size: 2.5rem;
        margin-bottom: 10px;
        text-shadow: var(--glow);
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .terminal-header p {
        color: var(--term-amber);
        font-size: 1.1rem;
        font-family: 'VT323', monospace;
    }

    .btn-terminal {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        background: transparent;
        color: var(--term-green);
        border: 1px solid var(--term-green);
        text-decoration: none;
        font-size: 0.9rem;
        transition: all 0.3s;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .btn-terminal:hover {
        background: var(--term-green);
        color: black;
        box-shadow: var(--glow);
    }

    .request-card {
        background: var(--term-panel);
        border: 1px solid var(--term-border);
        margin-bottom: 25px;
        padding: 25px;
        position: relative;
        transition: all 0.3s;
        overflow: hidden;
        backdrop-filter: blur(10px);
    }

    .request-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: repeating-linear-gradient(0deg, var(--scanline) 0px, var(--scanline) 1px, transparent 1px, transparent 2px);
        pointer-events: none;
    }

    .request-card:hover {
        border-color: var(--term-green);
        box-shadow: var(--glow);
        transform: translateY(-5px);
    }

    .request-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--term-border);
    }

    .request-id {
        font-size: 1.4rem;
        color: var(--term-green);
        font-weight: bold;
        text-shadow: var(--glow);
    }

    .request-date {
        color: #888;
        font-size: 0.9rem;
    }

    .status-badge {
        font-family: 'VT323', monospace;
        font-size: 1.1rem;
        padding: 4px 12px;
        border: 1px solid;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .status-pending {
        color: var(--term-amber);
        border-color: var(--term-amber);
        background: rgba(255, 176, 0, 0.1);
    }

    .status-approved {
        color: var(--term-green);
        border-color: var(--term-green);
        background: rgba(0, 255, 0, 0.1);
    }

    .status-rejected {
        color: var(--term-red);
        border-color: var(--term-red);
        background: rgba(255, 51, 51, 0.1);
    }

    .detail-row {
        display: grid;
        grid-template-columns: 140px 1fr;
        gap: 15px;
        margin-bottom: 12px;
        padding: 8px;
        background: rgba(0, 255, 0, 0.03);
        border: 1px solid transparent;
        transition: 0.2s;
    }

    .detail-row:hover {
        background: rgba(0, 255, 0, 0.07);
        border-color: rgba(0, 255, 0, 0.1);
    }

    .detail-label {
        color: #888;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .detail-value {
        color: var(--term-green);
        font-size: 1rem;
    }

    .points-value {
        color: var(--term-amber);
        font-weight: bold;
    }

    .no-requests {
        text-align: center;
        padding: 60px;
        border: 2px dashed var(--term-border);
        background: var(--term-panel);
    }

    .no-requests i {
        font-size: 4rem;
        color: var(--term-green);
        opacity: 0.3;
        margin-bottom: 20px;
    }

    /* Load Google Fonts */
    @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=VT323&display=swap');
</style>

<div class="crt-overlay"></div>

<div class="terminal-header">
    <h1><i class="fas fa-terminal"></i> PENDING_REQUESTS_LOG</h1>
    <p>> Displaying all data uplink requests currently awaiting system validation...</p>
    <div style="display: flex; gap: 15px; margin-top: 20px; flex-wrap: wrap;">
        <a href="table" class="btn-terminal"><i class="fas fa-arrow-left"></i> [ BACK_TO_CONTROL ]</a>
        <a href="add" class="btn-terminal" style="border-color: var(--term-amber); color: var(--term-amber);"><i
                class="fas fa-plus"></i> [ NEW_UPLINK ]</a>
    </div>
</div>

<?php if (empty($pending_requests)): ?>
    <div class="no-requests">
        <i class="fas fa-ghost"></i>
        <h2 style="color: var(--term-green);">NO_DATA_DETECTED</h2>
        <p style="color: #888; margin-bottom: 25px;">The request buffer is currently empty. Initializing waiting state...
        </p>
        <a href="add" class="btn-terminal">[ INITIATE_NEW_REQUEST ]</a>
    </div>
<?php else: ?>
    <?php foreach ($pending_requests as $request): ?>
        <div class="request-card">
            <div class="request-header">
                <div>
                    <span class="request-id">ID: #<?php echo htmlspecialchars((string) $request['id']); ?></span>
                    <div class="request-date">
                        <i class="far fa-clock"></i>
                        STAMP: <?php echo date('Y.m.d // H:i', strtotime($request['created_at'])); ?>
                    </div>
                </div>
                <?php if ($request['status'] == 'pending'): ?>
                    <span class="status-badge status-pending">
                        <i class="fas fa-sync fa-spin"></i> PENDING_REVIEW
                    </span>
                <?php elseif ($request['status'] == 'rejected'): ?>
                    <span class="status-badge status-rejected">
                        <i class="fas fa-ban"></i> ACCESS_DENIED
                    </span>
                <?php else: ?>
                    <span class="status-badge status-approved">
                        <i class="fas fa-check"></i> VALIDATED
                    </span>
                <?php endif; ?>
            </div>
            <div class="request-details">
                <div class="detail-row">
                    <span class="detail-label">DESIGNATION</span>
                    <span class="detail-value"><?php echo htmlspecialchars((string) $request['n']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">SIGNAL_REF</span>
                    <span class="detail-value"><?php echo htmlspecialchars((string) $request['e']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">COMMS_LINK</span>
                    <span class="detail-value"><?php echo htmlspecialchars((string) $request['t']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">PHYSICAL_LOC</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars((string) ($request['address'] ?? $request['a'] ?? 'NULL')); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">REWARD_CREDITS</span>
                    <span class="detail-value points-value"><?php echo number_format((float) ($request['points'] ?? 0)); ?>
                        UNITS</span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
PageController::end('./');
?>
