<?php

declare(strict_types=1);

/**
 * Clean · Monochrome · Professional
 */

namespace ROOTS\Pages;

use ROOTS\Controllers\PageController;
use ROOTS\Database\SchemaUpdater;
use ROOTS\Security\CsrfProtection;
use ROOTS\Services\LeaderboardService;
use ROOTS\Middleware\SecurityHeadersMiddleware;

require_once __DIR__ . '/vendor/autoload.php';

$pageData = PageController::setup('Dashboard', './', ['css/dashboard.css']);

$db = $pageData['db'] ?? null;
$userData = $pageData['user'] ?? [];
$isAdmin = $pageData['is_admin'] ?? false;
$nonce = SecurityHeadersMiddleware::getNonce();
$requestToken = CsrfProtection::generateToken();

if ((!($pageData['is_authenticated'] ?? false) || empty($userData['username'])) && !headers_sent()) {
    header('Location: /login');
    exit();
}

if ($db) {
    SchemaUpdater::updateLeaderboardSchema($db);
    $db->query("SET SESSION sql_mode = ''");
}

$leaderboardData = ['top_users' => [], 'current_user' => null, 'current_rank' => null, 'has_error' => !$db];
if ($db) {
    try {
        $lbService = new LeaderboardService($db);
        $leaderboardData = $lbService->getLeaderboard('balance', '30d', $userData['username'] ?? '');
    } catch (\Throwable) {
        $leaderboardData['has_error'] = true;
    }
}

$moduleCounts = [];
if ($db && !empty($userData['username'])) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM user_purchases WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('s', $userData['username']);
            $stmt->execute();
            $moduleCounts['my_purchases'] = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }
    } catch (\Throwable) {
        // Silently fail - module count is non-critical
    }
}

$points = (int) ($userData['points'] ?? 0);
$subscription = htmlspecialchars($userData['subscription'] ?? 'BASIC');
$displayName = htmlspecialchars($userData['display_name'] ?? $userData['username'] ?? 'OPERATIVE');
$currentUsername = $userData['username'] ?? '';
$progressPercent = min(100, ($points / 1000) * 100);
$currentTime = date('H:i:s');
$currentDate = date('Y.m.d');
$topUsers = $leaderboardData['top_users'] ?? [];
$currentRank = $leaderboardData['current_rank'] ?? null;
define('DEFAULT_AVATAR_PATH', 'img/user.jpg');

function armSubTag(string $sub): string
{
    $s = strtoupper($sub ?: 'BASIC');

    if (str_contains($s, 'ADMIN')) {
        $cls = 'tier-admin';
    } elseif (str_contains($s, 'PREMIUM')) {
        $cls = 'tier-premium';
    } elseif (str_contains($s, 'PRO')) {
        $cls = 'tier-pro';
    } elseif (str_contains($s, 'VIP')) {
        $cls = 'tier-vip';
    } else {
        $cls = 'tier-basic';
    }

    return "<span class='sub-tag {$cls}'>{$s}</span>";
}

function armRankCell(int $rank): string
{
    if ($rank === 1) {
        $cls = 'r1';
    } elseif ($rank === 2) {
        $cls = 'r2';
    } elseif ($rank === 3) {
        $cls = 'r3';
    } else {
        $cls = '';
    }

    return "<span class='rank-num {$cls}'>" . str_pad((string) $rank, 2, '0', STR_PAD_LEFT) . "</span>";
}

