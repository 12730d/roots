<?php
declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Config\Database;
use ROOTS\Controllers\PageController;
use ROOTS\Auth\Session;
use ROOTS\Security\CsrfProtection;

/**
 * Dedicated exception for CSRF token validation failures.
 * (Sonar rule php:S112 - avoid throwing generic Exception)
 */
class InvalidCsrfTokenException extends RuntimeException
{
}

/**
 * Dedicated exception for API key generation/regeneration failures.
 * (Sonar rule php:S112 - avoid throwing generic Exception)
 */
class ApiKeyGenerationException extends RuntimeException
{
}

/**
 * h - Clean output for HTML context
 */
if (!function_exists("h")) {
    function h(mixed $s): string
    {
        return htmlspecialchars((string) ($s ?? ""), ENT_QUOTES, "UTF-8");
    }
}

// 1. Setup Page with security
// PageController handles session, auth (require_auth default true) and csrf initialization
$pageData = PageController::setup("ROOTS API", "./", ["css/api-custom.css"]);

$con = $pageData["db"];
$user = $pageData["user"];
$username = (string) ($user["username"] ?? "");

if ($username === "") {
    header("Location: login");
    exit;
}

// (Moved logic above to access user_data for validation)
// --- Fetch User Data ---
$user_stmt = $con->prepare(
    "SELECT api_key, subscription, api_key_updated_at FROM login WHERE username = ?",
);
$user_stmt->bind_param("s", $username);
$user_stmt->execute();

$user_data = $user_stmt->get_result()->fetch_assoc();

$user_api_key = $user_data["api_key"];
$user_subscription = $user_data["subscription"] ?? "free";
$api_key_updated_at = $user_data["api_key_updated_at"];

// --- Handle API Key Generation ---
$msg = "";
$error = "";

