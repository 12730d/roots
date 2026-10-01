<?php

declare(strict_types=1);

namespace ROOTS\Services;

use ROOTS\Services\ActivityLogger;
use ROOTS\Database\ImageOptimizer;
use Exception;

/**
 * ProfileService - Handles secure profile updates, avatar uploads, and cooldown logic
 *
 * @package ROOTS\Services
 */
class ProfileService
{
    private const DATE_FORMAT = "Y-m-d H:i:s";
    private \mysqli $db;
    private string $username;

    /** @var array<string, mixed> */
    private array $user;

    public function __construct(\mysqli $db, string $username)
    {
        $this->db = $db;
        $this->username = $username;
        $this->user = $this->fetchUser();
    }

    /**
     * Fetch current user data with prepared statement
     *
     * @return array<string, mixed>
     */
    private function fetchUser(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM login WHERE username = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare user query");
        }
        $stmt->bind_param("s", $this->username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result !== false ? ($result->fetch_assoc() ?: []) : [];
        $stmt->close();
        return $user;
    }

    /**
     * Get current user data
     *
     * @return array<string, mixed>
     */
    public function getUser(): array
    {
        return $this->user;
    }

    /**
     * Check cooldown for a field (15 days)
     *
     * @return array<string, mixed>
     */
    public function checkCooldown(string $field): array
    {
        $lastChange = $this->user["last_{$field}_change"] ?? null;
        if (!$lastChange) {
            return ['allowed' => true, 'daysRemaining' => 0];
        }

        $last = new \DateTime($lastChange);
        $now = new \DateTime();
        $diff = $last->diff($now);

        if ($diff->days < 15) {
            return ['allowed' => false, 'daysRemaining' => 15 - $diff->days];
        }

        return ['allowed' => true, 'daysRemaining' => 0];
    }

    /**
     * Validate file upload for avatar
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function validateAvatarUpload(array $file): array
    {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Upload error'];
        }

        // Security: Verify file was actually uploaded
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Upload verification failed'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return ['valid' => false, 'error' => 'Invalid file type'];
        }

        if (!in_array($file['type'], $allowedMimes, true)) {
            return ['valid' => false, 'error' => 'Invalid MIME type'];
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                if ($detected !== false && !in_array($detected, $allowedMimes, true)) {
                    return ['valid' => false, 'error' => 'File content mismatch'];
                }
            }
        }

        if ($file['size'] > $maxSize) {
            return ['valid' => false, 'error' => 'File too large'];
        }

        return ['valid' => true];
    }

    /**
     * Process avatar upload and store as Data URI in database
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function processAvatarUpload(array $file): array
    {
        $validation = $this->validateAvatarUpload($file);
        if (!$validation['valid']) {
            return $validation;
        }

        try {
            $raw = file_get_contents($file['tmp_name']);
            if ($raw === false) {
                return ['valid' => false, 'error' => 'Failed to read uploaded file'];
            }

            // Compress image (max width 400px, quality 75)
            $compressed = ImageOptimizer::compress($raw, 400, 75);
            if (!$compressed) {
                return ['valid' => false, 'error' => 'Image processing failed'];
            }

            $dataUri = 'data:image/jpeg;base64,' . base64_encode($compressed);

            $stmt = $this->db->prepare("UPDATE login SET avatar_url = ? WHERE username = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare avatar update");
            }

            $stmt->bind_param("ss", $dataUri, $this->username);
            $success = $stmt->execute();
            $stmt->close();

            if (!$success) {
                throw new Exception("Failed to update avatar in database");
            }

            ActivityLogger::log("Avatar updated (DataURI)", $this->user, $this->db);
            return ['valid' => true, 'path' => 'Database Storage'];

        } catch (Exception $e) {
            error_log("Avatar processing error: " . $e->getMessage());
            return ['valid' => false, 'error' => 'System error during image processing'];
        }
    }

    /**
     * Update password with verification and hashing
     *
     * @return array<string, mixed>
     */
    public function updatePassword(string $current, string $new, string $confirm): array
    {
        if (empty($current) || empty($new) || empty($confirm)) {
            return ['success' => false, 'error' => 'All password fields are required'];
        }

        if ($new !== $confirm) {
            return ['success' => false, 'error' => 'New passwords do not match'];
        }

        $validation = \ROOTS\Validation\ProfileValidator::validatePassword($new);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        // Verify current password
        if (!password_verify($current, $this->user['password'])) {
            return ['success' => false, 'error' => 'Current password incorrect'];
        }

        $hash = password_hash($new, PASSWORD_ARGON2ID);
        $stmt = $this->db->prepare("UPDATE login SET password = ? WHERE username = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare password update");
        }

