<?php
declare(strict_types=1);

namespace ROOTS\Layout;

use ROOTS\Layout\LayoutHelpers;
use ROOTS\Layout\MasterLayout;

/**
 * NavProfile - Handles user profile dropdown and navigation profile components
 */
class NavProfile
{
    /**
     * Render user profile dropdown
     */
    public static function renderUserProfileDropdown(
        string $base_path,
        string $user_avatar,
        string $username,
        string $subscription
    ): void {
        ?>
        <style>
            /* User Badge styling for subscription */
            .bc-user__badge {
                position: absolute;
                bottom: -2px;
                right: -6px;
                background: var(--bc-accent);
                color: #0A1410;
                font-size: 0.55rem;
                font-weight: 800;
                padding: 2px 4px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 2px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
            }

            .bc-user__badge i {
                font-size: 0.4rem;
            }

            .bc-user__avatar-wrap {
                position: relative;
            }
        </style>
        <div class="bc-user">
            <button type="button" class="bc-user__trigger" aria-expanded="false" aria-label="Toggle user menu">
                <div class="bc-user__avatar-wrap">
                    <div class="bc-user__scan-bar"></div>
                    <img src="<?= LayoutHelpers::sanitize($user_avatar) ?>" alt="<?= LayoutHelpers::sanitize($username) ?>"
                        class="bc-user__avatar">
                </div>
            </button>
            <div class="bc-user__menu" aria-hidden="true">
                <div class="bc-user__menu-header">
                    <span class="bc-user__menu-title">USER_PROFILE</span>
                </div>
                <div class="bc-user__menu-content">
                    <?php self::renderUserProfileMenu($base_path, $subscription); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render user profile menu items
     */
    private static function renderUserProfileMenu(string $base_path, string $subscription): void
    {
        ?>
        <a href="<?= self::formatUrl($base_path, 'profile') ?>" class="bc-user__item">
            <i class="fas fa-user me-2"></i>Profile
        </a>

        <a href="<?= self::formatUrl($base_path, 'plans') ?>" class="bc-user__item">
            <i class="fas fa-crown me-2"></i>Subscription
        </a>

        <div class="bc-user__divider"></div>
        <a href="<?= self::formatUrl($base_path, 'logout') ?>" class="bc-user__item">
            <i class="fas fa-sign-out-alt me-2"></i>Logout
        </a>
        <?php
    }

    /**
     * Build a safe menu URL
     */
    private static function formatUrl(string $base_path, string $href): string
    {
        return LayoutHelpers::sanitize(LayoutHelpers::normalizeBasePath($base_path) . $href);
    }

    /**
     * Format a JSON lock message so it is safe for HTML attributes
     */
    private static function formatLockMessageAttribute(string $message): string
    {
        $encoded = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return LayoutHelpers::sanitize($encoded ?: '');
    }

    /**
     * Render dropdown menu link
     * @param array<string, mixed> $item
     */
    public static function renderDropdownMenuLink(
        array $item,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage,
        ?string $subscriptionLockMessage = null
    ): void {
        $requires_premium = $item["requires_premium"] ?? false;
        $lock_msg = $subscriptionLockMessage ?? $lockMessage;

        if (!$requires_premium || $hasSubscription): ?>
            <a href="<?= self::formatUrl($base_path, (string) ($item["href"] ?? '')) ?>" class="bc-user__item">
                <?php if (!empty($item["icon"])): ?>
                    <i class="<?= LayoutHelpers::sanitize((string) $item["icon"]) ?>" style="width: 20px; text-align: center;"></i>
                <?php endif; ?>
                <span><?= LayoutHelpers::sanitize((string) ($item["label"] ?? '')) ?></span>
            </a>
        <?php else: ?>
            <button type="button" class="bc-user__item bc-locked-item"
                data-lock-message="<?= self::formatLockMessageAttribute($lock_msg) ?>"
                title="<?= LayoutHelpers::sanitize($lock_msg) ?>">
                <?php if (!empty($item["icon"])): ?>
                    <i class="<?= LayoutHelpers::sanitize((string) $item["icon"]) ?>" style="width: 20px; text-align: center;"></i>
                <?php endif; ?>
                <span><?= LayoutHelpers::sanitize((string) ($item["label"] ?? '')) ?></span>
            </button>
        <?php endif;
    }

    /**
     * Render admin menu
     */
    public static function renderAdminMenu(string $base_path): void
    {
        $adminSections = NavigationDataProvider::getAdminMenuData();
        ?>
        <div class="bc-admin-menu bc-user">
            <button type="button" class="bc-user__trigger bc-admin-trigger" aria-expanded="false" aria-label="Admin menu">
                <i class="fas fa-shield-alt"></i>
            </button>
            <div class="bc-user__menu bc-admin-dropdown" aria-hidden="true">
                <div class="bc-user__menu-header">
                    <span class="bc-user__menu-title">ADMIN_TERMINAL</span>
                </div>
                <div class="bc-user__menu-content">
                    <?php foreach ($adminSections as $sectionIndex => $section): ?>
                        <?php if ($sectionIndex > 0): ?>
                            <div class="bc-user__divider"></div>
                        <?php endif; ?>

                        <div class="bc-admin-section-title">
                            <?= LayoutHelpers::sanitize($section["title"]) ?>
                        </div>

                        <?php foreach ($section["links"] as $link): ?>
                            <a href="<?= LayoutHelpers::sanitize(LayoutHelpers::normalizeBasePath($base_path) . $link["href"]) ?>"
                                class="bc-user__item">
                                <i class="<?= LayoutHelpers::sanitize($link["icon"]) ?>"></i>
                                <span><?= LayoutHelpers::sanitize($link["label"]) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render dropdown items with support for submenus
     */
    /**
     * Render dropdown items with support for submenus
     * @param array<int, array<string, mixed>> $items
     */
    public static function renderDropdownMenuItems(
        array $items,
        string $base_path,
        bool $hasSubscription,
        string $lockMessage,
        ?string $subscriptionLockMessage = null
    ): void {
        foreach ($items as $item) {
            if (!empty($item["children"]) && is_array($item["children"])): ?>
                <div class="bc-dropdown__submenu-wrap">
                    <button type="button" class="bc-user__item bc-dropdown__submenu-trigger" aria-expanded="false" aria-haspopup="true"
                        aria-label="Toggle <?= LayoutHelpers::sanitize($item["label"] ?? "submenu") ?>">
                        <i class="<?= LayoutHelpers::sanitize($item["icon"] ?? "fas fa-folder me-2") ?>"
                            style="width: 20px; text-align: center;"></i>
                        <span><?= LayoutHelpers::sanitize($item["label"] ?? "") ?></span>
                    </button>
                    <div class="bc-dropdown__submenu" aria-hidden="true">
                        <?php foreach ($item["children"] as $child) {
                            self::renderDropdownMenuLink(
                                $child,
                                $base_path,
                                $hasSubscription,
                                $lockMessage,
                                $subscriptionLockMessage,
                            );
                        } ?>
                    </div>
                </div>
                <?php continue;
            endif;

            self::renderDropdownMenuLink(
                $item,
                $base_path,
                $hasSubscription,
                $lockMessage,
                $subscriptionLockMessage,
            );
        }
    }

    /**
     * Render NavProfile CSS styles
     */
    public static function renderStyles(): void
    {
        ?>
        <style>
            /* User Profile Component Styles */
            .bc-user {
                position: relative;
                height: 100%;
                display: flex;
                align-items: center;
            }

            .bc-user__trigger {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: transparent;
                border: 0;
                padding: 0;
                cursor: pointer;
                color: var(--bc-text);
                width: clamp(34px, 8vw, 38px);
                height: clamp(34px, 8vw, 38px);
                border-radius: var(--bc-radius-full);
                transition: transform var(--bc-transition-base);
            }

          

            /* Hover bridge to prevent losing focus during mouse movement */
            .bc-user::after {
                content: "";
                position: absolute;
                top: 100%;
                right: 0;
                width: 100%;
                height: 12px;
                display: none;
                z-index: 10;
            }

            .bc-user:hover::after {
                display: block;
            }

            .bc-user__avatar-wrap {
                width: clamp(34px, 8vw, 38px);
                height: clamp(34px, 8vw, 38px);
                border-radius: 0;
                overflow: hidden;
                border: 1px solid rgba(46, 204, 113, 0.4);
                background: rgba(46, 204, 113, 0.05);
                transition: transform var(--bc-transition-fast), border-color 0.2s;
                position: relative;
            }

            .bc-user__trigger:hover .bc-user__avatar-wrap {
                transform: scale(1.05);
                border-color: var(--bc-accent);
            }

            .bc-user__scan-bar {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 3px;
                background: var(--bc-accent, #2ecc71);
                box-shadow: 0 0 5px var(--bc-accent, #2ecc71);
                z-index: 5;
                animation: nav-scan-vertical 3s ease-in-out infinite;
                opacity: 0.8;
            }

            @keyframes nav-scan-vertical {
                0% {
                    top: 0%;
                    opacity: 0;
                }

                10% {
                    opacity: 1;
                }

                90% {
                    opacity: 1;
                }

                100% {
                    top: 100%;
                    opacity: 0;
                }
            }

            .bc-user__avatar {
                width: 100%;
                height: 100%;
                object-fit: cover;
                filter: grayscale(100%) contrast(1.2) brightness(0.9) sepia(1) hue-rotate(60deg) saturate(4);
            }

            .bc-user__info {
                display: flex;
                flex-direction: column;
                margin-left: 10px;
                text-align: left;
            }

            .bc-user__name {
                font-size: 0.85rem;
                font-weight: 600;
                color: var(--bc-text);
                line-height: 1.2;
            }

            .bc-user__sub {
                font-size: 0.75rem;
                color: var(--bc-text-muted);
            }

            .bc-user__arrow {
                font-size: 0.7rem;
                color: var(--bc-accent);
                margin-left: 8px;
                transition: transform 0.2s ease;
            }

            .bc-user__trigger[aria-expanded="true"] .bc-user__arrow,
            .bc-user:hover .bc-user__arrow {
                transform: rotate(180deg);
            }

            .bc-user__menu {
                position: absolute;
                top: 100%;
                /* Contiguous for hover */
                right: 0;
                min-width: 200px;
                background: rgba(10, 20, 10, 0.96);
                border: 1px solid var(--bc-border-accent);
                border-radius: var(--bc-radius-xl);
                padding: 0;
                margin: 0;
                list-style: none;
                box-shadow: var(--bc-shadow-lg);
                opacity: 0;
                visibility: hidden;
                transform: translateY(10px);
                transition: opacity var(--bc-transition-base), transform var(--bc-transition-base), visibility var(--bc-transition-base);
                z-index: var(--bc-z-dropdown);
                overflow: hidden;
                pointer-events: none;
            }

            .bc-user__menu.is-open,
            .bc-user:hover .bc-user__menu {
                opacity: 1;
                visibility: visible;
                transform: translateY(0);
                pointer-events: auto;
            }

            .bc-user__menu-header {
                background: rgba(46, 204, 113, 0.05);
                padding: 8px 16px;
                border-bottom: 1px solid var(--bc-border);
            }

            .bc-user__menu-title {
                font-size: 0.65rem;
                font-weight: 800;
                color: var(--bc-accent);
                letter-spacing: 2px;
                font-family: 'Courier New', monospace;
            }

            .bc-user__menu-content {
                padding: 8px 0;
            }

            .bc-user__item {
                display: flex;
                align-items: center;
                padding: 8px 12px;
                color: var(--bc-text);
                text-decoration: none;
                transition: all var(--bc-transition-base);
                font-size: 0.82rem;
            }

            .bc-user__item:hover,
            .bc-user__item[aria-expanded="true"] {
                background: rgba(46, 204, 113, 0.08);
                color: var(--bc-accent);
            }

            .bc-user__item--disabled,
            .bc-locked-item {
                opacity: 0.6;
                cursor: pointer;
                background: transparent;
                border: 0;
                width: 100%;
                text-align: left;
            }

            .bc-locked-item i.fa-lock {
                color: #e74c3c;
            }

            .bc-user__divider {
                height: 1px;
                background: var(--bc-border);
                margin: 8px 0;
            }

            .bc-btn--admin {
                background: rgba(46, 204, 113, 0.1);
                border: 1px solid var(--bc-accent);
                color: var(--bc-accent);
                padding: 6px 12px;
                border-radius: var(--bc-radius-sm);
                font-size: 0.8rem;
                transition: all var(--bc-transition-base);
                text-decoration: none;
                display: inline-flex;
                align-items: center;
            }

            .bc-btn--admin:hover {
                background: rgba(46, 204, 113, 0.2);
            }

            .bc-admin-trigger {
                width: clamp(34px, 8vw, 36px);
                height: clamp(34px, 8vw, 36px);
                border-radius: 50% !important;
                border: none !important;
                background: transparent !important;
                color: var(--bc-accent) !important;
                justify-content: center;
                transition: all var(--bc-transition-fast);
            }

            .bc-admin-trigger:hover,
            .bc-admin-trigger[aria-expanded="true"] {
                background: rgba(46, 204, 113, 0.1) !important;
                color: #fff !important;
            }

            .bc-admin-section-title {
                font-size: 0.65rem;
                text-transform: uppercase;
                color: var(--bc-text-muted);
                padding: 8px 16px 4px;
                letter-spacing: 1px;
                font-weight: 700;
            }

            .bc-admin-dropdown {
                right: 0;
                left: auto;
                min-width: 240px;
            }

            .bc-admin-menu {
                display: flex;
                align-items: center;
                height: 100%;
            }

            .bc-admin-menu::after {
                content: "";
                position: absolute;
                top: 100%;
                right: 0;
                width: 100%;
                height: 12px;
                display: none;
                z-index: 10;
            }

            .bc-admin-menu:hover::after {
                display: block;
            }

            .bc-admin-menu:hover .bc-admin-dropdown {
                opacity: 1;
                visibility: visible;
                transform: translateY(0);
                pointer-events: auto;
            }

            .bc-dropdown__submenu-wrap {
                position: relative;
            }

            .bc-dropdown__submenu-trigger {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: transparent;
                border: 0;
                cursor: pointer;
                color: var(--bc-text);
            }

            .bc-dropdown__submenu {
                position: absolute;
                top: 0;
                right: 100%;
                min-width: 180px;
                background: rgba(10, 20, 10, 0.98);
                border: 1px solid var(--bc-border-accent);
                border-radius: var(--bc-radius-md);
                padding: 8px 0;
                margin: 0 -2px 0 0;
                /* Slight overlap to prevent gap */
                list-style: none;
                box-shadow: var(--bc-shadow-lg);
                opacity: 0;
                visibility: hidden;
                transform: translateX(-10px);
                transition: opacity var(--bc-transition-base), transform var(--bc-transition-base), visibility var(--bc-transition-base);
                z-index: var(--bc-z-dropdown);
                overflow: hidden;
                pointer-events: none;
            }

            .bc-dropdown__submenu-wrap.is-open .bc-dropdown__submenu,
            .bc-dropdown__submenu-wrap:hover .bc-dropdown__submenu {
                opacity: 1;
                visibility: visible;
                transform: translateX(0);
                pointer-events: auto;
            }

            @media (max-width: 991px) {
                .bc-user__info {
                    display: none;
                }

                .bc-user__arrow {
                    display: none;
                }

                .bc-user__menu {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    top: auto;
                    min-width: 260px;
                }

                /* Disable hover on mobile for consistency */
                .bc-user:hover .bc-user__menu {
                    opacity: 0;
                    visibility: hidden;
                    transform: translateY(10px);
                }

                .bc-user__menu.is-open {
                    opacity: 1;
                    visibility: visible;
                    transform: translateY(0);
                }
            }

            .bc-dropdown__submenu-chevron {
                transition: transform 0.2s ease;
            }

            .bc-dropdown__submenu-wrap.is-open .bc-dropdown__submenu-chevron {
                transform: rotate(-90deg);
            }
        </style>
        <?php
    }
}