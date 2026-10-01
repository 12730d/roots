<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/csrf.php';

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Security\CsrfProtection;

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
$isOnionService =
    (isset($_SERVER["HTTP_HOST"]) &&
        preg_match('/\.onion$/i', $_SERVER["HTTP_HOST"])) ||
    (isset($_SERVER["SERVER_NAME"]) &&
        preg_match('/\.onion$/i', $_SERVER["SERVER_NAME"]));

// ============================================================================
// SECURITY HEADERS
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
}

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
// RATE LIMITING
// ============================================================================
function checkRateLimit(string $identifier, int $maxAttempts = 15, int $windowSeconds = 60): bool {
    $key = 'osint_rate_' . hash('sha256', $identifier);
    $now = time();

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset_time' => $now + $windowSeconds];
    }

    if ($_SESSION[$key]['reset_time'] < $now) {
        $_SESSION[$key] = ['count' => 0, 'reset_time' => $now + $windowSeconds];
    }

    if ($_SESSION[$key]['count'] >= $maxAttempts) {
        return false;
    }

    $_SESSION[$key]['count']++;
    return true;
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

if ($isBlockedUser) {
    header("Location: blocked");
    exit();
}

$resources = PageController::setup('OSINT Tools', '/', ['css/screenresolutions.css']);

$con = $resources['db'] ?? Database::getConnection();
if (!$con) {
    error_log("Database connection failed in osint.php");
    die("A database error occurred. Please try again later.");
}

$is_authenticated = $resources['is_authenticated'] ?? false;
if (!$is_authenticated) {
    header('Location: login');
    exit;
}

// ============================================================================
// USER DATA
// ============================================================================
$current_user = $resources['user']['username'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;
$user_subscription_raw = $resources['user']['subscription'] ?? ($_SESSION['subscription'] ?? 'free');
$user_subscription = strtolower(trim((string) $user_subscription_raw));

// ============================================================================
// SUBSCRIPTION CHECK - OSINT TOOLS REQUIRE PAID SUBSCRIPTION
// ============================================================================
$paid_subscriptions = ['basic', 'pro', 'premium', 'vip', 'admin'];
$is_paid_user = in_array($user_subscription, $paid_subscriptions);

// ============================================================================
// OSINT SEARCH FUNCTIONALITY
// ============================================================================
$searchResults = [];
$searchPerformed = false;
$searchError = '';
$searchType = '';
$totalResults = 0;

if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $searchPerformed = true;

    // Rate limiting check
    $rateIdentifier = $_SESSION['user_id'] ?? $_SERVER['REMOTE_ADDR'];
    if (!checkRateLimit($rateIdentifier)) {
        $searchError = 'Too many searches. Please wait a minute and try again.';
    } else {
        $searchInput = trim($_GET['search']);
        $searchType = $_GET['search_type'] ?? 'email';

        // Check subscription for non-email searches
        if ($searchType !== 'email' && !$is_paid_user) {
            $searchError = 'This search type requires a paid subscription. Please upgrade to access all OSINT tools.';
        } elseif (strlen($searchInput) > 200) {
            $searchError = 'Search query too long. Maximum 200 characters.';
        } elseif (strlen($searchInput) < 3) {
            $searchError = 'Please enter at least 3 characters to search.';
        } else {
            // Perform search based on type
            switch ($searchType) {
                case 'email':
                    $searchResults = searchEmail($con, $searchInput);
                    break;
                case 'username':
                    $searchResults = searchUsername($con, $searchInput);
                    break;
                case 'ip':
                    $searchResults = searchIP($searchInput);
                    break;
                case 'domain':
                    $searchResults = searchDomain($con, $searchInput);
                    break;
                default:
                    $searchResults = searchEmail($con, $searchInput);
            }

            $totalResults = count($searchResults);
        }
    }
}

// ============================================================================
// OSINT SEARCH FUNCTIONS
// ============================================================================
/**
 * @return array<int, array<string, mixed>>
 */
