<?php
$current = 'reports';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION["user_id"])) { header('Location: ../index.php'); exit; }
$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — ChronoSales</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="stylesheet" href="../assets/css/reports.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body>
<div class="app">
    <?php include 'sidebar.php'; ?>
    <div class="main" id="main">

        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="topbar-breadcrumb">
                <i class="fa-solid fa-file-chart-column"></i>
                <span>Reports</span>
                <i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--ink-4);"></i>
                <span id="dateRangeLabel" style="color:var(--ink-4);font-size:12px;font-family:'DM Mono',monospace;">—</span>
            </div>
            <div class="topbar-right">
                <div class="topbar-date" id="topbarDate"></div>
                <button class="topbar-btn" id="refreshBtn" title="Refresh"><i class="fa-solid fa-arrows-rotate"></i></button>
            </div>
        </header>

        <div class="content" id="reportsContent">

            <div class="loading-overlay" id="reportsLoadingOverlay">
                <div class="loading-spinner">
                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                    <span>Generating report…</span>
                </div>
            </div>

            <!-- FILTER PANEL -->
            <div class="filter-panel">
                <div class="filter-panel-header">
                    <div class="filter-panel-title"><i class="fa-solid fa-sliders"></i> Report Filters &amp; Date Range</div>
                    <button class="filter-reset-btn" id="resetFilterBtn"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                </div>
                <div class="filter-group" style="margin-bottom:14px;">
                    <div class="filter-label">Date Range</div>
                    <div class="preset-btn-row" id="presetBtnRow">
                        <button class="preset-btn" data-preset="daily">Today</button>
                        <button class="preset-btn" data-preset="weekly">This Week</button>
                        <button class="preset-btn active" data-preset="monthly">This Month</button>
                        <button class="preset-btn" data-preset="quarterly">This Quarter</button>
                        <button class="preset-btn" data-preset="annual">This Year</button>
                        <button class="preset-btn" data-preset="custom">Custom</button>
                    </div>
                    <div class="custom-range-wrap" id="customRangeWrap">
                        <input type="date" id="dateFrom" class="filter-input">
                        <span style="font-size:11.5px;color:var(--ink-4);">TO</span>
                        <input type="date" id="dateTo" class="filter-input">
                    </div>
                </div>
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
                    <div class="filter-group" style="align-self:flex-end; justify-self:start;">
                        <button class="filter-apply-btn" id="applyFilterBtn">
                            <i class="fa-solid fa-file-chart-column" style="margin-right:5px;"></i> Generate Report
                        </button>
                    </div>
                </div>
            </div>

            <!-- REPORT TYPE TABS -->
            <div class="report-type-bar">
                <span class="report-type-label"><i class="fa-solid fa-table-list" style="margin-right:4px;"></i>Report</span>
                <button class="report-tab active" data-report="revenue"><i class="fa-solid fa-sack-dollar"></i> Revenue Summary</button>
                <button class="report-tab" data-report="vat"><i class="fa-solid fa-percent"></i> VAT Summary</button>
                <button class="report-tab" data-report="discount"><i class="fa-solid fa-tag"></i> Discount Cost</button>
                <button class="report-tab" data-report="comparison"><i class="fa-solid fa-code-compare"></i> Comparison</button>
                <button class="report-tab" data-report="integrity"><i class="fa-solid fa-shield-check"></i> Data Integrity</button>
                <button class="report-tab" data-report="schedule"><i class="fa-solid fa-calendar-clock"></i> Scheduled</button>
            </div>

            <!-- REVENUE SUMMARY -->
            <div class="report-section active" id="section-revenue">
                <div class="report-action-bar">
                    <div class="report-action-title"><i class="fa-solid fa-sack-dollar"></i> Revenue Summary Report <span class="period-badge" id="revPeriodBadge">—</span></div>
                    <div class="report-btn-group">
                        <button class="report-btn secondary" id="revExportCsvBtn"><i class="fa-solid fa-file-csv"></i> CSV</button>
                        <button class="report-btn primary" id="revExportPdfBtn"><i class="fa-solid fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="rev-group-tabs">
                    <button class="rev-group-tab active" data-group="monthly">Monthly</button>
                    <button class="rev-group-tab" data-group="quarterly">Quarterly</button>
                    <button class="rev-group-tab" data-group="annual">Annual</button>
                </div>
                <div class="summary-grid" style="margin-bottom:20px;">
                    <div class="summary-card accent">
                        <div class="s-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                        <div class="s-label">Total Revenue</div>
                        <div class="s-value" id="revTotal">—</div>
                        <div class="s-sub">period total</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-receipt"></i></div>
                        <div class="s-label">Transactions</div>
                        <div class="s-value" id="revTxCount">—</div>
                        <div class="s-sub">OK status</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-chart-simple"></i></div>
                        <div class="s-label">Avg. Order Value</div>
                        <div class="s-value" id="revAOV">—</div>
                        <div class="s-sub">per transaction</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-building"></i></div>
                        <div class="s-label">Active Branches</div>
                        <div class="s-value" id="revBranchCount">—</div>
                        <div class="s-sub">with transactions</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
                        <div class="s-label">Top Day Revenue</div>
                        <div class="s-value" id="revTopDay">—</div>
                        <div class="s-sub" id="revTopDaySub">best day</div>
                    </div>
                </div>
                <div class="chart-card full" style="margin-bottom:20px;">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fa-solid fa-chart-column"></i> Revenue Trend</div>
                        <span class="chart-card-badge" id="revChartBadge">MONTHLY</span>
                    </div>
                    <div class="chart-wrap" style="height:260px;"><canvas id="revTrendChart"></canvas></div>
                </div>
                <div class="report-table-card">
                    <div class="report-table-card-header">
                        <div class="report-table-title"><i class="fa-solid fa-table"></i> Period Breakdown</div>
                    </div>
                    <div class="rtable-wrap">
                        <table class="rtable">
                            <thead><tr><th>Period</th><th class="num">Transactions</th><th class="num">Gross Revenue</th><th class="num">Discounts</th><th class="num">VAT</th><th class="num">Net Revenue</th><th class="num">Avg. Ticket</th></tr></thead>
                            <tbody id="revTableBody"><tr><td colspan="7" style="padding:40px;text-align:center;color:var(--ink-4);">Generate a report to see data</td></tr></tbody>
                            <tfoot id="revTableFoot"></tfoot>
                        </table>
                    </div>
                </div>
                <div class="report-table-card">
                    <div class="report-table-card-header">
                        <div class="report-table-title"><i class="fa-solid fa-building"></i> Revenue by Branch</div>
                    </div>
                    <div class="rtable-wrap">
                        <table class="rtable">
                            <thead><tr><th>#</th><th>Branch</th><th class="num">Transactions</th><th class="num">Revenue</th><th class="num">Discounts</th><th class="num">VAT</th><th class="num">Avg. Ticket</th><th class="num">% of Total</th></tr></thead>
                            <tbody id="revBranchTableBody"><tr><td colspan="8" style="padding:30px;text-align:center;color:var(--ink-4);">—</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- VAT SUMMARY -->
            <div class="report-section" id="section-vat">
                <div class="report-action-bar">
                    <div class="report-action-title"><i class="fa-solid fa-percent"></i> VAT Summary Report <span class="period-badge" id="vatPeriodBadge">—</span></div>
                    <div class="report-btn-group">
                        <button class="report-btn secondary" id="vatExportCsvBtn"><i class="fa-solid fa-file-csv"></i> CSV</button>
                        <button class="report-btn primary" id="vatExportPdfBtn"><i class="fa-solid fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="summary-grid" style="margin-bottom:20px;">
                    <div class="summary-card accent">
                        <div class="s-icon"><i class="fa-solid fa-coins"></i></div>
                        <div class="s-label">Total VAT Collected</div>
                        <div class="s-value" id="vatTotal">—</div>
                        <div class="s-sub">12% VAT-inclusive</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-receipt"></i></div>
                        <div class="s-label">VAT-able Transactions</div>
                        <div class="s-value" id="vatTxCount">—</div>
                        <div class="s-sub">with VAT &gt; 0</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-building"></i></div>
                        <div class="s-label">Branches Covered</div>
                        <div class="s-value" id="vatBranchCount">—</div>
                        <div class="s-sub">with VAT entries</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-calculator"></i></div>
                        <div class="s-label">Avg VAT / Txn</div>
                        <div class="s-value" id="vatAvg">—</div>
                        <div class="s-sub">per vatable txn</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-trophy"></i></div>
                        <div class="s-label">Top VAT Branch</div>
                        <div class="s-value" id="vatTopBranch" style="font-size:13px;line-height:1.2;">—</div>
                        <div class="s-sub" id="vatTopBranchAmt">—</div>
                    </div>
                </div>
                <div class="report-table-card">
                    <div class="report-table-card-header"><div class="report-table-title"><i class="fa-solid fa-table"></i> VAT Breakdown by Branch</div></div>
                    <div class="rtable-wrap">
                        <table class="rtable">
                            <thead><tr><th>#</th><th>Branch</th><th class="num">VAT Txns</th><th class="num">Total Gross</th><th class="num">VAT Amount</th><th class="num">Net of VAT</th><th class="num">Avg VAT/Txn</th><th class="num">% of Total</th></tr></thead>
                            <tbody id="vatBranchTableBody"><tr><td colspan="8" style="padding:40px;text-align:center;color:var(--ink-4);">Generate a report to see data</td></tr></tbody>
                            <tfoot id="vatTableFoot"></tfoot>
                        </table>
                    </div>
                </div>
                <div class="report-table-card">
                    <div class="report-table-card-header"><div class="report-table-title"><i class="fa-solid fa-calendar"></i> VAT by Month</div></div>
                    <div class="rtable-wrap">
                        <table class="rtable">
                            <thead><tr><th>Month</th><th class="num">Transactions</th><th class="num">Total Revenue</th><th class="num">VAT Collected</th><th class="num">Net of VAT</th></tr></thead>
                            <tbody id="vatPeriodTableBody"><tr><td colspan="5" style="padding:30px;text-align:center;color:var(--ink-4);">—</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- DISCOUNT COST -->
            <div class="report-section" id="section-discount">
                <div class="report-action-bar">
                    <div class="report-action-title"><i class="fa-solid fa-tag"></i> Discount Cost Report <span class="period-badge" id="discPeriodBadge">—</span></div>
                    <div class="report-btn-group">
                        <button class="report-btn secondary" id="discExportCsvBtn"><i class="fa-solid fa-file-csv"></i> CSV</button>
                        <button class="report-btn primary" id="discExportPdfBtn"><i class="fa-solid fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="summary-grid" style="margin-bottom:20px;">
                    <div class="summary-card accent">
                        <div class="s-icon"><i class="fa-solid fa-tags"></i></div>
                        <div class="s-label">Total Discount Cost</div>
                        <div class="s-value" id="discTotal">—</div>
                        <div class="s-sub">all discounts applied</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-receipt"></i></div>
                        <div class="s-label">Discounted Txns</div>
                        <div class="s-value" id="discTxCount">—</div>
                        <div class="s-sub">with discount &gt; 0</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-chart-pie"></i></div>
                        <div class="s-label">Discount Rate</div>
                        <div class="s-value" id="discRate">—</div>
                        <div class="s-sub">% of gross revenue</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-calculator"></i></div>
                        <div class="s-label">Avg Discount / Txn</div>
                        <div class="s-value" id="discAvg">—</div>
                        <div class="s-sub">per discounted txn</div>
                    </div>
                    <div class="summary-card">
                        <div class="s-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div class="s-label">Highest Single Discount</div>
                        <div class="s-value" id="discMax">—</div>
                        <div class="s-sub" id="discMaxInv">—</div>
                    </div>
                </div>
                <div class="charts-row" style="margin-bottom:20px;">
                    <div class="chart-card wide">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="fa-solid fa-chart-bar"></i> Monthly Discount Trend</div></div>
                        <div class="chart-wrap" style="height:220px;"><canvas id="discTrendChart"></canvas></div>
                    </div>
                    <div class="chart-card narrow">
                        <div class="chart-card-header"><div class="chart-card-title"><i class="fa-solid fa-chart-pie"></i> By Discount Type</div></div>
                        <div class="chart-wrap" style="height:220px;"><canvas id="discTypeChart"></canvas></div>
                    </div>
                </div>
                <div class="report-table-card">
                    <div class="report-table-card-header"><div class="report-table-title"><i class="fa-solid fa-table"></i> Discount by Branch</div></div>
                    <div class="rtable-wrap">
                        <table class="rtable">
                            <thead><tr><th>#</th><th>Branch</th><th class="num">Total Txns</th><th class="num">Discounted</th><th class="num">Discount Value</th><th class="num">Gross Revenue</th><th class="num">Discount %</th><th class="num">Avg Discount</th></tr></thead>
                            <tbody id="discBranchTableBody"><tr><td colspan="8" style="padding:40px;text-align:center;color:var(--ink-4);">Generate a report to see data</td></tr></tbody>
                            <tfoot id="discTableFoot"></tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- COMPARISON -->
            <div class="report-section" id="section-comparison">
                <div class="report-action-bar">
                    <div class="report-action-title"><i class="fa-solid fa-code-compare"></i> Period-over-Period Comparison <span class="period-badge" id="cmpPeriodBadge">—</span></div>
                    <div class="report-btn-group">
                        <button class="report-btn primary" id="cmpExportPdfBtn"><i class="fa-solid fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <p style="font-size:12.5px;color:var(--ink-4);margin:0 0 18px;">
                    <i class="fa-solid fa-circle-info" style="color:var(--primary-mid);margin-right:5px;"></i>
                    Compares the selected period against the preceding equal-length period automatically.
                </p>
                <div class="comparison-grid" id="cmpMetricGrid">
                    <div class="comparison-card" style="opacity:0.4;text-align:center;padding:50px;grid-column:1/-1;">
                        <i class="fa-solid fa-arrow-right-arrow-left" style="font-size:28px;color:var(--border);"></i>
                        <p style="margin:12px 0 0;font-size:12.5px;color:var(--ink-4);">Generate a report to see comparison</p>
                    </div>
                </div>
                <div class="chart-card full" style="margin-bottom:20px;">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fa-solid fa-chart-line"></i> Revenue: Current vs Previous Period</div>
                    </div>
                    <div class="chart-wrap" style="height:260px;"><canvas id="cmpTrendChart"></canvas></div>
                </div>
                <div class="report-table-card">
                    <div class="report-table-card-header"><div class="report-table-title"><i class="fa-solid fa-table"></i> Branch-level Comparison</div></div>
                    <div class="rtable-wrap">
                        <table class="rtable">
                            <thead><tr><th>Branch</th><th class="num">Curr. Revenue</th><th class="num">Prev. Revenue</th><th class="num">Δ Amount</th><th class="num">Δ %</th><th class="num">Curr. VAT</th><th class="num">Prev. VAT</th><th class="num">Curr. Disc.</th><th class="num">Prev. Disc.</th></tr></thead>
                            <tbody id="cmpBranchTableBody"><tr><td colspan="9" style="padding:40px;text-align:center;color:var(--ink-4);">Generate a report to see data</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- DATA INTEGRITY -->
            <div class="report-section" id="section-integrity">
                <div class="report-action-bar">
                    <div class="report-action-title"><i class="fa-solid fa-shield-check"></i> Data Integrity Check <span class="period-badge" id="intPeriodBadge">—</span></div>
                    <div class="report-btn-group">
                        <button class="report-btn primary" id="intRunCheckBtn"><i class="fa-solid fa-magnifying-glass-chart"></i> Run Check</button>
                        <button class="report-btn secondary" id="intExportPdfBtn"><i class="fa-solid fa-file-pdf"></i> Export</button>
                    </div>
                </div>
                <div class="integrity-grid">
                    <div class="integrity-card ok">
                        <div class="integrity-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="integrity-body"><div class="integrity-count" id="intCleanCount">—</div><div class="integrity-label">Clean Records</div></div>
                    </div>
                    <div class="integrity-card warn">
                        <div class="integrity-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div class="integrity-body"><div class="integrity-count" id="intWarnCount">—</div><div class="integrity-label">Warnings</div></div>
                    </div>
                    <div class="integrity-card error">
                        <div class="integrity-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                        <div class="integrity-body"><div class="integrity-count" id="intErrorCount">—</div><div class="integrity-label">Critical Issues</div></div>
                    </div>
                    <div class="integrity-card">
                        <div class="integrity-icon"><i class="fa-solid fa-database"></i></div>
                        <div class="integrity-body"><div class="integrity-count" id="intTotalCount">—</div><div class="integrity-label">Total Checked</div></div>
                    </div>
                </div>
                <div class="report-table-card">
                    <div class="report-table-card-header">
                        <div class="report-table-title"><i class="fa-solid fa-flag"></i> Flagged Issues</div>
                        <span style="font-size:11.5px;color:var(--ink-4);">All detected anomalies</span>
                    </div>
                    <div id="intFlagsList">
                        <div class="empty-state">
                            <i class="fa-solid fa-shield-check"></i>
                            <h4>No check run yet</h4>
                            <p>Click "Run Check" to scan for data anomalies in the selected period.</p>
                        </div>
                    </div>
                </div>
                <div class="report-table-card" id="intSuspiciousCard" style="display:none;">
                    <div class="report-table-card-header"><div class="report-table-title"><i class="fa-solid fa-magnifying-glass"></i> Suspicious Transactions</div></div>
                    <div class="rtable-wrap">
                        <table class="rtable">
                            <thead><tr><th>Invoice #</th><th>Date</th><th>Branch</th><th class="num">Grand Total</th><th class="num">VAT</th><th class="num">Discount</th><th>Status</th><th>Issue</th></tr></thead>
                            <tbody id="intSuspiciousBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SCHEDULED REPORTS -->
            <div class="report-section" id="section-schedule">
                <div class="report-action-bar">
                    <div class="report-action-title"><i class="fa-solid fa-calendar-clock"></i> Scheduled Report Delivery</div>
                    <div class="report-btn-group">
                        <button class="report-btn primary" id="addScheduleBtn"><i class="fa-solid fa-plus"></i> New Schedule</button>
                    </div>
                </div>
                <p style="font-size:12.5px;color:var(--ink-4);margin:0 0 20px;">
                    <i class="fa-solid fa-circle-info" style="color:var(--primary-mid);margin-right:5px;"></i>
                    Reports are auto-generated and emailed to recipients. Requires cron:
                    <code style="background:var(--bg);padding:2px 8px;border-radius:5px;font-family:'DM Mono',monospace;font-size:11px;">php report_cron.php</code>
                </p>
                <div class="schedule-grid" id="scheduleGrid">
                    <div class="empty-state" style="grid-column:1/-1;">
                        <i class="fa-solid fa-calendar-clock"></i>
                        <h4>No schedules configured</h4>
                        <p>Click "New Schedule" to set up automated report delivery.</p>
                    </div>
                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /app -->

