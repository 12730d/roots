<?php
declare(strict_types=1);

$planMeta['pro'] = [
    'audience' => 'Operations teams needing read/write pipelines',
    'trial' => TRIAL_UPGRADE_NOTE,
    'support' => 'Priority · under 24h',
    'highlights' => [
        'Full read/write sovereignty',
        'Expanded 65-country jurisdiction',
        'Advanced Drone Fleet Management',
    ],
];

$planSpecGroups['pro'] = [
    [
        'heading' => 'Coverage',
        'items' => [
            ['label' => 'Countries', 'value' => '65', 'note' => 'Expansive global operations across 65 jurisdictions'],
        ],
    ],
    [
        'heading' => 'Access & Usage',
        'items' => [
            ['label' => LABEL_READ_ACCESS, 'value' => 'Unlimited', 'note' => 'Unrestricted read capabilities'],
            ['label' => LABEL_WRITE_ACCESS, 'value' => 'Full', 'note' => 'Direct data insertion without queue review'],
            ['label' => LABEL_CONCURRENT_SESSIONS, 'value' => '8', 'note' => 'Multi-desk operational powerhouse'],
        ],
    ],
    [
        'heading' => HEADING_DATA_STORAGE,
        'items' => [
            ['label' => LABEL_DB_ACCESS, 'value' => '86%', 'note' => 'Deep registry access for heavy operations'],
            ['label' => LABEL_SENSITIVE_DATA, 'value' => '34%', 'note' => 'Major access to restricted private fields'],
            ['label' => LABEL_STORAGE_CAP, 'value' => '47 TB', 'note' => 'Massive 47 TB capacity for large portfolios'],
        ],
    ],
    [
        'heading' => 'Drone API',
        'items' => [
            ['label' => 'Linked drones', 'value' => '50', 'note' => 'Command a massive fleet of 50 active drones'],
            ['label' => 'Face scan cost', 'value' => '73 tokens', 'note' => 'Optimized token efficiency (25% discount)'],
            ['label' => 'Database link', 'value' => '70%', 'note' => 'High-bandwidth drone-to-DB synchronous link'],
            ['label' => 'Geo-scanning', 'value' => 'Yes', 'note' => 'Unlock geographical area scanning & mapping'],
            ['label' => 'Cyberwarfare', 'value' => 'No', 'unavailable' => true, 'note' => 'Offensive capabilities require Premium tier'],
        ],
    ],
    [
        'heading' => 'Benefits',
        'items' => [
            ['label' => LABEL_SERVICE_DISCOUNT, 'value' => '29%', 'note' => 'Aggressive savings on advanced services'],
            ['label' => LABEL_API_ACCESS, 'value' => '80%', 'note' => 'Near-complete API surface with priority webhooks'],
            ['label' => LABEL_CONTRIBUTION_POINTS, 'value' => '2M+', 'note' => 'Premium multipliers for data influx'],
        ],
    ],
    [
        'heading' => HEADING_SYNC_SUPPORT,
        'items' => [
            ['label' => LABEL_DATA_PROPAGATION, 'value' => '1 hour', 'note' => 'Near-instant priority data pipeline'],
            ['label' => LABEL_PBX_ROUTING, 'value' => 'Priority', 'note' => 'Accelerated secure routing channels'],
        ],
    ],
];

$planGuideExtras['pro'] = [
    [
        'heading' => HEADING_PRIVACY_SECURITY,
        'items' => [
            ['label' => LABEL_PRIVACY_TIER, 'value' => 'High', 'note' => 'Strong encryption with additional protection layers'],
            ['label' => 'Transport', 'value' => 'PGP', 'note' => NOTE_ENCRYPTED_CONNECTION],
        ],
    ],
    [
        'heading' => HEADING_SENSITIVE_CONTENT,
        'items' => [
            ['label' => LABEL_FINANCIAL_RECORDS, 'value' => 'Moderate', 'note' => 'Limited bank accounts and credit-card datasets'],
            ['label' => 'Reading tools', 'value' => 'Advanced', 'note' => 'Intelligent filtering and faster query paths'],
        ],
    ],
    [
        'heading' => HEADING_PLATFORM_RULES,
        'items' => [
            ['label' => LABEL_USAGE_ANALYTICS, 'value' => 'Active', 'note' => NOTE_USAGE_ANALYTICS],
            ['label' => LABEL_DAILY_REQUEST_CAP, 'value' => '100', 'note' => NOTE_REQUEST_CAP_EXCEEDED],
        ],
    ],
];

$planCatalogDisplay['pro'] = [
    'id' => 'plan-pro',
    'name' => 'Pro',
    'tier' => 'Tier 02',
    'glyph' => '★',
    'headClass' => 'plan-head--pro',
    'cardModifiers' => ['plan-detail-card--popular'],
    'badge' => ['class' => 'plan-badge--popular', 'label' => 'Most'],
    'tagline' => 'Full read/write — 65 countries, 47 TB',
    'amount' => '$7k',
    'period' => '/ 3 years',
    'ctaLabel' => LABEL_VIEW_ON_REGISTRATION,
    'ctaHref' => HREF_CONTACT_PLANS,
];