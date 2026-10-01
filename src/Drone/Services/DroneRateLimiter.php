<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use Exception;

/**
 * DroneRateLimiter - Handles drone rate limiting
 */
class DroneRateLimiter extends AbstractDroneService
{
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const string DEFAULT_TIME_OFFSET = '-1 minute';
    private const string MINUTE_TIME_OFFSET = '+1 minute';
    private const string HOUR_TIME_OFFSET = '-1 hour';
    private const string HOUR_TIME_FORWARD = '+1 hour';
    /**
     * Validate drone access and check rate limits
     *
     * @param string $droneId Drone identifier
     * @param string $windowType Rate limit window type (minute, hour)
     * @return array<string, mixed> Validation result
     */
    public function validateDroneAccess(string $droneId, string $windowType = 'minute'): array
    {
        $result = [
            'success' => false,
            'error' => 'Access validation failed'
        ];

        try {
            // Get drone information
            $drone = $this->getDroneInfo($droneId);
            if (!$drone || $drone['status'] !== 'active') {
                $result = [
                    'success' => false,
                    'error' => 'Drone not found or not active'
                ];
            } else {
                // Get rate limit for window type
                $rateLimit = ($windowType === 'minute')
                    ? $drone['rate_limit_per_minute']
                    : $drone['rate_limit_per_hour'];

                // Clean old rate limit records
                $this->cleanOldRateLimitRecords($droneId, $windowType);

                // Get current request count
                $currentCount = $this->getCurrentRequestCount($droneId, $windowType);

                if ($currentCount >= $rateLimit) {
                    $result = [
                        'success' => false,
                        'error' => 'Rate limit exceeded',
                        'limit' => $rateLimit,
                        'current' => $currentCount,
                        'window' => $windowType
                    ];
                } else {
                    // Increment request count
                    $this->incrementRequestCount($droneId, $windowType);

                    $result = [
                        'success' => true,
                        'remaining' => $rateLimit - ($currentCount + 1),
                        'limit' => $rateLimit
                    ];
                }
            }

        } catch (Exception $e) {
            error_log("Drone access validation error: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Get drone information
     * @return array<string, mixed>|null
     */
    private function getDroneInfo(string $droneId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM drone_registrations WHERE drone_id = ?"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("s", $droneId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result !== false && $result->num_rows > 0) {
            $drone = $result->fetch_assoc();
            $stmt->close();
            return is_array($drone) ? $drone : null;
        }

        return null;
    }

    /**
     * Clean old rate limit records
     */
    private function cleanOldRateLimitRecords(string $droneId, string $windowType): void
    {
        $cutoffTime = match ($windowType) {
            'minute' => date(self::DATETIME_FORMAT, strtotime(self::DEFAULT_TIME_OFFSET)),
            'hour' => date(self::DATETIME_FORMAT, strtotime(self::HOUR_TIME_OFFSET)),
            'day' => date(self::DATETIME_FORMAT, strtotime('-1 day')),
            default => date(self::DATETIME_FORMAT, strtotime(self::DEFAULT_TIME_OFFSET))
        };

        $stmt = $this->db->prepare(
            "DELETE FROM drone_rate_limit_tracking
            WHERE drone_id = ? AND window_start < ?"
        );
        if ($stmt) {
            $stmt->bind_param("ss", $droneId, $cutoffTime);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Get current request count
     */
    private function getCurrentRequestCount(string $droneId, string $windowType): int
    {
        $stmt = $this->db->prepare(
            "SELECT SUM(request_count) as total FROM drone_rate_limit_tracking
            WHERE drone_id = ? AND window_type = ?"
        );
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("ss", $droneId, $windowType);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result !== false) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0);
        }

        return 0;
    }

    /**
     * Increment request count
     */
    private function incrementRequestCount(string $droneId, string $windowType): void
    {
        $windowStart = match ($windowType) {
            'minute' => date(self::DATETIME_FORMAT, strtotime(self::DEFAULT_TIME_OFFSET)),
            'hour' => date(self::DATETIME_FORMAT, strtotime(self::HOUR_TIME_OFFSET)),
            default => date(self::DATETIME_FORMAT, strtotime(self::DEFAULT_TIME_OFFSET))
        };

        $windowEnd = match ($windowType) {
            'minute' => date(self::DATETIME_FORMAT, strtotime(self::MINUTE_TIME_OFFSET)),
            'hour' => date(self::DATETIME_FORMAT, strtotime(self::HOUR_TIME_FORWARD)),
            default => date(self::DATETIME_FORMAT, strtotime(self::MINUTE_TIME_OFFSET))
        };

        $stmt = $this->db->prepare(
            "INSERT INTO drone_rate_limit_tracking
            (drone_id, user_id, window_type, request_count, window_start, window_end)
            VALUES (?, 1, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE request_count = request_count + 1"
        );
        
        // Get user_id from drone registration
        $drone = $this->getDroneInfo($droneId);
        $userId = $drone['user_id'] ?? 0;

        if ($stmt) {
            $stmt->bind_param("sisss", $droneId, $userId, $windowType, $windowStart, $windowEnd);
            $stmt->execute();
            $stmt->close();
        }
    }
}
