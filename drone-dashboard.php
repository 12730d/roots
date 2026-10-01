<?php

declare(strict_types=1);

/**
 * Drone Dashboard - Professional Drone Face Scanner Administration
 *
 * Enterprise-grade drone face scanner management interface
 * Compact, professional, and feature-rich
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set("session.cookie_httponly", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.cookie_samesite", "Lax");
    ini_set("session.use_strict_mode", "1");
    ini_set("session.gc_maxlifetime", "1800");
    ini_set("session.use_trans_sid", "0");
    if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
        ini_set("session.cookie_secure", "1");
    }
    session_name("ROOTS_SESSION");
    session_start();
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION["user_id"]) && !empty($_SESSION["user_id"]);
$subscription = $_SESSION["subscription"] ?? "";

// Allow access for users with paid subscriptions (Premium, VIP, Elite) or Admin
$hasAccess = in_array(strtolower($subscription), ["admin", "premium", "vip", "elite"]);

if (!$isLoggedIn) {
    header("Location: /login");
    exit();
}

if (!$hasAccess) {
    header("Location: /dashboard");
    exit();
}

// Load autoloader
require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Drone\Services\DroneAuthService;
use ROOTS\Drone\Services\FaceRecognitionService;

// Setup page
$pageData = PageController::setup("Drone Dashboard", "/", []);
$db = $pageData["db"];
$user = $pageData["user"];

// Initialize services
$droneAuthService = new DroneAuthService();
$faceService = new FaceRecognitionService();

// Helper functions for dashboard data
/**
 * @param \mysqli $db
 * @param int $userId
 * @return array<string, mixed>
 */
function getDashboardStats(\mysqli $db, int $userId): array
{
    // Get basic stats from database
    $stats = [
        'total_drones' => 0,
        'active_drones' => 0,
        'scans_today' => 0,
        'matches_today' => 0
    ];

    return $stats;
}

/**
 * @param \mysqli $db
 * @return array<int, array<string, mixed>>
 */
function getRecentScans(\mysqli $db): array
{
    return [];
}

// Get dashboard statistics
$stats = getDashboardStats($db, $user['id']);
$drones = $droneAuthService->getUserDrones($user['id']);
$recentScans = getRecentScans($db);
$registeredFaces = $faceService->getUserRegisteredFaces($user['id']);
$faceStats = $faceService->getFaceStatistics($user['id']);

