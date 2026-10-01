<?php

declare(strict_types=1);

// Handle pin action BEFORE any HTML output (PageController::setup renders layout immediately)
require_once __DIR__ . '/vendor/autoload.php';

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Middleware\SecurityHeadersMiddleware;
use ROOTS\Security\CsrfProtection;
use ROOTS\Services\SensitiveDataService;

// Admin-only action: set pinned purchase (GET with CSRF token)
if (isset($_GET['pin_purchase_id'])) {
    $pinId = (int) $_GET['pin_purchase_id'];
    $provided = (string) ($_GET['csrf_token'] ?? '');

    // Determine admin role without rendering layout
    $isAdmin = Session::isAdmin();

    if ($isAdmin && $pinId > 0 && $provided !== '' && hash_equals((string) CsrfProtection::getToken(), $provided)) {
        $db = Database::getConnection();
        if ($db) {
            // Ensure table exists
            $db->query("CREATE TABLE IF NOT EXISTS pinned_purchases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                purchase_id INT NOT NULL,
                set_by VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uniq_active (active),
                INDEX idx_purchase_id (purchase_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Deactivate all existing pinned purchases first
            $db->query("UPDATE pinned_purchases SET active = 0 WHERE active = 1");

            // Use REPLACE INTO to handle the unique constraint properly
            // REPLACE will delete existing row with active=1 and insert new one
            $setBy = (string) ($_SESSION['username'] ?? 'admin');
            $stmt = $db->prepare("REPLACE INTO pinned_purchases (active, purchase_id, set_by) VALUES (1, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('is', $pinId, $setBy);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    header('Location: my_password_purchases');
    exit;
}

$pageData = PageController::setup('PASSWORD_DB_PURCHASES // HUD_MAX', '../', []);
$csp_nonce_attr = SecurityHeadersMiddleware::getNonceAttribute();
$is_admin = (bool) ($pageData['is_admin'] ?? false);

/**
 * Service for handling Password DB Purchases
 */
class PasswordPurchaseService
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Get User Statistics for Password Leaks only
     * @return array{count: int, total: float}
     */
    public function getUserStats(string $username): array
    {
        $query = "SELECT COUNT(*) as total_purchases, SUM(points_spent) as total_points FROM user_purchases WHERE user_id = ? AND record_type = 'password_leak'";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return ['count' => 0, 'total' => 0];
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            return ['count' => 0, 'total' => 0];
        }
        $data = $result->fetch_assoc();

        $stmt->close();

        return [
            'count' => (int) ($data['total_purchases'] ?? 0),
            'total' => (float) ($data['total_points'] ?? 0)
        ];
    }

    /**
     * Get Password Leak Purchases for User only
     * @return array<int, array<string, mixed>>
     */
    public function getUserPurchases(string $username): array
    {
        $query = "SELECT up.id, up.record_id, up.purchased_at, up.original_data, up.points_spent
                  FROM user_purchases up
                  WHERE up.user_id = ? AND up.record_type = 'password_leak'
                  ORDER BY up.purchased_at DESC";

        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            return [];
        }

        $purchases = [];
        while ($row = $result->fetch_assoc()) {
            $row['data'] = json_decode((string) ($row['original_data'] ?? '{}'), true) ?: [];
            $purchases[] = $row;
        }

        $stmt->close();
        return $purchases;
    }

    /**
     * Create pinned table if missing.
     */
    public function ensurePinnedTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS pinned_purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            set_by VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_active (active),
            INDEX idx_purchase_id (purchase_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Set the pinned purchase (admin-only action).
     * Uses "single active row" pattern: deactivate all then replace active.
     */
    public function setPinnedPurchase(int $purchaseId, string $setBy): bool
    {
        $this->ensurePinnedTable();
        // Deactivate all existing pinned purchases first
        $this->db->query("UPDATE pinned_purchases SET active = 0 WHERE active = 1");
        // Use REPLACE INTO to handle the unique constraint properly
        // REPLACE will delete existing row with active=1 and insert new one
        $stmt = $this->db->prepare("REPLACE INTO pinned_purchases (active, purchase_id, set_by) VALUES (1, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $purchaseId, $setBy);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }

    /**
     * Get currently pinned purchase (public summary only).
     * @return array<string, mixed>|null
     */
    public function getPinnedPurchase(): ?array
    {
        $this->ensurePinnedTable();
        $sql = "SELECT pp.purchase_id, pp.set_by, pp.created_at,
                       up.id, up.record_id, up.purchased_at, up.original_data, up.points_spent
                FROM pinned_purchases pp
                JOIN user_purchases up ON up.id = pp.purchase_id
                WHERE pp.active = 1 AND up.record_type = 'password_leak'
                LIMIT 1";
        $res = $this->db->query($sql);
        if (!$res || $res === true) {
            return null;
        }
        $row = $res->fetch_assoc() ?: null;
        $res->free();
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode((string) ($row['original_data'] ?? '{}'), true) ?: [];
        return $row;
    }
}

// --- Controller Logic ---

$username = (string) ($_SESSION["username"] ?? '');
$stats = ['count' => 0, 'total' => 0];
$purchases = [];
$error_message = '';
$pinned = null;

try {
    $service = new PasswordPurchaseService();

    $pinned = $service->getPinnedPurchase();
    $stats = $service->getUserStats($username);
    $purchases = $service->getUserPurchases($username);
} catch (Exception $e) {
    error_log("PasswordDB Purchases Error: " . $e->getMessage());
    $error_message = "Could not load data.";
}

// Helper for display
/**
 * @param mixed $val
 * @return string
 */
function hudVal($val): string
{
    if (empty($val) || $val === '-') {
        return '<span class="hud-null">N/A</span>';
    }
    return htmlspecialchars($val);
}

/**
 * Public-safe redaction for pinned purchase.
 * Keep only non-sensitive fields to avoid leaking private info.
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function redactPinnedData(array $data): array
{
    $allow = [
        'n', // name
        'nationality',
        'age',
        'birth_date',
        'blood_type',
        'marital_status',
        'children_count',
        'height',
        'weight',
        'skin_color',
        'city',
        'district',
        'subscription',
        'points',
        'status',
        'submission_date',
        'approval_date',
        'created_at',
    ];

    $out = [];
    foreach ($allow as $k) {
        if (array_key_exists($k, $data)) {
            $out[$k] = $data[$k];
        }
    }
    return $out;
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
?>

<link rel="stylesheet" href="css/screenresolutions.css">

<style>
body {
    margin: 0;
    padding: 0;
    overflow-x: hidden;
}

:root {
    --hud-bg: #030507;
    --hud-panel: rgba(10, 20, 30, 0.9);
    --hud-primary: #0f0;
    /* Primary Green */
    --hud-secondary: #0f0;
    /* Green */
    --hud-alert: #ff2a6d;
    /* Pink/Red */
    --hud-text: #0f0;
    --hud-dim: #004400;
    --hud-grid: rgba(0, 255, 0, 0.03);
}

body {
    background-color: var(--hud-bg) !important;
    background-image:
        linear-gradient(var(--hud-grid) 1px, transparent 1px),
        linear-gradient(90deg, var(--hud-grid) 1px, transparent 1px);
    background-size: 30px 30px;
    color: var(--hud-text) !important;
    font-family: 'Segoe UI', 'Roboto Mono', monospace !important;
    margin: 0;
    padding: 0;
    overflow-x: hidden;
}

/* Container */
.hud-wrapper {
    margin: 0 auto;
    padding: 20px;
    position: relative;
    box-sizing: border-box;
}

/* Top Status Bar */
.hud-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-bottom: 2px solid var(--hud-primary);
    padding-bottom: 15px;
    margin-bottom: 40px;
    position: relative;
}

.hud-header::after {
    content: '';
    position: absolute;
    bottom: -2px;
    right: 0;
    width: 100px;
    height: 6px;
    background: var(--hud-primary);
    box-shadow: 0 0 15px var(--hud-primary);
}

.hud-title {
    font-size: 2rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 4px;
    color: var(--hud-primary);
    text-shadow: 0 0 10px rgba(0, 243, 255, 0.5);
    display: flex;
    align-items: center;
    gap: 15px;
}

.hud-meta {
    font-family: 'Roboto Mono', monospace;
    font-size: 0.9rem;
    color: var(--hud-dim);
    text-align: right;
    line-height: 1.4;
}

.hud-meta span {
    color: var(--hud-primary);
}

/* HUD Stats */
.hud-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.hud-stat-panel {
    background: var(--hud-panel);
    border: 1px solid rgba(0, 243, 255, 0.2);
    padding: 15px;
    position: relative;
    clip-path: polygon(0 0,
            100% 0,
            100% calc(100% - 20px),
            calc(100% - 20px) 100%,
            0 100%);
    backdrop-filter: blur(5px);
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
}

.hud-stat-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 20px;
    height: 20px;
    border-top: 2px solid var(--hud-primary);
    border-left: 2px solid var(--hud-primary);
}

.hud-stat-label {
    font-family: 'Roboto Mono', monospace;
    color: var(--hud-dim);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 5px;
}

.hud-stat-value {
    font-size: 1.8rem;
    font-weight: bold;
    color: var(--hud-primary);
    text-shadow: 0 0 8px var(--hud-primary);
}

/* Table Container */
.hud-table-container {
    background: var(--hud-panel);
    border: 1px solid rgba(0, 243, 255, 0.2);
    padding: 20px;
    position: relative;
    clip-path: polygon(0 0,
            100% 0,
            100% calc(100% - 20px),
            calc(100% - 20px) 100%,
            0 100%);
    backdrop-filter: blur(5px);
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
    margin-bottom: 30px;
    overflow: auto;
}

.hud-table-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 20px;
    height: 20px;
    border-top: 2px solid var(--hud-primary);
    border-left: 2px solid var(--hud-primary);
}

/* Table Styles */
.hud-table {
    width: 100%;
    border-collapse: collapse;
    font-family: 'Roboto Mono', monospace;
}

.hud-table th {
    background: rgba(0, 243, 255, 0.1);
    color: var(--hud-primary);
    padding: 12px;
    text-align: left;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 1px;
    border-bottom: 2px solid var(--hud-primary);
}

.hud-table td {
    padding: 12px;
    border-bottom: 1px solid rgba(0, 243, 255, 0.1);
    color: var(--hud-text);
}

.hud-table tr:hover {
    background: rgba(0, 243, 255, 0.05);
}

.hud-table tr:hover td {
    text-shadow: 0 0 5px var(--hud-primary);
}

/* Data Display */
.data-field {
    margin-bottom: 3px;
}

.data-label {
    display: inline-block;
    width: 20px;
    color: var(--hud-primary);
    font-weight: bold;
}

/* Buttons */
.hud-btn {
    background: transparent;
    border: 1px solid var(--hud-primary);
    color: var(--hud-primary);
    padding: 8px 15px;
    text-transform: uppercase;
    font-weight: bold;
    letter-spacing: 1px;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    margin: 2px;
}

.hud-btn:hover {
    background: rgba(0, 243, 255, 0.1);
    box-shadow: 0 0 15px rgba(0, 243, 255, 0.4);
    text-shadow: 0 0 5px var(--hud-primary);
}

.hud-btn.danger {
    border-color: var(--hud-alert);
    color: var(--hud-alert);
}

.hud-btn.danger:hover {
    background: rgba(255, 42, 109, 0.1);
    box-shadow: 0 0 15px rgba(255, 42, 109, 0.4);
    text-shadow: 0 0 5px var(--hud-alert);
}

/* Empty State */
.hud-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--hud-dim);
}

