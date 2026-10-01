<?php
// ============================================
// HONEYPOT: فخ للمهاجمين - بيانات وهمية كاملة
// ============================================

// سجل المهاجم في ملف خاص
$log_file = '/var/log/nginx/Honeypot.log';
$attack_time = date('Y-m-d H:i:s');
$attacker_ip = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$request_uri = $_SERVER['REQUEST_URI'];

// معلومات إضافية
$attack_data = "[$attack_time] IP: $attacker_ip | Agent: $user_agent | URI: $request_uri\n";
@file_put_contents($log_file, $attack_data, FILE_APPEND);

// إبطاء المهاجم (اجعل الصفحة تتحمل ببطء ليشغل وقته)
sleep(random_int(3, 8));


header("HTTP/1.1 200 OK");
header("Server: Apache/2.4.62 (Unix) OpenSSL/3.0.15");
header("X-Powered-By: PHP/8.3.15");

$fake_domains = [
    '2sr6wopzfn7vzuyj7n4ixraquuquwbdf5a4j537bzgrjiikdtua65lad.onion',
    'trzjmgy54a2kry3dsqvpiyutr34xgffnhrgd7okmpky2qaoqbyfovfyd.onion',
    '63ayf6w7bjy4r5eofhntrlksuztr2wlrapyce7wuk32zpv5l7hdy64id.onion',
    'se76pjkoagwczvjtz26ivdtwfiliov4ncjm7f5ab5ai4k5jsmqnumjid.onion'
];

// IPs for demo display only — not used for connections
$fakeIpPool = [];
for ($i = 1; $i <= 4; $i++) { $fakeIpPool[] = "185.220.101.{$i}"; }
for ($i = 56; $i <= 59; $i++) { $fakeIpPool[] = "104.244.74.{$i}"; }
// IPv6 demo addresses
$fakeIpPool[] = '2a0c:4a80:4200:1::1'; // NOSONAR
$fakeIpPool[] = '2a0c:4a80:4200:1::2'; // NOSONAR
$fakeIpPool[] = '2a0c:4a80:4200:1::3'; // NOSONAR
$fake_ips = $fakeIpPool;

$fake_requests = [
    '/index.php', '/about.html', '/contact.php', '/login.php', '/register.php',
    '/images/logo.png', '/css/style.css', '/js/main.js', '/favicon.ico',
    '/forum/index.php', '/blog/post-1', '/blog/post-2', '/search?q=privacy',
    '/admin/', '/dashboard/', '/profile.php', '/settings.php', '/api/v1/users'
];

$fake_status_codes = ['W', 'R', 'S', 'K', 'G', '_', 'C'];

