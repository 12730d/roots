<?php
declare(strict_types=1);

$planMeta['premium'] = [
    'audience' => 'Enterprise registry control and VIP lanes',
    'trial' => TRIAL_UPGRADE_NOTE,
    'support' => 'VIP dedicated · under 12h SLA',
    'highlights' => [
        'Ultimate R/W/D data sovereignty',
        'Unrestricted 132-country global reach',
        'Military-grade Drone & Cyberwarfare API',
    ],
];

$planSpecGroups['premium'] = [
    [
        'heading' => 'Coverage',
        'items' => [
            ['label' => 'Countries', 'value' => '132+', 'note' => 'Absolute worldwide footprint with VIP lane'],
        ],
    ],
    [
        'heading' => 'Access & Usage',
        'items' => [
            ['label' => 'Permissions', 'value' => 'R/W/D', 'note' => 'Total Read, Write, and Delete sovereignty'],
            ['label' => 'Data ingestion', 'value' => 'Instant', 'note' => 'Immediate live publish with zero delays'],
            ['label' => LABEL_CONCURRENT_SESSIONS, 'value' => 'Unlimited', 'note' => 'Infinite multi-desk scaling for enterprise'],
        ],
    ],
    [
        'heading' => HEADING_DATA_STORAGE,
        'items' => [
            ['label' => LABEL_DB_ACCESS, 'value' => '100%', 'note' => 'Unrestricted apex access to all databases'],
            ['label' => LABEL_SENSITIVE_DATA, 'value' => '72%+', 'note' => 'Maximum clearance for classified fields'],
            ['label' => LABEL_STORAGE_CAP, 'value' => '117 TB', 'note' => 'Colossal 117 TB enterprise data pool'],
        ],
    ],
    [
        'heading' => 'Drone API',
        'items' => [
            ['label' => 'Linked drones', 'value' => '100+', 'note' => 'Command an unparalleled armada of drones'],
            ['label' => 'Face scan cost', 'value' => '36 tokens', 'note' => 'Maximum token efficiency (63% discount)'],
            ['label' => 'Database link', 'value' => '78%', 'note' => 'Ultra-low latency synchronous DB link'],
            ['label' => 'Geo-scanning', 'value' => 'Instant', 'note' => 'Real-time global geographical mapping'],
            ['label' => 'Cyberwarfare', 'value' => 'Full', 'note' => 'Unlock complete offensive electronic warfare suites'],
        ],
    ],
    [
        'heading' => 'Benefits',
        'items' => [
            ['label' => LABEL_SERVICE_DISCOUNT, 'value' => '46%', 'note' => 'Ultimate discounts on all exclusive channels'],
            ['label' => LABEL_API_ACCESS, 'value' => '94%', 'note' => 'VIP API tier with exclusive hidden endpoints'],
            ['label' => LABEL_CONTRIBUTION_POINTS, 'value' => 'VIP 5M+', 'note' => 'Highest loyalty multipliers and campaigns'],
        ],
    ],
    [
        'heading' => HEADING_SYNC_SUPPORT,
        'items' => [
            ['label' => LABEL_DATA_PROPAGATION, 'value' => 'Live', 'note' => 'Real-time VIP instant ingestion queue'],
            ['label' => LABEL_PBX_ROUTING, 'value' => 'VIP Direct', 'note' => 'Dedicated secure 24/7 routing with cost coverage'],
        ],
    ],
];

$planGuideExtras['premium'] = [
    [
        'heading' => HEADING_PRIVACY_SECURITY,
        'items' => [
            ['label' => LABEL_PRIVACY_TIER, 'value' => 'Advanced', 'note' => 'Additional encryption and special data-protection shields'],
            ['label' => 'Transport', 'value' => 'PGP', 'note' => NOTE_ENCRYPTED_CONNECTION],
        ],
    ],
    [
        'heading' => HEADING_SENSITIVE_CONTENT,
        'items' => [
            ['label' => LABEL_FINANCIAL_RECORDS, 'value' => 'Extensive', 'note' => 'Broad bank-account and credit-card coverage'],
            ['label' => 'VIP datasets', 'value' => 'Included', 'note' => 'High-level personality and executive records where permitted'],
        ],
    ],
    [
        'heading' => HEADING_PLATFORM_RULES,
        'items' => [
            ['label' => LABEL_USAGE_ANALYTICS, 'value' => 'Active', 'note' => NOTE_USAGE_ANALYTICS],
            ['label' => LABEL_DAILY_REQUEST_CAP, 'value' => '100', 'note' => NOTE_REQUEST_CAP_EXCEEDED],
            ['label' => 'Reading tools', 'value' => 'Instant', 'note' => 'Advanced search, saved queries, and instant reads'],
        ],
    ],
];

$planCatalogDisplay['premium'] = [
    'id' => 'plan-premium',
    'name' => 'Premium',
    'tier' => 'Tier 03',
    'glyph' => '♛',
    'headClass' => 'plan-head--premium',
    'cardModifiers' => ['plan-detail-card--vip'],
    'badge' => ['class' => 'plan-badge--vip', 'label' => 'VIP'],
    'tagline' => 'Full sovereignty — 132 countries, VIP lane',
    'amount' => '$12k',
    'period' => '/ 5 years',
    'ctaLabel' => LABEL_VIEW_ON_REGISTRATION,
    'ctaHref' => HREF_CONTACT_PLANS,
    'glyphClass' => 'plan-tier-glyph--gold',
];