.hud-empty h3 {
    color: var(--hud-primary);
    margin-bottom: 20px;
    text-transform: uppercase;
    letter-spacing: 2px;
}

.hud-null {
    color: rgba(255, 255, 255, 0.2);
    font-style: italic;
}

/* Actions */
.hud-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

/* Details Panel */
.hud-details-panel {
    background: rgba(0, 20, 30, 0.95);
    border: 1px solid var(--hud-primary);
    padding: 20px;
    margin: 10px 0;
    position: relative;
    clip-path: polygon(0 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%);
}

.hud-details-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 15px;
    height: 15px;
    border-top: 2px solid var(--hud-primary);
    border-left: 2px solid var(--hud-primary);
}

.hud-details-header {
    border-bottom: 1px solid var(--hud-primary);
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.hud-details-header h4 {
    color: var(--hud-primary);
    margin: 0;
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 2px;
}

.hud-details-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 10px;
}

.hud-detail-item {
    background: rgba(0, 243, 255, 0.05);
    padding: 8px 12px;
    border-left: 2px solid var(--hud-primary);
}

.hud-detail-label {
    color: var(--hud-primary);
    font-weight: bold;
    font-size: 0.75rem;
    text-transform: uppercase;
    display: block;
    margin-bottom: 4px;
}

