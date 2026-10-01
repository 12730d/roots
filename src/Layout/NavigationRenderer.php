<?php
declare(strict_types=1);

namespace ROOTS\Layout;

use ROOTS\Layout\LayoutConfig;

/**
 * NavigationRenderer - Handles the HTML rendering of navigation menus and dropdowns
 * Works in conjunction with NavigationDataProvider to display the application's navigation structure
 */
class NavigationRenderer
{
    /**
     * Render a single dropdown item HTML content (without <li> wrapper)
     * @param array $item Item data
     * @param string $base_path Base path for links
     * @param bool $hasSubscription Whether user has an active subscription
     * @param string $lockMessage Message to show for locked items
     * @return string HTML content
     */
    /**
     * @param array<string, mixed> $item
     */
    private static function renderDropdownItemContent(
        array $item,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage
    ): string {
        if (!($item["requires_premium"] ?? false) || $hasSubscription) {
            $html = '<a href="' . LayoutHelpers::sanitize(
                            LayoutHelpers::normalizeBasePath($base_path) . $item["href"],
                        ) . '" class="' . LayoutConfig::CLASS_DROPDOWN_ITEM . '">';
            if (isset($item["icon"])) {
                $html .= '<i class="' . LayoutHelpers::sanitize($item["icon"]) . '"></i>';
            }
            $html .= LayoutHelpers::sanitize($item["label"]) . '</a>';
        } else {
            $html = '<button type="button" class="' . LayoutConfig::CLASS_DROPDOWN_ITEM . ' ' . LayoutConfig::CLASS_LOCKED_ITEM . '" data-lock-message="' . LayoutHelpers::sanitize(json_encode($lockMessage) ?: '') . '" title="' . LayoutHelpers::sanitize($lockMessage) . '">';
            $html .= '<i class="' . LayoutConfig::ICON_LOCK . '"></i>' . LayoutHelpers::sanitize($item["label"]) . '</button>';
        }
        return $html;
    }

    /**
     * Render a single dropdown item with <li> wrapper
     * @param array<string, mixed> $item
     */
    private static function renderDropdownItem(
        array $item,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage
    ): string {
        return '<li>' . self::renderDropdownItemContent($item, $base_path, $hasSubscription, $lockMessage) . '</li>';
    }

