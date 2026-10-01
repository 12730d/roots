<?php
declare(strict_types=1);

namespace ROOTS\Layout;

/**
 * LayoutConfig - Centralized configuration constants for layout components
 * Eliminates duplication and provides a single source of truth for layout settings
 */
class LayoutConfig
{
    // ==================== Default Values ====================
    
    /**
     * Default onion address for Tor network
     */
    public const DEFAULT_ONION_ADDRESS = 'trzjmgy54a2kry3dsqvpiyutr34xgffnhrgd7okmpky2qaoqbyfovfyd.onion';
    
    /**
     * Default avatar image path
     */
    public const DEFAULT_AVATAR = "img/user.jpg";
    
    /**
     * Default subscription tier
     */
    public const DEFAULT_SUBSCRIPTION = "Basic";
    
    /**
     * Default terminal font family
     */
    public const TERMINAL_FONT = "'Courier New', monospace";
    
    // ==================== Colors ====================
    
    /**
     * Primary accent color (terminal green)
     */
    public const ACCENT_COLOR = "#2ecc71";
    
    /**
     * System bar background color
     */
    public const BAR_BG = "#000000";
    
    /**
     * System bar border color
     */
    public const BAR_BORDER = "rgba(46, 204, 113, 0.2)";
    
    /**
     * Accent color for hover states
     */
    public const ACCENT_HOVER = "#27AE60";
    
    /**
     * Light accent color for backgrounds
     */
    public const ACCENT_LIGHT = "rgba(46, 204, 113, 0.25)";
    
    /**
     * Accent glow color
     */
    public const ACCENT_GLOW = "rgba(46, 204, 113, 0.18)";
    
    // ==================== Dimensions ====================
    
    /**
     * System bar height
     */
    public const BAR_HEIGHT = "32px";
    
    /**
     * Navbar height on mobile
     */
    public const NAVBAR_HEIGHT_MOBILE = "36px";
    
    /**
     * Navbar height on desktop
     */
    public const NAVBAR_HEIGHT_DESKTOP = "44px";
    
    // ==================== Text Messages ====================
    
    /**
     * Lock message for premium features
     */
    public const LOCK_MESSAGE = "This feature requires a paid subscription (BASIC | Pro | Premium | VIP)";
    
    /**
     * Node status text
     */
    public const NODE_STATUS_TEXT = "SECURE NODE ACTIVE TOR";
    
    /**
     * SSL status text
     */
    public const SSL_STATUS_TEXT = "SSL ACTIVE";
    
    /**
     * Notification modal title
     */
    public const NOTIFICATION_MODAL_TITLE = "SYSTEM_NOTIFICATIONS";
    
    /**
     * System terminal title
     */
    public const SYSTEM_TERMINAL_TITLE = "SYSTEM_TERMINAL";
    
    /**
     * My purchases text
     */
    public const MY_PURCHASES_TEXT = "My Purchases";
    
    /**
     * Default display name
     */
    public const DEFAULT_DISPLAY_NAME = "User";
    
    /**
     * Default username
     */
    public const DEFAULT_USERNAME = "Guest";
    
    // ==================== CSS Class Names ====================
    
    /**
     * System top bar container
     */
    public const CLASS_SYSTEM_TOP_BAR = "bc-system-top-bar";
    
    /**
     * Top bar inner container
     */
    public const CLASS_TOP_BAR_INNER = "bc-top-bar-inner";
    
    /**
     * Top bar node info section
     */
    public const CLASS_TOP_BAR_NODE = "top-bar-node";
    
    /**
     * Top bar points display
     */
    public const CLASS_TOP_BAR_POINTS = "top-bar-points";
    
    /**
     * Top bar address wrapper
     */
    public const CLASS_TOP_BAR_ADDRESS_WRAP = "top-bar-address-wrap";
    
    /**
     * Top bar signal indicator
     */
    public const CLASS_TOP_BAR_SIGNAL = "top-bar-signal";
    
    /**
     * Top bar actions section
     */
    public const CLASS_TOP_BAR_ACTIONS = "top-bar-actions";
    
    /**
     * System sidebar container
     */
    public const CLASS_SYSTEM_SIDEBAR = "bc-system-sidebar";
    
    /**
     * System sidebar header
     */
    public const CLASS_SYSTEM_SIDEBAR_HEADER = "system-sidebar-header";
    
    /**
     * System sidebar content
     */
    public const CLASS_SYSTEM_SIDEBAR_CONTENT = "system-sidebar-content";
    
    /**
     * Navigation bar item
     */
    public const CLASS_NAV_ITEM = "bc-nav__item";
    
    /**
     * Navigation bar link
     */
    public const CLASS_NAV_LINK = "bc-nav__link";
    
    /**
     * Dropdown container
     */
    public const CLASS_DROPDOWN = "bc-dropdown";
    
    /**
     * Dropdown trigger button
     */
    public const CLASS_DROPDOWN_TRIGGER = "bc-dropdown__trigger";
    
    /**
     * Dropdown menu
     */
    public const CLASS_DROPDOWN_MENU = "bc-dropdown__menu";
    
