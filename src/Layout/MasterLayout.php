<?php
declare(strict_types=1);

namespace ROOTS\Layout;
use Closure;

use ROOTS\Layout\LayoutHelpers;
use ROOTS\Layout\StylesRenderer;
use ROOTS\Layout\NavProfile;
use ROOTS\Layout\NavNotification;
use ROOTS\Layout\NavigationRenderer;
use ROOTS\Layout\NavigationDataProvider;
use ROOTS\Layout\Linkbar;
use ROOTS\Layout\LayoutConfig;
interface LayoutUserDataProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getUserData(?string $username): array;
    public function getDbConnection(): ?\mysqli;
    public function closeDbConnection(): void;
}

interface LayoutNavigationProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getNavigationMenuData(): array;
}

final class DefaultLayoutNavigationProvider implements LayoutNavigationProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getNavigationMenuData(): array
    {
        return NavigationDataProvider::getNavigationMenuData();
    }
}

final class DefaultLayoutUserDataProvider implements LayoutUserDataProviderInterface
{
    private bool $envLoaded = false;
    private ?\mysqli $db = null;

    private function loadEnv(): void
    {
        if ($this->envLoaded) {
            return;
        }

        $path = __DIR__ . "/../../.env";
        if (!file_exists($path) || !is_readable($path)) {
            $this->envLoaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $lines = [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === "" || $line[0] === "#" || strpos($line, "=") === false) {
                continue;
            }

            [$key, $value] = explode("=", $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv("$key=$value");
        }

        $this->envLoaded = true;
    }

    public function getDbConnection(): ?\mysqli
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $this->loadEnv();
        $config = [
            'host' => $_ENV["DB_HOST"] ?? "localhost",
            'username' => $_ENV["DB_USER"] ?? "root",
            'password' => $_ENV["SECRET"] ?? "",
            'database' => $_ENV["DB_NAME"] ?? "users_app",
        ];

        try {
            $mysqli = new \mysqli($config['host'], $config['username'], $config['password'], $config['database']);
            if ($mysqli->connect_error) {
                error_log("DB Connection failed: " . $mysqli->connect_error);
                return null;
            }
            $mysqli->set_charset("utf8mb4");
            $this->db = $mysqli;
        } catch (\Exception $e) {
            error_log("DB Connection exception: " . $e->getMessage());
            return null;
        }

        return $this->db;
    }

    public function closeDbConnection(): void
    {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserData(?string $username): array
    {
        $db = $this->getDbConnection();
        $default = [
            "id" => 0,
            "username" => $username ?: "Guest",
            "display_name" => $username ?: "User",
            "points" => 0,
            "avatar_url" => "",
            "subscription" => LayoutConfig::DEFAULT_SUBSCRIPTION,
            "expiry_date" => null,
        ];

        if (!$db || !$username) {
            return $default;
        }

        try {
            $stmt = $db->prepare("SELECT id, points, avatar_url, display_name, subscription, expiry_date FROM login WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    $rowData = [];
                    foreach ($row as $key => $value) {
                        $rowData[$key] = $value ?? ($default[$key] ?? "");
                    }

                    if (empty($rowData["display_name"])) {
                        $rowData["display_name"] = $username;
                    }

                    return array_merge($default, $rowData);
                }
                $stmt->close();
            }
        } catch (\Exception $e) {
            error_log("Error fetching user data: " . $e->getMessage());
        }

        return $default;
    }
}

/**
 * MasterLayout - Main layout controller for the application
 * Merges functionality from legacy and new versions into a modular structure
 */
class MasterLayout
{
    // Layout Constants - now using LayoutConfig

    private LayoutUserDataProviderInterface $userDataProvider;
    private LayoutNavigationProviderInterface $navigationProvider;
    private Closure $banChecker;

    public function __construct(
        ?LayoutUserDataProviderInterface $userDataProvider = null,
        ?LayoutNavigationProviderInterface $navigationProvider = null,
        ?callable $banChecker = null
    ) {
        $this->userDataProvider = $userDataProvider ?? new DefaultLayoutUserDataProvider();
        $this->navigationProvider = $navigationProvider ?? new DefaultLayoutNavigationProvider();
        $this->banChecker = $banChecker !== null
            ? Closure::fromCallable($banChecker)
            : static function (int $userId): bool {
                return class_exists('\ROOTS\Auth\BanSystem') && \ROOTS\Auth\BanSystem::isBanned($userId);
            };
    }

    public static function createDefault(): self
    {
        return new self();
    }

    public function getDbConnection(): ?\mysqli
    {
        return $this->userDataProvider->getDbConnection();
    }

