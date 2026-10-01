<?php
declare(strict_types=1);

namespace ROOTS\Layout;

use ROOTS\Layout\MasterLayout;
use ROOTS\Layout\NavProfile;
use ROOTS\Layout\NavNotification;
use ROOTS\Layout\LayoutConfig;

/**
 * Linkbar - Displays the system top bar with onion address and status indicators
 */
class Linkbar
{
    /**
     * Component Constants for isolation - now using LayoutConfig
     */

    /**
     * Render the system top bar (onion address + status indicators)
     * This bar is designed to be completely independent of the main Navbar
     */
    /**
     * Render the system top bar (onion address + status indicators)
     * This bar is designed to be completely independent of the main Navbar
     *
     * @param array<string, mixed>|null $user_data
     */
    public static function render(?string $onionAddress = null, ?array $user_data = null, ?bool $is_admin = false, string $base_path = ""): void
    {
        require_once __DIR__ . '/../../includes/logo.php';
        $address = $onionAddress ?? LayoutConfig::DEFAULT_ONION_ADDRESS;
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        $address = $onionAddress ?? ($currentHost . '');

        // Get user data with type safety
        $user_points = is_array($user_data) ? ($user_data['points'] ?? 0) : 0;
        $display_name = is_array($user_data) ? ($user_data['display_name'] ?? LayoutConfig::DEFAULT_DISPLAY_NAME) : LayoutConfig::DEFAULT_DISPLAY_NAME;
        $subscription = is_array($user_data) ? ($user_data['subscription'] ?? LayoutConfig::DEFAULT_SUBSCRIPTION) : LayoutConfig::DEFAULT_SUBSCRIPTION;
        $user_avatar = is_array($user_data) ? ($user_data['avatar_url'] ?? '') : '';
        $formattedPoints = LayoutConfig::formatPoints((int) $user_points);
        ?>
        <!-- Independent System Top Bar -->
        <div class="bc-system-top-bar" id="systemTopBar">
            <div class="bc-top-bar-inner">
                <!-- App Logo -->
                <div class="top-bar-logo" style="margin-right: 10px; display: flex; align-items: center;">
                    <?= render_logo(18) ?>
                </div>
                <!-- Left: Node Status (Hides on mobile, moves to sidebar) -->
                <div class="top-bar-node hide-md">
                    <!-- User Info Display (Small) -->
                    <div class="top-bar-user-info">
                        <span class="user-name-display" title="Username: <?= LayoutHelpers::sanitize($display_name) ?>">
                            <?= LayoutHelpers::sanitize(LayoutConfig::truncateDisplayName($display_name)) ?>
                        </span>
                        <span class="user-subscription" title="<?= LayoutHelpers::sanitize($subscription) ?> Tier">
                            <?= LayoutHelpers::sanitize(LayoutConfig::getSubscriptionAbbr($subscription)) ?>
                        </span>
                    </div>
                </div>

                <!-- Points Display (Always Visible) -->
                <div class="top-bar-points" title="Your Balance: <?= $formattedPoints ?> Coins">
                    <i class="<?= LayoutConfig::ICON_COINS ?> text-warning" style="font-size: 0.65rem;"></i>
                    <span class="points-count"><?= $formattedPoints ?></span>
                </div>

                <!-- Center: Onion Address (Flexible size) -->
                <div class="top-bar-address-wrap">
                    <!-- Signal Indicator (Phone-like signal bars) - Beside address -->
                    <div class="top-bar-signal" id="linkbarSignal">
                        <div class="signal-bars">
                            <div class="bar bar-1"></div>
                            <div class="bar bar-2"></div>
                            <div class="bar bar-3"></div>
                            <div class="bar bar-4"></div>
                        </div>
                        <span class="signal-text" id="linkbarSignalText"><?= LayoutConfig::getSignalPercentage() ?></span>
                    </div>


                    <div class="top-bar-address hud-bracket" id="copyOnionAddress" aria-label="Click to copy onion address">
                        <i class="<?= LayoutConfig::ICON_LINK ?> me-2"
                            style="font-size: 0.6rem; color: <?= LayoutConfig::ACCENT_COLOR ?>;"></i>
                        <span class="address-val addr-text"><?= LayoutHelpers::sanitize($address) ?></span>
                        <span class="address-copy copy-hint ms-2"></span>
                    </div>
                </div>



                <!-- Right: Network Status & Mobile Toggle -->
                <div class="top-bar-actions">



                    <!-- Sidebar Toggle for Top Bar -->

                    <button type="button" class="top-bar-navbar-toggle" id="navbarToggle" aria-label="Toggle main navbar">
                        <i class="<?= LayoutConfig::ICON_EYE ?>" id="navbarToggleIcon"></i>
                    </button>


                    <div class="top-bar-node top-bar-net hide-sm">
                        <div>
                            <button type="button" class="top-bar-sidebar-toggle" id="topBarSidebarToggle"
                                aria-label="Toggle system menu">
                                <i class="<?= LayoutConfig::ICON_SHIELD ?> me-1"></i>
                                <span class="net-label"><?= LayoutConfig::SSL_STATUS_TEXT ?></span>

                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Sidebar (Isolated) -->
        <div class="<?= LayoutConfig::CLASS_SYSTEM_SIDEBAR ?>" id="systemSidebar">
            <div class="<?= LayoutConfig::CLASS_SYSTEM_SIDEBAR_HEADER ?>">
                <span class="sidebar-title"><?= LayoutConfig::SYSTEM_TERMINAL_TITLE ?></span>
                <button type="button" class="sidebar-close" id="systemSidebarClose">&times;</button>
            </div>
            <div class="<?= LayoutConfig::CLASS_SYSTEM_SIDEBAR_CONTENT ?>">
                <div class="sidebar-section">
                    <span class="section-label"><?= LayoutConfig::SECTION_NODE_STATUS ?></span>
                    <div class="sidebar-item">
                        <span class="node-dot"></span>
                        <span><?= LayoutConfig::NODE_STATUS_TEXT ?></span>
                    </div>
                </div>
                <div class="sidebar-section">
                    <span class="section-label"><?= LayoutConfig::SECTION_NETWORK_SECURITY ?></span>
                    <div class="sidebar-item">
                        <i class="<?= LayoutConfig::ICON_SHIELD ?> me-2 text-success"></i>
                        <span>SSL ENCRYPTION ACTIVE</span>
                    </div>
                </div>
                <div class="sidebar-section">
                    <span class="section-label"><?= LayoutConfig::SECTION_SESSION_INFO ?></span>
                    <div class="sidebar-item">
                        <i class="<?= LayoutConfig::ICON_CLOCK ?> me-2 text-success"></i>
                        <span><?= LayoutConfig::getUptimeText() ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="system-sidebar-overlay" id="systemSidebarOverlay"></div>

        <!-- Notification System Components -->
        <?php NavNotification::renderArea(); ?>
        <?php NavNotification::renderModal(); ?>
        <?php NavNotification::renderStyles(); ?>
        <?php NavNotification::renderJavaScript($base_path); ?>
        <?php NavNotification::renderHelperFunctions(); ?>

        <script <?php echo \ROOTS\Middleware\SecurityHeadersMiddleware::getNonceAttribute(); ?>>
            (function () {
                /**
                 * Isolated copy function for the System Top Bar
                 */
                function handleTopBarCopy(el) {
                    if (!el) return;
                    const textEl = el.querySelector('.addr-text');
                    if (!textEl) return;

                    const url = textEl.textContent.trim();
                    navigator.clipboard.writeText(url).then(() => {
                        const copyTag = el.querySelector('.address-copy');
                        if (copyTag) {
                            const original = copyTag.textContent;
                            copyTag.textContent = '[ COPIED ]';
                            copyTag.style.color = '#fff';
                            setTimeout(() => {
                                copyTag.textContent = original;
                                copyTag.style.color = '<?= LayoutConfig::ACCENT_COLOR ?>';
                            }, 1500);
                        }
                    }).catch(err => console.error('[TopBar] Copy failed:', err));
                }

                document.addEventListener('DOMContentLoaded', function () {
                    const copyEl = document.getElementById('copyOnionAddress');
                    const toggleBtn = document.getElementById('topBarSidebarToggle');
                    const closeBtn = document.getElementById('systemSidebarClose');
                    const sidebar = document.getElementById('systemSidebar');
                    const overlay = document.getElementById('systemSidebarOverlay');

                    if (copyEl) {
                        copyEl.addEventListener('click', function () {
                            handleTopBarCopy(this);
                        });
                    }

                    function toggleSidebar() {
                        sidebar.classList.toggle('is-active');
                        overlay.classList.toggle('is-active');
                        document.body.style.overflow = sidebar.classList.contains('is-active') ? 'hidden' : '';
                    }

                    // Navbar Toggle Function
                    function toggleNavbar() {
                        const navbar = document.querySelector('.bc-nav');
                        const navbarToggleIcon = document.getElementById('navbarToggleIcon');
                        const body = document.body;

                        if (!navbar) return;

                        const isHidden = navbar.classList.toggle('navbar-hidden');
                        body.classList.toggle('navbar-hidden', isHidden);

                        // Toggle eye icon
                        if (navbarToggleIcon) {
                            if (isHidden) {
                                navbarToggleIcon.classList.remove('fa-eye');
                                navbarToggleIcon.classList.add('fa-eye-slash');
                            } else {
                                navbarToggleIcon.classList.remove('fa-eye-slash');
                                navbarToggleIcon.classList.add('fa-eye');
                            }
                        }

                        // Save state to localStorage
                        localStorage.setItem('navbarHidden', isHidden);
                    }

                    // Load saved state on page load
                    function loadNavbarState() {
                        const navbar = document.querySelector('.bc-nav');
                        const navbarToggleIcon = document.getElementById('navbarToggleIcon');
                        const body = document.body;
                        const isHidden = localStorage.getItem('navbarHidden') === 'true';

                        if (navbar && isHidden) {
                            navbar.classList.add('navbar-hidden');
                            body.classList.add('navbar-hidden');
                            if (navbarToggleIcon) {
                                navbarToggleIcon.classList.remove('fa-eye');
                                navbarToggleIcon.classList.add('fa-eye-slash');
                            }
                        }
                    }

                    // Wait for page to fully load before loading navbar state
                    window.addEventListener('load', loadNavbarState);

                    if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
                    if (closeBtn) closeBtn.addEventListener('click', toggleSidebar);
                    if (overlay) overlay.addEventListener('click', toggleSidebar);

                    // Initialize navbar toggle
                    const navbarToggleBtn = document.getElementById('navbarToggle');
                    if (navbarToggleBtn) {
                        navbarToggleBtn.addEventListener('click', toggleNavbar);
                    }

                    // Dynamic Signal Update (Same logic as welcome.php)
                    function updateLinkbarSignal() {
                        const now = new Date();
                        const utcTime = now.getTime() + (now.getTimezoneOffset() * 60000);

                        // Get current hostname to determine offset
                        const currentHost = window.location.hostname;
                        let tzOffset = 2; // Default (Primary/Local)

                        // Assign UTC offsets based on hostname patterns
                        if (currentHost.includes('51') || currentHost.includes('east')) tzOffset = -4;
                        else if (currentHost.includes('36') || currentHost.includes('bom')) tzOffset = 5.5;
                        else if (currentHost.includes('24') || currentHost.includes('bjk')) tzOffset = 8;
                        else if (currentHost.includes('12') || currentHost.includes('msc')) tzOffset = 3;
                        else if (currentHost.includes('11') || currentHost.includes('tok')) tzOffset = 9;
                        else if (currentHost.includes('10') || currentHost.includes('lon')) tzOffset = 4;
                        else if (currentHost.includes('kem')) tzOffset = 7;
                        else if (currentHost.includes('hub')) tzOffset = 2;
                        else if (currentHost.includes('07') || currentHost.includes('zur')) tzOffset = 12;
                        else if (currentHost.includes('43') || currentHost.includes('syd')) tzOffset = 11;

                        // Calculate Local Time for that server
                        const localDate = new Date(utcTime + (3600000 * tzOffset));
                        const secondsInDay = (localDate.getHours() * 3600) + (localDate.getMinutes() * 60) + localDate.getSeconds();
                        const totalSeconds = 86400;

                        // Formula: Start 13, End 961
                        let basePing = 13 + (948 * (secondsInDay / totalSeconds));
                        let individualPing = Math.floor(basePing + ((0 * 7) % 20));
                        individualPing = Math.max(13, individualPing);

                        // Convert to percentage (reverse: lower ping = higher percentage)
                        const percent = Math.floor(100 - ((individualPing - 13) / (961 - 13) * 100));

                        const signalText = document.getElementById('linkbarSignalText');
                        const signalContainer = document.getElementById('linkbarSignal');

                        if (signalContainer) {
                            // Determine signal strength (1-4 bars)
                            let strength = 1;
                            if (percent >= 75) strength = 4;
                            else if (percent >= 50) strength = 3;
                            else if (percent >= 25) strength = 2;

                            signalContainer.setAttribute('data-strength', strength);

                            // Adaptive Color Coding
                            const clampedPercent = Math.max(0, Math.min(100, percent));
                            const hue = Math.round(120 - ((clampedPercent / 100) * 120));
                            signalContainer.style.setProperty('--signal-color', `hsl(${hue}, 100%, 50%)`);
                        }

                        if (signalText) {
                            signalText.textContent = percent + '%';
                        }
                    }

                    // Update signal every second
                    setInterval(updateLinkbarSignal, 1000);
                    updateLinkbarSignal();

                    // Load saved state
                    loadNavbarState();
                });
            })();
        </script>
        <?php
    }

