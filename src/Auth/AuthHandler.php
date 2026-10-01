<?php

namespace ROOTS\Auth;

use ROOTS\Config\Database;
use ROOTS\Security\SecurityLogger;
use ROOTS\Security\AccountLockout;
use ROOTS\Security\RateLimiter;
use ROOTS\Security\RememberMe;
use Exception;
use mysqli;


class AuthHandler
{
    // Auth error message constants
    public const AUTH_FAILED_INVALID_CREDENTIALS = 'AUTH_FAILED: Invalid credentials or security code.';
    public const AUTH_FAILED_LOCKED_PERMANENT = 'AUTH_FAILED: Account permanently locked. Contact support.';
    public const AUTH_FAILED_LOCKED_TEMPORARY = 'AUTH_FAILED: Account temporarily locked. Try again later.';
    public const AUTH_FAILED_CAPTCHA_REQUIRED = 'AUTH_FAILED: Security code required.';
    private const string DEFAULT_IP = '0.0.0.0';

    private mysqli $db;
    private ?SecurityLogger $logger;
    private ?AccountLockout $accountLockout;
    private ?RateLimiter $rateLimiter;
    private ?RememberMe $rememberMe;

    public function __construct(
        ?SecurityLogger $logger = null,
        ?AccountLockout $accountLockout = null,
        ?RateLimiter $rateLimiter = null,
        ?RememberMe $rememberMe = null
    ) {
        $this->db = Database::getConnection();
        $this->logger = $logger;
        $this->accountLockout = $accountLockout;
        $this->rateLimiter = $rateLimiter;
        $this->rememberMe = $rememberMe;
    }

    /**
     * Handles the login request.
     *
     * @param array<string, mixed> $postData The $_POST data.
     * @param array<string, mixed> $sessionData The $_SESSION data.
     * @return array<string, mixed> An array containing status and message.
     */
    public function handleLoginRequest(array $postData, array &$sessionData): array
    {
        $errorMessage = null;

        if ($_SERVER["REQUEST_METHOD"] !== 'POST') {
            $errorMessage = 'Invalid request method.';
        } elseif (empty($postData["csrf_token"]) || !hash_equals($sessionData["csrf_token"] ?? '', $postData["csrf_token"])) {
            $this->logSecurityEvent('CSRF token validation failed.', 'security');
            $errorMessage = 'Invalid security token.';
        } else {
            $username = trim($postData["username"] ?? '');
            $password = $postData["password"] ?? '';

            if (empty($username) || empty($password)) {
                $errorMessage = 'Username and password are required.';
            }
        }

        if ($errorMessage !== null) {
            return ['status' => 'error', 'message' => $errorMessage];
        }

        // More logic for authentication, password verification, etc. will go here.
        // For now, we are just setting up the structure.

        return ['status' => 'success', 'message' => 'Login validation passed (structure only).'];
    }

    /**
     * Checks if a user account is currently locked.
     *
     * @param string $username The username to check.
     * @return array<string, mixed> An array containing the lock status and a message.
     */
    public function isAccountLocked(string $username): array
    {
        $locked = false;
        $message = '';

        if ($this->accountLockout !== null) {
            try {
                $lockStatus = $this->accountLockout->isLocked($username);
                if ($lockStatus['locked']) {
                    $locked = true;
                    $message = $lockStatus['is_permanent']
                        ? self::AUTH_FAILED_LOCKED_PERMANENT
                        : self::AUTH_FAILED_LOCKED_TEMPORARY;

                    if ($this->logger) {
                        $this->logger->log(
                            SecurityLogger::EVENT_LOGIN_BLOCKED,
                            SecurityLogger::SEVERITY_MEDIUM,
                            null,
                            $username,
                            'Login attempt on locked account',
                            [
                                'ip' => $_SERVER['REMOTE_ADDR'] ?? self::DEFAULT_IP,
                                'locked_until' => $lockStatus['locked_until'],
                            ]
                        );
                    }
                }
            } catch (Exception $e) {
                $this->logSecurityEvent('[SECURITY] Account lockout check error: ' . $e->getMessage(), 'file');
                // Fail open: If the check fails, don't block the user.
            }
        }

        return ['locked' => $locked, 'message' => $message];
    }