    public function closeDbConnection(): void
    {
        $this->userDataProvider->closeDbConnection();
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserData(?string $username): array
    {
        return $this->userDataProvider->getUserData($username);
    }

    public function isAdmin(): bool
    {
        return isset($_SESSION["subscription"]) && $_SESSION["subscription"] === "admin";
    }

    /**
     * Render the main navbar
     */
    public function renderNavbar(string $base_path = ""): void
    {
        $base_path = LayoutHelpers::normalizeBasePath($base_path);
        $username = $_SESSION["username"] ?? null;
        $user_data = $this->getUserData($username);

        // Check for ban
        if ($user_data["id"] > 0 && ($this->banChecker)((int) $user_data["id"])) {
            $this->renderBannedNavbar($base_path);
            return;
        }
        $is_admin = $this->isAdmin();
        $default_avatar = $base_path . LayoutConfig::DEFAULT_AVATAR;
        $user_avatar = LayoutHelpers::processAvatarUrl($user_data["avatar_url"], $base_path, $default_avatar);
        $subscription = $user_data["subscription"];

        $paidPlans = ["Basic", "Pro", "Premium", "VIP", "Elite", "admin"];
        $hasSubscription = in_array($subscription, $paidPlans, true);
        $menuData = $this->navigationProvider->getNavigationMenuData();

        ?>
<?php $this->renderNavbarStyles(); ?>
<nav class="bc-nav" data-component="global-nav" data-is-admin="<?= $is_admin ? "1" : "0" ?>"
    aria-label="Main navigation">
    <?php NavNotification::renderModal(); ?>

    <div class="bc-nav__inner">
        <!-- Brand -->
        <div class="bc-nav__brand">
            <button type="button" class="bc-nav__toggle" aria-expanded="false" aria-label="Toggle navigation">
                <span class="bc-nav__hamburger"></span>
            </button>
            <a href="<?= LayoutHelpers::sanitize($base_path) ?>index" class="bc-nav__logo" aria-label="Go to home page">
                <i class="fa fa-terminal text-success fa-lg"></i>
                <span class="bc-nav__site-title"
                    style="font-family: <?= LayoutConfig::TERMINAL_FONT ?>; font-weight: 700; font-size: 1.6rem; letter-spacing: 2px; color: var(--bc-accent); text-transform: uppercase; text-shadow: 0 0 10px rgba(46, 204, 113, 0.3);">ROOTS</span>
            </a>
        </div>

        <div class="bc-nav__overlay"></div>

        <!-- Main Menu -->
        <?php NavigationRenderer::renderMainNavigationMenu($base_path, $menuData, $hasSubscription, LayoutConfig::LOCK_MESSAGE, LayoutConfig::LOCK_MESSAGE); ?>

        <div class="bc-nav__actions" style="display: flex; gap: 8px; align-items: center;">
            <!-- Message Button -->
            <a href="<?= LayoutHelpers::sanitize($base_path) ?>massage/index" class="bc-action-btn"
                aria-label="Messages">
                <i class="fas fa-comment-dots"></i>
            </a>

            <!-- Notifications -->
            <?php NavNotification::renderButton(); ?>

            <!-- Wallet Button -->
            <a href="<?= LayoutHelpers::sanitize($base_path) ?>walet" class="bc-action-btn" aria-label="My Wallet">
                <i class="fab fa-bitcoin"></i>
            </a>

            <!-- Admin Menu -->
            <?php if ($is_admin)
                        NavProfile::renderAdminMenu($base_path); ?>

            <!-- User Profile Dropdown -->
            <?php NavProfile::renderUserProfileDropdown($base_path, $user_avatar, $user_data["display_name"], $subscription); ?>
        </div>
    </div>
</nav>
<script src="<?= LayoutHelpers::sanitize(LayoutHelpers::assetUrl($base_path, "js/global-nav.js")) ?>"></script>
<?php
    }

    /**
     * Render banned navbar
     */
    public function renderBannedNavbar(string $base_path): void
    {
        $safeBasePath = LayoutHelpers::sanitize($base_path);
        ?>
<?php $this->renderBannedNavbarStyles(); ?>
<nav class="bc-nav bc-nav--restricted" aria-label="Restricted mode navigation">
    <div class="bc-nav__inner bc-nav__inner--restricted">
        <div class="bc-nav__brand">
            <span class="bc-nav__restricted-title">
                <i class="fas fa-ban me-2"></i>RESTRICTED_MODE
            </span>
        </div>
        <div class="bc-nav__actions">
            <a href="<?= $safeBasePath ?>blocked" class="bc-btn-terminal"
                style="font-size: 0.65rem; padding: 4px 10px; margin-right: 8px;">
                <i class="fas fa-shield-alt"></i> RETURN_TO_BLOCK_PAGE
            </a>
            <a href="<?= $safeBasePath ?>logout" class="bc-btn-terminal" style="font-size: 0.65rem; padding: 4px 10px;">
                <i class="fas fa-sign-out-alt"></i> LOGOUT
            </a>
        </div>
    </div>
</nav>
<?php
    }

    /**
     * Render page start
     */
    /**
     * Render page start
     * @param array<int, string> $extraCSS
     */
    public function renderPageStart(
        string $pageTitle = "Post MySite",
        string $base_path = "",
        array $extraCSS = [],
        bool $showNavbar = true
    ): void {
        $base_path = LayoutHelpers::normalizeBasePath($base_path);

        // Get user data for Linkbar
        $username = $_SESSION["username"] ?? null;
        $user_data = $this->getUserData($username);
        $is_admin = $this->isAdmin();

        // Load security helpers
        if (file_exists(__DIR__ . "/../../includes/csrf.php")) {
            require_once __DIR__ . "/../../includes/csrf.php";
        }

        // Security Headers
        LayoutHelpers::safeHeader("X-Frame-Options: SAMEORIGIN");
        LayoutHelpers::safeHeader("X-XSS-Protection: 1; mode=block");
        LayoutHelpers::safeHeader("X-Content-Type-Options: nosniff");
        LayoutHelpers::safeHeader("Referrer-Policy: strict-origin-when-cross-origin");
        LayoutHelpers::safeHeader("Permissions-Policy: geolocation=(), microphone=(), camera=()");

        ?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= LayoutHelpers::sanitize($pageTitle) ?> - File Exchange System</title>

    <!-- CSRF Token for AJAX -->
    <?php if (function_exists("csrfTokenMeta"))
                echo csrfTokenMeta(); ?>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= LayoutHelpers::sanitize($base_path) ?>favicon.ico">

    <!-- External CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Roboto+Mono:wght@400;700&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">

    <!-- Inline Component Styles -->
    <?php StylesRenderer::renderAll(); ?>
    <?php $this->renderPageContainerStyles(); ?>

    <?php foreach ($extraCSS as $css): ?>
    <link rel="stylesheet" href="<?= LayoutHelpers::sanitize(LayoutHelpers::assetUrl($base_path, (string) $css)) ?>">
    <?php endforeach; ?>

    <!-- Preload Critical Resources -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">

    <!-- Core JS Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.15.2/dist/sweetalert2.all.min.js"></script>

    <?php NavNotification::renderHelperFunctions(); ?>

    <!-- External JavaScript for MasterLayout Functions -->
    <script src="<?= LayoutHelpers::sanitize($base_path) ?>js/masterlayout-functions.js"></script>
</head>

<body class="theme-legacy-green">
    <!-- First Bar: Main Navigation Bar -->
    <?php if ($showNavbar)
                $this->renderNavbar($base_path); ?>

    <!-- Second Bar: System Top Bar (Completely Isolated) -->
    <?php Linkbar::render(null, $user_data, $is_admin, $base_path); ?>

    <div class="content">
        <div class="main-container">
            <?php
    }

    /**
     * Render page end
     * @param array<int, string> $extraJS
     */
    public function renderPageEnd(string $base_path = "", array $extraJS = []): void
    {
        $base_path = LayoutHelpers::normalizeBasePath($base_path);
        ?>
        </div>
    </div>

    <?php NavNotification::renderArea(); ?>

    <!-- Loader -->
    <div id="globalLoader" class="global-loader" style="display: none;">
        <div class="loader-backdrop"></div>
        <div class="loader-content">
            <div class="spinner"></div>
            <div class="loader-text">Loading...</div>
        </div>
    </div>

    <?php foreach ($extraJS as $js): ?>
    <script src="<?= LayoutHelpers::sanitize(LayoutHelpers::assetUrl($base_path, (string) $js)) ?>"></script>
    <?php endforeach; ?>

    <script <?php echo \ROOTS\Middleware\SecurityHeadersMiddleware::getNonceAttribute(); ?>>
    // Global Helpers
    if (typeof window.showLoader === 'undefined') {
        window.showLoader = (msg = 'Loading...') => {
            const l = document.getElementById('globalLoader');
            if (l) {
                l.style.display = 'flex';
                l.querySelector('.loader-text').textContent = msg;
            }
        };
    }

    if (typeof window.hideLoader === 'undefined') {
        window.hideLoader = () => {
            const l = document.getElementById('globalLoader');
            if (l) l.style.display = 'none';
        };
    }

    // Download File Function
    if (typeof window.downloadFile === 'undefined') {
        window.downloadFile = function(fileId) {
            if (!fileId || fileId <= 0) {
                if (typeof showNotification === 'function') {
                    showNotification('Invalid file ID', 'danger', 3000);
                } else {
                    alert('Invalid file ID');
                }
                return;
            }

            try {
                if (typeof showNotification === 'function') {
                    showNotification('Starting download...', 'success', 2000);
                }
            } catch (e) {
                // Swallow and continue; avoid debug console output in production
            }

            const downloadLink = document.createElement('a');
            downloadLink.href = 'index?file_id=' + encodeURIComponent(fileId);
            downloadLink.download = '';
            downloadLink.style.display = 'none';
            downloadLink.target = '_blank';
            downloadLink.rel = 'noopener noreferrer';
            document.body.appendChild(downloadLink);

            try {
                downloadLink.click();
            } catch (e) {
                console.error('Download click failed, using window.location:', e);
                window.location.href = 'index?file_id=' + encodeURIComponent(fileId);
            }

            setTimeout(() => {
                if (downloadLink.parentNode) {
                    document.body.removeChild(downloadLink);
                }
            }, 100);
        };
    }

    // CSRF Helper Functions
    if (typeof window.getCsrfToken === 'undefined') {
        window.getCsrfToken = function() {
            const metaTag = document.querySelector('meta[name="csrf-token"]');
            return metaTag ? metaTag.getAttribute('content') : '';
        };
    }
    </script>
</body>

</html>
<?php
    }

    /**
     * Render MasterLayout specific styles
     */
    public function renderStyles(): void
    {
        $this->renderNavbarStyles();
        $this->renderBannedNavbarStyles();
        $this->renderPageContainerStyles();
    }

    /**
     * Render navbar-specific styles
     */
    private function renderNavbarStyles(): void
    {
        ?>
<style>
/* Main Navigation Bar - Fixed Dimensions & Stability */
.bc-nav {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    position: fixed;
    top: 32px;
    left: 0;
    right: 0;
    width: 100%;
    height: var(--bc-height-desktop) !important;
    /* Strictly fixed height */
    min-height: var(--bc-height-desktop) !important;
    max-height: var(--bc-height-desktop) !important;
    z-index: var(--bc-z-sticky);
    /* Use a solid, opaque background to prevent transparency issues */
    background: #0A1410;
    border-bottom: 1px solid var(--bc-border-accent);
    transition: all var(--bc-transition-base);
    overflow: visible !important;
}

.bc-nav__inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 100%;
    /* Match parent fixed height */
    padding: 0 8%;
    max-width: 100%;
    margin: 0 auto;
    width: 100%;
}

/* Remove constraints on large screens to prevent scroll buttons */
@media (min-width: 1600px) {
    .bc-nav__inner {
        max-width: none;
        padding: 0 var(--bc-spacing-xl);
    }
}

/* Hide scroll buttons on very large screens where all items fit */
@media (min-width: 3000px) {
    .bc-nav__scroll-btn {
        display: none !important;
    }
}

.bc-nav__brand {
    display: flex;
    align-items: center;
    gap: var(--bc-spacing-md);
    flex-shrink: 0;
    height: 100%;
}

.bc-nav__logo {
    display: flex;
    align-items: center;
    text-decoration: none;
    gap: 8px;
    height: 100%;
}

/* Mobile Toggle - Appears when screen is small (at 1100px) */
.bc-nav__toggle {
    display: none;
    background: transparent;
    border: 0;
    color: var(--bc-text);
    width: 40px;
    height: 40px;
    cursor: pointer;
    position: relative;
    z-index: 1001;
    margin-right: 10px;
}

/* Switch to Sidebar Menu at 1100px */
@media (max-width: 1100px) {
    .bc-nav__toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .bc-nav__menu {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: 280px;
        background: rgba(13, 27, 13, 0.98);
        /* Solid dark background */
        backdrop-filter: blur(15px);
        /* Strong blur for premium feel */
        -webkit-backdrop-filter: blur(15px);
        margin-left: 0;
        padding-top: 60px;
        transform: translateX(-100%);
        transition: transform 0.3s var(--bc-ease-out);
        z-index: var(--bc-z-menu);
        box-shadow: 10px 0 30px rgba(0, 0, 0, 0.5);
        /* Stronger shadow on the right */
        display: block !important;
        border-right: 1px solid var(--bc-border-accent);
    }

    .bc-nav__menu.is-open {
        transform: translateX(0);
    }

    .bc-nav__overlay.is-visible {
        display: block;
    }

    .bc-nav__menu .bc-nav__list {
        flex-direction: column;
        align-items: stretch;
        padding: 16px;
        display: flex !important;
        height: auto;
    }
}

.bc-nav__hamburger {
    display: block;
    width: 22px;
    height: 2px;
    background: var(--bc-text);
    position: relative;
    transition: all 0.3s ease;
}

.bc-nav__hamburger::before,
.bc-nav__hamburger::after {
    content: '';
    position: absolute;
    width: 100%;
    height: 2px;
    background: var(--bc-text);
    transition: all 0.3s ease;
}

.bc-nav__hamburger::before {
    top: -6px;
}

.bc-nav__hamburger::after {
    top: 6px;
}

.bc-nav__toggle[aria-expanded="true"] .bc-nav__hamburger {
    background: transparent;
}

.bc-nav__toggle[aria-expanded="true"] .bc-nav__hamburger::before {
    transform: rotate(45deg);
    top: 0;
}

.bc-nav__toggle[aria-expanded="true"] .bc-nav__hamburger::after {
    transform: rotate(-45deg);
    top: 0;
}

.bc-nav__menu {
    flex: 1;
    margin-left: 0;
    height: 100%;
    display: flex;
    align-items: center;
}

.bc-nav__actions {
    display: flex;
    align-items: center;
    gap: var(--bc-spacing-sm);
    flex-shrink: 0;
    height: 100%;
}

.bc-nav__overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(2px);
    z-index: var(--bc-z-overlay);
}

/* Mobile Adjustments for fixed height */
@media (max-width: 991px) {
    .bc-nav {
        height: var(--bc-height-mobile) !important;
        min-height: var(--bc-height-mobile) !important;
        max-height: var(--bc-height-mobile) !important;
    }

    .bc-nav__inner {
        height: 100%;
    }
}

.bc-btn-terminal {
    color: var(--bc-accent) !important;
    border: 1px solid rgba(46, 204, 113, 0.4) !important;
    background: rgba(46, 204, 113, 0.08) !important;
    font-family:
        <?=LayoutConfig::TERMINAL_FONT ?> !important;
    font-size: 0.75rem !important;
    letter-spacing: 1px !important;
    padding: 8px 20px !important;
    border-radius: 4px !important;
    text-transform: uppercase !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    outline: none !important;
    user-select: none;
}

.bc-btn-terminal:hover {
    background: rgba(46, 204, 113, 0.2) !important;
    border-color: var(--bc-accent) !important;
    box-shadow: 0 0 15px rgba(46, 204, 113, 0.4);
    color: #fff !important;
    transform: translateY(-1px);
}

.bc-btn-terminal:active {
    transform: translateY(0);
    box-shadow: 0 0 5px rgba(46, 204, 113, 0.2);
    background: rgba(46, 204, 113, 0.3) !important;
}

.bc-btn-terminal i {
    font-size: 0.8rem;
}

/* Shared Action Buttons (Message, Wallet, etc) */
.bc-action-btn {
    background: transparent;
    border: none;
    color: var(--bc-accent);
    border-radius: 50%;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--bc-transition-base);
    padding: 0;
    box-shadow: none;
    text-decoration: none;
}