    /**
     * Dropdown menu item
     */
    public const CLASS_DROPDOWN_ITEM = "bc-dropdown__item";
    
    /**
     * Locked item class
     */
    public const CLASS_LOCKED_ITEM = "bc-locked-item";
    
    /**
     * Submenu wrapper
     */
    public const CLASS_DROPDOWN_SUBMENU_WRAP = "bc-dropdown__submenu-wrap";
    
    /**
     * Submenu trigger
     */
    public const CLASS_DROPDOWN_SUBMENU_TRIGGER = "bc-dropdown__submenu-trigger";
    
    /**
     * Submenu class
     */
    public const CLASS_DROPDOWN_SUBMENU = "bc-dropdown__submenu";
    
    // ==================== Responsive Classes ====================
    
    /**
     * Hide on mobile breakpoint
     */
    public const CLASS_HIDE_SM = "hide-sm";
    
    /**
     * Hide on medium breakpoint
     */
    public const CLASS_HIDE_MD = "hide-md";
    
    /**
     * Hide on large breakpoint
     */
    public const CLASS_HIDE_LG = "hide-lg";
    
    // ==================== Icon Classes ====================
    
    /**
     * Lock icon
     */
    public const ICON_LOCK = "fas fa-lock me-2";
    
    /**
     * Cloud icon
     */
    public const ICON_CLOUD = "fa fa-cloud me-2";
    
    /**
     * Cart icon
     */
    public const ICON_CART = "fa fa-shopping-cart me-2";
    
    /**
     * Asterisk icon
     */
    public const ICON_ASTERISK = "fa fa-asterisk me-2";
    
    /**
     * Flask icon
     */
    public const ICON_FLASK = "fa fa-flask-vial me-2";
    
    /**
     * Plus icon
     */
    public const ICON_PLUS = "fas fa-plus-circle me-2";
    
    /**
     * Shield icon
     */
    public const ICON_SHIELD = "fas fa-shield-halved";
    
    /**
     * Coins icon
     */
    public const ICON_COINS = "fas fa-coins";
    
    /**
     * Link icon
     */
    public const ICON_LINK = "fas fa-link";
    
    /**
     * Eye icon
     */
    public const ICON_EYE = "fas fa-eye";
    
    /**
     * Clock icon
     */
    public const ICON_CLOCK = "fas fa-clock";
    
    /**
     * Chevron down icon
     */
    public const ICON_CHEVRON_DOWN = "fas fa-chevron-down";
    
    /**
     * Chevron right icon
     */
    public const ICON_CHEVRON_RIGHT = "fas fa-chevron-right";
    
    /**
     * Crown icon
     */
    public const ICON_CROWN = "fas fa-crown";
    
    /**
     * Key icon
     */
    public const ICON_KEY = "fas fa-key";
    
    /**
     * Search icon
     */
    public const ICON_SEARCH = "fas fa-search";
    
    /**
     * Database icon
     */
    public const ICON_DATABASE = "fa fa-database";
    
    // ==================== Mask Patterns ====================
    
    /**
     * Mask pattern 1
     */
    public const MASK_PATTERN_1 = "XXXX XXXXX";
    
    /**
     * Mask pattern 2
     */
    public const MASK_PATTERN_2 = "XX XXXXXXXX";
    
    /**
     * Mask pattern 3
     */
    public const MASK_PATTERN_3 = "XXXXXXX XXX";
    
    /**
     * Mask pattern 4
     */
    public const MASK_PATTERN_4 = "XXXXXX XXX";
    
    // ==================== Section Labels ====================
    
    /**
     * Node status section label
     */
    public const SECTION_NODE_STATUS = "NODE_STATUS";
    
    /**
     * Network security section label
     */
    public const SECTION_NETWORK_SECURITY = "NETWORK_SECURITY";
    
    /**
     * Session info section label
     */
    public const SECTION_SESSION_INFO = "SESSION_INFO";
    
    // ==================== Helper Methods ====================
    
    /**
     * Get copy hint text
     */
    public static function getCopyHint(): string
    {
        return "[ COPY ]";
    }
    
    /**
     * Get copied confirmation text
     */
    public static function getCopiedText(): string
    {
        return "[ COPIED ]";
    }
    
    /**
     * Get signal percentage text
     */
    public static function getSignalPercentage(int $percentage = 99): string
    {
        return $percentage . "%";
    }
    
    /**
     * Format points for display
     */
    public static function formatPoints(int|float $points): string
    {
        return number_format((int)$points);
    }
    
    /**
     * Get subscription abbreviation
     */
    public static function getSubscriptionAbbr(string $subscription): string
    {
        return strtoupper(substr($subscription, 0, 3));
    }
    
    /**
     * Truncate display name
     */
    public static function truncateDisplayName(string $name, int $maxLength = 10): string
    {
        return substr($name, 0, $maxLength);
    }
    
    /**
     * Get formatted time
     */
    public static function getFormattedTime(): string
    {
        return date('H:i:s');
    }
    
    /**
     * Get uptime text
     */
    public static function getUptimeText(): string
    {
        return "UPTIME: " . self::getFormattedTime();
    }
}
