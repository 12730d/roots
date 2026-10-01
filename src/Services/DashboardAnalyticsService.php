<?php

declare(strict_types=1);

namespace ROOTS\Services;

/**
 * DashboardAnalyticsService
 *
 * Platform-level analytics:
 *  - KPI cards: total volume, sales, purchases, active users, growth rate
 *  - Chart data: daily sales vs purchases, tx-type distribution, top 5 active users
 *
 * All results are cached for 60 seconds server-side.
 */
class DashboardAnalyticsService
{
    private const CACHE_TTL = 60;

    /** @var array<string, array{data: array<string, mixed>, ts: int}> */
    private static array $cache = [];

    public function __construct(private readonly \mysqli $db) {}

    // ──────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(string $period = '30d'): array
    {
        $period = in_array($period, ['24h','7d','30d','90d','all'], true) ? $period : '30d';
        $cacheKey = "analytics_{$period}";

        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['ts']) < self::CACHE_TTL) {
            return self::$cache[$cacheKey]['data'];
        }

        $result = [
            'success'         => true,
            'period'          => $period,
            'kpis'            => $this->getKPIs($period),
            'chart_daily'     => $this->getDailyChart($period),
            'tx_distribution' => $this->getTxDistribution($period),
            'top_active'      => $this->getTopActiveUsers($period),
            'generated_at'    => time(),
        ];

        self::$cache[$cacheKey] = ['data' => $result, 'ts' => time()];
        return $result;
    }

    // ──────────────────────────────────────────────────────
    //  KPIs
    // ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function getKPIs(string $period): array
    {
        $dateFilter  = $this->periodFilter($period, 'created_at');
        $prevFilter  = $this->prevPeriodFilter($period, 'created_at');

        // Current period
        $current = $this->fetchKPIRow($dateFilter);
        $prev    = $this->fetchKPIRow($prevFilter);

        $growthRate = 0.0;
        if (($prev['total_volume'] ?? 0) > 0) {
            $growthRate = (($current['total_volume'] - $prev['total_volume']) / $prev['total_volume']) * 100;
        }

        return [
            'total_volume'       => (int) ($current['total_volume'] ?? 0),
            'total_sales'        => (int) ($current['total_sales'] ?? 0),
            'total_purchases'    => (int) ($current['total_purchases'] ?? 0),
            'active_users'       => (int) ($current['active_users'] ?? 0),
            'growth_rate'        => round($growthRate, 2),
            // deltas vs previous period
            'volume_delta'       => $prev['total_volume'] > 0
                ? round(($current['total_volume']    - $prev['total_volume'])    / $prev['total_volume']    * 100, 1) : null,
            'sales_delta'        => $prev['total_sales'] > 0
                ? round(($current['total_sales']     - $prev['total_sales'])     / $prev['total_sales']     * 100, 1) : null,
            'purchases_delta'    => $prev['total_purchases'] > 0
                ? round(($current['total_purchases'] - $prev['total_purchases']) / $prev['total_purchases'] * 100, 1) : null,
            'users_delta'        => $prev['active_users'] > 0
                ? round(($current['active_users']    - $prev['active_users'])    / $prev['active_users']    * 100, 1) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchKPIRow(string $dateFilter): array
    {
        $sql = <<<SQL
SELECT
    COALESCE(SUM(points_amount), 0)                                                         AS total_volume,
    COALESCE(SUM(CASE WHEN transaction_type = 'sale'     THEN points_amount ELSE 0 END), 0) AS total_sales,
    COALESCE(SUM(CASE WHEN transaction_type = 'purchase' THEN points_amount ELSE 0 END), 0) AS total_purchases,
    COUNT(DISTINCT user_id)                                                                  AS active_users
FROM transaction_history
WHERE 1=1 {$dateFilter}
SQL;
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result === false) return [];
        $row = $result->fetch_assoc();
        return is_array($row) ? $row : [];
    }

    // ──────────────────────────────────────────────────────
    //  Daily chart
    // ──────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    private function getDailyChart(string $period): array
    {
        $days = match ($period) {
            '24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90, default => 30,
        };

        $groupBy = $days <= 1 ? 'DATE_FORMAT(created_at, \'%H:00\')' : 'DATE(created_at)';
        $dateFilter = $this->periodFilter($period, 'created_at');

        $sql = <<<SQL
SELECT
    {$groupBy} AS label,
    COALESCE(SUM(CASE WHEN transaction_type = 'sale'     THEN points_amount ELSE 0 END), 0) AS sales,
    COALESCE(SUM(CASE WHEN transaction_type = 'purchase' THEN points_amount ELSE 0 END), 0) AS purchases
FROM transaction_history
WHERE 1=1 {$dateFilter}
GROUP BY label
ORDER BY label ASC
LIMIT 90
SQL;
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return ['labels' => [], 'sales' => [], 'purchases' => []];
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result !== false ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return [
            'labels'    => array_column($rows, 'label'),
            'sales'     => array_map('intval', array_column($rows, 'sales')),
            'purchases' => array_map('intval', array_column($rows, 'purchases')),
        ];
    }

    // ──────────────────────────────────────────────────────
    //  Transaction type distribution
    // ──────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    private function getTxDistribution(string $period): array
    {
        $dateFilter = $this->periodFilter($period, 'created_at');
        $sql = <<<SQL
SELECT transaction_type AS label, COUNT(*) AS value
FROM transaction_history
WHERE 1=1 {$dateFilter}
GROUP BY transaction_type
ORDER BY value DESC
LIMIT 8
SQL;
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return ['labels' => [], 'values' => []];
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result !== false ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return [
            'labels' => array_map(fn(array $r): string => strtoupper($r['label']), $rows),
            'values' => array_map(fn(array $r): int => (int) $r['value'], $rows),
        ];
    }

    // ──────────────────────────────────────────────────────
    //  Top 5 active users
    // ──────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    private function getTopActiveUsers(string $period): array
    {
        $dateFilter = $this->periodFilter($period, 'th.created_at');
        $sql = <<<SQL
SELECT
    COALESCE(l.display_name, th.user_id) AS label,
    COALESCE(SUM(th.points_amount), 0)   AS value
FROM transaction_history th
LEFT JOIN login l ON l.username = th.user_id
WHERE 1=1 {$dateFilter}
  AND (l.role IS NULL OR l.role <> 'admin')
GROUP BY th.user_id, l.display_name
ORDER BY value DESC
LIMIT 5
SQL;
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return ['labels' => [], 'values' => []];
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result !== false ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        // Mask usernames for privacy
        $labels = array_map(function (array $r): string {
            $n = $r['label'] ?? '---';
            return strlen($n) > 6 ? substr($n, 0, 3) . '***' : $n;
        }, $rows);

        return [
            'labels' => $labels,
            'values' => array_map(fn(array $r): int => (int) $r['value'], $rows),
        ];
    }

    // ──────────────────────────────────────────────────────
    //  Period helpers
    // ──────────────────────────────────────────────────────

    private function periodFilter(string $period, string $col): string
    {
        return match ($period) {
            '24h'   => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            '7d'    => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d'   => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90d'   => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            default => '',
        };
    }

    private function prevPeriodFilter(string $period, string $col): string
    {
        return match ($period) {
            '24h'   => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 48 HOUR) AND {$col} < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            '7d'    => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 14 DAY)  AND {$col} < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d'   => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 60 DAY)  AND {$col} < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90d'   => "AND {$col} >= DATE_SUB(NOW(), INTERVAL 180 DAY) AND {$col} < DATE_SUB(NOW(), INTERVAL 90 DAY)",
            default => "AND {$col} < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        };
    }
}