    /**
     * Render main navigation items
     * @param array<int, array<string, mixed>> $mainNavItems
     */
    private static function renderMainNavItems(array $mainNavItems, string $base_path): void
    {
        $i = 1;
        foreach ($mainNavItems as $item): ?>
<li class="<?= LayoutConfig::CLASS_NAV_ITEM ?> nav-item-seq-<?= $i++ ?>">
    <a href="<?= LayoutHelpers::sanitize(
                            LayoutHelpers::normalizeBasePath($base_path) . $item["href"],
                        ) ?>" class="<?= LayoutConfig::CLASS_NAV_LINK ?>">
        <i class="<?= LayoutHelpers::sanitize($item["icon"]) ?>"></i><?= LayoutHelpers::sanitize($item["label"]) ?>
    </a>
</li>
<?php endforeach;
    }

    /**
     * Render Store Data dropdown
     * @param array<int, array<string, mixed>> $storeDataLinks
     */
    private static function renderStoreDataDropdown(
        array $storeDataLinks,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage
    ): void {
        ?>
<!-- Store Data Dropdown -->
<li class="<?= LayoutConfig::CLASS_NAV_ITEM ?> <?= LayoutConfig::CLASS_DROPDOWN ?> nav-item-seq-5">
    <button type="button" class="<?= LayoutConfig::CLASS_NAV_LINK ?> <?= LayoutConfig::CLASS_DROPDOWN_TRIGGER ?>" aria-expanded="false"
        aria-label="Toggle Store Data menu">
        <i class="<?= LayoutConfig::ICON_DATABASE ?> me-2"></i>Store Data
        <span class="bc-dropdown__arrow"><i class="<?= LayoutConfig::ICON_CHEVRON_DOWN ?>"></i></span>
    </button>
    <ul class="<?= LayoutConfig::CLASS_DROPDOWN_MENU ?>" aria-hidden="true">
        <?php foreach ($storeDataLinks as $storeItem):
                echo self::renderDropdownItem($storeItem, $base_path, $hasSubscription, $lockMessage);
                endforeach; ?>
    </ul>
</li>
<?php
    }

    /**
     * Render VIP dropdown with nested items
     * @param array<int, array<string, mixed>> $vipLinks
     */
    private static function renderVipDropdown(
        array $vipLinks,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage
    ): void {
        ?>
<!-- VIP Links Dropdown -->
<li class="<?= LayoutConfig::CLASS_NAV_ITEM ?> <?= LayoutConfig::CLASS_DROPDOWN ?> nav-item-seq-6">
    <button type="button" class="<?= LayoutConfig::CLASS_NAV_LINK ?> <?= LayoutConfig::CLASS_DROPDOWN_TRIGGER ?>" aria-expanded="false" aria-label="Toggle VIP menu">
        <i class="<?= LayoutConfig::ICON_CROWN ?> me-2"></i>VIP
        <span class="bc-dropdown__arrow"><i class="<?= LayoutConfig::ICON_CHEVRON_DOWN ?>"></i></span>
    </button>
    <ul class="<?= LayoutConfig::CLASS_DROPDOWN_MENU ?>" aria-hidden="true">
        <?php foreach ($vipLinks as $vipItem):
                if (isset($vipItem["children"])): ?>
        <li class="<?= LayoutConfig::CLASS_DROPDOWN_SUBMENU_WRAP ?>">
            <button type="button" class="<?= LayoutConfig::CLASS_DROPDOWN_ITEM ?> <?= LayoutConfig::CLASS_DROPDOWN_SUBMENU_TRIGGER ?>" aria-expanded="false">
                <?php if (isset($vipItem["icon"])): ?>
                <i class="<?= LayoutHelpers::sanitize($vipItem["icon"]) ?>"></i>
                <?php endif; ?>
                <?= LayoutHelpers::sanitize($vipItem["label"]) ?>
                <span class="bc-dropdown__arrow"><i class="<?= LayoutConfig::ICON_CHEVRON_RIGHT ?>"></i></span>
            </button>
            <ul class="<?= LayoutConfig::CLASS_DROPDOWN_MENU ?> <?= LayoutConfig::CLASS_DROPDOWN_SUBMENU ?>">
                <?php foreach ($vipItem["children"] as $child):
                        echo self::renderDropdownItem($child, $base_path, $hasSubscription, $lockMessage);
                        endforeach; ?>
            </ul>
        </li>
        <?php else:
                echo self::renderDropdownItem($vipItem, $base_path, $hasSubscription, $lockMessage);
                endif;
                endforeach; ?>
    </ul>
</li>
<?php
    }

    /**
     * Render Password dropdown
     * @param array<int, array<string, mixed>> $passwordLinks
     */
    private static function renderPasswordDropdown(
        array $passwordLinks,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage
    ): void {
        ?>
<!-- Password Links Dropdown -->
<li class="<?= LayoutConfig::CLASS_NAV_ITEM ?> <?= LayoutConfig::CLASS_DROPDOWN ?> nav-item-seq-7">
    <button type="button" class="<?= LayoutConfig::CLASS_NAV_LINK ?> <?= LayoutConfig::CLASS_DROPDOWN_TRIGGER ?>" aria-expanded="false"
        aria-label="Toggle Password menu">
        <i class="<?= LayoutConfig::ICON_KEY ?> me-2"></i>Password
        <span class="bc-dropdown__arrow"><i class="<?= LayoutConfig::ICON_CHEVRON_DOWN ?>"></i></span>
    </button>
    <ul class="<?= LayoutConfig::CLASS_DROPDOWN_MENU ?>" aria-hidden="true">
        <?php foreach ($passwordLinks as $passItem):
                if (isset($passItem["children"])): ?>
        <li class="<?= LayoutConfig::CLASS_DROPDOWN_SUBMENU_WRAP ?>">
            <button type="button" class="<?= LayoutConfig::CLASS_DROPDOWN_ITEM ?> <?= LayoutConfig::CLASS_DROPDOWN_SUBMENU_TRIGGER ?>" aria-expanded="false">
                <?php if (isset($passItem["icon"])): ?>
                <i class="<?= LayoutHelpers::sanitize($passItem["icon"]) ?>"></i>
                <?php endif; ?>
                <?= LayoutHelpers::sanitize($passItem["label"]) ?>
                <span class="bc-dropdown__arrow"><i class="<?= LayoutConfig::ICON_CHEVRON_RIGHT ?>"></i></span>
            </button>
            <ul class="<?= LayoutConfig::CLASS_DROPDOWN_MENU ?> <?= LayoutConfig::CLASS_DROPDOWN_SUBMENU ?>">
                <?php foreach ($passItem["children"] as $child):
                        echo self::renderDropdownItem($child, $base_path, $hasSubscription, $lockMessage);
                        endforeach; ?>
            </ul>
        </li>
        <?php else:
                echo self::renderDropdownItem($passItem, $base_path, $hasSubscription, $lockMessage);
                endif;
                endforeach; ?>
    </ul>
</li>
<?php
    }

    /**
     * Render Search DB dropdown
     * @param array<int, array<string, mixed>> $searchDbLinks
     */
    private static function renderSearchDbDropdown(
        array $searchDbLinks,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage
    ): void {
        ?>
<!-- Search DB Dropdown -->
<li class="<?= LayoutConfig::CLASS_NAV_ITEM ?> <?= LayoutConfig::CLASS_DROPDOWN ?> nav-item-seq-8">
    <button type="button" class="<?= LayoutConfig::CLASS_NAV_LINK ?> <?= LayoutConfig::CLASS_DROPDOWN_TRIGGER ?>" aria-expanded="false"
        aria-label="Toggle Search DB menu">
        <i class="<?= LayoutConfig::ICON_SEARCH ?> me-2"></i>Search DB
        <span class="bc-dropdown__arrow"><i class="<?= LayoutConfig::ICON_CHEVRON_DOWN ?>"></i></span>
    </button>
    <ul class="<?= LayoutConfig::CLASS_DROPDOWN_MENU ?>" aria-hidden="true">
        <?php foreach ($searchDbLinks as $searchItem):
                echo self::renderDropdownItem($searchItem, $base_path, $hasSubscription, $lockMessage);
                endforeach; ?>
    </ul>
</li>
<?php
    }

    /**
     * Render Drone API dropdown
     * @param array<int, array<string, mixed>> $droneApiLinks
     */
    private static function renderDroneApiDropdown(
        array $droneApiLinks,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage
    ): void {
        ?>
<!-- Drone API Dropdown -->
<li class="bc-nav__item bc-dropdown nav-item-seq-9">
    <button type="button" class="bc-nav__link bc-dropdown__trigger" aria-expanded="false"
        aria-label="Toggle Drone API menu">
        <i class="fas fa-drone me-2"></i>Drone API
        <span class="bc-dropdown__arrow"><i class="fas fa-chevron-down"></i></span>
    </button>
    <ul class="bc-dropdown__menu" aria-hidden="true">
        <?php foreach ($droneApiLinks as $droneItem):
                if (isset($droneItem["children"])): ?>
        <li class="bc-dropdown__submenu-wrap">
            <button type="button" class="bc-dropdown__item bc-dropdown__submenu-trigger" aria-expanded="false">
                <?php if (isset($droneItem["icon"])): ?>
                <i class="<?= LayoutHelpers::sanitize($droneItem["icon"]) ?>"></i>
                <?php endif; ?>
                <?= LayoutHelpers::sanitize($droneItem["label"]) ?>
                <span class="bc-dropdown__arrow"><i class="fas fa-chevron-right"></i></span>
            </button>
            <ul class="bc-dropdown__menu bc-dropdown__submenu">
                <?php foreach ($droneItem["children"] as $child):
                        echo self::renderDropdownItem($child, $base_path, $hasSubscription, $lockMessage);
                        endforeach; ?>
            </ul>
        </li>
        <?php else:
                echo self::renderDropdownItem($droneItem, $base_path, $hasSubscription, $lockMessage);
                endif;
                endforeach; ?>
    </ul>
</li>
<?php
    }

    /**
     * Render the main navigation menu
     * @param array<string, mixed> $menuData
     */
    public static function renderMainNavigationMenu(
        string $base_path,
        array $menuData,
        bool $hasSubscription,
        string $lockMessage,
        string $lockMsgConst
    ): void {
        ?>
<script nonce="<?php echo \ROOTS\Middleware\SecurityHeadersMiddleware::getNonce(); ?>">
// Set lock message for external JavaScript
window.LOCK_MSG = <?= json_encode($lockMsgConst) ?>;
</script>
<div class="bc-nav__menu">
    <div class="bc-nav__scroll-wrapper">
        <button type="button" class="bc-nav__scroll-btn bc-nav__scroll-btn--left" aria-label="Scroll left">
            <i class="fas fa-chevron-left"></i>
        </button>

        <div class="bc-nav__scroll-container">
            <ul class="bc-nav__list">
                <?php self::renderMainNavItems($menuData["mainNavItems"], $base_path); ?>
                <?php self::renderStoreDataDropdown($menuData["storeDataLinks"], $base_path, $hasSubscription, $lockMessage); ?>
                <?php self::renderVipDropdown($menuData["vipLinks"], $base_path, $hasSubscription, $lockMessage); ?>
                <?php self::renderPasswordDropdown($menuData["passwordLinks"], $base_path, $hasSubscription, $lockMessage); ?>
                <?php self::renderSearchDbDropdown($menuData["searchDbLinks"], $base_path, $hasSubscription, $lockMessage); ?>
                <?php self::renderDroneApiDropdown($menuData["droneApiLinks"], $base_path, $hasSubscription, $lockMessage); ?>
            </ul>
        </div>

        <button type="button" class="bc-nav__scroll-btn bc-nav__scroll-btn--right" aria-label="Scroll right">
            <i class="fas fa-chevron-right"></i>
        </button>
    </div>
</div>
<?php
    }

    /**
     * Render NavigationRenderer CSS styles
     */
    public static function renderStyles(): void
    {
        ?>
<style>
/* Navigation Renderer - Scroll & Responsive Logic */
.bc-nav__menu {
    flex: 1;
    margin-left: 0;
    margin-right: 0;
    height: 80%;
    display: flex;
    align-items: center;
    min-width: 0;
    position: relative;
    overflow: visible;
    /* Ensure dropdowns are not clipped by menu container */
}

.bc-nav__scroll-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
    height: 80%;
    padding: 0; /* Removed padding to maximize space for buttons */
    overflow: visible;
    z-index: 1;
}

