<?php

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;

require_once __DIR__ . '/includes/plan-catalog.php';

// ============================================================================
// ONION SERVICE DETECTION & SECURITY CONFIGURATION
// ============================================================================
$isOnionService =
    (isset($_SERVER["HTTP_HOST"]) &&
        preg_match('/\.onion$/i', $_SERVER["HTTP_HOST"])) ||
    (isset($_SERVER["SERVER_NAME"]) &&
        preg_match('/\.onion$/i', $_SERVER["SERVER_NAME"]));

// ============================================================================
// SECURITY HEADERS - ONION SERVICE COMPATIBLE
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: http: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://api.coingecko.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:; font-src \'self\' https:; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://api.coingecko.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';',
    );
}

if (
    !$isOnionService &&
    isset($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] === "on"
) {
    header(
        "Strict-Transport-Security: max-age=31536000; includeSubDomains; preload",
    );
}

// ============================================================================
// BAN STATUS CHECKING
// ============================================================================
$isBlockedUser = false;
try {
    if (class_exists('ROOTS\Auth\Session')) {
        \ROOTS\Auth\Session::start();
        if (\ROOTS\Auth\Session::isLoggedIn() && !empty($_SESSION["user_id"])) {
            $dbCheck = null;
            try {
                $dbCheck = Database::getConnection();
            } catch (Exception $e) {
                $dbCheck = null;
            }
            if ($dbCheck instanceof \mysqli) {
                $stmt = $dbCheck->prepare(
                    "SELECT ban_until, suspended FROM user_security_guard WHERE user_id = ? LIMIT 1",
                );
                if ($stmt) {
                    $uid = (int) $_SESSION["user_id"];
                    $stmt->bind_param("i", $uid);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        if (is_array($row)) {
                            if (!empty($row["suspended"])) {
                                $isBlockedUser = true;
                            } else {
                                $banUntil = $row["ban_until"] ?? null;
                                if (
                                    !empty($banUntil) &&
                                    strtotime((string) $banUntil) > time()
                                ) {
                                    $isBlockedUser = true;
                                }
                            }
                        }
                    } else {
                        error_log("Failed to execute ban check query: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    error_log("Failed to prepare ban check query: " . $dbCheck->error);
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error checking user ban status: " . $e->getMessage());
}

if ($isBlockedUser) {
    header("Location: blocked");
    exit();
}

PageController::setup("Subscription Plans - Terminal Access", "./", [
    "css/payment-modal.css",
]);

$db = Database::getConnection();

if (!$db) {
    die("SYSTEM ERROR: DATABASE CONNECTION FAILED. TERMINATING.");
}

$username = $_SESSION["username"];
$user_points = 0;

$query = "SELECT points FROM login WHERE username = ?";
$stmt = mysqli_prepare($db, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result !== false) {
        $fetched = mysqli_fetch_assoc($result);
        if (is_array($fetched)) {
            $user_points = (int) ($fetched["points"] ?? 0);
        }
    }
    mysqli_stmt_close($stmt);
}
?>

<link href="/css/term-variables.css" rel="stylesheet">
<?php renderPlanCardsCSS(); ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700;800&display=swap');

:root {
    --term-bg: #050a05;
    --term-bg-alt: #0a120a;
    --term-surface: #0d1a0d;
    --term-green: #00ff41;
    --term-green-dim: #00cc33;
    --term-green-dark: #006622;
    --term-cyan: #00e5ff;
    --term-cyan-dim: #00b8cc;
    --term-amber: #ffaa00;
    --term-red: #ff3333;
    --term-red-glow: #ff5555;
    --term-dim: #4a6b4a;
    --term-dimmer: #2a3a2a;
    --term-border: #1a3a1a;
    --term-glow: rgba(0, 255, 65, 0.05);
    --term-glow-strong: rgba(0, 255, 65, 0.1);
    --term-font: 'JetBrains Mono', 'Courier New', monospace;
    --glass-bg: rgba(10, 25, 10, 0.85);
    --glass-border: rgba(0, 255, 65, 0.1);
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html {
    overflow-x: hidden;
    max-width: 100vw;
    background: var(--term-bg);
}

body {
    background: var(--term-bg);
    color: var(--term-green);
    font-family: var(--term-font);
    overflow-x: hidden;
    min-height: 100vh;
    position: relative;
    line-height: 1.6;
}

/* CRT Scanline Overlay */
body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: linear-gradient(
        rgba(18, 16, 16, 0) 50%,
        rgba(0, 0, 0, 0.25) 50%
    ), linear-gradient(
        90deg,
        rgba(255, 0, 0, 0.06),
        rgba(0, 255, 0, 0.02),
        rgba(0, 0, 255, 0.06)
    );
    background-size: 100% 2px, 3px 100%;
    pointer-events: none;
    z-index: 9999;
    opacity: 0.4;
}

/* Ambient glow */
body::after {
    content: "";
    position: fixed;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(ellipse at 30% 20%, rgba(0, 255, 65, 0.01) 0%, transparent 50%),
                radial-gradient(ellipse at 70% 80%, rgba(0, 229, 255, 0.01) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
    animation: ambientShift 20s ease-in-out infinite;
}

@keyframes ambientShift {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(-2%, 2%) rotate(1deg); }
}

.container-fluid {
    max-width: 100%;
    overflow-x: hidden;
    padding-left: 20px;
    padding-right: 20px;
    width: 100%;
    position: relative;
    z-index: 1;
}

.content {
    padding: 20px;
    max-width: 100%;
    overflow-x: hidden;
    width: 100%;
}

/* ===== HEADER SECTION ===== */
.page-header {
    background: var(--glass-bg);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    padding: 24px 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--term-green), var(--term-cyan), transparent);
    animation: headerScan 3s linear infinite;
}

@keyframes headerScan {
    0% { transform: translateX(-100%); opacity: 0; }
    50% { opacity: 1; }
    100% { transform: translateX(100%); opacity: 0; }
}

.page-header h3 {
    font-family: var(--term-font);
    font-size: 1.4rem;
    font-weight: 800;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--term-green);
    text-shadow: none;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-header small {
    font-size: 0.7rem;
    letter-spacing: 2px;
    color: var(--term-dim);
    text-transform: uppercase;
}

/* Glitch effect on title */
.glitch-text {
    position: relative;
}

.glitch-text::before,
.glitch-text::after {
    content: attr(data-text);
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
}

.glitch-text::before {
    color: var(--term-cyan);
    animation: glitch-1 4s infinite linear alternate-reverse;
    clip-path: polygon(0 0, 100% 0, 100% 35%, 0 35%);
}

.glitch-text::after {
    color: var(--term-red);
    animation: glitch-2 3s infinite linear alternate-reverse;
    clip-path: polygon(0 65%, 100% 65%, 100% 100%, 0 100%);
}

@keyframes glitch-1 {
    0%, 90%, 100% { opacity: 0; transform: translate(0); }
    92% { opacity: 0.8; transform: translate(-2px, 1px); }
    94% { opacity: 0; transform: translate(0); }
    96% { opacity: 0.8; transform: translate(2px, -1px); }
}

@keyframes glitch-2 {
    0%, 85%, 100% { opacity: 0; transform: translate(0); }
    87% { opacity: 0.6; transform: translate(2px, 0); }
    89% { opacity: 0; transform: translate(0); }
}

.header-stat {
    font-family: var(--term-font);
    background: rgba(0, 30, 0, 0.5);
    border: 1px solid var(--term-border);
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 0.85rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.header-stat::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(0, 255, 65, 0.1), transparent);
    transition: left 0.5s ease;
}

.header-stat:hover::before {
    left: 100%;
}

.header-stat:hover {
    border-color: var(--term-green-dim);
    transform: translateY(-2px);
}

.header-stat h4 {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--term-green);
    text-shadow: none;
}

