<?php

namespace ROOTS\Services;

class UserService
{
    /**
     * @return array<string, mixed>
     */
    public static function getCurrentUser(\mysqli $db, ?string $username): array
    {
        if (!$username) {
            return self::guest();
        }

        $stmt = $db->prepare(
            "SELECT id, points, avatar_url, display_name, subscription, expiry_date
             FROM login WHERE username = ?"
        );

        if (!$stmt) {
            return self::guest();
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res !== false ? $res->fetch_assoc() : null;

        if (!$row) {
            return self::guest();
        }

        return [
            'id' => $row['id'],
            'points' => (int) $row['points'],
            'avatar' => $row['avatar_url'] ?: 'img/user.jpg',
            'name' => $row['display_name'] ?: $username,
            'subscription' => $row['subscription'] ?: 'Basic',
            'expiry' => $row['expiry_date']
                ? date('Y-m-d', strtotime((string)$row['expiry_date']) ?: time())
                : 'Never'
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function guest(): array
    {
        return [
            'id' => 'N/A',
            'points' => 0,
            'avatar' => 'img/user.jpg',
            'name' => 'Guest',
            'subscription' => 'Free',
            'expiry' => 'N/A'
        ];
    }
}
