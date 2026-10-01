/**
 * Dashboard Analytics Functions
 * Handles analytics data fetching, chart initialization, and period switching
 */

(function () {
    'use strict';

    // ── Config ──────────────────────────────────────────
    const ANALYTICS_CONFIG = {
        defaultPeriod: '30d',
        cacheTimeout: 60000, // 60 seconds
        chartColors: {
            sales: '#666666',
            purchases: '#555555',
            grid: 'rgba(255,255,255,0.03)',
            text: '#3a3a3a'
        }
    };

    // ── State ────────────────────────────────────────────
    let currentPeriod = ANALYTICS_CONFIG.defaultPeriod;
    let analyticsCache = null;
    let cacheTimestamp = 0;

    // ── Fetch Analytics Data ─────────────────────────────
    async function fetchAnalyticsData(period) {
        const now = Date.now();
        
        // Check cache
        if (analyticsCache && (now - cacheTimestamp) < ANALYTICS_CONFIG.cacheTimeout && currentPeriod === period) {
            return analyticsCache;
        }

        try {
            const response = await fetch(`get_dashboard_analytics.php?period=${period}`, {
                method: 'GET',
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                analyticsCache = data;
                cacheTimestamp = now;
                currentPeriod = period;
                return data;
            } else {
                console.error('Analytics API returned error:', data.message);
                return null;
            }
        } catch (error) {
            console.error('Failed to fetch analytics:', error);
            return null;
        }
    }

    // ── Update KPI Cards ─────────────────────────────────
    function updateKPICards(kpis) {
        if (!kpis) return;

        const kpiMap = {
            'kpiVolume': {
                value: kpis.total_volume || 0,
                delta: kpis.volume_delta,
                format: 'number'
            },
            'kpiSales': {
                value: kpis.total_sales || 0,
                delta: kpis.sales_delta,
                format: 'number'
            },
            'kpiPurchases': {
                value: kpis.total_purchases || 0,
                delta: kpis.purchases_delta,
                format: 'number'
            },
            'kpiUsers': {
                value: kpis.active_users || 0,
                delta: kpis.users_delta,
                format: 'number'
            },
            'kpiGrowth': {
                value: (kpis.growth_rate || 0).toFixed(1) + '%',
                delta: null,
                format: 'percent'
            }
        };

        for (const [id, info] of Object.entries(kpiMap)) {
            const valueEl = document.getElementById(id + 'Val');
            const deltaEl = document.getElementById(id + 'Delta');

            if (valueEl) {
                if (info.format === 'percent') {
                    valueEl.textContent = info.value;
                } else {
                    valueEl.textContent = Number(info.value).toLocaleString();
                }
            }

            if (deltaEl && info.delta !== null && info.delta !== undefined) {
                const isPositive = info.delta >= 0;
                deltaEl.className = `kpi-delta ${isPositive ? 'pos' : 'neg'}`;
                deltaEl.innerHTML = `<i class="fas fa-arrow-${isPositive ? 'up' : 'down'}"></i>${Math.abs(info.delta).toFixed(1)}%`;
            } else if (deltaEl) {
                deltaEl.textContent = '—';
                deltaEl.className = 'kpi-delta';
            }
        }
    }

    // ── Initialize Charts ─────────────────────────────────
    function initCharts(data) {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded');
            return;
        }

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#555555',
                        font: {
                            family: 'JetBrains Mono, monospace',
                            size: 11
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#3a3a3a',
                        font: { size: 10 }
                    },
                    grid: {
                        color: ANALYTICS_CONFIG.chartColors.grid
                    }
                },
                y: {
                    ticks: {
                        color: '#3a3a3a',
                        font: { size: 10 }
                    },
                    grid: {
                        color: ANALYTICS_CONFIG.chartColors.grid
                    }
                }
            }
        };

        // Sales vs Purchases Line Chart
        initLineChart(data.chart_daily, defaultOptions);

        // Transaction Types Doughnut Chart
        initDoughnutChart(data.tx_distribution);

        // Top Active Users Bar Chart
        initBarChart(data.top_active, defaultOptions);
    }

    function initLineChart(chartData, defaultOptions) {
        const ctx = document.getElementById('chartSalesPurchases');
        if (!ctx || !chartData) return;

        // Destroy existing chart
        if (ctx.chart) {
            ctx.chart.destroy();
        }

        ctx.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels || [],
                datasets: [
                    {
                        label: 'Sales',
                        data: chartData.sales || [],
                        borderColor: ANALYTICS_CONFIG.chartColors.sales,
                        backgroundColor: 'rgba(255,255,255,0.02)',
                        tension: 0.4,
                        pointRadius: 2,
                        borderWidth: 2,
                        fill: true
                    },
                    {
                        label: 'Purchases',
                        data: chartData.purchases || [],
                        borderColor: ANALYTICS_CONFIG.chartColors.purchases,
                        backgroundColor: 'rgba(255,255,255,0.02)',
                        tension: 0.4,
                        pointRadius: 2,
                        borderWidth: 2,
                        fill: true
                    }
                ]
            },
            options: defaultOptions
        });
    }

    function initDoughnutChart(distributionData) {
        const ctx = document.getElementById('chartTxTypes');
        if (!ctx || !distributionData) return;

        // Destroy existing chart
        if (ctx.chart) {
            ctx.chart.destroy();
        }

        ctx.chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: distributionData.labels || [],
                datasets: [{
                    data: distributionData.values || [],
                    backgroundColor: ['#555555', '#444444', '#666666', '#3a3a3a', '#4a4a4a'],
                    borderColor: '#0a0a0a',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#555555',
                            font: {
                                family: 'JetBrains Mono, monospace',
                                size: 10
                            },
                            padding: 12
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }

    function initBarChart(topActiveData, defaultOptions) {
        const ctx = document.getElementById('chartTopUsers');
        if (!ctx || !topActiveData) return;

        // Destroy existing chart
        if (ctx.chart) {
            ctx.chart.destroy();
        }

        ctx.chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: topActiveData.labels || [],
                datasets: [{
                    label: 'Activity Volume',
                    data: topActiveData.values || [],
                    backgroundColor: [
                        'rgba(255,215,0,0.7)',
                        'rgba(192,192,192,0.7)',
                        'rgba(205,127,50,0.7)',
                        'rgba(0,255,65,0.5)',
                        'rgba(0,245,255,0.5)'
                    ],
                    borderColor: 'transparent',
                    borderRadius: 4
                }]
            },
            options: {
                ...defaultOptions,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: '#3a3a3a',
                            font: { size: 9 },
                            maxRotation: 30
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        ticks: {
                            color: '#3a3a3a',
                            font: { size: 9 }
                        },
                        grid: {
                            color: ANALYTICS_CONFIG.chartColors.grid
                        }
                    }
                }
            }
        });
    }

    // ── Period Switch Handler ─────────────────────────────
    async function switchPeriod(period, btn) {
        // Update button states
        document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');

        // Show loading state
        const kpiContainer = document.getElementById('kpiContainer');
        if (kpiContainer) {
            kpiContainer.style.opacity = '0.5';
        }

        // Fetch new data
        const data = await fetchAnalyticsData(period);

        if (data && data.success) {
            // Update KPIs
            updateKPICards(data.kpis);

            // Update charts
            initCharts(data);

            // Store for other scripts
            window.__analyticsData = data;
        } else {
            console.error('Failed to load analytics data');
            
            // Show error state
            ['kpiVolume', 'kpiSales', 'kpiPurchases', 'kpiUsers', 'kpiGrowth'].forEach(id => {
                const valEl = document.getElementById(id + 'Val');
                if (valEl) valEl.textContent = 'Error';
            });
        }

        // Restore opacity
        if (kpiContainer) {
            kpiContainer.style.opacity = '1';
        }
    }

    // ── Public API ───────────────────────────────────────
    window.DashboardAnalytics = {
        switchPeriod: switchPeriod,
        updateKPICards: updateKPICards,
        initCharts: initCharts,
        fetchData: fetchAnalyticsData
    };

    // ── Auto-expose for dashboard-face-scanner.js compatibility ──
    window.switchAnalyticsPeriod = switchPeriod;

    // ── Initialize on Load ────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        // Load default period data
        const defaultBtn = document.querySelector('.period-btn[data-period="' + ANALYTICS_CONFIG.defaultPeriod + '"]');
        if (defaultBtn) {
            switchPeriod(ANALYTICS_CONFIG.defaultPeriod, defaultBtn);
        }
    });

})();