    /**
     * Render Linkbar CSS styles - Completely isolated from main navbar styles
     */
    public static function renderStyles(): void
    {
        ?>
        <style>
            /* Isolated System Top Bar Styles */
            .bc-system-top-bar {
                background:
                    <?= LayoutConfig::BAR_BG ?>
                ;
                border-bottom: 1px solid
                    <?= LayoutConfig::BAR_BORDER ?>
                ;
                height:
                    <?= LayoutConfig::BAR_HEIGHT ?>
                ;
                min-height:
                    <?= LayoutConfig::BAR_HEIGHT ?>
                ;
                display: flex;
                align-items: center;
                z-index: 1150;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                width: 100%;
                font-family: 'Courier New', monospace;
                margin: 0;
                padding: 0;
            }

            .bc-system-top-bar * {
                box-sizing: border-box;
            }

            .bc-top-bar-inner {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0 15px;
                width: 100%;
                max-width: 100%;
                margin: 0 auto;
                font-size: 0.6rem;
                letter-spacing: 1px;
                text-transform: uppercase;
                font-weight: 700;
            }

            /* Responsive Visibility Classes */
            @media (max-width: 991px) {
                .hide-md {
                    display: none !important;
                }
            }

            @media (max-width: 576px) {
                .hide-sm {
                    display: none !important;
                }
            }

            .top-bar-node {
                display: flex;
                align-items: center;
                gap: 6px;
                color: #2ecc71;
                white-space: nowrap;
            }

            .node-dot {
                width: 8px;
                height: 8px;
                background: #2ecc71;
                border-radius: 50%;
                box-shadow: 0 0 1px #2ecc71;
                animation: topbar-pulse 1.4s infinite;
            }

            @keyframes topbar-pulse {
                0% {
                    opacity: 1;
                }

                50% {
                    opacity: 0.4;
                }

                100% {
                    opacity: 1;
                }
            }

            .top-bar-address-wrap {
                flex: 1;
                display: flex;
                justify-content: center;
                padding: 0 10px;
            }

            .top-bar-address {
                display: flex;
                align-items: center;
                gap: 8px;
                color: #ffffff;
                cursor: pointer;
                background: rgba(46, 204, 113, 0.08);
                padding: 2px 12px;
                border: 1px solid rgba(46, 204, 113, 0.2);
                border-radius: 3px;
                transition: all 0.2s ease;
                position: relative;
                max-width: 100%;
            }

            .top-bar-address:hover {
                background: rgba(46, 204, 113, 0.15);
                border-color: #2ecc71;
                box-shadow: 0 0 10px rgba(46, 204, 113, 0.2);
            }

            .address-val {
                font-weight: 900;
                color: #ffffff;
                text-shadow: 0 0 5px rgba(46, 204, 113, 0.3);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .address-copy {
                color: #2ecc71;
                font-size: 0.55rem;
                font-weight: bold;
            }

            .top-bar-actions {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .top-bar-sidebar-toggle {
                background: transparent;
                border: 0;
                color: #2ecc71;
                cursor: pointer;
                font-size: 0.7rem;

                font-family: 'unifont', sans-serif;
                display: flex;
                padding: 8px;
                align-items: center;
                justify-content: center;
                padding: 8px;
            }

            .top-bar-sidebar-toggle:hover {
                color: #833d04;
            }

            /* Signal Indicator in Linkbar (Phone-like signal bars) */
            .top-bar-signal {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-right: 10px;
            }

            .signal-bars {
                display: flex;
                align-items: flex-end;
                gap: 2px;
                height: 14px;
            }

            .signal-bars .bar {
                width: 3px;
                background: rgba(46, 204, 113, 0.3);
                border-radius: 1px;
                transition: background-color 0.3s ease;
            }

            .signal-bars .bar-1 {
                height: 4px;
            }

            .signal-bars .bar-2 {
                height: 7px;
            }

            .signal-bars .bar-3 {
                height: 10px;
            }

            .signal-bars .bar-4 {
                height: 14px;
            }

            /* Active bars based on signal strength */
            .top-bar-signal[data-strength="1"] .bar-1 {
                background: var(--signal-color, #2ecc71);
            }

            .top-bar-signal[data-strength="2"] .bar-1,
            .top-bar-signal[data-strength="2"] .bar-2 {
                background: var(--signal-color, #2ecc71);
            }

            .top-bar-signal[data-strength="3"] .bar-1,
            .top-bar-signal[data-strength="3"] .bar-2,
            .top-bar-signal[data-strength="3"] .bar-3 {
                background: var(--signal-color, #2ecc71);
            }

            .top-bar-signal[data-strength="4"] .bar-1,
            .top-bar-signal[data-strength="4"] .bar-2,
            .top-bar-signal[data-strength="4"] .bar-3,
            .top-bar-signal[data-strength="4"] .bar-4 {
                background: var(--signal-color, #2ecc71);
            }

            .top-bar-signal .signal-text {
                font-size: 0.65rem;
                color: #2ecc71;
                font-weight: 600;
                min-width: 30px;
                text-align: right;
            }

            /* User Info Display in Linkbar */
            .top-bar-user-info {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-right: 10px;
                font-size: 0.6rem;
            }

            .top-bar-user-info .user-name-display {
                color: #fff;
                font-weight: 600;
                max-width: 60px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .top-bar-user-info .user-subscription {
                color: #2ecc71;
                font-size: 0.55rem;
                padding: 1px 4px;
                background: rgba(46, 204, 113, 0.1);
                border: 1px solid rgba(46, 204, 113, 0.3);
                border-radius: 2px;
            }

            /* Points Display in Linkbar */
            .top-bar-points {
                display: flex;
                align-items: center;
                gap: 4px;
                margin-right: 8px;
                padding: 2px 6px;
                background: rgba(255, 177, 0, 0.05);
                border: 1px solid rgba(255, 177, 0, 0.2);
                border-radius: 3px;
            }

            .top-bar-points .points-count {
                font-size: 0.65rem;
                color: #ffb100;
                font-weight: 600;
            }

            /* Mobile responsive adjustments for points */
            @media (max-width: 576px) {
                .top-bar-points {
                    margin-right: 4px;
                    padding: 2px 4px;
                }

                .top-bar-points .points-count {
                    font-size: 0.6rem;
                }

                .top-bar-points i {
                    font-size: 0.55rem !important;
                }
            }

            /* Navbar Toggle Button (Eye Icon) */
            .top-bar-navbar-toggle {
                background: rgba(0, 255, 0, 0.1);
                border: 1px solid rgba(46, 204, 113, 0.3);
                color: #2ecc71;
                cursor: pointer;
                font-size: 0.85rem;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 6px 10px;
                border-radius: 4px;
                transition: all 0.2s ease;
            }

            .top-bar-navbar-toggle:hover {
                background: rgba(0, 255, 0, 0.2);
                border-color: #2ecc71;
                color: #fff;
                box-shadow: 0 0 10px rgba(46, 204, 113, 0.3);
            }

            /* Main Navbar - fixed at top with smooth animation */
            .bc-nav {
                position: fixed;
                top: 32px;
                /* Below Linkbar when visible */
                left: 0;
                right: 0;
                z-index: 1049;
                transition: top 0.3s ease-in-out, opacity 0.3s ease-in-out;
            }

            .bc-nav.navbar-hidden {
                top: -44px;
                /* Move above viewport (negative of height) */
                opacity: 0;
                pointer-events: none;
            }

            /* Body adjustment when navbar is hidden (only Linkbar visible) */
            body.navbar-hidden {
                padding-top: 32px !important;
            }

            /* Body padding when navbar is visible (default) */
            body:not(.navbar-hidden) {
                padding-top: 76px !important;
                /* 32px Linkbar + 44px Navbar */
            }

            /* Bracket decorations */
            .hud-bracket::before,
            .hud-bracket::after {
                content: '';
                position: absolute;
                width: 3px;
                height: 3px;
                border: 1px solid #2ecc71;
                opacity: 0.4;
            }

            .hud-bracket::before {
                left: -6px;
                top: 50%;
                transform: translateY(-50%);
                border-right: none;
                border-bottom: none;
            }

            .hud-bracket::after {
                right: -6px;
                top: 50%;
                transform: translateY(-50%);
                border-left: none;
                border-bottom: none;
            }

            /* System Sidebar Styles */
            .bc-system-sidebar {
                position: fixed;
                top: 0;
                right: 0;
                bottom: 0;
                width: 280px;
                background: #080808;
                border-left: 1px solid rgba(46, 204, 113, 0.2);
                z-index: 3000;
                transform: translateX(100%);
                transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
                font-family: 'Courier New', monospace;
                display: flex;
                flex-direction: column;
                box-shadow: -10px 0 30px rgba(0, 0, 0, 0.8);
            }

            .bc-system-sidebar.is-active {
                transform: translateX(0);
            }

            .system-sidebar-header {
                padding: 20px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .sidebar-title {
                color: #2ecc71;
                font-weight: 800;
                font-size: 0.75rem;
                letter-spacing: 2px;
            }

            .sidebar-close {
                background: transparent;
                border: 0;
                color: #fff;
                font-size: 1.5rem;
                cursor: pointer;
                line-height: 1;
            }

            .system-sidebar-content {
                padding: 20px;
                flex: 1;
                overflow-y: auto;
            }

            .sidebar-section {
                margin-bottom: 25px;
            }

            .section-label {
                display: block;
                font-size: 0.55rem;
                color: #555;
                margin-bottom: 10px;
                letter-spacing: 1.5px;
            }

            .sidebar-item {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #eee;
                font-size: 0.7rem;
                background: rgba(255, 255, 255, 0.02);
                padding: 10px;
                border-radius: 4px;
            }

            .system-sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(2px);
                z-index: 2999;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }

            .system-sidebar-overlay.is-active {
                opacity: 1;
                visibility: visible;
            }

            /* Final Responsive Adjustment for Address */
            @media (max-width: 480px) {
                .address-val {
                    max-width: 100px;
                }

                .bc-top-bar-inner {
                    padding: 0 10px;
                }
            }

            /* Isolation overrides - prevent external CSS conflicts from dashboard.css */
            .bc-system-top-bar {
                margin: 0 !important;
                padding: 0 !important;
            }

            .bc-system-top-bar .top-bar-user-section {
                margin: 0 !important;
                padding: 0 !important;
            }

            .bc-system-top-bar .top-bar-center {
                margin: 0 !important;
                padding: 0 !important;
            }

            .bc-system-top-bar .top-bar-right {
                margin: 0 !important;
                padding: 0 !important;
            }

            .bc-system-top-bar .user-avatar-mini,
            .bc-system-top-bar .user-details-mini,
            .bc-system-top-bar .signal-container,
            .bc-system-top-bar .address-container,
            .bc-system-top-bar .points-display,
            .bc-system-top-bar .status-badge,
            .bc-system-top-bar .toggle-btn {
                margin: 0 !important;
                padding: 0 !important;
            }
        </style>
        <?php
    }
}
