<?php

declare(strict_types=1);

// PERF: compress output (Gzip) for faster page delivery
if (!ob_get_level()) {
    ob_start('ob_gzhandler');
}


require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Controllers\PageController;
use ROOTS\Middleware\SecurityHeadersMiddleware;
use ROOTS\Middleware\BotProtectionMiddleware;
use ROOTS\Services\ProfileService;
use ROOTS\Validation\ProfileValidator;
use ROOTS\Services\ActivityLogger;

/**
 * Profile Management Page
 * Handles user profile updates, security settings, and avatar management.
 */

// ============================================================================
// GLOBAL PROTECTIONS
// ============================================================================
BotProtectionMiddleware::apply();
SecurityHeadersMiddleware::apply();

// ============================================================================
// SETUP & AUTHENTICATION
// ============================================================================
$setupData = PageController::setup('My Profile - ROOTS', './', [
    'css/profile-custom.css',
    'css/screenresolutions.css',
]);

$db = $setupData['db'];
$csrfToken = $setupData['csrf_token'];
$user = $setupData['user'];

if (!$db) {
    error_log('Database connection failed in profile.php');
    die('A database error occurred. Please try again later.');
}

$username = $_SESSION['username'] ?? '';
if (empty($username)) {
    header('Location: login');
    exit;
}

// Initialize services
$profileService = new ProfileService($db, $username);
$currentUser = $profileService->getUser();

// ============================================================================
// REQUEST HANDLING (POST)
// ============================================================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        $message = 'Security validation failed. Access denied.';
        $messageType = 'danger';
        ActivityLogger::log('CSRF violation attempt', $user, $db);
    } else {
        try {
            mysqli_begin_transaction($db);
            $successCount = 0;
            $errors = [];

            // 1. Handle Avatar Deletion
            if (isset($_POST['delete_avatar'])) {
                $result = $profileService->deleteAvatar();
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errors[] = $result['message'];
                }
            }

            // 2. Handle Avatar Upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $result = $profileService->processAvatarUpload($_FILES['avatar']);
                if ($result['valid']) {
                    $successCount++;
                } else {
                    $errors[] = $result['error'];
                }
            }

            // 3. Handle Username Change
            if (!empty($_POST['new_username']) && $_POST['new_username'] !== $username) {
                $validation = ProfileValidator::validateUsername($_POST['new_username']);
                if ($validation['valid']) {
                    $result = $profileService->updateUsername($_POST['new_username']);
                    if ($result['success']) {
                        $username = $_POST['new_username']; // Update local reference
                        $successCount++;
                    } else {
                        $errors[] = $result['error'];
                    }
                } else {
                    $errors[] = $validation['error'];
                }
            }

            // 4. Handle Password Change
            if (!empty($_POST['password'])) {
                $result = $profileService->updatePassword(
                    $_POST['current_password'] ?? '',
                    $_POST['password'],
                    $_POST['confirm_password'] ?? ''
                );
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errors[] = $result['error'];
                }
            }

            // 5. Handle Profile Data Updates (Email, Display Name, Wallet)
            $profileData = [
                'display_name' => $_POST['display_name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'wallet_address' => $_POST['wallet_address'] ?? '',
            ];

            $validation = ProfileValidator::sanitizeProfileData($profileData);
            if (!empty($validation['errors'])) {
                $errors = array_merge($errors, array_values($validation['errors']));
            } elseif (!empty($validation['sanitized'])) {
                // Check if any data actually changed
                $hasChanges = false;
                foreach ($validation['sanitized'] as $key => $val) {
                    if (($currentUser[$key] ?? '') !== $val) {
                        $hasChanges = true;
                        break;
                    }
                }

                if ($hasChanges) {
                    $result = $profileService->updateProfile($validation['sanitized']);
                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $errors = array_merge($errors, $result['errors']);
                    }
                }
            }

            // Finalize Transaction
            if (empty($errors) && $successCount > 0) {
                mysqli_commit($db);
                $message = "System update complete. {$successCount} parameter(s) modified.";
                $messageType = 'success';
            } elseif (!empty($errors)) {
                mysqli_rollback($db);
                $message = "Update failed: " . implode(' | ', $errors);
                $messageType = 'danger';
            } else {
                mysqli_rollback($db);
                $message = "No changes detected.";
                $messageType = 'info';
            }

        } catch (Exception $e) {
            mysqli_rollback($db);
            error_log("Critical profile error: " . $e->getMessage());
            $message = "SYSTEM_ERROR: Transaction aborted.";
            $messageType = 'danger';
        }
    }
}

