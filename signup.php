<?php
// signup.php - Registration Portal (Optimized v4.1)
// Changes: reduced visual effects, faster load, SEO-ready, same backend workflow
require_once __DIR__ . "/vendor/autoload.php";

use Gregwar\Captcha\CaptchaBuilder;
use ROOTS\Auth\Session;

// Ensure session is started via central management
Session::start();
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Detect if running on Onion service
$isOnionService = (isset($_SERVER['HTTP_HOST']) && preg_match('/\.onion$/i', $_SERVER['HTTP_HOST'])) ||
                  (isset($_SERVER['SERVER_NAME']) && preg_match('/\.onion$/i', $_SERVER['SERVER_NAME']));

// ============================================================================
// SECURITY HEADERS - ONION SERVICE COMPATIBLE
// ============================================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Set Content-Security-Policy based on connection type
if ($isOnionService) {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https: https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src \'self\' data: http: https:; font-src \'self\' https: data: https://cdnjs.cloudflare.com https://fonts.gstatic.com;',
    );
} else {
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https:; style-src \'self\' \'unsafe-inline\' https: https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src \'self\' data: https:; font-src \'self\' https: data: https://cdnjs.cloudflare.com https://fonts.gstatic.com;',
    );
}

// Set HSTS header only for non-Onion HTTPS connections
if (!$isOnionService && isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

// Enable gzip output compression
if (!ob_get_level()) ob_start('ob_gzhandler');

// Initialize Captcha
$captchaBuilder = new CaptchaBuilder();
$captchaBuilder
    ->setDistortion(false)
    ->setMaxFrontLines(0)
    ->setMaxBehindLines(0)
    ->setBackgroundColor(255, 255, 255)
    ->setTextColor(0, 0, 0)
    ->build();
$_SESSION['captcha_phrase'] = $captchaBuilder->getPhrase();
$captchaInline = $captchaBuilder->inline();

// Handle AJAX refresh request
if (isset($_GET['ajax']) && $_GET['ajax'] === 'refresh_captcha') {
    header('Content-Type: application/json');
    echo json_encode([
        'image' => $captchaInline,
        'success' => true
    ]);
    exit;
}

// ----------------------------
// Rate limiting configuration
// ----------------------------
$maxAttempts = 5;
$lockoutTime = 900; // 15 minutes
$rateLimitKey = 'registration_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// Check rate limiting
if (isset($_SESSION[$rateLimitKey])) {
    $attempts = $_SESSION[$rateLimitKey];
    if ($attempts['count'] >= $maxAttempts && (time() - $attempts['first_attempt']) < $lockoutTime) {
        $remainingTime = $lockoutTime - (time() - $attempts['first_attempt']);
        $_SESSION['flash_message'] = "Too many attempts. Please try again in " . ceil($remainingTime / 60) . " minute(s).";
        $_SESSION['flash_type'] = "error";
    }
}

// ----------------------------
// Safe input cleaner
// ----------------------------
function cleanInput(string $data): string
{
    return htmlspecialchars(trim($data), ENT_QUOTES, "UTF-8");
}

// ----------------------------
// Email validator
// ----------------------------
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

require_once __DIR__ . '/includes/plan-catalog.php';

/** @var array<string, mixed> $planMeta */
/** @var array<string, mixed> $planSpecGroups */

?>

<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description"
        content="The Fund Registry — free staff registration, tiered civil registry access, and Mobile PBX infrastructure. Start your 60-day trial today." />
    <meta name="keywords"
        content="civil registry, staff registration, Mobile PBX, secure portal, free trial, database access" />
    <meta name="author" content="The Fund Registry" />
    <meta name="robots" content="index, follow" />
    <meta name="theme-color" content="#050505" />
    <title>The Fund Registry | Registration Portal</title>
    <link rel="canonical" href="https://example.com/signup" />
    <link rel="icon" type="image/x-icon" href="favicon.ico" />

    <!-- Performance: font loading optimized -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap"
        rel="stylesheet" crossorigin="anonymous" />

    <!-- Styles (same files, same versions) -->
    <link rel="stylesheet" href="/css/term-variables.css?v=1" />
    <link rel="stylesheet" href="/css/contact-terminal.css?v=8" />
    <link rel="stylesheet" href="/css/captcha-terminal.css?v=3" />
    <?php renderPlanCardsCSS(); ?>

    <!-- Performance: disable heavy visual effects without touching CSS files -->
    <style>
    /* --- VISUAL EFFECTS REDUCTION (override layer) --- */
    /* Remove animated canvas background entirely */
    #matrix-bg {
        display: none !important;
    }

    /* Remove CRT scanline overlay */
    .crt-overlay {
        display: none !important;
    }

    /* Remove ambient glow animation */
    .ambient-glow {
        display: none !important;
    }

    /* Keep grid overlay but static & subtle */
    .grid-overlay {
        opacity: 0.15 !important;
    }

    /* Remove glitch text animation */
    .glitch-text {
        animation: none !important;
    }

    .glitch-text::before,
    .glitch-text::after {
        display: none !important;
    }

    /* Remove hero scan line */
    .hero-scan {
        display: none !important;
    }

    /* Reduce heavy shadows on cards */
    .plan-card,
    .why-card,
    .support-card,
    .term-window {
        box-shadow: none !important;
    }

    /* Respect reduced motion preference */
    @media (prefers-reduced-motion: reduce) {

        *,
        *::before,
        *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }

    /* Content-visibility: skip offscreen rendering for long sections */
    .why-section,
    .support-grid,
    .contact-block {
        content-visibility: auto;
        contain-intrinsic-size: 400px;
    }
    </style>

    <!-- Open Graph / SEO -->
    <meta property="og:type" content="website" />
    <meta property="og:title" content="The Fund Registry | Registration Portal" />
    <meta property="og:description"
        content="Free staff registration, tiered civil registry access, and Mobile PBX infrastructure. Start your 60-day trial today." />
    <meta property="og:url" content="https://example.com/signup" />
    <meta name="twitter:card" content="summary" />
    <meta name="twitter:title" content="The Fund Registry | Registration Portal" />
    <meta name="twitter:description"
        content="Free staff registration, tiered civil registry access, and Mobile PBX infrastructure." />

    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebPage",
        "name": "The Fund Registry | Registration Portal",
        "description": "Free staff registration, tiered civil registry access, and Mobile PBX infrastructure. Start your 60-day trial today.",
        "url": "https://example.com/signup"
    }
    </script>
