<?php
$current = 'branch-performance';

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
    <title>Branch Performance — ChronoSales</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ── BP-specific variables & base ─────────────────────────── */
        :root {
            --card-radius: 14px;
            --decline-bg:  #fff1f2;
            --decline-clr: #e11d48;
            --growth-bg:   #f0fdf4;
            --growth-clr:  #16a34a;
            --neutral-bg:  #f8fafc;
            --neutral-clr: var(--ink-4);
        }

        /* ── Date range bar ──────────────────────────────────────────  */
        .bp-filter-bar {
            display: flex; align-items: center; gap: 10px;
            flex-wrap: wrap; margin-bottom: 20px;
        }
        .bp-filter-bar label {
            font-size: 11.5px; font-weight: 600;
            color: var(--ink-4); text-transform: uppercase; letter-spacing: .07em;
        }
        .bp-date-input {
            padding: 7px 11px; border-radius: 8px;
            border: 1px solid var(--border); background: var(--card);
            font-size: 12.5px; font-family: 'DM Sans', sans-serif; color: var(--ink);
            transition: border-color .15s;
        }
        .bp-date-input:focus { outline: none; border-color: var(--primary-mid); }

        .btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--primary); color: #fff; border: none;
            font-size: 12.5px; font-weight: 600; cursor: pointer;
            font-family: 'DM Sans', sans-serif; transition: opacity .15s;
        }
        .btn-primary:hover { opacity: .88; }
        .btn-secondary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            border: 1px solid var(--border); background: var(--card);
            font-size: 12.5px; font-weight: 500; color: var(--ink-3); cursor: pointer;
            font-family: 'DM Sans', sans-serif; transition: all .15s;
        }
        .btn-secondary:hover { border-color: var(--primary-mid); color: var(--primary); background: var(--primary-light); }

        /* ── KPI summary strip ───────────────────────────────────────  */
        .bp-kpi-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px; margin-bottom: 22px;
        }
        .bp-kpi-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--card-radius); padding: 16px 18px;
            box-shadow: var(--card-shadow);
        }
        .bp-kpi-label {
            font-size: 10.5px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .07em; color: var(--ink-4); margin-bottom: 6px;
            display: flex; align-items: center; gap: 6px;
        }
        .bp-kpi-label i { color: var(--primary-mid); }
        .bp-kpi-value {
            font-size: 20px; font-weight: 700; color: var(--ink);
            font-family: 'DM Mono', monospace;
        }
        .bp-kpi-sub {
            font-size: 11px; color: var(--ink-4); margin-top: 3px;
        }

        /* ── Charts row ──────────────────────────────────────────────  */
        .bp-charts-row {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 16px; margin-bottom: 22px;
        }
        .bp-chart-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--card-radius); padding: 18px 20px;
            box-shadow: var(--card-shadow);
        }
        .bp-chart-title {
            font-size: 12.5px; font-weight: 600; color: var(--ink);
            margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
        }
        .bp-chart-title i { color: var(--primary-mid); font-size: 13px; }
        .chart-canvas-wrap {
            position: relative; width: 100%;
        }
        .chart-canvas-wrap canvas { max-height: 270px; }
        .donut-wrap {
            position: relative; max-width: 220px; margin: 0 auto;
        }
        .donut-center {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            pointer-events: none;
        }
        .donut-center-val {
            font-size: 16px; font-weight: 700; color: var(--ink);
            font-family: 'DM Mono', monospace;
        }
        .donut-center-lbl {
            font-size: 10px; color: var(--ink-4); text-align: center;
        }

        /* ── Branch table ────────────────────────────────────────────  */
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

        .dm-search-wrap {
            position: relative; min-width: 220px;
        }
        .dm-search-wrap i {
            position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
            font-size: 11.5px; color: var(--ink-4);
        }
        .dm-search {
            width: 100%; padding: 7px 10px 7px 28px;
            border: 1px solid var(--border); border-radius: 8px;
            background: var(--bg); font-size: 12.5px;
            font-family: 'DM Sans', sans-serif; color: var(--ink);
            transition: border-color .15s; box-sizing: border-box;
        }
        .dm-search:focus { outline: none; border-color: var(--primary-mid); }

        .dm-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .dm-table th {
            padding: 10px 14px; text-align: left;
            font-size: 10.5px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .07em; color: var(--ink-4);
            border-bottom: 1px solid var(--border);
            background: #fafafa; white-space: nowrap;
            cursor: pointer; user-select: none; transition: color .15s;
        }
        .dm-table th:hover { color: var(--primary); }
        .dm-table th .sort-icon { margin-left: 4px; font-size: 9px; opacity: .5; }
        .dm-table th.sorted .sort-icon { opacity: 1; color: var(--primary); }
        .dm-table td {
            padding: 11px 14px; color: var(--ink-2);
            border-bottom: 1px solid #f1f5f9; vertical-align: middle;
        }
        .dm-table tr:last-child td { border-bottom: none; }
        .dm-table tr.branch-row { cursor: pointer; transition: background .12s; }
        .dm-table tr.branch-row:hover td { background: var(--primary-light); }
        .dm-table td.mono { font-family: 'DM Mono', monospace; font-size: 12px; }

        /* ── Badges ──────────────────────────────────────────────────  */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px; border-radius: 99px;
            font-size: 10.5px; font-weight: 700; font-family: 'DM Mono', monospace;
        }
        .badge.growth  { background: var(--growth-bg);  color: var(--growth-clr); }
        .badge.decline { background: var(--decline-bg); color: var(--decline-clr); }
        .badge.neutral { background: var(--neutral-bg); color: var(--neutral-clr); }
        .decline-flag {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 7px; border-radius: 99px;
            font-size: 10px; font-weight: 700;
            background: var(--decline-bg); color: var(--decline-clr);
            border: 1px solid #fda4af;
        }

        /* ── Sparkline mini canvas ───────────────────────────────────  */
        .spark-canvas { display: block; }

        /* ── Revenue bar ─────────────────────────────────────────────  */
        .rev-bar-wrap { display: flex; align-items: center; gap: 8px; min-width: 120px; }
        .rev-bar-bg {
            flex: 1; height: 6px; background: #e2e8f0;
            border-radius: 3px; overflow: hidden;
        }
        .rev-bar-fill {
            height: 100%; border-radius: 3px;
            background: var(--primary); transition: width .4s;
        }
        .rev-bar-pct {
            font-size: 10px; color: var(--ink-4);
            font-family: 'DM Mono', monospace; white-space: nowrap;
        }

        /* ── VAT section ─────────────────────────────────────────────  */
        .vat-section {
            margin-bottom: 22px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }
        .vat-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 10px; padding: 12px 16px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: var(--card-shadow);
        }
        .vat-icon {
            width: 36px; height: 36px; border-radius: 9px;
            background: var(--violet-light, #ede9fe); color: var(--violet, #7c3aed);
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .vat-info { flex: 1; min-width: 0; }
        .vat-branch { font-size: 11px; color: var(--ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .vat-amount { font-size: 14px; font-weight: 700; color: var(--ink); font-family: 'DM Mono', monospace; }

        /* ── Drill-down modal ────────────────────────────────────────  */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 1000;
            align-items: center; justify-content: center;
            backdrop-filter: blur(3px); padding: 20px;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: var(--card); border-radius: 16px;
            width: 100%; max-width: 760px; max-height: 90vh;
            overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2);
            animation: modalIn .2s ease;
        }
        @keyframes modalIn { from { opacity: 0; transform: scale(.96) translateY(8px); } to { opacity: 1; transform: none; } }
        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 22px; border-bottom: 1px solid var(--border);
            position: sticky; top: 0; background: var(--card); z-index: 1;
        }
        .modal-title { font-size: 14px; font-weight: 600; color: var(--ink); display: flex; align-items: center; gap: 8px; }
        .modal-title i { color: var(--primary-mid); }
        .modal-close {
            width: 30px; height: 30px; border-radius: 8px;
            border: none; background: var(--bg); color: var(--ink-3);
            font-size: 13px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: all .15s;
        }
        .modal-close:hover { background: var(--danger-light); color: var(--danger); }
        .modal-body { padding: 20px 22px; }

        .drill-kpis {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 12px; margin-bottom: 18px;
        }
        .drill-kpi {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 10px; padding: 12px 14px;
        }
        .drill-kpi-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-4); margin-bottom: 4px; }
        .drill-kpi-value { font-size: 16px; font-weight: 700; color: var(--ink); font-family: 'DM Mono', monospace; }
        

        /* ── Pagination (matches data-management.php) ───────────────  */
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

        /* ── Per-page select ─────────────────────────────────────────  */
        .dm-filter-select {
            padding: 7px 28px 7px 10px; border-radius: 8px;
            border: 1px solid var(--border); background: var(--card);
            font-size: 12.5px; font-family: 'DM Sans', sans-serif;
            color: var(--ink); cursor: pointer;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%239ca3af' d='M0 0l5 6 5-6z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .dm-filter-select:focus { outline: none; border-color: var(--primary-mid); }

        /* ── Empty state ─────────────────────────────────────────────  */
        .empty-state {
            padding: 48px 24px; text-align: center;
        }
        .empty-state i { font-size: 32px; color: var(--ink-4); margin-bottom: 12px; display: block; }
        .empty-state p { font-size: 13px; color: var(--ink-3); }
        .empty-state strong { display: block; font-size: 14px; color: var(--ink-2); margin-bottom: 4px; }


        /* ── Loading skeleton ────────────────────────────────────────  */
        .loading-overlay {
            position: absolute; inset: 0; background: rgba(255,255,255,.75);
            z-index: 5; border-radius: var(--card-radius);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; color: var(--ink-3); gap: 8px;
        }
        .loading-overlay.hidden { display: none; }

        /* ── Toast ───────────────────────────────────────────────────  */
        #toast-container {
            position: fixed; bottom: 24px; right: 24px;
            z-index: 9999; display: flex; flex-direction: column; gap: 8px;
        }
        .toast {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; border-radius: 10px; min-width: 260px;
            box-shadow: 0 4px 20px rgba(0,0,0,.15);
            font-size: 13px; font-family: 'DM Sans', sans-serif; font-weight: 500;
            animation: toastIn .25s ease;
        }
        @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }
        .toast.success { background: var(--success); color: #fff; }
        .toast.error   { background: var(--danger);  color: #fff; }
        .toast.info    { background: var(--primary);  color: #fff; }

        /* ── Responsive ──────────────────────────────────────────────  */
        @media (max-width: 900px) {
            .bp-charts-row { grid-template-columns: 1fr; }
            .drill-kpis    { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 600px) {
            .bp-kpi-strip  { grid-template-columns: 1fr 1fr; }
            .drill-kpis    { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app">

    <?php include 'sidebar.php'; ?>

    <div class="main" id="main">

        <!-- ── Topbar ─────────────────────────────────────────────── -->
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="topbar-breadcrumb">
                <i class="fa-solid fa-store"></i>
                <span>Branch Performance</span>
            </div>
            <div class="topbar-right">
                <div class="topbar-date" id="topbarDate"></div>
                <button class="topbar-btn" id="refreshBtn" title="Refresh" onclick="loadData()">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>
        </header>

        <!-- ── Content ───────────────────────────────────────────── -->
        <div class="content">

            <!-- Page header -->
            <div style="margin-bottom:18px;">
                <div style="font-size:18px;font-weight:600;color:var(--ink);margin-bottom:4px;">
                    <i class="fa-solid fa-store" style="color:var(--primary-mid);margin-right:8px;"></i>
                    Branch Performance
                </div>
                <p style="font-size:13px;color:var(--ink-3);">
                    Comparative analysis of all branches — revenue, growth, discounts, VAT, and health status.
                </p>
            </div>

            <!-- ── Filter bar ──────────────────────────────────── -->
            <div class="bp-filter-bar">
                <label for="dateFrom">From</label>
                <input type="date" id="dateFrom" class="bp-date-input">
                <label for="dateTo">To</label>
                <input type="date" id="dateTo"   class="bp-date-input">
                <button class="btn-primary" onclick="loadData()">
                    <i class="fa-solid fa-filter"></i> Apply
                </button>
                <button class="btn-secondary" onclick="resetDates()">
                    <i class="fa-solid fa-rotate-left"></i> This Month
                </button>
                <span id="periodLabel" style="font-size:11.5px;color:var(--ink-4);margin-left:4px;"></span>
            </div>

            <!-- ── KPI summary strip ───────────────────────────── -->
            <div class="bp-kpi-strip" id="kpiStrip">
                <div class="bp-kpi-card">
                    <div class="bp-kpi-label"><i class="fa-solid fa-store"></i> Active Branches</div>
                    <div class="bp-kpi-value" id="kpiBranches">—</div>
                    <div class="bp-kpi-sub">with transactions in period</div>
                </div>
                <div class="bp-kpi-card">
                    <div class="bp-kpi-label"><i class="fa-solid fa-peso-sign"></i> Total Revenue</div>
                    <div class="bp-kpi-value" id="kpiRevenue">—</div>
                    <div class="bp-kpi-sub" id="kpiRevSub">selected period</div>
                </div>
                <div class="bp-kpi-card">
                    <div class="bp-kpi-label"><i class="fa-solid fa-receipt"></i> Total Transactions</div>
                    <div class="bp-kpi-value" id="kpiTx">—</div>
                    <div class="bp-kpi-sub">OK status only</div>
                </div>
                <div class="bp-kpi-card">
                    <div class="bp-kpi-label"><i class="fa-solid fa-tag"></i> Total Discounts</div>
                    <div class="bp-kpi-value" id="kpiDiscount">—</div>
                    <div class="bp-kpi-sub">applied across all branches</div>
                </div>
                <div class="bp-kpi-card">
                    <div class="bp-kpi-label"><i class="fa-solid fa-triangle-exclamation"></i> Declining Branches</div>
                    <div class="bp-kpi-value" id="kpiDeclining" style="color:var(--decline-clr);">—</div>
                    <div class="bp-kpi-sub">30-day downward trend</div>
                </div>
            </div>

            <!-- ── Charts row ──────────────────────────────────── -->
            <div class="bp-charts-row">
                <!-- Donut: revenue share -->
                <div class="bp-chart-card">
                    <div class="bp-chart-title">
                        <i class="fa-solid fa-chart-pie"></i> Revenue Share by Branch
                    </div>
                    <div class="donut-wrap">
                        <canvas id="donutChart" height="220"></canvas>
                        <div class="donut-center">
                            <div class="donut-center-val" id="donutCenterVal">—</div>
                            <div class="donut-center-lbl">Total<br>Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Bar: Discount vs Grand Total -->
                <div class="bp-chart-card">
                    <div class="bp-chart-title">
                        <i class="fa-solid fa-chart-bar"></i> Discount vs Grand Total per Branch
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="discountChart" height="270"></canvas>
                    </div>
                </div>
            </div>

            <!-- ── VAT Insights ────────────────────────────────── -->
            <div style="margin-bottom:10px;">
                <div class="bp-chart-title" style="font-size:13px;font-weight:600;color:var(--ink);display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-file-invoice-dollar" style="color:var(--primary-mid);"></i>
                    VAT Collected per Branch
                </div>
                <p style="font-size:11.5px;color:var(--ink-4);margin-bottom:12px;">Summary of VAT amounts from OK transactions in the selected period.</p>
            </div>
            <div class="vat-section" id="vatSection">
                <div class="vat-card"><div class="vat-icon"><i class="fa-solid fa-spinner fa-spin"></i></div><div class="vat-info"><div class="vat-branch">Loading…</div></div></div>
            </div>

            <!-- ── Branch Comparison Table ─────────────────────── -->
            <div class="bp-table-card" style="position:relative;">

                <div class="loading-overlay hidden" id="tableLoading">
                    <i class="fa-solid fa-circle-notch fa-spin"></i> Fetching data…
                </div>

                <div class="bp-table-header">
                    <div class="bp-table-header-title">
                        <i class="fa-solid fa-table-list"></i>
                        Branch Comparison
                        <span style="font-size:11px;color:var(--ink-4);font-weight:400;" id="tableSubLabel"></span>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <div class="dm-search-wrap" style="min-width:200px;">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="dm-search" id="branchSearch"
                                   placeholder="Search branch…" oninput="onSearch()">
                        </div>
                        <select class="dm-filter-select" id="statusFilter" onchange="onStatusFilter()">
                            <option value="all">All Branches</option>
                            <option value="declining">Declining Only</option>
                            <option value="growth">Growth Only</option>
                        </select>
                        <select class="dm-filter-select" id="perPageSelect" onchange="onPerPageChange()">
                            <option value="10">10 / page</option>
                            <option value="25" selected>25 / page</option>
                            <option value="50">50 / page</option>
                            <option value="100">100 / page</option>
                        </select>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="dm-table" id="branchTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable('branch_name')"       data-col="branch_name">
                                    Branch <span class="sort-icon"><i class="fa-solid fa-sort"></i></span>
                                </th>
                                <th onclick="sortTable('total_revenue')"     data-col="total_revenue" class="sorted">
                                    Revenue <span class="sort-icon"><i class="fa-solid fa-sort-down"></i></span>
                                </th>
                                <th onclick="sortTable('tx_count')"          data-col="tx_count">
                                    Transactions <span class="sort-icon"><i class="fa-solid fa-sort"></i></span>
                                </th>
                                <th onclick="sortTable('avg_ticket')"        data-col="avg_ticket">
                                    Avg Ticket <span class="sort-icon"><i class="fa-solid fa-sort"></i></span>
                                </th>
                                <th>Revenue Share</th>
                                <th onclick="sortTable('mom_growth')"        data-col="mom_growth">
                                    MoM Growth <span class="sort-icon"><i class="fa-solid fa-sort"></i></span>
                                </th>
                                <th>30-Day Trend</th>
                                <th onclick="sortTable('total_vat')"         data-col="total_vat">
                                    VAT <span class="sort-icon"><i class="fa-solid fa-sort"></i></span>
                                </th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="branchTableBody">
                            <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--ink-4);">
                                <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                            </td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="dm-pagination">
                    <div class="page-info" id="pageInfo">—</div>
                    <div class="page-btns" id="pageBtns"></div>
                </div>
            </div>

        </div><!-- .content -->
    </div><!-- .main -->
</div><!-- .app -->

<!-- ── Drill-Down Modal ────────────────────────────────────────────── -->
<div class="modal-overlay" id="drillModalOverlay">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-store"></i>
                <span id="drillTitle">Branch Detail</span>
            </div>
            <button class="modal-close" onclick="closeDrill()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="drill-kpis" id="drillKpis"></div>
            <div class="bp-chart-title" style="margin-bottom:10px;font-size:12.5px;">
                <i class="fa-solid fa-chart-line" style="color:var(--primary-mid);"></i>
                12-Month Revenue Trend
            </div>
            <div class="chart-canvas-wrap">
                <canvas id="drillChart" height="200"></canvas>
            </div>
            <div style="margin-top:18px;text-align:right;">
                <a id="drillTxLink" href="#"
                   style="font-size:12.5px;color:var(--primary);font-weight:600;text-decoration:none;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    View all transactions for this branch →
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
const API = '/backend/api_proxy.php';

/* ── Palette ────────────────────────────────────────────────── */
const PALETTE = [
    '#0d9488','#14b8a6','#5eead4','#0891b2','#38bdf8',
    '#6366f1','#a78bfa','#f59e0b','#f97316','#ef4444',
    '#84cc16','#10b981','#ec4899','#8b5cf6','#3b82f6',
    '#06b6d4','#d946ef','#f43f5e','#22c55e','#eab308',
    '#a3e635','#34d399','#fb7185','#818cf8','#38bdf8','#fb923c',
];

/* ── State ──────────────────────────────────────────────────── */
let allBranches      = [];   // all branches from API (full dataset)
let filteredBranches = [];   // after search + status filter
let pagedBranches    = [];   // current page slice

let sortCol  = 'total_revenue';
let sortDir  = 'desc';
let curPage  = 1;
let perPage  = 10;
let searchTerm  = '';
let statusFilter = 'all';

let drillChart        = null;
let donutChartInst    = null;
let discountChartInst = null;
const sparkCharts     = {};

/* ── Helpers ────────────────────────────────────────────────── */
const peso = v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
const pct  = v => (v === null || v === undefined) ? '—' : (v >= 0 ? '+' : '') + v.toFixed(1) + '%';
const fmt  = v => Number(v).toLocaleString('en-PH');

function showToast(msg, type = 'info') {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info' };
    const el    = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="fa-solid ${icons[type]||icons.info}"></i> ${msg}`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

/* ── Date defaults ──────────────────────────────────────────── */
function resetDates() {
    const today = new Date();
    const from  = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('dateFrom').value = from.toISOString().slice(0,10);
    document.getElementById('dateTo').value   = today.toISOString().slice(0,10);
}

function getDateRange() {
    return {
        date_from: document.getElementById('dateFrom').value,
        date_to:   document.getElementById('dateTo').value,
    };
}

/* ── Load main data ─────────────────────────────────────────── */
async function loadData() {
    document.getElementById('tableLoading').classList.remove('hidden');
    const { date_from, date_to } = getDateRange();

    try {
        const res  = await fetch(`${API}?endpoint=branch-performance&preset=custom&date_from=${date_from}&date_to=${date_to}`);
        if (!res.ok) throw new Error('API error ' + res.status);
        const data = await res.json();

        allBranches = (data.branches || []).map(b => ({
            ...b,
            mom_growth: b.mom_growth_pct ?? null,
            sparkline:  (b.trend_30d || []).map(d => d.revenue),
        }));

        renderKPIs(data);
        renderDonut(data);
        renderDiscountChart(data);
        renderVAT(data);

        // Reset pagination & filter state on fresh load
        curPage    = 1;
        applyFiltersAndRender();

        document.getElementById('periodLabel').textContent = `${date_from} → ${date_to}`;
    } catch (e) {
        showToast('Failed to load branch data: ' + e.message, 'error');
    } finally {
        document.getElementById('tableLoading').classList.add('hidden');
    }
}

/* ── Filter + sort + paginate pipeline ─────────────────────── */
function applyFiltersAndRender() {
    // 1. Filter
    filteredBranches = allBranches.filter(b => {
        const matchSearch = !searchTerm || b.branch_name.toLowerCase().includes(searchTerm);
        const matchStatus = statusFilter === 'all'
            || (statusFilter === 'declining' && b.declining)
            || (statusFilter === 'growth'    && b.mom_growth !== null && b.mom_growth > 0);
        return matchSearch && matchStatus;
    });

    // 2. Sort
    filteredBranches = [...filteredBranches].sort((a, b) => {
        let av = a[sortCol], bv = b[sortCol];
        if (av === null || av === undefined) av = sortDir === 'asc' ? Infinity : -Infinity;
        if (bv === null || bv === undefined) bv = sortDir === 'asc' ? Infinity : -Infinity;
        if (typeof av === 'string') av = av.toLowerCase();
        if (typeof bv === 'string') bv = bv.toLowerCase();
        if (av < bv) return sortDir === 'asc' ? -1 : 1;
        if (av > bv) return sortDir === 'asc' ?  1 : -1;
        return 0;
    });

    // 3. Paginate
    const total  = filteredBranches.length;
    const pages  = Math.ceil(total / perPage) || 1;
    curPage      = Math.min(curPage, pages);
    const start  = (curPage - 1) * perPage;
    pagedBranches = filteredBranches.slice(start, start + perPage);

    // 4. Render
    renderTableRows(pagedBranches);
    renderPagination(total);
    updateTableSubLabel(total);
}

/* ── Toolbar event handlers ─────────────────────────────────── */
function onSearch() {
    searchTerm = document.getElementById('branchSearch').value.toLowerCase().trim();
    curPage    = 1;
    applyFiltersAndRender();
}

function onStatusFilter() {
    statusFilter = document.getElementById('statusFilter').value;
    curPage      = 1;
    applyFiltersAndRender();
}

function onPerPageChange() {
    perPage = parseInt(document.getElementById('perPageSelect').value, 10);
    curPage = 1;
    applyFiltersAndRender();
}

/* ── Sorting ────────────────────────────────────────────────── */
function sortTable(col) {
    if (sortCol === col) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        sortCol = col;
        sortDir = col === 'branch_name' ? 'asc' : 'desc';
    }

    document.querySelectorAll('#branchTable th').forEach(th => {
        th.classList.remove('sorted');
        const icon = th.querySelector('.sort-icon i');
        if (icon) icon.className = 'fa-solid fa-sort';
    });
    const activeTh = document.querySelector(`#branchTable th[data-col="${col}"]`);
    if (activeTh) {
        activeTh.classList.add('sorted');
        const icon = activeTh.querySelector('.sort-icon i');
        if (icon) icon.className = sortDir === 'asc' ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down';
    }

    curPage = 1;
    applyFiltersAndRender();
}

/* ── Pagination render ──────────────────────────────────────── */
function renderPagination(total) {
    const pages = Math.ceil(total / perPage) || 1;
    const start = total ? (curPage - 1) * perPage + 1 : 0;
    const end   = Math.min(curPage * perPage, total);

    document.getElementById('pageInfo').textContent =
        total ? `${start}–${end} of ${total} branch${total !== 1 ? 'es' : ''}` : 'No branches';

    let btns = '';
    btns += `<button class="page-btn" ${curPage===1?'disabled':''} onclick="goPage(${curPage-1})">
                <i class="fa-solid fa-chevron-left"></i></button>`;

    pageRange(curPage, pages).forEach(p => {
        if (p === '…') btns += `<button class="page-btn" disabled>…</button>`;
        else btns += `<button class="page-btn ${p===curPage?'active':''}" onclick="goPage(${p})">${p}</button>`;
    });

    btns += `<button class="page-btn" ${curPage===pages?'disabled':''} onclick="goPage(${curPage+1})">
                <i class="fa-solid fa-chevron-right"></i></button>`;

    document.getElementById('pageBtns').innerHTML = btns;
}

function pageRange(cur, total) {
    if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
    if (cur <= 4)          return [1, 2, 3, 4, 5, '…', total];
    if (cur >= total - 3)  return [1, '…', total-4, total-3, total-2, total-1, total];
    return [1, '…', cur-1, cur, cur+1, '…', total];
}

function goPage(p) { curPage = p; applyFiltersAndRender(); }

function updateTableSubLabel(total) {
    const filtered = total !== allBranches.length ? ` (${total} of ${allBranches.length} filtered)` : ` — ${allBranches.length} branches`;
    document.getElementById('tableSubLabel').textContent = filtered;
}

/* ── KPI Strip ──────────────────────────────────────────────── */
function renderKPIs(data) {
    const branches  = data.branches || [];
    const withTx    = branches.filter(b => b.tx_count > 0).length;
    const totalRev  = branches.reduce((s,b) => s + b.total_revenue, 0);
    const totalTx   = branches.reduce((s,b) => s + b.tx_count, 0);
    const totalDisc = branches.reduce((s,b) => s + b.total_discount, 0);
    const declining = branches.filter(b => b.declining).length;

    document.getElementById('kpiBranches').textContent  = withTx;
    document.getElementById('kpiRevenue').textContent   = peso(totalRev);
    document.getElementById('kpiTx').textContent        = fmt(totalTx);
    document.getElementById('kpiDiscount').textContent  = peso(totalDisc);
    document.getElementById('kpiDeclining').textContent = declining;
}

/* ── Donut chart ────────────────────────────────────────────── */
function renderDonut(data) {
    const branches = (data.branches || []).filter(b => b.tx_count > 0);
    const labels   = branches.map(b => b.branch_name);
    const values   = branches.map(b => b.total_revenue);
    const total    = values.reduce((s,v) => s+v, 0);

    if (donutChartInst) donutChartInst.destroy();

    const ctx = document.getElementById('donutChart').getContext('2d');
    donutChartInst = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data:            values,
                backgroundColor: PALETTE.slice(0, labels.length),
                borderWidth:     2,
                borderColor:     '#fff',
                hoverBorderWidth: 3,
            }],
        },
        options: {
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${peso(ctx.parsed)} (${(ctx.parsed/total*100).toFixed(1)}%)`,
                    },
                },
            },
        },
    });

    document.getElementById('donutCenterVal').textContent = peso(total);
}

/* ── Discount vs Grand Total bar chart ─────────────────────── */
function renderDiscountChart(data) {
    const branches = (data.branches || []).filter(b => b.tx_count > 0).slice(0, 18);
    const labels   = branches.map(b => b.branch_name.length > 18 ? b.branch_name.slice(0,16)+'…' : b.branch_name);

    if (discountChartInst) discountChartInst.destroy();

    const ctx = document.getElementById('discountChart').getContext('2d');
    discountChartInst = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label:           'Grand Total',
                    data:            branches.map(b => b.total_revenue),
                    backgroundColor: 'rgba(13,148,136,.75)',
                    borderRadius:    4,
                    order:           2,
                },
                {
                    label:           'Total Discount',
                    data:            branches.map(b => b.total_discount),
                    backgroundColor: 'rgba(239,68,68,.7)',
                    borderRadius:    4,
                    order:           1,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${peso(ctx.parsed.y)}`,
                    },
                },
            },
            scales: {
                x: { ticks: { font: { size: 10 }, maxRotation: 45 }, grid: { display: false } },
                y: {
                    ticks: {
                        callback: v => '₱' + (v >= 1000 ? (v/1000).toFixed(0)+'k' : v),
                        font: { size: 10 },
                    },
                    grid: { color: '#f1f5f9' },
                },
            },
        },
    });
}