function searchEmail(mysqli $con, string $email): array {
    $results = [];

    // Check if it's a valid email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [['error' => 'Invalid email format']];
    }

    // Search in password leaks
    $query = "SELECT id, email, username, source, leak_date, created_at
              FROM password_leaks
              WHERE email LIKE ?
              ORDER BY leak_date DESC
              LIMIT 10";

    $stmt = mysqli_prepare($con, $query);
    if ($stmt) {
        $searchPattern = "%$email%";
        mysqli_stmt_bind_param($stmt, "s", $searchPattern);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $results[] = [
                        'type' => 'password_leak',
                        'source' => 'password_db',
                        'data' => $row
                    ];
                }
                mysqli_free_result($result);
            }
        }
        mysqli_stmt_close($stmt);
    }

    // Add additional OSINT data sources here
    $results[] = [
        'type' => 'email_analysis',
        'source' => 'OSINT_ROOTS',
        'data' => [
            'domain' => explode('@', $email)[1],
            'domain_age' => 'Unknown',
            'disposable' => checkDisposableEmail($email),
            'deliverable' => 'Unknown'
        ]
    ];

    return $results;
}

/**
 * @return array<int, array<string, mixed>>
 */
function searchUsername(mysqli $con, string $username): array {
    $results = [];

    // Search in password leaks
    $query = "SELECT id, email, username, source, leak_date, created_at
              FROM password_leaks
              WHERE username LIKE ?
              ORDER BY leak_date DESC
              LIMIT 10";

    $stmt = mysqli_prepare($con, $query);
    if ($stmt) {
        $searchPattern = "%$username%";
        mysqli_stmt_bind_param($stmt, "s", $searchPattern);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $results[] = [
                        'type' => 'password_leak',
                        'source' => 'password_db',
                        'data' => $row
                    ];
                }
                mysqli_free_result($result);
            }
        }
        mysqli_stmt_close($stmt);
    }

    // Simulate social media presence check
    $socialPlatforms = [
        'twitter' => checkSocialPresence($username, 'twitter'),
        'github' => checkSocialPresence($username, 'github'),
        'instagram' => checkSocialPresence($username, 'instagram'),
        'linkedin' => checkSocialPresence($username, 'linkedin')
    ];

    $results[] = [
        'type' => 'social_presence',
        'source' => 'OSINT_ROOTS',
        'data' => $socialPlatforms
    ];

    return $results;
}

/**
 * @return array<int, array<string, mixed>>
 */
function searchIP(string $ip): array {
    $results = [];

    // Validate IP
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return [['error' => 'Invalid IP address format']];
    }

    // Add IP search logic here
    $results[] = [
        'type' => 'ip_info',
        'source' => 'OSINT_ROOTS',
        'data' => [
            'ip' => $ip,
            'country' => 'Unknown',
            'isp' => 'Unknown',
            'is_vpn' => false,
            'is_tor' => false
        ]
    ];

    return $results;
}

/**
 * @return array<int, array<string, mixed>>
 */
function searchDomain(mysqli $con, string $domain): array {
    $results = [];

    // Validate Domain
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/i', $domain)) {
        return [['error' => 'Invalid domain format']];
    }

    // Add Domain search logic here
    $results[] = [
        'type' => 'domain_info',
        'source' => 'osint_ROOTS',
        'data' => [
            'domain' => $domain,
            'registrar' => 'Unknown',
            'created_date' => 'Unknown',
            'ssl_valid' => true
        ]
    ];

    return $results;
}

function checkDisposableEmail(string $email): bool {
    $disposableDomains = ['tempmail.com', 'mailinator.com', 'guerrillamail.com'];
    $domain = explode('@', $email)[1];
    return in_array($domain, $disposableDomains);
}

/**
 * @return array<string, mixed>
 */
function checkSocialPresence(string $username, string $platform): array {
    // Simulated check using username
    $isFound = (strlen($username) > 0 && rand(0, 1) == 1);
    return [
        'platform' => $platform,
        'found' => $isFound
    ];
}

function maskEmail(string $email): string {
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1];
    $maskedName = substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
    return $maskedName . '@' . $domain;
}

function maskUsername(string $username): string {
    if ($username === 'N/A') {
        return 'N/A';
    }
    return substr($username, 0, 2) . str_repeat('*', max(0, strlen($username) - 2));
}

// ============================================================================
// PAGE CONTENT START
// ============================================================================
?>

<style>
/* OSINT Page Styles - Force Black Background */
html, body, .content, .main-container {
    background-color: #000000 !important;
    color: #00ff00 !important;
    margin: 0;
    padding: 0;
    min-height: 100vh;
}

.osint-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.osint-header {
    text-align: center;
    margin-bottom: 40px;
    padding: 30px;
    background: #000000;
    border-radius: 15px;
    border: 1px solid rgba(0, 255, 0, 0.3);
}

.osint-header h1 {
    color: #00ff00;
    font-size: 2.5em;
    margin-bottom: 10px;
}

.osint-header .subtitle {
    color: #888;
    font-size: 1.1em;
}

