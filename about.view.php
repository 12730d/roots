<?php
// Initialize variables if not set (for static analysis compatibility)
$planCatalogDisplay = $planCatalogDisplay ?? [];
$year = $year ?? date('Y');
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="The Fund Registry — complete guide to registration form fields, plan features, and service capabilities." />
    <title>The Fund Registry | Registration Guide</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet" crossorigin="anonymous" />
    <link rel="stylesheet" href="/css/contact-terminal.css?v=8" />
    <?php renderPlanCardsCSS(); ?>
</head>

<body class="page-contact page-guide">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <canvas id="matrix-bg" class="matrix-canvas" aria-hidden="true" hidden tabindex="-1"></canvas>
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
                    <li><a href="about" aria-current="page">About</a></li>
                    <li><a href="signup" class="nav-cta">Registry</a></li>
                    <li><a href="../login" class="nav-cta">Sign in</a></li>
                </ul>
            </nav>
        </header>

        <section class="hero" aria-labelledby="guide-hero-title">
            <p class="hero-eyebrow">Documentation // field reference</p>
            <h1 id="guide-hero-title" class="glitch-text" data-text="Complete registration guide">
                Complete <span class="accent">registration</span> guide
            </h1>
            <span class="hero-scan" aria-hidden="true"></span>
            <p class="hero-lead">
                Comprehensive information about registration form fields and tiered service capabilities across all plans.
            </p>
            <div class="hero-actions">
                <a href="signup" class="btn btn-primary">Start free registration</a>
                <a href="#form-fields" class="btn btn-secondary">Form fields</a>
                <a href="#service-features" class="btn btn-ghost">Service features</a>
            </div>
        </section>

        <main id="main-content">
            <div class="guide-main">
            <nav class="guide-jump" aria-label="On this page">
                <a href="#form-fields">Form fields</a>
                <a href="#service-features">All plans</a>
                <a href="#plan-free">Free</a>
                <a href="#plan-basic">Basic</a>
                <a href="#plan-pro">Pro</a>
                <a href="#plan-premium">Premium</a>
            </nav>

            <section id="form-fields" class="guide-section" aria-labelledby="form-fields-heading">
                <p class="section-label">Section 01</p>
                <h2 id="form-fields-heading" class="section-title">Form fields &amp; requirements</h2>

                <div class="term-window">
                    <div class="term-titlebar">
                        <div class="term-dots" aria-hidden="true">
                            <span class="dot-close"></span>
                            <span class="dot-min"></span>
                            <span class="dot-max"></span>
                        </div>
                        <span class="term-title">registry@portal:~/docs/form-fields — cat registration_guide.md</span>
                        <span class="term-titlebar-status">READ-ONLY</span>
                    </div>
                    <div class="term-body">
                        <p class="term-boot-line" aria-hidden="true">documentation loaded — 4 fields indexed</p>
                        <p class="guide-intro">
                            Use this reference when completing the registration form on the portal. Required fields are marked; optional fields help our team tailor your access.
                        </p>

                        <ul class="guide-list">
                            <li class="guide-item">
                                <h3 class="guide-item-label">Full name</h3>
                                <div class="guide-item-body">
                                    <p><strong>Required field:</strong> Enter your complete legal name exactly as it appears on your official documents. This should include your first name, middle name (if applicable), and last name.</p>
                                    <p><strong>Important:</strong> Use only letters, spaces, and hyphens. Avoid special characters or numbers. The name should match your government-issued ID for verification purposes.</p>
                                    <p><strong>Examples:</strong> John Smith, Maria Garcia-Lopez, Ahmed Al-Rashid</p>
                                </div>
                            </li>

                            <li class="guide-item">
                                <h3 class="guide-item-label">Email address</h3>
                                <div class="guide-item-body">
                                    <p><strong>Required field:</strong> Provide a valid, active email address that you check regularly. This email will be used for important communications including payment confirmations, account activation, and support requests.</p>
                                    <p><strong>Requirements:</strong></p>
                                    <ul class="guide-tier-list">
                                        <li>Must be a valid email format (example@domain.com)</li>
                                        <li>Should be an email you have access to</li>
                                        <li>Avoid temporary or disposable email addresses</li>
                                        <li>Gmail, Outlook, Proton Mail, and other secure providers are recommended</li>
                                    </ul>
                                    <p class="guide-note"><strong>Security note:</strong> This email will receive your account credentials and payment confirmations.</p>
                                </div>
                            </li>

                            <li class="guide-item">
                                <h3 class="guide-item-label">Wallet address</h3>
                                <div class="guide-item-body">
                                    <p><strong>Required field:</strong> Enter the cryptocurrency wallet address you used for payment. This is crucial for payment verification and potential refunds if your request is not approved.</p>
                                    <p><strong>Important instructions:</strong></p>
                                    <ul class="guide-tier-list">
                                        <li>Use the same wallet address that you used to send the cryptocurrency payment</li>
                                        <li>Copy the address exactly from your wallet (case-sensitive)</li>
                                        <li>Double-check for any typos as incorrect addresses cannot be recovered</li>
                                        <li>Ensure you're using the correct blockchain network (Bitcoin or Ethereum)</li>
                                    </ul>
                                    <p><strong>Format examples:</strong><br />
                                        <strong>Bitcoin:</strong> bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh<br />
                                        <strong>Ethereum:</strong> 0x742d35Cc6634C0532925a3b8D807A69b8d6B4f8</p>
                                </div>
                            </li>

                            <li class="guide-item">
                                <h3 class="guide-item-label">Comment or special requests</h3>
                                <div class="guide-item-body">
                                    <p><strong>Optional field:</strong> Use this section to provide any additional information or special requests. This field is not required but can be helpful for our team to understand your specific needs.</p>
                                    <p><strong>What you can include:</strong></p>
                                    <ul class="guide-tier-list">
                                        <li>Special access requirements or restrictions</li>
                                        <li>Preferred countries or regions of interest</li>
                                        <li>Technical requirements or API needs</li>
                                        <li>Questions about the service or features</li>
                                        <li>Any specific use cases you have in mind</li>
                                        <li>Additional contact information if needed</li>
                                    </ul>
                                    <p><strong>Character limit:</strong> Maximum 1000 characters. Be concise but descriptive.</p>
                                    <p class="guide-note"><strong>Note:</strong> Our support team will review any special requests and respond via email within 24 hours.</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>
            </div>

            <section id="service-features" class="guide-section guide-section--plans" aria-labelledby="service-features-heading">
                <p class="section-label">Section 02</p>
                <h2 id="service-features-heading" class="section-title">Subscription tiers — full breakdown</h2>
                <p class="guide-intro guide-intro--center">
                    Each plan lives in its own container below with every capability, limit, and support detail. Jump to a tier or scroll the row on desktop.
                </p>

                <nav class="plan-details-jump" aria-label="Subscription tiers">
                    <?php foreach (planCatalogKeys() as $planKey) :
                        $catalogDisplay = is_array($planCatalogDisplay) ? $planCatalogDisplay : [];
                        $planJump = isset($catalogDisplay[$planKey]) && is_array($catalogDisplay[$planKey]) ? $catalogDisplay[$planKey] : ['id' => '', 'name' => ''];
                        $planId = isset($planJump['id']) && is_string($planJump['id']) ? $planJump['id'] : '';
                        $planName = isset($planJump['name']) && is_string($planJump['name']) ? $planJump['name'] : '';
                        ?>
                    <a href="#<?php echo htmlspecialchars($planId, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($planName, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <?php endforeach; ?>
                </nav>

                <ul class="plan-details-grid">
                    <?php foreach (planCatalogKeys() as $planKey) { ?>
                        <li>
                            <?php renderPlanDetailCard($planKey); ?>
                        </li>
                    <?php } ?>
                </ul>

                <p class="guide-note guide-note--global">
                    <em>Tip:</em> Register on Free first; paid tiers (Basic, Pro, Premium) unlock from your dashboard when operations need more depth.
                </p>
            </section>

            <div class="guide-actions">
                <a href="signup" class="btn btn-primary">Back to registration</a>
                <a href="signup#plans-heading" class="btn btn-secondary">Compare plans</a>
                <a href="../login" class="btn btn-ghost">Sign in</a>
            </div>
        </main>
    </div>

    <footer class="site-footer">
        <p>&copy; <?php echo htmlspecialchars(is_string($year) ? $year : date('Y'), ENT_QUOTES, 'UTF-8'); ?> The Fund Registry. All rights reserved. Encrypted connection.</p>
    </footer>

    <script src="/js/contact-terminal.js?v=3" defer></script>
</body>

</html>