// Refresh user data after updates
$currentUser = $profileService->getUser();

// ============================================================================
// VIEW RENDERING (Ensure layout start is rendered for POST requests)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $layout = ROOTS\Layout\MasterLayout::createDefault();
    $layout->renderPageStart(
        'My Profile - ROOTS',
        './',
        ['css/profile-custom.css', 'css/screenresolutions.css'],
        true
    );
}

// ============================================================================
// PREPARE VIEW DATA
// ============================================================================
$displayName = $currentUser['display_name'] ?? $currentUser['username'];
$email = $currentUser['email'] ?? '';

// Handle Avatar URL (FileSystem or DataURI)
$avatarUrl = 'img/user.jpg';
if (!empty($currentUser['avatar_url']) && (str_starts_with($currentUser['avatar_url'], 'data:image/') || file_exists(__DIR__ . '/' . $currentUser['avatar_url']))) {
    $avatarUrl = $currentUser['avatar_url'];
}

$subscription = $currentUser['subscription'] ?? 'Basic';
$points = (int) ($currentUser['points'] ?? 0);
$userId = $currentUser['id'] ?? 'ERR';

$cooldowns = [
    'username' => $profileService->checkCooldown('username'),
    'display_name' => $profileService->checkCooldown('display_name'),
    'email' => $profileService->checkCooldown('email'),
    'wallet_address' => $profileService->checkCooldown('wallet_address'),
];

/**
 * @param array<string, mixed> $cooldown
 */
function getCooldownInfo(array $cooldown): string
{
    return $cooldown['allowed']
        ? 'Cooldown: Ready'
        : "Locked: {$cooldown['daysRemaining']} days remaining";
}

// Fetch stats for achievements
$submissions_count = 0;
$normal_purchases = 0;
$password_purchases = 0;
$drone_count = 0;
$drone_disconnected = 0;
$faces_scanned = 0;

if ($db) {
    $statsQuery = "
        SELECT 
            (SELECT COUNT(*) FROM pending_records WHERE submitted_by = ?) as submissions_count,
            (SELECT COUNT(*) FROM user_purchases WHERE user_id = ? AND record_type != 'password_leak') as normal_purchases,
            (SELECT COUNT(*) FROM user_purchases WHERE user_id = ? AND record_type = 'password_leak') as password_purchases,
            (SELECT COUNT(*) FROM drone_registrations WHERE user_id = ?) as drone_count,
            (SELECT COUNT(*) FROM drone_registrations WHERE user_id = ? AND status != 'online') as drone_disconnected,
            (SELECT COALESCE(SUM(scan_count), 0) FROM registered_faces WHERE user_id = ?) as faces_scanned
    ";
    $stmtStats = $db->prepare($statsQuery);
    if ($stmtStats) {
        $stmtStats->bind_param("ssiiii", $username, $username, $username, $userId, $userId, $userId);
        $stmtStats->execute();
        $statsResult = $stmtStats->get_result()->fetch_assoc();
        
        $submissions_count = (int) ($statsResult['submissions_count'] ?? 0);
        $normal_purchases = (int) ($statsResult['normal_purchases'] ?? 0);
        $password_purchases = (int) ($statsResult['password_purchases'] ?? 0);
        $drone_count = (int) ($statsResult['drone_count'] ?? 0);
        $drone_disconnected = (int) ($statsResult['drone_disconnected'] ?? 0);
        $faces_scanned = (int) ($statsResult['faces_scanned'] ?? 0);
        
        $stmtStats->close();
    }
}

// Session Expiration
$createdAt = strtotime($currentUser['created_at'] ?? 'now');
$expiresAt = $createdAt + (30 * 86400); // Assuming 30 days
$daysRemaining = max(0, floor(($expiresAt - time()) / 86400));

// Masked Wallet Address
$rawWallet = $currentUser['wallet_address'] ?? '';
$maskedWallet = 'NOT_SET';
if ($rawWallet) {
    $hash = strtoupper(substr(md5($rawWallet), 0, 15));
    $maskedWallet = '******** ' . substr($hash, 0, 3) . ' *' . substr($hash, 3, 4) . '  ' . substr($hash, 7, 4) . '**' . substr($hash, 11, 4);
}

// ============================================================================
// VIEW RENDERING
// ============================================================================
?>

<style>
/* ===== PERF v1.0: reduce visual effects & speed up rendering ===== */
*,
*::before,
*::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
}