.bc-nav__list {
    display: flex;
    align-items: center;
    list-style: none;
    gap: 4px;
    height: 80%;
    margin: 0;
    padding: 0;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: transform;
}

.bc-nav__item {
    position: relative;
    display: flex;
    align-items: center;
    height: 80%;
    flex-shrink: 0;
}

.bc-dropdown {
    position: relative;
    height: 80%;
    display: flex;
    align-items: center;
}

/* Scroll Buttons - Absolute positioning within menu */
.bc-nav__scroll-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(10, 20, 10, 0.9);
    border: 1px solid var(--bc-accent);
    color: var(--bc-accent);
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 1125;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    box-shadow: 0 0 15px rgba(46, 204, 113, 0.3);
}

.bc-nav__scroll-btn--left {
    left: 0;
}

.bc-nav__scroll-btn--right {
    right: 0;
}

.bc-nav__scroll-btn.is-visible {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}

.bc-nav__scroll-btn:hover {
    background: rgba(46, 204, 113, 0.2);
    border-color: var(--bc-accent);
    color: #fff;
}

.bc-nav__scroll-container {
    width: 100%;
    overflow-x: clip;
    overflow-y: visible;
}

@media (max-width: 1280px) {
    .bc-nav__scroll-btn {
        display: none !important;
    }
}

