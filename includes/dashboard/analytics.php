<?php
/**
 * includes/dashboard/analytics.php
 * KPI cards + Chart.js charts partial.
 *
 * Variables expected from parent:
 *   (none — all data loaded async by JS)
 */
?>

<!-- ── Analytics Section ── -->
<section class="container-fluid px-4 mb-3" id="analyticsSection">
    <div class="terminal-card rounded p-3">
        <div class="corner-accent tr"></div>
        <div class="corner-accent bl"></div>
        <div class="terminal-card-bg"></div>

        <!-- Header + Period Filter -->
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h6 class="section-header mb-0">ANALYTICS_MATRIX</h6>
            <nav class="d-flex gap-1 flex-wrap" aria-label="Time period filter">
                <button class="period-btn" data-period="24h"  onclick="switchAnalyticsPeriod('24h',this)">[24H]</button>
                <button class="period-btn active" data-period="30d" onclick="switchAnalyticsPeriod('30d',this)">[30D]</button>
                <button class="period-btn" data-period="7d"   onclick="switchAnalyticsPeriod('7d',this)">[7D]</button>
                <button class="period-btn" data-period="90d"  onclick="switchAnalyticsPeriod('90d',this)">[90D]</button>
                <button class="period-btn" data-period="all"  onclick="switchAnalyticsPeriod('all',this)">[ALL]</button>
            </nav>
        </div>

        <!-- KPI Row -->
        <div class="row g-2 mb-3" id="kpiContainer">

            <div class="col-6 col-md-4 col-xl">
                <div class="kpi-card">
                    <div class="kpi-label">TOTAL_VOLUME</div>
                    <div class="kpi-value up" id="kpiVolumeVal">
                        <span class="spinner" style="width:16px;height:16px;border-width:1px;"></span>
                    </div>
                    <div class="kpi-delta pos" id="kpiVolumeDelta"></div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl">
                <div class="kpi-card">
                    <div class="kpi-label">SALES_VALUE</div>
                    <div class="kpi-value up" id="kpiSalesVal">—</div>
                    <div class="kpi-delta" id="kpiSalesDelta"></div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl">
                <div class="kpi-card">
                    <div class="kpi-label">PURCHASES</div>
                    <div class="kpi-value" id="kpiPurchasesVal">—</div>
                    <div class="kpi-delta" id="kpiPurchasesDelta"></div>
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl">
                <div class="kpi-card">
                    <div class="kpi-label">ACTIVE_USERS</div>
                    <div class="kpi-value up" id="kpiUsersVal">—</div>
                    <div class="kpi-delta" id="kpiUsersDelta"></div>
                </div>
            </div>

            <div class="col-12 col-md-4 col-xl">
                <div class="kpi-card">
                    <div class="kpi-label">GROWTH_RATE</div>
                    <div class="kpi-value" id="kpiGrowthVal">—</div>
                    <div class="kpi-delta" id="kpiGrowthDelta"></div>
                </div>
            </div>

        </div><!-- /KPI Row -->

        <!-- Charts Row -->
        <div class="row g-3">

            <!-- Line: Sales vs Purchases -->
            <div class="col-12 col-lg-6">
                <div class="terminal-card p-3" style="background:rgba(0,0,0,.3);">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fas fa-chart-line" style="color:var(--term-green);font-size:.75rem;"></i>
                        <small style="font-size:.6rem;color:var(--term-green);letter-spacing:1px;">SALES_VS_PURCHASES</small>
                    </div>
                    <div class="chart-container" style="height:180px;">
                        <canvas id="chartSalesPurchases"></canvas>
                    </div>
                </div>
            </div>

            <!-- Doughnut: Tx Distribution -->
            <div class="col-6 col-lg-3">
                <div class="terminal-card p-3" style="background:rgba(0,0,0,.3);">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fas fa-chart-pie" style="color:var(--term-cyan);font-size:.75rem;"></i>
                        <small style="font-size:.6rem;color:var(--term-cyan);letter-spacing:1px;">TX_TYPES</small>
                    </div>
                    <div class="chart-container" style="height:180px;">
                        <canvas id="chartTxTypes"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bar: Top 5 Users -->
            <div class="col-6 col-lg-3">
                <div class="terminal-card p-3" style="background:rgba(0,0,0,.3);">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fas fa-chart-bar" style="color:var(--term-yellow);font-size:.75rem;"></i>
                        <small style="font-size:.6rem;color:var(--term-yellow);letter-spacing:1px;">TOP_ACTIVE</small>
                    </div>
                    <div class="chart-container" style="height:180px;">
                        <canvas id="chartTopUsers"></canvas>
                    </div>
                </div>
            </div>

        </div><!-- /Charts Row -->
    </div>
</section>
