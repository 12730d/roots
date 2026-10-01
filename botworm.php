<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Auth\Session;
use ROOTS\Middleware\SecurityHeadersMiddleware;
use ROOTS\Middleware\BotProtectionMiddleware;
use ROOTS\Auth\BanSystem;

Session::start();

// Apply Global Protections
BotProtectionMiddleware::apply();
SecurityHeadersMiddleware::apply();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!Session::isLoggedIn() || empty($_SESSION["user_id"])) {
    header("Location: /login");
    exit();
}

$uid = (int) $_SESSION["user_id"];

// Check for violation count via BanSystem
$violationCount = BanSystem::getViolationCount($uid);
if ($violationCount >= 10) {
    // User has 10+ violations, redirect to permanent ban page
    header("Location: /blocked_403");
    exit();
}

// Use Central BanSystem for details
$banData = BanSystem::getBanDetails($uid);
$banUntil = $banData["ban_until"] ?? null;
$suspended = $banData["suspended"] ?? 0;
// BanSystem uses 1 for suspended
$isSuspended = $suspended == 1;
$banLookupFailed = empty($banData) && !$isSuspended;

$_SESSION["blocked_notice_shown"] = 1;

$banUntilTs = !empty($banUntil) ? strtotime((string) $banUntil) : 0;
$banUntilMs = $banUntilTs > 0 ? $banUntilTs * 1000 : 0;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Temporary Block</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
    :root {
        --bg: #050505;
        --panel: rgba(0, 0, 0, 0.85);
        --green: #33ff00;
        --red: #ff3333;
        --amber: #ffb000;
        --dim: rgba(51, 255, 0, 0.25);
        --font: "Courier New", Courier, monospace;
    }

    html,
    body {
        height: 100%;
        margin: 0;
        background: var(--bg);
        color: var(--green);
        font-family: var(--font);
    }

    body {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background-image:
            linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%),
            linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
        background-size: 100% 2px, 3px 100%;
    }

    .wrap {
        width: min(820px, 100%);
        border: 1px solid var(--dim);
        background: var(--panel);
        box-shadow: 0 0 18px rgba(51, 255, 0, 0.15);
        padding: 22px;
        position: relative;
    }

    .title {
        margin: 0 0 10px 0;
        font-size: 20px;
        letter-spacing: 1px;
        border-bottom: 1px dashed var(--dim);
        padding-bottom: 10px;
    }

    .msg {
        margin: 14px 0;
        line-height: 1.8;
        font-size: 14px;
    }

    .timer {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        margin-top: 14px;
        padding: 14px;
        border: 1px solid var(--dim);
        background: rgba(0, 20, 0, 0.35);
    }

    .timer .label {
        color: rgba(255, 255, 255, 0.55);
        font-size: 12px;
    }

    .timer .value {
        font-size: 28px;
        font-weight: 700;
        color: var(--amber);
        letter-spacing: 2px;
    }

    .fineprint {
        margin-top: 14px;
        font-size: 12px;
        color: rgba(255, 255, 255, 0.55);
        line-height: 1.7;
    }

    .actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 18px;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px 14px;
        text-decoration: none;
        border: 1px solid var(--green);
        color: var(--green);
        background: transparent;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 13px;
        transition: 0.15s ease;
        user-select: none;
    }

    .btn:hover {
        background: var(--green);
        color: #000;
    }

    .btn:focus {
        outline: 2px solid rgba(51, 255, 0, 0.35);
        outline-offset: 2px;
    }

    @media (max-width: 540px) {
        .actions {
            grid-template-columns: 1fr;
        }

        .timer .value {
            font-size: 24px;
        }
    }

    .stamp {
        margin-top: 12px;
        font-size: 12px;
        color: rgba(255, 255, 255, 0.45);
    }

    .stamp b {
        color: var(--green);
    }
    </style>
</head>

<body>
    <div class="wrap">
        <h1 class="title">> SYSTEM_NOTICE: <?php echo $isSuspended
            ? "ACCOUNT_SUSPENDED"
            : "TEMPORARY_BLOCK_ACTIVE"; ?>
        </h1>
        <div class="msg">
            <?php if ($isSuspended): ?>
            > Your account has been <span style="color: var(--red); font-weight: 700;">suspended</span>. During
            suspension, the system only allows you to:
            <br>
            - Access the chat page
            <br>
            - Access the data entry page
            <br>
            > Any attempt to access other links will be treated as a <span
                style="color: var(--red); font-weight: 700;">bypass attempt</span>.
            <?php else: ?>
            > A temporary block has been activated on your account. During the block period, the system only allows you
            to:
            <br>
            - Access the chat page
            <br>
            - Access the data entry page
            <br>
            > Any attempt to access other links will be treated as a <span
                style="color: var(--red); font-weight: 700;">bypass attempt</span>.
            <?php endif; ?>
        </div>

        <?php if (!$isSuspended): ?>
        <div class="timer" aria-live="polite">
            <div class="label">Time remaining until automatic unblock</div>
            <div class="value" id="countdown">--:--:--</div>
        </div>
        <?php endif; ?>

        <?php if ($isSuspended): ?>
        <div class="fineprint" style="margin-top: 10px; color: rgba(255,255,255,0.7);">
            > This account is suspended. Contact administration for assistance.
        </div>
        <?php elseif ($banLookupFailed): ?>
        <div class="fineprint" style="margin-top: 10px; color: rgba(255,255,255,0.7);">
            > The system could not determine the block duration at this time. Please try again later.
        </div>
        <?php endif; ?>

        <div class="actions">
            <a class="btn" href="/massage/index.php">[ CHAT ]</a>
            <a class="btn" href="/add.php">[ DATA ENTRY ]</a>
        </div>

        <?php if ($isSuspended): ?>
        <div class="fineprint">
            > Note: This account is suspended. All activities are monitored.
            <br>
            > Any violation may result in further action.
        </div>
        <?php else: ?>
        <div class="fineprint">
            > Note: Attempts to use unauthorized links are monitored.
            <br>
            > Repeated violations will result in a notification sent to administration to review your activity.
        </div>
        <?php endif; ?>

        <div class="stamp">SYSTEM_TIMESTAMP:
            <b><?php echo htmlspecialchars(
                date("Y-m-d H:i:s"),
                ENT_QUOTES,
                "UTF-8",
            ); ?></b> | STATUS:
            <b><?php echo $isSuspended
                ? "SUSPENDED"
                : $banUntil ?? "UNKNOWN"; ?></b>
        </div>

        <div style="margin-top: 8px; font-size: 10px; color: rgba(255, 255, 255, 0.4); text-align: center;">
            Block duration: three days
        </div>
    </div>

    <?php if (!$isSuspended): ?>
    <script>
    (function() {
        'use strict';

        var banUntilMs =
            <?php echo json_encode(
                        $banUntilMs,
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
                    ); ?>;
        var el = document.getElementById('countdown');

        function pad(n) {
            n = Math.floor(n);
            return (n < 10 ? '0' : '') + n;
        }

        function tick() {
            var now = Date.now();
            var diff = banUntilMs - now;
            if (diff <= 0) {
                el.textContent = '00:00:00';

                setTimeout(function() {
                    window.location.href = '/';
                }, 600);

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