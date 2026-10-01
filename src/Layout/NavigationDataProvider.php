<?php
declare(strict_types=1);

namespace ROOTS\Layout;

/**
 * NavigationDataProvider - Centralized data provider for navigation links and menus
 * Provides structured data for the main navigation, VIP links, and other dropdowns
 */
class NavigationDataProvider
{
    /**
     * Get main navigation items
     * @return array<int, array<string, mixed>>
     */
    public static function getMainNavItems(): array
    {
        return [
            [
                "href" => "index",
                "icon" => "fa fa-tachometer-alt me-2",
                "label" => "Dashboard",
            ],

            [
                "href" => "buy_points",
                "icon" => "fa fa-coins me-2",
                "label" => "Buy Points",
            ],
            [
                "href" => "api",
                "icon" => "fa fa-code me-2",
                "label" => "API",
                "icon_style" => "",
            ],
        ];
    }

    /**
     * Get VIP simple links
     * @return array<int, array<string, mixed>>
     */
    private static function getVipSimpleLinks(): array
    {
        return [
            [
                "href" => "v7k9m2p4q1",
                "icon" => LayoutConfig::ICON_LOCK,
                "label" => LayoutConfig::MASK_PATTERN_1,
                "requires_premium" => true,
            ],
            [
                "href" => "b3n8v1c5x9",
                "icon" => LayoutConfig::ICON_LOCK,
                "label" => LayoutConfig::MASK_PATTERN_2,
                "requires_premium" => true,
            ],
            [
                "href" => "z6x2c4v8b1",
                "icon" => LayoutConfig::ICON_LOCK,
                "label" => LayoutConfig::MASK_PATTERN_3,
                "requires_premium" => true,
            ],
            [
                "href" => "m1n2b3v4c5",
                "icon" => LayoutConfig::ICON_LOCK,
                "label" => LayoutConfig::MASK_PATTERN_1,
                "requires_premium" => true,
            ],
            [
                "href" => "q1w2e3r4t5",
                "icon" => LayoutConfig::ICON_LOCK,
                "label" => LayoutConfig::MASK_PATTERN_2,
                "requires_premium" => true,
            ],
            [
                "href" => "a1s2d3f4g5",
                "icon" => LayoutConfig::ICON_LOCK,
                "label" => LayoutConfig::MASK_PATTERN_3,
                "requires_premium" => true,
            ],
        ];
    }