@media (max-width: 1100px) {
    .bc-nav__menu:not(.is-open) {
        display: none !important;
    }
}

.bc-nav__link {
    display: flex;
    align-items: center;
    padding: 0 8px;
    height: 100%;
    color: var(--bc-text);
    text-decoration: none;
    border-radius: var(--bc-radius-sm);
    font-size: 0.81rem;
    white-space: nowrap;
}

.bc-nav__link i {
    font-size: 0.85rem;
    margin-right: 5px;
}

.bc-nav__link:hover,
.bc-nav__link[aria-expanded="true"],
.bc-dropdown:hover>.bc-nav__link {
    background: rgba(46, 204, 113, 0.1);
    color: var(--bc-accent);
}

.bc-dropdown__trigger {
    background: transparent;
    border: 0;
    cursor: pointer;
    font-family: inherit;
}

.bc-dropdown__menu {
    position: absolute;
    top: 100%;
    left: 0;
    min-width: 220px;
    background: rgba(10, 20, 10, 0.98);
    border: 1px solid var(--bc-border-accent);
    border-radius: var(--bc-radius-lg);
    padding: 8px 0;
    margin: 0;
    list-style: none;
    box-shadow: var(--bc-shadow-lg);
    opacity: 0;
    visibility: hidden;
    transform: translateY(10px);
    transition: opacity var(--bc-transition-base), transform var(--bc-transition-base), visibility var(--bc-transition-base);
    z-index: 9999;
    pointer-events: none;
}

