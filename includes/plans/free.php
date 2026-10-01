<?php
declare(strict_types=1);

$planMeta['free'] = [
    'audience' => 'Evaluators and new staff onboarding',
    'trial' => '60-day trial included',
    'support' => 'Community queue · under 72h',
    'highlights' => [
        'Risk-free sandbox environment',
        'Basic registry + PBX previews',
        'Upgrade anytime to unlock full power',
    ],
];

$planSpecGroups['free'] = [
    [
        'heading' => 'Coverage',
        'items' => [
            ['label' => 'Countries', 'value' => '10', 'note' => 'Sample jurisdictions for proof-of-value'],
        ],
    ],
    [
        'heading' => 'Access & Usage',
        'items' => [
            ['label' => LABEL_READ_ACCESS, 'value' => 'Limited', 'note' => 'Capped daily request quota'],
            ['label' => LABEL_WRITE_ACCESS, 'value' => 'No', 'unavailable' => true, 'note' => 'Read-only during trial tier. Upgrade required.'],
            ['label' => LABEL_CONCURRENT_SESSIONS, 'value' => '1', 'note' => 'Single active operator session'],
        ],
    ],
    [
        'heading' => HEADING_DATA_STORAGE,
        'items' => [
            ['label' => LABEL_DB_ACCESS, 'value' => '5%', 'note' => 'Limited registry preview for evaluation only'],
            ['label' => LABEL_SENSITIVE_DATA, 'value' => '0%', 'unavailable' => true, 'note' => 'Restricted fields locked. Upgrade to unlock.'],
            ['label' => LABEL_STORAGE_CAP, 'value' => 'No', 'unavailable' => true, 'note' => 'Minimal dataset volume for sandbox use'],
        ],
    ],
    [
        'heading' => 'Drone API',
        'items' => [
            ['label' => 'Linked drones', 'value' => '0', 'unavailable' => true, 'note' => 'Drone linkage unavailable on Free tier'],
            ['label' => 'Face scan cost', 'value' => 'N/A', 'unavailable' => true, 'note' => 'Scanner API locked'],
            ['label' => 'Database link', 'value' => '0%', 'unavailable' => true, 'note' => 'Drone-to-DB sync locked'],
            ['label' => 'Cyberwarfare', 'value' => 'No', 'unavailable' => true, 'note' => 'Offensive drone capabilities locked'],
        ],
    ],
    [
        'heading' => 'Benefits',
        'items' => [
            ['label' => LABEL_SERVICE_DISCOUNT, 'value' => '0%', 'unavailable' => true, 'note' => 'Paid-tier discounts unlock after upgrade'],
            ['label' => LABEL_API_ACCESS, 'value' => 'No', 'unavailable' => true, 'note' => 'REST/API disabled on Free'],
            ['label' => LABEL_CONTRIBUTION_POINTS, 'value' => '1M cap', 'note' => 'Minimal rewards for data quality submissions'],
        ],
    ],
    [
        'heading' => HEADING_SYNC_SUPPORT,
        'items' => [
            ['label' => LABEL_DATA_PROPAGATION, 'value' => '24 hours', 'note' => 'Standard data ingestion delay'],
            ['label' => LABEL_PBX_ROUTING, 'value' => 'Preview', 'note' => 'Mobile PBX sandbox routing only'],
        ],
    ],
];

$planGuideExtras['free'] = [
    [
        'heading' => HEADING_PRIVACY_SECURITY,
        'items' => [
            ['label' => LABEL_PRIVACY_TIER, 'value' => 'Basic', 'note' => 'Standard encryption for trial workloads'],
            ['label' => 'Transport', 'value' => 'PGP', 'note' => NOTE_ENCRYPTED_CONNECTION],
        ],
    ],
    [
        'heading' => HEADING_SENSITIVE_CONTENT,
        'items' => [
            ['label' => LABEL_FINANCIAL_RECORDS, 'value' => 'None', 'unavailable' => true, 'note' => 'No bank-account or credit-card datasets'],
            ['label' => 'Private profiles', 'value' => 'None', 'unavailable' => true, 'note' => 'Sensitive content access is not available on Free'],
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

$planCatalogDisplay['free'] = [
    'id' => 'plan-free',
    'name' => 'Free',
    'tier' => 'Tier 00',
    'glyph' => '◇',
    'headClass' => 'plan-head--free',
    'cardModifiers' => [],
    'badge' => ['class' => 'plan-badge--trial', 'label' => '60-day'],
    'tagline' => 'Safe sandbox — upgrade from dashboard when ready',
    'amount' => '$0',
    'period' => '/ 60-day trial',
    'ctaLabel' => 'Register free',
    'ctaHref' => 'contact#register-form',
];