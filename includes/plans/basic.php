<?php
declare(strict_types=1);

$planMeta['basic'] = [
    'audience' => 'Regional teams with steady read workloads',
    'trial' => TRIAL_UPGRADE_NOTE,
    'support' => 'Standard · under 48h',
    'highlights' => [
        'Unlimited read queries',
        'Unlock 43-country dataset',
        'Introductory Drone API & Discounts',
    ],
];

$planSpecGroups['basic'] = [
    [
        'heading' => 'Coverage',
        'items' => [
            ['label' => 'Countries', 'value' => '43', 'note' => 'Broad multi-region civil registry coverage'],
        ],
    ],
    [
        'heading' => 'Access & Usage',
        'items' => [
            ['label' => LABEL_READ_ACCESS, 'value' => 'Unlimited', 'note' => 'No daily request ceiling'],
            ['label' => LABEL_WRITE_ACCESS, 'value' => 'No', 'unavailable' => true, 'note' => 'Read-focused tier; upgrade to Pro to write'],
            ['label' => LABEL_CONCURRENT_SESSIONS, 'value' => '3', 'note' => 'Small team parallelism'],
        ],
    ],
    [
        'heading' => HEADING_DATA_STORAGE,
        'items' => [
            ['label' => LABEL_DB_ACCESS, 'value' => '43%', 'note' => 'Moderate registry depth for production reads'],
            ['label' => LABEL_SENSITIVE_DATA, 'value' => '17%', 'note' => 'Controlled sensitive-field access unlocked'],
            ['label' => LABEL_STORAGE_CAP, 'value' => '12 TB', 'note' => 'Ample capacity for regional datasets'],
        ],
    ],
    [
        'heading' => 'Drone API',
        'items' => [
            ['label' => 'Linked drones', 'value' => '10', 'note' => 'Command up to 10 active drones'],
            ['label' => 'Face scan cost', 'value' => '98 tokens', 'note' => 'Standard token consumption per facial scan'],
            ['label' => 'Database link', 'value' => '26%', 'note' => 'Introductory drone-to-DB sync capacity'],
            ['label' => 'Cyberwarfare', 'value' => 'No', 'unavailable' => true, 'note' => 'Offensive capabilities require Premium tier'],
        ],
    ],
    [
        'heading' => 'Benefits',
        'items' => [
            ['label' => LABEL_SERVICE_DISCOUNT, 'value' => '17%', 'note' => 'Valuable savings on add-ons and channels'],
            ['label' => LABEL_API_ACCESS, 'value' => '60%', 'note' => 'Core endpoints unlocked with rate limits'],
            ['label' => LABEL_CONTRIBUTION_POINTS, 'value' => '1M+', 'note' => 'Quality-weighted contributor rewards'],
        ],
    ],
    [
        'heading' => HEADING_SYNC_SUPPORT,
        'items' => [
            ['label' => LABEL_DATA_PROPAGATION, 'value' => '12 hours', 'note' => 'Expedited standard ingestion queue'],
            ['label' => LABEL_PBX_ROUTING, 'value' => 'Standard', 'note' => 'International call transfer with agent assist'],
        ],
    ],
];

$planGuideExtras['basic'] = [
    [
        'heading' => HEADING_PRIVACY_SECURITY,
        'items' => [
            ['label' => LABEL_PRIVACY_TIER, 'value' => 'Moderate', 'note' => 'Advanced encryption beyond the Free tier'],
            ['label' => 'Transport', 'value' => 'PGP', 'note' => NOTE_ENCRYPTED_CONNECTION],
        ],
    ],
    [
        'heading' => HEADING_SENSITIVE_CONTENT,
        'items' => [
            ['label' => LABEL_FINANCIAL_RECORDS, 'value' => 'Limited', 'note' => 'Restricted access to select sensitive information'],
            ['label' => 'Private profiles', 'value' => 'Partial', 'note' => 'Some classified fields remain locked'],
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

$planCatalogDisplay['basic'] = [
    'id' => 'plan-basic',
    'name' => 'Basic',
    'tier' => 'Tier 01',
    'glyph' => '◆',
    'headClass' => 'plan-head--basic',
    'cardModifiers' => [],
    'badge' => null,
    'tagline' => 'Production reads — 43 countries, 12 TB',
    'amount' => '$3k',
    'period' => '/ 1 year',
    'ctaLabel' => LABEL_VIEW_ON_REGISTRATION,
    'ctaHref' => HREF_CONTACT_PLANS,
];