<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/table_helpers.php';

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Security\CsrfProtection;

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
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdnjs.cloudflare.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
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

$csrf_token = CsrfProtection::generateToken();
$resources = PageController::setup('Personnel Database', '/', ['css/search_table.css']);

$con = $resources['db'] ?? Database::getConnection();
if (!$con) {
    error_log("Database connection failed in table.php");
    die("A database error occurred. Please try again later.");
}

// If user is not authenticated, redirect to login (or show unauthorized)
$is_authenticated = $resources['is_authenticated'] ?? false;
if (!$is_authenticated) {
    header('Location: login');
    exit;
}

$current_user = $resources['user']['username'] ?? null;
$user_subscription_raw = $resources['user']['subscription'] ?? ($_SESSION['subscription'] ?? 'free');
$user_subscription = strtolower(trim((string) $user_subscription_raw));

// Send loader early so the user sees feedback while the database query is running
?>
<div class="loading-overlay is-visible" id="pageLoader" aria-live="polite" aria-busy="true">
    <div class="loading-spinner"></div>
    <div class="loading-text">Loading Data...</div>
</div>
<input type="hidden" id="csrfTokenInput" name="csrf_token"
    value="<?= htmlspecialchars($csrf_token, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" hidden>
<?php
if (ob_get_level() > 0) {
    ob_flush();
}
flush();

$searchTerm = null;
if (isset($_GET['search']) && is_string($_GET['search'])) {
    $searchTerm = table_validate_search_term($_GET['search']);
}
$searchActive = $searchTerm !== null;
$searchInputValue = $searchTerm ?? (isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '');

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

$user_purchases = 0;
if (is_string($current_user) && $current_user !== '') {
    $purchases_query = "SELECT COUNT(*) AS total FROM user_purchases WHERE user_id = ? AND (record_type IS NULL OR record_type != 'password_leak')";
    $purchases_stmt = mysqli_prepare($con, $purchases_query);
    if ($purchases_stmt) {
        mysqli_stmt_bind_param($purchases_stmt, 's', $current_user);
        if (mysqli_stmt_execute($purchases_stmt)) {
            $purchases_result = mysqli_stmt_get_result($purchases_stmt);
            $purchases_data = $purchases_result ? mysqli_fetch_assoc($purchases_result) : null;
            $user_purchases = (int) ($purchases_data['total'] ?? 0);
        }
        mysqli_stmt_close($purchases_stmt);
    }
}

$searchPageData = null;
$page = 1;
$total_pages = 0;
$total_records = 0;
if ($searchTerm !== null) {
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $searchPageData = table_fetch_search_page($con, $searchTerm, $page);
    if ($searchPageData !== null) {
        $page = $searchPageData['page'];
        $total_pages = $searchPageData['total_pages'];
        $total_records = $searchPageData['total_records'];
    }
}
?>

<!-- Content -->

<div class="search-container">

    <div class="compact-stats-bar">
        <div class="compact-stat">
            <span class="compact-stat-label">
                <i class="fa fa-shopping-bag me-1"></i>Purchases
            </span>
            <span class="compact-stat-value"><?= number_format($user_purchases) ?></span>
        </div>

        <div class="compact-stat-divider"></div>

        <div class="compact-stat compact-stat-subscription">
            <span class="compact-stat-label">
                <i class="fa fa-user-shield me-1"></i><?= table_h(ucfirst($subscriptionKey)) ?>
            </span>
            <span class="compact-stat-subvalue">
                <?= table_h($percentLabel) ?>%
            </span>
            <div class="compact-progress" role="progressbar" aria-valuenow="<?= table_h((string) $percentForBar) ?>"
                aria-valuemin="0" aria-valuemax="100">
                <div class="compact-progress-fill" data-width="<?= table_h((string) $percentForBar) ?>">
                </div>
            </div>
        </div>

        <div class="compact-stat-divider"></div>

        <div class="compact-stat">
            <span
                class="compact-pill<?= $searchActive ? ' is-active' : '' ?>"><?= $searchActive ? 'Active' : 'Server: |83|' ?></span>
        </div>

        <div class="compact-stat-divider"></div>

        <div class="compact-stat">
            <div class="column-width-controls">
                <button type="button" class="column-width-btn" id="decreaseColumnWidth"
                    title="Decrease column width (-)">
                    <i class="fas fa-minus"></i>
                </button>
                <span class="column-width-label" id="columnWidthLabel">100px</span>
                <button type="button" class="column-width-btn" id="increaseColumnWidth"
                    title="Increase column width (+)">
                    <i class="fas fa-plus"></i>
                </button>
                <button type="button" class="column-width-btn column-width-reset" id="resetColumnWidth"
                    title="Reset to default">
                    <i class="fas fa-undo"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="search-meta"></div>

    <form action="" method="GET" autocomplete="off">
        <div class="search-wrapper">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="search" required class="search-input" placeholder="Search (ID, Name)..."
                autocomplete="off" value="<?= table_h($searchInputValue) ?>" maxlength="100"
                pattern="^[A-Za-z0-9+\-@_.\s]+$" inputmode="search">
            <button type="submit" class="search-btn">
                <i class="fas fa-search"></i>
            </button>
        </div>

        <div>
            <div class="search-hint">
                <i class=""></i>
                <span>Use keywords for faster results</span>
            </div>
        </div>
    </form>
</div>

