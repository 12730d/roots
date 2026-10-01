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
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
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
function checkRateLimit(string $identifier, int $maxAttempts = 10, int $windowSeconds = 60): bool
{
    $key = 'search_rate_' . hash_hmac('sha256', $identifier, 'rate_limit_secret_key');
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
                $stmt = @$dbCheck->prepare(
                    "SELECT ban_until, suspended FROM user_security_guard WHERE user_id = ? LIMIT 1",
                );
                if ($stmt) {
                    $uid = (int) $_SESSION["user_id"];
                    $stmt->bind_param("i", $uid);
                    if (@$stmt->execute()) {
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
                    }
                    $stmt->close();
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

$resources = PageController::setup('Password Database', '/', ['css/search_table.css', 'css/password_db.css']);

$con = $resources['db'] ?? Database::getConnection();
if (!$con) {
    error_log("Database connection failed in password_db.php");
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

$levels = [
    'free' => 0.7,
    'basic' => 25.0,
    'pro' => 86.0,
    'premium' => 93.0,
    'vip' => 99.0,
    'admin' => 100.0
];

$subscriptionKey = array_key_exists($user_subscription, $levels) ? $user_subscription : 'free';
$percent = $levels[$subscriptionKey];
$percentForBar = max(0, min(100, (float) $percent));
$percentLabel = rtrim(rtrim(number_format((float) $percent, 1, '.', ''), '0'), '.');

// ============================================================================
// GET USER'S PURCHASES FOR PASSWORD SEARCH
// ============================================================================
$passwordPurchases = 0;
$purchasesQuery = "SELECT COUNT(*) as total FROM user_purchases WHERE user_id = ? AND record_type = 'password_leak'";
$purchasesStmt = mysqli_prepare($con, $purchasesQuery);
if ($purchasesStmt) {
    mysqli_stmt_bind_param($purchasesStmt, "s", $current_user);
    mysqli_stmt_execute($purchasesStmt);
    $purchasesResult = mysqli_stmt_get_result($purchasesStmt);
    if ($purchasesResult) {
        $purchasesData = mysqli_fetch_assoc($purchasesResult);
        $passwordPurchases = (int) ($purchasesData['total'] ?? 0);
    }
    mysqli_stmt_close($purchasesStmt);
}

// ============================================================================
// GET DAILY ATTEMPTS REMAINING
// ============================================================================
$dailyAttemptsRemaining = 50;
$dailyAttemptsQuery = "SELECT daily_attempts, last_reset_date FROM user_daily_limits WHERE username = ?";
$dailyAttemptsStmt = mysqli_prepare($con, $dailyAttemptsQuery);
if ($dailyAttemptsStmt) {
    mysqli_stmt_bind_param($dailyAttemptsStmt, "s", $current_user);
    mysqli_stmt_execute($dailyAttemptsStmt);
    $dailyAttemptsResult = mysqli_stmt_get_result($dailyAttemptsStmt);
    if ($dailyAttemptsResult) {
        $dailyAttemptsData = mysqli_fetch_assoc($dailyAttemptsResult);
        if ($dailyAttemptsData) {
            // Check if it's a new day
            $today = date('Y-m-d');
            if ($dailyAttemptsData['last_reset_date'] !== $today) {
                // Reset to 50
                $resetQuery = "UPDATE user_daily_limits SET daily_attempts = 50, last_reset_date = ? WHERE username = ?";
                $resetStmt = mysqli_prepare($con, $resetQuery);
                if ($resetStmt) {
                    mysqli_stmt_bind_param($resetStmt, "ss", $today, $current_user);
                    mysqli_stmt_execute($resetStmt);
                    mysqli_stmt_close($resetStmt);
                }
                $dailyAttemptsRemaining = 50;
            } else {
                $dailyAttemptsRemaining = (int) ($dailyAttemptsData['daily_attempts'] ?? 50);
            }
        }
    }
    mysqli_stmt_close($dailyAttemptsStmt);
}

// ============================================================================
// TOTAL RECORDS COUNT (estimated for performance with large tables)
// ============================================================================
$totalRecords = 0;
$isEstimate = false;

// Try to get fast approximate count from information_schema first
$schemaQuery = "SELECT table_rows FROM information_schema.tables
                WHERE table_schema = DATABASE()
                AND table_name = 'password_leaks'";
$schemaResult = mysqli_query($con, $schemaQuery);
if ($schemaResult instanceof mysqli_result) {
    $schemaData = mysqli_fetch_assoc($schemaResult);
    $totalRecords = (int) ($schemaData['table_rows'] ?? 0);
    mysqli_free_result($schemaResult);
    $isEstimate = true;
}

// If information_schema fails or returns 0, try exact count with timeout protection
if ($totalRecords == 0) {
    $countQuery = "SELECT COUNT(*) as total FROM password_leaks";
    $countStmt = mysqli_prepare($con, $countQuery);
    if ($countStmt) {
        mysqli_stmt_execute($countStmt);
        $countResult = mysqli_stmt_get_result($countStmt);
        if ($countResult) {
            $countData = mysqli_fetch_assoc($countResult);
            $totalRecords = (int) ($countData['total'] ?? 0);
        }
        mysqli_stmt_close($countStmt);
        $isEstimate = false;
    }
}

// Calculate percentage of 87 Trillion Passwords database base
$databaseBase = 87000000000000;
$leakPercentage = ($totalRecords / $databaseBase) * 100;

// ============================================================================
// SEARCH FUNCTIONALITY
// ============================================================================
$searchResults = [];
$searchPerformed = false;
$searchError = '';
$totalSearchResults = 0;

if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $searchPerformed = true;

    // Rate limiting check
    $rateIdentifier = $_SESSION['user_id'] ?? $_SERVER['REMOTE_ADDR'];
    if (!checkRateLimit($rateIdentifier)) {
        $searchError = 'Too many searches. Please wait a minute and try again.';
    } else {
        $searchInput = trim($_GET['search']);

        // Validate input
        if (strlen($searchInput) > 100) {
            $searchError = 'Search query too long. Maximum 100 characters.';
        } elseif (strlen($searchInput) < 4) {
            $searchError = 'Please enter at least 4 characters to search.';
        } elseif (!preg_match('/^[a-zA-Z0-9._%+\-@]+$/', $searchInput)) {
            $searchError = 'Invalid characters in search query. Use letters, numbers, and standard email characters only.';
        } else {
            // Determine if searching by email or username
            $isEmail = filter_var($searchInput, FILTER_VALIDATE_EMAIL);

            if ($isEmail) {
                // Email partial match (fuzzy search)
                $countQuery = "SELECT COUNT(*) as total FROM password_leaks WHERE email LIKE ?";
                $query = "SELECT id, email, username, password_hash, password_plaintext, source, leak_date, created_at
                         FROM password_leaks
                         WHERE email LIKE ?
                         ORDER BY leak_date DESC
                         LIMIT 5";
                $searchInput = "%$searchInput%";
                $paramType = "s";
            } else {
                // Username partial match
                $countQuery = "SELECT COUNT(*) as total FROM password_leaks WHERE username LIKE ?";
                $query = "SELECT id, email, username, password_hash, password_plaintext, source, leak_date, created_at
                         FROM password_leaks
                         WHERE username LIKE ?
                         ORDER BY leak_date DESC
                         LIMIT 5";
                $searchInput = "%$searchInput%";
                $paramType = "s";
            }

            // Get total count
            if ($stmt = mysqli_prepare($con, $countQuery)) {
                mysqli_stmt_bind_param($stmt, $paramType, $searchInput);
                $countExecute = mysqli_stmt_execute($stmt);
                if (!$countExecute) {
                    error_log("Count query execute failed: " . mysqli_stmt_error($stmt));
                }
                $totalResult = mysqli_stmt_get_result($stmt);
                if (!$totalResult) {
                    error_log("Count query get_result failed: " . mysqli_stmt_error($stmt));
                } else {
                    $totalRow = mysqli_fetch_assoc($totalResult);
                    $totalSearchResults = $totalRow['total'] ?? 0;
                    mysqli_free_result($totalResult);
                }
                mysqli_stmt_close($stmt);
            } else {
                error_log("Count query prepare failed: " . mysqli_error($con));
            }

            // Get results
            if ($stmt = mysqli_prepare($con, $query)) {
                mysqli_stmt_bind_param($stmt, $paramType, $searchInput);
                $executeSuccess = mysqli_stmt_execute($stmt);
                if (!$executeSuccess) {
                    error_log("Search execute failed: " . mysqli_stmt_error($stmt));
                    $searchError = 'Database error during search. Please try again.';
                } else {
                    $result = mysqli_stmt_get_result($stmt);
                    if (!$result) {
                        error_log("Search get_result failed: " . mysqli_stmt_error($stmt));
                        $searchError = 'Database error retrieving results.';
                    } else {
                        $rowCount = 0;
                        while ($row = mysqli_fetch_assoc($result)) {
                            $searchResults[] = $row;
                            $rowCount++;
                        }
                        mysqli_free_result($result);
                    }
                }
                mysqli_stmt_close($stmt);
            } else {
                error_log("Search prepare failed: " . mysqli_error($con));
                $searchError = 'Database error preparing search.';
            }
        }
    }
}

// ============================================================================
// MASKING FUNCTIONS
// ============================================================================
function maskEmail(string $email): string
{
    if (empty($email) || !strpos($email, '@')) {
        return '***@***.com';
    }
    list($user, $domain) = explode('@', $email, 2);
    $maskedUser = substr($user, 0, 1) . str_repeat('*', max(1, strlen($user) - 2)) . substr($user, -1);
    $domainParts = explode('.', $domain);
    $domainName = $domainParts[0];
    $maskedDomain = substr($domainName, 0, 1) . str_repeat('*', max(1, strlen($domainName) - 1));
    return $maskedUser . '@' . $maskedDomain . '.' . end($domainParts);
}

function maskUsername(string $username): string
{
    if (empty($username)) {
        return '***';
    }
    return substr($username, 0, 1) . str_repeat('*', max(1, strlen($username) - 2)) . substr($username, -1);
}

// ============================================================================
// CHECK IF USER PURCHASED SPECIFIC RECORD
// ============================================================================
function isRecordPurchased(mysqli $con, string $username, int $recordId): bool
{
    $query = "SELECT COUNT(*) as count FROM user_purchases WHERE user_id = ? AND record_id = ? AND record_type = 'password_leak'";
    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "si", $username, $recordId);
    mysqli_stmt_execute($stmt);
    $inner_result = mysqli_stmt_get_result($stmt);
    $count = 0;
    if ($inner_result) {
        $row = mysqli_fetch_assoc($inner_result);
        $count = (int) ($row['count'] ?? 0);
    }
    mysqli_stmt_close($stmt);

    return $count > 0;
}

$searchActive = isset($_GET['search']) && trim($_GET['search']) !== '';
?>

<div class="loading-overlay" id="pageLoader" style="display: none;">
    <div class="loading-spinner"></div>
    <div class="loading-text">Loading...</div>
</div>

<script>
// The loader is hidden by default. If it was showing, hide it on load.
document.addEventListener('DOMContentLoaded', function() {
    const loader = document.getElementById('pageLoader');
    if (loader) loader.style.display = 'none';
});
</script>

<!-- Content -->
<div class="password-db-container">

    <!-- Stats Bar -->
    <div class="compact-stats-bar">
        <div class="compact-stat">
            <span class="compact-stat-label">
                <i class="fa fa-shopping-bag me-1"></i>Purchases
            </span>
            <span class="compact-stat-value"><?= number_format((float) $passwordPurchases) ?></span>
        </div>

        <div class="compact-stat-divider"></div>

        <div class="compact-stat">
            <span class="compact-stat-label">
                <i class="fa fa-clock me-1"></i>Daily Attempts
            </span>
            <span class="compact-stat-value"><?= number_format((float) $dailyAttemptsRemaining) ?>/50</span>
        </div>

        <div class="compact-stat-divider"></div>

        <div class="compact-stat compact-stat-subscription">
            <span class="compact-stat-label">
                <i class="fa fa-user-shield me-1"></i><?= htmlspecialchars(ucfirst($subscriptionKey)) ?>
            </span>
            <span class="compact-stat-subvalue">
                <?= htmlspecialchars($percentLabel) ?>%
            </span>
            <progress class="compact-progress-accessible" value="<?= htmlspecialchars((string) $percentForBar) ?>"
                max="100" aria-label="<?= htmlspecialchars(ucfirst($subscriptionKey)) ?> subscription progress">
            </progress>
            <div class="compact-progress" aria-hidden="true" style="display:none;">
                <div class="compact-progress-fill" data-width="<?= htmlspecialchars((string) $percentForBar) ?>"></div>
            </div>
        </div>

        <div class="compact-stat-divider"></div>

        <div class="compact-stat">
            <span class="compact-stat-label">
                <i class="fa fa-database me-1"></i>118T DB Coverage
                <?= $isEstimate ? '<small title="Estimated count">(~)</small>' : '' ?>
            </span>
            <span class="compact-stat-value" title="<?= number_format((float) $totalRecords) ?> total records">
                <?= number_format((float) $leakPercentage, 8) ?>%
                <i class="fa fa-arrow-trend-up ms-1" style="color: #2ecc71; font-size: 0.8em;"
                    title="Continuously rising"></i>
            </span>
        </div>
    </div>

    <div class="search-meta"></div>

    <!-- Search Form -->
    <?= CsrfProtection::tokenMeta() ?>
    <form action="" method="GET" class="password-search-form">
        <div class="search-wrapper">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="search" required class="search-input"
                placeholder="Enter email or username to check..." autocomplete="off"
                value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>" maxlength="100">
            <button type="submit" class="search-btn">
                <i class="fas fa-shield-alt"></i> Check
            </button>
        </div>

        <div class="search-hint">
            <i class="fas fa-info-circle"></i>
            <span>Search for your email or username to check if your credentials have been compromised</span>
        </div>

        <?php if ($searchError): ?>
        <div class="alert alert-warning mt-2">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($searchError) ?>
        </div>
        <?php endif; ?>
    </form>

    <!-- Search Results -->
    <?php if ($searchPerformed && !$searchError): ?>
    <div class="results-section">
        <h3 class="results-header">
            <i class="fas fa-search"></i> Search Results
            <span class="results-count">(<?= number_format((float) $totalSearchResults) ?> found)</span>
        </h3>

        <?php if (count($searchResults) > 0): ?>
        <div class="table-responsive">
            <table class="table-bordered password-results-table">
                <thead>
                    <tr>
                        <th><i class="fas fa-id-badge"></i> ID</th>
                        <th><i class="fas fa-envelope"></i> Email</th>
                        <th><i class="fas fa-user"></i> Username</th>
                        <th><i class="fas fa-key"></i> Password</th>
                        <th><i class="fas fa-calendar"></i> Leak Date</th>
                        <th><i class="fas fa-shopping-cart"></i> Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchResults as $record):
                                $recordId = (int) $record['id'];
                                $isPurchased = isRecordPurchased($con, $current_user, $recordId);
                                // Prepare full data for view modal (base64 encoded)
                                $recordEncoded = json_encode([
                                    'id' => $recordId,
                                    'email' => $record['email'],
                                    'username' => $record['username'] ?? 'N/A',
                                    'password_hash' => $record['password_hash'],
                                    'password_plaintext' => $record['password_plaintext'] ?? null,
                                    'leak_date' => $record['leak_date'] ?? 'Unknown'
                                ]);
                                $recordData = $recordEncoded ? base64_encode($recordEncoded) : '';
                                ?>
                    <tr class="<?= $isPurchased ? 'purchased' : '' ?>"
                        data-record="<?= htmlspecialchars($recordData) ?>">
                        <td><?= htmlspecialchars((string) $record['id']) ?></td>
                        <td class="email-cell">
                            <?php if ($isPurchased): ?>
                            <span class="full-data"><?= htmlspecialchars((string) $record['email']) ?></span>
                            <span class="badge badge-success">Unlocked</span>
                            <?php else: ?>
                            <span class="masked-data"><?= maskEmail((string) $record['email']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="username-cell">
                            <?php if ($isPurchased): ?>
                            <span
                                class="full-data"><?= htmlspecialchars((string) ($record['username'] ?? 'N/A')) ?></span>
                            <?php else: ?>
                            <span class="masked-data"><?= maskUsername((string) ($record['username'] ?? '')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="password-cell">
                            <?php if ($isPurchased): ?>
                            <span class="full-data" style="color: #00ff88; font-weight: bold;">
                                <?= htmlspecialchars((string) ($record['password_plaintext'] ?? $record['password_hash'] ?? 'N/A')) ?>
                            </span>
                            <?php else: ?>
                            <span class="masked-data"><?= str_repeat('*', 15) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($record['leak_date']) ? htmlspecialchars((string) $record['leak_date']) : 'Unknown' ?>
                        </td>
                        <td class="action-cell">
                            <?php if ($isPurchased): ?>
                            <button type="button" class="btn btn-success btn-sm view-btn"
                                data-record-id="<?= (int) $record['id'] ?>">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-primary btn-sm purchase-btn"
                                data-id="<?= (int) $record['id'] ?>"
                                data-record-id="<?= (int) $record['id'] ?>"
                                data-record-type="password_leak"
                                data-points="10">
                                <i class="fas fa-unlock"></i> Purchase (10 pts)
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalSearchResults > 50): ?>
        <div class="alert alert-info mt-3">
            <i class="fas fa-info-circle"></i>
            Showing the first <?= number_format((float) max(0, (int) $totalSearchResults - 50)) ?> results only. There
            are more results, but
            they are only available to partners. You can refine your search or upgrade your subscription to see more.

        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="no-results">
            <i class="fas fa-shield-alt fa-3x text-success"></i>
            <h4>Good News!</h4>
            <p>No compromised credentials found for your search.</p>
            <p class="text-muted">Your credentials don't appear in our database of known data breaches.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Info Section -->
    <div class="info-section">
        <div class="info-card">
            <h4><i class="fas fa-info-circle"></i> How it works</h4>
            <ul>
                <li>Enter your email or username to search our database</li>
                <li>If found, we'll show masked results with the breach source</li>
                <li>Purchase individual records to view full details</li>
                <li>Data comes from publicly known data breaches</li>
            </ul>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-shield-alt"></i> Security Notes</h4>
            <ul>
                <li>We never store plaintext passwords</li>
                <li>All searches are logged for security</li>
                <li>Rate limited to prevent abuse</li>
                <li>Only hashes are stored in our database</li>
            </ul>
        </div>
    </div>

</div>

<!-- View Record Modal -->
<div id="viewRecordModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Full Record Details</h3>
            <span class="modal-close">&times;</span>
        </div>
        <div class="modal-body">
            <div class="record-detail-row">
                <span class="detail-label"><i class="fas fa-id-badge"></i> Record ID:</span>
                <span class="detail-value" id="modalRecordId"></span>
            </div>
            <div class="record-detail-row">
                <span class="detail-label"><i class="fas fa-envelope"></i> Email:</span>
                <span class="detail-value" id="modalEmail"></span>
            </div>
            <div class="record-detail-row">
                <span class="detail-label"><i class="fas fa-user"></i> Username:</span>
                <span class="detail-value" id="modalUsername"></span>
            </div>
            <div class="record-detail-row password-row">
                <span class="detail-label"><i class="fas fa-key"></i> Password:</span>
                <span class="detail-value hash-value" id="modalPasswordPlaintext"></span>
                <button type="button" class="btn-copy" onclick="copyToClipboard('modalPasswordPlaintext')">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
            <div class="record-detail-row">
                <span class="detail-label"><i class="fas fa-lock"></i> Hash:</span>
                <span class="detail-value hash-value" id="modalPasswordHash"
                    style="font-size: 0.75em; opacity: 0.7;"></span>
            </div>
            <div class="record-detail-row">
                <span class="detail-label"><i class="fas fa-calendar"></i> Leak Date:</span>
                <span class="detail-value" id="modalLeakDate"></span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary modal-close-btn">Close</button>
        </div>
    </div>
</div>

<!-- Purchase Confirmation Modal -->
<div id="purchaseConfirmModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-shopping-cart"></i> Confirm Purchase</h3>
            <span class="modal-close confirm-modal-close">&times;</span>
        </div>
        <div class="modal-body">
            <p class="confirm-message">Purchase this record for 10 points?</p>
            <p class="text-muted">You will be charged 10 points to unlock the full breach record and view the data immediately.</p>
        </div>
        <div class="modal-footer purchase-modal-footer">
            <button type="button" class="btn btn-secondary confirm-cancel-btn">Cancel</button>
            <button type="button" class="btn btn-primary confirm-purchase-btn"><i class="fas fa-unlock"></i> Confirm Purchase</button>
        </div>
    </div>
</div>

<style>
/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(5px);
}

