<?php
declare(strict_types=1);

namespace ROOTS\SearchDB;

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Controllers\PageController;
use ROOTS\Config\Database;
use ROOTS\Middleware\SecurityHeadersMiddleware;
use ROOTS\Services\SensitiveDataService;
use ROOTS\Services\ValidationService;
use Exception;
use InvalidArgumentException;
use mysqli;

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
// Detect if running on Onion service
$isOnionService =
    (isset($_SERVER["HTTP_HOST"]) &&
        preg_match('/\.onion$/i', $_SERVER["HTTP_HOST"])) ||
    (isset($_SERVER["SERVER_NAME"]) &&
        preg_match('/\.onion$/i', $_SERVER["SERVER_NAME"]));

// ============================================================================
// PAGE SETUP & AUTHENTICATION
// ============================================================================
$base_path = "../";
$css_files = [];
$pageData = PageController::setup(
    "SECURE_VIEW // HUD_MAX",
    $base_path,
    $css_files,
    ["require_auth" => true, "session_lifetime" => 3600]
);

$is_admin = $pageData["is_admin"];
$user_data = $pageData["user"];
$current_user = $user_data["username"];
$csrf_token = $pageData["csrf_token"];

// Define constant for redirect location
const REDIRECT_MY_PURCHASES = "Location: my_purchases";

// Define constant for base64 image data URI prefix
const BASE64_JPEG_PREFIX = "data:image/jpeg;base64,";

// Get CSP nonce for inline scripts
$csp_nonce_attr = SecurityHeadersMiddleware::getNonceAttribute();

class ViewPurchaseService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get purchase data for a user
     *
     * @param int $purchaseId Purchase ID
     * @param string $username Username
     * @param bool $isAdmin Is admin user
     * @return array<string, mixed> Purchase data or empty array
     */
    public function getPurchase(
        int $purchaseId,
        string $username,
        bool $isAdmin,
    ): array {
        // Admins can view any purchase, others can only view their own.
        $query = "SELECT up.id, up.record_id, up.purchased_at, up.original_data, up.points_spent,
                  s.u, s.n, s.e, s.t, s.a, s.address, s.birth_cert, s.birth_certificate_number,
                  s.nationality, s.marital_status, s.birth_date, s.age, s.blood_type,
                  s.height, s.weight, s.skin_color, s.city, s.district, s.street,
                  s.building_number, s.apartment_number, s.postal_code, s.relatives,
                  s.children_count, s.personal_car_number, s.social_media, s.bank_accounts
                  FROM user_purchases up
                  LEFT JOIN search s ON up.record_id = s.id
                  WHERE up.id = ?";
        if (!$isAdmin) {
            $query .= " AND up.user_id = ?";
        }

        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            error_log("DB Prepare failed: " . $this->db->error);
            return [];
        }

        if ($isAdmin) {
            $stmt->bind_param("i", $purchaseId);
        } else {
            $stmt->bind_param("is", $purchaseId, $username);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $stmt->close();

        return is_array($row) ? $row : [];
    }
}

function base64UrlDecode(string $data): ?string
{
    $data = strtr($data, "-_", "+/");
    $pad = strlen($data) % 4;
    if ($pad !== 0) {
        $data .= str_repeat("=", 4 - $pad);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? null : $decoded;
}

// Handle POST to GET redirection to hide token from URL
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["t"])) {
    $token = (string) $_POST["t"];
    $_SESSION["temp_view_token"] = $token;
    header("Location: view_purchase");
    exit();
}

// Validate purchase ID from GET parameter (supports encrypted token 't', legacy 'id', or session token)
$purchase_id = 0;
$using_token = false;
$token_nonce = null;
$token_user = "";
$token_exp = 0;
try {
    $token = null;
    if (!empty($_GET["t"])) {
        $token = (string) $_GET["t"];
    } elseif (!empty($_SESSION["temp_view_token"])) {
        $token = (string) $_SESSION["temp_view_token"];
        unset($_SESSION["temp_view_token"]); // Use only once
    }

    if ($token !== null) {
        $token = rawurldecode($token);

        $decoded = base64UrlDecode($token);
        if ($decoded === null) {
            throw new InvalidArgumentException("Invalid token encoding");
        }

        $payloadJson = SensitiveDataService::decrypt($decoded);
        if ($payloadJson === null) {
            throw new InvalidArgumentException("Invalid token");
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException("Invalid token payload");
        }

        $purchase_id = ValidationService::validateInt(
            $payload["purchase_id"] ?? 0,
            "purchase_id",
            1,
        );

        $nonce = (string) ($payload["nonce"] ?? "");
        if (!preg_match('/^[a-f0-9]{32}$/i', $nonce)) {
            throw new InvalidArgumentException("Invalid token nonce");
        }

        $tokenUser = (string) ($payload["u"] ?? "");
        $exp = (int) ($payload["exp"] ?? 0);
        if ($exp > 0 && time() > $exp) {
            throw new InvalidArgumentException("Token expired");
        }

        $sessionUser = (string) ($_SESSION["username"] ?? "");
        if (!$is_admin && $tokenUser !== "" && $tokenUser !== $sessionUser) {
            throw new InvalidArgumentException("Token user mismatch");
        }

        $using_token = true;
        $token_nonce = $nonce;
        $token_user = $tokenUser;
        $token_exp = $exp;
    } else {
        $purchase_id = ValidationService::validateInt(
            $_GET["id"] ?? 0,
            "id",
            1,
        );
    }
} catch (\Throwable $e) {
    header(REDIRECT_MY_PURCHASES);
    exit();
}

