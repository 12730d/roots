<?php

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Layout\PaymentModal;

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

// Setup Page - Maintaining original logic
PageController::setup("Buy Points - Terminal Access", "./", [
    "css/payment-modal.css",
    "css/buy_points.css",
]);
?>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<?php
// Use centralized database connection
$db = Database::getConnection();

if (!$db) {
    die("SYSTEM ERROR: DATABASE CONNECTION FAILED. TERMINATING.");
}

// Get current user points
$username = $_SESSION["username"];
$user_points = 0;
$total_purchased = 0;

$query = "SELECT points FROM login WHERE username = ?";
$stmt = mysqli_prepare($db, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result !== false) {
        $fetched = mysqli_fetch_assoc($result);
        if (is_array($fetched)) {
            $user_points = (int) ($fetched["points"] ?? 0);
        }
    }
    mysqli_stmt_close($stmt);
}

// Get total purchased points for tier calculation
$total_purchased = 0;
$user_id = $_SESSION["user_id"] ?? null;
$username = $_SESSION["username"] ?? null;

$purchaseQueries = [
    "payment_transactions" => [
        "sql" => "SELECT COALESCE(SUM(points_purchased + bonus_points), 0) as total FROM payment_transactions WHERE username = ? AND status = 'completed'",
        "paramType" => "s",
        "value" => $username,
    ],
    "points_transactions" => [
        "sql" => "SELECT COALESCE(SUM(points_added + bonus_points), 0) as total FROM points_transactions WHERE username = ? AND status = 'completed'",
        "paramType" => "s",
        "value" => $username,
    ],
    "user_purchases" => [
        "sql" => "SELECT COALESCE(SUM(points_spent), 0) as total FROM user_purchases WHERE user_id = ?",
        "paramType" => "i",
        "value" => $user_id,
    ],
];

foreach ($purchaseQueries as $table => $data) {
    if ($table === "user_purchases" && !$user_id) {
        continue;
    }
    if (($table === "payment_transactions" || $table === "points_transactions") && !$username) {
        continue;
    }

    $stmt = mysqli_prepare($db, $data["sql"]);
    if (!$stmt) {
        continue;
    }

    mysqli_stmt_bind_param($stmt, $data["paramType"], $data["value"]);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        continue;
    }

    $result = mysqli_stmt_get_result($stmt);
    if ($result !== false) {
        $fetched = mysqli_fetch_assoc($result);
        if (is_array($fetched)) {
            $total_purchased = (int) ($fetched["total"] ?? 0);
            mysqli_stmt_close($stmt);
            break;
        }
    }
    mysqli_stmt_close($stmt);
}

// Test mode - for demonstration purposes
if (!empty($_GET["test_tier"]) && $_GET["test_tier"] === "demo") {
    $test_values = [0, 1500, 5000, 9000, 12000, 15000];
    $test_index = (int) ($_GET["demo_value"] ?? 0) % count($test_values);
    $total_purchased = $test_values[$test_index];
}

// Calculate subscription tier
$tier = "Starter";
$tier_color = "#008f11";
$progress_percent = 0;
$progress_goal = 3000;

if ($total_purchased >= 12000) {
    $tier = "Premium";
    $tier_color = "#ff6b00";
    $progress_goal = 12000;
    $progress_percent = 100;
} elseif ($total_purchased >= 7000) {
    $tier = "Pro";
    $tier_color = "#39ff14";
    $progress_goal = 12000;
    $progress_percent = (($total_purchased - 7000) / (12000 - 7000)) * 100;
} elseif ($total_purchased >= 3000) {
    $tier = "Basic";
    $tier_color = "#00ff41";
    $progress_goal = 7000;
    $progress_percent = (($total_purchased - 3000) / (7000 - 3000)) * 100;
} else {
    $tier = "Starter";
    $tier_color = "#008f11";
    $progress_goal = 3000;
    $progress_percent = ($total_purchased / 3000) * 100;
}

$progress_percent = max(0, min(100, round($progress_percent, 2)));