    /**
     * Get VIP database search links
     * @return array<int, array<string, mixed>>
     */
    private static function getVipDatabaseSearchLinks(): array
    {
        return [
            [
                "label" => "database search",
                "icon" => "fa fa-database me-2",
                "children" => [
                    [
                        "href" => "abc123def4",
                        "icon" => "fa fa-bolt me-2",
                        "label" => "XXX XXXXXX",
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "xzy987mno6",
                        "icon" => "fas fa-shield-alt me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "p5o9i8u7t6",
                        "icon" => LayoutConfig::ICON_CLOUD,
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "p5o9i8u7t6",
                        "icon" => LayoutConfig::ICON_CLOUD,
                        "label" => LayoutConfig::MASK_PATTERN_2,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "p5o9i8u7t6",
                        "icon" => LayoutConfig::ICON_CLOUD,
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "p5o9i8u7t6",
                        "icon" => LayoutConfig::ICON_CLOUD,
                        "label" => LayoutConfig::MASK_PATTERN_4,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "p5o9i8u7t6",
                        "icon" => LayoutConfig::ICON_CLOUD,
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get VIP dummy models links
     * @return array<int, array<string, mixed>>
     */
    private static function getVipDummyModelsLinks(): array
    {
        return [
            [
                "label" => "Dummy Models",
                "icon" => "fas fa-sitemap me-2",
                "children" => [
                    [
                        "href" => "l3k2j1h4g7",
                        "icon" => "fa fa-list me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "n9m8b7v6c5",
                        "icon" => "fa fa-chart-bar me-2",
                        "label" => LayoutConfig::MASK_PATTERN_4,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "n9m8b7v6c5",
                        "icon" => "fa fa-chart-bar me-2",
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get VIP login links
     * @return array<int, array<string, mixed>>
     */
    private static function getVipLoginLinks(): array
    {
        return [
            [
                "label" => "Login",
                "icon" => "fas fa-sign-in-alt me-2",
                "children" => [
                    [
                        "href" => "w1e2r3t4y5",
                        "icon" => "fas fa-key me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "q2w3e4r5t6",
                        "icon" => "fa fa-user-secret me-2",
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get VIP notifications links
     * @return array<int, array<string, mixed>>
     */
    private static function getVipNotificationsLinks(): array
    {
        return [
            [
                "label" => "Notifications",
                "icon" => "fa fa-bell me-2",
                "children" => [
                    [
                        "href" => "az1xs2dc3",
                        "icon" => "fas fa-exclamation-triangle me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "fv4gb5hn6",
                        "icon" => "fa fa-info-circle me-2",
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "fv4gb5hn6",
                        "icon" => "fa fa-info-circle me-2",
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get VIP additional services links
     * @return array<int, array<string, mixed>>
     */
    private static function getVipAdditionalServicesLinks(): array
    {
        return [
            [
                "label" => "Additional Services",
                "icon" => "fas fa-plus-square me-2",
                "children" => [
                    [
                        "href" => "jk7lm8no9",
                        "icon" => "fa fa-cogs me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "pq2rs3tu4",
                        "icon" => "fas fa-wrench me-2",
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "vw5xy6z1a",
                        "icon" => "fas fa-question me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "vw5xy6z1a",
                        "icon" => "fas fa-question me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get VIP navigation links
     * @return array<int, array<string, mixed>>
     */
    public static function getVipLinks(): array
    {
        return array_merge(
            self::getVipSimpleLinks(),
            self::getVipDatabaseSearchLinks(),
            self::getVipDummyModelsLinks(),
            self::getVipLoginLinks(),
            self::getVipNotificationsLinks(),
            self::getVipAdditionalServicesLinks()
        );
    }

    /**
     * Get password navigation links
     * @return array<int, array<string, mixed>>
     */
    public static function getPasswordLinks(): array
    {
        return [
            [
                "href" => "password_db",
                "icon" => LayoutConfig::ICON_LOCK,
                "label" => "Manage Password",
            ],
            [
                "href" => "my_password_purchases",
                "icon" => LayoutConfig::ICON_CART,
                "label" => LayoutConfig::MY_PURCHASES_TEXT,
            ],
            [
                "href" => "security_logs",
                "icon" => "fas fa-shield-alt me-2",
                "label" => LayoutConfig::MASK_PATTERN_1,
                "requires_premium" => true,
            ],
            [
                "href" => "change_password",
                "icon" => "fas fa-user-shield me-2",
                "label" => LayoutConfig::MASK_PATTERN_1,
                "requires_premium" => true,
            ],
            [
                "href" => "recovery_options",
                "icon" => "fas fa-key me-2",
                "label" => LayoutConfig::MASK_PATTERN_2,
                "requires_premium" => true,
            ],
            [
                "href" => "security_audit",
                "icon" => "fas fa-user-check me-2",
                "label" => LayoutConfig::MASK_PATTERN_3,
                "requires_premium" => true,
            ],
            [
                "label" => "Echo Chamber",
                "icon" => "fas fa-random me-2",
                "children" => [
                    [
                        "href" => "r1a2n3d4",
                        "icon" => "fa fa-dice me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "t6g5h4j3",
                        "icon" => LayoutConfig::ICON_ASTERISK,
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "t6g5h4j3",
                        "icon" => LayoutConfig::ICON_ASTERISK,
                        "label" => LayoutConfig::MASK_PATTERN_4,
                        "requires_premium" => true,
                    ],
                ],
            ],
            [
                "label" => "Shuffle Options",
                "icon" => "fa fa-arrow-right-arrow-left me-2",
                "children" => [
                    [
                        "href" => "s4h3u2f1",
                        "icon" => "fa fa-arrow-right-arrow-left me-2",
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "t6g5h4j3",
                        "icon" => LayoutConfig::ICON_ASTERISK,
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "t6g5h4j3",
                        "icon" => LayoutConfig::ICON_ASTERISK,
                        "label" => LayoutConfig::MASK_PATTERN_4,
                        "requires_premium" => true,
                    ],
                ],
            ],
            [
                "label" => "Shuffle Experiments",
                "icon" => LayoutConfig::ICON_ASTERISK,
                "children" => [
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                ],
            ],
            [
                "label" => "Protection Bypass",
                "icon" => LayoutConfig::ICON_FLASK,
                "children" => [
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_2,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ]
                ],
            ]
        ];
    }

    /**
     * Get search database navigation links
     * @return array<int, array<string, mixed>>
     */
    public static function getSearchDbLinks(): array
    {
        return [
            [
                "href" => "table",
                "icon" => "fa fa-table me-2",
                "label" => "Search Table",
            ],
            [
                "href" => "osint",
                "icon" => "fas fa-search-plus me-2",
                "label" => "OSINT",
            ],
            [
                "href" => "my_purchases",
                "icon" => LayoutConfig::ICON_CART,
                "label" => LayoutConfig::MY_PURCHASES_TEXT,
            ],
            [
                "href" => "pending_requests",
                "icon" => "fa fa-list me-2",
                "label" => "Purchase Record",
            ],
            [
                "href" => "add",
                "icon" => "fa fa-plus-circle me-2",
                "label" => "Add Data",
            ],
            [
                "href" => "status_purchase_record",
                "icon" => "fa fa-check-square me-2",
                "label" => "status purchase",
            ],
        ];
    }

    /**
     * Get store data navigation links
     * @return array<int, array<string, mixed>>
     */
    public static function getStoreDataLinks(): array
    {
        return [
            [
                "href" => "post_mysite",
                "icon" => "fa fa-tachometer-alt me-2",
                "label" => "Dashboard (Basic)",
                "requires_premium" => true,
            ],
            [
                "href" => "post_mysite/browse_files",
                "icon" => "fa fa-folder-open me-2",
                "label" => "Browse Files",
                "requires_premium" => true,
            ],
            [
                "href" => "post_mysite/upload_form",
                "icon" => "fa fa-upload me-2",
                "label" => "Upload File",
                "requires_premium" => true,
            ],
            [
                "href" => "post_mysite/my_files",
                "icon" => "fa fa-file me-2",
                "label" => "My Files",
                "requires_premium" => true,
            ],
            [
                "href" => "post_mysite/my_purchases",
                "icon" => LayoutConfig::ICON_CART,
                "label" => LayoutConfig::MY_PURCHASES_TEXT,
                "requires_premium" => true,
            ],
            [
                "href" => "post_mysite/my_transactions",
                "icon" => "fa fa-history me-2",
                "label" => "Transactions",
                "requires_premium" => true,
            ],
        ];
    }

    /**
     * Get drone API navigation links
     * @return array<int, array<string, mixed>>
     */
    public static function getDroneApiLinks(): array
    {
        return [
            [
                "href" => "drone-dashboard",
                "icon" => "fas fa-drone me-2",
                "label" => "Drone Dashboard",
                "requires_premium" => true,
            ],
            [
                "href" => "docs/drone-api-documentation",
                "icon" => "fas fa-book me-2",
                "label" => "API Documentation",
                "requires_premium" => true,
            ],
            [
                "href" => "api/v1/drone",
                "icon" => "fas fa-code me-2",
                "label" => "API Endpoints",
                "requires_premium" => true,
            ],
            [
                "href" => "drone-register",
                "icon" => LayoutConfig::ICON_PLUS,
                "label" => "Register Drone",
                "requires_premium" => true,
            ],
            [
                "href" => "Face_scan_Database",
                "icon" => LayoutConfig::ICON_PLUS,
                "label" => "Face scan Database",
                "requires_premium" => true,
            ],
            [
                "href" => "Face Arche",
                "icon" => LayoutConfig::ICON_PLUS,
                "label" => "Face Arch ",
                "requires_premium" => true,
            ],
            [
                "href" => "drone-settings",
                "icon" => "fas fa-cog me-2",
                "label" => "Drone Settings",
                "children" => [
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_1,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_2,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_3,
                        "requires_premium" => true,
                    ],
                    [
                        "href" => "x1c2v3b4",
                        "icon" => LayoutConfig::ICON_FLASK,
                        "label" => LayoutConfig::MASK_PATTERN_4,
                        "requires_premium" => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get admin navigation links
     * @return array<int, array<string, mixed>>
     */
    public static function getAdminMenuData(): array
    {
        return [
            [
                "title" => "Terminal Tools",
                "links" => [
                    [
                        "href" => "admin_security_guard",
                        "icon" => "fas fa-shield-alt me-2",
                        "label" => "Security Guard",
                    ],
                    [
                        "href" => "professional_admins",
                        "icon" => "fas fa-user-tie me-2",
                        "label" => "Add requests",
                    ],
                    [
                        "href" => "admin_withdrawals",
                        "icon" => "fas fa-money-bill-wave me-2",
                        "label" => "Withdrawals",
                    ],
                ],
            ],
            [
                "title" => "System Admin",
                "links" => [
                    [
                        "href" => "admin_payments",
                        "icon" => "fa fa-credit-card me-2",
                        "label" => "Buy requests",
                    ],
                    [
                        "href" => "phpmyadmin",
                        "icon" => "fa fa-database me-2",
                        "label" => "phpMyAdmin",
                    ],
                ],
            ],
        ];
    }

    /**
     * Get all navigation menu data
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getNavigationMenuData(): array
    {
        return [
            "mainNavItems" => self::getMainNavItems(),
            "vipLinks" => self::getVipLinks(),
            "passwordLinks" => self::getPasswordLinks(),
            "searchDbLinks" => self::getSearchDbLinks(),
            "storeDataLinks" => self::getStoreDataLinks(),
            "droneApiLinks" => self::getDroneApiLinks(),
        ];
    }
}