<?php
declare(strict_types=1);

namespace ROOTS\Layout;

/**
 * StylesRenderer - Centralized renderer for all layout component styles
 * Provides a single method to render all layout CSS at once
 */
class StylesRenderer
{
    /**
     * Render all layout component styles
     * Call this once in your layout head to include all necessary CSS
     */
    public static function renderAll(): void
    {
        LayoutHelpers::renderUtilityStyles();
        NavProfile::renderStyles();
        NavNotification::renderStyles();
        NavbarMenu::renderStyles();
        NavigationRenderer::renderStyles();
        Linkbar::renderStyles();
    }

    /**
     * Render specific component styles by name
     * @param string $component Component name (profile, notification, menu, navigation, linkbar, utility)
     */
    public static function renderComponent(string $component): void
    {
        switch (strtolower($component)) {
            case 'profile':
                NavProfile::renderStyles();
                break;
            case 'notification':
                NavNotification::renderStyles();
                break;
            case 'menu':
                NavbarMenu::renderStyles();
                break;
            case 'navigation':
                NavigationRenderer::renderStyles();
                break;
            case 'linkbar':
                Linkbar::renderStyles();
                break;
            case 'utility':
                LayoutHelpers::renderUtilityStyles();
                break;
            default:
                // If unknown component, render all
                self::renderAll();
        }
    }

    /**
     * Render essential styles only (utility + navigation)
     * Use this for minimal pages
     */
    public static function renderEssential(): void
    {
        LayoutHelpers::renderUtilityStyles();
        NavigationRenderer::renderStyles();
    }

    /**
     * Render enhanced styles (utility + navigation + profile)
     * Use this for dashboard and main pages
     */
    public static function renderEnhanced(): void
    {
        LayoutHelpers::renderUtilityStyles();
        NavigationRenderer::renderStyles();
        NavProfile::renderStyles();
        Linkbar::renderStyles();
    }
}