</head>

<body class="page-contact">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <!-- Heavy visual layers disabled (kept for structure compatibility) -->
    <canvas id="matrix-bg" class="matrix-canvas" hidden></canvas>
    <div class="grid-overlay" aria-hidden="true"></div>
    <div class="crt-overlay" aria-hidden="true"></div>
    <div class="ambient-glow" aria-hidden="true"></div>

    <div class="contact-wrap">

        <header class="site-header">
            <a href="signup" class="brand">
                <span class="brand-mark" aria-hidden="true">⛨</span>
                <span class="brand-title">The Fund Registry</span>
            </a>
            <nav class="site-nav" aria-label="Primary navigation">
                <ul>
                    <li><a href="about">About</a></li>
                    <li><a href="signup" class="nav-cta">Registry</a></li>
                    <li><a href="login" class="nav-cta">Sign in</a></li>
                </ul>
            </nav>
        </header>

        <section class="hero" aria-labelledby="hero-title">
            <p class="hero-eyebrow">Registry portal // secure channel</p>
            <h1 id="hero-title" class="glitch-text" data-text="What is your registry hiding from operations?">
                What is your <span class="accent">registry</span> hiding from operations?
            </h1>
            <p class="hero-lead">
                Create your free account in minutes. Upgrade subscription tiers anytime from the dashboard to unlock
                deeper civil registry and network capabilities.
            </p>
            <p class="hero-hook">
                Explore tiered civil-registry sync and Mobile PBX infrastructure — start with a 60-day trial, then scale
                when your team is ready.
            </p>
            <div class="hero-actions">
                <a href="#register-form" class="btn btn-primary">Get free access</a>
                <a href="#plans-heading" class="btn btn-secondary">Compare plans</a>
                <a href="login" class="btn btn-ghost">Already registered? Sign in</a>
            </div>
        </section>

        <div class="proof-strip" aria-label="Trust indicators">
            <div class="proof-item">
                <span class="proof-value">132</span>
                <span class="proof-label">Countries</span>
            </div>
            <div class="proof-item">
                <span class="proof-value">117 TB</span>
                <span class="proof-label">Data capacity</span>
            </div>
            <div class="proof-item">
                <span class="proof-value">&lt;24h</span>
                <span class="proof-label">Support response</span>
            </div>
            <div class="proof-item">
                <span class="proof-value">60 days</span>
                <span class="proof-label">Free</span>
            </div>
        </div>

        <main class="contact-main" id="main-content">

            <section class="why-section" aria-labelledby="why-heading">
                <p class="section-label">Why subscribe now</p>
                <h2 id="why-heading" class="section-title">Start free — explore, then scale</h2>
                <div class="why-grid">
                    <article class="why-card">
                        <h3>Risk-free evaluation</h3>
                        <p>60 days on Free to explore civil-registry channels and Mobile PBX before any paid commitment.
                        </p>
                    </article>
                    <article class="why-card">
                        <h3>Dashboard upgrades</h3>
                        <p>Move between Basic, Pro, and Premium when operations need deeper data, API, or write access.
                        </p>
                    </article>
                    <article class="why-card">
                        <h3>Encrypted channel</h3>
                        <p>CAPTCHA-protected registration with dedicated staff support in under 24 hours.</p>
                    </article>
                </div>
            </section>

            <?php if (isset($_SESSION["flash_message"])): ?>
            <?php
                $flashType = $_SESSION["flash_type"] ?? "info";
                $flashClass = match ($flashType) {
                    "success" => "flash flash--success",
                    "error" => "flash flash--error",
                    default => "flash flash--info",
                };
                ?>
            <div class="<?php echo htmlspecialchars($flashClass, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
                <span aria-hidden="true">▸</span>
                <span>
                    <?php echo htmlspecialchars(
                            $_SESSION["flash_message"],
                            ENT_QUOTES,
                            "UTF-8",
                        ); ?>
                </span>
            </div>
            <?php unset(
                    $_SESSION["flash_message"],
                    $_SESSION["flash_type"],
                ); ?>
            <?php endif; ?>

            <section class="plans-section" aria-labelledby="plans-heading">
                <p class="section-label">Access tiers</p>
                <h2 id="plans-heading" class="section-title">Choose your path — upgrade anytime</h2>
                <p class="plans-intro">
                    Register on Free today. Paid tiers (Basic, Pro, Premium) unlock from your dashboard when you need
                    more registry depth, API capacity, or write control.
                </p>
                <div class="subscription-cards-container">
                    <div class="subscription-cards-row" role="radiogroup" aria-label="Subscription plan">
                        <article class="plan-card plan-card--selected" data-plan="free" data-price="0">
                            <span class="plan-badge plan-badge--trial">60-day</span>
                            <span class="plan-tier-glyph" aria-hidden="true">◇</span>
                            <header class="plan-head plan-head--free">
                                <p class="plan-tier-label">Tier 00</p>
                                <h3>Free</h3>
                                <p class="plan-tagline">Safe sandbox — upgrade from dashboard when ready</p>
                                <p class="plan-price"><span class="amount">$0</span><span class="period">/ 60-day</span>
                                </p>
                            </header>
                            <div class="plan-body">
                                <?php renderPlanMetaExtras($planMeta['free']); ?>
                                <?php renderPlanSpecGroups($planSpecGroups['free']); ?>
                            </div>
                            <footer class="plan-foot">
                                <label class="plan-select">
                                    <input type="radio" name="plan_selection_radio" value="free" checked>
                                    <span>Free — $0 / 60 days</span>
                                </label>
                            </footer>
                        </article>

                        <article class="plan-card" data-plan="basic" data-price="3000">
                            <span class="plan-tier-glyph" aria-hidden="true">◆</span>
                            <header class="plan-head plan-head--basic">
                                <p class="plan-tier-label">Tier 01</p>
                                <h3>Basic</h3>
                                <p class="plan-tagline">Production reads — 43 countries, 12 TB</p>
                                <p class="plan-price"><span class="amount">$3k</span><span class="period">/ 1
                                        year</span></p>
                            </header>
                            <div class="plan-body">
                                <?php renderPlanMetaExtras($planMeta['basic']); ?>
                                <?php renderPlanSpecGroups($planSpecGroups['basic']); ?>
                            </div>
                            <footer class="plan-foot">
                                <label class="plan-select">
                                    <input type="radio" name="plan_selection_radio" value="basic">
                                    <span>Basic — $3k / year</span>
                                </label>
                            </footer>
                        </article>

                        <article class="plan-card plan-card--popular" data-plan="pro" data-price="7000">
                            <span class="plan-badge plan-badge--popular">Most</span>
                            <span class="plan-tier-glyph" aria-hidden="true">★</span>
                            <header class="plan-head plan-head--pro">
                                <p class="plan-tier-label">Tier 02</p>
                                <h3>Pro</h3>
                                <p class="plan-tagline">Full read/write — 65 countries, 47 TB</p>
                                <p class="plan-price"><span class="amount">$7k</span><span class="period">/ 3
                                        years</span></p>
                            </header>
                            <div class="plan-body">
                                <?php renderPlanMetaExtras($planMeta['pro']); ?>
                                <?php renderPlanSpecGroups($planSpecGroups['pro']); ?>
                            </div>
                            <footer class="plan-foot">
                                <label class="plan-select">
                                    <input type="radio" name="plan_selection_radio" value="pro">
                                    <span>Pro — $7k / 3 years</span>
                                </label>
                            </footer>
                        </article>

                        <article class="plan-card plan-card--vip" data-plan="premium" data-price="12000">
                            <span class="plan-badge plan-badge--vip">VIP</span>
                            <span class="plan-tier-glyph plan-tier-glyph--gold" aria-hidden="true">♛</span>
                            <header class="plan-head plan-head--premium">
                                <p class="plan-tier-label">Tier 03</p>
                                <h3>Premium</h3>
                                <p class="plan-tagline">Full sovereignty — 132 countries, VIP lane</p>
                                <p class="plan-price"><span class="amount">$12k</span><span class="period">/ 5
                                        years</span></p>
                            </header>
                            <div class="plan-body">
                                <?php renderPlanMetaExtras($planMeta['premium']); ?>
                                <?php renderPlanSpecGroups($planSpecGroups['premium']); ?>
                            </div>
                            <footer class="plan-foot">
                                <label class="plan-select plan-select--gold">
                                    <input type="radio" name="plan_selection_radio" value="premium">
                                    <span>Premium — $12k / 5 years</span>
                                </label>
                            </footer>
                        </article>
                    </div>
                </div>
            </section>

            <section class="support-grid" aria-labelledby="support-heading">
                <p class="section-label">Staff channels</p>
                <h2 id="support-heading" class="section-title">Dedicated support lines</h2>
                <article class="support-card">
                    <h3><span aria-hidden="true">◫</span> Civil registry support</h3>
                    <p>
                        Dedicated sync channel for registry staff. Includes priority national-ID verification and
                        database alignment.
                    </p>
                    <ul class="support-list">
                        <li>Database error correction and reconciliation</li>
                        <li>Batch document processing workflows</li>
                        <li>Identity verification escalation</li>
                    </ul>
                </article>

                <article class="support-card support-card--pbx">
                    <h3><span aria-hidden="true">☎</span> Mobile PBX technical support</h3>
                    <p>
                        Direct line for engineers operating Mobile PBX infrastructure and secure routing.
                    </p>
                    <ul class="support-list">
                        <li>International call transfer backed by agents in all covered countries</li>
                        <li>Contact-center staff cover special costs for secure, low-friction connections</li>
                        <li>Secure routing setup and failover configuration</li>
                    </ul>
                </article>

                <article class="support-card support-card--drone">
                    <h3><span aria-hidden="true">🚁</span> Drone engineering support</h3>
                    <p>
                        We have engineers at the highest level in drone engineering
                    </p>
                    <p>
                        Maintenance, installation, and integration of site API code. You can contact them through the
                        site chat only
                    </p>
                    <p>
                        They are available 24 hours a day
                    </p>
                </article>
            </section>

            <section class="register-section" id="register-form" aria-labelledby="register-heading">
                <div class="term-window">
                    <div class="term-body">
                        <p class="term-boot-line" aria-hidden="true">channel ready — awaiting registration payload</p>
                        <div class="register-header">
                            <h2 id="register-heading">Create your free account</h2>
                            <p>Join The Fund Registry. Upgrade tiers anytime from your dashboard.</p>
                        </div>

                        <form id="registration-form" action="<?php echo htmlspecialchars(
                            "submit_signup",
                            ENT_QUOTES,
                            "UTF-8",
                        ); ?>" method="POST" novalidate>

                            <input type="hidden" name="plan" id="hidden-plan" value="free">
                            <input type="hidden" name="plan_price" id="plan-price" value="0">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(
                                    $_SESSION["csrf_token"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ); ?>">

                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="username">Username *</label>
                                    <div class="prompt-field">
                                        <span class="prompt-prefix" aria-hidden="true">$</span>
                                        <input type="text" id="username" name="username" required maxlength="50"
                                            class="field-input" autocomplete="username" placeholder="username">
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label for="email">Email address *</label>
                                    <div class="prompt-field">
                                        <span class="prompt-prefix" aria-hidden="true">@</span>
                                        <input type="email" id="email" name="email" required
                                            pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$" class="field-input"
                                            autocomplete="email" placeholder="you@domain.com">
                                    </div>
                                    <p id="email-error" class="field-error" hidden>Please enter a valid email address
                                    </p>
                                </div>
                                <div class="input-group">
                                    <label for="password">Password *</label>
                                    <div class="prompt-field">
                                        <span class="prompt-prefix" aria-hidden="true">#</span>
                                        <input type="password" id="password" name="password" required
                                            autocomplete="new-password" class="field-input" placeholder="••••••••">
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label for="confirm_password">Confirm password *</label>
                                    <div class="prompt-field">
                                        <span class="prompt-prefix" aria-hidden="true">#</span>
                                        <input type="password" id="confirm_password" name="confirm_password" required
                                            autocomplete="new-password" class="field-input" placeholder="••••••••">
                                    </div>
                                    <p id="password-match-error" class="field-error" hidden>Passwords do not match</p>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="name">Full name (optional)</label>
                                    <div class="prompt-field">
                                        <span class="prompt-prefix" aria-hidden="true">~</span>
                                        <input type="text" id="name" name="name" maxlength="50" class="field-input"
                                            autocomplete="name">
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label for="wallet">Wallet address (optional)</label>
                                    <div class="prompt-field">
                                        <span class="prompt-prefix" aria-hidden="true">₿</span>
                                        <input type="text" id="wallet" name="wallet" maxlength="52" class="field-input">
                                    </div>
                                </div>
                            </div>

                            <div class="input-group form-grid--full">
                                <label for="comment">Staff notes / comment</label>
                                <div class="prompt-field prompt-field--textarea">
                                    <span class="prompt-prefix" aria-hidden="true">&gt;</span>
                                    <textarea id="comment" name="comment" rows="3" maxlength="1000"
                                        placeholder="Department codes or special requests..."
                                        class="field-textarea"></textarea>
                                </div>
                            </div>

                            <div class="captcha-block">
                                <div class="captcha-row">
                                    <img id="captcha" class="captcha-img"
                                        src="<?php echo htmlspecialchars($captchaInline, ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="Security verification code" width="200" height="60">
                                    <input type="text" name="captcha_code" class="field-input captcha-input"
                                        placeholder="SECURITY_CODE" required autocomplete="off"
                                        aria-label="CAPTCHA code">
                                </div>
                                <button type="button" class="captcha-regen" id="captcha-refresh">
                                    [ regenerate_code ]
                                </button>
                            </div>

                            <button type="submit" id="submitBtn" class="form-submit">
                                Complete free registration
                            </button>

                            <p class="form-legal">
                                By registering, you agree to The Fund Registry terms of service.
                            </p>
                        </form>
                    </div>
                </div>
            </section>

            <section class="contact-block" aria-labelledby="contact-heading">
                <h2 id="contact-heading">Staff support response time: <span class="highlight">under 24 hours</span></h2>

                <div class="tox-card">
                    <img src="/img/tox.jpg" alt="Tox" width="28" height="28" loading="lazy" />
                    <div>
                        <div class="tox-label">Secure contact (qTox ID)</div>
                        <div class="tox-id">8CE5724D1B7F65ECB2B461E4D8675EBED58C1EDEE76846C94C81526C1724D920DB613CDD8814
                        </div>
                    </div>
                </div>

                <div class="contact-methods">
                    <div class="contact-row">
                        <div class="contact-row-inner">
                            <span class="contact-icon contact-icon--mail" aria-hidden="true">✉</span>
                            <div class="contact-meta">
                                <div class="contact-meta-label">Email</div>
                                <a href="mailto:buttcry@protonmail.com">buttcry@protonmail.com</a>
                            </div>
                        </div>
                        <button type="button" class="contact-copy" data-copy="buttcry@protonmail.com"
                            title="Copy email address">Copy</button>
                    </div>

                    <div class="contact-mini-grid">
                        <div class="contact-mini">
                            <div class="contact-row-inner">
                                <span class="contact-icon contact-icon--shield" aria-hidden="true">⛨</span>
                                <div class="contact-meta">
                                    <div class="contact-meta-label">PGP key</div>
                                    <div class="mono">Available on request</div>
                                </div>
                            </div>
                        </div>
                        <div class="contact-mini">
                            <div class="contact-row-inner">
                                <span class="contact-icon contact-icon--clock" aria-hidden="true">◷</span>
                                <div class="contact-meta">
                                    <div class="contact-meta-label">Response time</div>
                                    <div class="mono">Under 24 hours</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <footer class="site-footer">
        <p>&copy; <?php echo htmlspecialchars((string) date("Y"), ENT_QUOTES, "UTF-8"); ?> The Fund Registry. All rights
            reserved. Encrypted connection.</p>
    </footer>

    <script>
    // Submit button loading state
    document.getElementById('registration-form').addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '[ PROCESSING_REQUEST... ]';
        btn.style.opacity = '0.8';
        btn.style.cursor = 'wait';
    });
    </script>
    <!-- JS loads deferred — does not block rendering -->
    <script src="/js/contact-terminal.js?v=3" defer></script>
</body>

</html>