// --- Configuration: Packages Data ---
$packages = [
    [
        "id" => "basic",
        "amount" => 1000,
        "price" => 1000,
        "description" => "LEVEL 1",
        "icon" => "fas fa-bolt",
        "bonus" => 0,
        "tag" => ""
    ],
    [
        "id" => "standard",
        "amount" => 5000,
        "price" => 4750,
        "description" => "LEVEL 2",
        "icon" => "fas fa-star",
        "bonus" => 250,
        "tag" => "POPULAR"
    ],
    [
        "id" => "premium",
        "amount" => 10000,
        "price" => 9000,
        "description" => "LEVEL 3",
        "icon" => "fas fa-crown",
        "bonus" => 1000,
        "tag" => ""
    ],
    [
        "id" => "elite",
        "amount" => 20000,
        "price" => 15000,
        "description" => "LEVEL 4",
        "icon" => "fas fa-gem",
        "bonus" => 5000,
        "tag" => "BEST VALUE"
    ],
    [
        "id" => "ultimate",
        "amount" => 35000,
        "price" => 23000,
        "description" => "LEVEL 5",
        "icon" => "fas fa-fire",
        "bonus" => 12000,
        "tag" => ""
    ],
    [
        "id" => "mega",
        "amount" => 60000,
        "price" => 34000,
        "description" => "MEGA ACCESS",
        "icon" => "fas fa-rocket",
        "bonus" => 26000,
        "tag" => ""
    ],
    [
        "id" => "ultra",
        "amount" => 100000,
        "price" => 50000,
        "description" => "ULTRA ACCESS",
        "icon" => "fas fa-brain",
        "bonus" => 50000,
        "tag" => ""
    ],
    [
        "id" => "supreme",
        "amount" => 150000,
        "price" => 70000,
        "description" => "SUPREME ACCESS",
        "icon" => "fas fa-infinity",
        "bonus" => 80000,
        "tag" => "MAXIMUM"
    ],
];
?>
<div id="spinner"
    class="position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
    <div class="text-center"><output class="spinner-border mb-3"></output>
        <div class="text-uppercase ls-2">INITIALIZING TERMINAL...</div>
    </div>
</div>
<div class="grid-bg" aria-hidden="true"></div>

<div class="header-container">
    <div class="container-fluid pt-3 pb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap: 1rem;">
            <div>
                <h3 class="header-title mb-0 blinking-cursor"><i class="fas fa-terminal me-2"></i>SECURE PAYMENT SYSTEM</h3>
                <small class="text-muted">ENCRYPTED TRANSACTION // SSL 256-BIT</small>
            </div>
            <div class="d-flex align-items-center header-stat">
                <span class="text-muted me-2">AVAILABLE:</span>
                <h4 class="mb-0 fw-bold"><?php echo number_format($user_points); ?> PTS</h4>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4 mb-5">
    <div class="intro-section">
        <h5 class="text-uppercase mb-3">Choose Your Best Plan</h5>
        <p>Get instant points with additional rewards. All transactions are protected and secure.</p>

        <div class="trust-badges">
            <div class="trust-badge">
                <i class="fas fa-lock"></i>
                <small>100% Secure</small>
            </div>
            <div class="trust-badge">
                <i class="fas fa-bolt"></i>
                <small>Instant Activation</small>
            </div>
            <div class="trust-badge">
                <i class="fas fa-undo"></i>
                <small>Satisfaction Guaranteed</small>
            </div>
            <div class="trust-badge">
                <i class="fas fa-headset"></i>
                <small>24/7 Support</small>
            </div>
        </div>
    </div>

    <div id="packagesGrid"><?php foreach ($packages as $package): ?>
            <div>
                <div class="terminal-card<?php echo ($package["tag"] !== "" ? ' featured' : ''); ?>">
                    <div class="package-header">
                        <i class="package-icon <?php echo $package["icon"]; ?>"></i>
                            <?php if ($package["tag"] !== ""): ?>
                            <div class="package-tag"><?php echo $package["tag"]; ?></div>
                            <?php endif; ?>
                        <div class="package-name"><?php echo $package["description"]; ?></div>
                        <div class="package-description">Level
                                <?php echo str_pad((array_search($package, $packages) + 1), 1, '0', STR_PAD_LEFT); ?>
                        </div>
                    </div>

                    <div class="package-amount"><?php echo number_format($package["amount"]); ?></div>

                        <?php if ($package["bonus"] > 0): ?>
                        <div class="bonus-info">
                            <strong>+ <?php echo number_format($package["bonus"]); ?> BONUS</strong><br>
                            <small>Extra bonus on purchase</small>
                        </div>
                        <?php endif; ?>

                    <div class="price-wrapper">
                        <div class="package-price"><?php echo number_format($package["price"]); ?></div>
                        <button class="btn-terminal buy-points" data-bs-toggle="modal" data-bs-target="#paymentModal"
                            data-amount="<?php echo $package["amount"]; ?>" data-price="<?php echo $package["price"]; ?>"
                            data-bonus="<?php echo $package["bonus"]; ?>" data-package="<?php echo $package["id"]; ?>">
                            <i class="fas fa-shopping-cart me-2"></i>Buy Now
                        </button>
                    </div>
                </div>
            </div><?php endforeach; ?>
    </div>
</div>

<div class="section-divider"></div>