/* ===== SECURITY BADGE ===== */
.security-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    background: rgba(0, 229, 255, 0.05);
    border: 1px solid rgba(0, 229, 255, 0.2);
    border-radius: 6px;
    font-size: 0.7rem;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--term-cyan);
    margin: 16px auto;
    position: relative;
    overflow: hidden;
    animation: badgePulse 4s ease-in-out infinite;
}

@keyframes badgePulse {
    0%, 100% { box-shadow: none; }
    50% { box-shadow: none; }
}

.security-badge i {
    color: var(--term-cyan);
    animation: spinSlow 8s linear infinite;
}

@keyframes spinSlow {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ===== PROTOCOL STATUS ===== */
.protocol-status {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 24px;
    background: var(--glass-bg);
    backdrop-filter: blur(10px);
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    margin: 20px 0;
    position: relative;
    overflow: hidden;
}

.status-dot {
    width: 10px;
    height: 10px;
    background: var(--term-green);
    border-radius: 50%;
    position: relative;
    box-shadow: none;
}

.status-dot::after {
    content: '';
    position: absolute;
    top: -4px;
    left: -4px;
    right: -4px;
    bottom: -4px;
    border-radius: 50%;
    border: 1px solid var(--term-green);
    animation: statusRipple 2s ease-out infinite;
    opacity: 0;
}

@keyframes statusRipple {
    0% { transform: scale(0.8); opacity: 1; }
    100% { transform: scale(2); opacity: 0; }
}

.status-text {
    font-size: 0.75rem;
    letter-spacing: 2px;
    color: var(--term-green);
    font-weight: 500;
}

/* ===== BUTTONS ===== */
.btn-secondary {
    background: transparent !important;
    border: 1.5px solid var(--term-green-dim) !important;
    color: var(--term-green) !important;
    font-family: var(--term-font);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 12px 20px;
    border-radius: 8px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    width: 100%;
    cursor: pointer;
}

.btn-secondary::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(0, 255, 65, 0.2), transparent);
    transition: left 0.5s ease;
}

