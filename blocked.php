<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Auth\Session;
use ROOTS\Config\Database;
use ROOTS\Middleware\SecurityHeadersMiddleware;
use ROOTS\Middleware\BotProtectionMiddleware;
use ROOTS\Auth\BanSystem;

Session::start();

// Apply Global Protections
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
if ($violationCount >= 50) {
    // User has 50+ violations, redirect to permanent ban page
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

if (
    !$isSuspended &&
    (empty($banUntil) || strtotime((string) $banUntil) <= time())
) {
    unset($_SESSION["blocked_notice_shown"]);
    header("Location: /login");
    exit();
}

$_SESSION["blocked_notice_shown"] = 1;

$banUntilTs = !empty($banUntil) ? strtotime((string) $banUntil) : 0;
$banUntilMs = $banUntilTs > 0 ? $banUntilTs * 1000 : 0;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Notice - Blocked</title>
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
            width: min(880px, 100%);
            border: 1px solid var(--dim-green);
            background: var(--panel);
            box-shadow: 0 0 40px rgba(0, 255, 65, 0.05), inset 0 0 20px rgba(0, 255, 65, 0.02);
            padding: 30px;
            position: relative;
            z-index: 10;
            backdrop-filter: blur(4px);
            border-radius: 4px;
            margin: auto;
        }

        .title {
            margin: 0 0 20px 0;
            font-size: 22px;
            letter-spacing: 2px;
            border-bottom: 1px solid var(--dim-green);
            padding-bottom: 15px;
            color: var(--green);
            text-transform: uppercase;
            text-shadow: 0 0 10px rgba(0, 255, 65, 0.3);
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
            padding-left: 15px;
            border-left: 2px solid var(--dim-green);
        }

        .timer-box {
            margin: 25px 0;
            padding: 20px;
            background: rgba(0, 40, 0, 0.2);
            border: 1px solid var(--dim-green);
            text-align: center;
            border-radius: 2px;
            position: relative;
            overflow: hidden;
        }

        .timer-box::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--green);
            animation: scan-line 3s linear infinite;
            opacity: 0.3;
        }

        @keyframes scan-line {
            0% { top: 0; }
            100% { top: 100%; }
        }

        .timer-label {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .timer-value {
            font-size: 32px;
            font-weight: bold;
            color: var(--amber);
            letter-spacing: 4px;
            font-family: monospace;
            text-shadow: 0 0 10px rgba(255, 177, 0, 0.3);
        }

        .fineprint {
            margin-top: 20px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
            line-height: 1.6;
            padding: 15px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 2px;
        }

        .actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            padding: 14px 20px;
            border: 1px solid var(--green);
            color: var(--green);
            text-decoration: none;
            background: rgba(0, 255, 65, 0.05);
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            font-size: 12px;
            transition: all 0.3s;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 2px;
        }

        .btn:hover {
            background: var(--green);
            color: #000;
            box-shadow: 0 0 20px var(--dim-green);
            transform: translateY(-2px);
        }

        .btn-red {
            border-color: var(--red);
            color: var(--red);
        }

        .btn-red:hover {
            background: var(--red);
            color: #fff;
            box-shadow: 0 0 20px var(--dim-red);
        }

        .stamp {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px dashed var(--dim-green);
            font-size: 11px;
            color: rgba(255, 255, 255, 0.3);
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .stamp b { color: var(--green); font-weight: normal; }

        @media (max-width: 600px) {
            .wrap { padding: 20px; }
            .timer-value { font-size: 24px; }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <?php $titleText = $isSuspended ? "ACCOUNT_SUSPENDED" : "TEMPORARY_BLOCK_ACTIVE"; ?>
        <h1 class="title">
            <span class="glitch" data-text="SYSTEM_NOTICE: <?php echo $titleText; ?>">
                > SYSTEM_NOTICE: <?php echo $titleText; ?>
            </span>
        </h1>

        <div class="msg">
            <?php if ($isSuspended): ?>
            <span class="msg-line">> STATUS: <b style="color: var(--red);">SUSPENDED</b></span>
            <span class="msg-line">> Access to core modules has been restricted by system firewall.</span>
            <span class="msg-line">> Allowed operations: <b style="color: var(--blue);">[CHAT]</b>, <b style="color: var(--blue);">[DATA_ENTRY]</b>.</span>
            <?php else: ?>
            <span class="msg-line">> STATUS: <b style="color: var(--amber);">TEMPORARY_BLOCK</b></span>
            <span class="msg-line">> Security protocols have restricted your access level.</span>
            <span class="msg-line">> Allowed operations: <b style="color: var(--blue);">[CHAT]</b>, <b style="color: var(--blue);">[DATA_ENTRY]</b>.</span>
            <?php endif; ?>
            <span class="msg-line">> <b style="color: var(--red);">WARNING</b>: Navigation bypass attempts are logged and flagged.</span>
        </div>

        <?php if (!$isSuspended): ?>
        <div class="timer-box" aria-live="polite">
            <div class="timer-label">TIME_UNTIL_AUTOMATIC_UNBLOCK</div>
            <div class="timer-value" id="countdown">--:--:--:--</div>
        </div>
        <?php endif; ?>

        <?php if ($isSuspended): ?>
        <div class="fineprint">
            > This account is under administrative review.
            <br>
            > Please contact system support for further clarification.
        </div>
        <?php elseif ($banLookupFailed): ?>
        <div class="fineprint">
            > CRITICAL: Unable to synchronize block duration with master server.
            <br>
            > Please re-establish connection later.
        </div>
        <?php endif; ?>

        <div class="actions">
            <a class="btn" href="/massage/index">[ CHAT_SYSTEM ]</a>
            <a class="btn" href="/add">[ DATA_ENTRY_PORTAL ]</a>
            <a class="btn btn-red" href="/logout">[ TERMINATE_SESSION ]</a>
        </div>

        <div class="fineprint">
            > Note: All terminal activities are being monitored by the system integrity monitor.
            <br>
            > Repeated violations will escalate this incident to administrative review.
        </div>

        <div class="stamp">
            <div>TIMESTAMP: <b><?php echo date("Y-m-d H:i:s"); ?></b></div>
            <div>STATUS_ID: <b><?php echo $isSuspended ? "SUSPENDED" : ($banUntil ?? "UNKNOWN"); ?></b></div>
        </div>

        <div style="margin-top: 15px; font-size: 10px; color: rgba(255, 255, 255, 0.2); text-align: center; letter-spacing: 1px;">
            STANDARD_BLOCK_DURATION: 259200_SECONDS (3_DAYS)
        </div>
    </div>

    <?php if (!$isSuspended): ?>
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
                setTimeout(function() { window.location.href = '/'; }, 1000);
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

