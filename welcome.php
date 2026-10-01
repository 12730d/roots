<?php
// welcome.php - ROOTS Global Gateway (Optimized v4.1)
// Changes: redesigned server display, removed ping system entirely
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="preload" href="favicon.ico" as="image">
    <link rel="icon" type="image/x-icon" href="favicon.ico">

    <!-- SEO Meta Tags -->
    <title>ROOTS | Global Gateway - Secure Access Portal</title>
    <meta name="description"
        content="ROOTS Global Gateway - Your secure access portal to comprehensive database services, network infrastructure, and advanced technical solutions. Connect to global networks securely.">
    <meta name="keywords"
        content="ROOTS, global gateway, secure access, network infrastructure, database services, technical solutions, secure portal, global network">
    <meta name="author" content="ROOTS">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#050505">
    <link rel="canonical" href="https://example.com/">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://example.com/">
    <meta property="og:title" content="ROOTS | Global Gateway - Secure Access Portal">
    <meta property="og:description"
        content="Your secure access portal to comprehensive database services, network infrastructure, and advanced technical solutions.">
    <meta property="og:image" content="https://example.com/img/og-image.jpg">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://example.com/">
    <meta name="twitter:title" content="ROOTS | Global Gateway">
    <meta name="twitter:description"
        content="Your secure access portal to comprehensive database services and network infrastructure.">
    <meta name="twitter:image" content="https://example.com/img/twitter-image.jpg">

    <!-- Structured Data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": "ROOTS | Global Gateway",
        "url": "https://example.com/",
        "description": "Your secure access portal to comprehensive database services, network infrastructure, and advanced technical solutions.",
        "publisher": {
            "@type": "Organization",
            "name": "ROOTS",
            "logo": "https://example.com/img/logo.png"
        }
    }
    </script>

    <?php
    if (!ob_get_level()) ob_start('ob_gzhandler');
    ?>

    <style>
    :root {
        --primary-color: #00cc00;
        --bg-color: #050505;
        --glass-border: rgba(0, 204, 0, 0.25);
        --text-main: #ffffff;
        --text-muted: #999999;
        --row-bg: rgba(255, 255, 255, 0.03);
        --row-border: rgba(255, 255, 255, 0.06);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background-color: var(--bg-color);
        background-image: url('backgraund.png');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        color: var(--text-main);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .interface-container {
        width: 100%;
        max-width: 900px;
        padding: 20px;
    }

    .main-card {
        background: rgba(15, 15, 15, 0.92);
        border: 1px solid var(--glass-border);
        border-radius: 12px;
        padding: 50px 40px;
    }

    h1.brand-title {
        font-size: 3rem;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        margin-bottom: 10px;
        text-align: center;
    }

    p.tagline {
        text-align: center;
        font-size: 1.05rem;
        color: var(--text-muted);
        max-width: 600px;
        margin: 0 auto 40px auto;
        line-height: 1.6;
    }

    /* --- NETWORK SECTION --- */
    .network-section {
        margin-bottom: 40px;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding-bottom: 10px;
    }

    .section-title {
        font-size: 1.1rem;
        color: var(--primary-color);
        text-transform: uppercase;
        letter-spacing: 1.5px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* --- SERVER LIST (new clean design) --- */
    .server-list {
        display: none;
        flex-direction: column;
        gap: 6px;
        max-height: 300px;
        overflow-y: auto;
        padding-right: 5px;
    }

    .server-list.visible {
        display: flex;
    }

    .server-list::-webkit-scrollbar {
        width: 6px;
    }

    .server-list::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.03);
    }

    .server-list::-webkit-scrollbar-thumb {
        background: rgba(0, 204, 0, 0.5);
        border-radius: 4px;
    }

    .server-row {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--row-bg);
        border: 1px solid var(--row-border);
        border-radius: 6px;
        padding: 10px 14px;
        text-decoration: none;
        color: inherit;
        transition: background 0.15s, border-color 0.15s;
    }

    .server-row:hover {
        background: rgba(0, 204, 0, 0.06);
        border-color: var(--primary-color);
    }

    .server-flag {
        font-size: 1rem;
        flex-shrink: 0;
    }

    .server-code {
        font-weight: 700;
        font-size: 0.95rem;
        min-width: 70px;
        flex-shrink: 0;
    }

    /* Truncated onion address */
    .server-addr {
        flex: 1;
        min-width: 0;
        font-family: "Courier New", monospace;
        font-size: 0.75rem;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Copy button */
    .copy-btn {
        flex-shrink: 0;
        background: transparent;
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: var(--text-muted);
        font-size: 0.7rem;
        padding: 4px 10px;
        border-radius: 4px;
        cursor: pointer;
    }

    .copy-btn:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    .copy-btn.copied {
        background: var(--primary-color);
        color: #000;
        border-color: var(--primary-color);
    }

    /* Status badge (replaces ping) */
    .status-badge {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--primary-color);
    }

    .status-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--primary-color);
    }

    /* VIP variant */
    .server-row.vip {
        background: rgba(212, 175, 55, 0.07);
        border-color: rgba(212, 175, 55, 0.3);
    }

    .server-row.vip:hover {
        border-color: #d4af37;
    }

    .server-row.vip .status-badge {
        color: #d4af37;
    }

    .server-row.vip .status-dot {
        background: #d4af37;
    }

    .server-row.vip .copy-btn:hover {
        border-color: #d4af37;
        color: #d4af37;
    }

    .server-row.vip .copy-btn.copied {
        background: #d4af37;
        border-color: #d4af37;
        color: #000;
    }

    /* Main server (primary) */
    .server-row.primary {
        background: rgba(0, 204, 0, 0.08);
        border: 1px solid var(--primary-color);
        max-width: 480px;
        margin: 0 auto 24px auto;
    }

    .server-row.primary::before {
        content: 'PRIMARY';
        background: var(--primary-color);
        color: #000;
        font-size: 0.6rem;
        font-weight: bold;
        padding: 2px 7px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    /* Filter buttons */
    .node-filter-btns {
        display: flex;
        gap: 8px;
    }

    .filter-btn {
        border: 1px solid rgba(255, 255, 255, 0.15);
        background: transparent;
        color: var(--text-muted);
        padding: 6px 16px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 0.8rem;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .filter-btn.active {
        background: var(--primary-color);
        color: #000;
        border-color: var(--primary-color);
    }

    .filter-btn.vip-btn.active {
        background: #d4af37;
        border-color: #d4af37;
        color: #000;
    }

    /* Actions */
    .actions {
        display: flex;
        justify-content: center;
        gap: 20px;
    }

    .btn {
        padding: 13px 40px;
        font-weight: 600;
        font-size: 1rem;
        letter-spacing: 1px;
        text-transform: uppercase;
        border-radius: 6px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .btn-primary {
        background: var(--primary-color);
        color: #000;
        border: 1px solid var(--primary-color);
    }

    .btn-primary:hover {
        background: #fff;
    }

    .btn-secondary {
        background: transparent;
        color: var(--primary-color);
        border: 1px solid rgba(0, 204, 0, 0.4);
    }

    .btn-secondary:hover {
        background: rgba(0, 204, 0, 0.1);
    }

    .sys-info {
        position: fixed;
        bottom: 20px;
        right: 30px;
        color: rgba(255, 255, 255, 0.3);
        font-size: 0.85rem;
        text-align: right;
    }

    /* Simplified loader */
    #boot-screen {
        position: fixed;
        inset: 0;
        background: #050505;
        z-index: 999;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        transition: opacity 0.3s ease;
    }

    #boot-screen.hidden {
        opacity: 0;
        pointer-events: none;
    }

    #boot-status {
        color: var(--primary-color);
        letter-spacing: 3px;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 0.95rem;
    }

    .loader-bar {
        width: 200px;
        height: 2px;
        background: rgba(0, 204, 0, 0.15);
        margin-top: 15px;
        border-radius: 2px;
        overflow: hidden;
        position: relative;
    }

    .loader-bar::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        height: 100%;
        width: 40%;
        background: var(--primary-color);
        animation: loadScroll 0.9s infinite linear;
    }

    @keyframes loadScroll {
        0% {
            left: -40%;
        }

        100% {
            left: 100%;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .loader-bar::after {
            animation: none;
            width: 100%;
        }
    }

    /* Mobile */
    @media (max-width: 768px) {
        .brand-title {
            font-size: 2rem;
        }

        .main-card {
            padding: 30px 20px;
        }

        .actions {
            flex-direction: column;
        }

        .btn {
            width: 100%;
            text-align: center;
        }

        .server-list {
            max-height: 240px;
        }

        .server-addr {
            display: none;
        }

        /* hide address on small screens, code is enough */
        .server-row.primary {
            max-width: 100%;
        }
    }
    </style>
</head>

<body>

    <div id="boot-screen" aria-hidden="true">
        <div id="boot-status">Initializing</div>
        <div class="loader-bar"></div>
    </div>

    <main class="interface-container">
        <article class="main-card">

            <h1 class="brand-title">ROOTS SYSTEM</h1>
            <p class="tagline">
                Access the Deep Data Layer. Advanced privacy infrastructure and decentralized node routing for the
                modern web.
            </p>

            <section class="network-section" aria-label="Network nodes">
                <div class="section-header">
                    <h2 class="section-title">Active Nodes</h2>
                    <div class="node-filter-btns" role="tablist">
                        <button class="filter-btn" id="globalBtn" onclick="showServers('global')"
                            role="tab">Global</button>
                        <button class="filter-btn vip-btn" id="vipBtn" onclick="showServers('vip')"
                            role="tab">VIP</button>
                    </div>
                </div>

                <!-- Primary Server -->
                <a href="http://trzjmgy54a2kry3dsqvpiyutr34xgffnhrgd7okmpky2qaoqbyfovfyd.onion"
                    class="server-row primary">
                    <span class="server-code">ROOTS</span>
                    <span class="server-addr">trzjmgy54a2kry3dsqvpiyutr34xgffnhrgd7okmpky2qaoqbyfovfyd.onion</span>
                    <span class="status-badge"><span class="status-dot"></span>ONLINE</span>
                </a>

                <!-- Global Servers -->
                <div class="server-list" id="regularServers">

                    <a href="http://2sr6wopzfn7vzuyj7n4ixraquuquwbdf5a4j537bzgrjiikdtua65lad.onion" class="server-row">
                        <span class="server-flag" aria-hidden="true">🔓</span>
                        <span class="server-code">51-EAST</span>
                        <span class="server-addr">2sr6wopzfn7vzuyj7n4ixraquuquwbdf5a4j537bzgrjiikdtua65lad.onion</span>
                        <button class="copy-btn"
                            data-addr="2sr6wopzfn7vzuyj7n4ixraquuquwbdf5a4j537bzgrjiikdtua65lad.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>ONLINE</span>
                    </a>

                    <a href="http://cs7c6d3ldh6iqonevbyzhr74wyenkljleyztirzfbn5l6pasdku6xdad.onion" class="server-row">
                        <span class="server-flag" aria-hidden="true">🔓</span>
                        <span class="server-code">36-BOM</span>
                        <span class="server-addr">cs7c6d3ldh6iqonevbyzhr74wyenkljleyztirzfbn5l6pasdku6xdad.onion</span>
                        <button class="copy-btn"
                            data-addr="cs7c6d3ldh6iqonevbyzhr74wyenkljleyztirzfbn5l6pasdku6xdad.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>ONLINE</span>
                    </a>

                    <a href="http://se76pjkoagwczvjtz26ivdtwfiliov4ncjm7f5ab5ai4k5jsmqnumjid.onion" class="server-row">
                        <span class="server-flag" aria-hidden="true">🔓</span>
                        <span class="server-code">24-BJK</span>
                        <span class="server-addr">se76pjkoagwczvjtz26ivdtwfiliov4ncjm7f5ab5ai4k5jsmqnumjid.onion</span>
                        <button class="copy-btn"
                            data-addr="se76pjkoagwczvjtz26ivdtwfiliov4ncjm7f5ab5ai4k5jsmqnumjid.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>ONLINE</span>
                    </a>

                    <a href="http://63ayf6w7bjy4r5eofhntrlksuztr2wlrapyce7wuk32zpv5l7hdy64id.onion" class="server-row">
                        <span class="server-flag" aria-hidden="true">🔓</span>
                        <span class="server-code">12-MSC</span>
                        <span class="server-addr">63ayf6w7bjy4r5eofhntrlksuztr2wlrapyce7wuk32zpv5l7hdy64id.onion</span>
                        <button class="copy-btn"
                            data-addr="63ayf6w7bjy4r5eofhntrlksuztr2wlrapyce7wuk32zpv5l7hdy64id.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>ONLINE</span>
                    </a>

                </div>

                <!-- VIP Servers -->
                <div class="server-list" id="vipServers">

                    <a href="http://oimy3ufexbk6c4dwubokg4z7kxz64ipjntkqo2fanku6tnu7ip3orxid.onion"
                        class="server-row vip">
                        <span class="server-flag" aria-hidden="true">🔐</span>
                        <span class="server-code">36-KEM</span>
                        <span class="server-addr">oimy3ufexbk6c4dwubokg4z7kxz64ipjntkqo2fanku6tnu7ip3orxid.onion</span>
                        <button class="copy-btn"
                            data-addr="oimy3ufexbk6c4dwubokg4z7kxz64ipjntkqo2fanku6tnu7ip3orxid.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>VIP</span>
                    </a>

                    <a href="http://6kdrjykxddpojonnnxfvktmrbjwu6xu7fstjb43zgulm5og2ke5dz6ad.onion"
                        class="server-row vip">
                        <span class="server-flag" aria-hidden="true">🔐</span>
                        <span class="server-code">22-TOK</span>
                        <span class="server-addr">6kdrjykxddpojonnnxfvktmrbjwu6xu7fstjb43zgulm5og2ke5dz6ad.onion</span>
                        <button class="copy-btn"
                            data-addr="6kdrjykxddpojonnnxfvktmrbjwu6xu7fstjb43zgulm5og2ke5dz6ad.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>VIP</span>
                    </a>

                    <a href="http://iroqkxujwgxqa4pysserguarjcqehtk33qfdghadntaddrzid2vtf3yd.onion"
                        class="server-row vip">
                        <span class="server-flag" aria-hidden="true">🔐</span>
                        <span class="server-code">43-LON</span>
                        <span class="server-addr">iroqkxujwgxqa4pysserguarjcqehtk33qfdghadntaddrzid2vtf3yd.onion</span>
                        <button class="copy-btn"
                            data-addr="iroqkxujwgxqa4pysserguarjcqehtk33qfdghadntaddrzid2vtf3yd.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>VIP</span>
                    </a>

                    <a href="http://ye3mzc3burwn7unb6woc35wx2dbgt2wwqc7hzprpbnmmcxxeuojjx6id.onion"
                        class="server-row vip">
                        <span class="server-flag" aria-hidden="true">🔐</span>
                        <span class="server-code">11-SYD</span>
                        <span class="server-addr">ye3mzc3burwn7unb6woc35wx2dbgt2wwqc7hzprpbnmmcxxeuojjx6id.onion</span>
                        <button class="copy-btn"
                            data-addr="ye3mzc3burwn7unb6woc35wx2dbgt2wwqc7hzprpbnmmcxxeuojjx6id.onion"
                            onclick="copyAddr(event, this)">COPY</button>
                        <span class="status-badge"><span class="status-dot"></span>VIP</span>
                    </a>

                </div>
            </section>

            <nav class="actions" aria-label="Main actions">
                <a class="btn btn-primary" href="login">Access Terminal</a>
                <a class="btn btn-secondary" href="signup">Registration</a>
            </nav>

        </article>
    </main>

    <footer class="sys-info">
        v4.1.0 Stable<br>Encrypted Tunnel: ACTIVE
    </footer>

    <script>
    (function() {
        'use strict';

        /* --- Simplified loader --- */
        var bootScreen = document.getElementById('boot-screen');

        function hideBoot() {
            if (!bootScreen || bootScreen.classList.contains('hidden')) return;
            bootScreen.classList.add('hidden');
            setTimeout(function() {
                if (bootScreen && bootScreen.parentNode) bootScreen.parentNode.removeChild(bootScreen);
            }, 400);
        }
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(hideBoot, 250);
        });
        window.addEventListener('load', hideBoot);
        setTimeout(hideBoot, 1500);

        /* --- Server filter (same workflow) --- */
        window.showServers = function(type) {
            var regular = document.getElementById('regularServers');
            var vip = document.getElementById('vipServers');
            var gBtn = document.getElementById('globalBtn');
            var vBtn = document.getElementById('vipBtn');
            if (!regular || !vip) return;
            var showGlobal = (type === 'global');
            regular.classList.toggle('visible', showGlobal);
            vip.classList.toggle('visible', !showGlobal);
            gBtn.classList.toggle('active', showGlobal);
            vBtn.classList.toggle('active', !showGlobal);
        };

        /* --- Copy onion address --- */
        window.copyAddr = function(event, btn) {
            event.preventDefault();
            event.stopPropagation();
            var addr = btn.getAttribute('data-addr');

            function done() {
                btn.textContent = 'COPIED';
                btn.classList.add('copied');
                setTimeout(function() {
                    btn.textContent = 'COPY';
                    btn.classList.remove('copied');
                }, 1500);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText('http://' + addr).then(done, function() {
                    fallback();
                });
            } else {
                fallback();
            }

            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = 'http://' + addr;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                } catch (e) {}
                document.body.removeChild(ta);
                done();
            }
        };
    })();
    </script>
</body>

</html>