.btn-secondary:hover::before {
    left: 100%;
}

.btn-secondary:hover {
    background: rgba(0, 255, 65, 0.1) !important;
    border-color: var(--term-green) !important;
    color: var(--term-green) !important;
    box-shadow: 0 0 20px var(--term-glow);
    transform: translateY(-2px);
}

.buy-subscription {
    width: 100%;
    margin-top: 0;
}

.btn-terminal {
    background: transparent;
    border: 2px solid var(--term-green-dim);
    color: var(--term-green);
    font-family: var(--term-font);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 12px 24px;
    border-radius: 8px;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.btn-terminal::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(0, 255, 65, 0.1);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.6s ease, height 0.6s ease;
}

.btn-terminal:hover::after {
    width: 300px;
    height: 300px;
}

.btn-terminal:hover {
    border-color: var(--term-green);
    box-shadow: 0 0 20px var(--term-glow);
    transform: translateY(-2px);
}

.btn-terminal[data-bs-dismiss="modal"] {
    border-color: rgba(255, 85, 85, 0.5);
    color: rgba(255, 85, 85, 0.9);
}

.btn-terminal[data-bs-dismiss="modal"]:hover {
    border-color: var(--term-red);
    color: var(--term-red);
    box-shadow: 0 0 20px rgba(255, 85, 85, 0.2);
}

#confirmSubscriptionBtn {
    background: rgba(0, 255, 65, 0.05);
    border-color: var(--term-green);
}

#confirmSubscriptionBtn:hover {
    background: rgba(0, 255, 65, 0.15);
    box-shadow: 0 0 30px var(--term-glow);
}

/* ===== MODAL ===== */
.modal-content {
    background: rgba(5, 15, 5, 0.95) !important;
    backdrop-filter: blur(30px);
    border: 1px solid var(--term-border) !important;
    color: var(--term-green) !important;
    border-radius: 16px;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.6), 0 0 40px rgba(0, 255, 65, 0.1);
    overflow: hidden;
}

.modal-header {
    border-bottom: 1px solid var(--term-border) !important;
    background: linear-gradient(90deg, rgba(0, 30, 0, 0.5), transparent);
    padding: 24px;
}

.modal-title {
    font-family: var(--term-font);
    font-size: 1rem;
    letter-spacing: 3px;
    font-weight: 700;
    text-shadow: 0 0 15px var(--term-glow);
}

.modal-body {
    padding: 28px;
}

.modal-footer {
    border-top: 1px solid var(--term-border) !important;
    background: rgba(0, 30, 0, 0.2);
    padding: 20px 24px;
    gap: 12px;
}