?><!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="theme-color" content="#0a0a0a">
    <title>DASHBOARD — TERMINAL INTERFACE</title>

    <!-- Google Fonts: preconnect for performance, SRI not supported by Google Fonts API -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Bootstrap CSS: trusted CDN, SRI omitted for simplicity with local fallback -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <link rel="stylesheet" href="css/dashboard.css">

    <!-- Chart.js local with CDN fallback -->
    <script src="lib/chart/chart.min.js" defer></script>
    <script
        nonce="<?= $nonce ?>">window.addEventListener('load', function () { if (typeof Chart === 'undefined') { var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'; document.head.appendChild(s); } });</script>
</head>

<body class="dashboard-loading" data-username="<?= htmlspecialchars($currentUsername) ?>">

    <input type="hidden" id="csrf_token" value="<?= $requestToken ?>">

    <!-- ═══════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════ -->


    <div id="dashboardContent">
        <div class="container-fluid" style="padding: 16px; max-width:1600px;margin:0 auto;">

            <!-- ── ROW 1: Wallet + Monthly ── -->
            <div class="row g-3 mb-3">

                <div class="col-12 col-md-6">
                    <div class="arm-card p-4">
                        <div class="arm-corner tr"></div>
                        <div class="arm-corner bl"></div>
                        <div class="arm-label mb-3">WALLET BALANCE</div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                            <div>
                                <div class="d-flex align-items-baseline gap-2">
                                    <span class="stat-value"><?= number_format($points) ?></span>
                                    <span class="stat-unit">PTS</span>
                                </div>
                                <div class="arm-progress mt-2" style="width:140px;">
                                    <div class="arm-progress-bar" style="width:<?= $progressPercent ?>%;"></div>
                                </div>
                            </div>
                            <div class="ms-auto">
                                <?= armSubTag($subscription) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="arm-card p-4">
                        <div class="arm-corner tr"></div>
                        <div class="arm-corner bl"></div>
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="arm-label">MONTHLY LOG</div>
                            <span style="font-size:.55rem;color:#00cc33;letter-spacing:1px;"><?= $currentDate ?></span>
                        </div>
                        <div id="monthlyProgressBars">
                            <div class="d-flex align-items-center gap-2">
                                <span class="arm-spin"></span>
                                <span style="font-size:.6rem;color:#00cc33;">LOADING...</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── ROW 2: Notification ── -->
            <div class="mb-3">
                <div class="arm-card" style="min-height:36px;">
                    <div id="notificationContent"
                        style="padding:8px 16px;font-size:.65rem;color:#00cc33;text-shadow:0 0 5px rgba(0,255,65,0.6);">
                    </div>
                </div>
            </div>

           

            <!-- ── ROW 3: Drone Dashboard Stats ── -->
            <div class="mb-3">
                <div class="arm-card p-3">
                    <div class="arm-corner tr"></div>
                    <div class="arm-corner bl"></div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="arm-label">DRONE DASHBOARD</div>
                        <span style="font-size:.55rem;color:#00cc33;letter-spacing:1px;">SYSTEM MONITOR</span>
                    </div>

                    <div class="row g-2 row-cols-2 row-cols-md-4">
                        <!-- عدد الطائرات -->
                        <div class="col">
                            <div class="kpi-card">
                                <div class="kpi-label">DRONE COUNT</div>
                                <div class="kpi-value" id="droneCount">
                                    <span class="arm-spin"></span>
                                </div>
                                <div class="kpi-delta">Active Units</div>
                            </div>
                        </div>

                        <!-- عدد الوجوه المفحوصة -->
                        <div class="col">
                            <div class="kpi-card">
                                <div class="kpi-label">FACES SCANNED</div>
                                <div class="kpi-value" id="facesScanned">
                                    <span class="arm-spin"></span>
                                </div>
                                <div class="kpi-delta">Recognition Matrix</div>
                            </div>
                        </div>

                        <!-- الطائرات المفصولة -->
                        <div class="col">
                            <div class="kpi-card">
                                <div class="kpi-label">DISCONNECTED</div>
                                <div class="kpi-value" id="droneDisconnected">
                                    <span class="arm-spin"></span>
                                </div>
                                <div class="kpi-delta neg">Offline Units</div>
                            </div>
                        </div>

                        <!-- عدد التوكين -->
                        <div class="col">
                            <div class="kpi-card" style="border-color: rgba(255, 215, 0, 0.3);">
                                <div class="kpi-label" style="color: #ffd700;">TOKEN COUNT</div>
                                <div class="kpi-value" id="tokenCount" style="color: #ffd700;">
                                    <span class="arm-spin"></span>
                                </div>
                                <div class="kpi-delta" style="color: #daa520;">Active Tokens</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 4: KPIs + Analytics ── -->
            <div class="mb-3">
                <div class="arm-card p-3">
                    <div class="arm-corner tr"></div>
                    <div class="arm-corner bl"></div>
                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <div class="arm-label">ANALYTICS</div>
                        <div class="d-flex gap-1 flex-wrap">
                            <button class="period-btn" data-period="24h">24H</button>
                            <button class="period-btn active" data-period="30d">30D</button>
                            <button class="period-btn" data-period="7d">7D</button>
                            <button class="period-btn" data-period="90d">90D</button>
                            <button class="period-btn" data-period="all">ALL</button>
                        </div>
                    </div>

                    <!-- KPI row -->
                    <div class="row g-2 mb-3" id="kpiContainer">
                        <?php
                        $kpis = [
                            ['id' => 'kpiVolume', 'label' => 'TOTAL VOLUME'],
                            ['id' => 'kpiSales', 'label' => 'SALES'],
                            ['id' => 'kpiPurchases', 'label' => 'PURCHASES'],
                            ['id' => 'kpiUsers', 'label' => 'ACTIVE USERS'],
                            ['id' => 'kpiGrowth', 'label' => 'GROWTH RATE'],
                        ];
                        foreach ($kpis as $k) {
                            ?>
                            <div class="col-6 col-md-4 col-xl">
                                <div class="kpi-card">
                                    <div class="kpi-label"><?= $k['label'] ?></div>
                                    <div class="kpi-value" id="<?= $k['id'] ?>Val"><span class="arm-spin"></span></div>
                                    <div class="kpi-delta" id="<?= $k['id'] ?>Delta"></div>
                                </div>
                            </div>
                        <?php } ?>
                    </div>

                    <!-- Charts -->
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="chart-wrap">
                                <div class="arm-label mb-2">SALES / PURCHASES</div>
                                <div style="height:170px;"><canvas id="chartSalesPurchases"></canvas></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="chart-wrap">
                                <div class="arm-label mb-2">TX TYPES</div>
                                <div style="height:170px;"><canvas id="chartTxTypes"></canvas></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="chart-wrap">
                                <div class="arm-label mb-2">TOP ACTIVE</div>
                                <div style="height:170px;"><canvas id="chartTopUsers"></canvas></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 5: Leaderboard + User card ── -->
            <div class="row g-3 mb-4">

                <!-- Leaderboard -->
                <div class="col-12 col-lg-8">
                    <div class="arm-card p-3 h-100">
                        <div class="arm-corner tr"></div>
                        <div class="arm-corner bl"></div>

                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-3">
                                <div class="arm-label">TOP OPERATIVES</div>
                                <?php if ($currentRank): ?>
                                    <span
                                        style="font-size:.58rem;color:#00cc33;letter-spacing:1.5px;text-shadow:0 0 5px rgba(0,255,65,0.6);">
                                        YOUR RANK &nbsp;<strong
                                            style="color:#00ff41;text-shadow:0 0 8px rgba(0,255,65,1);">#<?= (int) $currentRank ?></strong>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-1 flex-wrap">
                                <button class="metric-tab active" data-metric="balance">BALANCE</button>
                                <button class="metric-tab" data-metric="activity">ACTIVITY</button>
                                <button class="metric-tab" data-metric="earned">EARNED</button>
                                <button class="metric-tab" data-metric="composite">COMPOSITE</button>
                            </div>
                        </div>

                        <!-- Podium -->
                        <?php if (count($topUsers) >= 3): ?>
                            <div class="podium-grid" id="podiumContainer">
                                <?php
                                $order = [$topUsers[1], $topUsers[0], $topUsers[2]];
                                $posLabels = ['02', '01', '03'];
                                foreach ($order as $i => $u): ?>
                                    <?php
                                    $isFirst = $posLabels[$i] === '01';
                                    $name = htmlspecialchars($u['display_name'] ?? $u['username'] ?? '---');
                                    $pts = number_format((int) ($u['points'] ?? 0));
                                    $avatar = !empty($u['avatar_url']) ? htmlspecialchars($u['avatar_url']) : DEFAULT_AVATAR_PATH;
                                    ?>
                                    <div class="podium-cell <?= $isFirst ? 'pos-1' : '' ?>">
                                        <span class="podium-rank-num"><?= $posLabels[$i] ?></span>
                                        <img src="<?= $avatar ?>" alt="<?= $name ?>" class="podium-avatar" loading="lazy">
                                        <div class="podium-name"><?= $name ?></div>
                                        <div class="podium-pts"><?= $pts ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Table -->
                        <div class="table-responsive">
                            <table class="lb-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th>OPERATIVE</th>
                                        <th class="d-none d-sm-table-cell">TIER</th>
                                        <th>SCORE</th>
                                        <th style="width:60px;">Δ</th>
                                    </tr>
                                </thead>
                                <tbody id="leaderboardTableBody">
                                    <?php foreach ($topUsers as $idx => $u): ?>
                                        <?php
                                        $rank = $idx + 1;
                                        $name = htmlspecialchars($u['display_name'] ?? $u['username'] ?? '---');
                                        $pts = number_format((int) ($u['points'] ?? 0));
                                        $sub = armSubTag($u['subscription'] ?? 'BASIC');
                                        $pinned = ($u['username'] ?? '') === $currentUsername;
                                        $avatar = !empty($u['avatar_url']) ? htmlspecialchars($u['avatar_url']) : DEFAULT_AVATAR_PATH;

                                        $diff = (int) ($u['points'] ?? 0) - (int) ($u['previous_points'] ?? 0);
                                        if ($diff > 0) {
                                            $chg = "<i class='fas fa-arrow-up' style='color:#66aa66;font-size:.8rem;'></i>";
                                        } elseif ($diff < 0) {
                                            $chg = "<i class='fas fa-arrow-down' style='color:#aa6666;font-size:.8rem;'></i>";
                                        } else {
                                            $chg = "<span style='color:#666666;font-size:.8rem;'>—</span>";
                                        }
                                        ?>
                                        <tr class="<?= $pinned ? 'pinned-row' : '' ?>">
                                            <td><?= armRankCell($rank) ?></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <img src="<?= $avatar ?>" alt="<?= $name ?>" class="lb-avatar"
                                                        loading="lazy">
                                                    <span><?= $name ?>
                                                        <?= $pinned ? "<span class='you-tag'>[OPERATIVE]</span>" : '' ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="d-none d-sm-table-cell"><?= $sub ?></td>
                                            <td style="font-weight:600;"><?= $pts ?></td>
                                            <td><?= $chg ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- User Status Card -->
                <div class="col-12 col-lg-4">
                    <div class="arm-card p-3 h-100">
                        <div class="arm-corner tr"></div>
                        <div class="arm-corner bl"></div>

                        <div class="arm-label mb-3">OPERATIVE STATUS</div>

                        <div class="text-center mb-3">
                            <?php
                            $currentUserAvatar = !empty($leaderboardData['current_user']['avatar_url'])
                                ? htmlspecialchars($leaderboardData['current_user']['avatar_url'])
                                : DEFAULT_AVATAR_PATH;
                            ?>
                            <img src="<?= $currentUserAvatar ?>" alt="avatar"
                                style="width:64px;height:64px;border-radius:50%;border:2px solid #00ff41;filter:grayscale(40%) sepia(20%) hue-rotate(90deg);box-shadow:0 0 15px rgba(0,255,65,0.5);"
                                loading="lazy">
                            <div
                                style="font-size:.8rem;font-weight:600;color:#00ff41;margin-top:8px;text-shadow:0 0 10px rgba(0,255,65,0.8);">
                                <?= $displayName ?>
                            </div>
                            <div class="mt-1"><?= armSubTag($subscription) ?></div>
                        </div>

                        <hr class="arm-divider mb-3">

                        <?php if ($currentRank): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3 p-2"
                                style="border:1px solid #33aa33;box-shadow:0 0 10px rgba(0,255,65,0.2);">
                                <span
                                    style="font-size:.58rem;letter-spacing:2px;color:#00ff41;text-transform:uppercase;text-shadow:0 0 5px rgba(0,255,65,0.8);">Military
                                    Rank</span>
                                <span
                                    style="font-size:1.1rem;font-weight:700;color:#00ff41;text-shadow:0 0 15px rgba(0,255,65,1);">#<?= (int) $currentRank ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-column gap-2 mb-3">
                            <div class="d-flex justify-content-between" style="font-size:.62rem;">
                                <span
                                    style="color:#00cc33;letter-spacing:1px;text-transform:uppercase;text-shadow:0 0 3px rgba(0,255,65,0.5);">Military
                                    Balance</span>
                                <span
                                    style="color:#00ff41;font-weight:600;text-shadow:0 0 8px rgba(0,255,65,0.8);"><?= number_format($points) ?>
                                    PTS</span>
                            </div>
                            <div class="arm-progress">
                                <div class="arm-progress-bar" style="width:<?= $progressPercent ?>%;"></div>
                            </div>

                            <div class="d-flex justify-content-between mt-1" style="font-size:.62rem;">
                                <span
                                    style="color:#00cc33;letter-spacing:1px;text-transform:uppercase;text-shadow:0 0 3px rgba(0,255,65,0.5);">Earned
                                    / Month</span>
                                <span id="sidebarEarned"
                                    style="color:#00ff41;text-shadow:0 0 5px rgba(0,255,65,0.7);">—</span>
                            </div>
                            <div class="d-flex justify-content-between" style="font-size:.62rem;">
                                <span
                                    style="color:#00cc33;letter-spacing:1px;text-transform:uppercase;text-shadow:0 0 3px rgba(0,255,65,0.5);">Spent
                                    / Month</span>
                                <span id="sidebarSpent"
                                    style="color:#00ff41;text-shadow:0 0 5px rgba(0,255,65,0.7);">—</span>
                            </div>
                        </div>

                        <hr class="arm-divider mb-3">

                        <div class="d-flex flex-column gap-2">
                            <a href="profile" class="module-tile"
                                style="flex-direction:row;justify-content:flex-start;gap:10px;padding:9px 12px;">
                                <i class="fas fa-user-edit module-tile-icon" style="font-size:.8rem;"></i>
                                <span class="module-tile-label">EDIT PROFILE</span>
                            </a>
                            <a href="buy_points" class="module-tile"
                                style="flex-direction:row;justify-content:flex-start;gap:10px;padding:9px 12px;">
                                <i class="fas fa-coins module-tile-icon" style="font-size:.8rem;"></i>
                                <span class="module-tile-label">BUY POINTS</span>
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="arm-footer text-center">
                [MILITARY] INTERFACE &nbsp;·&nbsp; v5.0 &nbsp;·&nbsp; <?= date('Y') ?> &nbsp;·&nbsp; ALL TACTICAL
                SYSTEMS NOMINAL
            </div>

        </div><!-- /container -->

    </div><!-- /dashboardContent -->


    <!-- ── SCRIPTS ── -->
    <!-- Bootstrap JS: trusted CDN, SRI omitted for simplicity -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/dashboard-analytics.js" nonce="<?= $nonce ?>"></script>
    <script src="js/dashboard-functions.js" nonce="<?= $nonce ?>"></script>

    <script nonce="<?= $nonce ?>">
        // Uptime clock
        setInterval(function () {
            const el = document.getElementById('uptimeClock');
            if (el) el.textContent = new Date().toTimeString().slice(0, 8);
        }, 1000);

        // Function to fetch drone dashboard stats
        async function fetchDroneStats() {
            try {
                const response = await fetch('get_drone_stats.php', {
                    credentials: 'include',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();

                if (data.success) {
                    // Update drone count
                    const droneCountEl = document.getElementById('droneCount');
                    if (droneCountEl && data.drone_count !== undefined) {
                        droneCountEl.textContent = data.drone_count.toLocaleString();
                    }

                    // Update faces scanned
                    const facesScannedEl = document.getElementById('facesScanned');
                    if (facesScannedEl && data.faces_scanned !== undefined) {
                        facesScannedEl.textContent = data.faces_scanned.toLocaleString();
                    }

                    // Update disconnected drones
                    const droneDisconnectedEl = document.getElementById('droneDisconnected');
                    if (droneDisconnectedEl && data.drone_disconnected !== undefined) {
                        droneDisconnectedEl.textContent = data.drone_disconnected.toLocaleString();
                    }

                    // Update token count
                    const tokenCountEl = document.getElementById('tokenCount');
                    if (tokenCountEl && data.token_count !== undefined) {
                        tokenCountEl.textContent = data.token_count.toLocaleString();
                    }
                } else {
                    console.error('Drone stats API returned error:', data.message);
                    // Show default values if API fails
                    ['droneCount', 'facesScanned', 'droneDisconnected', 'tokenCount'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.textContent = '—';
                    });
                }
            } catch (error) {
                console.error('Failed to fetch drone stats:', error);
                // Show default values if error occurs
                ['droneCount', 'facesScanned', 'droneDisconnected', 'tokenCount'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = '—';
                });
            }
        }

        // Load drone dashboard stats immediately
        fetchDroneStats();

        // Monthly stats - fetch immediately instead of waiting for dashboard:ready event
        fetch('get_monthly_stats.php', { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => {
                return r.json();
            })
            .then(d => {
                if (!d.success) return;
                const e = d.earned || 0, s = d.spent || 0;
                const se = document.getElementById('sidebarEarned');
                const ss = document.getElementById('sidebarSpent');
                if (se) se.textContent = Number(e).toLocaleString() + ' PTS';
                if (ss) ss.textContent = Number(s).toLocaleString() + ' PTS';

                const c = document.getElementById('monthlyProgressBars');
                if (c) c.innerHTML = `
            <div class="mb-2">
                <div class="d-flex justify-content-between mb-1" style="font-size:.58rem;">
                    <span style="color:#444;letter-spacing:1px;text-transform:uppercase;">Earned</span>
                    <span style="color:#5a7a5a;font-weight:600;">${Number(e).toLocaleString()} PTS</span>
                </div>
                <div class="arm-progress"><div class="arm-progress-bar" style="width:${Math.min(100, (e / 1000) * 100)}%;"></div></div>
            </div>
            <div>
                <div class="d-flex justify-content-between mb-1" style="font-size:.58rem;">
                    <span style="color:#444;letter-spacing:1px;text-transform:uppercase;">Spent</span>
                    <span style="color:#7a5a5a;font-weight:600;">${Number(s).toLocaleString()} PTS</span>
                </div>
                <div class="arm-progress"><div class="arm-progress-bar" style="width:${Math.min(100, (s / 1000) * 100)}%;background:#444;"></div></div>
            </div>`;
            })
            .catch(err => {
                console.error('Monthly stats fetch error:', err);
                const c = document.getElementById('monthlyProgressBars');
                if (c) c.innerHTML = '<span style="font-size:.6rem;color:#aa6666;">Error loading stats</span>';
            });

        // Trigger dashboard:ready event for other components that depend on it
        window.dispatchEvent(new Event('dashboard:ready'));

        // Load analytics data on page load
        if (typeof switchAnalyticsPeriod === 'function') {
            // Load 30D analytics by default on page load
            setTimeout(() => {
                const defaultBtn = document.querySelector('.period-btn[data-period="30d"]');
                if (defaultBtn && typeof switchAnalyticsPeriod === 'function') {
                    switchAnalyticsPeriod('30d', defaultBtn);
                }
            }, 500);
        }
    </script>

</body>

</html>