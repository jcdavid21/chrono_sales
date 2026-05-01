<?php
$current = 'customer-insights';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header('Location: ../index.php');
    exit;
}

$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
$user_role = $_SESSION['user_role'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Insights — ChronoSales</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ── CI-specific variables ─────────────────────────────────────────── */
        :root {
            --card-radius: 14px;
            --c-new:       #0d9488;
            --c-returning: #2563eb;
            --c-gold:      #d97706;
            --c-silver:    #64748b;
            --c-bronze:    #b45309;
        }

        /* ── Filter bar ────────────────────────────────────────────────────── */
        .bp-filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .bp-filter-bar label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--ink-4);
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .bp-date-input {
            padding: 7px 11px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--card);
            font-size: 12.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            transition: border-color .15s;
        }

        .bp-date-input:focus { outline: none; border-color: var(--primary-mid); }

        .dm-filter-select {
            padding: 7px 28px 7px 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--card);
            font-size: 12.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%239ca3af' d='M0 0l5 6 5-6z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        .dm-filter-select:focus { outline: none; border-color: var(--primary-mid); }

        .bp-text-input {
            padding: 7px 11px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--card);
            font-size: 12.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            width: 110px;
            transition: border-color .15s;
        }

        .bp-text-input:focus { outline: none; border-color: var(--primary-mid); }

        .btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px; background: var(--primary);
            color: #fff; border: none; font-size: 12.5px; font-weight: 600;
            cursor: pointer; font-family: 'DM Sans', sans-serif; transition: opacity .15s;
        }

        .btn-primary:hover { opacity: .88; }

        .btn-secondary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px; border: 1px solid var(--border);
            background: var(--card); font-size: 12.5px; font-weight: 500;
            color: var(--ink-3); cursor: pointer; font-family: 'DM Sans', sans-serif;
            transition: all .15s;
        }

        .btn-secondary:hover {
            border-color: var(--primary-mid);
            color: var(--primary);
            background: var(--primary-light);
        }

        /* ── KPI strip ─────────────────────────────────────────────────────── */
        .bp-kpi-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .bp-kpi-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            padding: 16px 18px;
            box-shadow: var(--card-shadow);
        }

        .bp-kpi-label {
            font-size: 10.5px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .07em; color: var(--ink-4); margin-bottom: 6px;
            display: flex; align-items: center; gap: 6px;
        }

        .bp-kpi-label i { color: var(--primary-mid); }

        .bp-kpi-value {
            font-size: 20px; font-weight: 700;
            color: var(--ink); font-family: 'DM Mono', monospace;
        }

        .bp-kpi-sub { font-size: 11px; color: var(--ink-4); margin-top: 3px; }

        /* ── Chart cards ───────────────────────────────────────────────────── */
        .bp-chart-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            padding: 18px 20px;
            box-shadow: var(--card-shadow);
        }

        .bp-chart-title {
            font-size: 12.5px; font-weight: 600; color: var(--ink);
            margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
        }

        .bp-chart-title i { color: var(--primary-mid); font-size: 13px; }

        .chart-canvas-wrap { position: relative; width: 100%; }
        .chart-canvas-wrap canvas { max-height: 280px; }

        /* ── Donut ─────────────────────────────────────────────────────────── */
        .donut-wrap {
            position: relative;
            max-width: 220px;
            margin: 0 auto;
        }

        .donut-center {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            pointer-events: none;
        }

        .donut-center-val { font-size: 16px; font-weight: 700; color: var(--ink); font-family: 'DM Mono', monospace; }
        .donut-center-lbl { font-size: 10px; color: var(--ink-4); text-align: center; }

        /* ── Grid layouts ──────────────────────────────────────────────────── */
        .ci-charts-row {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 16px;
            margin-bottom: 22px;
        }

        .ci-heatmap-row {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 16px;
            margin-bottom: 22px;
        }

        .ci-full { margin-bottom: 22px; }

        /* ── Legend ────────────────────────────────────────────────────────── */
        .pi-legend { margin-top: 14px; }

        .pi-legend-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 5px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px;
        }

        .pi-legend-item:last-child { border-bottom: none; }

        .pi-legend-dot {
            width: 10px; height: 10px; border-radius: 50%;
            flex-shrink: 0; margin-right: 8px;
        }

        .pi-legend-label { display: flex; align-items: center; flex: 1; color: var(--ink-2); }
        .pi-legend-pct   { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--ink-3); margin-left: 8px; }
        .pi-legend-val   { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700; color: var(--ink); }

        /* ── Leaderboard ───────────────────────────────────────────────────── */
        .leaderboard-list { margin-top: 4px; }

        .lb-item {
            display: flex; align-items: center; gap: 12px;
            padding: 9px 0; border-bottom: 1px solid #f1f5f9;
        }

        .lb-item:last-child { border-bottom: none; }

        .lb-rank {
            width: 24px; height: 24px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; flex-shrink: 0;
            font-family: 'DM Mono', monospace;
        }

        .lb-rank.gold   { background: #fef3c7; color: #b45309; }
        .lb-rank.silver { background: #f1f5f9; color: #475569; }
        .lb-rank.bronze { background: #fdf6ec; color: #b45309; }
        .lb-rank.other  { background: #f8fafc; color: var(--ink-3); }

        .lb-name {
            flex: 1; font-size: 12.5px; color: var(--ink-2);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .lb-meta { font-size: 11px; color: var(--ink-4); margin-top: 1px; }

        .lb-spend {
            font-family: 'DM Mono', monospace; font-size: 12.5px;
            font-weight: 700; color: var(--ink); text-align: right;
        }

        .lb-pct {
            font-size: 10.5px; color: var(--ink-4);
            font-family: 'DM Mono', monospace; text-align: right; margin-top: 1px;
        }

        .lb-bar-wrap {
            width: 60px; height: 4px; background: #f1f5f9;
            border-radius: 99px; overflow: hidden; flex-shrink: 0;
        }

        .lb-bar { height: 100%; border-radius: 99px; background: var(--primary-mid); }

        /* ── Heatmap ───────────────────────────────────────────────────────── */
        .heatmap-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 8px;
        }

        .heatmap-row { display: flex; gap: 6px; align-items: center; }

        .heatmap-label {
            font-size: 11px; color: var(--ink-4); width: 110px;
            flex-shrink: 0; white-space: nowrap; overflow: hidden;
            text-overflow: ellipsis; text-align: right; padding-right: 8px;
        }

        .heatmap-cell {
            flex: 1; height: 28px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 10.5px; font-family: 'DM Mono', monospace;
            font-weight: 600; transition: opacity .15s; cursor: default;
            position: relative;
        }

        .heatmap-cell:hover::after {
            content: attr(data-tip);
            position: absolute; bottom: calc(100% + 6px); left: 50%;
            transform: translateX(-50%);
            background: rgba(15,23,42,.88); color: #fff;
            font-size: 11px; padding: 4px 8px; border-radius: 6px;
            white-space: nowrap; pointer-events: none; z-index: 10;
        }

        /* ── Table ─────────────────────────────────────────────────────────── */
        .bp-table-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--card-radius); overflow: hidden;
            box-shadow: var(--card-shadow); margin-bottom: 22px;
        }

        .bp-table-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; border-bottom: 1px solid var(--border);
            flex-wrap: wrap; gap: 10px;
        }

        .bp-table-header-title {
            font-size: 13px; font-weight: 600; color: var(--ink);
            display: flex; align-items: center; gap: 8px;
        }

        .bp-table-header-title i { color: var(--primary-mid); }

        .dm-search-wrap { position: relative; min-width: 220px; }

        .dm-search-wrap i {
            position: absolute; left: 10px; top: 50%;
            transform: translateY(-50%); font-size: 11.5px; color: var(--ink-4);
        }

        .dm-search {
            width: 100%; padding: 7px 10px 7px 28px; border: 1px solid var(--border);
            border-radius: 8px; background: var(--bg); font-size: 12.5px;
            font-family: 'DM Sans', sans-serif; color: var(--ink); transition: border-color .15s;
            box-sizing: border-box;
        }

        .dm-search:focus { outline: none; border-color: var(--primary-mid); }

        .dm-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }

        .dm-table th {
            padding: 10px 14px; text-align: left; font-size: 10.5px;
            font-weight: 600; text-transform: uppercase; letter-spacing: .07em;
            color: var(--ink-4); border-bottom: 1px solid var(--border);
            background: #fafafa; white-space: nowrap; cursor: pointer;
            user-select: none;
        }

        .dm-table th:hover { color: var(--primary); }
        .dm-table th.sorted { color: var(--primary); }
        .dm-table th .sort-icon { font-size: 9px; margin-left: 4px; opacity: .5; }
        .dm-table th.sorted .sort-icon { opacity: 1; }

        .dm-table td {
            padding: 11px 14px; color: var(--ink-2);
            border-bottom: 1px solid #f1f5f9; vertical-align: middle;
        }

        .dm-table tr:last-child td { border-bottom: none; }
        .dm-table td.mono { font-family: 'DM Mono', monospace; font-size: 12px; }

        /* ── Pagination ────────────────────────────────────────────────────── */
        .dm-pagination {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 18px; border-top: 1px solid var(--border);
            background: #fafafa; flex-wrap: wrap; gap: 8px;
        }

        .page-info { font-size: 12px; color: var(--ink-4); font-family: 'DM Mono', monospace; }

        .page-btns { display: flex; gap: 4px; align-items: center; }

        .page-btn {
            min-width: 30px; height: 30px; border-radius: 6px;
            border: 1px solid var(--border); background: var(--card);
            font-size: 12px; color: var(--ink-3); cursor: pointer;
            font-family: 'DM Mono', monospace; transition: all 0.15s;
            display: inline-flex; align-items: center; justify-content: center; padding: 0 6px;
        }

        .page-btn:hover { border-color: var(--primary-mid); color: var(--primary); }
        .page-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .page-btn:disabled { opacity: 0.35; cursor: not-allowed; }

        /* ── Spend bar in table ────────────────────────────────────────────── */
        .spend-bar-wrap {
            display: flex; align-items: center; gap: 8px;
        }

        .spend-bar-bg {
            flex: 1; height: 5px; background: #f1f5f9; border-radius: 99px; overflow: hidden;
        }

        .spend-bar-fill { height: 100%; border-radius: 99px; background: var(--primary-mid); }

        /* ── Loading overlay ───────────────────────────────────────────────── */
        .loading-overlay {
            position: absolute; inset: 0; background: rgba(255,255,255,.75);
            z-index: 5; border-radius: var(--card-radius);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; color: var(--ink-3); gap: 8px;
        }

        .loading-overlay.hidden { display: none; }

        /* ── Empty state ───────────────────────────────────────────────────── */
        .empty-state { padding: 48px 24px; text-align: center; }
        .empty-state i { font-size: 32px; color: var(--ink-4); margin-bottom: 12px; display: block; }
        .empty-state p { font-size: 13px; color: var(--ink-3); }

        /* ── Toast ─────────────────────────────────────────────────────────── */
        #toast-container {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            display: flex; flex-direction: column; gap: 8px;
        }

        .toast {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; border-radius: 10px; min-width: 260px;
            box-shadow: 0 4px 20px rgba(0,0,0,.15); font-size: 13px;
            font-family: 'DM Sans', sans-serif; font-weight: 500;
            animation: toastIn .25s ease;
        }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(20px); }
            to   { opacity: 1; transform: none; }
        }

        .toast.success { background: var(--success); color: #fff; }
        .toast.error   { background: var(--danger);  color: #fff; }
        .toast.info    { background: var(--primary);  color: #fff; }

        /* ── Skeleton shimmer ──────────────────────────────────────────────── */
        .skel-line {
            height: 14px; border-radius: 6px;
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%; animation: shimmer 1.4s infinite;
        }

        @keyframes shimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* ── Responsive ────────────────────────────────────────────────────── */
        @media (max-width: 960px) {
            .ci-charts-row  { grid-template-columns: 1fr; }
            .ci-heatmap-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .bp-kpi-strip { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>

<body>
    <div class="app">

        <?php include 'sidebar.php'; ?>

        <div class="main" id="main">

            <!-- ── Topbar ──────────────────────────────────────────────── -->
            <header class="topbar">
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-breadcrumb">
                    <i class="fa-solid fa-users"></i>
                    <span>Customer Insights</span>
                </div>
                <div class="topbar-right">
                    <div class="topbar-date" id="topbarDate"></div>
                    <button class="topbar-btn" id="refreshBtn" title="Refresh" onclick="loadAll()">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </button>
                </div>
            </header>

            <!-- ── Content ─────────────────────────────────────────────── -->
            <div class="content">

                <!-- Page header -->
                <div style="margin-bottom:18px;">
                    <div style="font-size:18px;font-weight:600;color:var(--ink);margin-bottom:4px;">
                        <i class="fa-solid fa-users" style="color:var(--primary-mid);margin-right:8px;"></i>
                        Customer Insights
                    </div>
                    <p style="font-size:13px;color:var(--ink-3);">
                        Understand customer behavior — top spenders, visit frequency, new vs returning ratios, and branch-level spend distribution.
                    </p>
                </div>

                <!-- ── Filter bar ──────────────────────────────────────── -->
                <div class="bp-filter-bar" id="filterBar">
                    <label>From</label>
                    <input type="date" id="dateFrom" class="bp-date-input">
                    <label>To</label>
                    <input type="date" id="dateTo" class="bp-date-input">

                    <select class="dm-filter-select" id="filterBranch">
                        <option value="all">All Branches</option>
                    </select>

                    <div class="dm-search-wrap" style="min-width:180px;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="filterName" class="dm-search"
                               placeholder="Search customer…" oninput="onNameSearch()">
                    </div>

                    <label>Spend ₱</label>
                    <input type="number" id="spendMin" class="bp-text-input" placeholder="Min" min="0" step="1">
                    <span style="font-size:12px;color:var(--ink-4);">–</span>
                    <input type="number" id="spendMax" class="bp-text-input" placeholder="Max" min="0" step="1">

                    <button class="btn-primary" onclick="loadAll()">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                    <button class="btn-secondary" onclick="resetDates()">
                        <i class="fa-solid fa-rotate-left"></i> This Month
                    </button>
                    <span id="periodLabel" style="font-size:11.5px;color:var(--ink-4);margin-left:4px;"></span>
                </div>

                <!-- ── KPI strip ───────────────────────────────────────── -->
                <div class="bp-kpi-strip">
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-users"></i> Total Customers</div>
                        <div class="bp-kpi-value" id="kpiCustomers">—</div>
                        <div class="bp-kpi-sub">with OK transactions</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-peso-sign"></i> Total Revenue</div>
                        <div class="bp-kpi-value" id="kpiRevenue">—</div>
                        <div class="bp-kpi-sub">from all customers</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-user-check"></i> Avg Spend / Customer</div>
                        <div class="bp-kpi-value" id="kpiAvgCust">—</div>
                        <div class="bp-kpi-sub">per customer in period</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-basket-shopping"></i> Avg Spend / Visit</div>
                        <div class="bp-kpi-value" id="kpiAvgVisit">—</div>
                        <div class="bp-kpi-sub">average transaction value</div>
                    </div>
                </div>

                <!-- ── Row 1: Segmentation donut + Spend trend ─────────── -->
                <div class="ci-charts-row">

                    <!-- Donut: new vs returning -->
                    <div class="bp-chart-card">
                        <div class="bp-chart-title">
                            <i class="fa-solid fa-chart-pie"></i> New vs Returning Customers
                        </div>
                        <div class="donut-wrap">
                            <canvas id="segDonut" height="220"></canvas>
                            <div class="donut-center">
                                <div class="donut-center-val" id="segTotal">—</div>
                                <div class="donut-center-lbl">Total<br>Customers</div>
                            </div>
                        </div>
                        <div class="pi-legend" id="segLegend"></div>
                    </div>

                    <!-- Line: spend trend over time -->
                    <div class="bp-chart-card">
                        <div class="bp-chart-title" style="justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <span><i class="fa-solid fa-chart-line"></i> Customer Spend Trend</span>
                            <select class="dm-filter-select" id="trendMetric" onchange="renderTrend()"
                                style="font-size:11.5px;padding:4px 24px 4px 8px;">
                                <option value="revenue">By Revenue</option>
                                <option value="unique_customers">By Unique Customers</option>
                                <option value="visit_count">By Visit Count</option>
                            </select>
                        </div>
                        <div class="chart-canvas-wrap">
                            <canvas id="trendChart" height="260"></canvas>
                        </div>
                    </div>
                </div>

                <!-- ── Row 2: Heatmap + Leaderboard ────────────────────── -->
                <div class="ci-heatmap-row">

                    <!-- Branch heatmap -->
                    <div class="bp-chart-card" style="position:relative;">
                        <div class="bp-chart-title">
                            <i class="fa-solid fa-map-location-dot"></i> Customer Spending by Branch
                            <span style="font-size:10.5px;color:var(--ink-4);margin-left:auto;font-weight:400;">
                                Avg spend per customer · darker = higher
                            </span>
                        </div>
                        <div id="branchHeatmap" class="heatmap-grid"></div>
                        <div class="loading-overlay hidden" id="heatmapLoading">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                        </div>
                    </div>

                    <!-- Top customers leaderboard -->
                    <div class="bp-chart-card">
                        <div class="bp-chart-title">
                            <i class="fa-solid fa-trophy"></i> Top Customers
                        </div>
                        <div class="leaderboard-list" id="leaderboard"></div>
                    </div>
                </div>

                <!-- ── Customer Table ───────────────────────────────────── -->
                <div class="bp-table-card">
                    <div class="bp-table-header">
                        <div class="bp-table-header-title">
                            <i class="fa-solid fa-table"></i>
                            Customer Summary Table
                            <span id="tableSubLabel" style="font-size:11px;color:var(--ink-4);font-weight:400;"></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <select class="dm-filter-select" id="perPageSelect"
                                    onchange="loadTable(1)" style="font-size:12px;">
                                <option value="20">20 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                            <button class="btn-secondary" onclick="exportCsv()" title="Export CSV">
                                <i class="fa-solid fa-file-csv"></i> Export CSV
                            </button>
                        </div>
                    </div>

                    <div style="position:relative;">
                        <div class="loading-overlay hidden" id="tableLoading">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                        </div>
                        <table class="dm-table">
                            <thead>
                                <tr>
                                    <th onclick="sortTable('full_name')"   id="th-full_name">
                                        Customer <i class="fa-solid fa-sort sort-icon"></i>
                                    </th>
                                    <th onclick="sortTable('total_spend')" id="th-total_spend" class="sorted">
                                        Total Spend <i class="fa-solid fa-sort-down sort-icon"></i>
                                    </th>
                                    <th onclick="sortTable('visit_count')" id="th-visit_count">
                                        Visits <i class="fa-solid fa-sort sort-icon"></i>
                                    </th>
                                    <th onclick="sortTable('last_visit')"  id="th-last_visit">
                                        Last Visit <i class="fa-solid fa-sort sort-icon"></i>
                                    </th>
                                    <th>Spend Share</th>
                                </tr>
                            </thead>
                            <tbody id="custTableBody">
                                <tr><td colspan="5">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-spinner fa-spin"></i>
                                        <p>Loading customer data…</p>
                                    </div>
                                </td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="dm-pagination">
                        <div class="page-info" id="pageInfo">—</div>
                        <div class="page-btns" id="pageBtns"></div>
                    </div>
                </div>

            </div><!-- /.content -->
        </div><!-- /.main -->
    </div><!-- /.app -->

    <div id="toast-container"></div>

    <script>
        /* ════════════════════════════════════════════════════════════════════
           CUSTOMER INSIGHTS  —  JS
        ════════════════════════════════════════════════════════════════════ */
        const API = '../backend/api_proxy.php';

        let segChart   = null;
        let trendChart = null;
        let cachedData = null;

        // Table sort state
        let currentPage  = 1;
        let currentSort  = 'total_spend';
        let currentDir   = 'desc';
        let maxSpend     = 0;   // for the relative spend bar in table
        let nameTimer    = null;

        /* ── Formatters ──────────────────────────────────────────────────── */
        function peso(v) {
            return '₱' + Number(v || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });
        }

        function fmt(v)  { return Number(v || 0).toLocaleString('en-PH'); }
        function pct(v)  { return Number(v || 0).toFixed(1) + '%'; }

        /* ── Date helpers ────────────────────────────────────────────────── */
        function resetDates() {
            const today = new Date();
            const y = today.getFullYear(),
                  m = String(today.getMonth() + 1).padStart(2, '0');
            document.getElementById('dateFrom').value = `${y}-${m}-01`;
            document.getElementById('dateTo').value   = today.toISOString().slice(0, 10);
        }

        function getDateRange() {
            return {
                date_from: document.getElementById('dateFrom').value,
                date_to:   document.getElementById('dateTo').value,
            };
        }

        function updatePeriodLabel(from, to) {
            const f = new Date(from + 'T00:00:00').toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
            const t = new Date(to   + 'T00:00:00').toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
            document.getElementById('periodLabel').textContent = `${f} – ${t}`;
        }

        /* ── Toast ───────────────────────────────────────────────────────── */
        function showToast(msg, type = 'info') {
            const el = document.createElement('div');
            el.className = `toast ${type}`;
            el.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-xmark' : 'fa-circle-info'}"></i> ${msg}`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => el.remove(), 3500);
        }

        /* ── Load branches for filter ────────────────────────────────────── */
        async function loadFilters() {
            try {
                const res  = await fetch(`${API}?endpoint=customer-insights/filters`);
                const data = await res.json();
                const sel  = document.getElementById('filterBranch');
                (data.branches || []).forEach(b => {
                    const o = document.createElement('option');
                    o.value = b.id; o.textContent = b.name;
                    sel.appendChild(o);
                });
            } catch (e) {
                showToast('Could not load filter options.', 'error');
            }
        }

        /* ── Build shared query params ───────────────────────────────────── */
        function getFilters() {
            const { date_from, date_to } = getDateRange();
            const spend_min = document.getElementById('spendMin').value.trim();
            const spend_max = document.getElementById('spendMax').value.trim();
            const filters = {
                preset:    'custom',
                date_from, date_to,
                branch_id: document.getElementById('filterBranch').value,
                search:    document.getElementById('filterName').value.trim(),
            };
            if (spend_min !== '') filters.spend_min = spend_min;
            if (spend_max !== '') filters.spend_max = spend_max;
            return filters;
        }

        /* ══════════════════════════════════════════════════════════════════
           MAIN LOAD
        ══════════════════════════════════════════════════════════════════ */
        async function loadAll() {
            const { date_from, date_to } = getDateRange();
            updatePeriodLabel(date_from, date_to);

            const qs = new URLSearchParams({
                endpoint: 'customer-insights',
                ...getFilters(),
            });

            try {
                const res  = await fetch(`${API}?${qs}`);
                const data = await res.json();
                if (data.error) throw new Error(data.error);

                cachedData = data;
                renderKpis(data.kpi);
                renderSegDonut(data.segmentation);
                renderTrend();
                renderHeatmap(data.branch_heatmap);
                renderLeaderboard(data.top_customers);
                loadTable(1);
            } catch (e) {
                showToast('Failed to load customer insights: ' + e.message, 'error');
            }
        }

        /* ── KPIs ────────────────────────────────────────────────────────── */
        function renderKpis(k) {
            document.getElementById('kpiCustomers').textContent  = fmt(k.total_customers);
            document.getElementById('kpiRevenue').textContent    = peso(k.total_revenue);
            document.getElementById('kpiAvgCust').textContent    = peso(k.avg_spend_per_customer);
            document.getElementById('kpiAvgVisit').textContent   = peso(k.avg_spend_per_visit);
        }

        /* ── Segmentation donut ──────────────────────────────────────────── */
        function renderSegDonut(seg) {
            if (segChart) segChart.destroy();
            if (!seg) return;

            const newCount  = seg.new       || 0;
            const retCount  = seg.returning || 0;
            const total     = newCount + retCount;

            document.getElementById('segTotal').textContent = fmt(total);

            const labels = ['New', 'Returning'];
            const counts = [newCount, retCount];
            const colors = ['#0d9488', '#2563eb'];

            const ctx = document.getElementById('segDonut').getContext('2d');
            segChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{ data: counts, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }],
                },
                options: {
                    responsive: true,
                    cutout: '68%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    const pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                    return ` ${ctx.label}: ${fmt(ctx.raw)} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // Legend
            const legend = document.getElementById('segLegend');
            legend.innerHTML = labels.map((lbl, i) => {
                const p = total ? ((counts[i] / total) * 100).toFixed(1) : '0.0';
                return `
                <div class="pi-legend-item">
                    <div class="pi-legend-label">
                        <div class="pi-legend-dot" style="background:${colors[i]};"></div>
                        ${lbl}
                    </div>
                    <span class="pi-legend-pct">${p}%</span>
                    <span class="pi-legend-val" style="margin-left:10px;">${fmt(counts[i])}</span>
                </div>`;
            }).join('');
        }

        /* ── Spend trend line chart ──────────────────────────────────────── */
        function renderTrend() {
            if (!cachedData || !cachedData.spend_trend) return;
            if (trendChart) trendChart.destroy();

            const trend  = cachedData.spend_trend;
            const metric = document.getElementById('trendMetric').value;
            const labels = trend.map(r => r.date);

            const metaMap = {
                revenue:          { label: 'Revenue (₱)', color: '#2563eb', yFmt: v => peso(v) },
                unique_customers: { label: 'Unique Customers', color: '#0d9488', yFmt: v => fmt(v) },
                visit_count:      { label: 'Visit Count', color: '#7c3aed', yFmt: v => fmt(v) },
            };

            const meta  = metaMap[metric] || metaMap.revenue;
            const vals  = trend.map(r => r[metric] || 0);

            const ctx = document.getElementById('trendChart').getContext('2d');
            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label:          meta.label,
                        data:           vals,
                        borderColor:    meta.color,
                        backgroundColor: meta.color + '18',
                        borderWidth:    2,
                        pointRadius:    vals.length > 60 ? 0 : 3,
                        tension:        0.35,
                        fill:           true,
                    }],
                },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: { label: ctx => ` ${meta.label}: ${meta.yFmt(ctx.raw)}` }
                        }
                    },
                    scales: {
                        x: {
                            ticks: { maxTicksLimit: 10, font: { size: 11 } },
                            grid:  { display: false },
                        },
                        y: {
                            ticks: {
                                font: { size: 11 },
                                callback: v => metric === 'revenue' ? '₱' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }) : fmt(v),
                            },
                            grid: { color: '#f1f5f9' },
                        }
                    }
                }
            });
        }

        /* ── Branch heatmap ──────────────────────────────────────────────── */
        function renderHeatmap(rows) {
            const container = document.getElementById('branchHeatmap');

            if (!rows || !rows.length) {
                container.innerHTML = `<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No branch data available.</p></div>`;
                return;
            }

            // Compute max avg_spend_cust for colour intensity
            const maxVal = Math.max(...rows.map(r => r.avg_spend_cust), 1);

            container.innerHTML = rows.map(r => {
                const intensity = r.avg_spend_cust / maxVal;          // 0–1
                const alpha     = (0.08 + intensity * 0.72).toFixed(2);
                const bgColor   = `rgba(37, 99, 235, ${alpha})`;
                const txtColor  = intensity > 0.55 ? '#fff' : 'var(--ink)';
                const tip       = `${r.branch_name} · ${r.cust_count} customers · ${fmt(r.visit_count)} visits`;

                return `
                <div class="heatmap-row">
                    <div class="heatmap-label" title="${r.branch_name}">${r.branch_name}</div>
                    <div class="heatmap-cell"
                         style="background:${bgColor};color:${txtColor};"
                         data-tip="${tip}">
                        ${peso(r.avg_spend_cust)}
                    </div>
                </div>`;
            }).join('');
        }

        /* ── Leaderboard ─────────────────────────────────────────────────── */
        function renderLeaderboard(customers) {
            const el = document.getElementById('leaderboard');
            if (!customers || !customers.length) {
                el.innerHTML = `<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No customer data.</p></div>`;
                return;
            }

            const maxSpendLb = customers[0].total_spend || 1;

            el.innerHTML = customers.map(c => {
                const rankClass = c.rank === 1 ? 'gold' : c.rank === 2 ? 'silver' : c.rank === 3 ? 'bronze' : 'other';
                const barPct    = Math.round((c.total_spend / maxSpendLb) * 100);

                return `
                <div class="lb-item">
                    <div class="lb-rank ${rankClass}">${c.rank}</div>
                    <div style="flex:1;min-width:0;">
                        <div class="lb-name" title="${c.name}">${c.name}</div>
                        <div class="lb-meta">${fmt(c.visit_count)} visits · last ${c.last_visit}</div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div class="lb-spend">${peso(c.total_spend)}</div>
                        <div class="lb-pct">${pct(c.revenue_pct)} of rev</div>
                    </div>
                    <div class="lb-bar-wrap">
                        <div class="lb-bar" style="width:${barPct}%;"></div>
                    </div>
                </div>`;
            }).join('');
        }

        /* ══════════════════════════════════════════════════════════════════
           CUSTOMER TABLE
        ══════════════════════════════════════════════════════════════════ */
        async function loadTable(page) {
            currentPage = page || currentPage;
            const loading = document.getElementById('tableLoading');
            loading.classList.remove('hidden');

            const qs = new URLSearchParams({
                endpoint: 'customer-insights/table',
                ...getFilters(),
                sort:     currentSort,
                dir:      currentDir,
                page:     currentPage,
                per_page: document.getElementById('perPageSelect').value,
            });

            try {
                const res  = await fetch(`${API}?${qs}`);
                const data = await res.json();
                if (data.error) throw new Error(data.error);

                // Store max spend for bar widths
                maxSpend = data.rows.length ? Math.max(...data.rows.map(r => r.total_spend), 1) : 1;

                renderTable(data.rows);
                renderPagination(data.pagination);

                const p = data.pagination;
                const start = (p.page - 1) * p.per_page + 1;
                const end   = Math.min(p.page * p.per_page, p.total);
                document.getElementById('tableSubLabel').textContent =
                    ` — ${start}–${end} of ${fmt(p.total)} customers`;
            } catch (e) {
                showToast('Table load failed: ' + e.message, 'error');
            } finally {
                loading.classList.add('hidden');
            }
        }

        function renderTable(rows) {
            const tbody = document.getElementById('custTableBody');
            if (!rows || !rows.length) {
                tbody.innerHTML = `<tr><td colspan="5">
                    <div class="empty-state">
                        <i class="fa-solid fa-inbox"></i>
                        <strong>No Customers</strong>
                        <p>No customers match the current filters.</p>
                    </div>
                </td></tr>`;
                return;
            }

            tbody.innerHTML = rows.map(r => {
                const barW = maxSpend ? Math.round((r.total_spend / maxSpend) * 100) : 0;
                return `<tr>
                    <td style="font-weight:500;color:var(--ink);">${r.name}</td>
                    <td class="mono">${peso(r.total_spend)}</td>
                    <td class="mono">${fmt(r.visit_count)}</td>
                    <td class="mono" style="font-size:11.5px;">${r.last_visit}</td>
                    <td>
                        <div class="spend-bar-wrap">
                            <div class="spend-bar-bg">
                                <div class="spend-bar-fill" style="width:${barW}%;"></div>
                            </div>
                            <span style="font-size:10.5px;color:var(--ink-4);font-family:'DM Mono',monospace;white-space:nowrap;">
                                ${barW}%
                            </span>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        function renderPagination(p) {
            document.getElementById('pageInfo').textContent =
                `Page ${p.page} of ${p.total_pages} (${fmt(p.total)} total)`;

            const btns    = document.getElementById('pageBtns');
            const maxShow = 7;
            let pages = [];

            if (p.total_pages <= maxShow) {
                for (let i = 1; i <= p.total_pages; i++) pages.push(i);
            } else {
                pages = [1];
                if (p.page > 3) pages.push('…');
                for (let i = Math.max(2, p.page - 1); i <= Math.min(p.total_pages - 1, p.page + 1); i++) pages.push(i);
                if (p.page < p.total_pages - 2) pages.push('…');
                pages.push(p.total_pages);
            }

            btns.innerHTML = `
                <button class="page-btn" onclick="loadTable(${p.page - 1})" ${p.page <= 1 ? 'disabled' : ''}>
                    <i class="fa-solid fa-chevron-left" style="font-size:10px;"></i>
                </button>
                ${pages.map(pg => pg === '…'
                    ? `<button class="page-btn" disabled>…</button>`
                    : `<button class="page-btn ${pg === p.page ? 'active' : ''}" onclick="loadTable(${pg})">${pg}</button>`
                ).join('')}
                <button class="page-btn" onclick="loadTable(${p.page + 1})" ${p.page >= p.total_pages ? 'disabled' : ''}>
                    <i class="fa-solid fa-chevron-right" style="font-size:10px;"></i>
                </button>`;
        }

        /* ── Table sorting ───────────────────────────────────────────────── */
        function sortTable(col) {
            if (currentSort === col) {
                currentDir = currentDir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort = col;
                currentDir  = 'desc';
            }
            updateSortHeaders();
            loadTable(1);
        }

        function updateSortHeaders() {
            const cols = ['full_name', 'total_spend', 'visit_count', 'last_visit'];
            cols.forEach(col => {
                const th   = document.getElementById(`th-${col}`);
                const icon = th ? th.querySelector('.sort-icon') : null;
                if (!th) return;
                if (col === currentSort) {
                    th.classList.add('sorted');
                    if (icon) {
                        icon.className = `fa-solid ${currentDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'} sort-icon`;
                    }
                } else {
                    th.classList.remove('sorted');
                    if (icon) icon.className = 'fa-solid fa-sort sort-icon';
                }
            });
        }

        /* ── CSV Export ──────────────────────────────────────────────────── */
        function exportCsv() {
            const qs = new URLSearchParams({
                endpoint: 'customer-insights/export',
                ...getFilters(),
            });
            window.location.href = `${API}?${qs}`;
        }

        /* ── Debounced name search ────────────────────────────────────────── */
        function onNameSearch() {
            clearTimeout(nameTimer);
            nameTimer = setTimeout(() => loadTable(1), 400);
        }

        /* ── Topbar date ─────────────────────────────────────────────────── */
        function updateTopbarDate() {
            document.getElementById('topbarDate').textContent = new Date().toLocaleDateString('en-PH', {
                weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
            });
        }

        /* ── Sidebar toggle ──────────────────────────────────────────────── */
        function initSidebar() {
            const toggle  = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');
            if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
        }

        /* ── Boot ────────────────────────────────────────────────────────── */
        (async function init() {
            resetDates();
            updateTopbarDate();
            initSidebar();
            await loadFilters();
            loadAll();
        })();
    </script>
</body>

</html>