.bc-action-btn:hover {
    background: rgba(46, 204, 113, 0.1);
    color: #fff;
    transform: translateY(-1px);
}

.bc-wallet-btn:active {
    transform: translateY(0) scale(0.95) !important;
}

.bc-wallet-btn i {
    font-size: 1.1rem !important;
}
</style>
<?php
    }

    /**
     * Render restricted navbar specific styles
     */
    private function renderBannedNavbarStyles(): void
    {
        ?>
<style>
.bc-nav.bc-nav--restricted {
    height: 40px;
    min-height: 40px;
    background: #220000;
    border-bottom: 1px solid #ff0000;
}

.bc-nav__inner.bc-nav__inner--restricted {
    height: 40px;
    padding: 0 15px;
}

.bc-nav__restricted-title {
    color: #ff0000;
    font-weight: bold;
    letter-spacing: 1px;
}

.bc-nav--restricted .bc-btn-terminal {
    border-color: rgba(46, 204, 113, 0.3) !important;
}
</style>
<?php
    }

    /**
     * Render page container specific styles
     */
    private function renderPageContainerStyles(): void
    {
        ?>
<style>
.content {
    padding: 20px;
    min-height: calc(100vh - 120px);
    position: relative;
    z-index: 1;
}

.main-container {
    background: rgba(10, 20, 10, 0.8);
    border: 1px solid var(--bc-border);
    border-radius: 8px;
    padding: 20px;
}
</style>
<?php
    }
}