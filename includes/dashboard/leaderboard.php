<?php
/**
 * includes/dashboard/leaderboard.php
 * Top 15 leaderboard with Podium + metric tabs.
 *
 * Variables expected from parent:
 *   $leaderboardData  array  (from LeaderboardService::getLeaderboard)
 *   $currentUsername  string
 */

$topUsers    = $leaderboardData['top_users']    ?? [];
$currentUser = $leaderboardData['current_user'] ?? null;
$currentRank = $leaderboardData['current_rank'] ?? null;
$hasError    = $leaderboardData['has_error']    ?? false;
$un          = $currentUsername ?? '';

function subBadgeHtml(string $sub): string {
    $s = strtoupper($sub ?: 'BASIC');
    if (str_contains($s, 'ADMIN')) {
        $cls = 'badge-admin';
    } elseif (str_contains($s, 'PREMIUM')) {
        $cls = 'badge-premium';
    } elseif (str_contains($s, 'PRO')) {
        $cls = 'badge-pro';
    } elseif (str_contains($s, 'VIP')) {
        $cls = 'badge-vip';
    } else {
        $cls = 'badge-basic';
    }
    return "<span class='badge {$cls}' style='font-size:.55rem;padding:3px 7px;'>{$s}</span>";
}
?>

<section class="container-fluid px-4 mb-3">
    <div class="terminal-card rounded p-3">
        <div class="corner-accent tr"></div>
        <div class="corner-accent bl"></div>
        <div class="terminal-card-bg"></div>

        <!-- Header + metric tabs -->
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <h6 class="section-header mb-0">TOP_OPERATIVES</h6>
                <?php if ($currentRank): ?>
                    <small style="font-size:.6rem;color:var(--term-yellow);">
                        YOUR_RANK: <strong style="color:#fff;">#<?= (int)$currentRank ?></strong>
                    </small>
                <?php endif; ?>
            </div>
            <nav class="d-flex gap-1 flex-wrap" aria-label="Leaderboard metric selection">
                <button class="metric-tab active" onclick="switchLeaderboardMetric('balance',this)">[BALANCE]</button>
                <button class="metric-tab" onclick="switchLeaderboardMetric('activity',this)">[ACTIVITY]</button>
                <button class="metric-tab" onclick="switchLeaderboardMetric('earned',this)">[EARNED]</button>
                <button class="metric-tab" onclick="switchLeaderboardMetric('composite',this)">[COMPOSITE]</button>
            </nav>
        </div>

        <!-- Podium: top 3 (hidden on mobile) -->
        <?php if (count($topUsers) >= 3): ?>
        <div class="podium-wrap" id="podiumContainer">
            <?php
            $order = [$topUsers[1], $topUsers[0], $topUsers[2]];
            $ranks = [2, 1, 3];
            $crowns = [2 => '🥈', 1 => '👑', 3 => '🥉'];
            foreach ($order as $i => $u):
                $r    = $ranks[$i];
                $name = htmlspecialchars($u['display_name'] ?? $u['username'] ?? '---');
                $pts  = number_format((int)($u['points'] ?? 0));
                $sub  = subBadgeHtml($u['subscription'] ?? 'BASIC');
            ?>
            <div class="podium-card rank-<?= $r ?>">
                <span class="podium-medal"><?= $r ?></span>
                <span class="podium-crown"><?= $crowns[$r] ?></span>
                <img src="img/user.jpg" alt="<?= $name ?>" class="podium-avatar" loading="lazy">
                <div class="podium-name"><?= $name ?></div>
                <div class="podium-pts"><?= $pts ?> <small style="font-size:.55rem;opacity:.6">PTS</small></div>
                <div class="mt-1"><?= $sub ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Table -->
        <?php if ($hasError): ?>
            <div class="text-center py-4" style="color:var(--term-red);font-size:.75rem;">
                <i class="fas fa-exclamation-triangle me-2"></i>DATABASE_CONNECTION_ERROR
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>OPERATIVE</th>
                        <th class="d-none d-sm-table-cell">TIER</th>
                        <th>SCORE</th>
                        <th style="width:70px;">Δ</th>
                    </tr>
                </thead>
                <tbody id="leaderboardTableBody">
                    <?php foreach ($topUsers as $idx => $u):
                        $rank    = $idx + 1;
                        $name    = htmlspecialchars($u['display_name'] ?? $u['username'] ?? '---');
                        $pts     = number_format((int)($u['points'] ?? 0));
                        $sub     = subBadgeHtml($u['subscription'] ?? 'BASIC');
                        $isPinned = ($u['username'] ?? '') === $un;

                        $diff = (int)($u['points'] ?? 0) - (int)($u['previous_points'] ?? 0);
                        if ($diff > 0) {
                            $changeHtml = "<span style='color:var(--term-green);font-size:.7rem'><i class='fas fa-caret-up'></i> " . number_format($diff) . "</span>";
                        } elseif ($diff < 0) {
                            $changeHtml = "<span style='color:var(--term-red);font-size:.7rem'><i class='fas fa-caret-down'></i> " . number_format(abs($diff)) . "</span>";
                        } else {
                            $changeHtml = "<span style='color:#444;font-size:.7rem'>—</span>";
                        }

                        if ($rank === 1) {
                            $rankCell = "<span class='rank-badge-glow g'>1</span>";
                            $rowClass = 'row-gold';
                        } elseif ($rank === 2) {
                            $rankCell = "<span class='rank-badge-glow s'>2</span>";
                            $rowClass = 'row-silver';
                        } elseif ($rank === 3) {
                            $rankCell = "<span class='rank-badge-glow b'>3</span>";
                            $rowClass = 'row-bronze';
                        } else {
                            $rankCell = "<span style='color:#555;font-size:.8rem'>{$rank}</span>";
                            $rowClass = '';
                        }

                        if ($isPinned) {
                            $rowClass .= ' pinned-user-row';
                        }
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="rank-cell text-center"><?= $rankCell ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="img/user.jpg" alt="<?= $name ?>" class="user-avatar-small" loading="lazy">
                                <span style="font-size:.75rem">
                                    <?= $name ?>
                                    <?php if ($isPinned): ?>
                                        <span style="color:var(--term-yellow);font-size:.55rem">[YOU]</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </td>
                        <td class="d-none d-sm-table-cell"><?= $sub ?></td>
                        <td style="font-size:.85rem;font-weight:700;color:#fff;"><?= $pts ?></td>
                        <td><?= $changeHtml ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</section>