    /**
     * Validates the CAPTCHA code.
     *
     * @param string $captchaCode The code from the form.
     * @param string|null $sessionPhrase The phrase from the session.
     * @return array<string, mixed> An array with validation status and message.
     */
    public function validateCaptcha(string $captchaCode, ?string $sessionPhrase): array
    {
        if (empty($captchaCode)) {
            return ['valid' => false, 'message' => self::AUTH_FAILED_CAPTCHA_REQUIRED];
        }

        if (empty($sessionPhrase) || strcasecmp($captchaCode, $sessionPhrase) !== 0) {
            $this->logSecurityEvent(SecurityLogger::EVENT_LOGIN_FAILED, '[SECURITY] Failed captcha validation.');
            return ['valid' => false, 'message' => self::AUTH_FAILED_INVALID_CREDENTIALS];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Authenticates a user against the database.
     *
     * @param string $username
     * @param string $password
     * @return array<string, mixed>|null User data on success, null on failure.
     */
    public function authenticate(string $username, string $password): ?array
    {
        $row = $this->fetchUserByUsername($username);
        if ($row === null) {
            return null;
        }

        if ($this->verifyPassword($password, $row)) {
            return $row;
        }

        $this->recordFailedAttempt($username);
        $this->logSecurityEvent(SecurityLogger::EVENT_LOGIN_FAILED, "[SECURITY] Failed login attempt for user: {$username}");

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUserByUsername(string $username): ?array
    {
        $query = "SELECT id, username, password FROM `login` WHERE username = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            $this->logSecurityEvent('[LOGIN] DB prepare failed: ' . $this->db->error, 'file');
            return null;
        }

        $stmt->bind_param("s", $username);
        if (!$stmt->execute()) {
            $this->logSecurityEvent('[LOGIN] DB execute failed: ' . $stmt->error, 'file');
            $stmt->close();
            return null;
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function verifyPassword(string $password, ?array $row): bool
    {
        // Timing attack mitigation
        $dummyHash = '$2y$10$RXI6ZWhH4.xTQuBelpclG.uzxTONXhB18dCSRtYo/5usg1ce1DF.a';
        $userExists = is_array($row);
        $storedHash = $userExists ? (string)$row['password'] : $dummyHash;

        return password_verify($password, $storedHash) && $userExists;
    }

    private function recordFailedAttempt(string $username): void
    {
        if ($this->rateLimiter) {
            $this->rateLimiter->recordFailedAttempt($_SERVER['REMOTE_ADDR'] ?? self::DEFAULT_IP, $username);
        }
        if ($this->accountLockout && $username) {
            $this->accountLockout->recordFailedAttempt($username, $_SERVER['REMOTE_ADDR'] ?? self::DEFAULT_IP);
        }
    }

    /**
     * Handles post-login actions for a successfully authenticated user.
     *
     * @param array<string, mixed> $user The user data array.
     * @param bool $rememberMeChecked Whether the 'remember me' box was checked.
     * @param string $ipAddress
     * @param string $userAgent
     * @return string The path to redirect to.
     */
    public function handleSuccessfulLogin(array $user, bool $rememberMeChecked, string $ipAddress, string $userAgent): string
    {
        $this->initializeSession($user);
        $this->resetSecurityCounters($ipAddress, $user['username']);
        $this->handleRememberMeToken($user, $rememberMeChecked, $ipAddress, $userAgent);
        $this->logSuccessfulLogin($user, $ipAddress, $userAgent);
        $this->clearBrowserBlock();

        $redirect = $this->determineRedirectPath($user);

        return in_array($redirect, ['index', 'blocked']) ? $redirect : 'index';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function initializeSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login_attempts'] = 0;
    }

    private function resetSecurityCounters(string $ipAddress, string $username): void
    {
        if ($this->rateLimiter) {
            $this->rateLimiter->resetAttempts($ipAddress);
        }
        if ($this->accountLockout) {
            $this->accountLockout->resetAttempts($username);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function handleRememberMeToken(array $user, bool $rememberMeChecked, string $ipAddress, string $userAgent): void
    {
        if ($rememberMeChecked && $this->rememberMe) {
            try {
                $token = $this->rememberMe->createToken((int)$user['id'], $user['username'], $ipAddress, $userAgent);
                if ($token) {
                    $this->rememberMe->setCookie($token['token']);
                }
            } catch (Exception $e) {
                $this->logSecurityEvent('[SECURITY] Remember Me token creation error: ' . $e->getMessage(), 'file');
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function logSuccessfulLogin(array $user, string $ipAddress, string $userAgent): void
    {
        if ($this->logger) {
            $this->logger->log(
                SecurityLogger::EVENT_LOGIN_SUCCESS,
                SecurityLogger::SEVERITY_LOW,
                (int)$user['id'],
                $user['username'],
                'Successful login',
                ['ip' => $ipAddress, 'user_agent' => substr($userAgent, 0, 100)]
            );
        }
    }

    private function clearBrowserBlock(): void
    {
        $identifier = BanSystem::getBrowserIdentifier();
        BanSystem::unblockGuestBrowser($identifier);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function determineRedirectPath(array $user): string
    {
        $redirect = 'index';
        $banStmt = $this->db->prepare("SELECT ban_until, suspended FROM user_security_guard WHERE user_id = ? LIMIT 1");
        if ($banStmt) {
            $uid = (int)$user['id'];
            $banStmt->bind_param("i", $uid);
            if ($banStmt->execute()) {
                $banRes = $banStmt->get_result();
                $banRow = $banRes ? $banRes->fetch_assoc() : null;
                if (is_array($banRow)) {
                    $banUntil = $banRow['ban_until'] ?? null;
                    $suspended = !empty($banRow['suspended']);
                    if ($suspended || (!empty($banUntil) && strtotime((string)$banUntil) > time())) {
                        $redirect = 'blocked';
                    }
                }
            }
            $banStmt->close();
        }

        return $redirect;
    }

    /**
     * Logs a security event.
     *
     * @param string $eventType The type of security event.
     * @param string $message The message to log.
     */
    private function logSecurityEvent(string $eventType, string $message): void
    {
        if ($this->logger) {
            try {
                $this->logger->log($eventType, SecurityLogger::SEVERITY_MEDIUM, null, null, $message);
            } catch (Exception $e) {
                error_log("SecurityLogger call failed: " . $e->getMessage());
            }
        } else {
            // Fallback to error_log if logger is not available
            error_log($message);
        }
    }
}