.btn-close {
    filter: invert(1) grayscale(100%) sepia(100%) hue-rotate(50deg) saturate(500%);
    opacity: 0.6;
    transition: all 0.3s ease;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(0, 255, 65, 0.1);
    padding: 8px;
}

.btn-close:hover {
    opacity: 1;
    background: rgba(0, 255, 65, 0.2);
    transform: rotate(90deg);
}

.alert-warning {
    background: rgba(255, 170, 0, 0.08);
    border: 1px solid rgba(255, 170, 0, 0.3);
    color: var(--term-amber);
    font-size: 0.8rem;
    padding: 16px;
    border-radius: 8px;
    letter-spacing: 0.5px;
}

/* Transaction Details */
.transaction-details {
    background: rgba(0, 15, 0, 0.4);
    border: 1px solid var(--term-border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}

.transaction-details::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 3px;
    height: 100%;
    background: linear-gradient(180deg, var(--term-green), var(--term-cyan));
}

.transaction-details h6 {
    font-family: var(--term-font);
    font-size: 0.85rem;
    letter-spacing: 3px;
    color: var(--term-green);
    margin-bottom: 20px;
    font-weight: 700;
}

.transaction-details .d-flex {
    padding: 10px 0;
    border-bottom: 1px solid rgba(0, 255, 65, 0.08);
    font-size: 0.85rem;
}

.transaction-details .d-flex:last-child {
    border-bottom: none;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--term-border);
}

/* Balance Boxes */
.balance-box {
    background: rgba(0, 20, 0, 0.4);
    border: 1px solid var(--term-border);
    border-radius: 10px;
    padding: 20px;
    transition: all 0.3s ease;
}

.balance-box:hover {
    border-color: var(--term-green-dim);
    box-shadow: 0 0 15px var(--term-glow);
}

.balance-box small {
    font-family: var(--term-font);
    font-size: 0.65rem;
    letter-spacing: 2px;
    color: var(--term-dim);
    text-transform: uppercase;
    display: block;
    margin-bottom: 8px;
}

.balance-box span {
    font-family: var(--term-font);
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--term-green);
    text-shadow: 0 0 10px var(--term-glow);
}

.balance-box span#newBalance.negative {
    color: var(--term-red);
    text-shadow: 0 0 10px rgba(255, 85, 85, 0.3);
    animation: negativePulse 1s ease-in-out infinite;
}

@keyframes negativePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.info-text {
    font-family: var(--term-font);
    font-size: 0.75rem;
    color: var(--term-dim);
    text-align: center;
    padding: 16px;
    background: rgba(0, 30, 0, 0.3);
    border-radius: 8px;
    border-left: 3px solid var(--term-green-dim);
    letter-spacing: 0.5px;
    line-height: 1.6;
}

/* ===== DIVIDERS & FOOTER ===== */
.section-divider {
    width: 100%;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--term-green-dim), var(--term-cyan), var(--term-green-dim), transparent);
    margin: 40px 0;
    opacity: 0.5;
    position: relative;
}

.section-divider::after {
    content: '◆';
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    color: var(--term-green);
    font-size: 0.6rem;
    background: var(--term-bg);
    padding: 0 12px;
}

.page-footer-info {
    text-align: center;
    padding: 32px 20px;
    margin-top: 40px;
    border-top: 1px solid var(--term-border);
    color: var(--term-dim);
    font-size: 0.7rem;
    background: linear-gradient(180deg, transparent, rgba(0, 30, 0, 0.2));
}

.page-footer-info p {
    margin: 8px 0;
    letter-spacing: 1px;
    transition: color 0.3s ease;
}

.page-footer-info p:hover {
    color: var(--term-green-dim);
}

/* ===== CURSOR & ANIMATIONS ===== */
.blinking-cursor::after {
    content: "▋";
    animation: blink 1.2s step-end infinite;
    margin-left: 8px;
    color: var(--term-green);
    font-weight: 300;
}

@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}

/* Typing effect for header */
.typing-effect {
    overflow: hidden;
    white-space: nowrap;
    animation: typing 2s steps(40, end);
}

