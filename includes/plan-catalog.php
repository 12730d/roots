<?php
declare(strict_types=1);

/**
 * Link CSS for subscription plan cards.
 * v3.2 — CSS moved to plan-card.css to avoid page conflicts.
 */
function renderPlanCardsCSS(): void
{
    echo '<link href="/css/plan-card.css" rel="stylesheet">';
}

/**
 * Render grouped plan specification rows (label + value + note).
 *
 * @param list<<array{heading: string, items: list<<array{label: string, value?: string, note: string}>}> $groups
 */
function renderPlanSpecGroups(array $groups): void
{
    foreach ($groups as $group) {
        echo '<div class="plan-spec-group">';
        echo '<h4 class="plan-spec-heading">' . htmlspecialchars($group['heading'], ENT_QUOTES, 'UTF-8') . '</h4>';
        echo '<dl class="plan-spec-list">';
        foreach ($group['items'] as $item) {
            $hasValue = !empty($item['value']);
            $isPercent = $hasValue && str_ends_with($item['value'], '%');
            $isComparison = $hasValue && $item['label'] === 'Countries';
            $isUnavailable = !empty($item['unavailable']);

            $rowClass = 'plan-spec-row';
            if ($isUnavailable) {
                $rowClass .= ' plan-spec-row--unavailable';
            }
            echo '<div class="' . $rowClass . '">';
            echo '<dd' . ($hasValue ? '' : ' class="plan-spec-dd--solo"') . '>';
            if ($hasValue) {
                $valueClass = 'plan-spec-value';
                if ($isUnavailable) {
                    $valueClass .= ' plan-spec-value--unavailable';
                } elseif ($isPercent) {
                    $valueClass .= ' plan-spec-value--percent';
                } elseif ($isComparison) {
                    $valueClass .= ' plan-spec-value--comparison';
                }
                echo '<span class="' . $valueClass . '">' . htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '</dd>';
            echo '<dt>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</dt>';
            echo '<span class="plan-spec-note">' . htmlspecialchars($item['note'], ENT_QUOTES, 'UTF-8') . '</span>';
            echo '</div>';
        }
        echo '</dl>';
        echo '</div>';
    }
}

/**
 * Render plan marketing extras (highlights, audience, trial, support).
 *
 * @param array{highlights: list<string>, audience: string, trial: string, support: string} $meta
 */
function renderPlanMetaExtras(array $meta): void
{
    echo '<div class="plan-meta-extras">';
    echo '<p class="plan-audience"><span class="plan-meta-label">Best for</span> ';
    echo htmlspecialchars($meta['audience'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<ul class="plan-highlights" aria-label="Key highlights">';
    foreach ($meta['highlights'] as $highlight) {
        echo '<li>' . htmlspecialchars($highlight, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
    echo '<div class="plan-meta-row">';
    echo '<span class="plan-trial-chip">' . htmlspecialchars($meta['trial'], ENT_QUOTES, 'UTF-8') . '</span>';
    echo '<span class="plan-support-chip">' . htmlspecialchars($meta['support'], ENT_QUOTES, 'UTF-8') . '</span>';
    echo '</div>';
    echo '</div>';
}

const TRIAL_UPGRADE_NOTE = 'Upgrade after free trial';
const HEADING_DATA_STORAGE = 'Data & storage';
const LABEL_DB_ACCESS = 'Database access';
const LABEL_SENSITIVE_DATA = 'Sensitive data';
const LABEL_STORAGE_CAP = 'Storage cap';
const LABEL_CONCURRENT_SESSIONS = 'Concurrent sessions';

const HEADING_USAGE_LIMITS = 'Usage limits';
const LABEL_READ_ACCESS = 'Read access';
const LABEL_WRITE_ACCESS = 'Write access';
const LABEL_SERVICE_DISCOUNT = 'Service discount';
const LABEL_API_ACCESS = 'API access';
const LABEL_CONTRIBUTION_POINTS = 'Contribution points';
const HEADING_SYNC_SUPPORT = 'Sync & support';
const LABEL_DATA_PROPAGATION = 'New data propagation';
const LABEL_PBX_ROUTING = 'PBX routing';
const HEADING_PRIVACY_SECURITY = 'Privacy & security';
const LABEL_PRIVACY_TIER = 'Privacy tier';
const NOTE_ENCRYPTED_CONNECTION = 'Fully encrypted connection on every plan';
const HEADING_SENSITIVE_CONTENT = 'Sensitive content';
const LABEL_FINANCIAL_RECORDS = 'Financial records';
const HEADING_PLATFORM_RULES = 'Platform rules';
const NOTE_USAGE_ANALYTICS = 'Browsing patterns are analyzed on all tiers';
const LABEL_USAGE_ANALYTICS = 'Usage analytics';
const LABEL_DAILY_REQUEST_CAP = 'Daily request cap';
const NOTE_REQUEST_CAP_EXCEEDED = 'Exceeding the cap triggers a 1-hour temporary ban';
const LABEL_VIEW_ON_REGISTRATION = 'View on registration';
const HREF_CONTACT_PLANS = 'contact#plans-heading';

$planMeta = [];
$planSpecGroups = [];
$planGuideExtras = [];
$planCatalogDisplay = [];

require_once __DIR__ . '/plans/free.php';
require_once __DIR__ . '/plans/basic.php';
require_once __DIR__ . '/plans/pro.php';
require_once __DIR__ . '/plans/premium.php';

/**
 * Render a self-contained plan detail card for the About / guide page.
 */
function renderPlanDetailCard(string $planKey): void
{
    global $planCatalogDisplay, $planMeta, $planSpecGroups, $planGuideExtras;

    if (!isset($planCatalogDisplay[$planKey], $planMeta[$planKey], $planSpecGroups[$planKey])) {
        return;
    }

    $display = $planCatalogDisplay[$planKey];
    $cardClass = 'plan-card';
    if (!empty($display['cardModifiers'])) {
        $cardClass .= ' ' . implode(' ', $display['cardModifiers']);
    }

    $groups = array_values(array_merge(
        $planSpecGroups[$planKey],
        $planGuideExtras[$planKey] ?? [],
    ));

    $titleId = $display['id'] . '-title';
    $glyphClass = 'plan-tier-glyph';
    if (!empty($display['glyphClass'])) {
        $glyphClass .= ' ' . $display['glyphClass'];
    }
    ?>
    <article class="<?php echo htmlspecialchars($cardClass, ENT_QUOTES, 'UTF-8'); ?>"
        id="<?php echo htmlspecialchars($display['id'], ENT_QUOTES, 'UTF-8'); ?>"
        aria-labelledby="<?php echo htmlspecialchars($titleId, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if (!empty($display['badge'])): ?>
            <span class="plan-badge <?php echo htmlspecialchars($display['badge']['class'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($display['badge']['label'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        <?php endif; ?>
        <span class="<?php echo htmlspecialchars($glyphClass, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
            <?php echo htmlspecialchars($display['glyph'], ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <header class="plan-head <?php echo htmlspecialchars($display['headClass'], ENT_QUOTES, 'UTF-8'); ?>">
            <p class="plan-tier-label"><?php echo htmlspecialchars($display['tier'], ENT_QUOTES, 'UTF-8'); ?></p>
            <h3 id="<?php echo htmlspecialchars($titleId, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($display['name'], ENT_QUOTES, 'UTF-8'); ?>
            </h3>
            <p class="plan-tagline"><?php echo htmlspecialchars($display['tagline'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="plan-price">
                <span class="amount"><?php echo htmlspecialchars($display['amount'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="period"><?php echo htmlspecialchars($display['period'], ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
        </header>
        <div class="plan-body">
            <?php renderPlanMetaExtras($planMeta[$planKey]); ?>
            <?php renderPlanSpecGroups($groups); ?>
        </div>
        <footer class="plan-foot">
            <a href="<?php echo htmlspecialchars($display['ctaHref'], ENT_QUOTES, 'UTF-8'); ?>"
                class="btn btn-secondary plan-detail-cta">
                <?php echo htmlspecialchars($display['ctaLabel'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        </footer>
    </article>
    <?php
}

/**
 * @return list<string>
 */
function planCatalogKeys(): array
{
    return ['free', 'basic', 'pro', 'premium'];
}