// CSRF Protection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["generate_key"])) {
    try {
        if (!CsrfProtection::verifyToken($_POST["csrf_token"] ?? "")) {
            throw new InvalidCsrfTokenException(
                "Security Error: Invalid CSRF Token.",
            );
        }
        // Enforce 30-day cooldown
        $can_generate = true;
        if ($api_key_updated_at) {
            $last_update = strtotime($api_key_updated_at);
            $next_available = strtotime("+30 days", $last_update);
            if (time() < $next_available) {
                $can_generate = false;
                $error =
                    "You can only generate a new key once every 30 days. Next available: " .
                    date("M j, Y", $next_available);
            }
        }
        if ($can_generate) {
            try {
                $new_api_key = bin2hex(random_bytes(16));
                $update_stmt = $con->prepare("UPDATE login SET
    api_key = ?, api_key_updated_at = NOW() WHERE username = ?");
                $update_stmt->bind_param("ss", $new_api_key, $username);

                if ($update_stmt->execute()) {
                    $msg = "New API Key generated successfully.";
                    // Refresh data for display
                    $user_api_key = $new_api_key;
                    $api_key_updated_at = date("Y-m-d H:i:s");
                } else {
                    throw new ApiKeyGenerationException(
                        "Failed to generate API Key.",
                    );
                }
            } catch (Throwable $e) {
                throw new ApiKeyGenerationException(
                    "Error: " . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Prepare Display Variables
$NO_API_KEY_MESSAGE = "No API Key";
$key_display = $user_api_key ? $user_api_key : $NO_API_KEY_MESSAGE;
$btn_text = $user_api_key ? "Regenerate Key" : "Generate Key";
$btn_disabled = "";
$blur_class = "";

// --- Fetch System Statistics ---
$stats = [
    "account_checks" => 0,
    "verified_accounts" => 0,
    "unknown_accounts" => 0,
    "breached_passwords" => 0,
];
try {
    // Check if api_logs table exists first
    $table_check = $con->prepare("SHOW TABLES LIKE 'api_logs'");
    $table_check->execute();
    $table_exists = $table_check->get_result()->num_rows > 0;
    $table_check->close();

    if ($table_exists) {
        // Total account checks (last 30 days)
        $stmt = $con->prepare(
            "SELECT COUNT(*) as total FROM api_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        );
        if ($stmt && $stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $stats["account_checks"] = (int) $row["total"];
            }
        }
        if ($stmt) {
            $stmt->close();
        }
    } else {
        // Table doesn't exist, set account_checks to 0
        $stats["account_checks"] = 0;
    }

    // Check if login.verified column exists to avoid errors on missing schema
    $verified_column_exists = false;
    $col_check = $con->prepare(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login' AND COLUMN_NAME = 'verified' LIMIT 1",
    );
    if ($col_check && $col_check->execute()) {
        $col_check->store_result();
        $verified_column_exists = $col_check->num_rows > 0;
    }
    if ($col_check) {
        $col_check->close();
    }

    if ($verified_column_exists) {
        // Verified accounts
        $stmt = $con->prepare(
            "SELECT COUNT(*) as total FROM login WHERE verified = 1",
        );
        if ($stmt && $stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $stats["verified_accounts"] = (int) $row["total"];
            }
        }
        if ($stmt) {
            $stmt->close();
        }

        // Unknown accounts
        $stmt = $con->prepare(
            "SELECT COUNT(*) as total FROM login WHERE verified = 0 OR verified IS NULL",
        );
        if ($stmt && $stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $stats["unknown_accounts"] = (int) $row["total"];
            }
        }
        if ($stmt) {
            $stmt->close();
        }
    } else {
        // Schema lacks verified column; leave defaults to avoid runtime errors
        $stats["verified_accounts"] = 0;
        $stats["unknown_accounts"] = 0;
    }

    // Check if breached_passwords table exists before querying
    $breached_table_check = $con->prepare(
        "SHOW TABLES LIKE 'breached_passwords'",
    );
    $breached_table_exists = false;
    if ($breached_table_check && $breached_table_check->execute()) {
        $breached_table_exists =
            $breached_table_check->get_result()->num_rows > 0;
    }
    if ($breached_table_check) {
        $breached_table_check->close();
    }

    if ($breached_table_exists) {
        // Breached passwords (last 30 days)
        $stmt = $con->prepare(
            "SELECT COUNT(*) as total FROM breached_passwords WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        );
        if ($stmt && $stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $stats["breached_passwords"] = (int) $row["total"];
            }
        }
        if ($stmt) {
            $stmt->close();
        }
    } else {
        // Table doesn't exist, set breached_passwords to 0
        $stats["breached_passwords"] = 0;
    }
} catch (Exception $e) {
    error_log("[API_STATS] Failed to fetch statistics: " . $e->getMessage());
    // Set default values to prevent page break
    $stats = [
        "account_checks" => 0,
        "verified_accounts" => 0,
        "unknown_accounts" => 0,
        "breached_passwords" => 0,
    ];
}

$account_checks = $stats["account_checks"];
$verified_accounts = $stats["verified_accounts"];
$unknown_accounts = $stats["unknown_accounts"];
$breached_passwords = $stats["breached_passwords"];

// --- Fetch Initial Countries for Stream (Avoid initial lag) ---
$initial_countries = [];
try {
    $c_stmt = $con->prepare("SELECT rank_position, country_name, population_2025 as population, land_area_km2, density_per_km2
                            FROM countries ORDER BY RAND() LIMIT 20");
    $c_stmt->execute();
    $c_res = $c_stmt->get_result();
    while ($row = $c_res->fetch_assoc()) {
        $row["population_formatted"] = number_format((int) $row["population"]);
        $row["land_area_formatted"] =
            number_format((int) $row["land_area_km2"] / 1000, 1) . "k";
        $initial_countries[] = $row;
    }
    $c_stmt->close();
} catch (Exception $e) {
    // Fallback to empty
}

/**
 * Helper to render initial country rows on server-side
 * @param array<string, mixed> $country
 */
function renderCountryRow(array $country): string
{
    $density = (int) $country["density_per_km2"];
    $densityClass = "density-low";
    $densityLevel = "LOW";
    if ($density > 500) {
        $densityClass = "density-high";
        $densityLevel = "HIGH";
    } elseif ($density > 100) {
        $densityClass = "density-medium";
        $densityLevel = "MED";
    }

    $name = (string) $country["country_name"];
    $displayName = strlen($name) > 15 ? substr($name, 0, 15) . "..." : $name;

    return '
        <div class="stream-row">
            <div class="col-icon">
                <span style="color: var(--term-green); font-size: 0.9rem; font-weight: bold;">#' .
        h((string) $country["rank_position"]) .
        '</span>
            </div>
            <div class="col-user" title="' .
        h($name) .
        '">' .
        h($displayName) .
        '</div>
            <div class="col-type">
                <span style="color: var(--term-green); font-size: 0.8rem; font-weight: bold;">' .
        h((string) $country["population_formatted"]) .
        '</span>
            </div>
            <div class="col-desc" title="' .
        h((string) $country["land_area_formatted"]) .
        ' km²">
                <span style="color: #aaa; font-size: 0.8rem;">' .
        h((string) $country["land_area_formatted"]) .
        ' km²</span>
            </div>
            <div class="col-amount ' .
        $densityClass .
        '">' .
        number_format($density) .
        '/km²</div>
            <div class="col-time">
                <span class="badge ' .
        $densityClass .
        '" style="font-size: 0.6rem; padding: 2px 4px;">' .
        $densityLevel .
        '</span>
            </div>
        </div>';
}
?>

<!-- API Page Content -->
<div class="container">
    <header class="mb-5">
        <div class="d-flex align-items-center justify-content-center mb-3">
            <div class="api-pulse me-3"></div>
            <h1 class="display-5 fw-bold mb-0">Global Intelligence API</h1>
        </div>
        <p class="subtitle lead">Seamlessly integrate real-time demographic and security data into your applications
            with our high-performance, secure API infrastructure.</p>
    </header>

    <?php if ($msg): ?>
        <div class="alert alert-success text-center"><?php echo h(
            $msg,
        ); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger text-center"><?php echo h(
            $error,
        ); ?></div>
    <?php endif; ?>

    <!-- Task Manager Section -->
    <section class="task-manager">

        <h2 style="text-align: center; margin-bottom: 30px; color: #33ff00; font-size: 1.8rem;">System
            Statistics</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="stat-content">
                    <h3>Account Checks</h3>
                    <div class="stat-number" id="account-checks">
                        <?php echo h(number_format($account_checks)); ?>
                    </div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon verified">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3>Verified Accounts</h3>
                    <div class="stat-number" id="verified-accounts">
                        <?php echo h(number_format($verified_accounts)); ?>
                    </div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon unknown">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div class="stat-content">
                    <h3>Unknown Accounts</h3>
                    <div class="stat-number" id="unknown-accounts">
                        <?php echo h(number_format($unknown_accounts)); ?>
                    </div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon breached">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <h3>Breached Passwords</h3>
                    <div class="stat-number" id="breached-passwords">
                        <?php echo h(number_format($breached_passwords)); ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Countries Population Stream Section -->
    <section class="countries-stream-section">
        <div class="transaction-history-card rounded p-2">
            <div class="corner-accent tr"></div>
            <div class="corner-accent bl"></div>

            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="mb-0" style="font-size: 0.7rem;">
                    <i class="fas fa-globe-americas me-2"></i>COUNTRIES_POPULATION_STREAM
                </h6>
                <span class="badge"
                    style="background: var(--term-green); color: #000; font-family: monospace; font-size: 0.55rem; padding: 2px 5px;"
                    id="countriesStreamStatus">LIVE</span>
            </div>

            <div id="countriesStreamContainer">
                <?php if (!empty($initial_countries)): ?>
                    <?php // Render first 10 countries immediately
                        for (
                            $i = 0;
                            $i < min(10, count($initial_countries));
                            $i++
                        ) {
                            echo renderCountryRow($initial_countries[$i]);
                        } ?>
                <?php else: ?>
                    <!-- Dynamic Content Placeholder -->
                    <div id="countries-placeholder" class="text-center py-2">
                        <output class="spinner-border text-success"
                            style="display: block; width: 1rem; height: 1rem; border-width: 2px;">
                            <span class="visually-hidden">Loading...</span>
                        </output>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stream-mask"></div>
        </div>
    </section>

    <section class="key-panel mb-5">
        <div class="d-flex align-items-center mb-4">
            <i class="fas fa-shield-alt fa-2x me-3" style="color: var(--primary-color);"></i>
            <h3 class="mb-0">API Credentials</h3>
        </div>
        <?php if ($user_subscription === "free"): ?>
            <div class="alert alert-info border-0"
                style="background: rgba(0, 212, 255, 0.1); border-left: 3px solid #00d4ff;">
                <i class="fas fa-info-circle me-2" style="color: #00d4ff;"></i>
                <strong>API access requires a paid subscription.</strong> Upgrade your account to generate an API key and
                access our powerful verification system.
                <a href="buy_points" class="ms-3" style="color: var(--term-green); text-decoration: underline;">Upgrade Now
                    →</a>
            </div>
        <?php else: ?>
            <p class="text-muted mb-4 text-start">Use this secret key to authenticate your requests. Never share it in
                client-side code or public repositories.</p>
        <?php endif; ?>

        <form method="POST" id="apiKeyForm" action="api" class="row g-3 align-items-center justify-content-center">
            <?php echo CsrfProtection::tokenField(); ?>
            <div class="col-md-8">
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-success">
                        <i class="fas fa-key"></i>
                    </span>
                    <input type="password" id="api-key-input"
                        class="form-control api-key-display <?php echo h($blur_class); ?>"
                        value="<?php echo h($key_display); ?>" readonly>
                    <button class="btn btn-outline-secondary" type="button" id="toggleKey">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                    <button class="btn btn-outline-success" type="button" id="copyKey" title="Copy to clipboard">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>

            <div class="col-md-4">
                <?php if ($user_subscription === "free"): ?>
                    <button type="button" class="btn btn-secondary w-100 py-2" disabled
                        title="API key generation requires a paid subscription">
                        <i class="fas fa-lock me-2"></i><?php echo h($btn_text); ?>
                    </button>
                    <small class="d-block text-muted mt-2 text-center">
                        <i class="fas fa-lock-open me-1"></i>Requires paid subscription
                    </small>
                <?php else: ?>
                    <button type="submit" name="generate_key" class="btn btn-action w-100 py-2" <?php echo h($btn_disabled); ?>>
                        <i class="fas fa-sync-alt me-2"></i><?php echo h($btn_text); ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($user_api_key): ?>
            <div class="alert alert-warning border-0 bg-transparent mt-3 p-0" style="font-size: 0.8rem;">
                <i class="fas fa-exclamation-triangle me-1"></i> Regenerating your key will immediately invalidate the
                current one.
            </div>
        <?php endif; ?>
    </section>

    <section>
        <h2 class="docs-title">Integration Examples</h2>
        <p style="margin-bottom: 25px; color: #1a8000;">
            Endpoint: <code
                style="background: #050505; padding: 4px 8px; border-radius: 4px; color: #33ff00; border: 1px solid #33ff00;">https://yoursite.com/api/v1/check-user</code>
        </p>

        <div class="grid-container">

            <div class="code-card">
                <div class="card-header">
                    <span class="lang-name">JavaScript / Node.js</span>
                    <span style="font-size: 1.2rem; color: #f7df1e;">JS</span>
                </div>
                <div class="card-body">
                    <pre><code><span class="token-keyword">const</span> apiKey = <span class="token-string"><?php echo h(
                        json_encode($user_api_key ? $user_api_key : "YOUR_KEY"),
                    ); ?></span>;

<span class="token-func">fetch</span>(<span class="token-string">'https://yoursite.com/api/v1/check-user'</span>, {
  method: <span class="token-string">'POST'</span>,
  headers: {
    <span class="token-string">'Content-Type'</span>: <span class="token-string">'application/json'</span>,
    <span class="token-string">'Authorization'</span>: <span class="token-string">`Bearer ${apiKey}`</span>
  },
  body: JSON.stringify({
    email: <span class="token-string">'user@example.com'</span>,
    ip: <span class="token-string">'192.168.1.1'</span>
  })
})
.then(res => res.json())
.then(data => {
    // Process data
});</code></pre>
                </div>
            </div>

            <div class="code-card">
                <div class="card-header">
                    <span class="lang-name">Python</span>
                    <span style="font-size: 1.2rem; color: #3776ab;">Py</span>
                </div>


                <div class="code-card">
                    <div class="card-header">
                        <span class="lang-name">PHP</span>
                        <span style="font-size: 1.2rem; color: #777bb4;">PHP</span>
                    </div>
                    <div class="card-body">
                        <pre><code><span class="token-var">$apiKey</span> = <span class="token-string">"<?php echo h(
                            $user_api_key ? $user_api_key : "YOUR_KEY",
                        ); ?>"</span>;
<span class="token-var">$url</span> = <span class="token-string">"https://yoursite.com/api/v1/check-user"</span>;

<span class="token-var">$ch</span> = <span class="token-func">curl_init</span>(<span class="token-var">$url</span>);
<span class="token-func">curl_setopt</span>(<span class="token-var">$ch</span>, CURLOPT_POST, 1);
<span class="token-func">curl_setopt</span>(<span class="token-var">$ch</span>, CURLOPT_POSTFIELDS, [
    <span class="token-string">'email'</span> => <span class="token-string">'user@example.com'</span>
]);
<span class="token-func">curl_setopt</span>(<span class="token-var">$ch</span>, CURLOPT_HTTPHEADER, [
    <span class="token-string">"Authorization: Bearer "</span> . <span class="token-var">$apiKey</span>
]);
<span class="token-func">curl_setopt</span>(<span class="token-var">$ch</span>, CURLOPT_RETURNTRANSFER, true);

<span class="token-var">$response</span> = <span class="token-func">curl_exec</span>(<span class="token-var">$ch</span>);
<span class="token-func">curl_close</span>(<span class="token-var">$ch</span>);</code></pre>
                    </div>
                </div>

            </div>
    </section>

    <!-- API Access Section -->
    <section class="api-access-section">
        <h2 class="section-title">API Access Based on Your Subscription</h2>

        <?php
        $api_features = [
            "free" => [
                "name" => "Free Plan",
                "api_access" => "No API Access",
                "api_percentage" => "0%",
                "features" => [
                    "API access not available",
                    "Limited to web interface only",
                    "No programmatic access",
                    "Trial purposes only",
                ],
                "color" => "#666",
                "icon" => "fa-lock",
            ],
            "basic" => [
                "name" => "Basic Plan",
                "api_access" => "Limited API Access",
                "api_percentage" => "60%",
                "features" => [
                    "60% of full API capabilities",
                    "Standard endpoints access",
                    "Basic authentication",
                    "Limited requests per day",
                ],
                "color" => "#4e9bf5",
                "icon" => "fa-key",
            ],
            "pro" => [
                "name" => "Pro Plan",
                "api_access" => "Advanced API Access",
                "api_percentage" => "80%",
                "features" => [
                    "80% of advanced capabilities",
                    "All standard endpoints",
                    "Advanced authentication",
                    "Higher request limits",
                    "Webhook support",
                ],
                "color" => "#36d399",
                "icon" => "fa-rocket",
            ],
            "premium" => [
                "name" => "Premium Plan",
                "api_access" => "Full API Access",
                "api_percentage" => "94%",
                "features" => [
                    "94% of full capabilities with exclusive features",
                    "All endpoints including beta features",
                    "Enterprise authentication",
                    "Unlimited requests",
                    "Priority support",
                    "Custom integrations",
                ],
                "color" => "#fbd38d",
                "icon" => "fa-crown",
            ],
            "admin" => [
                "name" => "Admin Access",
                "api_access" => "Complete API Access",
                "api_percentage" => "100%",
                "features" => [
                    "100% unrestricted API access",
                    "All endpoints including admin-only",
                    "Full system control",
                    "Unlimited everything",
                    "Custom endpoint creation",
                    "White-label options",
                ],
                "color" => "#ff4444",
                "icon" => "fa-shield-alt",
            ],
        ];

        $current_plan =
            $api_features[$user_subscription] ?? $api_features["free"];
        ?>

        <div class="api-access-card" style="border-color: <?php echo h(
            $current_plan["color"],
        ); ?>;">
            <div class="plan-header" style="background: linear-gradient(135deg, <?php echo h(
                $current_plan["color"],
            ); ?>20, <?php echo h(
                 $current_plan["color"],
             ); ?>10); border-bottom-color: <?php echo h($current_plan["color"]); ?>;">
                <div class="plan-icon" style="color: <?php echo h(
                    $current_plan["color"],
                ); ?>;">
                    <i class="fas <?php echo h($current_plan["icon"]); ?>"></i>
                </div>
                <div class="plan-info">
                    <h3><?php echo h($current_plan["name"]); ?></h3>
                    <p class="api-level"><?php echo h(
                        $current_plan["api_access"],
                    ); ?></p>
                    <div class="api-percentage">
                        <span class="percentage-number"><?php echo h(
                            $current_plan["api_percentage"],
                        ); ?></span>
                        <span class="percentage-label">API Coverage</span>
                    </div>
                </div>
            </div>

            <div class="plan-features">
                <h4>Available Features:</h4>
                <ul class="features-list">
                    <?php foreach ($current_plan["features"] as $feature): ?>
                        <li>
                            <i class="fas fa-check-circle" style="color: <?php echo h(
                                $current_plan["color"],
                            ); ?>;"></i>
                            <?php echo h($feature); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($user_subscription === "free"): ?>
                <div class="upgrade-prompt">
                    <h4>Upgrade Your Plan for API Access</h4>
                    <p>Get programmatic access to our powerful verification and fraud detection system.</p>
                    <a href="buy_points" class="upgrade-btn">
                        <i class="fas fa-arrow-up"></i> Upgrade Now
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($user_subscription !== "free"): ?>
                <div class="usage-info">
                    <h4>API Usage Information</h4>
                    <div class="usage-stats">
                        <div class="usage-item">
                            <span class="usage-label">Daily Requests:</span>
                            <span class="usage-value">
                                <?php echo h(
                                    match ($user_subscription) {
                                        "basic" => "1,000",
                                        "pro" => "10,000",
                                        "premium" => "100,000",
                                        "admin" => "Unlimited",
                                        default => "0",
                                    },
                                ); ?>
                            </span>
                        </div>
                        <div class="usage-item">
                            <span class="usage-label">Rate Limit:</span>
                            <span class="usage-value">
                                <?php echo h(
                                    match ($user_subscription) {
                                        "basic" => "10 req/min",
                                        "pro" => "60 req/min",
                                        "premium" => "300 req/min",
                                        "admin" => "No limit",
                                        default => "N/A",
                                    },
                                ); ?>
                            </span>
                        </div>
                        <div class="usage-item">
                            <span class="usage-label">Support:</span>
                            <span class="usage-value">
                                <?php echo h(
                                    match ($user_subscription) {
                                        "basic" => "Email (48h)",
                                        "pro" => "Email (24h)",
                                        "premium" => "Priority (12h)",
                                        "admin" => "Instant",
                                        default => "N/A",
                                    },
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="api-endpoints-info">
            <h4>Available API Endpoints</h4>
            <div class="endpoints-grid">
                <div class="endpoint-card">
                    <div class="endpoint-method">POST</div>
                    <div class="endpoint-path">/api/v1/check-user</div>
                    <div class="endpoint-desc">User verification and fraud detection</div>
                </div>
                <div class="endpoint-card">
                    <div class="endpoint-method">GET</div>
                    <div class="endpoint-path">/api/v1/user-info</div>
                    <div class="endpoint-desc">Retrieve user information</div>
                </div>
                <div class="endpoint-card">
                    <div class="endpoint-method">POST</div>
                    <div class="endpoint-path">/api/v1/breached-check</div>
                    <div class="endpoint-desc">Check for breached passwords</div>
                </div>
                <?php if (
                    in_array($user_subscription, ["pro", "premium", "admin"])
                ): ?>
                    <div class="endpoint-card">
                        <div class="endpoint-method">POST</div>
                        <div class="endpoint-path">/api/v1/advanced-scan</div>
                        <div class="endpoint-desc">Advanced security scanning</div>
                    </div>
                <?php endif; ?>
                <?php if (
                    in_array($user_subscription, ["premium", "admin"])
                ): ?>
                    <div class="endpoint-card">
                        <div class="endpoint-method">POST</div>
                        <div class="endpoint-path">/api/v1/bulk-verify</div>
                        <div class="endpoint-desc">Bulk user verification</div>
                    </div>
                <?php endif; ?>
                <?php if ($user_subscription === "admin"): ?>
                    <div class="endpoint-card">
                        <div class="endpoint-method">ALL</div>
                        <div class="endpoint-path">/api/v1/admin/*</div>
                        <div class="endpoint-desc">Admin system control</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="text-center">
        <a href="index" class="back-link">&larr; Return to Dashboard</a>
    </div>
</div>

<script>
    // Animate statistics on page load
    document.addEventListener('DOMContentLoaded', function () {
        animateStats();

        // Refresh statistics every 30 seconds
        setInterval(updateStats, 30000);
    });

    function animateStats() {
        const statNumbers = document.querySelectorAll('.stat-number');

        statNumbers.forEach(stat => {
            const finalValue = parseInt(stat.textContent.replace(/,/g, ''));
            let currentValue = 0;
            const increment = finalValue / 50; // Animation duration: 50 steps
            const timer = setInterval(() => {
                currentValue += increment;
                if (currentValue >= finalValue) {
                    currentValue = finalValue;
                    clearInterval(timer);
                }
                stat.textContent = Math.floor(currentValue).toLocaleString();
            }, 30);
        });
    }

    async function updateStats() {
        try {
            const response = await fetch('api_stats');
            const data = await response.json();

            if (data.success) {
                const elements = {
                    'account-checks': data.account_checks,
                    'verified-accounts': data.verified_accounts,
                    'unknown-accounts': data.unknown_accounts,
                    'breached-passwords': data.breached_passwords
                };

                for (const [id, value] of Object.entries(elements)) {
                    const el = document.getElementById(id);
                    if (el) el.textContent = value.toLocaleString();
                }

                animateStats();
            }
        } catch (error) {
            // Failed to update statistics
        }
    }

    function copyToClipboard(text, event) {
        navigator.clipboard.writeText(text).then(() => {
            const btn = event ? event.currentTarget : null;
            if (btn) {
                const icon = btn.querySelector('i');
                const originalClass = icon.className;
                icon.className = 'fas fa-check';
                setTimeout(() => {
                    icon.className = originalClass;
                }, 2000);
            }
        });
    }

    // --- Countries Population Stream Logic ---
    let allCountries = <?php echo json_encode($initial_countries); ?>;
    let currentCountryIndex = 10;
    const maxRows = 10;

    function getDensityLevel(density) {
        if (density > 500) return 'HIGH';
        if (density > 100) return 'MED';
        return 'LOW';
    }

    function renderCountryHTML(country) {
        if (!country) return '';
        let densityClass = 'density-low';
        if (country.density_per_km2 > 500) densityClass = 'density-high';
        else if (country.density_per_km2 > 100) densityClass = 'density-medium';

        return `
            <div class="col-icon">
                <span style="color: var(--term-green); font-size: 0.9rem; font-weight: bold;">#${country.rank_position}</span>
            </div>
            <div class="col-user" title="${country.country_name}">
                ${country.country_name.length > 15 ? country.country_name.substring(0, 15) + '...' : country.country_name}
            </div>
            <div class="col-type">
                <span style="color: var(--term-green); font-size: 0.8rem; font-weight: bold;">
                    ${country.population_formatted}
                </span>
            </div>
            <div class="col-desc" title="${country.land_area_formatted} km²">
                <span style="color: #1a8000; font-size: 0.8rem;">${country.land_area_formatted} km²</span>
            </div>
            <div class="col-amount ${densityClass}">
                ${country.density_per_km2.toLocaleString()}/km²
            </div>
            <div class="col-time">
                <span class="badge ${densityClass}" style="font-size: 0.6rem; padding: 2px 4px;">
                    ${getDensityLevel(country.density_per_km2)}
                </span>
            </div>
        `;
    }

    function addNewCountry() {
        const container = document.getElementById('countriesStreamContainer');
        if (!container || !allCountries || allCountries.length === 0) return;
        const placeholder = document.getElementById('countries-placeholder');
        if (placeholder) placeholder.remove();

        const newRow = document.createElement('div');
        newRow.className = 'stream-row';
        newRow.style.opacity = '0';
        newRow.style.transform = 'translateY(-20px)';

        const country = allCountries[currentCountryIndex];
        if (!country) return;
        newRow.innerHTML = renderCountryHTML(country);
        container.insertBefore(newRow, container.firstChild);

        setTimeout(() => {
            newRow.style.transition = 'all 0.5s ease';
            newRow.style.opacity = '1';
            newRow.style.transform = 'translateY(0)';
        }, 50);

        currentCountryIndex = (currentCountryIndex + 1) % allCountries.length;
        const rows = container.querySelectorAll('.stream-row');
        if (rows.length > maxRows) {
            const oldRow = rows[rows.length - 1];
            oldRow.style.transition = 'all 0.3s ease';
            oldRow.style.opacity = '0';
            oldRow.style.transform = 'translateY(20px)';
            setTimeout(() => oldRow.remove(), 300);
        }
    }

    async function loadCountries() {
        const elStatus = document.getElementById('countriesStreamStatus');
        try {
            const response = await fetch('api_countries?limit=25', {
                credentials: 'same-origin'
            });

            // Read as text first to detect HTML error pages and avoid JSON parse errors
            const text = await response.text();

            // If response isn't ok, log and display error
            if (!response.ok) {
                console.error('Countries endpoint returned HTTP', response.status, text);
                if (elStatus) {
                    elStatus.textContent = 'ERROR';
                    elStatus.style.background = 'var(--term-danger)';
                }
                return;
            }

            let data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON from countries endpoint:', text);
                if (elStatus) {
                    elStatus.textContent = 'ERROR';
                    elStatus.style.background = 'var(--term-danger)';
                }
                return;
            }

            if (data && data.success) {
                allCountries = data.data;
                if (elStatus) {
                    elStatus.textContent = 'LIVE';
                    elStatus.style.background = 'var(--term-green)';
                }
            } else {
                console.error('Countries endpoint returned error payload:', data);
                if (elStatus) {
                    elStatus.textContent = 'ERROR';
                    elStatus.style.background = 'var(--term-danger)';
                }
            }
        } catch (error) {
            console.error('Error loading countries:', error);
            if (elStatus) {
                elStatus.textContent = 'ERROR';
                elStatus.style.background = 'var(--term-danger)';
            }
        }
    }

    function startCountriesStream() {
        function scheduleNext() {
            const interval = Math.floor(Math.random() * (7000 - 5000 + 1)) + 5000;
            setTimeout(() => {
                addNewCountry();
                scheduleNext();
            }, interval);
        }
        setTimeout(scheduleNext, 5000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        animateStats();
        setInterval(updateStats, 30000);

        // API Key interactions
        const toggleBtn = document.getElementById('toggleKey');
        const keyInput = document.getElementById('api-key-input');
        const toggleIcon = document.getElementById('toggleIcon');
        const copyBtn = document.getElementById('copyKey');

        if (toggleBtn && keyInput) {
            toggleBtn.addEventListener('click', function () {
                const isPassword = keyInput.type === 'password';
                keyInput.type = isPassword ? 'text' : 'password';
                toggleIcon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
            });
        }

        if (copyBtn && keyInput) {
            copyBtn.addEventListener('click', function (e) {
                copyToClipboard(keyInput.value, e);
            });
        }

        const apiKeyForm = document.getElementById('apiKeyForm');
        if (apiKeyForm) {
            apiKeyForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                e.stopPropagation(); // Prevent any other handlers

                const submitBtn = apiKeyForm.querySelector('button[name="generate_key"]');
                if (!submitBtn) {
                    console.error('Submit button not found');
                    return;
                }

                const originalText = submitBtn.innerHTML;
                const originalDisabled = submitBtn.disabled;

                // Disable button to prevent double-click
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
                submitBtn.disabled = true;

                try {
                    // Submit form via fetch
                    const formData = new FormData(apiKeyForm);
                    const response = await fetch(apiKeyForm.action || 'api', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });

                    // Check response status
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: Key generation failed`);
                    }

                    // Small delay before reload to ensure server processed the request
                    await new Promise(resolve => setTimeout(resolve, 800));

                    // Reload page to show updated key
                    window.location.reload();
                } catch (error) {
                    // Reset button on error
                    console.error('Key generation error:', error);
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = originalDisabled;

                    // Show error notification
                    if (typeof window.showNotification === 'function') {
                        window.showNotification('❌ Failed to generate key: ' + error.message, 'danger',
                            5000);
                    } else {
                        alert('Failed to generate key: ' + error.message);
                    }
                }
            });
        }

        if (allCountries && allCountries.length > 0) startCountriesStream();
        loadCountries();
    });
</script>

<?php PageController::end("./");
?>