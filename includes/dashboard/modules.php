<?php
/**
 * includes/dashboard/modules.php
 * Business Modules Grid — rendered as terminal-card tiles.
 *
 * $userSubscription  string  Current user subscription level
 * $moduleCounts      array   Optional counters per module key
 */

$subscription = strtolower($userSubscription ?? 'basic');
$isAdmin      = $isAdmin ?? false;

// Color constants for module cards
const COLOR_GREEN = 'var(--term-green)';
const COLOR_CYAN = 'var(--term-cyan)';

$modules = [
    [
        'key'       => 'buy_points',
        'label'     => 'Buy Points',
        'icon'      => 'fa-coins',
        'href'      => 'buy_points',
        'required'  => 'basic',
        'color'     => 'var(--term-yellow)',
    ],
    [
        'key'       => 'walet',
        'label'     => 'Wallet',
        'icon'      => 'fa-wallet',
        'href'      => 'walet',
        'required'  => 'basic',
        'color'     => COLOR_GREEN,
    ],
    [
        'key'       => 'api',
        'label'     => 'API Access',
        'icon'      => 'fa-code',
        'href'      => 'api',
        'required'  => 'pro',
        'color'     => COLOR_CYAN,
    ],
    [
        'key'       => 'table',
        'label'     => 'Search DB',
        'icon'      => 'fa-search',
        'href'      => 'table',
        'required'  => 'basic',
        'color'     => COLOR_GREEN,
    ],
    [
        'key'       => 'osint',
        'label'     => 'OSINT',
        'icon'      => 'fa-user-secret',
        'href'      => 'osint',
        'required'  => 'pro',
        'color'     => 'var(--term-magenta)',
    ],
    [
        'key'       => 'password_db',
        'label'     => 'Password DB',
        'icon'      => 'fa-key',
        'href'      => 'password_db',
        'required'  => 'pro',
        'color'     => 'var(--term-red)',
    ],
    [
        'key'       => 'drone-dashboard',
        'label'     => 'Drone Face API',
        'icon'      => 'fa-helicopter',
        'href'      => 'drone-dashboard',
        'required'  => 'premium',
        'color'     => COLOR_CYAN,
    ],
    [
        'key'       => 'massage',
        'label'     => 'Massage Terminal',
        'icon'      => 'fa-spa',
        'href'      => 'massage/index',
        'required'  => 'basic',
        'color'     => COLOR_GREEN,
    ],
    [
        'key'       => 'my_purchases',
        'label'     => 'My Purchases',
        'icon'      => 'fa-shopping-cart',
        'href'      => 'my_purchases',
        'required'  => 'basic',
        'color'     => 'var(--term-yellow)',
    ],
    [
        'key'       => 'my_payments',
        'label'     => 'Payments',
        'icon'      => 'fa-credit-card',
        'href'      => 'my_payments',
        'required'  => 'basic',
        'color'     => COLOR_GREEN,
    ],
    [
        'key'       => 'profile',
        'label'     => 'Profile',
        'icon'      => 'fa-user',
        'href'      => 'profile',
        'required'  => 'basic',
        'color'     => COLOR_CYAN,
    ],
];

// Subscription tier check
$tierMap = ['basic' => 0, 'pro' => 1, 'premium' => 2, 'vip' => 3, 'admin' => 99];
$userTier = $tierMap[$subscription] ?? 0;
if ($isAdmin) {
    $userTier = 99;
}

/**
 * @param array<string, int> $tierMap
 */
function isLocked(string $required, int $userTier, array $tierMap): bool {
    return ($tierMap[$required] ?? 0) > $userTier;
}

$counts = $moduleCounts ?? [];
?>

<section class="container-fluid px-4 mb-3">
    <div class="terminal-card rounded p-3">
        <div class="corner-accent tr"></div>
        <div class="corner-accent bl"></div>

        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="section-header mb-0">PLATFORM_MODULES</h6>
            <small style="font-size:.6rem;color:rgba(0,255,65,.4);"><?= count($modules) ?> UNITS</small>
        </div>

        <div class="row g-2 row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-xl-6">
            <?php foreach ($modules as $mod): ?>
                <?php
                    $locked  = isLocked($mod['required'], $userTier, $tierMap);
                    $href    = $locked ? '#' : $mod['href'];
                    $count   = $counts[$mod['key']] ?? null;
                    $iconStyle = "color:{$mod['color']};";
                    $lockClass = $locked ? ' locked' : '';
                ?>
                <div class="col">
                    <a href="<?= htmlspecialchars($href) ?>"
                       class="module-tile<?= $lockClass ?>"
                       <?= $locked ? 'aria-disabled="true" role="button" tabindex="-1"' : '' ?>>

                        <?php if ($locked): ?>
                            <span class="lock-mark" title="Requires <?= strtoupper($mod['required']) ?>">
                                <i class="fas fa-lock"></i>
                            </span>
                        <?php endif; ?>

                        <div class="module-tile-icon">
                            <i class="fas <?= htmlspecialchars($mod['icon']) ?>"></i>
                        </div>

                        <span class="module-tile-label"><?= htmlspecialchars($mod['label']) ?></span>

                        <?php if ($count !== null): ?>
                            <span class="module-count"><?= (int)$count ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