<div class="table-container">
    <table class="table-bordered">
        <thead>
            <tr>
                <th><i class="fas fa-shopping-cart"></i> Buy</th>
                <th><i class="fas fa-flag"></i> Country</th>
                <th><i class="fas fa-image"></i> Photo</th>
                <th><i class="fas fa-user"></i> Username</th>
                <th><i class="fas fa-id-card"></i> Name</th>
                <th><i class="fas fa-envelope"></i> Email</th>
                <th><i class="fas fa-phone"></i> Phone</th>
                <th><i class="fas fa-home"></i> Home Address</th>
                <th><i class="fas fa-certificate"></i> Birth Certificate</th>
                <th><i class="fas fa-flag"></i> Nationality</th>
                <th><i class="fas fa-users"></i> Relatives</th>
                <th><i class="fas fa-tint"></i> Blood Type</th>
                <th><i class="fas fa-share-alt"></i> Social Media</th>
                <th><i class="fas fa-credit-card"></i> Bank Information</th>
                <th><i class="fas fa-city"></i> City</th>
                <th><i class="fas fa-map-marker-alt"></i> District</th>
                <th><i class="fas fa-road"></i> Street</th>
                <th><i class="fas fa-building"></i> Building No.</th>
                <th><i class="fas fa-door-open"></i> Apartment No.</th>
                <th><i class="fas fa-mail-bulk"></i> Postal Code</th>
                <th><i class="fas fa-file-alt"></i> Birth Cert. No.</th>
                <th><i class="fas fa-birthday-cake"></i> Birth Date</th>
                <th><i class="fas fa-heart"></i> Marital Status</th>
                <th><i class="fas fa-child"></i> Children Count</th>
                <th><i class="fas fa-id-card-alt"></i> ID Card File</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($searchTerm !== null && $searchPageData !== null) {
                $rows = $searchPageData['rows'];
                if ($rows === []) {
                    echo '<tr><td colspan="24" class="text-center">No Records Found</td></tr>';
                } else {
                    foreach ($rows as $items) {
                        table_render_result_row($items);
                    }
                }
            } elseif ($searchTerm !== null && $searchPageData === null) {
                echo '<tr><td colspan="24" class="text-center">Unable to load results. Please try again.</td></tr>';
            } elseif (isset($_GET['search']) && !$searchActive) {
                echo '<tr><td colspan="24" class="text-center text-warning">Invalid search query. Use letters, numbers, spaces, and + - @ _ . only.</td></tr>';
            }
            ?>
        </tbody>
    </table>
</div>

<?php if ($searchTerm !== null && $total_pages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <form method="GET" class="pagination-form">
        <input type="hidden" name="search" value="<?= table_h($searchTerm) ?>">
        <input type="hidden" name="page" value="1">
        <button type="submit" class="btn btn-nav" aria-label="First page">
            <i class="fas fa-angle-double-left"></i>
        </button>
    </form>
    <form method="GET" class="pagination-form">
        <input type="hidden" name="search" value="<?= table_h($searchTerm) ?>">
        <input type="hidden" name="page" value="<?= (int) ($page - 1) ?>">
        <button type="submit" class="btn btn-nav" aria-label="Previous page">
            <i class="fas fa-angle-left"></i>
        </button>
    </form>
    <?php endif; ?>

    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
    <form method="GET" class="pagination-form">
        <input type="hidden" name="search" value="<?= table_h($searchTerm) ?>">
        <input type="hidden" name="page" value="<?= (int) $i ?>">
        <button type="submit" class="btn <?= $page === $i ? 'btn-primary active' : '' ?>"
            aria-label="Page <?= (int) $i ?>">
            <?= table_h((string) $i) ?>
        </button>
    </form>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
    <form method="GET" class="pagination-form">
        <input type="hidden" name="search" value="<?= table_h($searchTerm) ?>">
        <input type="hidden" name="page" value="<?= (int) ($page + 1) ?>">
        <button type="submit" class="btn btn-nav" aria-label="Next page">
            <i class="fas fa-angle-right"></i>
        </button>
    </form>
    <form method="GET" class="pagination-form">
        <input type="hidden" name="search" value="<?= table_h($searchTerm) ?>">
        <input type="hidden" name="page" value="<?= (int) $total_pages ?>">
        <button type="submit" class="btn btn-nav" aria-label="Last page">
            <i class="fas fa-angle-double-right"></i>
        </button>
    </form>
    <?php endif; ?>
    <div class="pagination-info">
        Page <?= table_h((string) $page) ?> of <?= table_h((string) $total_pages) ?>
        (<?= table_h((string) $total_records) ?> results)
    </div>
</div>
<?php endif; ?>
</div>

<div class="modal fade" id="purchaseConfirmModal" tabindex="-1" aria-labelledby="purchaseConfirmTitle"
    data-bs-backdrop="false">
    <div class="modal-dialog modal-dialog-centered purchase-confirm-dialog">
        <div class="modal-content purchase-confirm-content">
            <div class="modal-header purchase-confirm-header">
                <h5 class="modal-title" id="purchaseConfirmTitle">Confirm Purchase</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body purchase-confirm-body">
                <p class="purchase-confirm-text">Do you want to purchase this record?</p>

                <p class="purchase-confirm-points" id="purchaseConfirmPoints" hidden></p>
            </div>
            <div class="modal-footer purchase-confirm-footer">
                <button type="button" class="btn purchase-confirm-btn-no" data-bs-dismiss="modal">No</button>
                <button type="button" class="btn purchase-confirm-btn-yes" id="purchaseConfirmYes">Yes</button>
            </div>
        </div>
    </div>
</div>

<?php

// End page rendering
PageController::end('/', ['js/search_table.js']);
?>