@keyframes typing {
    from { width: 0; }
    to { width: 100%; }
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
        padding: 20px;
    }
    
    .page-header h3 {
        font-size: 1.1rem;
        justify-content: center;
    }
    
    .header-stat {
        width: 100%;
        justify-content: center;
    }
    
    .subscription-cards-row {
        grid-template-columns: 1fr;
        gap: 20px;
        padding: 10px;
    }
    
    .plan-card--popular {
        transform: scale(1);
        order: -1;
    }
    
    .plan-card--popular:hover {
        transform: scale(1.02) translateY(-4px);
    }
    
    .btn-terminal {
        padding: 10px 16px;
        font-size: 0.7rem;
    }
    
    #subscriptionConfirmModal .modal-dialog {
        max-width: 95%;
        margin: 10px auto;
    }
    
    .transaction-details {
        padding: 16px;
    }
    
    .balance-box {
        padding: 14px;
    }
    
    .balance-box span {
        font-size: 1.1rem;
    }
}

/* Scrollbar styling */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: var(--term-bg);
}

::-webkit-scrollbar-thumb {
    background: var(--term-border);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--term-green-dark);
}

/* Selection color */
::selection {
    background: rgba(0, 255, 65, 0.3);
    color: var(--term-green);
}

/* Loading shimmer for buttons */
@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

.btn-secondary:active {
    transform: scale(0.98);
}

/* Plan card corner accents */
.plan-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    right: 0;
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, transparent 50%, rgba(0, 255, 65, 0.1) 50%);
    border-radius: 0 0 16px 0;
    pointer-events: none;
}

.plan-card--popular::after {
    background: linear-gradient(135deg, transparent 50%, rgba(255, 170, 0, 0.15) 50%);
}

/* Grid background pattern */
.grid-bg {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-image: 
        linear-gradient(rgba(0, 255, 65, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 255, 65, 0.03) 1px, transparent 1px);
    background-size: 50px 50px;
    pointer-events: none;
    z-index: 0;
}
</style>

<!-- Grid Background -->
<div class="grid-bg"></div>

<div class="container-fluid mb-4">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h3 class="mb-0 blinking-cursor glitch-text" data-text="SUBSCRIPTION.ACCESS_PROTOCOLS">
                <i class="fas fa-key me-2"></i> SUBSCRIPTION.ACCESS_PROTOCOLS
            </h3>
            <small class="text-muted">SUBSCRIPTION PLANS // ENCRYPTION: 256-BIT // v2.4.1</small>
        </div>
        <div class="d-flex align-items-center header-stat">
            <span class="text-muted me-2">AVAILABLE_ASSETS:</span>
            <h4 class="mb-0 fw-bold"><?php echo number_format($user_points); ?> PTS</h4>
        </div>
    </div>
</div>

<!-- Security Badge -->
<div class="container-fluid" style="text-align: center;">
    <div class="security-badge">
        <i class="fas fa-certificate"></i>
        <span>SECURE CONNECTION ESTABLISHED // TLS 1.3 VERIFIED</span>
    </div>
</div>

<!-- Protocol Status -->
<div class="container-fluid">
    <div class="protocol-status">
        <div class="status-dot"></div>
        <div class="status-text">SYSTEM STATUS: OPERATIONAL // ALL PROTOCOLS ACTIVE // UPTIME: 99.97%</div>
    </div>
</div>