$con = Database::getConnection();
$current_user = (string) ($_SESSION["username"] ?? "");
$service = new ViewPurchaseService($con);

if ($using_token) {
    if (!isset($_SESSION["view_link_session_key"])) {
        $_SESSION["view_link_session_key"] = bin2hex(random_bytes(32));
    }
    $sessionKey = (string) $_SESSION["view_link_session_key"];

    if (!is_string($token_nonce)) {
        header(REDIRECT_MY_PURCHASES);
        exit();
    }

    $create = $con->query("CREATE TABLE IF NOT EXISTS purchase_view_tokens (
        nonce CHAR(32) PRIMARY KEY,
        purchase_id INT NOT NULL,
        username VARCHAR(50) NOT NULL,
        expires_at DATETIME NOT NULL,
        first_used_at DATETIME NOT NULL,
        session_key CHAR(64) NOT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username),
        INDEX idx_purchase_id (purchase_id),
        INDEX idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($create === false) {
        header(REDIRECT_MY_PURCHASES);
        exit();
    }

    $stmt = $con->prepare(
        "SELECT session_key, expires_at, username, purchase_id FROM purchase_view_tokens WHERE nonce = ? LIMIT 1",
    );
    if (!$stmt) {
        header(REDIRECT_MY_PURCHASES);
        exit();
    }

    $stmt->bind_param("s", $token_nonce);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();

    if ($row === null) {
        $expiresAt = date(
            "Y-m-d H:i:s",
            $token_exp > 0 ? $token_exp : time() + 1800,
        );
        $ip = $_SERVER["REMOTE_ADDR"] ?? null;

        $ins = $con->prepare(
            "INSERT INTO purchase_view_tokens (nonce, purchase_id, username, expires_at, first_used_at, session_key, ip_address) VALUES (?, ?, ?, ?, NOW(), ?, ?)",
        );
        if (!$ins) {
            header(REDIRECT_MY_PURCHASES);
            exit();
        }
        $ins->bind_param(
            "sissss",
            $token_nonce,
            $purchase_id,
            $token_user,
            $expiresAt,
            $sessionKey,
            $ip,
        );
        $ok = $ins->execute();
        $insErr = $ins->errno;
        $ins->close();

        if (!$ok) {
            if ($insErr === 1062) {
                $stmt = $con->prepare(
                    "SELECT session_key FROM purchase_view_tokens WHERE nonce = ? LIMIT 1",
                );
                if (!$stmt) {
                    header(REDIRECT_MY_PURCHASES);
                    exit();
                }
                $stmt->bind_param("s", $token_nonce);
                $stmt->execute();
                $res = $stmt->get_result();
                $existing = $res ? ($res->fetch_assoc() ?: null) : null;
                $stmt->close();
                if (
                    !$is_admin &&
                    (!$existing ||
                        !hash_equals(
                            (string) ($existing["session_key"] ?? ""),
                            $sessionKey,
                        ))
                ) {
                    header(REDIRECT_MY_PURCHASES);
                    exit();
                }
            } else {
                header(REDIRECT_MY_PURCHASES);
                exit();
            }
        }
    } else {
        $dbExp = strtotime((string) ($row["expires_at"] ?? ""));
        if ($dbExp !== false && time() > $dbExp) {
            header(REDIRECT_MY_PURCHASES);
            exit();
        }
        if (
            !$is_admin &&
            !hash_equals((string) ($row["session_key"] ?? ""), $sessionKey)
        ) {
            header(REDIRECT_MY_PURCHASES);
            exit();
        }
    }

    if (
        !isset($_SESSION["view_purchase_loads"]) ||
        !is_array($_SESSION["view_purchase_loads"])
    ) {
        $_SESSION["view_purchase_loads"] = [];
    }
    $_SESSION["view_purchase_loads"][$token_nonce] =
        (int) ($_SESSION["view_purchase_loads"][$token_nonce] ?? 0) + 1;
    if ($_SESSION["view_purchase_loads"][$token_nonce] > 30) {
        header(REDIRECT_MY_PURCHASES);
        exit();
    }
}