        $stmt->bind_param("ss", $hash, $this->username);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new Exception("Failed to update password");
        }

        ActivityLogger::log("Password changed", $this->user, $this->db);
        return ['success' => true, 'message' => 'Password updated successfully'];
    }

    /**
     * Delete current avatar
     *
     * @return array<string, mixed>
     */
    public function deleteAvatar(): array
    {
        if (empty($this->user['avatar_url'])) {
            return ['success' => true, 'message' => 'No avatar to delete'];
        }

        $stmt = $this->db->prepare("UPDATE login SET avatar_url = NULL WHERE username = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare avatar deletion");
        }

        $stmt->bind_param("s", $this->username);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new Exception("Failed to update database for avatar deletion");
        }

        ActivityLogger::log("Avatar deleted", $this->user, $this->db);
        return ['success' => true, 'message' => 'Avatar deleted successfully'];
    }

    /**
     * Update profile fields with cooldown and validation
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateProfile(array $data): array
    {
        $result = $this->collectProfileUpdates($data);

        if (!empty($result['errors'])) {
            return ['success' => false, 'errors' => $result['errors']];
        }

        if (empty($result['updates'])) {
            return ['success' => true, 'message' => 'No changes detected'];
        }

        $this->executeProfileUpdate($result['updates']);

        ActivityLogger::log("Profile updated", $this->user, $this->db);
        return ['success' => true, 'message' => 'Profile updated successfully'];
    }

    /**
     * Collect all profile updates and validate them
     *
     * @param array<string, mixed> $data
     * @return array{updates: array<string, string>, errors: array<int, string>}
     */
    private function collectProfileUpdates(array $data): array
    {
        $updates = [];
        $errors = [];

        $this->processDisplayName($data, $updates, $errors);
        $this->processEmail($data, $updates, $errors);
        $this->processWallet($data, $updates, $errors);

        return ['updates' => $updates, 'errors' => $errors];
    }

    /**
     * Process display name update
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> &$updates
     * @param array<int, string> &$errors
     */
    private function processDisplayName(array $data, array &$updates, array &$errors): void
    {
        if (!isset($data['display_name'])) {
            return;
        }

        $cooldown = $this->checkCooldown('display_name');
        if (!$cooldown['allowed']) {
            $errors[] = "Display name: cooldown {$cooldown['daysRemaining']} days";
            return;
        }

        $updates['display_name'] = trim($data['display_name']);
        $updates['last_display_name_change'] = (new \DateTime())->format(self::DATE_FORMAT);
    }

    /**
     * Process email update
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> &$updates
     * @param array<int, string> &$errors
     */
    private function processEmail(array $data, array &$updates, array &$errors): void
    {
        if (!isset($data['email'])) {
            return;
        }

        $cooldown = $this->checkCooldown('email');
        if (!$cooldown['allowed']) {
            $errors[] = "Email: cooldown {$cooldown['daysRemaining']} days";
            return;
        }

        $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $errors[] = "Invalid email format";
            return;
        }

        $updates['email'] = $email;
        $updates['last_email_change'] = (new \DateTime())->format(self::DATE_FORMAT);
    }

    /**
     * Process wallet address update
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> &$updates
     * @param array<int, string> &$errors
     */
    private function processWallet(array $data, array &$updates, array &$errors): void
    {
        if (!isset($data['wallet_address'])) {
            return;
        }

        $cooldown = $this->checkCooldown('wallet_address');
        if (!$cooldown['allowed']) {
            $errors[] = "Wallet: cooldown {$cooldown['daysRemaining']} days";
            return;
        }

        $updates['wallet_address'] = trim($data['wallet_address']);
        $updates['last_wallet_address_change'] = (new \DateTime())->format(self::DATE_FORMAT);
    }

    /**
     * Execute the profile update in database
     *
     * @param array<string, mixed> $updates
     */
    private function executeProfileUpdate(array $updates): void
    {
        $setParts = [];
        $types = '';
        $values = [];

        foreach ($updates as $field => $value) {
            $setParts[] = "{$field} = ?";
            $types .= 's';
            $values[] = $value;
        }

        $sql = "UPDATE login SET " . implode(', ', $setParts) . " WHERE username = ?";
        $types .= 's';
        $values[] = $this->username;

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare profile update");
        }

        $stmt->bind_param($types, ...$values);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new Exception("Failed to update profile");
        }
    }

    /**
     * Update username with strict validation and cooldown
     *
     * @return array<string, mixed>
     */
    public function updateUsername(string $newUsername): array
    {
        $cooldown = $this->checkCooldown('username');
        if (!$cooldown['allowed']) {
            return [
                'success' => false,
                'error' => "Username cooldown: {$cooldown['daysRemaining']} days remaining"
            ];
        }

        $newUsername = trim($newUsername);
        if (empty($newUsername) || strlen($newUsername) < 3 || strlen($newUsername) > 50) {
            return ['success' => false, 'error' => 'Invalid username length'];
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $newUsername)) {
            return ['success' => false, 'error' => 'Username contains invalid characters'];
        }

        // Check uniqueness
        $stmt = $this->db->prepare("SELECT id FROM login WHERE username = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare username check");
        }
        $stmt->bind_param("s", $newUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result !== false && $result->num_rows > 0;
        $stmt->close();

        if ($exists) {
            return ['success' => false, 'error' => 'Username already taken'];
        }

        // Update
        $stmt = $this->db->prepare(
            "UPDATE login SET username = ?, last_username_change = NOW() WHERE username = ?"
        );
        if (!$stmt) {
            throw new Exception("Failed to prepare username update");
        }
        $stmt->bind_param("ss", $newUsername, $this->username);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new Exception("Failed to update username");
        }

        ActivityLogger::log("Username changed", $this->user, $this->db);
        $this->username = $newUsername;
        $_SESSION['username'] = $newUsername;

        return ['success' => true, 'message' => 'Username updated successfully'];
    }
}
