/**
 * Dashboard Face Scanner Boot Sequence
 * Deep Terminal Visual Identity
 *
 * Flow:
 * 1. Show overlay with HUD scanner animation
 * 2. Fetch face_scanner_boot.php + analytics in parallel
 * 3. Show "face scanner matrix loaded" on success
 * 4. Fade out overlay, reveal dashboard, init charts
 */

(function () {
    'use strict';

    // ── Config ──────────────────────────────────────────
    const BOOT_CONFIG = {
        scanDuration:    2200,   // ms scanning phase
        successHold:     900,    // ms hold on success text
        fadeOutDuration: 600,    // ms overlay fade
        retryLimit:      3,
        analyticsDefault:'30d'
    };

    // ── State ────────────────────────────────────────────
    let retryCount   = 0;

    // ── DOM refs (populated on init) ────────────────────
    let overlay, progressBar, bootMessage, bootLog, retryBtn, skipBtn;

    // ── Matrix Rain ──────────────────────────────────────
    function initMatrixRain() {
        // Matrix rain disabled as requested by user
    }

    function stopMatrixRain() {
        // Matrix rain disabled as requested by user
    }

    // ── Log helper ───────────────────────────────────────
    let logIndex = 0;
    function addLog(text, type = 'info') {
        if (!bootLog) return;
        const line = document.createElement('div');
        line.className = `boot-log-line ${type}`;
        line.style.animationDelay = `${logIndex * 80}ms`;
        line.textContent = text;
        bootLog.appendChild(line);
        bootLog.scrollTop = bootLog.scrollHeight;
        logIndex++;
    }

    // ── Progress bar ─────────────────────────────────────
    function setProgress(pct) {
        if (progressBar) progressBar.style.width = `${Math.min(100, pct)}%`;
    }

    // ── Message ──────────────────────────────────────────
    function setMessage(text, cls = '') {
        if (!bootMessage) return;
        bootMessage.className = `boot-message ${cls}`;
        bootMessage.textContent = text;
    }

    // ── Update clock ─────────────────────────────────────
    function updateClock() {
        const el = document.getElementById('bootClock');
        if (!el) return;
        el.textContent = new Date().toTimeString().slice(0, 8);
    }

    // ── Reveal dashboard ─────────────────────────────────
    function revealDashboard() {
        document.body.classList.remove('dashboard-loading');
        const content = document.getElementById('dashboardContent');
        if (content) content.style.opacity = '1';

        // Init charts if data is ready
        if (window.__analyticsData) {
            initDashboardCharts(window.__analyticsData);
        }

        // Dispatch event for other scripts
        window.dispatchEvent(new CustomEvent('dashboard:ready'));
    }

    // ── Boot sequence ────────────────────────────────────
    async function runBootSequence() {
        setProgress(5);
        addLog('Initializing terminal session...', 'info');
        await sleep(300);

        setProgress(15);
        addLog('Loading security modules...', 'info');

        // Kick off both fetches in parallel
        const [bootResult, analyticsResult] = await Promise.allSettled([
            fetchBootStatus(),
            fetchAnalytics(BOOT_CONFIG.analyticsDefault)
        ]);

        // Store analytics regardless
        if (analyticsResult.status === 'fulfilled' && analyticsResult.value?.success) {
            window.__analyticsData = analyticsResult.value;
        }

        setProgress(70);
        addLog('Drone API handshake complete', 'ok');

        // Check if user has completed boot before - skip overlay
        if (bootResult.status === 'fulfilled' && bootResult.value?.skip_overlay) {
            addLog('Boot previously completed - skipping overlay', 'ok');
            setProgress(100);
            finishBoot();
            return;
        }

        // Simulate scan duration visuals
        await animateScan();

        setProgress(92);

        if (bootResult.status === 'fulfilled' && bootResult.value?.success) {
            setProgress(100);
            addLog(bootResult.value.message || 'face scanner matrix loaded', 'ok');
            setMessage(bootResult.value.message || 'face scanner matrix loaded', 'success');

            await sleep(BOOT_CONFIG.successHold);
            finishBoot();
        } else {
            // Error state
            const errMsg = bootResult.reason?.message || 'Connection timeout';
            addLog(errMsg, 'err');
            setMessage('API ERROR — RETRY REQUIRED', 'error');
            setProgress(0);

            retryCount++;
            if (retryBtn) {
                retryBtn.style.display = 'inline-block';
            }

            if (retryCount <= BOOT_CONFIG.retryLimit) {
                addLog(`Auto-retry in 3s... (${retryCount}/${BOOT_CONFIG.retryLimit})`, 'warn');
                await sleep(3000);
                logIndex = 0;
                if (bootLog) bootLog.innerHTML = '';
                runBootSequence();
            } else {
                addLog('Max retries reached. Manual skip available.', 'warn');
                if (skipBtn) skipBtn.style.display = 'block';
            }
        }
    }

    // ── Scan animation phase ──────────────────────────────
    async function animateScan() {
        const steps = [
            { pct: 75, log: 'Scanning biometric matrix...', ms: 400 },
            { pct: 82, log: 'Pattern recognition active',   ms: 350 },
            { pct: 88, log: 'Neural mesh calibrated',       ms: 300 },
        ];

        for (const step of steps) {
            setProgress(step.pct);
            setMessage(step.log);
            addLog(step.log, 'info');
            await sleep(step.ms);
        }
    }

    // ── Finish & fade out ─────────────────────────────────
    async function finishBoot() {
        // Mark boot as completed in session
        try {
            await fetch('face_scanner_complete.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            // Also set localStorage as backup
            localStorage.setItem('face_scan_boot_completed', 'true');
        } catch (e) {
            console.warn('Failed to mark boot as completed:', e);
        }

        stopMatrixRain();
        if (overlay) {
            overlay.classList.add('fade-out');
            setTimeout(() => {
                overlay.style.display = 'none';
                revealDashboard();
            }, BOOT_CONFIG.fadeOutDuration);
        } else {
            revealDashboard();
        }
    }

    // ── API calls ─────────────────────────────────────────
    async function fetchBootStatus() {
        const res = await fetch('face_scanner_boot.php', {
            method: 'GET',
            credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Boot failed');
        return data;
    }

    async function fetchAnalytics(period) {
        try {
            const res = await fetch(`get_dashboard_analytics.php?period=${period}`, {
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) return null;
            return await res.json();
        } catch {
            return null;
        }
    }

    // ── Chart initializer ─────────────────────────────────
    function initDashboardCharts(data) {
        if (typeof Chart === 'undefined') return;

        const defaultOpts = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#555', font: { family: 'JetBrains Mono', size: 11 } } }
            },
            scales: {
                x: { ticks: { color: '#3a3a3a', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.03)' } },
                y: { ticks: { color: '#3a3a3a', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.03)' } }
            }
        };

        // Line chart: sales vs purchases
        const lineCtx = document.getElementById('chartSalesPurchases');
        if (lineCtx && data.chart_daily) {
            // Destroy existing chart if it exists
            if (lineCtx.chart) {
                lineCtx.chart.destroy();
            }
            lineCtx.chart = new Chart(lineCtx, {
                type: 'line',
                data: {
                    labels: data.chart_daily.labels,
                    datasets: [
                        {
                            label: 'Sales',
                            data: data.chart_daily.sales,
                            borderColor: '#666',
                            backgroundColor: 'rgba(255,255,255,0.02)',
                            tension: 0.4, pointRadius: 2, borderWidth: 2, fill: true
                        },
                        {
                            label: 'Purchases',
                            data: data.chart_daily.purchases,
                            borderColor: '#555',
                            backgroundColor: 'rgba(255,255,255,0.02)',
                            tension: 0.4, pointRadius: 2, borderWidth: 2, fill: true
                        }
                    ]
                },
                options: { ...defaultOpts, scales: { ...defaultOpts.scales } }
            });
        }

        // Doughnut: transaction types
        const donutCtx = document.getElementById('chartTxTypes');
        if (donutCtx && data.tx_distribution) {
            // Destroy existing chart if it exists
            if (donutCtx.chart) {
                donutCtx.chart.destroy();
            }
            donutCtx.chart = new Chart(donutCtx, {
                type: 'doughnut',
                data: {
                    labels: data.tx_distribution.labels,
                    datasets: [{
                        data: data.tx_distribution.values,
                        backgroundColor: ['#555','#444','#666','#3a3a3a','#4a4a4a'],
                        borderColor: '#0a0a0a', borderWidth: 2
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#555', font: { family: 'JetBrains Mono', size: 10 }, padding: 12 } }
                    },
                    cutout: '65%'
                }
            });
        }

        // Bar: top 5 active users
        const barCtx = document.getElementById('chartTopUsers');
        if (barCtx && data.top_active) {
            // Destroy existing chart if it exists
            if (barCtx.chart) {
                barCtx.chart.destroy();
            }
            barCtx.chart = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: data.top_active.labels,
                    datasets: [{
                        label: 'Activity Volume',
                        data: data.top_active.values,
                        backgroundColor: [
                            'rgba(255,215,0,.7)',
                            'rgba(192,192,192,.7)',
                            'rgba(205,127,50,.7)',
                            'rgba(0,255,65,.5)',
                            'rgba(0,245,255,.5)'
                        ],
                        borderColor: 'transparent', borderRadius: 4
                    }]
                },
                options: {
                    ...defaultOpts,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#3a3a3a', font: { size: 9 }, maxRotation: 30 }, grid: { display: false } },
                        y: { ticks: { color: '#3a3a3a', font: { size: 9 } }, grid: { color: 'rgba(255,255,255,0.03)' } }
                    }
                }
            });
        }
    }

    // ── Leaderboard metric switch ──────────────────────────
    window.switchLeaderboardMetric = async function (metric, btn) {
        document.querySelectorAll('.metric-tab').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');

        const table = document.getElementById('leaderboardTableBody');
        const podium = document.getElementById('podiumContainer');
        if (table) table.innerHTML = '<tr><td colspan="5" class="text-center py-3"><span class="spinner"></span></td></tr>';

        try {
            const period = document.querySelector('.period-btn.active')?.dataset.period || '30d';
            const res = await fetch(`get_leaderboard.php?metric=${metric}&period=${period}`, {
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (data.success && data.top_users) {
                renderLeaderboard(data.top_users, data.current_user, data.current_rank, table, podium);
            }
        } catch (e) {
            if (table) table.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3" style="font-size:.7rem">CONNECTION_ERROR</td></tr>';
        }
    };

    function renderLeaderboard(users, currentUser, currentRank, tableBody, podiumContainer) {
        // Podium
        if (podiumContainer && users.length >= 3) {
            const top3 = users.slice(0, 3);
            const order = [top3[1], top3[0], top3[2]]; // visual: 2nd, 1st, 3rd
            const ranks  = [2, 1, 3];

            podiumContainer.innerHTML = order.map((u, i) => {
                const r = ranks[i];
                const crown = r === 1 ? '👑' : r === 2 ? '🥈' : '🥉';
                const pts   = Number(u.points ?? 0).toLocaleString();
                const name  = escHtml(u.display_name ?? u.username ?? '---');
                const sub   = buildSubBadge(u.subscription);
                return `
                <div class="podium-card rank-${r}">
                    <span class="podium-medal">${r}</span>
                    <span class="podium-crown">${crown}</span>
                    <img src="img/user.jpg" alt="${name}" class="podium-avatar" loading="lazy">
                    <div class="podium-name">${name}</div>
                    <div class="podium-pts">${pts} <small style="font-size:.55rem;opacity:.7">PTS</small></div>
                    <div class="mt-1">${sub}</div>
                </div>`;
            }).join('');
        }

        if (!tableBody) return;
        const currentUsername = document.body.dataset.username || '';

        tableBody.innerHTML = users.map((u, idx) => {
            const rank = idx + 1;
            let rankCell, rowClass = '';

            if (rank === 1) { rankCell = `<span class="rank-badge-glow g">1</span>`; rowClass = 'row-gold'; }
            else if (rank === 2) { rankCell = `<span class="rank-badge-glow s">2</span>`; rowClass = 'row-silver'; }
            else if (rank === 3) { rankCell = `<span class="rank-badge-glow b">3</span>`; rowClass = 'row-bronze'; }
            else rankCell = `<span style="color:#555;font-size:.8rem">${rank}</span>`;

            const isPinned  = u.username === currentUsername;
            if (isPinned) rowClass += ' pinned-user-row';

            const pts    = Number(u.points ?? 0).toLocaleString();
            const name   = escHtml(u.display_name ?? u.username ?? '---');
            const sub    = buildSubBadge(u.subscription);
            const change = buildChangeIndicator(u.points, u.previous_points);

            return `
            <tr class="${rowClass}">
                <td class="rank-cell">${rankCell}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <img src="img/user.jpg" alt="${name}" class="user-avatar-small" loading="lazy">
                        <span style="font-size:.75rem">${name}${isPinned ? ' <span style="color:var(--term-yellow);font-size:.55rem">[YOU]</span>' : ''}</span>
                    </div>
                </td>
                <td class="d-none d-sm-table-cell">${sub}</td>
                <td style="font-size:.85rem;font-weight:700;color:#fff;">${pts}</td>
                <td>${change}</td>
            </tr>`;
        }).join('');
    }

    // ── Analytics period switch ────────────────────────────
    window.switchAnalyticsPeriod = async function (period, btn) {
        document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');

        const kpiContainer = document.getElementById('kpiContainer');
        if (kpiContainer) kpiContainer.style.opacity = '0.5';

        const data = await fetchAnalytics(period);
        if (data?.success) {
            window.__analyticsData = data;
            updateKPICards(data.kpis);
            initDashboardCharts(data);
        } else {
            console.error('Failed to fetch analytics data:', data);
            // Show error state in KPI cards
            ['kpiVolume', 'kpiSales', 'kpiPurchases', 'kpiUsers', 'kpiGrowth'].forEach(id => {
                const valEl = document.getElementById(id + 'Val');
                if (valEl) valEl.textContent = 'Error';
            });
        }

        if (kpiContainer) kpiContainer.style.opacity = '1';
    };

    function updateKPICards(kpis) {
        if (!kpis) return;
        const map = {
            'kpiVolume':    { v: kpis.total_volume,  d: kpis.volume_delta },
            'kpiSales':     { v: kpis.total_sales,   d: kpis.sales_delta  },
            'kpiPurchases': { v: kpis.total_purchases, d: kpis.purchases_delta },
            'kpiUsers':     { v: kpis.active_users,  d: kpis.users_delta  },
            'kpiGrowth':    { v: (kpis.growth_rate ?? 0).toFixed(1) + '%', d: null, raw: true }
        };

        for (const [id, info] of Object.entries(map)) {
            const valEl = document.getElementById(id + 'Val');
            const dltEl = document.getElementById(id + 'Delta');
            if (valEl) valEl.textContent = info.raw ? info.v : Number(info.v ?? 0).toLocaleString();
            if (dltEl && info.d != null) {
                const pos = info.d >= 0;
                dltEl.className = `kpi-delta ${pos ? 'pos' : 'neg'}`;
                dltEl.innerHTML = `<i class="fas fa-arrow-${pos ? 'up' : 'down'}"></i>${Math.abs(info.d).toFixed(1)}%`;
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────
    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

    function escHtml(str) {
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    function buildSubBadge(sub) {
        const s = (sub ?? 'BASIC').toUpperCase();
        const cls = s.includes('ADMIN') ? 'badge-admin' :
                    s.includes('PREMIUM') ? 'badge-premium' :
                    s.includes('PRO') ? 'badge-pro' :
                    s.includes('VIP') ? 'badge-vip' : 'badge-basic';
        return `<span class="badge ${cls}" style="font-size:.55rem;padding:3px 7px;">${escHtml(s)}</span>`;
    }

    function buildChangeIndicator(current, previous) {
        const c = Number(current ?? 0);
        const p = Number(previous ?? 0);
        if (p === 0) return `<span style="color:#555;font-size:.65rem">—</span>`;
        const diff = c - p;
        const cls  = diff >= 0 ? 'var(--term-green)' : 'var(--term-red)';
        const icon = diff >= 0 ? 'fa-caret-up' : 'fa-caret-down';
        return `<span style="color:${cls};font-size:.7rem"><i class="fas ${icon}"></i> ${Math.abs(diff).toLocaleString()}</span>`;
    }

    // ── Clock tick ────────────────────────────────────────
    setInterval(updateClock, 1000);

    // ── Init ─────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        overlay     = document.getElementById('faceScannerOverlay');
        progressBar = document.getElementById('bootProgressBar');
        bootMessage = document.getElementById('bootMessage');
        bootLog     = document.getElementById('bootLog');
        retryBtn    = document.getElementById('bootRetryBtn');
        skipBtn     = document.getElementById('bootSkipBtn');

        // Check if boot was completed before (using localStorage as backup)
        const bootCompleted = localStorage.getItem('face_scan_boot_completed') === 'true';
        if (bootCompleted && overlay) {
            // Skip the boot sequence entirely
            overlay.style.display = 'none';
            document.body.classList.remove('dashboard-loading');
            const content = document.getElementById('dashboardContent');
            if (content) content.style.opacity = '1';
            window.dispatchEvent(new CustomEvent('dashboard:ready'));
            return;
        }

        if (!overlay) {
            // No overlay = just reveal
            revealDashboard();
            return;
        }

        // Skip button (admin)
        if (skipBtn) {
            skipBtn.addEventListener('click', () => {
                stopMatrixRain();
                overlay.style.display = 'none';
                revealDashboard();
            });
        }

        // Retry button
        if (retryBtn) {
            retryBtn.addEventListener('click', () => {
                retryBtn.style.display = 'none';
                retryCount = 0;
                logIndex   = 0;
                if (bootLog) bootLog.innerHTML = '';
                runBootSequence();
            });
        }

        initMatrixRain();
        updateClock();
        runBootSequence();
    });

    // Expose for external use
    window.DashboardFaceScanner = { initCharts: initDashboardCharts };
})();