.osint-search-section {
    background: #000000;
    padding: 30px;
    border-radius: 15px;
    border: 1px solid rgba(0, 255, 0, 0.2);
    margin-bottom: 30px;
}

.search-type-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.search-tab {
    flex: 1;
    min-width: 120px;
    padding: 12px 20px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    color: #888;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 0.9em;
}

.search-tab:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}

.search-tab.active {
    background: rgba(0, 255, 0, 0.2);
    border-color: rgba(0, 255, 0, 0.5);
    color: #00ff00;
}

.search-tab.restricted-tab {
    opacity: 0.8;
}

.search-tab.restricted-tab i.fa-lock {
    color: #ffaa00;
}

.badge {
    padding: 4px 8px;
    font-size: 0.7em;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-radius: 4px;
}

.bg-success { background-color: rgba(0, 255, 0, 0.2) !important; color: #00ff00 !important; border: 1px solid rgba(0, 255, 0, 0.3); }
.bg-primary { background-color: rgba(0, 123, 255, 0.2) !important; color: #007bff !important; border: 1px solid rgba(0, 123, 255, 0.3); }
</style>

<div class="loading-overlay" id="pageLoader" style="display: none;">
    <div class="loading-spinner"></div>
    <div class="loading-text">Loading OSINT Tools...</div>
</div>

<script>
// Loader is hidden by default. Hide it on load if it was shown.
document.addEventListener('DOMContentLoaded', function() {
    const loader = document.getElementById('pageLoader');
    if (loader) loader.style.display = 'none';
});
</script>

<!-- OSINT Tools Container -->
<div class="osint-container">

    <!-- Header -->
    <div class="osint-header">
        <h1><i class="fas fa-search-plus"></i> Infinity OSINT</h1>
        <p class="subtitle">Advanced Open Source Intelligence Tools</p>
    </div>

    <!-- Search Form -->
    <div class="osint-search-section">
        <form action="" method="GET" class="osint-search-form">

            <div class="search-type-tabs">
                <button type="button" class="search-tab active" data-type="email">
                    <i class="fas fa-envelope"></i> Email (Free)
                </button>
                <button type="button" class="search-tab <?= !$is_paid_user ? 'restricted-tab' : '' ?>" data-type="username">
                    <i class="fas fa-user"></i> Username <?= !$is_paid_user ? '<i class="fas fa-lock ms-1"></i>' : '' ?>
                </button>
                <button type="button" class="search-tab <?= !$is_paid_user ? 'restricted-tab' : '' ?>" data-type="ip">
                    <i class="fas fa-globe"></i> IP Address <?= !$is_paid_user ? '<i class="fas fa-lock ms-1"></i>' : '' ?>
                </button>
                <button type="button" class="search-tab <?= !$is_paid_user ? 'restricted-tab' : '' ?>" data-type="domain">
                    <i class="fas fa-server"></i> Domain <?= !$is_paid_user ? '<i class="fas fa-lock ms-1"></i>' : '' ?>
                </button>
            </div>

            <input type="hidden" name="search_type" id="searchType" value="email">

            <?php if (!$is_paid_user): ?>
            <!-- Upgrade Banner for Free Users -->
            <div id="upgradeBanner" class="alert alert-info mb-3" style="display: none; background: rgba(0, 123, 255, 0.1); border: 1px solid rgba(0, 123, 255, 0.3); color: #007bff;">
                <i class="fas fa-crown"></i> <strong>Premium Tool:</strong> This search type requires a paid subscription. <a href="/buy_points" style="color: #007bff; text-decoration: underline;">Upgrade Now</a>
            </div>
            <?php endif; ?>

            <div class="search-input-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text"
                       name="search"
                       required
                       class="osint-search-input"
                       placeholder="Enter email address to search..."
                       autocomplete="off"
                       value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>"
                       maxlength="200"
                       <?= (!$is_paid_user && isset($_GET['search_type']) && $_GET['search_type'] !== 'email') ? 'disabled' : '' ?>>
                <button type="submit" class="osint-search-btn" id="submitBtn" <?= (!$is_paid_user && isset($_GET['search_type']) && $_GET['search_type'] !== 'email') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                    <i class="fas fa-crosshairs"></i> Investigate
                </button>
            </div>

            <div class="search-hint">
                <i class="fas fa-info-circle"></i>
                <span id="searchHint">Search for email addresses across multiple data sources</span>
            </div>

            <?php if ($searchError): ?>
            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($searchError) ?>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- Search Results -->
    <?php if ($searchPerformed && !$searchError): ?>
    <div class="osint-results-section">
        <div class="results-header">
            <h3><i class="fas fa-database"></i> Investigation Results</h3>
            <span class="results-count"><?= number_format($totalResults) ?> findings</span>
        </div>

        <?php if (count($searchResults) > 0): ?>
        <div class="results-container">
            <?php foreach ($searchResults as $result): ?>
            <?php if (isset($result['error'])): ?>
            <div class="result-card error-card">
                <div class="result-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="result-content">
                    <h4>Error</h4>
                    <p><?= htmlspecialchars($result['error']) ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="result-card">
                <div class="result-icon">
                    <?php if ($result['type'] === 'password_leak'): ?>
                        <i class="fas fa-key"></i>
                    <?php elseif ($result['type'] === 'email_analysis'): ?>
                        <i class="fas fa-envelope-open"></i>
                    <?php elseif ($result['type'] === 'social_presence'): ?>
                        <i class="fas fa-users"></i>
                    <?php elseif ($result['type'] === 'ip_info'): ?>
                        <i class="fas fa-globe"></i>
                    <?php elseif ($result['type'] === 'domain_info'): ?>
                        <i class="fas fa-server"></i>
                    <?php else: ?>
                        <i class="fas fa-info-circle"></i>
                    <?php endif; ?>
                </div>
                <div class="result-content">
                    <h4><?= ucfirst(str_replace('_', ' ', $result['type'])) ?></h4>
                    <span class="result-source">Source: <?= htmlspecialchars($result['source']) ?></span>

                    <?php if ($result['type'] === 'password_leak'): ?>
                        <div class="result-details">
                            <p><strong>Email:</strong> <?= maskEmail($result['data']['email']) ?></p>
                            <p><strong>Username:</strong> <?= maskUsername($result['data']['username'] ?? 'N/A') ?></p>
                            <p><strong>Source:</strong> <?= htmlspecialchars($result['data']['source']) ?></p>
                            <p><strong>Leak Date:</strong> <?= htmlspecialchars($result['data']['leak_date'] ?? 'Unknown') ?></p>
                        </div>
                    <?php elseif ($result['type'] === 'email_analysis'): ?>
                        <div class="result-details">
                            <p><strong>Domain:</strong> <?= htmlspecialchars($result['data']['domain']) ?></p>
                            <p><strong>Disposable:</strong> <?= $result['data']['disposable'] ? 'Yes' : 'No' ?></p>
                            <p><strong>Deliverable:</strong> <?= htmlspecialchars($result['data']['deliverable']) ?></p>
                        </div>
                    <?php elseif ($result['type'] === 'social_presence'): ?>
                        <div class="result-details">
                            <?php foreach ($result['data'] as $platform): ?>
                            <div class="social-check">
                                <i class="fab fa-<?= $platform['platform'] ?>"></i>
                                <?= ucfirst($platform['platform']) ?>:
                                <?= $platform['found'] ? '<span class="found">Found</span>' : '<span class="not-found">Not Found</span>' ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($result['type'] === 'ip_info'): ?>
                        <div class="result-details">
                            <p><strong>IP:</strong> <?= htmlspecialchars($result['data']['ip']) ?></p>
                            <p><strong>Country:</strong> <?= htmlspecialchars($result['data']['country']) ?></p>
                            <p><strong>ISP:</strong> <?= htmlspecialchars($result['data']['isp']) ?></p>
                            <p><strong>VPN:</strong> <?= $result['data']['is_vpn'] ? 'Yes' : 'No' ?></p>
                            <p><strong>Tor:</strong> <?= $result['data']['is_tor'] ? 'Yes' : 'No' ?></p>
                        </div>
                    <?php elseif ($result['type'] === 'domain_info'): ?>
                        <div class="result-details">
                            <p><strong>Domain:</strong> <?= htmlspecialchars($result['data']['domain']) ?></p>
                            <p><strong>Registrar:</strong> <?= htmlspecialchars($result['data']['registrar']) ?></p>
                            <p><strong>Created:</strong> <?= htmlspecialchars($result['data']['created_date']) ?></p>
                            <p><strong>SSL Valid:</strong> <?= $result['data']['ssl_valid'] ? 'Yes' : 'No' ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-results">
            <i class="fas fa-shield-alt fa-3x text-success"></i>
            <h4>No Results Found</h4>
            <p>No information found for your search query.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Info Section -->
    <div class="osint-info-section">
        <div class="info-card">
            <h4><i class="fas fa-search"></i> What is OSINT?</h4>
            <p>Open Source Intelligence (OSINT) is the collection and analysis of information gathered from publicly available sources to be used in an intelligence context.</p>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-shield-alt"></i> Privacy & Security</h4>
            <ul>
                <li>All searches are logged for security purposes</li>
                <li>Rate limited to prevent abuse</li>
                <li>Data comes from publicly available sources</li>
                <li>We do not store sensitive personal information</li>
            </ul>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-tools"></i> Available Tools</h4>
            <ul>
                <li><strong>Email Analysis:</strong> <span class="badge bg-success">Free</span> Check email breaches and validity</li>
                <li><strong>Username Search:</strong> <span class="badge bg-primary">Premium</span> Find social media presence</li>
                <li><strong>IP Lookup:</strong> <span class="badge bg-primary">Premium</span> Geolocation and VPN detection</li>
                <li><strong>Domain Info:</strong> <span class="badge bg-primary">Premium</span> WHOIS and SSL verification</li>
            </ul>
        </div>
    </div>

</div>

<style>
.search-tab i {
    margin-right: 8px;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    gap: 10px;
}

.search-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #888;
    font-size: 1.2em;
}

.osint-search-input {
    flex: 1;
    padding: 15px 15px 15px 45px;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    color: #fff;
    font-size: 1em;
    transition: all 0.3s;
}

.osint-search-input:focus {
    outline: none;
    border-color: rgba(0, 255, 0, 0.5);
    box-shadow: 0 0 15px rgba(0, 255, 0, 0.2);
}

.osint-search-btn {
    padding: 15px 30px;
    background: linear-gradient(135deg, #00ff00 0%, #006400 100%);
    border: none;
    border-radius: 8px;
    color: #000;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
}

.osint-search-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0, 255, 0, 0.3);
}

