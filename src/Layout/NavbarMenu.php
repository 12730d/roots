<?php

namespace ROOTS\Layout;

/**
 * NavbarMenu - Renders a simplified navigation menu
 * Useful for secondary pages or minimal layouts
 */
class NavbarMenu
{
    /**
     * Render the simplified navigation menu
     * @param string $base_path Base path for links
     */
    public static function render(string $base_path = ""): void
    {
        $base_path = LayoutHelpers::normalizeBasePath($base_path);
        ?>
<ul class="bc-nav__list">
    <li class="bc-nav__item">
        <a href="<?= LayoutHelpers::sanitize($base_path) ?>index" class="bc-nav__link">
            <i class="fa fa-tachometer-alt me-2"></i>Dashboard
        </a>
    </li>
    <li class="bc-nav__item">
        <a href="<?= LayoutHelpers::sanitize($base_path) ?>massage/index" class="bc-nav__link">
            <i class="fas fa-spa me-2"></i>Massage
        </a>
    </li>
    <li class="bc-nav__item">
        <a href="<?= LayoutHelpers::sanitize($base_path) ?>buy_points" class="bc-nav__link">
            <i class="fa fa-coins me-2"></i>Buy Points
        </a>
    </li>

    <li class="bc-nav__item">
        <a href="<?= LayoutHelpers::sanitize($base_path) ?>api" class="bc-nav__link">
            <i class="fa fa-code me-2"></i>API
        </a>
    </li>

    <!-- Search Database -->
    <li class="bc-nav__item bc-dropdown">
        <button type="button" class="bc-nav__link bc-dropdown__trigger" aria-expanded="false">
            <i class="fa fa-database me-2"></i>Search DB
            <i class="bc-dropdown__arrow">

            </i>
        </button>
        <div class="bc-dropdown__menu">
            <a href="<?= LayoutHelpers::sanitize($base_path) ?>table" class="bc-dropdown__item">
                <i class="fa fa-table me-2"></i>Search Table
            </a>
            <a href="<?= LayoutHelpers::sanitize($base_path) ?>my_purchases" class="bc-dropdown__item">
                <i class="fa fa-shopping-cart me-2"></i>My Purchases
            </a>
            <a href="<?= LayoutHelpers::sanitize($base_path) ?>add" class="bc-dropdown__item">
                <i class="fa fa-plus-circle me-2"></i>Add Data
            </a>
            <a href="<?= LayoutHelpers::sanitize($base_path) ?>status_purchase_record" class="bc-dropdown__item">
                <i class="fa fa-check-square me-2"></i>Status Purchase
            </a>
        </div>
    </li>
</ul>
<?php
    }

    /**
     * Render NavbarMenu CSS styles
     */
    public static function renderStyles(): void
    {
        ?>
<style>
/* Navbar Menu Styles - Integrated and Simplified */
.bc-nav__list {
    display: flex;
    align-items: center;
    list-style: none;
    gap: var(--bc-spacing-sm);
}

.bc-nav__item {
    position: relative;
}

.bc-nav__link {
    display: flex;
    align-items: center;
    padding: var(--bc-spacing-sm) var(--bc-spacing-md);
    color: var(--bc-text);
    text-decoration: none;
    border-radius: var(--bc-radius-sm);
    transition: all var(--bc-transition-fast);
    font-size: 0.85rem;
}

.bc-nav__link:hover,
.bc-nav__link[aria-expanded="true"],
.bc-dropdown:hover>.bc-nav__link {
    background: rgba(46, 204, 113, 0.1);
    color: var(--bc-accent);
}

.bc-dropdown__trigger {
    background: none;
    border: none;
    color: var(--bc-text);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}

.bc-dropdown__menu {
    position: absolute;
    top: 100%;
    /* Contiguous for hover */
    left: 0;
    min-width: 200px;
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
    z-index: var(--bc-z-dropdown);
    pointer-events: none;
}

.bc-dropdown:hover>.bc-dropdown__menu,
.bc-dropdown__menu.is-open {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    pointer-events: auto;
}

/* Hover bridge to prevent losing focus during mouse movement */
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

.bc-dropdown__item {
    display: flex;
    align-items: center;
    padding: 10px 16px;
    color: var(--bc-text);
    text-decoration: none;
    transition: all var(--bc-transition-fast);
    font-size: 0.85rem;
}

.bc-dropdown__item:hover {
    background: rgba(46, 204, 113, 0.1);
    color: var(--bc-accent);
}

.bc-dropdown__arrow {
    transition: transform 0.2s ease;
    font-size: 0.7rem;
}

.bc-dropdown__trigger[aria-expanded="true"] .bc-dropdown__arrow,
.bc-dropdown:hover .bc-dropdown__arrow {
    transform: rotate(180deg);
}

@media (max-width: 1100px) {
    .bc-dropdown:hover>.bc-dropdown__menu {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    .bc-dropdown__menu.is-open,
    .bc-dropdown__menu[aria-hidden="false"] {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .bc-nav__list {
        flex-direction: column;
        align-items: flex-start;
        gap: 0;
        padding: 10px;
    }

    .bc-dropdown__menu {
        position: static;
        opacity: 1;
        visibility: visible;
        transform: none;
        box-shadow: none;
        border: none;
        padding-left: 20px;
        display: none;
        background: transparent;
        pointer-events: auto;
    }

    .bc-dropdown__menu.is-open {
        display: block;
    }
}
</style>
<?php
    }
}