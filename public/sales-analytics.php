<?php
$current = 'sales-analytics';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header('Location: ../index.php');
    exit;
}

$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics — ChronoSales</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        /* ── Print styles for PDF export ── */
        @media print {
            .sidebar, .topbar, .filter-panel, .export-bar, .menu-toggle { display: none !important; }
            .main  { margin-left: 0 !important; }
            .content { padding: 0 !important; }
            .chart-card, .summary-card { break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="app">

    <?php include 'sidebar.php'; ?>

    <div class="main" id="main">

        <!-- ── Topbar ── -->
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="topbar-breadcrumb">
                <i class="fa-solid fa-chart-mixed"></i>
                <span>Sales Analytics</span>
                <i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--ink-4);"></i>
                <span id="dateRangeLabel" style="color:var(--ink-4);font-size:12px;font-family:'DM Mono',monospace;">—</span>
            </div>
            <div class="topbar-right">
                <div class="topbar-date" id="topbarDate"></div>
                <button class="topbar-btn" id="refreshBtn" title="Refresh data">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>
        </header>

        <!-- ── Analytics Content ── -->
        <div class="content" id="analyticsContent">

            <!-- Loading overlay -->
            <div class="loading-overlay" id="analyticsLoadingOverlay">
                <div class="loading-spinner">
                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                    <span>Loading analytics…</span>
                </div>
            </div>

            <!-- ══ FILTER PANEL ══════════════════════════════════════════ -->
            <div class="filter-panel">
                <div class="filter-panel-header">
                    <div class="filter-panel-title">
                        <i class="fa-solid fa-sliders"></i>
                        Filters &amp; Date Range
                    </div>
                    <button class="filter-reset-btn" id="resetFilterBtn">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </button>
                </div>

                <!-- Preset date range -->
                <div class="filter-group" style="margin-bottom:14px;">
                    <div class="filter-label">Date Range</div>
                    <div class="preset-btn-row" id="presetBtnRow">
                        <button class="preset-btn" data-preset="daily">Today</button>
                        <button class="preset-btn" data-preset="weekly">This Week</button>
                        <button class="preset-btn active" data-preset="monthly">This Month</button>
                        <button class="preset-btn" data-preset="custom">Custom</button>
                    </div>
                    <!-- Custom range inputs (hidden unless Custom selected) -->
                    <div class="custom-range-wrap" id="customRangeWrap">
                        <input type="date" id="dateFrom" class="filter-input" placeholder="From">
                        <span style="font-size:11.5px;color:var(--ink-4);">to</span>
                        <input type="date" id="dateTo"   class="filter-input" placeholder="To">
                    </div>
                </div>

                <!-- Filter selects + status + apply -->
                <div class="filter-grid">

                    <div class="filter-group">
                        <label class="filter-label" for="filterBranch">Branch</label>
                        <select class="filter-select" id="filterBranch">
                            <option value="all">All Branches</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="filterPayment">Payment Method</label>
                        <select class="filter-select" id="filterPayment">
                            <option value="all">All Methods</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="filterDiscount">Discount Type</label>
                        <select class="filter-select" id="filterDiscount">
                            <option value="all">All Types</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <div class="filter-label">Transaction Status</div>
                        <div class="preset-btn-row" id="statusBtnRow">
                            <!-- Populated dynamically from API -->
                        </div>
                    </div>

                    <div class="filter-group" style="align-self:flex-end;">
                        <button class="filter-apply-btn" id="applyFilterBtn">
                            <i class="fa-solid fa-magnifying-glass" style="margin-right:5px;"></i> Apply Filters
                        </button>
                    </div>

                </div>
            </div>

            <!-- ══ EXPORT BAR ════════════════════════════════════════════ -->
            <div class="export-bar">
                <span style="font-size:11.5px;color:var(--ink-4);margin-right:4px;">Export:</span>
                <button class="export-btn" id="exportCsvBtn">
                    <i class="fa-solid fa-file-csv"></i> CSV
                </button>
                <button class="export-btn" id="exportPdfBtn">
                    <i class="fa-solid fa-file-pdf"></i> PDF
                </button>
            </div>

            <!-- ══ SUMMARY METRIC CARDS ══════════════════════════════════ -->
            <div class="summary-grid">

                <div class="summary-card accent">
                    <div class="s-icon"><i class="fa-solid fa-peso-sign"></i></div>
                    <div class="s-label">Total Revenue</div>
                    <div class="s-value" id="sumRevenue">—</div>
                    <div class="s-sub">filtered period</div>
                </div>

                <div class="summary-card">
                    <div class="s-icon"><i class="fa-solid fa-receipt"></i></div>
                    <div class="s-label">Transactions</div>
                    <div class="s-value" id="sumTx">—</div>
                    <div class="s-sub">total count</div>
                </div>

                <div class="summary-card">
                    <div class="s-icon"><i class="fa-solid fa-basket-shopping"></i></div>
                    <div class="s-label">Avg Order Value</div>
                    <div class="s-value" id="sumAOV">—</div>
                    <div class="s-sub">per transaction</div>
                </div>

                <div class="summary-card">
                    <div class="s-icon" style="background:var(--accent-light);color:var(--accent);"><i class="fa-solid fa-tag"></i></div>
                    <div class="s-label">Discounts Given</div>
                    <div class="s-value" id="sumDiscount">—</div>
                    <div class="s-sub">total discount value</div>
                </div>

                <div class="summary-card">
                    <div class="s-icon" style="background:var(--violet-light);color:var(--violet);"><i class="fa-solid fa-percent"></i></div>
                    <div class="s-label">Total VAT</div>
                    <div class="s-value" id="sumVAT">—</div>
                    <div class="s-sub">collected</div>
                </div>

            </div>

            <!-- ══ CHARTS ROW 1: Branch Revenue + Daily Trend ═══════════ -->
            <div class="charts-row">

                <!-- Branch Revenue Bar Chart -->
                <div class="chart-card wide">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-code-branch"></i>
                            Revenue by Branch
                        </div>
                        <div class="chart-metric-toggle" id="branchMetricToggle">
                            <button class="cmt-btn active" data-metric="revenue">Revenue</button>
                            <button class="cmt-btn"        data-metric="tx_count">Txns</button>
                            <button class="cmt-btn"        data-metric="avg_ticket">Avg Ticket</button>
                        </div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="branchChart"></canvas>
                    </div>
                </div>

                <!-- Daily Trend -->
                <div class="chart-card narrow">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-chart-area"></i>
                            Daily Revenue Trend
                        </div>
                        <span class="chart-card-badge">Revenue + Txns</span>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="dailyTrendChart"></canvas>
                    </div>
                </div>

            </div>

            <!-- ══ HEATMAP ════════════════════════════════════════════════ -->
            <div class="chart-card full">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <i class="fa-solid fa-table-cells"></i>
                        Sales Volume Heatmap
                        <span style="font-size:11px;color:var(--ink-4);font-weight:400;">— transactions by Day × Hour</span>
                    </div>
                    <span class="chart-card-badge">Day × Hour</span>
                </div>
                <div class="chart-wrap heatmap" id="heatmapContainer">
                    <!-- Rendered by JS -->
                </div>
            </div>

            <!-- ══ CHARTS ROW 2: Discount Analysis ═══════════════════════ -->
            <div class="charts-row">

                <!-- Discount Analysis -->
                <div class="chart-card wide">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-tags"></i>
                            Discount Analysis
                        </div>
                        <span class="chart-card-badge">By Type</span>
                    </div>
                    <div class="discount-cards" id="discountCards">
                        <!-- Rendered by JS -->
                    </div>
                </div>

                <!-- Quick Summary Callouts -->
                <div class="chart-card narrow">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-lightbulb"></i>
                            Quick Insights
                        </div>
                    </div>
                    <div id="quickInsights" style="display:flex;flex-direction:column;gap:14px;margin-top:6px;">
                        <div class="insight-block" style="background:var(--primary-light);border-radius:10px;padding:14px 16px;">
                            <div style="font-size:10.5px;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;">
                                <i class="fa-solid fa-trophy" style="margin-right:4px;"></i> Top Branch
                            </div>
                            <div id="insightTopBranch" style="font-size:13px;font-weight:600;color:var(--ink);">—</div>
                            <div id="insightTopBranchRev" style="font-size:12px;color:var(--ink-3);margin-top:2px;font-family:'DM Mono',monospace;">—</div>
                        </div>
                        <div class="insight-block" style="background:var(--accent-light);border-radius:10px;padding:14px 16px;">
                            <div style="font-size:10.5px;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;">
                                <i class="fa-solid fa-star" style="margin-right:4px;"></i> Top Customer
                            </div>
                            <div id="insightTopCustomer" style="font-size:13px;font-weight:600;color:var(--ink);">—</div>
                            <div id="insightTopCustomerSpent" style="font-size:12px;color:var(--ink-3);margin-top:2px;font-family:'DM Mono',monospace;">—</div>
                        </div>
                        <div class="insight-block" style="background:var(--violet-light);border-radius:10px;padding:14px 16px;">
                            <div style="font-size:10.5px;font-weight:700;color:var(--violet);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;">
                                <i class="fa-solid fa-credit-card" style="margin-right:4px;"></i> Top Payment
                            </div>
                            <div id="insightTopPayment" style="font-size:13px;font-weight:600;color:var(--ink);">—</div>
                            <div id="insightTopPaymentPct" style="font-size:12px;color:var(--ink-3);margin-top:2px;font-family:'DM Mono',monospace;">—</div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ══ TOP 10 CUSTOMERS TABLE ═════════════════════════════════ -->
            <div class="chart-card full" style="margin-bottom:24px;">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <i class="fa-solid fa-users"></i>
                        Top 10 Customers
                    </div>
                    <span class="chart-card-badge">By Total Spend</span>
                </div>
                <div class="customers-table-wrap">
                    <table class="customers-table" id="topCustomersTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Transactions</th>
                                <th>Total Spent</th>
                                <th>Avg Ticket</th>
                                <th>Last Purchase</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6" style="text-align:center;color:var(--ink-4);padding:24px;">Loading…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /app -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/analytics.js"></script>

<!-- REPLACE the entire inline <script> block at the bottom WITH: -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const _orig = window.renderAll;
    window.renderAll = function(data) {
        _orig(data);

        // ── Top Branch ────────────────────────────────────────────
        const topBranch = data.branch_revenue?.[0];
        if (topBranch) {
            document.getElementById('insightTopBranch').textContent    = topBranch.name;
            document.getElementById('insightTopBranchRev').textContent = '₱' + Number(topBranch.revenue).toLocaleString('en-PH', { minimumFractionDigits: 0 });
        }

        // ── Top Customer ──────────────────────────────────────────
        const topCust = data.top_customers?.[0];
        if (topCust) {
            document.getElementById('insightTopCustomer').textContent      = topCust.name;
            document.getElementById('insightTopCustomerSpent').textContent = '₱' + Number(topCust.total_spent).toLocaleString('en-PH', { minimumFractionDigits: 0 }) + ' · ' + topCust.tx_count + ' txns';
        }

        // ── Top Payment ───────────────────────────────────────────
        const payments  = data.payment_breakdown ?? [];
        const topPay    = payments[0];
        if (topPay) {
            const totalRev  = payments.reduce((s, p) => s + p.revenue, 0);
            const pct       = totalRev > 0 ? ((topPay.revenue / totalRev) * 100).toFixed(1) : '0';
            document.getElementById('insightTopPayment').textContent    = topPay.method;
            document.getElementById('insightTopPaymentPct').textContent = pct + '% of revenue · ' + topPay.tx_count.toLocaleString() + ' txns';
        }
    };
});
</script>

</body>
</html>