<!-- Subscription Tier Progress Section -->
<div class="container-fluid px-4 mb-5">
    <div class="tier-progress-section">
        <div class="tier-header">
            <div class="tier-info">
                <h6>Your Subscription Tier</h6>
                <small class="text-muted">Advance your level by purchasing more points</small>
            </div>
            <div class="tier-badge tier-<?php echo strtolower($tier === 'Starter' ? 'starter' : strtolower($tier)); ?>">
                <?php echo $tier; ?>
            </div>
        </div>

        <div class="progress-container">
            <div class="progress-bar-bg">
                <div class="progress-bar-fill <?php echo strtolower($tier === 'Basic' || $tier === 'Pro' || $tier === 'Premium' ? strtolower($tier) : ''); ?>"
                    role="progressbar" aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0"
                    aria-valuemax="100" style="width: <?php echo $progress_percent; ?>%;">
                    <?php echo round($progress_percent); ?>%
                </div>
            </div>

            <div class="tier-milestones">
                <div class="milestone <?php echo ($total_purchased >= 0 ? 'completed' : ''); ?>">
                    <div class="milestone-label">Starter</div>
                    <div class="milestone-value">0 PTS</div>
                </div>
                <div
                    class="milestone <?php echo ($total_purchased >= 3000 ? 'completed' : ($total_purchased > 0 && $total_purchased < 3000 ? 'active' : '')); ?>">
                    <div class="milestone-label">Basic</div>
                    <div class="milestone-value">3,000 PTS</div>
                </div>
                <div
                    class="milestone <?php echo ($total_purchased >= 7000 ? 'completed' : ($total_purchased > 3000 && $total_purchased < 7000 ? 'active' : '')); ?>">
                    <div class="milestone-label">Pro</div>
                    <div class="milestone-value">7,000 PTS</div>
                </div>
                <div
                    class="milestone <?php echo ($total_purchased >= 12000 ? 'completed active' : ($total_purchased > 7000 && $total_purchased < 12000 ? 'active' : '')); ?>">
                    <div class="milestone-label">Premium</div>
                    <div class="milestone-value">12,000 PTS</div>
                </div>
            </div>

            <?php if ($total_purchased === 0): ?>
                <div
                    style="text-align: center; margin-top: 1rem; padding: 1rem; background: rgba(0, 143, 17, 0.1); border: 1px solid var(--term-dim); border-radius: 4px;">
                    <small class="text-muted">Start your journey by purchasing points above to unlock tier benefits!
                        🚀</small>
                </div>
            <?php endif; ?>
        </div>

        <div class="tier-stats">
            <div class="stat-box">
                <div class="stat-label">Total Purchased</div>
                <div class="stat-value"><?php echo number_format($total_purchased); ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Current Balance</div>
                <div class="stat-value"><?php echo number_format($user_points); ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Current Tier</div>
                <div class="stat-value"><?php echo $tier; ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">
                    <?php
                    if ($tier === 'Premium') {
                        echo 'Tier Completed';
                    } elseif ($tier === 'Pro') {
                        echo 'Until Premium';
                    } elseif ($tier === 'Basic') {
                        echo 'Until Pro';
                    } else {
                        echo 'Until Basic';
                    }
                    ?>
                </div>
                <div class="stat-value">
                    <?php
                    if ($tier === 'Premium') {
                        echo '✓ MAX';
                    } elseif ($tier === 'Pro') {
                        echo number_format(12000 - $total_purchased);
                    } elseif ($tier === 'Basic') {
                        echo number_format(7000 - $total_purchased);
                    } else {
                        echo number_format(3000 - $total_purchased);
                    }
                    ?> PTS
                </div>
            </div>
        </div>
    </div>
</div>

<?php PaymentModal::render(); ?>

<script>
    window.addEventListener('load', function () {
        const spinner = document.getElementById('spinner');
        if (spinner) {
            spinner.classList.add('hide');
            setTimeout(() => {
                spinner.style.display = 'none';
            }, 300);
        }

        // Animate progress bar (no debug logs)
        const progressBar = document.querySelector('.progress-bar-fill');
        if (progressBar) {
            setTimeout(() => {
                progressBar.style.width = '<?php echo $progress_percent; ?>%';
            }, 200);
        }

        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
</script>
<?php PageController::end("./", [
    "/lib/chart/chart.min.js",
    "/lib/easing/easing.min.js",
    "/lib/waypoints/waypoints.min.js",
    "/lib/tempusdominus/js/moment.min.js",
    "/lib/tempusdominus/js/moment-timezone.min.js",
    "/lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js",
    "/js/main.js",
    "/js/payment-modal.js",
]);
?>