/* Disable scanner/scanline decorative effects (kept in DOM, hidden from paint) */
.scanner-overlay,
.scan-bar,
.scanner-ring {
    display: none !important;
}

/* Skip off-screen rendering work for below-the-fold panels */
.achievements-panel,
.password-panel {
    content-visibility: auto;
    contain-intrinsic-size: auto 300px;
}

html {
    -webkit-font-smoothing: antialiased;
}

@media (prefers-reduced-motion: reduce) {

    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}

.copy-btn {
    background: transparent;
    border: 1px solid rgba(0, 255, 65, 0.4);
    color: #00ff41;
    cursor: pointer;
    padding: 2px 6px;
    font-size: 0.75rem;
    transition: all 0.2s;
}

.copy-btn:hover {
    background: rgba(0, 255, 65, 0.2);
}

.stat-badge {
    background: rgba(0, 255, 65, 0.1);
    border: 1px solid #00ff41;
    color: #00ff41;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    display: inline-block;
    margin-top: 5px;
}

.tier-badge {
    padding: 4px 8px;
    font-weight: bold;
    border: 1px solid currentColor;
    font-size: 0.8rem;
}

.tier-premium {
    color: #ffd700;
    border-color: #ffd700;
    background: rgba(255, 215, 0, 0.1);
}

.tier-pro {
    color: #00f3ff;
    border-color: #00f3ff;
    background: rgba(0, 243, 255, 0.1);
}

.tier-admin {
    color: #ff3333;
    border-color: #ff3333;
    background: rgba(255, 51, 51, 0.1);
}

.tier-basic {
    color: #00ff41;
    border-color: #00ff41;
    background: rgba(0, 255, 65, 0.1);
}

.achievements-panel {
    background: rgba(10, 15, 10, 0.95);
    border: 1px solid rgba(0, 255, 65, 0.2);
    padding: 15px;
    margin-top: 20px;
}

.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    text-align: center;
}

.ach-item {
    border: 1px dashed rgba(0, 255, 65, 0.3);
    padding: 10px;
}

.ach-label {
    font-size: 0.65rem;
    color: #888;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.ach-val {
    font-size: 1.2rem;
    font-weight: bold;
    color: #00ff41;
}

.password-panel {
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(0, 255, 65, 0.2);
    padding: 20px;
    position: relative;
}

.password-panel::before {
    content: '[ SECURITY OVERRIDE ]';
    position: absolute;
    top: -10px;
    left: 15px;
    background: #050505;
    padding: 0 10px;
    font-size: 0.75rem;
    color: #00ff41;
}

.scanner-target-wrap {
    position: relative;
    display: inline-block;
    padding: 10px;
    border: 2px solid rgba(0, 255, 65, 0.5);
    border-radius: 5px;
    background: rgba(0, 255, 65, 0.05);
}
</style>