/* Keep right-edge dropdowns inside viewport */
.bc-nav__item.nav-item-seq-5 > .bc-dropdown__menu,
.bc-nav__item.nav-item-seq-6 > .bc-dropdown__menu,
.bc-nav__item.nav-item-seq-7 > .bc-dropdown__menu,
.bc-nav__item.nav-item-seq-8 > .bc-dropdown__menu,
.bc-nav__item.nav-item-seq-9 > .bc-dropdown__menu {
    left: auto;
    right: 0;
}

/* Nested submenus for right-edge dropdowns should open to the left */
.bc-nav__item.nav-item-seq-5 .bc-dropdown__submenu,
.bc-nav__item.nav-item-seq-6 .bc-dropdown__submenu,
.bc-nav__item.nav-item-seq-7 .bc-dropdown__submenu,
.bc-nav__item.nav-item-seq-8 .bc-dropdown__submenu,
.bc-nav__item.nav-item-seq-9 .bc-dropdown__submenu {
    left: auto;
    right: 100%;
    margin: 0 -2px 0 0;
    transform: translateX(-10px);
}

/* Hover bridge prevents losing :hover while moving pointer into the panel */
.bc-dropdown::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 0;
    width: 100%;
    height: 12px;
    display: none;
    z-index: 10;
}

.bc-dropdown:hover::after {
    display: block;
}

/* Show menu on hover (Desktop) or when is-open class is added (JS) */
.bc-dropdown:hover>.bc-dropdown__menu,
.bc-dropdown__menu.is-open,
.bc-dropdown__menu[aria-hidden="false"] {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    pointer-events: auto;
    /* Enable interaction when shown */
}

.bc-dropdown__item {
    display: flex;
    align-items: center;
    padding: 10px 16px;
    color: var(--bc-text);
    text-decoration: none;
    transition: all var(--bc-transition-fast);
    font-size: 0.85rem;
    white-space: nowrap;
    width: 100%;
    text-align: left;
    background: transparent;
    border: 0;
    cursor: pointer;
    gap: 8px;
}