<div style="max-width: 1400px; margin: 0 auto; padding: 0 20px;">
    <div class="subscription-cards-row">
        <?php foreach (planCatalogKeys() as $planKey): ?>
        <?php if (!isset($planCatalogDisplay[$planKey], $planMeta[$planKey], $planSpecGroups[$planKey])) continue; ?>
        <?php $display = $planCatalogDisplay[$planKey]; ?>
        <?php $meta = $planMeta[$planKey]; ?>
        <?php $groups = $planSpecGroups[$planKey]; ?>
        <?php $cardClass = 'plan-card'; if ($planKey === 'free') $cardClass .= ' plan-card--selected'; if ($planKey === 'pro') $cardClass .= ' plan-card--popular'; if ($planKey === 'premium') $cardClass .= ' plan-card--vip'; ?>
        <?php $glyphClass = 'plan-tier-glyph'; if ($planKey === 'premium') $glyphClass .= ' plan-tier-glyph--gold'; ?>
        <?php $headClass = 'plan-head'; if ($planKey === 'free') $headClass .= ' plan-head--free'; if ($planKey === 'basic') $headClass .= ' plan-head--basic'; if ($planKey === 'pro') $headClass .= ' plan-head--pro'; if ($planKey === 'premium') $headClass .= ' plan-head--premium'; ?>
        <?php $price = isset($display['amount']) ? (int)str_replace(['$', 'k'], '', $display['amount']) * 1000 : 0; ?>
        <div>
            <article class="<?php echo htmlspecialchars($cardClass, ENT_QUOTES, 'UTF-8'); ?>"
                data-plan="<?php echo htmlspecialchars($planKey, ENT_QUOTES, 'UTF-8'); ?>"
                data-price="<?php echo $price; ?>">
                <?php if (!empty($display['badge'])): ?>
                <span class="plan-badge <?php echo htmlspecialchars($display['badge']['class'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($display['badge']['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
                <span class="<?php echo htmlspecialchars($glyphClass, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
                    <?php echo htmlspecialchars($display['glyph'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <header class="<?php echo htmlspecialchars($headClass, ENT_QUOTES, 'UTF-8'); ?>">
                    <p class="plan-tier-label"><?php echo htmlspecialchars($display['tier'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <h3><?php echo htmlspecialchars($display['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="plan-tagline"><?php echo htmlspecialchars($display['tagline'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="plan-price">
                        <span class="amount"><?php echo htmlspecialchars($display['amount'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="period"><?php echo htmlspecialchars($display['period'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </p>
                </header>
                <div class="plan-body">
                    <?php renderPlanMetaExtras($meta); ?>
                    <?php renderPlanSpecGroups($groups); ?>
                </div>
                <footer class="plan-foot">
                    <button class="btn btn-secondary buy-subscription"
                        data-plan="<?php echo htmlspecialchars($planKey, ENT_QUOTES, 'UTF-8'); ?>"
                        data-price="<?php echo $price; ?>"
                        data-duration="<?php echo htmlspecialchars($display['period'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-type="subscription">
                        <?php if ($planKey === 'free'): ?>
                        <i class="fas fa-unlock me-2"></i>Register Free
                        <?php else: ?>
                        <i class="fas fa-rocket me-2"></i>Subscribe — <?php echo htmlspecialchars($display['amount'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </button>
                </footer>
            </article>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section-divider"></div>

<!-- Page Footer Info -->
<div class="container-fluid">
    <div class="page-footer-info">
        <p><i class="fas fa-lock me-2"></i>ALL TRANSACTIONS ARE SECURE AND ENCRYPTED</p>
        <p><i class="fas fa-shield-alt me-2"></i>YOUR DATA IS PROTECTED BY INDUSTRY-STANDARD SECURITY PROTOCOLS</p>
        <p><i class="fas fa-clock me-2"></i>SUBSCRIPTION ACTIVATION WITHIN 24 HOURS OF CONFIRMATION</p>
        <p style="margin-top: 16px; font-size: 0.6rem; opacity: 0.5;">
            <i class="fas fa-code me-1"></i>TERMINAL ACCESS SYSTEM v2.4.1 // BUILD 2026.06.18
        </p>
    </div>
</div>

<div class="modal fade" id="subscriptionConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-terminal me-2"></i>>> CONFIRM_SUBSCRIPTION_PROTOCOL
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    WARNING: POINTS WILL BE DEDUCTED FROM ACCOUNT
                </div>

                <div class="transaction-details">
                    <h6 class="mb-3 text-uppercase">
                        <i class="fas fa-file-invoice me-2"></i>>> TRANSACTION_DETAILS
                    </h6>
                    <div class="d-flex justify-content-between">
                        <span style="color: var(--term-dim);">PLAN:</span>
                        <span class="fw-bold" style="color: var(--term-green);" id="confirmPlanName">-</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span style="color: var(--term-dim);">DURATION:</span>
                        <span class="fw-bold" style="color: var(--term-green);" id="confirmDuration">-</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-2">
                        <span style="color: var(--term-dim);">COST:</span>
                        <span class="fw-bold fs-5" style="color: var(--term-green); text-shadow: 0 0 10px var(--term-glow);" id="confirmPrice">-</span>
                    </div>
                </div>

                <div class="row g-3 text-center mb-3">
                    <div class="col-6">
                        <div class="balance-box">
                            <small class="d-block">CURRENT_BALANCE</small>
                            <span id="currentBalance"><?php echo number_format($user_points); ?></span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="balance-box">
                            <small class="d-block">NEW_BALANCE</small>
                            <span id="newBalance">-</span>
                        </div>
                    </div>
                </div>

                <div class="info-text">
                    <i class="fas fa-info-circle me-2"></i>
                    Subscription activates immediately upon confirmation. Full access granted within 24 hours. No refunds.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-terminal" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>[ CANCEL ]
                </button>
                <button type="button" class="btn-terminal" id="confirmSubscriptionBtn">
                    <i class="fas fa-check me-2"></i>[ CONFIRM_PURCHASE ]
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('subscriptionConfirmModal');
    if (!modalEl) return;
    const bootstrapModal = new bootstrap.Modal(modalEl, {
        backdrop: false,
        keyboard: true
    });
    const confirmBtn = document.getElementById('confirmSubscriptionBtn');

    let currentSelection = {};

    document.querySelectorAll('.buy-subscription').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const plan = this.dataset.plan || this.getAttribute('data-plan') || '-';
            const price = parseInt(this.dataset.price || this.getAttribute('data-price') || '0', 10);
            const duration = this.dataset.duration || this.getAttribute('data-duration') || '-';
            const type = this.dataset.type || this.getAttribute('data-type') || 'subscription';

            currentSelection = { plan, price, duration, type };

            const elPlan = document.getElementById('confirmPlanName');
            const elDuration = document.getElementById('confirmDuration');
            const elPrice = document.getElementById('confirmPrice');
            const elNewBalance = document.getElementById('newBalance');
            const elCurrent = document.getElementById('currentBalance');

            if (elPlan) elPlan.textContent = plan.toUpperCase();
            if (elDuration) elDuration.textContent = duration;
            if (elPrice) elPrice.textContent = price.toLocaleString();

            const current = parseInt(elCurrent ? elCurrent.textContent.replace(/[^0-9]/g, '') : '<?php echo (int) $user_points; ?>', 10) || 0;
            const newBal = current - price;
            const isInsufficient = newBal < 0;

            if (elNewBalance) {
                elNewBalance.textContent = isInsufficient ? 'INSUFFICIENT' : newBal.toLocaleString();
                if (isInsufficient) {
                    elNewBalance.classList.add('negative');
                } else {
                    elNewBalance.classList.remove('negative');
                }
            }

            if (confirmBtn) {
                confirmBtn.disabled = isInsufficient;
                if (isInsufficient) {
                    confirmBtn.style.opacity = '0.5';
                    confirmBtn.style.cursor = 'not-allowed';
                    confirmBtn.innerHTML = '<i class="fas fa-ban me-2"></i>[ INSUFFICIENT_FUNDS ]';
                } else {
                    confirmBtn.style.opacity = '1';
                    confirmBtn.style.cursor = 'pointer';
                    confirmBtn.innerHTML = '<i class="fas fa-check me-2"></i>[ CONFIRM_PURCHASE ]';
                }
            }

            bootstrapModal.show();
        });
    });

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (this.disabled) return;
            
            // Loading state
            this.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>[ PROCESSING... ]';
            this.style.pointerEvents = 'none';
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'process_subscription';
            form.style.display = 'none';

            const add = (name, value) => {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = name;
                i.value = value;
                form.appendChild(i);
            };
            add('plan', currentSelection.plan || '');
            add('price', currentSelection.price || '0');
            add('duration', currentSelection.duration || '');
            
            const csrfEl = document.querySelector('input[name="csrf_token"]');
            if (csrfEl) add('csrf_token', csrfEl.value);

            document.body.appendChild(form);
            
            // Simulate brief processing delay for UX
            setTimeout(() => {
                form.submit();
            }, 600);
        });
    }
});
</script>

<?php PageController::end("./", []); ?>