.search-hint {
    margin-top: 15px;
    color: #888;
    font-size: 0.9em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.osint-results-section {
    margin-bottom: 30px;
}

.results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.results-header h3 {
    color: #00ff00;
    margin: 0;
}

.results-count {
    background: rgba(0, 255, 0, 0.2);
    padding: 5px 15px;
    border-radius: 20px;
    color: #00ff00;
    font-size: 0.9em;
}

.results-container {
    display: grid;
    gap: 20px;
}

.result-card {
    background: #000000;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    gap: 20px;
    transition: all 0.3s;
}

.result-card:hover {
    border-color: rgba(0, 255, 0, 0.3);
    transform: translateY(-2px);
}

.result-card.error-card {
    border-color: rgba(255, 68, 68, 0.3);
}

.result-icon {
    width: 50px;
    height: 50px;
    background: rgba(0, 255, 0, 0.1);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #00ff00;
    font-size: 1.5em;
    flex-shrink: 0;
}

.result-card.error-card .result-icon {
    background: rgba(255, 68, 68, 0.1);
    color: #ff4444;
}

.result-content {
    flex: 1;
}

.result-content h4 {
    color: #fff;
    margin: 0 0 5px 0;
}

.result-source {
    color: #888;
    font-size: 0.85em;
}

.result-details {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
}

.result-details p {
    color: #ccc;
    margin: 5px 0;
    font-size: 0.9em;
}

.result-details strong {
    color: #00ff00;
}

.social-check {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 8px 0;
    color: #ccc;
}

.social-check .found {
    color: #00ff00;
    font-weight: bold;
}

.social-check .not-found {
    color: #ff4444;
}

.no-results {
    text-align: center;
    padding: 60px 20px;
    background: #000000;
    border-radius: 15px;
    border: 1px solid rgba(0, 255, 0, 0.2);
}

.no-results h4 {
    color: #00ff00;
    margin: 20px 0 10px 0;
}

.no-results p {
    color: #888;
}

.osint-info-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.info-card {
    background: #000000;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 25px;
}

.info-card h4 {
    color: #00ff00;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-card p {
    color: #ccc;
    line-height: 1.6;
}

.info-card ul {
    color: #ccc;
    padding-left: 20px;
}

.info-card li {
    margin: 10px 0;
    line-height: 1.6;
}

.info-card strong {
    color: #00ff00;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-top: 15px;
}

.alert-warning {
    background: rgba(255, 170, 0, 0.1);
    border: 1px solid rgba(255, 170, 0, 0.3);
    color: #ffaa00;
}

/* Upgrade Message Styles */
.upgrade-message {
    background: linear-gradient(135deg, rgba(255, 170, 0, 0.1) 0%, rgba(255, 152, 0, 0.1) 100%);
    border: 2px solid rgba(255, 170, 0, 0.3);
    border-radius: 15px;
    padding: 40px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
}

.upgrade-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 170, 0, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffaa00;
    font-size: 2.5em;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.upgrade-content h3 {
    color: #ffaa00;
    font-size: 1.8em;
    margin: 0 0 15px 0;
}

.upgrade-content p {
    color: #ccc;
    margin: 10px 0;
    font-size: 1.1em;
}

.upgrade-subtitle {
    color: #888;
    font-size: 0.9em;
    font-style: italic;
}

.upgrade-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 15px 40px;
    background: linear-gradient(135deg, #ffaa00 0%, #ff9800 100%);
    color: #000;
    text-decoration: none;
    border-radius: 30px;
    font-weight: bold;
    font-size: 1.1em;
    transition: all 0.3s;
    margin-top: 10px;
}

.upgrade-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(255, 170, 0, 0.4);
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.loading-spinner {
    width: 50px;
    height: 50px;
    border: 3px solid rgba(0, 255, 0, 0.3);
    border-top-color: #00ff00;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.loading-text {
    color: #00ff00;
    margin-top: 20px;
    font-size: 1.1em;
}

@media (max-width: 768px) {
    .osint-header h1 {
        font-size: 1.8em;
    }

    .search-type-tabs {
        flex-direction: column;
    }

    .search-input-wrapper {
        flex-direction: column;
    }

    .osint-search-btn {
        width: 100%;
    }

    .result-card {
        flex-direction: column;
    }

    .osint-info-section {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search type tabs
    const searchTabs = document.querySelectorAll('.search-tab');
    const searchTypeInput = document.getElementById('searchType');
    const searchInput = document.querySelector('.osint-search-input');
    const searchHint = document.getElementById('searchHint');
    const upgradeBanner = document.getElementById('upgradeBanner');
    const submitBtn = document.getElementById('submitBtn');

    const isPaidUser = <?= $is_paid_user ? 'true' : 'false' ?>;

    const hints = {
        email: 'Search for email addresses across multiple data sources',
        username: 'Search for usernames across social media platforms',
        ip: 'Lookup IP address information and geolocation',
        domain: 'Get domain information and WHOIS data'
    };

    const placeholders = {
        email: 'Enter email address to search...',
        username: 'Enter username to search...',
        ip: 'Enter IP address to lookup...',
        domain: 'Enter domain to investigate...'
    };

    const validateForm = () => {
        const type = searchTypeInput.value;
        const value = searchInput.value.trim();
        let isValid = false;

        if (value.length === 0) {
            isValid = false;
        } else {
            switch (type) {
                case 'email':
                    isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
                    break;
                case 'ip':
                    isValid = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/.test(value);
                    break;
                case 'domain':
                    isValid = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/i.test(value);
                    break;
                case 'username':
                    isValid = value.length >= 3;
                    break;
                default:
                    isValid = value.length >= 3;
            }
        }

        // Check if restricted by subscription
        const isRestricted = !isPaidUser && type !== 'email';

        if (isRestricted) {
            searchInput.disabled = true;
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
            if (upgradeBanner) upgradeBanner.style.display = 'block';
        } else {
            searchInput.disabled = false;
            submitBtn.disabled = !isValid;
            submitBtn.style.opacity = isValid ? '1' : '0.5';
            submitBtn.style.cursor = isValid ? 'pointer' : 'not-allowed';
            if (upgradeBanner) upgradeBanner.style.display = 'none';
        }
    };

    searchTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active class from all tabs
            searchTabs.forEach(t => t.classList.remove('active'));
            // Add active class to clicked tab
            this.classList.add('active');

            // Update search type
            const type = this.getAttribute('data-type');
            searchTypeInput.value = type;

            // Update placeholder and hint
            searchInput.placeholder = placeholders[type];
            searchHint.textContent = hints[type];

            // Validate form after type change
            validateForm();
        });
    });

    // Add input listener for validation
    searchInput.addEventListener('input', validateForm);

    // Set initial state based on URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const searchTypeFromUrl = urlParams.get('search_type');
    if (searchTypeFromUrl) {
        const matchingTab = document.querySelector(`.search-tab[data-type="${searchTypeFromUrl}"]`);
        if (matchingTab) {
            matchingTab.click();
        }
    }

    // Run initial validation
    validateForm();
});
</script>

<?php
PageController::end();

