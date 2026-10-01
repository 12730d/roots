<?php

declare(strict_types=1);

namespace ROOTS\Middleware;

use ROOTS\Auth\BanSystem;

/**
 * Middleware to protect against malicious bots, scrapers, and AI crawlers.
 */
class BotProtectionMiddleware
{
    /**
     * List of blocked User-Agent patterns (Regex)
     */
    private const BLOCKED_BOTS = [
        // Malicious / Attack Bots
        'OpenClaw', 'MoltBot', 'ClawdBot', 'SSHStalker', 'Kimwolf', 'ZeroDayRAT',
        'GoBruteforcer', 'Mirai', 'QakBot', 'Emotet', 'PXA-Stealer', 'AtomicStealer',
        'AMOS', 'RedLine', 'AgentTesla', 'Lumma', 'AuraStealer', 'DarkSpectre',

        // Scrapers / Libraries
        'aiohttp', 'python-requests', 'curl', 'libwww-perl',

        // AI Crawlers
        'GPTBot', 'ClaudeBot', 'anthropic-ai', 'PerplexityBot', 'Amazonbot',
        'Google-Extended', 'CCBot', 'Omgilibot', 'FacebookBot',

        // SEO / Marketing Bots
        'SemrushBot', 'AhrefsBot', 'MJ12bot', 'DotBot', 'DataForSeoBot',
        'Bytespider', 'PetalBot', 'AspiegelBot', 'BLEXBot', 'SeznamBot',
        'Baiduspider', 'YandexBot', 'Sogou'
    ];

    /**
     * Apply bot protection.
     *
     * @return void
     */
    public static function apply(): void
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (empty($userAgent)) {
            self::block('EMPTY_USER_AGENT');
        }

        foreach (self::BLOCKED_BOTS as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                self::block($bot);
            }
        }
    }

    /**
     * Block the request and terminate execution.
     *
     * @param string $botName The detected bot name.
     */
    private static function block(string $botName): never
    {
        http_response_code(403);

        // Log the event
        error_log("[SECURITY] Bot access blocked: {$botName} | IP: " . BanSystem::getClientIp());

        // Display a professional terminal-style error
        die('<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>403 Forbidden - Security Shield</title>
    <style>
        body { background: #0a0a0a; color: #00ff00; font-family: "Courier New", Courier, monospace; padding: 50px; }
        .box { border: 1px solid #00ff00; padding: 20px; box-shadow: 0 0 15px #00ff00; max-width: 600px; margin: 0 auto; }
        .alert { color: #ff0000; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <p>> [SYSTEM_INIT] Security Protocol Active...</p>
        <p>> [SCAN] Analyzing request signature...</p>
        <p class="alert">> [DETECTED] Malicious pattern or crawler detected: ' . htmlspecialchars($botName) . '</p>
        <p>> [ACTION] Access denied. IP has been recorded.</p>
        <p>> Connection terminated.</p>
    </div>
</body>
</html>');
    }
}