.bc-dropdown__item:hover,
.bc-dropdown__item[aria-expanded="true"],
.bc-dropdown__submenu-wrap:hover>.bc-dropdown__item {
    background: rgba(46, 204, 113, 0.1);
    color: var(--bc-accent);
}

.bc-dropdown__item--disabled,
.bc-locked-item {
    opacity: 0.6;
    cursor: pointer;
    /* Keep pointer so users know they can click for info */
}

.bc-locked-item i.fa-lock {
    color: #e74c3c;
}

.bc-dropdown__submenu-wrap {
    position: relative;
}

/* Submenu Positioning and Logic */
.bc-dropdown__submenu {
    position: absolute;
    top: 0;
    left: 100%;
    min-width: 220px;
    background: rgba(10, 20, 10, 0.98);
    border: 1px solid var(--bc-border-accent);
    border-radius: var(--bc-radius-lg);
    padding: 8px 0;
    margin: 0 0 0 -2px;
    /* Slight overlap to prevent gap during mouse movement */
    list-style: none;
    box-shadow: var(--bc-shadow-lg);
    opacity: 0;
    visibility: hidden;
    transform: translateX(10px);
    transition: opacity var(--bc-transition-base), transform var(--bc-transition-base), visibility var(--bc-transition-base);
    z-index: var(--bc-z-dropdown);
    pointer-events: none;
}

/* Show submenu on hover or via JS */
.bc-dropdown__submenu-wrap:hover>.bc-dropdown__submenu,
.bc-dropdown__submenu-wrap.is-open>.bc-dropdown__submenu {
    opacity: 1;
    visibility: visible;
    transform: translateX(0);
    pointer-events: auto;
}

.bc-dropdown__arrow {
    font-size: 0.7rem;
    margin-left: auto;
    padding-left: 8px;
}

/* Removed arrow rotation animation */
.bc-dropdown__trigger[aria-expanded="true"] .bc-dropdown__arrow,
.bc-dropdown:hover .bc-dropdown__arrow,
.bc-dropdown__submenu-trigger[aria-expanded="true"] .bc-dropdown__arrow,
.bc-dropdown__submenu-wrap:hover .bc-dropdown__arrow {
    transform: none;
}

/* Arrow styling for dropdown items */
.bc-dropdown__item .bc-dropdown__arrow {
    margin-left: auto;
}

/* Sidebar View Logic - Synchronized with MasterLayout (1100px) */
@media (max-width: 1100px) {
    .bc-nav__menu:not(.is-open) .bc-nav__item {
        display: none !important;
    }

    /* Items in Sidebar */
    .bc-nav__menu.is-open .bc-nav__item {
        display: flex !important;
        width: 100%;
        height: auto;
    }

    .bc-nav__list {
        flex-direction: column;
        align-items: stretch;
        padding: 16px;
        height: auto;
        overflow: visible;
        /* Disable horizontal scroll in sidebar */
        transform: none !important;
        /* Force reset translate in sidebar */
    }

    .bc-nav__scroll-wrapper {
        display: block;
        height: auto;
        overflow: visible;
    }

    .bc-nav__scroll-container {
        overflow: visible;
    }

    /* Dropdown and Submenu behavior in Sidebar */
    .bc-dropdown__menu {
        position: static;
        opacity: 1;
        visibility: visible;
        transform: none !important;
        box-shadow: none;
        border: none;
        background: transparent;
        padding-left: 16px;
        display: none;
        pointer-events: auto;
    }

    .bc-dropdown__menu.is-open {
        display: block;
    }

    .bc-dropdown__submenu {
        position: static;
        opacity: 1;
        visibility: visible;
        transform: none !important;
        box-shadow: none;
        border: none;
        background: transparent;
        padding-left: 16px;
        display: none;
        pointer-events: auto;
    }

    .bc-dropdown__submenu-wrap.is-open>.bc-dropdown__submenu {
        display: block;
    }


}
</style>
<?php
    }
}