<!-- ADD SCHEDULE MODAL -->
<div class="modal-backdrop" id="scheduleModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-calendar-plus" style="color:var(--primary-mid);margin-right:8px;"></i>New Report Schedule</div>
            <button class="modal-close" id="scheduleModalClose"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-field">
            <label class="modal-label">Schedule Name</label>
            <input type="text" class="modal-input" id="schName" placeholder="e.g. Weekly Revenue Report">
        </div>
        <div class="modal-row">
            <div class="modal-field">
                <label class="modal-label">Report Type</label>
                <select class="modal-select" id="schReportType">
                    <option value="revenue">Revenue Summary</option>
                    <option value="vat">VAT Summary</option>
                    <option value="discount">Discount Cost</option>
                    <option value="comparison">Comparison</option>
                </select>
            </div>
            <div class="modal-field">
                <label class="modal-label">Frequency</label>
                <select class="modal-select" id="schFrequency">
                    <option value="weekly">Weekly (Every Monday)</option>
                    <option value="monthly">Monthly (1st of Month)</option>
                </select>
            </div>
        </div>
        <div class="modal-field">
            <label class="modal-label">Recipient Email(s)</label>
            <input type="text" class="modal-input" id="schEmails" placeholder="admin@company.com, manager@company.com">
        </div>
        <div class="modal-field">
            <label class="modal-label">Branch Filter</label>
            <select class="modal-select" id="schBranch">
                <option value="all">All Branches</option>
            </select>
        </div>
        <div class="modal-field">
            <label class="modal-label">Export Format</label>
            <select class="modal-select" id="schFormat">
                <option value="pdf">PDF Report</option>
                <option value="csv">CSV Data</option>
                <option value="both">Both PDF + CSV</option>
            </select>
        </div>
        <div class="modal-footer">
            <button class="report-btn secondary" id="scheduleModalCancel">Cancel</button>
            <button class="report-btn primary" id="scheduleModalSave"><i class="fa-solid fa-floppy-disk"></i> Save Schedule</button>
        </div>
    </div>
</div>

<!-- PDF render target (hidden off-screen) -->
<div id="pdfReportTarget"></div>
<div class="toast-container" id="toastContainer"></div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/reports.js"></script>
</body>
</html>