.hud-detail-value {
    color: var(--hud-text);
    font-size: 0.85rem;
    word-break: break-all;
}
</style>

<div class="hud-wrapper">

    <div class="hud-header">
        <div class="hud-title">
            <i class="fas fa-key"></i> PASSWORD_DB_PURCHASES
        </div>
        <div class="hud-meta">
            <div>USER: <span><?= htmlspecialchars($username) ?></span></div>
            <div>STATUS: <span>ONLINE</span></div>
            <div>MODE: <span>PASSWORD_ONLY</span></div>
        </div>
    </div>
    <?php if (!empty($purchases)): ?>
    <div class="hud-stats">
        <div class="hud-stat-panel">
            <div class="hud-stat-label">RECORDS_FOUND</div>
            <div class="hud-stat-value"><?= $stats['count'] ?></div>
        </div>
        <div class="hud-stat-panel">
            <div class="hud-stat-label">POINTS_EXPENDED</div>
            <div class="hud-stat-value"><?= number_format($stats['total']) ?></div>
        </div>
        <div class="hud-stat-panel">
            <div class="hud-stat-label">SYSTEM_MODE</div>
            <div class="hud-stat-value">PASSWORD_DB</div>
        </div>
    </div>

    <div class="hud-table-container">
        <table class="hud-table">
            <thead>
                <tr>
                    <th><i class="fas fa-hashtag"></i> ID</th>
                    <th><i class="fas fa-user"></i> TARGET_DATA</th>
                    <th><i class="fas fa-coins"></i> COST</th>
                    <th><i class="fas fa-calendar"></i> ACQUIRED</th>
                    <th><i class="fas fa-cogs"></i> ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($purchases as $p):
                        $purchase_id = (int) $p['id'];
                        $record_id = htmlspecialchars((string) $p['record_id']);
                        $points = number_format((float) $p['points_spent']);
                        $date = date('Y.m.d H:i', strtotime($p['purchased_at']));

                        $view_token = '';
                        try {
                            $payload = json_encode([
                                'purchase_id' => $purchase_id,
                                'u' => $username,
                                'exp' => time() + 1800,
                                'nonce' => bin2hex(random_bytes(16))
                            ], JSON_UNESCAPED_UNICODE);
                            $encrypted = SensitiveDataService::encrypt($payload ?: null);
                            $view_token = $encrypted !== null ? base64UrlEncode($encrypted) : '';
                        } catch (Exception $e) {
                            $view_token = '';
                        }

                        $d = $p['data'];
                        $name = hudVal($d['n'] ?? '');
                        $email = hudVal($d['e'] ?? '');
                        $phone = hudVal($d['t'] ?? '');
                        $password = hudVal($d['password_plaintext'] ?? $d['password_hash'] ?? '');

                        // Safe name for JS
                        $safeName = htmlspecialchars(addslashes($d['n'] ?? ''));
                        $uniqueId = 'purchase_' . $purchase_id;
                        ?>
                <tr>
                    <td style="color: var(--hud-primary); font-weight: bold;">#<?= $record_id ?></td>
                    <td>
                        <div style="font-size: 0.9rem;">
                            <div class="data-field">
                                <span class="data-label">[N]</span> <?= $name ?>
                            </div>
                            <div class="data-field">
                                <span class="data-label">[E]</span> <?= $email ?>
                            </div>
                            <div class="data-field">
                                <span class="data-label">[P]</span> <?= $phone ?>
                            </div>
                            <div class="data-field" style="color: #ff0; font-weight: bold;">
                                <span class="data-label">[K]</span> <?= $password ?>
                            </div>
                        </div>
                    </td>
                    <td style="color: var(--hud-secondary);"><?= $points ?> PTS</td>
                    <td style="color: var(--hud-dim); font-size: 0.8rem;"><?= $date ?></td>
                    <td>
                        <div class="hud-actions">
                            <button class="hud-btn" type="button" data-action="toggle-details"
                                data-target="<?= htmlspecialchars($uniqueId, ENT_QUOTES) ?>">
                                <i class="fas fa-eye"></i> VIEW
                            </button>
                            <button class="hud-btn danger" type="button" data-action="delete"
                                data-purchase-id="<?= $purchase_id ?>" data-record-id="<?= $record_id ?>"
                                data-name="<?= htmlspecialchars($d['n'] ?? '', ENT_QUOTES) ?>">
                                <i class="fas fa-trash"></i> DELETE
                            </button>
                        </div>
                    </td>
                </tr>
                <tr id="<?= $uniqueId ?>_row" style="display: none;">
                    <td colspan="5">
                        <div class="hud-details-panel" id="<?= $uniqueId ?>_panel">
                            <div class="hud-details-header">
                                <h4>PURCHASE DETAILS #<?= $record_id ?></h4>
                            </div>
                            <div class="hud-details-content">
                                <?php foreach ($d as $key => $value): ?>
                                <?php if (!empty($value) && strtolower((string)$key) !== 'source'):
                                                $isPassword = in_array(strtolower((string)$key), ['password_plaintext', 'password_hash', 'password', 'hash']);
                                                $itemStyle = $isPassword ? 'border-left: 3px solid #ff0; background: rgba(255, 255, 0, 0.1);' : '';
                                                $labelStyle = $isPassword ? 'color: #ff0;' : '';
                                            ?>
                                <div class="hud-detail-item" style="<?= $itemStyle ?>">
                                    <span class="hud-detail-label"
                                        style="<?= $labelStyle ?>"><?= htmlspecialchars(strtoupper((string)$key)) ?>:</span>
                                    <span
                                        class="hud-detail-value"><?= is_array($value) ? htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '') : htmlspecialchars((string)$value) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="hud-empty">
        <h3>NO_PASSWORD_RECORDS_FOUND</h3>
        <p>No password leak purchase records in database.</p>
        <div style="margin-top: 30px;">
            <a href="password_db" class="hud-btn">
                <i class="fas fa-search"></i> INITIATE_PASSWORD_SEARCH
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- JS Manager -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.15.2/dist/sweetalert2.all.min.js"
    integrity="sha384-sDPrkvETPutaBBLV0zJmzduG+PxF88FtQ/8gAf7+0WwtV8AkyArHc8guhSUfcV4h" crossorigin="anonymous">