$purchase = $service->getPurchase($purchase_id, $current_user, $is_admin);
if (empty($purchase)) {
    // Use a session message to provide context to the user.
    $_SESSION["message"] =
        "ACCESS_DENIED: Record not found or permission denied.";
    $_SESSION["messageType"] = "error";
    // Use meta refresh to avoid headers already sent error (CSP-compliant)
    echo '<meta http-equiv="refresh" content="0;url=my_purchases">';
    exit();
}

// Safely decode JSON data
$record_data = [];
if (!empty($purchase["original_data"])) {
    $decoded_data = json_decode((string) $purchase["original_data"], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_data)) {
        $record_data = $decoded_data;
    }
}

// Merge with direct database fields to ensure encrypted fields are available
// Prioritize database fields for encrypted data
$encrypted_fields = ['a', 'access_code', 'e', 'email', 't', 'phone'];
foreach ($encrypted_fields as $field) {
    if (isset($purchase[$field])) {
        $record_data[$field] = $purchase[$field];
    }
}

// Merge other fields
foreach ($purchase as $key => $value) {
    if (!isset($record_data[$key])) {
        $record_data[$key] = $value;
    }
}

function safeExternalUrl(string $url): ?string
{
    $url = trim($url);
    if ($url === "" || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts["scheme"] ?? ""));
    if (!in_array($scheme, ["http", "https"], true)) {
        return null;
    }
    return $url;
}

// Secure helper for display
function hudVal(mixed $val): string
{
    if (empty($val) || $val === "-") {
        return '<span class="hud-null">N/A</span>';
    }
    return htmlspecialchars((string) $val, ENT_QUOTES, "UTF-8");
}
?>