$current_domain = $fake_domains[random_int(0, count($fake_domains) - 1)];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Apache Status (Server: <?php echo $current_domain; ?>)</title>
    <meta http-equiv="refresh" content="5">
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Courier New', monospace;
        background: #0a0a0a;
        color: #00ff00;
        padding: 20px;
        line-height: 1.6;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
    }

    h1,
    h2,
    h3 {
        color: #00cc00;
        margin: 20px 0 10px;
        border-bottom: 1px solid #00cc00;
        padding-bottom: 5px;
    }

    .header {
        background: #1a1a1a;
        padding: 15px;
        border: 1px solid #00ff00;
        border-radius: 5px;
        margin-bottom: 20px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin: 15px 0;
    }

    .info-item {
        background: #1a1a1a;
        padding: 8px 12px;
        border-left: 3px solid #00ff00;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        background: #1a1a1a;
    }

    th,
    td {
        border: 1px solid #00cc00;
        padding: 8px;
        text-align: left;
    }

    th {
        background: #003300;
        color: #00ff00;
    }

    tr:hover {
        background: #2a2a2a;
    }

    .blink {
        animation: blink 1s infinite;
    }

    @keyframes blink {
        0% {
            opacity: 1;
        }

        50% {
            opacity: 0;
        }

        100% {
            opacity: 1;
        }
    }

    .warning {
        color: #ff6600;
        background: #330000;
        padding: 10px;
        border: 1px solid #ff6600;
        border-radius: 5px;
    }

    .footer {
        margin-top: 30px;
        text-align: center;
        color: #006600;
        font-size: 12px;
    }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Apache Server Status <span class="blink">(LIVE)</span></h1>
            <p>Server: <?php echo $current_domain; ?> (Hidden Service)</p>
        </div>

        <div class="info-grid">
            <div class="info-item">📅 Current Time: <?php echo date('l, d-M-Y H:i:s T'); ?></div>
            <div class="info-item">🔄 Restart Time: <?php echo date('l, d-M-Y H:i:s T', strtotime('-3 days')); ?></div>
            <div class="info-item">⏰ Server Uptime:
                <?php echo random_int(3, 30) . ' days ' . random_int(1, 23) . ' hours ' . random_int(1, 59) . ' minutes'; ?>
            </div>
            <div class="info-item">📊 Total Accesses: <?php echo random_int(15000, 50000); ?></div>
            <div class="info-item">💾 Total Traffic: <?php echo random_int(2, 50); ?> GB</div>
            <div class="info-item">⚡ CPU Load: <?php echo random_int(1, 30) / 10; ?>%</div>
            <div class="info-item">📈 Requests/sec: <?php echo random_int(5, 50) / 10; ?></div>
            <div class="info-item">🌐 Active Connections: <?php echo random_int(8, 25); ?></div>
        </div>

        <div class="warning">
            ⚠️ WARNING: Suspicious activity detected from multiple IPs
        </div>

        <h2>🔥 Active Connections (Real-time)</h2>
        <table>
            <tr>
                <th>PID</th>
                <th>Client IP</th>
                <th>Protocol</th>
                <th>VHost</th>
                <th>Request</th>
                <th>Status</th>
                <th>Duration</th>
                <th>CPU</th>
            </tr>
            <?php

            for ($i = 0; $i < random_int(12, 20); $i++) {
                echo "<tr>";
                echo "<td>" . random_int(1000, 9999) . "</td>";
                echo "<td>" . $fake_ips[random_int(0, count($fake_ips) - 1)] . "</td>";
                echo "<td>HTTP/1.1</td>";
                echo "<td>" . $fake_domains[random_int(0, count($fake_domains) - 1)] . "</td>";
                echo "<td>" . $fake_requests[random_int(0, count($fake_requests) - 1)] . "</td>";
                echo "<td><span style='color: #00ff00; font-weight: bold;'>" . $fake_status_codes[random_int(0, count($fake_status_codes) - 1)] . "</span></td>";
                echo "<td>" . (random_int(1, 300) / 10) . " sec</td>";
                echo "<td>" . random_int(1, 15) . "%</td>";
                echo "</tr>\n";
            }
            ?>
        </table>

        <h2>📋 Last 20 Requests</h2>
        <table>
            <tr>
                <th>Time</th>
                <th>Client IP</th>
                <th>Method</th>
                <th>Request</th>
                <th>Status</th>
                <th>Size</th>
                <th>User-Agent</th>
            </tr>
            <?php

            $methods = ['GET', 'POST', 'HEAD', 'PUT', 'DELETE'];
            $statuses = [200, 200, 200, 200, 200, 200, 301, 302, 404, 403, 500];

            for ($i = 0; $i < 20; $i++) {
                $time = date('H:i:s', strtotime('-' . random_int(0, 300) . ' seconds') ?: time());
                $method = $methods[random_int(0, count($methods) - 1)];
                $status = $statuses[random_int(0, count($statuses) - 1)];
                $size = random_int(500, 50000);
                $agent = $fake_ips[random_int(0, count($fake_ips) - 1)];

                echo "<tr>";
                echo "<td>{$time}</td>";
                echo "<td>{$fake_ips[random_int(0, count($fake_ips) - 1)]}</td>";
                echo "<td>{$method}</td>";
                echo "<td>" . $fake_requests[random_int(0, count($fake_requests) - 1)] . "</td>";
                echo "<td>" . ($status == 200 ? "<span style='color:#00ff00;'>{$status}</span>" : "<span style='color:#ff6600;'>{$status}</span>") . "</td>";
                echo "<td>" . number_format($size) . " B</td>";
                echo "<td>Mozilla/5.0 (Windows NT 10.0; rv:128.0) Firefox/128.0</td>";
                echo "</tr>\n";
            }
            ?>
        </table>

        <h2>📊 Top Clients by Traffic</h2>
        <table>
            <tr>
                <th>Rank</th>
                <th>Client IP</th>
                <th>Requests</th>
                <th>Traffic (MB)</th>
                <th>Bandwidth (KB/s)</th>
            </tr>
            <?php
            for ($i = 1; $i <= 10; $i++) {
                $requests = random_int(500, 5000);
                $traffic = random_int(50, 800);
                $bandwidth = random_int(10, 200);
                echo "<tr>";
                echo "<td>#{$i}</td>";
                echo "<td>{$fake_ips[random_int(0, count($fake_ips) - 1)]}</td>";
                echo "<td>{$requests}</td>";
                echo "<td>{$traffic} MB</td>";
                echo "<td>{$bandwidth} KB/s</td>";
                echo "</tr>\n";
            }
            ?>
        </table>

        <div class="footer">
            Apache/2.4.62 (Unix) OpenSSL/3.0.15 - Hidden Service Mode<br>
            Server uptime: 3 days 2 hours - Load average: 0.52, 0.43, 0.38
        </div>
    </div>
</body>

</html>