</script>

<script <?= $csp_nonce_attr ?>>
// Minimal Terminal SweetAlert
const termSwal = Swal.mixin({
    customClass: {
        popup: 'bg-black border border-danger text-danger font-monospace rounded-0',
        confirmButton: 'btn btn-outline-danger btn-sm rounded-0 mx-1',
        cancelButton: 'btn btn-outline-secondary btn-sm rounded-0 mx-1'
    },
    buttonsStyling: false,
    background: '#000',
    color: '#ff3333',
    showConfirmButton: true,
    width: '350px'
});

const PasswordPurchaseManager = {
    toggleDetails: function(uniqueId) {
        const row = document.getElementById(uniqueId + '_row');
        if (row) {
            row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
        }
    },
    delete: async function(id, recordId, name) {
        const confirmResult = await termSwal.fire({
            title: 'DELETE RECORD?',
            text: `Target: ${name}`,
            showCancelButton: true,
            confirmButtonText: '[ CONFIRM ]',
            cancelButtonText: '[ ABORT ]',
            reverseButtons: true
        });

        if (!confirmResult.isConfirmed) return;

        try {
            const response = await fetch('delete_from_database', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '<?= CsrfProtection::getToken() ?>'
                },
                body: JSON.stringify({
                    purchase_id: id,
                    record_id: recordId,
                    record_type: 'password_leak',
                    csrf_token: '<?= CsrfProtection::getToken() ?>'
                })
            });

            const result = await response.json();

            if (result.success) {
                await termSwal.fire({
                    title: 'SUCCESS',
                    text: 'Record purged.',
                    timer: 1500,
                    showConfirmButton: false
                });
                window.location.reload();
            } else {
                await termSwal.fire({
                    title: 'FAILED',
                    text: result.message || 'Error occurred',
                    confirmButtonText: '[ CLOSE ]'
                });
            }
        } catch (e) {
            console.error(e);
            await termSwal.fire({
                title: 'SYSTEM ERROR',
                text: 'Connection lost',
                confirmButtonText: '[ CLOSE ]'
            });
        }
    }
};
document.addEventListener('click', function(event) {
    const button = event.target.closest('button[data-action]');
    if (!button) {
        return;
    }

    const action = button.dataset.action;
    if (action === 'toggle-details') {
        PasswordPurchaseManager.toggleDetails(button.dataset.target || '');
    }

    if (action === 'delete') {
        const purchaseId = parseInt(button.dataset.purchaseId || '0', 10);
        const recordId = parseInt(button.dataset.recordId || '0', 10);
        const name = button.dataset.name || '';
        PasswordPurchaseManager.delete(purchaseId, recordId, name);
    }
});
</script>

<?php

PageController::end('../');