/* ── VAT section ────────────────────────────────────────────── */
function renderVAT(data) {
    const branches  = (data.branches || []).filter(b => b.total_vat > 0)
        .sort((a,b) => b.total_vat - a.total_vat);
    const container = document.getElementById('vatSection');

    if (!branches.length) {
        container.innerHTML = '<p style="font-size:13px;color:var(--ink-4);">No VAT recorded in this period.</p>';
        return;
    }

    container.innerHTML = branches.map(b => `
        <div class="vat-card">
            <div class="vat-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            <div class="vat-info">
                <div class="vat-branch" title="${b.branch_name}">${b.branch_name}</div>
                <div class="vat-amount">${peso(b.total_vat)}</div>
            </div>
        </div>
    `).join('');
}

/* ── Table rows ─────────────────────────────────────────────── */
function renderTableRows(rows) {
    const tbody = document.getElementById('branchTableBody');

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="9">
            <div class="empty-state">
                <i class="fa-solid fa-store-slash"></i>
                <strong>No branches found</strong>
                <p>Try adjusting your search or filter.</p>
            </div>
        </td></tr>`;
        return;
    }

    const maxRev = Math.max(...filteredBranches.map(r => r.total_revenue), 1);

    tbody.innerHTML = rows.map(b => {
        const momBadge = b.mom_growth === null
            ? `<span class="badge neutral">— N/A</span>`
            : b.mom_growth >= 0
                ? `<span class="badge growth"><i class="fa-solid fa-arrow-trend-up"></i> ${pct(b.mom_growth)}</span>`
                : `<span class="badge decline"><i class="fa-solid fa-arrow-trend-down"></i> ${pct(b.mom_growth)}</span>`;

        const statusBadge = b.declining
            ? `<span class="decline-flag"><i class="fa-solid fa-triangle-exclamation"></i> Declining</span>`
            : `<span class="badge neutral" style="background:var(--growth-bg);color:var(--growth-clr);">
                 <i class="fa-solid fa-circle-check"></i> Healthy
               </span>`;

        const barPct = Math.round(b.total_revenue / maxRev * 100);

        return `
        <tr class="branch-row" onclick="openDrill(${b.branch_id})" title="Click to view drill-down">
            <td style="font-weight:500;color:var(--ink);">${b.branch_name}</td>
            <td class="mono">${peso(b.total_revenue)}</td>
            <td class="mono">${fmt(b.tx_count)}</td>
            <td class="mono">${peso(b.avg_ticket)}</td>
            <td>
                <div class="rev-bar-wrap">
                    <div class="rev-bar-bg">
                        <div class="rev-bar-fill" style="width:${barPct}%;"></div>
                    </div>
                    <span class="rev-bar-pct">${b.revenue_share.toFixed(1)}%</span>
                </div>
            </td>
            <td>${momBadge}</td>
            <td>
                <canvas class="spark-canvas" id="spark-${b.branch_id}" width="90" height="32"></canvas>
            </td>
            <td class="mono">${peso(b.total_vat)}</td>
            <td>${statusBadge}</td>
        </tr>`;
    }).join('');

    // Draw sparklines after DOM insert
    requestAnimationFrame(() => {
        rows.forEach(b => drawSparkline(b.branch_id, b.sparkline || [], b.declining));
    });
}

/* ── Sparkline ──────────────────────────────────────────────── */
function drawSparkline(bid, data, declining) {
    const canvas = document.getElementById(`spark-${bid}`);
    if (!canvas) return;
    if (sparkCharts[bid]) sparkCharts[bid].destroy();

    const color = declining ? '#e11d48' : '#0d9488';
    sparkCharts[bid] = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels:   data.map((_,i) => i),
            datasets: [{
                data,
                borderColor:     color,
                borderWidth:     1.5,
                pointRadius:     0,
                fill:            true,
                backgroundColor: declining ? 'rgba(225,29,72,.08)' : 'rgba(13,148,136,.08)',
                tension:         0.3,
            }],
        },
        options: {
            animation:  false,
            responsive: false,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales:  { x: { display: false }, y: { display: false } },
        },
    });
}

/* ── Drill-down ─────────────────────────────────────────────── */
async function openDrill(branchId) {
    const branch = allBranches.find(b => b.branch_id === branchId);
    if (!branch) return;

    document.getElementById('drillTitle').textContent = branch.branch_name;

    document.getElementById('drillKpis').innerHTML = `
        <div class="drill-kpi">
            <div class="drill-kpi-label">Total Revenue</div>
            <div class="drill-kpi-value">${peso(branch.total_revenue)}</div>
        </div>
        <div class="drill-kpi">
            <div class="drill-kpi-label">Transactions</div>
            <div class="drill-kpi-value">${fmt(branch.tx_count)}</div>
        </div>
        <div class="drill-kpi">
            <div class="drill-kpi-label">Avg Ticket</div>
            <div class="drill-kpi-value">${peso(branch.avg_ticket)}</div>
        </div>
        <div class="drill-kpi">
            <div class="drill-kpi-label">VAT Collected</div>
            <div class="drill-kpi-value">${peso(branch.total_vat)}</div>
        </div>
        <div class="drill-kpi">
            <div class="drill-kpi-label">Total Discount</div>
            <div class="drill-kpi-value">${peso(branch.total_discount)}</div>
        </div>
        <div class="drill-kpi">
            <div class="drill-kpi-label">MoM Growth</div>
            <div class="drill-kpi-value" style="color:${branch.mom_growth >= 0 ? 'var(--growth-clr)' : 'var(--decline-clr)'};">
                ${pct(branch.mom_growth)}
            </div>
        </div>
    `;

    const { date_from, date_to } = getDateRange();
    document.getElementById('drillTxLink').href =
        `?page=data-management&tab=transactions&branch_id=${branchId}`;

    document.getElementById('drillModalOverlay').classList.add('open');

    try {
        const res  = await fetch(`${API}?endpoint=branch-performance/${branchId}/transactions&preset=custom&date_from=${date_from}&date_to=${date_to}&per_page=500`);
        const data = await res.json();
        // Build monthly aggregation from transactions for the chart
        const monthMap = {};
        (data.transactions || []).forEach(t => {
            const ym = t.date.slice(0, 7);
            if (!monthMap[ym]) monthMap[ym] = { ym, revenue: 0, tx_count: 0 };
            monthMap[ym].revenue  += t.grand_total;
            monthMap[ym].tx_count += 1;
        });
        const monthly = Object.values(monthMap).sort((a,b) => a.ym.localeCompare(b.ym));
        renderDrillChart(monthly);
    } catch (e) {
        showToast('Could not load monthly trend.', 'error');
    }
}

function renderDrillChart(monthly) {
    if (drillChart) drillChart.destroy();

    const ctx = document.getElementById('drillChart').getContext('2d');
    drillChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels:   monthly.map(r => r.ym),
            datasets: [
                {
                    type:            'bar',
                    label:           'Revenue',
                    data:            monthly.map(r => r.revenue),
                    backgroundColor: 'rgba(13,148,136,.7)',
                    borderRadius:    5,
                    yAxisID:         'yRev',
                    order:           2,
                },
                {
                    type:        'line',
                    label:       'Transactions',
                    data:        monthly.map(r => r.tx_count),
                    borderColor: '#f59e0b',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointBackgroundColor: '#f59e0b',
                    fill:        false,
                    tension:     0.3,
                    yAxisID:     'yTx',
                    order:       1,
                },
            ],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label === 'Revenue'
                            ? ` Revenue: ${peso(ctx.parsed.y)}`
                            : ` Transactions: ${ctx.parsed.y}`,
                    },
                },
            },
            scales: {
                yRev: {
                    type: 'linear', position: 'left',
                    ticks: { callback: v => '₱' + (v >= 1000 ? (v/1000).toFixed(0)+'k' : v), font: { size: 10 } },
                    grid:  { color: '#f1f5f9' },
                },
                yTx: {
                    type: 'linear', position: 'right',
                    ticks: { font: { size: 10 } },
                    grid:  { display: false },
                },
            },
        },
    });
}

function closeDrill() {
    document.getElementById('drillModalOverlay').classList.remove('open');
    if (drillChart) { drillChart.destroy(); drillChart = null; }
}

document.getElementById('drillModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeDrill();
});

/* ── Topbar date ─────────────────────────────────────────────── */
function updateTopbarDate() {
    document.getElementById('topbarDate').textContent = new Date().toLocaleDateString('en-PH', {
        weekday:'short', year:'numeric', month:'short', day:'numeric',
    });
}

/* ── Sidebar toggle ──────────────────────────────────────────── */
function initSidebar() {
    const toggle  = document.getElementById('menuToggle');
    const sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
}

/* ── Boot ────────────────────────────────────────────────────── */
(function init() {
    resetDates();
    updateTopbarDate();
    initSidebar();
    loadData();
})();
</script>
</body>
</html>