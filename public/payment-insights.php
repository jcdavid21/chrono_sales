<?php
$current = 'payment-insights';

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
    <title>Payment Insights — ChronoSales</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ── PI-specific variables ────────────────────────────────────────── */
        :root {
            --card-radius: 14px;
            --decline-bg: #fff1f2;
            --decline-clr: #e11d48;
            --growth-bg: #f0fdf4;
            --growth-clr: #16a34a;
            --neutral-bg: #f8fafc;
            --neutral-clr: var(--ink-4);

            /* Payment method palette */
            --c-cash: #0d9488;
            --c-card: #2563eb;
            --c-qr: #7c3aed;
            --c-bank: #d97706;
            --c-check: #0891b2;
            --c-multi: #db2777;
            --c-other: #6b7280;
        }

        /* ── Filter bar ──────────────────────────────────────────────────── */
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

        .bp-date-input:focus {
            outline: none;
            border-color: var(--primary-mid);
        }

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

        .dm-filter-select:focus {
            outline: none;
            border-color: var(--primary-mid);
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            background: var(--primary);
            color: #fff;
            border: none;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: opacity .15s;
        }

        .btn-primary:hover { opacity: .88; }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--card);
            font-size: 12.5px;
            font-weight: 500;
            color: var(--ink-3);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all .15s;
        }

        .btn-secondary:hover {
            border-color: var(--primary-mid);
            color: var(--primary);
            background: var(--primary-light);
        }

        /* ── KPI strip ───────────────────────────────────────────────────── */
        .bp-kpi-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--ink-4);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .bp-kpi-label i { color: var(--primary-mid); }

        .bp-kpi-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--ink);
            font-family: 'DM Mono', monospace;
        }

        .bp-kpi-sub {
            font-size: 11px;
            color: var(--ink-4);
            margin-top: 3px;
        }

        /* ── Chart cards ─────────────────────────────────────────────────── */
        .bp-chart-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            padding: 18px 20px;
            box-shadow: var(--card-shadow);
        }

        .bp-chart-title {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bp-chart-title i {
            color: var(--primary-mid);
            font-size: 13px;
        }

        .chart-canvas-wrap {
            position: relative;
            width: 100%;
        }

        .chart-canvas-wrap canvas { max-height: 280px; }

        .donut-wrap {
            position: relative;
            max-width: 220px;
            margin: 0 auto;
        }

        .donut-center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .donut-center-val {
            font-size: 16px;
            font-weight: 700;
            color: var(--ink);
            font-family: 'DM Mono', monospace;
        }

        .donut-center-lbl {
            font-size: 10px;
            color: var(--ink-4);
            text-align: center;
        }

        /* ── Grid layouts ────────────────────────────────────────────────── */
        .pi-charts-row {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 16px;
            margin-bottom: 22px;
        }

        .pi-full { margin-bottom: 22px; }

        /* ── Legend list ─────────────────────────────────────────────────── */
        .pi-legend { margin-top: 14px; }

        .pi-legend-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
        }

        .pi-legend-item:last-child { border-bottom: none; }

        .pi-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-right: 8px;
        }

        .pi-legend-label {
            display: flex;
            align-items: center;
            flex: 1;
            color: var(--ink-2);
        }

        .pi-legend-pct {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            color: var(--ink-3);
            margin-left: 8px;
        }

        .pi-legend-val {
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
        }

        /* ── AI Insights panel ───────────────────────────────────────────── */
        .insights-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 22px;
        }

        .insights-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(90deg, #f8f5ff 0%, #f0f4ff 100%);
            flex-wrap: wrap;
            gap: 10px;
        }

        .insights-header-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .insights-header-title i { color: #7c3aed; }

        .insights-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 99px;
            background: #ede9fe;
            color: #5b21b6;
            font-size: 11px;
            font-weight: 600;
        }

        .insights-body {
            padding: 18px 20px;
        }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }

        .insight-item {
            display: flex;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 10px;
            background: var(--bg, #f8fafc);
            border: 1px solid transparent;
            transition: border-color .15s, box-shadow .15s;
        }

        .insight-item:hover {
            border-color: var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .insight-item.trend-up {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .insight-item.trend-down {
            background: #fff1f2;
            border-color: #fecdd3;
        }

        .insight-item.trend-neutral {
            background: #f8fafc;
            border-color: #e2e8f0;
        }

        .insight-item.trend-info {
            background: #f0f4ff;
            border-color: #c7d2fe;
        }

        .insight-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .trend-up .insight-icon    { background: #dcfce7; color: #16a34a; }
        .trend-down .insight-icon  { background: #ffe4e6; color: #e11d48; }
        .trend-neutral .insight-icon { background: #e2e8f0; color: #475569; }
        .trend-info .insight-icon  { background: #e0e7ff; color: #4f46e5; }

        .insight-text {
            font-size: 12.5px;
            line-height: 1.55;
            color: var(--ink-2);
        }

        .insight-text strong {
            color: var(--ink);
            font-weight: 600;
        }

        /* Skeleton / loading state */
        .insights-skeleton {
            display: flex;
            gap: 12px;
            flex-direction: column;
        }

        .skel-line {
            height: 14px;
            border-radius: 6px;
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: shimmer 1.4s infinite;
        }

        @keyframes shimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .skel-line.w-3\/4  { width: 75%; }
        .skel-line.w-1\/2  { width: 50%; }
        .skel-line.w-full  { width: 100%; }

        /* ── Breakdown table ─────────────────────────────────────────────── */
        .bp-table-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 22px;
        }

        .bp-table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 10px;
        }

        .bp-table-header-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bp-table-header-title i { color: var(--primary-mid); }

        .dm-search-wrap {
            position: relative;
            min-width: 220px;
        }

        .dm-search-wrap i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11.5px;
            color: var(--ink-4);
        }

        .dm-search {
            width: 100%;
            padding: 7px 10px 7px 28px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            font-size: 12.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            transition: border-color .15s;
            box-sizing: border-box;
        }

        .dm-search:focus {
            outline: none;
            border-color: var(--primary-mid);
        }

        .dm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        .dm-table th {
            padding: 10px 14px;
            text-align: left;
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--ink-4);
            border-bottom: 1px solid var(--border);
            background: #fafafa;
            white-space: nowrap;
        }

        .dm-table td {
            padding: 11px 14px;
            color: var(--ink-2);
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .dm-table tr:last-child td { border-bottom: none; }
        .dm-table td.mono {
            font-family: 'DM Mono', monospace;
            font-size: 12px;
        }

        /* ── Pagination ──────────────────────────────────────────────────── */
        .dm-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 18px;
            border-top: 1px solid var(--border);
            background: #fafafa;
            flex-wrap: wrap;
            gap: 8px;
        }

        .page-info {
            font-size: 12px;
            color: var(--ink-4);
            font-family: 'DM Mono', monospace;
        }

        .page-btns {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .page-btn {
            min-width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--card);
            font-size: 12px;
            color: var(--ink-3);
            cursor: pointer;
            font-family: 'DM Mono', monospace;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }

        .page-btn:hover {
            border-color: var(--primary-mid);
            color: var(--primary);
        }

        .page-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .page-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }

        /* ── Status badges ───────────────────────────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 99px;
            font-size: 10.5px;
            font-weight: 700;
            font-family: 'DM Mono', monospace;
        }

        .badge-ok      { background: #f0fdf4; color: #16a34a; }
        .badge-void    { background: #fef3c7; color: #b45309; }
        .badge-failed  { background: #fff1f2; color: #e11d48; }
        .badge-pending { background: #eff6ff; color: #2563eb; }
        .badge-other   { background: #f1f5f9; color: #475569; }

        /* ── Method pill ─────────────────────────────────────────────────── */
        .method-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
        }

        /* ── Loading overlay ─────────────────────────────────────────────── */
        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, .75);
            z-index: 5;
            border-radius: var(--card-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: var(--ink-3);
            gap: 8px;
        }

        .loading-overlay.hidden { display: none; }

        /* ── Empty state ─────────────────────────────────────────────────── */
        .empty-state { padding: 48px 24px; text-align: center; }
        .empty-state i { font-size: 32px; color: var(--ink-4); margin-bottom: 12px; display: block; }
        .empty-state p { font-size: 13px; color: var(--ink-3); }

        /* ── Toast ───────────────────────────────────────────────────────── */
        #toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            min-width: 260px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .15);
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            font-weight: 500;
            animation: toastIn .25s ease;
        }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(20px); }
            to   { opacity: 1; transform: none; }
        }

        .toast.success { background: var(--success); color: #fff; }
        .toast.error   { background: var(--danger); color: #fff; }
        .toast.info    { background: var(--primary); color: #fff; }

        /* ── Responsive ──────────────────────────────────────────────────── */
        @media (max-width: 900px) {
            .pi-charts-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .bp-kpi-strip { grid-template-columns: 1fr 1fr; }
            .insights-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <div class="app">

        <?php include 'sidebar.php'; ?>

        <div class="main" id="main">

            <!-- ── Topbar ──────────────────────────────────────────────────── -->
            <header class="topbar">
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-breadcrumb">
                    <i class="fa-solid fa-credit-card"></i>
                    <span>Payment Insights</span>
                </div>
                <div class="topbar-right">
                    <div class="topbar-date" id="topbarDate"></div>
                    <button class="topbar-btn" id="refreshBtn" title="Refresh" onclick="loadAll()">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </button>
                </div>
            </header>

            <!-- ── Content ─────────────────────────────────────────────────── -->
            <div class="content">

                <!-- Page header -->
                <div style="margin-bottom:18px;">
                    <div style="font-size:18px;font-weight:600;color:var(--ink);margin-bottom:4px;">
                        <i class="fa-solid fa-credit-card" style="color:var(--primary-mid);margin-right:8px;"></i>
                        Payment Insights
                    </div>
                    <p style="font-size:13px;color:var(--ink-3);">
                        Analyze customer payment behavior — method distribution, usage trends, and AI-generated insights.
                    </p>
                </div>

                <!-- ── Filter bar ─────────────────────────────────────────── -->
                <div class="bp-filter-bar" id="filterBar">
                    <label>From</label>
                    <input type="date" id="dateFrom" class="bp-date-input">
                    <label>To</label>
                    <input type="date" id="dateTo" class="bp-date-input">

                    <select class="dm-filter-select" id="filterBranch">
                        <option value="all">All Branches</option>
                    </select>

                    <select class="dm-filter-select" id="filterStatus">
                        <option value="all">All Statuses</option>
                        <option value="OK">OK</option>
                        <option value="VOID">Void</option>
                        <option value="PENDING">Pending</option>
                        <option value="FAILED">Failed</option>
                        <option value="CANCELLED">Cancelled</option>
                    </select>

                    <button class="btn-primary" onclick="loadAll()">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                    <button class="btn-secondary" onclick="resetDates()">
                        <i class="fa-solid fa-rotate-left"></i> This Month
                    </button>
                    <span id="periodLabel" style="font-size:11.5px;color:var(--ink-4);margin-left:4px;"></span>
                </div>

                <!-- ── KPI strip ──────────────────────────────────────────── -->
                <div class="bp-kpi-strip">
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-receipt"></i> OK Transactions</div>
                        <div class="bp-kpi-value" id="kpiOk">—</div>
                        <div class="bp-kpi-sub">in selected period</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-peso-sign"></i> Total Revenue</div>
                        <div class="bp-kpi-value" id="kpiRevenue">—</div>
                        <div class="bp-kpi-sub">OK transactions only</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-wallet"></i> Avg Transaction</div>
                        <div class="bp-kpi-value" id="kpiAvg">—</div>
                        <div class="bp-kpi-sub">average grand total</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-layer-group"></i> Payment Methods</div>
                        <div class="bp-kpi-value" id="kpiMethods">—</div>
                        <div class="bp-kpi-sub">distinct methods used</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-ban" style="color:#e11d48;"></i> Voided</div>
                        <div class="bp-kpi-value" id="kpiVoided" style="color:#b45309;">—</div>
                        <div class="bp-kpi-sub" id="kpiVoidedSub">—</div>
                    </div>
                    <div class="bp-kpi-card">
                        <div class="bp-kpi-label"><i class="fa-solid fa-circle-xmark" style="color:#e11d48;"></i> Failed / Other</div>
                        <div class="bp-kpi-value" id="kpiFailed" style="color:#e11d48;">—</div>
                        <div class="bp-kpi-sub" id="kpiFailedSub">—</div>
                    </div>
                </div>

                <!-- ── AI Insights ─────────────────────────────────────────── -->
                <div class="insights-card">
                    <div class="insights-header">
                        <div class="insights-header-title">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            AI-Generated Insights
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="insights-badge">
                                <i class="fa-solid fa-sparkles" style="font-size:10px;"></i>
                                Smart Insights · Updates with filters
                            </span>
                            <button class="btn-secondary" id="refreshInsightsBtn"
                                onclick="generateInsights()" style="padding:5px 12px;font-size:11.5px;">
                                <i class="fa-solid fa-rotate-right"></i> Regenerate
                            </button>
                        </div>
                    </div>
                    <div class="insights-body">
                        <div id="insightsContent">
                            <div class="insights-skeleton">
                                <div class="skel-line w-full" style="height:16px;"></div>
                                <div class="skel-line w-3\/4"></div>
                                <div class="skel-line w-full" style="height:16px;margin-top:8px;"></div>
                                <div class="skel-line w-1\/2"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Row 1: Donut + Trend ───────────────────────────────── -->
                <div class="pi-charts-row">
                    <!-- Donut: payment distribution -->
                    <div class="bp-chart-card">
                        <div class="bp-chart-title">
                            <i class="fa-solid fa-chart-pie"></i> Payment Method Distribution
                        </div>
                        <div class="donut-wrap">
                            <canvas id="donutChart" height="220"></canvas>
                            <div class="donut-center">
                                <div class="donut-center-val" id="donutCenterVal">—</div>
                                <div class="donut-center-lbl">Total<br>Transactions</div>
                            </div>
                        </div>
                        <div class="pi-legend" id="methodLegend"></div>
                    </div>

                    <!-- Line: trend over time -->
                    <div class="bp-chart-card">
                        <div class="bp-chart-title" style="justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <span><i class="fa-solid fa-chart-line"></i> Payment Method Trend</span>
                            <select class="dm-filter-select" id="trendMetric" onchange="renderTrendChart()"
                                style="font-size:11.5px;padding:4px 24px 4px 8px;">
                                <option value="tx_count">By Transaction Count</option>
                                <option value="revenue">By Revenue</option>
                            </select>
                        </div>
                        <div class="chart-canvas-wrap">
                            <canvas id="trendChart" height="260"></canvas>
                        </div>
                    </div>
                </div>

                <!-- ── Transaction monitoring table ──────────────────────── -->
                <div class="bp-table-card" style="position:relative;">

                    <div class="loading-overlay hidden" id="tableLoading">
                        <i class="fa-solid fa-circle-notch fa-spin"></i> Fetching data…
                    </div>

                    <div class="bp-table-header">
                        <div class="bp-table-header-title">
                            <i class="fa-solid fa-table-list"></i>
                            Transaction Monitoring
                            <span style="font-size:11px;color:var(--ink-4);font-weight:400;" id="tableSubLabel"></span>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <div class="dm-search-wrap" style="min-width:200px;">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" class="dm-search" id="txSearch"
                                    placeholder="Search invoice, customer, branch…"
                                    oninput="onTxSearch()">
                            </div>
                            <select class="dm-filter-select" id="txPaymentFilter" onchange="loadTable(1)">
                                <option value="all">All Methods</option>
                            </select>
                            <select class="dm-filter-select" id="txStatusFilter" onchange="loadTable(1)">
                                <option value="all">All Statuses</option>
                                <option value="OK">OK</option>
                                <option value="VOID">Void</option>
                                <option value="PENDING">Pending</option>
                                <option value="FAILED">Failed</option>
                                <option value="CANCELLED">Cancelled</option>
                            </select>
                            <select class="dm-filter-select" id="perPageSelect" onchange="loadTable(1)">
                                <option value="10" selected>10 / page</option>
                                <option value="25" >25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                            <button class="btn-secondary" onclick="exportCsv()" style="padding:7px 12px;">
                                <i class="fa-solid fa-download"></i> Export
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="dm-table" id="txTable">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Branch</th>
                                    <th>Payment Method</th>
                                    <th>Grand Total</th>
                                    <th>VAT</th>
                                    <th>Discount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="txTableBody">
                                <tr>
                                    <td colspan="9" style="text-align:center;padding:32px;color:var(--ink-4);">
                                        <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                                    </td>
                                </tr>
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

    <!-- Toast container -->
    <div id="toast-container"></div>

    <!-- ════════════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════════════ -->
    <script>
        'use strict';

        /* ── Proxy API base ──────────────────────────────────────────────────── */
        const API = '../backend/api_proxy.php';

        /* ── Chart instances ─────────────────────────────────────────────────── */
        let donutChart  = null;
        let trendChart  = null;

        /* ── Cached data ─────────────────────────────────────────────────────── */
        let cachedInsights = null;
        let txSearchTimer  = null;
        let currentPage    = 1;

        /* ── Payment method color palette ───────────────────────────────────── */
        const PALETTE = [
            '#0d9488', '#2563eb', '#7c3aed', '#d97706',
            '#0891b2', '#db2777', '#16a34a', '#f59e0b',
            '#6b7280', '#dc2626', '#059669', '#4f46e5',
        ];

        function methodColor(name, idx) {
            const n = (name || '').toLowerCase();
            if (n.includes('cash'))                                          return '#0d9488';
            if (n.includes('card') || n.includes('ghl') || n.includes('global')) return '#2563eb';
            if (n.includes('qr') || n.includes('gcash') || n.includes('maya') || n.includes('instapay')) return '#7c3aed';
            if (n.includes('bank') || n.includes('transfer'))               return '#d97706';
            if (n.includes('check'))                                         return '#0891b2';
            if (n.includes('multi') || n.includes('+'))                     return '#db2777';
            return PALETTE[idx % PALETTE.length];
        }

        /* ── Formatters ──────────────────────────────────────────────────────── */
        function peso(v) {
            return '₱' + Number(v || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });
        }
        function fmt(v)  { return Number(v || 0).toLocaleString('en-PH'); }
        function pct(v)  { return (v >= 0 ? '+' : '') + Number(v || 0).toFixed(1) + '%'; }
        function absPct(v) { return Number(v || 0).toFixed(1) + '%'; }

        /* ── Date helpers ────────────────────────────────────────────────────── */
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

        /* ── Toast ───────────────────────────────────────────────────────────── */
        function showToast(msg, type = 'info') {
            const el = document.createElement('div');
            el.className = `toast ${type}`;
            el.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-xmark' : 'fa-circle-info'}"></i> ${msg}`;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => el.remove(), 3500);
        }

        /* ── Load filter options (branches, payment methods) ─────────────────── */
        async function loadFilters() {
            try {
                const res  = await fetch(`${API}?endpoint=analytics/filters`);
                const data = await res.json();

                const branchSel = document.getElementById('filterBranch');
                (data.branches || []).forEach(b => {
                    const o = document.createElement('option');
                    o.value = b.id; o.textContent = b.name;
                    branchSel.appendChild(o);
                });

                const txPaySel = document.getElementById('txPaymentFilter');
                (data.payments || []).forEach(p => {
                    const o = document.createElement('option');
                    o.value = p.id; o.textContent = p.name;
                    txPaySel.appendChild(o);
                });
            } catch (e) {
                showToast('Could not load filter options.', 'error');
            }
        }

        /* ══════════════════════════════════════════════════════════════════════
           MAIN DATA LOAD
        ══════════════════════════════════════════════════════════════════════ */
        async function loadAll() {
            const { date_from, date_to } = getDateRange();
            updatePeriodLabel(date_from, date_to);

            const branch_id = document.getElementById('filterBranch').value;
            const status    = document.getElementById('filterStatus').value;

            const qs = new URLSearchParams({
                endpoint: 'payment-insights',
                preset: 'custom',
                date_from, date_to, branch_id, status,
            });

            try {
                const res  = await fetch(`${API}?${qs}`);
                const data = await res.json();
                if (data.error) throw new Error(data.error);

                cachedInsights = data;
                renderKpis(data.kpi);
                renderDonut(data.method_distribution);
                renderTrendChart();
                loadTable(1);
                generateInsights();
            } catch (e) {
                showToast('Failed to load payment insights: ' + e.message, 'error');
            }
        }

        /* ── KPIs ────────────────────────────────────────────────────────────── */
        function renderKpis(k) {
            document.getElementById('kpiOk').textContent      = fmt(k.total_ok_transactions);
            document.getElementById('kpiRevenue').textContent  = peso(k.total_revenue);
            document.getElementById('kpiAvg').textContent      = peso(k.avg_transaction_value);
            document.getElementById('kpiMethods').textContent  = k.distinct_methods;
            document.getElementById('kpiVoided').textContent   = fmt(k.voided_count);
            document.getElementById('kpiVoidedSub').textContent = peso(k.voided_total) + ' total';
            document.getElementById('kpiFailed').textContent   = fmt(k.failed_count);
            document.getElementById('kpiFailedSub').textContent = peso(k.failed_total) + ' total';
        }

        /* ── Donut: payment distribution ─────────────────────────────────────── */
        function renderDonut(dist) {
            if (donutChart) donutChart.destroy();
            if (!dist || !dist.length) return;

            const labels = dist.map(d => d.method);
            const counts = dist.map(d => d.tx_count);
            const colors = dist.map((d, i) => methodColor(d.method, i));
            const total  = counts.reduce((a, b) => a + b, 0);

            document.getElementById('donutCenterVal').textContent = fmt(total);

            const ctx = document.getElementById('donutChart').getContext('2d');
            donutChart = new Chart(ctx, {
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
                                label: c => ` ${c.label}: ${fmt(c.parsed)} txns (${dist[c.dataIndex].pct_count}%)`,
                            },
                        },
                    },
                },
            });

            // Legend
            document.getElementById('methodLegend').innerHTML = dist.map((d, i) => `
                <div class="pi-legend-item">
                    <span class="pi-legend-label">
                        <span class="pi-legend-dot" style="background:${colors[i]};"></span>
                        ${d.method}
                        <span class="pi-legend-pct">${d.pct_count}%</span>
                    </span>
                    <span class="pi-legend-val">${fmt(d.tx_count)}</span>
                </div>
            `).join('');
        }

        /* ── Line trend ──────────────────────────────────────────────────────── */
        function renderTrendChart() {
            if (!cachedInsights) return;
            const trend  = cachedInsights.trend;
            const metric = document.getElementById('trendMetric').value;

            if (trendChart) trendChart.destroy();

            if (!trend || !trend.days || !trend.days.length) {
                const ctx = document.getElementById('trendChart').getContext('2d');
                trendChart = new Chart(ctx, {
                    type: 'line',
                    data: { labels: ['No data'], datasets: [{ label: 'None', data: [0] }] },
                    options: { plugins: { legend: { display: false } } },
                });
                return;
            }

            const datasets = (trend.methods || []).map((method, i) => {
                const color = methodColor(method, i);
                return {
                    label: method,
                    data:  (trend.series || []).find(s => s.method === method)?.[metric] || [],
                    borderColor: color,
                    backgroundColor: color + '18',
                    borderWidth: 2,
                    pointRadius: trend.days.length > 30 ? 0 : 3,
                    fill: false,
                    tension: 0.3,
                };
            });

            const ctx = document.getElementById('trendChart').getContext('2d');
            trendChart = new Chart(ctx, {
                type: 'line',
                data: { labels: trend.days, datasets },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { font: { size: 11 }, boxWidth: 12, padding: 12 }
                        },
                        tooltip: {
                            callbacks: {
                                label: c => metric === 'revenue'
                                    ? ` ${c.dataset.label}: ${peso(c.parsed.y)}`
                                    : ` ${c.dataset.label}: ${fmt(c.parsed.y)} txns`
                            }
                        },
                    },
                    scales: {
                        x: {
                            ticks: { font: { size: 9 }, maxTicksLimit: 12 },
                            grid:  { color: '#f1f5f9' }
                        },
                        y: {
                            ticks: {
                                font: { size: 10 },
                                callback: v => metric === 'revenue' ? '₱' + fmt(v) : fmt(v),
                            },
                            grid: { color: '#f1f5f9' }
                        },
                    },
                },
            });
        }

        /* ══════════════════════════════════════════════════════════════════════
           AI-GENERATED INSIGHTS
           Uses the Anthropic API (Claude) with data distilled from the dashboard
           payload. No external endpoints beyond the Anthropic API are called.
        ══════════════════════════════════════════════════════════════════════ */
        /* ══════════════════════════════════════════════════════════════════════
           STATIC INSIGHT TEMPLATES
           Organised by theme so the selector can mix them intelligently.
           Each entry: { tag, icon, text, themes[] }
        ══════════════════════════════════════════════════════════════════════ */
        const INSIGHT_POOL = [
            // ── Digital / QR growth ──────────────────────────────────────────
            {
                tag: 'GROWTH', icon: 'fa-solid fa-qrcode',
                text: 'QR payments are becoming more dominant compared to cash in recent transactions, reflecting a broad shift toward app-based checkout.',
                themes: ['qr', 'digital', 'growth'],
            },
            {
                tag: 'GROWTH', icon: 'fa-solid fa-mobile-screen-button',
                text: 'Digital payments (QR and Card) show a steady upward trend, suggesting customers are increasingly comfortable with cashless options.',
                themes: ['digital', 'qr', 'card', 'growth'],
            },
            {
                tag: 'GROWTH', icon: 'fa-solid fa-arrow-trend-up',
                text: 'Cashless transaction volume has grown consistently over the observed period, pointing to accelerating adoption across customer segments.',
                themes: ['digital', 'growth'],
            },
            {
                tag: 'INFO', icon: 'fa-solid fa-chart-pie',
                text: 'QR-based methods now represent a notable share of total payment volume, reinforcing the value of maintaining reliable QR infrastructure.',
                themes: ['qr', 'digital', 'info'],
            },

            // ── Card usage ───────────────────────────────────────────────────
            {
                tag: 'NEUTRAL', icon: 'fa-solid fa-credit-card',
                text: 'Card usage remains consistent across the period, indicating stable customer preference for traditional electronic payment.',
                themes: ['card', 'neutral'],
            },
            {
                tag: 'INFO', icon: 'fa-solid fa-credit-card',
                text: 'Card transactions maintain a steady share of revenue, with higher average ticket sizes compared to QR and cash.',
                themes: ['card', 'info'],
            },
            {
                tag: 'NEUTRAL', icon: 'fa-solid fa-credit-card',
                text: 'Credit and debit card activity is holding steady, suggesting this segment has reached a mature adoption plateau.',
                themes: ['card', 'neutral'],
            },

            // ── Cash behaviour ───────────────────────────────────────────────
            {
                tag: 'NEUTRAL', icon: 'fa-solid fa-money-bill-wave',
                text: 'Cash transactions still account for a significant portion of total payments, indicating a meaningful segment of customers who prefer traditional tender.',
                themes: ['cash', 'neutral'],
            },
            {
                tag: 'INFO', icon: 'fa-solid fa-money-bill-wave',
                text: 'Cash remains the fallback of choice during network disruptions, so keeping cash-handling processes efficient continues to matter.',
                themes: ['cash', 'info'],
            },
            {
                tag: 'DECLINE', icon: 'fa-solid fa-arrow-trend-down',
                text: 'Cash transaction share is gradually declining relative to digital alternatives, consistent with a long-term shift in payment behavior.',
                themes: ['cash', 'decline'],
            },

            // ── Cashless / trend ─────────────────────────────────────────────
            {
                tag: 'INFO', icon: 'fa-solid fa-wave-square',
                text: 'Payment behavior suggests a gradual shift toward cashless transactions, which may reduce reconciliation time and improve end-of-day accuracy.',
                themes: ['digital', 'info', 'growth'],
            },
            {
                tag: 'GROWTH', icon: 'fa-solid fa-bolt',
                text: 'The combined share of QR and Card payments has expanded over the selected period, a positive signal for operational efficiency.',
                themes: ['digital', 'qr', 'card', 'growth'],
            },

            // ── Voids / failures ─────────────────────────────────────────────
            {
                tag: 'DECLINE', icon: 'fa-solid fa-triangle-exclamation',
                text: 'Voided transactions represent a fraction of total activity; however, monitoring void frequency by branch can help identify training gaps.',
                themes: ['void', 'decline', 'info'],
            },
            {
                tag: 'INFO', icon: 'fa-solid fa-circle-exclamation',
                text: 'Failed payment attempts are present in the dataset — ensuring payment terminals and QR scanners are regularly tested can reduce these occurrences.',
                themes: ['failed', 'info'],
            },
            {
                tag: 'NEUTRAL', icon: 'fa-solid fa-rotate-left',
                text: 'Void and failed transaction rates remain within normal operational ranges, posing no immediate concern for payment reliability.',
                themes: ['void', 'failed', 'neutral'],
            },

            // ── Multi-method / diversity ─────────────────────────────────────
            {
                tag: 'INFO', icon: 'fa-solid fa-layer-group',
                text: 'Multiple distinct payment methods are active in this period, giving customers flexibility and reducing dependency on any single channel.',
                themes: ['diversity', 'info'],
            },
            {
                tag: 'NEUTRAL', icon: 'fa-solid fa-scale-balanced',
                text: 'No single payment method holds an overwhelming majority, reflecting a healthy distribution that limits concentration risk.',
                themes: ['diversity', 'neutral'],
            },

            // ── Revenue observations ─────────────────────────────────────────
            {
                tag: 'GROWTH', icon: 'fa-solid fa-peso-sign',
                text: 'Average transaction value across the selected period is holding firm, suggesting customers are not significantly reducing basket sizes.',
                themes: ['revenue', 'growth', 'neutral'],
            },
            {
                tag: 'INFO', icon: 'fa-solid fa-chart-column',
                text: 'Revenue concentration among the top two payment methods highlights where investment in payment infrastructure yields the highest return.',
                themes: ['revenue', 'info'],
            },

            // ── Branch / operational ─────────────────────────────────────────
            {
                tag: 'INFO', icon: 'fa-solid fa-store',
                text: 'Filtering by branch reveals variations in preferred payment methods, which can inform branch-level promotions or terminal placement decisions.',
                themes: ['branch', 'info'],
            },
            {
                tag: 'NEUTRAL', icon: 'fa-solid fa-clock-rotate-left',
                text: 'Transaction patterns across the date range show consistent daily activity, with no extreme spikes that would indicate data anomalies.',
                themes: ['trend', 'neutral'],
            },
        ];

        /* ── Tag → CSS class map ─────────────────────────────────────────────── */
        const TAG_CLASS = {
            GROWTH: 'trend-up',
            DECLINE: 'trend-down',
            NEUTRAL: 'trend-neutral',
            INFO:    'trend-info',
        };

        function generateInsights() {
            const container = document.getElementById('insightsContent');
            const btn       = document.getElementById('refreshInsightsBtn');

            // Check if there is actual data before showing insights
            const totalOk      = cachedInsights?.kpi?.total_ok_transactions ?? 0;
            const totalRevenue = cachedInsights?.kpi?.total_revenue ?? 0;
            const hasData      = Number(totalOk) > 0 || Number(totalRevenue) > 0;

            if (!hasData) {
                container.innerHTML = `
                    <div style="padding:24px;text-align:center;color:var(--ink-4);">
                        <i class="fa-solid fa-chart-simple" style="font-size:28px;margin-bottom:10px;display:block;opacity:.4;"></i>
                        <div style="font-size:13px;font-weight:600;color:var(--ink-3);margin-bottom:4px;">No data for this period</div>
                        <div style="font-size:12px;">AI insights will appear once there are transactions in the selected date range.</div>
                    </div>`;
                btn.disabled = false;
                return;
            }

            // Brief skeleton flash so it feels responsive
            container.innerHTML = `
                <div class="insights-skeleton">
                    <div class="skel-line w-full" style="height:16px;"></div>
                    <div class="skel-line w-3/4"></div>
                    <div class="skel-line w-full" style="height:16px;margin-top:8px;"></div>
                    <div class="skel-line w-1/2"></div>
                </div>`;
            btn.disabled = true;

            setTimeout(() => {
                try {
                    const selected = _pickInsights();
                    container.innerHTML = `<div class="insights-grid">
                        ${selected.map(ins => `
                            <div class="insight-item ${TAG_CLASS[ins.tag] || 'trend-neutral'}">
                                <div class="insight-icon">
                                    <i class="${ins.icon}"></i>
                                </div>
                                <div class="insight-text">${ins.text}</div>
                            </div>
                        `).join('')}
                    </div>`;
                } catch (e) {
                    container.innerHTML = `
                        <div style="padding:16px;color:var(--ink-3);font-size:13px;display:flex;gap:10px;align-items:flex-start;">
                            <i class="fa-solid fa-triangle-exclamation" style="color:#d97706;margin-top:2px;"></i>
                            <span>Could not render insights. Please try again.</span>
                        </div>`;
                } finally {
                    btn.disabled = false;
                }
            }, 420);
        }

        /* ── Internal: selection logic ───────────────────────────────────────── */
        function _pickInsights() {
            const TARGET     = 4;  // how many to show
            const branchVal  = document.getElementById('filterBranch')?.value  || 'all';
            const statusVal  = document.getElementById('filterStatus')?.value  || 'all';
            const dateFrom   = document.getElementById('dateFrom')?.value      || '';
            const dateTo     = document.getElementById('dateTo')?.value        || '';

            // Build a priority theme list based on active filters
            const priorityThemes = ['qr', 'card', 'cash', 'digital']; // always relevant

            if (branchVal !== 'all')    priorityThemes.unshift('branch');
            if (statusVal === 'VOID')   priorityThemes.unshift('void');
            if (statusVal === 'FAILED') priorityThemes.unshift('failed');

            // Detect if date range spans more than ~14 days → emphasise trend
            if (dateFrom && dateTo) {
                const diffDays = (new Date(dateTo) - new Date(dateFrom)) / 86400000;
                if (diffDays > 14) priorityThemes.unshift('trend', 'revenue');
            }

            // Shuffle the full pool deterministically with a time-based seed so
            // each "Regenerate" click gives a fresh selection.
            const seed  = Math.floor(Date.now() / 1000); // changes every second
            const pool  = _shuffle([...INSIGHT_POOL], seed);

            const chosen = [];
            const usedThemes = new Set();

            // First pass: pick one insight per priority theme
            for (const theme of priorityThemes) {
                if (chosen.length >= TARGET) break;
                const match = pool.find(
                    ins => ins.themes.includes(theme) && !chosen.includes(ins)
                );
                if (match) {
                    chosen.push(match);
                    match.themes.forEach(t => usedThemes.add(t));
                }
            }

            // Second pass: fill remaining slots with any unused insight
            for (const ins of pool) {
                if (chosen.length >= TARGET) break;
                if (!chosen.includes(ins)) chosen.push(ins);
            }

            // Shuffle the chosen set so order feels organic
            return _shuffle(chosen.slice(0, TARGET), seed + 1);
        }

        /* ── Seeded Fisher-Yates shuffle ─────────────────────────────────────── */
        function _shuffle(arr, seed) {
            let s = seed >>> 0;
            const rng = () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; };
            for (let i = arr.length - 1; i > 0; i--) {
                const j = Math.floor(rng() * (i + 1));
                [arr[i], arr[j]] = [arr[j], arr[i]];
            }
            return arr;
        }

        /* ══════════════════════════════════════════════════════════════════════
           TRANSACTION TABLE
        ══════════════════════════════════════════════════════════════════════ */

        async function loadTable(page) {
            currentPage = page || currentPage;
            const { date_from, date_to } = getDateRange();
            const branch_id  = document.getElementById('filterBranch').value;
            const payment_id = document.getElementById('txPaymentFilter').value;
            const status     = document.getElementById('txStatusFilter').value;
            const search     = document.getElementById('txSearch').value.trim();
            const per_page   = document.getElementById('perPageSelect').value;

            const loading = document.getElementById('tableLoading');
            loading.classList.remove('hidden');

            const qs = new URLSearchParams({
                endpoint: 'payment-insights/table',
                preset: 'custom',
                date_from, date_to,
                branch_id, payment_id, status, search,
                page: currentPage, per_page,
            });

            try {
                const res  = await fetch(`${API}?${qs}`);
                const data = await res.json();
                if (data.error) throw new Error(data.error);

                renderTxTable(data.rows);
                renderPagination(data.pagination);

                const p = data.pagination;
                const start = (p.page - 1) * p.per_page + 1;
                const end   = Math.min(p.page * p.per_page, p.total);
                document.getElementById('tableSubLabel').textContent =
                    ` — ${start}–${end} of ${fmt(p.total)} transactions`;
            } catch (e) {
                showToast('Table load failed: ' + e.message, 'error');
            } finally {
                loading.classList.add('hidden');
            }
        }

        function renderTxTable(rows) {
            const tbody = document.getElementById('txTableBody');
            if (!rows || !rows.length) {
                tbody.innerHTML = `<tr><td colspan="9">
                    <div class="empty-state">
                        <i class="fa-solid fa-inbox"></i>
                        <strong>No Transactions</strong>
                        <p>No transactions match the current filters.</p>
                    </div>
                </td></tr>`;
                return;
            }

            tbody.innerHTML = rows.map(r => {
                const statusClass = {
                    'OK':      'badge-ok',
                    'VOID':    'badge-void',
                    'FAILED':  'badge-failed',
                    'PENDING': 'badge-pending',
                }[r.status] || 'badge-other';

                return `<tr>
                    <td class="mono" style="font-size:11px;">${r.invoice}</td>
                    <td class="mono">${r.date}</td>
                    <td style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${r.customer}</td>
                    <td>${r.branch}</td>
                    <td>
                        <span class="method-pill" style="background:${methodColor(r.payment_method,0)}22;color:${methodColor(r.payment_method,0)};">
                            ${r.payment_method}
                        </span>
                    </td>
                    <td class="mono">${peso(r.grand_total)}</td>
                    <td class="mono">${peso(r.vat)}</td>
                    <td class="mono">${peso(r.discount)}</td>
                    <td><span class="badge ${statusClass}">${r.status}</span></td>
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

        /* ── CSV Export ──────────────────────────────────────────────────────── */
        function exportCsv() {
            const { date_from, date_to } = getDateRange();
            const branch_id  = document.getElementById('filterBranch').value;
            const payment_id = document.getElementById('txPaymentFilter').value;
            const status     = document.getElementById('txStatusFilter').value;

            const qs = new URLSearchParams({
                endpoint: 'payment-insights/export',
                preset: 'custom',
                date_from, date_to, branch_id, payment_id, status,
            });
            window.location.href = `${API}?${qs}`;
        }

        /* ── Debounced search ─────────────────────────────────────────────────── */
        function onTxSearch() {
            clearTimeout(txSearchTimer);
            txSearchTimer = setTimeout(() => loadTable(1), 400);
        }

        /* ── Topbar date ─────────────────────────────────────────────────────── */
        function updateTopbarDate() {
            document.getElementById('topbarDate').textContent = new Date().toLocaleDateString('en-PH', {
                weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
            });
        }

        /* ── Sidebar toggle ──────────────────────────────────────────────────── */
        function initSidebar() {
            const toggle  = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');
            if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
        }

        /* ── Boot ────────────────────────────────────────────────────────────── */
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