<style>
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
        min-height: 100vh;
    }

    /* Container */
    .hud-wrapper {
        width: 100vw;
        height: 100vh;
        margin: 0;
        padding: 10px;
        position: relative;
        box-sizing: border-box;
    }

    /* Top Status Bar */
    .hud-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        border-bottom: 2px solid var(--hud-primary);
        padding-bottom: 10px;
        margin-bottom: 20px;
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
        text-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
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

    /* Layout Grid */
    .hud-main-grid {
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 15px;
    }

    /* Panel Styles */
    .hud-panel {
        background: var(--hud-panel);
        border: 1px solid rgba(0, 255, 0, 0.2);
        padding: 15px;
        position: relative;
        margin-bottom: 15px;
        /* Angled Corners */
        clip-path: polygon(0 0,
                100% 0,
                100% calc(100% - 15px),
                calc(100% - 15px) 100%,
                0 100%);
        backdrop-filter: blur(5px);
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
    }

    /* Decorative Corner Tick */
    .hud-panel::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 20px;
        height: 20px;
        border-top: 2px solid var(--hud-primary);
        border-left: 2px solid var(--hud-primary);
    }

    .hud-section-header {
        font-family: 'Roboto Mono', monospace;
        color: var(--hud-secondary);
        font-size: 0.85rem;
        letter-spacing: 2px;
        text-transform: uppercase;
        border-bottom: 1px solid rgba(0, 255, 0, 0.1);
        padding-bottom: 5px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .hud-section-header i {
        color: var(--hud-primary);
    }

    /* Data Fields */
    .data-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .data-grid-3 {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 10px;
    }

    .data-row {
        margin-bottom: 5px;
        display: flex;
        flex-direction: column;
    }

    .data-label {
        font-size: 0.75rem;
        color: var(--hud-dim);
        text-transform: uppercase;
        margin-bottom: 2px;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .data-value {
        font-size: 1.05rem;
        color: var(--hud-text);
        border-left: 2px solid var(--hud-dim);
        padding-left: 10px;
        transition: 0.3s;
        word-break: break-word;
    }

    .data-value:hover {
        border-left-color: var(--hud-primary);
        background: linear-gradient(90deg, rgba(0, 255, 0, 0.05), transparent);
        text-shadow: 0 0 8px var(--hud-primary);
    }

    .hud-null {
        color: rgba(255, 255, 255, 0.2);
        font-style: italic;
        font-size: 0.9em;
    }

    /* Image Styling */
    .img-display {
        width: 100%;
        margin-bottom: 10px;
        position: relative;
        border: 1px solid var(--hud-dim);
        background: #000;
        min-height: 150px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .img-display img {
        max-width: 100%;
        height: auto;
        display: block;
        filter: contrast(1.1) grayscale(30%) sepia(20%) hue-rotate(180deg);
        transition: 0.4s;
        cursor: pointer;
    }

    .img-display img:hover {
        filter: none;
    }

    /* Reticle Overlay on Images */
    .img-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
        box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.8);
        border: 1px solid rgba(0, 255, 0, 0.3);
    }

    .img-overlay::after {
        content: 'SCANNING';
        position: absolute;
        bottom: 5px;
        right: 5px;
        background: var(--hud-primary);
        color: #000;
        font-size: 0.6rem;
        padding: 2px 4px;
        font-weight: bold;
    }

    /* Buttons */
    .hud-actions {
        display: flex;
        gap: 15px;
        margin-top: 30px;
        justify-content: flex-end;
    }

    .hud-btn {
        background: transparent;
        border: 1px solid var(--hud-primary);
        color: var(--hud-primary);
        padding: 12px 25px;
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
    }

    .hud-btn:hover {
        background: rgba(0, 255, 0, 0.1);
        box-shadow: 0 0 15px rgba(0, 255, 0, 0.4);
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

    .hud-btn.back {
        border-color: var(--hud-dim);
        color: var(--hud-dim);
        margin-right: auto;
    }

    .hud-btn.back:hover {
        color: #fff;
        border-color: #fff;
    }

    /* Alert Box */
    .hud-alert-box {
        border-left: 4px solid var(--hud-primary);
        background: linear-gradient(90deg, rgba(0, 255, 0, 0.1), transparent);
        padding: 15px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        color: var(--hud-primary);
        font-weight: bold;
        animation: slideIn 0.5s ease-out;
    }

    @keyframes slideIn {
        from {
            transform: translateX(-20px);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* Responsive */
    @media (max-width: 900px) {
        .hud-main-grid {
            grid-template-columns: 1fr;
        }

        .hud-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .hud-meta {
            text-align: left;
        }
    }

    /* Lightbox */
    .lightbox-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 5, 10, 0.95);
        z-index: 10000;
        display: none;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(10px);
    }

    .lightbox-modal.active {
        display: flex;
    }

    .lightbox-content {
        border: 1px solid var(--hud-primary);
        padding: 5px;
        background: rgba(0, 0, 0, 0.8);
        box-shadow: 0 0 30px rgba(0, 255, 0, 0.2);
    }

    .lightbox-image {
        max-width: 90vw;
        max-height: 80vh;
        display: block;
    }

    .lightbox-caption {
        padding: 10px;
        text-align: center;
        color: var(--hud-primary);
        text-transform: uppercase;
        letter-spacing: 2px;
        font-weight: bold;
    }
</style>

<div class="hud-wrapper">

    <?php if (isset($_SESSION["success_message"])): ?>
        <div class="hud-alert-box">
            <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i>
            <div>
                <div style="font-size:0.7rem; opacity:0.7;">SYSTEM NOTIFICATION</div>
                <?= $_SESSION["success_message"] ?>
            </div>
        </div>
        <?php unset($_SESSION["success_message"]);endif; ?>

    <div class="hud-header">
        <div class="hud-title">
            <i class="fas fa-shield-alt"></i> CLASSIFIED_VIEW
        </div>
        <div class="hud-meta">
            <div>REC_ID: <span>#<?= htmlspecialchars(
                (string) $purchase["record_id"],
            ) ?></span></div>
            <div>DB_INDEX: <span><?= hudVal(
                $record_data["id"] ?? "",
            ) ?></span></div>
            <div>ACQUIRED:
                <span><?= htmlspecialchars(
                    date("Y.m.d H:i:s", strtotime($purchase["purchased_at"])),
                ) ?></span>
            </div>
        </div>
    </div>

    <div class="hud-main-grid">

        <div style="display: flex; flex-direction: column;">

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-camera"></i> VISUAL_INTEL</div>

                <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px;">PRIMARY_TARGET (person_photo)
                </div>
                <div class="img-display"
                    data-lightbox-src="<?= !empty($record_data["person_photo"]) ? BASE64_JPEG_PREFIX . htmlspecialchars((string) $record_data["person_photo"], ENT_QUOTES, 'UTF-8') : '' ?>"
                    data-lightbox-caption="SUBJECT PHOTO">
                    <?php if (!empty($record_data["person_photo"])): ?>
                        <img src="<?= BASE64_JPEG_PREFIX . htmlspecialchars(
                            (string) $record_data["person_photo"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>" alt="Target">
                        <div class="img-overlay"></div>
                    <?php else: ?>
                        <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                    <?php endif; ?>
                </div>

                <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px; margin-top: 15px;">
                    PROFILE_AVATAR (profile_image)</div>
                <div class="img-display"
                    data-lightbox-src="<?= !empty($record_data["profile_image"]) ? BASE64_JPEG_PREFIX . htmlspecialchars((string) $record_data["profile_image"], ENT_QUOTES, 'UTF-8') : '' ?>"
                    data-lightbox-caption="PROFILE AVATAR">
                    <?php if (!empty($record_data["profile_image"])): ?>
                        <img src="<?= BASE64_JPEG_PREFIX . htmlspecialchars(
                            (string) $record_data["profile_image"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>" alt="Profile">
                        <div class="img-overlay"></div>
                    <?php else: ?>
                        <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                    <?php endif; ?>
                </div>

                <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px; margin-top: 15px;">
                    DOCUMENT_SCAN (id_card)</div>
                <button class="img-display"
                    type="button"
                    data-lightbox-src="<?= !empty($record_data["id_card_file"]) ? BASE64_JPEG_PREFIX . htmlspecialchars((string) $record_data["id_card_file"], ENT_QUOTES, 'UTF-8') : '' ?>"
                    data-lightbox-caption="ID CARD SCAN">
                    <?php if (!empty($record_data["id_card_file"])): ?>
                        <img src="<?= BASE64_JPEG_PREFIX . htmlspecialchars(
                            (string) $record_data["id_card_file"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>" alt="ID Card">
                        <div class="img-overlay"></div>
                    <?php else: ?>
                        <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                    <?php endif; ?>
                </button>

                <div style="font-size: 0.7rem; color: var(--hud-dim); margin-bottom: 5px; margin-top: 15px;">
                    DRIVING_LICENSE (driving_license)</div>
                <button class="img-display"
                    type="button"
                    data-lightbox-src="<?= !empty($record_data["driving_license_image"]) ? BASE64_JPEG_PREFIX . htmlspecialchars((string) $record_data["driving_license_image"], ENT_QUOTES, 'UTF-8') : '' ?>"
                    data-lightbox-caption="DRIVING LICENSE IMAGE">
                    <?php if (!empty($record_data["driving_license_image"])): ?>
                        <img src="<?= BASE64_JPEG_PREFIX . htmlspecialchars(
                            (string) $record_data["driving_license_image"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>" alt="Driving License">
                        <div class="img-overlay"></div>
                    <?php else: ?>
                        <div style="color: var(--hud-dim); font-size: 0.8em;">NO_SIGNAL</div>
                    <?php endif; ?>
                </button>

            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-microchip"></i> TECH_METADATA</div>
                <div class="data-row">
                    <span class="data-label">SOURCE_USER (u)</span>
                    <span class="data-value"><?= hudVal(
                        $record_data["u"] ?? "",
                    ) ?></span>
                </div>
                <div class="data-row">
                    <span class="data-label">PHOTO_METADATA</span>
                    <div class="data-value" style="font-size: 0.8rem; font-family: monospace;">
                        <?= hudVal(
                            $record_data["person_photo_metadata"] ?? "",
                        ) ?>
                    </div>
                </div>
            </div>

        </div>

        <div style="display: flex; flex-direction: column;">

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-id-card"></i> Personal Identity</div>
                <div class="data-row">
                    <span class="data-label"><i class="fas fa-user" style="margin-right:4px;"></i> Full Name</span>
                    <span class="data-value"
                        style="font-size: 1.2rem; color: var(--hud-primary);"><?= hudVal(
                            $record_data["n"] ?? "",
                        ) ?></span>
                </div>

                <div class="data-grid-2" style="margin-top: 10px;">
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-user-tag" style="margin-right:4px;"></i> Username</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["u"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-key" style="margin-right:4px;"></i> ID Number (Access Code)</span>
                        <span class="data-value">
                            <?php
                            $access_code = $purchase["a"] ?? "";
                            if (!empty($access_code)) {
                                try {
                                    $decrypted = SensitiveDataService::decrypt($access_code);
                                    echo hudVal($decrypted ?: "");
                                } catch (\Exception $e) {
                                    error_log("Decryption error: " . $e->getMessage());
                                    echo hudVal($access_code);
                                }
                            } else {
                                echo hudVal("");
                            }
                            ?>
                        </span>
                    </div>
                </div>

                <div class="data-grid-3" style="margin-top: 10px;">
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-calendar-alt" style="margin-right:4px;"></i> Date of Birth</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["birth_date"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-hourglass-half" style="margin-right:4px;"></i> Age</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["age"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-flag" style="margin-right:4px;"></i> Nationality</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["nationality"] ?? "",
                        ) ?></span>
                    </div>
                </div>

                <div class="data-row" style="margin-top: 10px;">
                    <span class="data-label"><i class="fas fa-globe" style="margin-right:4px;"></i> Country (from phone)</span>
                    <div class="data-value">
                        <?php
                        $phone = $record_data["t"] ?? "";
                        $phoneClean = preg_replace('/\D/', '', (string) $phone) ?? '';
                        $flagMap = require_once __DIR__ . '/includes/table_country_flags.php';
                        $flagFile = 'stock.png';
                        if ($phoneClean !== '') {
                            $sortedCodes = array_keys($flagMap);
                            usort($sortedCodes, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
                            foreach ($sortedCodes as $code) {
                                if (str_starts_with($phoneClean, (string) $code)) {
                                    $candidate = $flagMap[$code];
                                    if (is_file(__DIR__ . '/../id/' . $candidate)) {
                                        $flagFile = $candidate;
                                    }
                                    break;
                                }
                            }
                        }
                        ?>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <img src="id/<?= htmlspecialchars($flagFile) ?>" alt="" width="40" height="30" style="border: 1px solid var(--hud-dim);">
                            <span><?= htmlspecialchars($flagFile === 'stock.png' ? 'Unknown' : str_replace('.png', '', $flagFile)) ?></span>
                        </div>
                    </div>
                </div>

                <div class="data-row" style="margin-top: 10px;">
                    <span class="data-label">SUBSCRIPTION</span>
                    <span class="data-value"><?= hudVal(
                        $record_data["subscription"] ?? "",
                    ) ?></span>
                </div>
            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-heartbeat"></i> Physical Characteristics</div>

                <div class="data-grid-3">
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-ruler-vertical" style="margin-right:4px;"></i> Height</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["height"] ?? "",
                        ) ?> CM</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-weight" style="margin-right:4px;"></i> Weight</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["weight"] ?? "",
                        ) ?> KG</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-tint" style="margin-right:4px;"></i> Blood Type</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["blood_type"] ?? "",
                        ) ?></span>
                    </div>
                </div>

                <div class="data-grid-2" style="margin-top: 10px;">
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-palette" style="margin-right:4px;"></i> Skin Color</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["skin_color"] ?? "",
                        ) ?></span>
                    </div>
                </div>
            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-users"></i> Family Status</div>

                <div class="data-grid-2">
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-ring" style="margin-right:4px;"></i> Marital Status</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["marital_status"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-child" style="margin-right:4px;"></i> Children Count</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["children_count"] ?? "",
                        ) ?></span>
                    </div>
                </div>
            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-file-contract"></i> Identification Documents</div>

                <div class="data-grid-2">
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-file-contract" style="margin-right:4px;"></i> Birth Certificate</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["birth_cert"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-certificate" style="margin-right:4px;"></i> Birth Cert. No.</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["birth_certificate_number"] ?? "",
                        ) ?></span>
                    </div>
                </div>
            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-satellite-dish"></i> Contact Information</div>

                <div class="data-grid-2">
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-envelope" style="margin-right:4px;"></i> Email</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["e"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label"><i class="fas fa-phone" style="margin-right:4px;"></i> Phone</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["t"] ?? "",
                        ) ?></span>
                    </div>
                </div>
            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-map-marked-alt"></i> Address Information</div>

                <div class="data-grid-3">
                    <div class="data-row">
                        <span class="data-label">CITY</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["city"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">DISTRICT</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["district"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">POSTAL_CODE</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["postal_code"] ?? "",
                        ) ?></span>
                    </div>
                </div>

                <div class="data-grid-3" style="margin-top: 10px;">
                    <div class="data-row">
                        <span class="data-label">STREET</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["street"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">BUILDING_NO</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["building_number"] ?? "",
                        ) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">APARTMENT_NO</span>
                        <span class="data-value"><?= hudVal(
                            $record_data["apartment_number"] ?? "",
                        ) ?></span>
                    </div>
                </div>
            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-network-wired"></i> Financial & Relations</div>
                <div class="data-row" style="margin-bottom: 20px;">
                    <span class="data-label" style="color: var(--hud-primary);">
                        <i class="fas fa-users" style="margin-right: 5px;"></i> Relatives
                    </span>
                    <div style="border-left: 2px solid var(--hud-dim); padding-left: 10px; margin-top: 5px;">
                        <?php
                        $relatives = $record_data["relatives"] ?? "";
                        if (!empty($relatives)) {
                            $normalized_relatives = str_replace(
                                ["/", "\r\n", "\r", "\n"],
                                ",",
                                $relatives,
                            );
                            $relatives_array = array_filter(
                                array_map(
                                    static fn ($v) => trim((string) $v),
                                    str_getcsv($normalized_relatives),
                                ),
                            );
                            foreach ($relatives_array as $rel) {
                                echo '<div style="margin-bottom:3px; color: var(--hud-text);">> ' .
                                    htmlspecialchars(
                                        $rel,
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) .
                                    "</div>";
                            }
                        } else {
                            echo '<span class="hud-null">NO_DATA</span>';
                        }
                        ?>
                    </div>
                </div>

                <div class="data-row" style="margin-bottom: 20px;">
                    <span class="data-label" style="color: var(--hud-primary); font-size: 0.85rem;">
                        <i class="fas fa-car" style="margin-right: 5px;"></i> Vehicle Intel
                    </span>
                    <div style="border-left: 2px solid var(--hud-dim); padding-left: 10px; margin-top: 5px;">
                        <span class="data-value" style="font-size: 1.5rem; letter-spacing: 4px; font-family: 'Courier New', monospace; font-weight: bold; text-transform: uppercase; color: var(--hud-text);"><?= hudVal(
                            $record_data["personal_car_number"] ?? "",
                        ) ?></span>
                    </div>
                </div>

                <div>
                    <span class="data-label" style="color: var(--hud-primary);">Bank Information</span>
                    <div style="border-left: 2px solid var(--hud-dim); padding-left: 10px; margin-top: 5px;">
                        <?php
                        $bankAccountsData = $record_data["bank_accounts"] ?? "";
                        $bank_cards = [];
                        if ($bankAccountsData) {
                            $decrypted = SensitiveDataService::decrypt(
                                $bankAccountsData,
                            );
                            if ($decrypted) {
                                $bank_cards =
                                    json_decode($decrypted, true) ?? [];
                            }
                        }
                        if (!empty($bank_cards) && is_array($bank_cards)) {
                            foreach ($bank_cards as $card) {
                                $bank = htmlspecialchars(
                                    $card["bank"] ?? "UNKNOWN_BANK",
                                );
                                $number = htmlspecialchars(
                                    $card["number"] ?? "####",
                                );
                                echo '<div style="margin-bottom: 5px;">';
                                echo '<span style="color:var(--hud-secondary); font-weight:bold;">[' .
                                    $bank .
                                    "]</span> " .
                                    $number;
                                // Expand bank details if available
                                $extra = [];
                                if (!empty($card["name"])) {
                                    $extra[] =
                                        "HOLDER: " .
                                        htmlspecialchars($card["name"]);
                                }
                                if (!empty($card["expiry"])) {
                                    $extra[] =
                                        "EXP: " .
                                        htmlspecialchars($card["expiry"]);
                                }
                                if (!empty($card["cvv"])) {
                                    $extra[] = "CVV: ***";
                                }
                                if (!empty($extra)) {
                                    echo '<div style="font-size:0.8em; opacity:0.7; padding-left:10px;">' .
                                        implode(" | ", $extra) .
                                        "</div>";
                                }
                                echo "</div>";
                            }
                        } else {
                            echo '<span class="hud-null">NO_FINANCIAL_RECORDS</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="hud-panel">
                <div class="hud-section-header"><i class="fas fa-share-alt"></i> Social Media</div>
                <div class="data-row" style="margin-top: 10px;">
                    <span class="data-label">SOCIAL_MEDIA_LINKS</span>
                    <span class="data-value" style="font-family: monospace; font-size: 0.8rem;">
                        <?php
                        $social_media = $record_data["social_media"] ?? "";
                        // Handle both JSON array and string formats
                        if (
                            is_string($social_media) &&
                            !empty($social_media)
                        ) {
                            $decoded = json_decode($social_media, true);
                            if (
                                json_last_error() === JSON_ERROR_NONE &&
                                is_array($decoded)
                            ) {
                                echo hudVal(implode("\n", $decoded));
                            } else {
                                echo hudVal($social_media);
                            }
                        } elseif (
                            is_array($social_media) &&
                            !empty($social_media)
                        ) {
                            echo hudVal(implode("\n", $social_media));
                        } else {
                            echo '<span class="hud-null">NO DATA</span>';
                        }
                        ?>
                    </span>
                </div>
            </div>

        </div>
    </div>

    <div class="hud-actions">
        <a href="my_purchases" class="hud-btn back">
            <i class="fas fa-chevron-left"></i> BACK_TO_DATABASE
        </a>

        <button type="button" class="hud-btn" data-action="edit" data-purchase-id="<?= $purchase_id ?>">
            <i class="fas fa-pen-square"></i> MODIFY_RECORD [-120 PTS]
        </button>

        <?php
        $subscription = $_SESSION["subscription"] ?? "free";
        $canDelete = !in_array(strtolower($subscription), ["free", "basic"]);
        ?>
        <button type="button" class="hud-btn danger"
            data-action="delete" data-purchase-id="<?= $purchase_id ?>" data-record-id="<?= $purchase["record_id"] ?>"
            <?= $canDelete ? '' : 'disabled style="opacity: 0.5; cursor: not-allowed;"' ?>>
            <i class="fas fa-trash-alt"></i> HARD_DELETE [-200 PTS]
        </button>
        <?php if (!$canDelete): ?>
        <div style="color: var(--hud-alert); font-size: 0.8rem; margin-top: 5px;">
            <i class="fas fa-lock"></i> Delete requires Pro subscription or higher
        </div>
        <?php endif; ?>
    </div>

</div>

<div id="lightboxModal" class="lightbox-modal">
    <div class="lightbox-content">
        <img id="lightboxImage" class="lightbox-image" src="" alt="">
        <div id="lightboxCaption" class="lightbox-caption"></div>
    </div>
    <div style="position: absolute; top: 20px; right: 20px; font-size: 2rem; color: var(--hud-primary); cursor: pointer;"
        data-action="close-lightbox">
        <i class="fas fa-times"></i>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.15.2/dist/sweetalert2.all.min.js"
    integrity="sha384-sDPrkvETPutaBBLV0zJmzduG+PxF88FtQ/8gAf7+0WwtV8AkyArHc8guhSUfcV4h"
    crossorigin="anonymous"></script>

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

    // Lightbox Functionality
    function openLightbox(src, cap) {
        document.getElementById('lightboxImage').src = src;
        document.getElementById('lightboxCaption').textContent = cap;
        document.getElementById('lightboxModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeLightbox() {
        document.getElementById('lightboxModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    document.getElementById('lightboxModal').addEventListener('click', function (e) {
        if (e.target === this) closeLightbox();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

    // Event Listeners for CSP compliance
    document.addEventListener('DOMContentLoaded', function() {
        // Lightbox triggers
        document.querySelectorAll('[data-lightbox-src]').forEach(el => {
            el.addEventListener('click', function() {
                const src = this.dataset.lightboxSrc;
                const caption = this.dataset.lightboxCaption;
                if (src) openLightbox(src, caption);
            });
        });

        // Close lightbox button
        document.querySelectorAll('[data-action="close-lightbox"]').forEach(el => {
            el.addEventListener('click', closeLightbox);
        });

        // Edit button
        document.querySelectorAll('[data-action="edit"]').forEach(el => {
            el.addEventListener('click', function() {
                const purchaseId = this.dataset.purchaseId;
                if (purchaseId) confirmEdit(purchaseId);
            });
        });

        // Delete button
        document.querySelectorAll('[data-action="delete"]').forEach(el => {
            el.addEventListener('click', function() {
                const purchaseId = this.dataset.purchaseId;
                const recordId = this.dataset.recordId;
                if (purchaseId && recordId) confirmDeleteFromDatabase(purchaseId, recordId);
            });
        });
    });

    // Logic Handlers
    async function confirmEdit(purchaseId) {
        const confirmResult = await termSwal.fire({
            title: 'MODIFY RECORD?',
            text: 'Cost: 120 POINTS',
            showCancelButton: true,
            confirmButtonText: '[ PROCEED ]',
            cancelButtonText: '[ ABORT ]',
            reverseButtons: true
        });

        if (confirmResult.isConfirmed) {
            // Use POST form submission to hide token from URL and logs
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'edit_purchase?id=' + purchaseId + '&deduct=120';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?= $csrf_token ?>';

            form.appendChild(csrfInput);
            document.body.appendChild(form);
            form.submit();
        }
    }

    async function confirmDeleteFromDatabase(purchaseId, recordId) {
        const confirmResult = await termSwal.fire({
            title: 'HARD DELETE?',
            text: `Record #${recordId} | Cost: 200 PTS`,
            showCancelButton: true,
            confirmButtonText: '[ CONFIRM ]',
            cancelButtonText: '[ ABORT ]',
            reverseButtons: true
        });

        if (confirmResult.isConfirmed) {
            deleteFromDatabase(purchaseId, recordId);
        }
    }

    function deleteFromDatabase(purchaseId, recordId) {
        const csrfToken = '<?= $csrf_token ?>';
        fetch('delete_from_database', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                purchase_id: purchaseId,
                record_id: recordId,
                record_type: 'search',
                csrf_token: csrfToken
            })
        })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error('HTTP ' + response.status + ': ' + text.substring(0, 100));
                    });
                }
                return response.json();
            })
            .then(async data => {
                if (data.success) {
                    await termSwal.fire({
                        title: 'SUCCESS',
                        text: 'Record purged.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    window.location.href = 'my_purchases';
                } else {
                    await termSwal.fire({
                        title: 'FAILED',
                        text: data.message || 'Error occurred',
                        confirmButtonText: '[ CLOSE ]'
                    });
                }
            })
            .catch(async error => {
                console.error('Error:', error);
                await termSwal.fire({
                    title: 'SYSTEM ERROR',
                    text: 'Connection lost',
                    confirmButtonText: '[ CLOSE ]'
                });
            });
    }
</script>

<?php PageController::end($base_path); ?>