.modal-content {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    margin: 5% auto;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid rgba(0, 255, 136, 0.3);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    animation: modalSlideIn 0.3s ease;
    display: flex;
    flex-direction: column;
}

@keyframes modalSlideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }

    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid rgba(0, 255, 136, 0.2);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: #00ff88;
    font-size: 1.3em;
}

.modal-close {
    color: #888;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    transition: color 0.2s;
}

.modal-close:hover {
    color: #ff4444;
}

.modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.record-detail-row {
    display: flex;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    flex-wrap: wrap;
}

.record-detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    min-width: 120px;
    max-width: 140px;
    color: #888;
    font-size: 0.85em;
    flex-shrink: 0;
}

.detail-label i {
    margin-right: 6px;
    color: #00ff88;
}

.detail-value {
    flex: 1;
    min-width: 0;
    color: #fff;
    font-family: 'Courier New', monospace;
    word-break: break-word;
    overflow-wrap: break-word;
    font-size: 0.9em;
}

.hash-value {
    background: rgba(0, 0, 0, 0.3);
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 0.8em;
    border: 1px solid rgba(0, 255, 136, 0.2);
    width: 100%;
    box-sizing: border-box;
}

.password-row {
    flex-wrap: wrap;
    gap: 10px;
}

.btn-copy {
    background: rgba(0, 255, 136, 0.1);
    border: 1px solid rgba(0, 255, 136, 0.3);
    color: #00ff88;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85em;
    transition: all 0.2s;
}

