<?php

declare(strict_types=1);

namespace ROOTS\Services;

/**
 * LeaderboardService
 *
 * Handles all leaderboard queries with 4 metrics:
 *  - balance   : login.points
 *  - activity  : SUM of sale+purchase in transaction_history
 *  - earned    : SUM of sale transactions
 *  - composite : weighted 0.40×balance + 0.35×activity + 0.25×earned
 *
 * All results exclude admins, limited to 15 users.
 */
class LeaderboardService
{
    private const LIMIT       = 15;
    private const CACHE_TTL   = 60; // seconds

    /** @var array<string, array{data: array<string, mixed>, ts: int}> */
    private static array $cache = [];

    public function __construct(private readonly \mysqli $db) {}

    // ──────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────

    /**
     * Get leaderboard data.
     *
     * @param string $metric  balance|activity|earned|composite
     * @param string $period  7d|30d|all
     * @param string $currentUsername
     * @return array<string, mixed>
     */
    public function getLeaderboard(
        string $metric          = 'balance',
        string $period          = '30d',
        string $currentUsername = ''
    ): array {
        $metric = in_array($metric, ['balance','activity','earned','composite'], true)
            ? $metric : 'balance';
        $period = in_array($period, ['7d','30d','90d','all'], true) ? $period : '30d';

        $cacheKey = "lb_{$metric}_{$period}";
        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['ts']) < self::CACHE_TTL) {
            $result = self::$cache[$cacheKey]['data'];
            $result['current_rank'] = $this->getCurrentRank($currentUsername, $metric, $period);
            $result['current_user'] = $this->getCurrentUser($currentUsername);
            return $result;
        }

        $topUsers = match ($metric) {
            'activity'  => $this->getByActivity($period),
            'earned'    => $this->getByEarned($period),
            'composite' => $this->getByComposite($period),
            default     => $this->getByBalance(),
        };

        $result = ['top_users' => $topUsers, 'has_error' => false];
        self::$cache[$cacheKey] = ['data' => $result, 'ts' => time()];

        $result['current_rank'] = $this->getCurrentRank($currentUsername, $metric, $period);
        $result['current_user'] = $this->getCurrentUser($currentUsername);

        return $result;
    }

    // ──────────────────────────────────────────────────────
    //  Query Methods
    // ──────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getByBalance(): array
    {
        $sql = <<<SQL
SELECT display_name, username, subscription, points, previous_points, avatar_url
FROM login
WHERE (role IS NULL OR role <> 'admin')
  AND (subscription NOT LIKE '%admin%' OR subscription IS NULL)
ORDER BY points DESC
LIMIT ?
SQL;
        return $this->fetchRows($sql, 'i', [self::LIMIT]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getByActivity(string $period): array
    {
        $dateFilter = $this->periodToSql($period, 'th.created_at');
        $sql = <<<SQL
SELECT l.display_name, l.username, l.subscription, l.points, l.previous_points, l.avatar_url,
       COALESCE(SUM(th.points_amount), 0) AS activity_score
FROM login l
LEFT JOIN transaction_history th ON th.user_id = l.username
    AND th.transaction_type IN ('sale','purchase')
    {$dateFilter}
WHERE (l.role IS NULL OR l.role <> 'admin')
  AND (l.subscription NOT LIKE '%admin%' OR l.subscription IS NULL)
GROUP BY l.username, l.display_name, l.subscription, l.points, l.previous_points, l.avatar_url
ORDER BY activity_score DESC
LIMIT ?
SQL;
        $rows = $this->fetchRows($sql, 'i', [self::LIMIT]);
        foreach ($rows as &$r) $r['points'] = (int) ($r['activity_score'] ?? $r['points']);
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getByEarned(string $period): array
    {
        $dateFilter = $this->periodToSql($period, 'th.created_at');
        $sql = <<<SQL
SELECT l.display_name, l.username, l.subscription, l.points, l.previous_points, l.avatar_url,
       COALESCE(SUM(th.points_amount), 0) AS earned_score
FROM login l
LEFT JOIN transaction_history th ON th.user_id = l.username
    AND th.transaction_type = 'sale'
    {$dateFilter}
WHERE (l.role IS NULL OR l.role <> 'admin')
  AND (l.subscription NOT LIKE '%admin%' OR l.subscription IS NULL)
GROUP BY l.username, l.display_name, l.subscription, l.points, l.previous_points, l.avatar_url
ORDER BY earned_score DESC
LIMIT ?
SQL;
        $rows = $this->fetchRows($sql, 'i', [self::LIMIT]);
        foreach ($rows as &$r) $r['points'] = (int) ($r['earned_score'] ?? $r['points']);
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getByComposite(string $period): array
    {
        $dateFilter = $this->periodToSql($period, 'th.created_at');

        // Subquery: raw scores per user
        $sql = <<<SQL
SELECT
    l.display_name,
    l.username,
    l.subscription,
    l.points            AS raw_balance,
    l.previous_points,
    COALESCE(SUM(CASE WHEN th.transaction_type IN ('sale','purchase') THEN th.points_amount ELSE 0 END), 0) AS raw_activity,
    COALESCE(SUM(CASE WHEN th.transaction_type = 'sale'               THEN th.points_amount ELSE 0 END), 0) AS raw_earned
FROM login l
LEFT JOIN transaction_history th ON th.user_id = l.username {$dateFilter}
WHERE (l.role IS NULL OR l.role <> 'admin')
  AND (l.subscription NOT LIKE '%admin%' OR l.subscription IS NULL)
GROUP BY l.username, l.display_name, l.subscription, l.points, l.previous_points
SQL;

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result !== false ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        if (empty($rows)) return [];

        // Normalize 0-1 then compute composite score
        $maxBal  = max(array_column($rows, 'raw_balance') ?: [0])  ?: 1;
        $maxAct  = max(array_column($rows, 'raw_activity') ?: [0]) ?: 1;
        $maxEar  = max(array_column($rows, 'raw_earned') ?: [0])   ?: 1;

        foreach ($rows as &$r) {
            $r['composite_score'] = (int) round(
                0.40 * ($r['raw_balance']  / $maxBal)  * 10000 +
                0.35 * ($r['raw_activity'] / $maxAct)  * 10000 +
                0.25 * ($r['raw_earned']   / $maxEar)  * 10000
            );
            $r['points'] = $r['composite_score'];
        }

        usort($rows, fn($a, $b) => $b['composite_score'] <=> $a['composite_score']);
        return array_slice($rows, 0, self::LIMIT);
    }

    // ──────────────────────────────────────────────────────
    //  Helper methods
    // ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function getCurrentUser(string $username): ?array
    {
        if (!$username) return null;
        $sql  = "SELECT display_name, subscription, points, previous_points, role, avatar_url FROM login WHERE username = ?";
        $rows = $this->fetchRows($sql, 's', [$username]);
        return $rows[0] ?? null;
    }

    private function getCurrentRank(string $username, string $metric, string $period): int
    {
        if (!$username) return 0;
        // Re-use cached top list
        $cacheKey = "lb_{$metric}_{$period}";
        $topUsers = self::$cache[$cacheKey]['data']['top_users'] ?? [];
        foreach ($topUsers as $i => $u) {
            if (($u['username'] ?? '') === $username) return $i + 1;
        }
        // Not in top 15, do a COUNT query on balance for rough rank
        $sql  = "SELECT COUNT(*) + 1 AS `rank` FROM login WHERE points > (SELECT COALESCE(points,0) FROM login WHERE username = ?)";
        $rows = $this->fetchRows($sql, 's', [$username]);
        return (int) ($rows[0]['rank'] ?? 0);
    }

    private function periodToSql(string $period, string $col): string
    {
        return match ($period) {
            '7d'  => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d' => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90d' => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            default => '',
        };
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        if ($types && $params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result !== false ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}
