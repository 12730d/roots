<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Auth\Session;
use ROOTS\Middleware\SecurityHeadersMiddleware;
use ROOTS\Middleware\BotProtectionMiddleware;
use ROOTS\Auth\BanSystem;

Session::start();

// Apply Global Protections (Skip BotProtection if already on error page to prevent loops)
$requestPath = parse_url($_SERVER["REQUEST_URI"] ?? '', PHP_URL_PATH);
$requestPath = $requestPath !== false ? $requestPath : '';
if (basename($requestPath ?? '') !== 'blocked_403' && basename($requestPath ?? '') !== 'blocked_403.php') {
    BotProtectionMiddleware::apply();
}
SecurityHeadersMiddleware::apply();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Get error code from URL parameter and sanitize
$errorCode = $_GET['code'] ?? '403';
$errorReason = $_GET['reason'] ?? '';

// Allow only standard HTTP error codes
if (!in_array($errorCode, ['400', '403', '404', '429', '500'])) {
    $errorCode = '403';
}

$errorTitle = '';
$errorDescription = '';
$errorIcon = '';

// Define error types with detailed messages
switch ($errorCode) {
    case '400':
        $errorTitle = '400 - Bad Request';
        $errorDescription = !empty($errorReason) ? str_replace('_', ' ', $errorReason) : 'The server cannot process this request due to invalid syntax or malformed request.';
        $errorIcon = '??';
        http_response_code(400);
        break;
    case '403':
        $errorTitle = '403 - Forbidden';
        $errorDescription = !empty($errorReason) ? str_replace('_', ' ', $errorReason) : 'Access to this resource is denied. You do not have permission to view this page.';
        $errorIcon = '??';
        http_response_code(403);
        break;
    case '404':
        $errorTitle = '404 - Not Found';
        $errorDescription = !empty($errorReason) ? str_replace('_', ' ', $errorReason) : 'The page you requested cannot be found. Please check the URL and try again.';
        $errorIcon = '??';
        http_response_code(404);
        break;
    case '429':
        $errorTitle = '429 - Too Many Requests';
        $errorDescription = 'You have sent too many requests in a given amount of time. Please slow down.';
        $errorIcon = '??';
        http_response_code(429);
        break;
    case '500':
        $errorTitle = '500 - Internal Server Error';
        $errorDescription = 'The server encountered an unexpected condition and was unable to complete the request.';
        $errorIcon = '??';
        http_response_code(500);
        break;
    default:
        $errorTitle = '403 - Forbidden';
        $errorDescription = 'Access to this resource is denied. You do not have permission to view this page.';
        $errorIcon = '??';
        http_response_code(403);
        break;
}

// Rate limiting and ban status
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$isLoggedIn = isset($_SESSION["user_id"]) && !empty($_SESSION["user_id"]);

if (!$isLoggedIn) {
    // Check if the browser is explicitly blocked (Security Strikes)
    $browserBlock = BanSystem::isBrowserBlocked();

    // Normal rate limiting check (no increment)
    $rateLimit = BanSystem::checkGuestRateLimit(150, 60, false);
    $currentRate = $rateLimit['current'];
    $maxRequests = $rateLimit['max'];
    $isRateBlocked = $rateLimit['is_blocked'];

    if ($browserBlock['is_blocked']) {
        // Browser is blocked due to strikes or manual ban
        http_response_code(403);
        $errorCode = '403';

        $reason = $browserBlock['reason'] ?? 'Multiple strikes';
        $isRateLimit = str_contains($reason, 'RATE_LIMIT_EXCEEDED');

        if ($isRateLimit) {
            $errorTitle = '429 - Too Many Requests (Throttled)';
            $errorDescription = 'Your browser has been temporarily throttled due to high request volume. ' . $reason;
        } else {
            $errorTitle = '403 - Forbidden (Security Block)';
            $errorDescription = 'Your browser has been temporarily blocked due to repeated security violations. Reason: ' . $reason;
        }
    } elseif ($isRateBlocked) {
        // Not blocked by strikes, but hit rate limit
        http_response_code(429);
        $errorCode = '429';
        $errorTitle = '429 - Too Many Requests';
        $blockType = $rateLimit['type'] ?? 'ip';

        if ($blockType === 'user_agent') {
            $errorDescription = 'Your browser has been temporarily blocked due to excessive requests. Try using a different browser or wait.';
        } else {
            $errorDescription = 'Your IP has been temporarily blocked due to excessive requests. Please try again later.';
        }

        if (isset($rateLimit['until'])) {
            $errorDescription .= " Blocked until: " . $rateLimit['until'];
        }
    }
} else {
    // Check Strikes for logged-in users
    $violation = BanSystem::checkStrikeSystem((int) $_SESSION["user_id"]);
    $isFrozen = $violation["status"] === "frozen";
    $strikeCount = $violation["count"];
    $maxStrikes = $violation["max"] ?? 100;

    // Get Ban Details for Timer
    $banDetails = BanSystem::getBanDetails((int) $_SESSION["user_id"]);
    $banUntil = $banDetails["ban_until"] ?? null;
    $banUntilTs = !empty($banUntil) ? strtotime((string) $banUntil) : 0;
    $banUntilMs = $banUntilTs > 0 ? $banUntilTs * 1000 : 0;
    $isSuspended = ($banDetails["suspended"] ?? 0) == 1;
}