<div class="scanner-overlay"></div>
<div class="scanner-wrapper">
    <div class="container-fluid flex-grow-1 d-flex flex-column p-0">
        <div class="row g-0 flex-grow-1 justify-content-center align-items-stretch">
            <div class="col-12">
                <div class="terminal-border content h-100 border-0 border-sm-1">
                    <!-- Header -->
                    <div
                        class="d-flex justify-content-between align-items-start mb-4 border-bottom border-success border-opacity-25 pb-3">
                        <div>
                            <h3 class="m-0 mb-1"><i class="fa fa-user-circle me-2"></i>USER_PROFILE_MANAGER</h3>
                            <div class="badge bg-transparent border border-success text-success rounded-0">
                                <span class="me-2">●</span> ENCRYPTED_SESSION_ACTIVE
                            </div>
                        </div>
                        <div class="text-end font-monospace small">
                            <div class="text-muted">NODE: <?= htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'LOCAL') ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($message): ?>
                    <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show mb-4"
                        role="alert">
                        <strong>[ STATUS ]</strong> <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-4">
                            <!-- Left Column: Avatar & Quick Info -->
                            <div class="col-md-4 text-center">
                                <div class="scanner-target-wrap mb-3">
                                    <div class="scanner-target"
                                        style="width: 150px; height: 150px; border-radius: 50%; overflow: hidden; margin: 0 auto;">
                                        <div class="scan-bar"></div>
                                        <div class="scanner-ring"></div>
                                        <img src="<?= htmlspecialchars($avatarUrl) ?>" class="scanner-img" alt="Avatar"
                                            decoding="async" style="width: 100%; height: 100%; object-fit: cover;">
                                    </div>
                                </div>

                                <div class="mt-3 px-2">
                                    <label for="avatar" class="form-label small d-block text-start">>
                                        BIOMETRIC_INPUT</label>
                                    <div class="input-group input-group-sm mb-2">
                                        <input class="form-control bg-dark text-success border-success" type="file"
                                            id="avatar" name="avatar" accept="image/*">
                                    </div>

                                    <?php if (!empty($currentUser['avatar_url'])): ?>
                                    <button type="submit" name="delete_avatar" value="1"
                                        class="btn btn-outline-danger btn-sm w-100 mt-2"
                                        onclick="return confirm('Confirm permanent deletion of biometric record?')">
                                        <i class="fas fa-trash-alt me-1"></i> [ PURGE_DATA ]
                                    </button>
                                    <?php endif; ?>
                                </div>

                                <div
                                    class="mt-4 p-3 bg-dark bg-opacity-50 border border-success border-opacity-25 text-start font-monospace">
                                    <h6 class="small border-bottom border-success border-opacity-25 pb-2 mb-3"><i
                                            class="fas fa-microchip me-2"></i>SYSTEM_INFO</h6>

                                    <div class="mb-3">
                                        <div class="text-muted small mb-1">CLEARANCE_LEVEL:</div>
                                        <?php
                                        $subLower = strtolower($subscription);
                                        $tierClass = 'tier-basic';
                                        if (str_contains($subLower, 'admin'))
                                            $tierClass = 'tier-admin';
                                        elseif (str_contains($subLower, 'premium'))
                                            $tierClass = 'tier-premium';
                                        elseif (str_contains($subLower, 'pro'))
                                            $tierClass = 'tier-pro';
                                        ?>
                                        <span
                                            class="tier-badge <?= $tierClass ?>"><?= htmlspecialchars(strtoupper($subscription)) ?></span>
                                    </div>

                                    <div class="mb-3">
                                        <div class="text-muted small mb-1" style="text-transform: uppercase;">counter:
                                        </div>
                                        <div class="text-white fs-4 fw-bold"><?= $daysRemaining ?> souls</div>
                                        <div style="font-size: 0.30rem; color: #88888818; text-transform: uppercase;">
                                            Убедитесь, что вы знаете, сколько дней осталось.
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <div class="text-muted small mb-1">WALLET BALANCE:</div>
                                        <div class="text-success fs-5"><?= number_format($points) ?> <small>PTS</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column: Details -->
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <!-- Identity -->
                                    <div class="col-md-6">
                                        <label class="form-label small">> ACCOUNT_ID</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-dark text-success font-monospace"
                                                id="accountIdStr" value="<?= htmlspecialchars((string) $userId) ?>"
                                                readonly>
                                            <button class="btn btn-outline-success copy-btn" type="button"
                                                onclick="copyToClipboard('accountIdStr')"><i
                                                    class="fas fa-copy"></i></button>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="new_username" class="form-label small">> CODENAME</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-dark border-success text-success">@</span>
                                            <input type="text" class="form-control bg-dark text-white border-success"
                                                id="new_username" name="new_username"
                                                value="<?= htmlspecialchars($username) ?>"
                                                <?= $cooldowns['username']['allowed'] ? '' : 'readonly' ?>>
                                        </div>
                                        <div class="form-text"><?= getCooldownInfo($cooldowns['username']) ?></div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="display_name" class="form-label small">> PUBLIC_ALIAS</label>
                                        <input type="text" class="form-control bg-dark text-white border-success"
                                            id="display_name" name="display_name"
                                            value="<?= htmlspecialchars($displayName) ?>" required
                                            <?= $cooldowns['display_name']['allowed'] ? '' : 'readonly' ?>>
                                        <div class="form-text"><?= getCooldownInfo($cooldowns['display_name']) ?></div>
                                    </div>

                                    <!-- Communication -->
                                    <div class="col-md-6">
                                        <label for="email" class="form-label small">> SECURE_COMM_LINK</label>
                                        <input type="email" class="form-control bg-dark text-white border-success"
                                            id="email" name="email" value="<?= htmlspecialchars($email) ?>" required
                                            <?= $cooldowns['email']['allowed'] ? '' : 'readonly' ?>>
                                        <div class="form-text"><?= getCooldownInfo($cooldowns['email']) ?></div>
                                    </div>

                                    <!-- Financial -->
                                    <div class="col-12">
                                        <label for="wallet_address" class="form-label small">> WALLET_NODE_ID</label>
                                        <div class="input-group mb-2">
                                            <span class="input-group-text bg-dark border-success text-success"><i
                                                    class="fas fa-wallet"></i></span>
                                            <input type="text"
                                                class="form-control bg-dark font-monospace border-success"
                                                style="color: #aaddaa;" value="<?= $maskedWallet ?>" readonly
                                                id="walletMaskedStr">
                                            <button class="btn btn-outline-success copy-btn" type="button"
                                                onclick="copyToClipboard('wallet_address')"><i
                                                    class="fas fa-copy"></i></button>
                                        </div>
                                        <input type="text"
                                            class="form-control font-monospace bg-dark text-white border-success d-none"
                                            id="wallet_address" name="wallet_address"
                                            value="<?= htmlspecialchars($rawWallet) ?>"
                                            <?= $cooldowns['wallet_address']['allowed'] ? '' : 'readonly' ?>>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mb-1"
                                            onclick="document.getElementById('wallet_address').classList.toggle('d-none'); document.getElementById('walletMaskedStr').classList.toggle('d-none');">EDIT
                                            RAW ADDRESS</button>
                                        <div class="form-text"><?= getCooldownInfo($cooldowns['wallet_address']) ?>
                                        </div>
                                    </div>

                                    <!-- Security -->
                                    <div class="col-12 mt-4">
                                        <div class="password-panel">
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label for="current_password" class="form-label small">>
                                                        CURRENT_KEY</label>
                                                    <input type="password"
                                                        class="form-control bg-dark text-white border-success"
                                                        id="current_password" name="current_password"
                                                        placeholder="********">
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="password" class="form-label small">> NEW_KEY</label>
                                                    <input type="password"
                                                        class="form-control bg-dark text-white border-success"
                                                        id="password" name="password" placeholder="********">
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="confirm_password" class="form-label small">>
                                                        VERIFY_KEY</label>
                                                    <input type="password"
                                                        class="form-control bg-dark text-white border-success"
                                                        id="confirm_password" name="confirm_password"
                                                        placeholder="********">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end mt-4">
                                    <button type="submit" class="btn btn-outline-success px-5 py-2">
                                        <i class="fa fa-save me-2"></i>COMMIT_CHANGES
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Achievements Panel -->
                    <div class="achievements-panel font-monospace">
                        <div class="text-success mb-2 small"><i class="fas fa-trophy me-2"></i>OPERATIVE ACHIEVEMENTS
                        </div>
                        <div class="achievements-grid">
                            <div class="ach-item">
                                <div class="ach-label">DRONE COUNT</div>
                                <div class="ach-val" id="achDroneCount"><?= number_format($drone_count) ?></div>
                            </div>
                            <div class="ach-item">
                                <div class="ach-label">FACES SCANNED</div>
                                <div class="ach-val" id="achFacesScanned"><?= number_format($faces_scanned) ?></div>
                            </div>
                            <div class="ach-item">
                                <div class="ach-label">DISCONNECTED</div>
                                <div class="ach-val text-danger" id="achDisconnected">
                                    <?= number_format($drone_disconnected) ?></div>
                            </div>
                            <div class="ach-item">
                                <div class="ach-label">TARGETS ADDED</div>
                                <div class="ach-val"><?= number_format($submissions_count) ?></div>
                            </div>
                            <div class="ach-item">
                                <div class="ach-label">REG PURCHASES</div>
                                <div class="ach-val"><?= number_format($normal_purchases) ?></div>
                            </div>
                            <div class="ach-item">
                                <div class="ach-label">PW PURCHASES</div>
                                <div class="ach-val text-warning"><?= number_format($password_purchases) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div
                        class="mt-4 pt-3 border-top border-success border-opacity-25 text-center font-monospace small text-muted">
                        ROOTS_OS_v2.5.0 // ESTABLISHED_CONNECTION // <?= date('Y-m-d H:i:s') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(elementId) {
    var copyText = document.getElementById(elementId);
    if (copyText) {
        // Un-hide temporarily if hidden to select
        var wasHidden = copyText.classList.contains('d-none');
        if (wasHidden) copyText.classList.remove('d-none');

        copyText.select();
        copyText.setSelectionRange(0, 99999); /* For mobile devices */
        navigator.clipboard.writeText(copyText.value);

        if (wasHidden) copyText.classList.add('d-none');

        // Minimal visual feedback
        alert("Copied: " + copyText.value);
    }
}
</script>

<?php
PageController::end('./');
?>