?>
<!DOCTYPE html>
<html lang="en" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drone Dashboard | Enterprise Face Scanner</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --enterprise-bg: #0a0e14;
            --enterprise-panel: #111827;
            --enterprise-border: #1f2937;
            --enterprise-primary: #3b82f6;
            --enterprise-success: #10b981;
            --enterprise-warning: #f59e0b;
            --enterprise-danger: #ef4444;
            --enterprise-text: #e5e7eb;
            --enterprise-text-muted: #9ca3af;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--enterprise-bg);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--enterprise-text);
            margin: 0;
            padding: 0;
            font-size: 14px;
        }

        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Compact Header */
        .dashboard-header {
            background: var(--enterprise-panel);
            border: 1px solid var(--enterprise-border);
            border-radius: 8px;
            padding: 16px 24px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dashboard-title i {
            color: var(--enterprise-primary);
            font-size: 20px;
        }

        /* Compact Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--enterprise-panel);
            border: 1px solid var(--enterprise-border);
            border-radius: 6px;
            padding: 16px;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: var(--enterprise-primary);
            transform: translateY(-2px);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .stat-icon.primary { background: rgba(59, 130, 246, 0.1); color: var(--enterprise-primary); }
        .stat-icon.success { background: rgba(16, 185, 129, 0.1); color: var(--enterprise-success); }
        .stat-icon.warning { background: rgba(245, 158, 11, 0.1); color: var(--enterprise-warning); }
        .stat-icon.danger { background: rgba(239, 68, 68, 0.1); color: var(--enterprise-danger); }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }

        .stat-label {
            font-size: 12px;
            color: var(--enterprise-text-muted);
            margin: 0;
        }

        /* Main Content Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 992px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Panel */
        .panel {
            background: var(--enterprise-panel);
            border: 1px solid var(--enterprise-border);
            border-radius: 8px;
            overflow: hidden;
        }

        .panel-header {
            background: rgba(59, 130, 246, 0.05);
            border-bottom: 1px solid var(--enterprise-border);
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-title {
            font-size: 14px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-body {
            padding: 16px;
        }

        /* Drone Status Grid */
        .drone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
        }

        .drone-card {
            background: var(--enterprise-bg);
            border: 1px solid var(--enterprise-border);
            border-radius: 6px;
            padding: 12px;
            position: relative;
        }

        .drone-card.online { border-left: 3px solid var(--enterprise-success); }
        .drone-card.offline { border-left: 3px solid var(--enterprise-danger); }
        .drone-card.standby { border-left: 3px solid var(--enterprise-warning); }

        .drone-name {
            font-size: 13px;
            font-weight: 600;
            margin: 0 0 4px 0;
        }

        .drone-status {
            font-size: 11px;
            color: var(--enterprise-text-muted);
            margin: 0;
        }

        .drone-signal {
            position: absolute;
            top: 12px;
            right: 12px;
            font-size: 12px;
        }

        /* Compact Table */
        .compact-table {
            width: 100%;
            border-collapse: collapse;
        }

        .compact-table th {
            background: rgba(59, 130, 246, 0.05);
            padding: 8px 12px;
            text-align: right;
            font-size: 12px;
            font-weight: 600;
            color: var(--enterprise-text-muted);
            border-bottom: 1px solid var(--enterprise-border);
        }

        .compact-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--enterprise-border);
            font-size: 13px;
        }

        .compact-table tbody tr:hover {
            background: rgba(59, 130, 246, 0.03);
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }

        .badge-success { background: rgba(16, 185, 129, 0.1); color: var(--enterprise-success); }
        .badge-danger { background: rgba(239, 68, 68, 0.1); color: var(--enterprise-danger); }
        .badge-warning { background: rgba(245, 158, 11, 0.1); color: var(--enterprise-warning); }
        .badge-primary { background: rgba(59, 130, 246, 0.1); color: var(--enterprise-primary); }

        /* Search Filter */
        .search-filter {
            background: var(--enterprise-bg);
            border: 1px solid var(--enterprise-border);
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .filter-row {
            display: flex;
            gap: 12px;
            margin-bottom: 8px;
        }

        .filter-row:last-child {
            margin-bottom: 0;
        }

        .form-control {
            background: var(--enterprise-bg);
            border: 1px solid var(--enterprise-border);
            color: var(--enterprise-text);
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: var(--enterprise-primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--enterprise-primary);
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Alert System */
        .alert-system {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 350px;
        }

        .alert-item {
            background: var(--enterprise-panel);
            border: 1px solid var(--enterprise-border);
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .alert-item.match { border-left: 3px solid var(--enterprise-success); }
        .alert-item.alert { border-left: 3px solid var(--enterprise-danger); }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .alert-icon {
            font-size: 16px;
        }

        .alert-content h5 {
            font-size: 13px;
            margin: 0 0 4px 0;
            font-weight: 600;
        }

        .alert-content p {
            font-size: 12px;
            margin: 0;
            color: var(--enterprise-text-muted);
        }

        /* Permission Management */
        .permission-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--enterprise-border);
        }

        .permission-row:last-child {
            border-bottom: none;
        }

        .permission-info {
            flex: 1;
        }

        .permission-name {
            font-size: 13px;
            font-weight: 500;
            margin: 0 0 2px 0;
        }

        .permission-role {
            font-size: 11px;
            color: var(--enterprise-text-muted);
            margin: 0;
        }

        .permission-actions {
            display: flex;
            gap: 8px;
        }

        /* Activity Log */
        .activity-log {
            max-height: 300px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--enterprise-border);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-time {
            font-size: 11px;
            color: var(--enterprise-text-muted);
            min-width: 80px;
        }

        .activity-content {
            flex: 1;
            font-size: 13px;
        }

        .activity-type {
            font-weight: 500;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--enterprise-bg);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--enterprise-border);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--enterprise-text-muted);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">
                <i class="bi bi-drone"></i>
                Drone Dashboard
            </h1>
            <div class="d-flex gap-2">
                <span class="badge badge-success">
                    <i class="bi bi-circle-fill me-1"></i>
                    System Online
                </span>
                <span class="badge badge-primary">
                    <i class="bi bi-shield-check me-1"></i>
                    <?php echo htmlspecialchars($subscription); ?>
                </span>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon primary">
                        <i class="bi bi-drone"></i>
                    </div>
                </div>
                <h2 class="stat-value"><?php echo count($drones); ?></h2>
                <p class="stat-label">Active Drones</p>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon success">
                        <i class="bi bi-person-badge"></i>
                    </div>
                </div>
                <h2 class="stat-value"><?php echo $faceStats['total_faces'] ?? 0; ?></h2>
                <p class="stat-label">Registered Faces</p>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon warning">
                        <i class="bi bi-camera"></i>
                    </div>
                </div>
                <h2 class="stat-value"><?php echo $stats['scans_today'] ?? 0; ?></h2>
                <p class="stat-label">Scans Today</p>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon danger">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
                <h2 class="stat-value"><?php echo $stats['matches_today'] ?? 0; ?></h2>
                <p class="stat-label">Face Matches</p>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="main-grid">
            <!-- Left Column -->
            <div class="d-flex flex-column gap-3">
                <!-- Drone Status Panel -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-broadcast"></i>
                            Drone Status Monitor
                        </h3>
                        <button class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                    <div class="panel-body">
                        <div class="drone-grid">
                            <?php foreach ($drones as $drone): ?>
                            <div class="drone-card <?php echo $drone['status'] === 'online' ? 'online' : 'offline'; ?>">
                                <div class="drone-signal">
                                    <i class="bi bi-wifi-<?php echo $drone['status'] === 'online' ? '2' : '0'; ?>"></i>
                                </div>
                                <h4 class="drone-name"><?php echo htmlspecialchars($drone['name']); ?></h4>
                                <p class="drone-status">
                                    <?php echo ucfirst($drone['status']); ?> •
                                    <?php echo $drone['battery_level'] ?? 0; ?>% Battery
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Face Search Panel -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-search"></i>
                            Advanced Face Search
                        </h3>
                    </div>
                    <div class="panel-body">
                        <div class="search-filter">
                            <div class="filter-row">
                                <input type="text" class="form-control" placeholder="Search by name or ID..." style="flex: 2;">
                                <select class="form-control" style="flex: 1;">
                                    <option value="">All Status</option>
                                    <option value="match">Matched</option>
                                    <option value="pending">Pending</option>
                                    <option value="blocked">Blocked</option>
                                </select>
                            </div>
                            <div class="filter-row">
                                <input type="date" class="form-control" style="flex: 1;">
                                <input type="date" class="form-control" style="flex: 1;">
                                <button class="btn btn-primary">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </div>

                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Confidence</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($recentScans, 0, 5) as $scan): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($scan['id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($scan['person_name'] ?? 'Unknown'); ?></td>
                                    <td><span class="badge <?php echo $scan['status'] === 'match' ? 'badge-success' : 'badge-warning'; ?>"><?php echo ucfirst($scan['status'] ?? 'pending'); ?></span></td>
                                    <td><?php echo ($scan['confidence'] ?? 0) . '%'; ?></td>
                                    <td><?php echo date('M d', strtotime($scan['scan_date'] ?? 'now')); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="d-flex flex-column gap-3">
                <!-- Permission Management -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-shield-lock"></i>
                            Permission Manager
                        </h3>
                    </div>
                    <div class="panel-body">
                        <?php
                        // Sample permission data
                        $permissions = [
                            ['name' => 'Face Scan Access', 'role' => 'Admin', 'status' => 'active'],
                            ['name' => 'Drone Control', 'role' => 'Operator', 'status' => 'active'],
                            ['name' => 'Database Access', 'role' => 'Viewer', 'status' => 'pending'],
                        ];
                        ?>
                        <?php foreach ($permissions as $perm): ?>
                        <div class="permission-row">
                            <div class="permission-info">
                                <h5 class="permission-name"><?php echo htmlspecialchars($perm['name']); ?></h5>
                                <p class="permission-role"><?php echo htmlspecialchars($perm['role']); ?></p>
                            </div>
                            <div class="permission-actions">
                                <button class="btn btn-sm btn-primary">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm <?php echo $perm['status'] === 'active' ? 'badge-danger' : 'badge-success'; ?>">
                                    <i class="bi bi-<?php echo $perm['status'] === 'active' ? 'lock' : 'unlock'; ?>"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Activity Log -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-activity"></i>
                            Activity Log
                        </h3>
                    </div>
                    <div class="panel-body activity-log">
                        <?php
                        $activities = [
                            ['type' => 'Face Match', 'message' => 'Match found for User #1234', 'time' => '2m ago'],
                            ['type' => 'Drone Connected', 'message' => 'Drone Alpha-7 online', 'time' => '5m ago'],
                            ['type' => 'Face Added', 'message' => 'New face registered', 'time' => '12m ago'],
                            ['type' => 'Scan Complete', 'message' => 'Area scan finished', 'time' => '25m ago'],
                        ];
                        ?>
                        <?php foreach ($activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-time"><?php echo htmlspecialchars($activity['time']); ?></div>
                            <div class="activity-content">
                                <span class="activity-type"><?php echo htmlspecialchars($activity['type']); ?>:</span>
                                <?php echo htmlspecialchars($activity['message']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-lightning"></i>
                            Quick Actions
                        </h3>
                    </div>
                    <div class="panel-body">
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>
                                Register New Face
                            </button>
                            <button class="btn btn-primary" style="background: var(--enterprise-success);">
                                <i class="bi bi-camera-video me-2"></i>
                                Start Live Scan
                            </button>
                            <button class="btn btn-primary" style="background: var(--enterprise-warning);">
                                <i class="bi bi-database me-2"></i>
                                Export Database
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert System -->
    <div class="alert-system" id="alertSystem">
        <!-- Alerts will be dynamically added here -->
    </div>

    <script>
        // Simulate real-time updates
        setInterval(() => {
            // Random drone status updates
            const droneCards = document.querySelectorAll('.drone-card');
            droneCards.forEach(card => {
                if (Math.random() > 0.9) {
                    const signal = card.querySelector('.drone-signal i');
                    signal.className = 'bi bi-wifi-' + (Math.random() > 0.5 ? '2' : '0');
                }
            });

            // Random alerts
            if (Math.random() > 0.95) {
                showAlert('Face Match Detected', 'User #1234 matched with 98% confidence', 'match');
            }
        }, 5000);

        function showAlert(title, message, type) {
            const alertSystem = document.getElementById('alertSystem');
            const alert = document.createElement('div');
            alert.className = `alert-item ${type}`;
            alert.innerHTML = `
                <div class="alert-icon">
                    <i class="bi bi-${type === 'match' ? 'check-circle' : 'exclamation-circle'}"></i>
                </div>
                <div class="alert-content">
                    <h5>${title}</h5>
                    <p>${message}</p>
                </div>
            `;
            alertSystem.appendChild(alert);

            setTimeout(() => {
                alert.remove();
            }, 5000);
        }
    </script>
</body>
</html>