if (!isset($isFrozen)) {
    $isFrozen = false;
}
if (!isset($strikeCount)) {
    $strikeCount = 0;
}
if (!isset($maxStrikes)) {
    $maxStrikes = 100;
}
if (!isset($banUntilMs)) {
    $banUntilMs = 0;
}
if (!isset($isSuspended)) {
    $isSuspended = false;
}
if (!isset($currentRate)) {
    $currentRate = 0;
}
if (!isset($maxRequests)) {
    $maxRequests = 50;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($errorTitle); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --bg: #0a0a0b;
            --panel: rgba(10, 10, 11, 0.95);
            --green: #00ff41;
            --red: #ff3131;
            --amber: #ffb100;
            --blue: #00d4ff;
            --dim-green: rgba(0, 255, 65, 0.15);
            --dim-red: rgba(255, 49, 49, 0.15);
            --font: "JetBrains Mono", "Fira Code", "Courier New", Courier, monospace;
        }

        * {
            box-sizing: border-box;
            scrollbar-width: thin;
            scrollbar-color: var(--dim-green) transparent;
        }

        html, body {
            height: 100%;
            margin: 0;
            background: var(--bg);
            color: var(--green);
            font-family: var(--font);
            overflow-x: hidden;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
            background-image:
                radial-gradient(circle at 50% 50%, rgba(20, 20, 25, 1) 0%, rgba(5, 5, 5, 1) 100%),
                linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.1) 50%),
                linear-gradient(90deg, rgba(255, 0, 0, 0.03), rgba(0, 255, 0, 0.01), rgba(0, 0, 255, 0.03));
            background-size: 100% 100%, 100% 4px, 3px 100%;
            position: relative;
        }

        /* Scanline Effect */
        body::before {
            content: " ";
            display: block;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
            z-index: 100;
            background-size: 100% 2px, 3px 100%;
            pointer-events: none;
        }

        /* Flickering Overlay */
        body::after {
            content: " ";
            display: block;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: rgba(18, 16, 16, 0.1);
            opacity: 0;
            z-index: 101;
            pointer-events: none;
            animation: flicker 0.15s infinite;
        }

        @keyframes flicker {
            0% { opacity: 0.1; }
            5% { opacity: 0.2; }
            10% { opacity: 0.1; }
            15% { opacity: 0.3; }
            20% { opacity: 0.1; }
            100% { opacity: 0.1; }
        }

        .wrap {
            width: min(920px, 100%);
            border: 1px solid var(--dim-red);
            background: var(--panel);
            box-shadow: 0 0 40px rgba(255, 49, 49, 0.1), inset 0 0 20px rgba(255, 49, 49, 0.05);
            padding: 30px;
            position: relative;
            z-index: 10;
            backdrop-filter: blur(4px);
            border-radius: 4px;
            margin: auto;
        }

        .title {
            margin: 0 0 20px 0;
            font-size: 24px;
            letter-spacing: 2px;
            border-bottom: 1px solid var(--dim-red);
            padding-bottom: 15px;
            color: var(--red);
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 15px;
            text-shadow: 0 0 10px rgba(255, 49, 49, 0.5);
        }

        .glitch {
            position: relative;
        }

        .glitch::before, .glitch::after {
            content: attr(data-text);
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .glitch::before {
            left: 2px;
            text-shadow: -2px 0 var(--blue);
            clip: rect(44px, 450px, 56px, 0);
            animation: glitch-anim 5s infinite linear alternate-reverse;
        }

        .glitch::after {
            left: -2px;
            text-shadow: -2px 0 var(--red);
            clip: rect(44px, 450px, 56px, 0);
            animation: glitch-anim2 5s infinite linear alternate-reverse;
        }

        @keyframes glitch-anim {
            0% { clip: rect(31px, 9999px, 94px, 0); }
            20% { clip: rect(62px, 9999px, 42px, 0); }
            40% { clip: rect(16px, 9999px, 78px, 0); }
            60% { clip: rect(89px, 9999px, 12px, 0); }
            80% { clip: rect(54px, 9999px, 34px, 0); }
            100% { clip: rect(23px, 9999px, 67px, 0); }
        }

        @keyframes glitch-anim2 {
            0% { clip: rect(12px, 9999px, 56px, 0); }
            20% { clip: rect(87px, 9999px, 23px, 0); }
            40% { clip: rect(45px, 9999px, 98px, 0); }
            60% { clip: rect(21px, 9999px, 45px, 0); }
            80% { clip: rect(67px, 9999px, 12px, 0); }
            100% { clip: rect(34px, 9999px, 89px, 0); }
        }

        .msg {
            margin: 20px 0;
            line-height: 1.8;
            font-size: 15px;
            color: rgba(0, 255, 65, 0.9);
        }

        .msg-line {
            display: block;
            margin-bottom: 8px;
            border-left: 3px solid transparent;
            padding-left: 10px;
            transition: all 0.2s;
        }

        .msg-line:hover {
            border-left-color: var(--green);
            background: var(--dim-green);
        }

        .warn {
            margin-top: 20px;
            padding: 20px;
            border: 1px solid var(--dim-red);
            background: rgba(40, 5, 5, 0.4);
            color: rgba(255, 255, 255, 0.9);
            border-radius: 2px;
            font-size: 14px;
        }

        .warn-header {
            color: var(--red);
            font-weight: bold;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .meta {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px dashed var(--dim-green);
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .meta-item b {
            color: var(--amber);
            font-weight: normal;
        }

        .btn-group {
            margin-top: 35px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            padding-top: 25px;
            border-top: 1px solid rgba(0, 255, 65, 0.1);
        }

        .btn {
            padding: 12px 28px;
            border: 1px solid var(--green);
            color: var(--green);
            text-decoration: none;
            background: rgba(0, 255, 65, 0.05);
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-size: 13px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 200px;
        }

        .btn::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 255, 65, 0.2), transparent);
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn:hover {
            background: var(--dim-green);
            box-shadow: 0 0 15px var(--dim-green);
            transform: translateY(-2px);
        }

        .btn-red {
            border-color: var(--red);
            color: var(--red);
            background: rgba(255, 49, 49, 0.05);
        }

        .btn-red:hover {
            background: var(--dim-red);
            box-shadow: 0 0 15px var(--dim-red);
        }

        .btn-amber {
            border-color: var(--amber);
            color: var(--amber);
            background: rgba(255, 177, 0, 0.05);
        }

        .btn-amber:hover {
            background: rgba(255, 177, 0, 0.15);
            box-shadow: 0 0 15px rgba(255, 177, 0, 0.15);
        }

        .timer-box {
            margin-top: 20px;
            padding: 15px;
            background: rgba(0, 40, 0, 0.2);
            border: 1px solid var(--dim-green);
            text-align: center;
            border-radius: 2px;
        }

        .timer-label {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .timer-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--amber);
            letter-spacing: 3px;
            font-family: monospace;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin-left: 10px;
        }

        .badge-red { background: var(--red); color: white; }
        .badge-amber { background: var(--amber); color: black; }

        @media (max-width: 600px) {
            .wrap { padding: 20px; }
            .title { font-size: 18px; }
            .btn { width: 100%; }
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
    </style>
</head>

<body>
    <div class="wrap" role="alert">
        <?php if (!$isLoggedIn): ?>
            <!-- Guest Visitor - Dynamic Error -->
            <h1 class="title">
                <span class="glitch" data-text="<?php echo htmlspecialchars($errorTitle); ?>">
                    > <?php echo htmlspecialchars($errorTitle); ?>
                </span>
            </h1>

            <div class="msg">
                <span class="msg-line">> <b style="color: var(--red);">ACCESS_DENIED</b>: Unauthorized attempt detected.</span>
                <span class="msg-line">> <b style="color: var(--blue);">DESCRIPTION</b>: <?php echo htmlspecialchars($errorDescription); ?></span>

                <div style="margin-top: 15px; padding: 10px; border: 1px solid var(--dim-green); background: rgba(0, 255, 65, 0.02);">
                    > <b style="color: var(--amber);">TRAFFIC_MONITOR</b>: <?php echo $currentRate; ?> / <?php echo $maxRequests; ?> REQ_WINDOW
                    <?php if ($currentRate >= $maxRequests): ?>
                        <br>
                        > <span style="color: var(--red); animation: pulse 1s infinite;">⚠️ ALERT: RATE_LIMIT_EXCEEDED</span>
                    <?php elseif ($currentRate >= $maxRequests - 10): ?>
                        <br>
                        > <span style="color: var(--amber); animation: pulse 2s infinite;">⚠️ WARNING: APPROACHING RATE_LIMIT</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="btn-group">
                <a href="/login" class="btn">
                    [ INITIALIZE_LOGIN ]
                </a>
            </div>

        <?php else: ?>
            <!-- Logged-in User - Error Display -->
            <h1 class="title">
                <span class="glitch" data-text="<?php echo htmlspecialchars($errorTitle); ?>">
                    > <?php echo htmlspecialchars($errorTitle); ?>
                </span>
                <?php if ($isFrozen): ?>
                    <span class="badge badge-red">LOCKED</span>
                <?php endif; ?>
            </h1>

            <div class="msg">
                <?php if ($isFrozen): ?>
                    <span class="msg-line">> <b style="color: var(--red);">ACCOUNT_STATUS</b>: PERMANENT_SUSPENSION_ACTIVE</span>
                    <span class="msg-line">> <b style="color: var(--red);">VIOLATION_THRESHOLD</b>: EXCEEDED (<?php echo $strikeCount; ?>/<?php echo $maxStrikes; ?>)</span>
                    <span class="msg-line">> <b style="color: var(--blue);">ACTION</b>: Access credentials revoked by system integrity monitor.</span>
                <?php else: ?>
                    <span class="msg-line">> <b style="color: var(--amber);">SECURITY_STRIKE</b>: <?php echo $strikeCount; ?> / <?php echo $maxStrikes; ?></span>
                    <span class="msg-line">> <b style="color: var(--blue);">MESSAGE</b>: <?php echo htmlspecialchars($errorDescription); ?></span>
                <?php endif; ?>
            </div>

            <div class="warn">
                <div class="warn-header">
                    <i class="fas fa-exclamation-triangle"></i> Security Protocol Warning
                </div>
                <?php if ($isFrozen): ?>
                    - Account locked due to repeated policy violations.
                    <br>
                    - Hardware ID and IP signatures blacklisted.
                    <br>
                    - No further appeals will be processed for this entity.
                <?php else: ?>
                    - Unauthorized navigation attempts are recorded as security strikes.
                    <br>
                    - Reaching <b><?php echo $maxStrikes; ?> strikes</b> triggers automatic account liquidation.
                    <br>
                    - All activities are being monitored by the administration.
                <?php endif; ?>
            </div>

            <div style="margin-top: 20px; padding: 15px; border: 1px solid rgba(0, 212, 255, 0.2); background: rgba(0, 212, 255, 0.05); font-size: 13px; text-align: center;">
                <span style="color: rgba(255, 255, 255, 0.6);">
                    Technical Support Uplink:
                    <a href="mailto:buttcry@proton.me" style="color: var(--blue); text-decoration: none; font-weight: bold; border-bottom: 1px solid transparent; transition: 0.2s;" onmouseover="this.style.borderBottomColor='var(--blue)'" onfocus="this.style.borderBottomColor='var(--blue)'" onmouseout="this.style.borderBottomColor='transparent'" onblur="this.style.borderBottomColor='transparent'">buttcry@proton.me</a>
                </span>
            </div>

            <?php if (!$isFrozen && !$isSuspended && $banUntilMs > 0): ?>
            <div class="timer-box" aria-live="polite">
                <div class="timer-label">TEMPORARY_BLOCK_EXPIRATION</div>
                <div class="timer-value" id="countdown">--:--:--:--</div>
            </div>
            <?php endif; ?>

            <div class="meta">
                <div class="meta-item">TIMESTAMP: <span><?php echo date("Y-m-d H:i:s"); ?></span></div>
                <div class="meta-item">USER_ID: <span><?php echo htmlspecialchars((string) ($_SESSION["user_id"] ?? "0")); ?></span></div>
                <div class="meta-item">SESSION_STATUS: <span>ACTIVE</span></div>
            </div>

            <div class="btn-group">
                <?php if (!$isFrozen): ?>
                    <a href="/blocked" class="btn">
                        [ RETURN_TO_DASHBOARD ]
                    </a>
                <?php endif; ?>
                <a href="/login" class="btn btn-amber">
                    [ SYSTEM_HOME ]
                </a>
                <a href="/logout" class="btn btn-red">
                    [ TERMINATE_SESSION ]
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isLoggedIn && !$isFrozen && !$isSuspended && $banUntilMs > 0): ?>
    <script>
        (function() {
            'use strict';
            var banUntilMs = <?php echo json_encode($banUntilMs); ?>;
            var el = document.getElementById('countdown');

            function pad(n) {
                n = Math.floor(n);
                return (n < 10 ? '0' : '') + n;
            }

            function tick() {
                var now = Date.now();
                var diff = banUntilMs - now;
                if (diff <= 0) {
                    el.textContent = '00:00:00:00';
                    return;
                }

                var totalSeconds = Math.floor(diff / 1000);
                var days = Math.floor(totalSeconds / (3600 * 24));
                var hours = Math.floor((totalSeconds % (3600 * 24)) / 3600);
                var minutes = Math.floor((totalSeconds % 3600) / 60);
                var seconds = totalSeconds % 60;

                el.textContent = pad(days) + ':' + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
                setTimeout(tick, 250);
            }
            tick();
        })();
    </script>
    <?php endif; ?>
</body>

</html>