.btn-copy:hover {
    background: rgba(0, 255, 136, 0.2);
}

    .confirm-message {
        color: #fff;
        font-size: 1rem;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background: rgba(0, 255, 136, 0.08);
        border: 1px solid rgba(0, 255, 136, 0.2);
        border-radius: 12px;
    }

    .purchase-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        padding-top: 0;
    }

    .confirm-purchase-btn {
        background: linear-gradient(135deg, #00ff88 0%, #00cc6a 100%);
        border: none;
        color: #000;
        font-weight: bold;
        min-width: 170px;
    }

    .confirm-purchase-btn:hover {
        background: linear-gradient(135deg, #00ff99 0%, #00dd77 100%);
        transform: translateY(-1px);
    }

    .confirm-cancel-btn {
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: #fff;
    }

    .confirm-success {
        color: #00ff88;
        border-color: rgba(0, 255, 136, 0.4);
        background: rgba(0, 255, 136, 0.08);
    }

    .confirm-error {
        color: #ff7777;
        border-color: rgba(255, 119, 119, 0.4);
        background: rgba(255, 119, 119, 0.08);
    }

.modal-close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #fff;
    padding: 8px 20px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}

/* View button style */
.view-btn {
    background: linear-gradient(135deg, #00ff88 0%, #00cc6a 100%);
    border: none;
    color: #000;
    font-weight: bold;
}

.view-btn:hover {
    background: linear-gradient(135deg, #00ff99 0%, #00dd77 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 255, 136, 0.3);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Progress bar animation
    const progressBars = document.querySelectorAll('.compact-progress-fill');
    progressBars.forEach(bar => {
        const width = bar.getAttribute('data-width');
        if (width) {
            setTimeout(() => {
                bar.style.width = width + '%';
            }, 100);
        }
    });

    // Purchase confirmation modal elements
    const purchaseConfirmModal = document.getElementById('purchaseConfirmModal');
    const confirmMessageEl = purchaseConfirmModal?.querySelector('.confirm-message');
    const confirmPurchaseBtn = purchaseConfirmModal?.querySelector('.confirm-purchase-btn');
    const cancelPurchaseBtn = purchaseConfirmModal?.querySelector('.confirm-cancel-btn');
    const purchaseModalCloseButtons = purchaseConfirmModal?.querySelectorAll('.confirm-modal-close');

    let pendingPurchaseButton = null;
    let pendingPurchaseId = null;
    let pendingPurchaseType = null;
    let pendingCsrfToken = null;
    let purchaseInProgress = false;

    const purchaseButtons = document.querySelectorAll('.purchase-btn');
    purchaseButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const recordId = this.getAttribute('data-record-id');
            const recordType = this.getAttribute('data-record-type') || 'password_leak';
            const csrfToken = document.querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') || document.querySelector(
                    'input[name="csrf_token"]')?.value;

            if (!recordId) {
                alert('Error: Invalid record ID');
                return;
            }

            pendingPurchaseButton = this;
            pendingPurchaseId = recordId;
            pendingPurchaseType = recordType;
            pendingCsrfToken = csrfToken;

            if (confirmMessageEl) {
                confirmMessageEl.textContent = 'Purchase this record for 10 points?';
            }

            if (purchaseConfirmModal) {
                purchaseConfirmModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        });
    });

    function closePurchaseConfirmModal() {
        if (!purchaseConfirmModal) {
            return;
        }

        purchaseConfirmModal.style.display = 'none';
        document.body.style.overflow = 'auto';
        purchaseInProgress = false;
        pendingPurchaseId = null;
        pendingPurchaseType = null;
        pendingCsrfToken = null;
        pendingPurchaseButton = null;
    }

    if (cancelPurchaseBtn) {
        cancelPurchaseBtn.addEventListener('click', closePurchaseConfirmModal);
    }

    if (purchaseModalCloseButtons) {
        purchaseModalCloseButtons.forEach(btn => {
            btn.addEventListener('click', closePurchaseConfirmModal);
        });
    }

    if (confirmPurchaseBtn) {
        confirmPurchaseBtn.addEventListener('click', async function() {
            if (!pendingPurchaseId || purchaseInProgress || !pendingPurchaseButton) {
                return;
            }

            purchaseInProgress = true;
            const originalHtml = pendingPurchaseButton.innerHTML;
            pendingPurchaseButton.disabled = true;
            pendingPurchaseButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

            const headers = {
                'Content-Type': 'application/json',
            };

            if (pendingCsrfToken) {
                headers['X-CSRF-Token'] = pendingCsrfToken;
            }

            try {
                const response = await fetch('purchase_record', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers,
                    body: JSON.stringify({
                        record_id: parseInt(pendingPurchaseId),
                        action: 'purchase',
                        type: pendingPurchaseType,
                        csrf_token: pendingCsrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    if (confirmMessageEl) {
                        confirmMessageEl.textContent = result.message || 'Purchase completed successfully.';
                        confirmMessageEl.classList.add('confirm-success');
                    }

                    if (pendingPurchaseButton) {
                        pendingPurchaseButton.disabled = true;
                        pendingPurchaseButton.innerHTML = '<i class="fas fa-check"></i> Purchased';
                    }

                    setTimeout(function() {
                        closePurchaseConfirmModal();
                    }, 1600);
                } else {
                    if (confirmMessageEl) {
                        confirmMessageEl.textContent = result.message || 'Purchase failed. Please try again.';
                        confirmMessageEl.classList.add('confirm-error');
                    }
                    pendingPurchaseButton.disabled = false;
                    pendingPurchaseButton.innerHTML = originalHtml;
                }
            } catch (error) {
                console.error('Purchase error:', error);
                if (confirmMessageEl) {
                    confirmMessageEl.textContent = 'Network error. Please check your connection and try again.';
                    confirmMessageEl.classList.add('confirm-error');
                }
                pendingPurchaseButton.disabled = false;
                pendingPurchaseButton.innerHTML = originalHtml;
                setTimeout(closePurchaseConfirmModal, 1600);
            }
        });
    }

    // Handle view buttons for purchased records
    const viewButtons = document.querySelectorAll('.view-btn');
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            const row = this.closest('tr');
            const recordData = row.getAttribute('data-record');

            if (recordData) {
                try {
                    // Decode base64 and parse JSON
                    const decoded = atob(recordData);
                    const record = JSON.parse(decoded);

                    // Populate modal
                    document.getElementById('modalRecordId').textContent = record.id;
                    document.getElementById('modalEmail').textContent = record.email;
                    document.getElementById('modalUsername').textContent = record.username;
                    document.getElementById('modalPasswordPlaintext').textContent = record
                        .password_plaintext || 'N/A';
                    document.getElementById('modalPasswordHash').textContent = record
                        .password_hash;
                    document.getElementById('modalLeakDate').textContent = record.leak_date;

                    // Show modal
                    const modal = document.getElementById('viewRecordModal');
                    modal.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                } catch (e) {
                    console.error('Error parsing record data:', e);
                    alert('Error displaying record details.');
                }
            }
        });
    });

    // Modal close handlers
    const modal = document.getElementById('viewRecordModal');
    const closeBtn = modal.querySelector('.modal-close');
    const closeBtnFooter = modal.querySelector('.modal-close-btn');

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    closeBtn.addEventListener('click', closeModal);
    closeBtnFooter.addEventListener('click', closeModal);

    // Close modals when clicking outside
    window.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
        if (purchaseConfirmModal && e.target === purchaseConfirmModal) {
            closePurchaseConfirmModal();
        }
    });

    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (modal.style.display === 'block') {
                closeModal();
            }
            if (purchaseConfirmModal && purchaseConfirmModal.style.display === 'block') {
                closePurchaseConfirmModal();
            }
        }
    });
});

// Copy to clipboard function
function copyToClipboard(elementId) {
    const text = document.getElementById(elementId).textContent;
    navigator.clipboard.writeText(text).then(function() {
        // Show temporary success message
        const btn = event.target.closest('.btn-copy');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.style.background = 'rgba(0, 255, 136, 0.3)';
        setTimeout(function() {
            btn.innerHTML = original;
            btn.style.background = '';
        }, 2000);
    }).catch(function(err) {
        console.error('Failed to copy:', err);
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Copied to clipboard!');
    });
}
</script>

<?php
// End page rendering
PageController::end('/', ['js/search_table.js']);
?>