<?php
$current = 'ml-training';

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
    <title>Model Training — ChronoSales</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="stylesheet" href="../assets/css/general.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        /* ── Source selector tabs ─────────────────────────────── */
        .dm-tabs {
            display: flex; gap: 4px;
            background: var(--card); border: 1px solid var(--border);
            border-radius: 12px; padding: 5px;
            margin-bottom: 20px; width: fit-content;
        }
        .dm-tab {
            padding: 7px 16px; border-radius: 8px; border: none;
            font-size: 12.5px; font-weight: 500; font-family: 'DM Sans', sans-serif;
            color: var(--ink-3); background: transparent; cursor: pointer;
            transition: all 0.15s; display: flex; align-items: center; gap: 6px;
        }
        .dm-tab:hover { color: var(--ink); background: var(--bg); }
        .dm-tab.active {
            background: var(--primary); color: #fff;
            box-shadow: 0 2px 8px rgba(15,118,110,0.25);
        }
        .dm-tab i { font-size: 12px; }

        /* ── Shared button styles ─────────────────────────────── */
        .btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--primary); color: #fff; border: none;
            font-size: 12.5px; font-weight: 600; cursor: pointer;
            font-family: 'DM Sans', sans-serif; transition: opacity 0.15s; white-space: nowrap;
        }
        .btn-primary:hover { opacity: 0.88; }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-secondary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            border: 1px solid var(--border); background: var(--card);
            font-size: 12.5px; font-weight: 500; color: var(--ink-3); cursor: pointer;
            font-family: 'DM Sans', sans-serif; transition: all 0.15s; white-space: nowrap;
        }
        .btn-secondary:hover { border-color: var(--primary-mid); color: var(--primary); background: var(--primary-light); }
        .btn-danger {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--danger); color: #fff; border: none;
            font-size: 12.5px; font-weight: 600; cursor: pointer;
            font-family: 'DM Sans', sans-serif; transition: opacity 0.15s; white-space: nowrap;
        }
        .btn-danger:hover { opacity: 0.85; }

        /* ── ML section card ──────────────────────────────────── */
        .ml-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 14px; overflow: hidden;
            box-shadow: var(--card-shadow); margin-bottom: 20px;
        }
        .ml-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; border-bottom: 1px solid var(--border);
            background: #fafafa;
        }
        .ml-card-title {
            font-size: 13px; font-weight: 600; color: var(--ink);
            display: flex; align-items: center; gap: 8px;
        }
        .ml-card-title i { color: var(--primary-mid); font-size: 12px; }
        .ml-card-body { padding: 18px; }

        /* ── Step badge ───────────────────────────────────────── */
        .step-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; border-radius: 50%;
            background: var(--primary); color: #fff;
            font-size: 10px; font-weight: 700; font-family: 'DM Mono', monospace;
            flex-shrink: 0;
        }

        /* ── Drop zone ────────────────────────────────────────── */
        .import-drop {
            border: 2px dashed var(--border); border-radius: 12px;
            padding: 32px 24px; text-align: center; cursor: pointer;
            transition: all 0.2s; margin-bottom: 16px;
        }
        .import-drop:hover, .import-drop.drag-over {
            border-color: var(--primary-mid); background: var(--primary-light);
        }
        .import-drop i { font-size: 28px; color: var(--ink-4); margin-bottom: 10px; display: block; }
        .import-drop.drag-over i { color: var(--primary); }
        .import-drop p { font-size: 13px; color: var(--ink-3); }
        .import-drop p strong { color: var(--primary); }

        /* ── DB source info box ───────────────────────────────── */
        .db-info-box {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 10px; padding: 16px 18px;
        }
        .db-info-box p { font-size: 13px; color: var(--ink-2); margin: 0 0 6px; }
        .db-info-box p:last-child { margin: 0; color: var(--ink-3); font-size: 12px; }
        .db-info-box code {
            font-family: 'DM Mono', monospace; font-size: 12px;
            background: var(--primary-light); color: var(--primary);
            padding: 1px 6px; border-radius: 4px;
        }

        /* ── Preview table ────────────────────────────────────── */
        .import-preview {
            background: var(--bg); border-radius: 8px; overflow: hidden;
            border: 1px solid var(--border); max-height: 220px; overflow-y: auto;
        }
        .import-preview table { width: 100%; font-size: 11.5px; border-collapse: collapse; }
        .import-preview th {
            padding: 7px 10px; background: #f1f5f9; color: var(--ink-4);
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em;
            border-bottom: 1px solid var(--border); white-space: nowrap;
            position: sticky; top: 0;
        }
        .import-preview td { padding: 7px 10px; color: var(--ink-2); border-bottom: 1px solid #f8fafc; white-space: nowrap; }
        .import-preview tr:last-child td { border-bottom: none; }

        /* ── Progress bar ─────────────────────────────────────── */
        .progress-bar-track {
            height: 8px; background: var(--bg);
            border-radius: 99px; overflow: hidden;
            border: 1px solid var(--border);
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-mid));
            border-radius: 99px; width: 0%;
            transition: width 0.3s ease;
        }
        .progress-header {
            display: flex; justify-content: space-between;
            font-size: 12px; color: var(--ink-4);
            font-family: 'DM Mono', monospace; margin-bottom: 6px;
        }

        /* ── Epoch stats row ──────────────────────────────────── */
        .epoch-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 10px; margin: 16px 0;
        }
        .epoch-stat {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 10px; padding: 10px 14px;
        }
        .epoch-stat .es-label {
            font-size: 10px; font-weight: 600; color: var(--ink-4);
            text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px;
        }
        .epoch-stat .es-value {
            font-size: 1.25rem; font-weight: 700; color: var(--ink);
            font-family: 'DM Mono', monospace; line-height: 1.2;
        }

        /* ── Charts row ───────────────────────────────────────── */
        .charts-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
            margin: 16px 0;
        }
        @media (max-width: 700px) { .charts-row { grid-template-columns: 1fr; } }
        .chart-box {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 10px; padding: 14px;
        }
        .chart-box-label {
            font-size: 10.5px; font-weight: 600; color: var(--ink-4);
            text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 10px;
        }

        /* ── Log console ──────────────────────────────────────── */
        .log-console {
            background: #1a202c; border-radius: 8px;
            padding: 12px 14px; font-family: 'DM Mono', monospace;
            font-size: 11.5px; color: #68d391; max-height: 150px;
            overflow-y: auto; line-height: 1.7; margin-top: 14px;
        }
        .log-console p { margin: 0; }
        .log-console p.log-err { color: #fc8181; }
        .log-console p.log-dim { color: #718096; }

        /* ── Results metric cards ─────────────────────────────── */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px; margin-bottom: 20px;
        }
        .metric-card {
            background: var(--primary-light); border: 1px solid var(--primary-mid);
            border-radius: 12px; padding: 14px 16px; text-align: center;
        }
        .metric-card .mc-label {
            font-size: 10px; font-weight: 600; color: var(--primary);
            text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 6px;
        }
        .metric-card .mc-val {
            font-size: 2rem; font-weight: 800; color: var(--primary);
            line-height: 1; font-family: 'DM Mono', monospace;
        }
        .metric-card .mc-raw {
            font-size: 10.5px; color: var(--ink-4); margin-top: 4px;
            font-family: 'DM Mono', monospace;
        }

        /* ── Confusion matrix ─────────────────────────────────── */
        .cm-wrap { display: flex; justify-content: center; margin-top: 4px; }
        .confusion-matrix { border-collapse: collapse; font-size: 12px; }
        .confusion-matrix th, .confusion-matrix td {
            padding: 8px 18px; border: 1px solid var(--border); text-align: center;
        }
        .confusion-matrix th { background: #f1f5f9; color: var(--ink-4); font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em; }
        .confusion-matrix .cm-tp { background: var(--success-light); color: var(--success); font-weight: 700; }
        .confusion-matrix .cm-tn { background: var(--success-light); color: var(--success); font-weight: 700; }
        .confusion-matrix .cm-fp { background: var(--danger-light);  color: var(--danger);  font-weight: 700; }
        .confusion-matrix .cm-fn { background: var(--danger-light);  color: var(--danger);  font-weight: 700; }

        /* ── Section divider ──────────────────────────────────── */
        .section-divider {
            display: flex; align-items: center; gap: 10px;
            font-size: 10px; font-weight: 600; color: var(--ink-4);
            text-transform: uppercase; letter-spacing: 0.08em;
            margin: 20px 0 14px;
        }
        .section-divider::before, .section-divider::after {
            content: ''; flex: 1; height: 1px; background: var(--border);
        }

        /* ── Success banner ───────────────────────────────────── */
        .success-banner {
            display: flex; align-items: center; gap: 12px;
            background: var(--success-light); border: 1px solid var(--success);
            border-radius: 10px; padding: 12px 16px; margin-bottom: 18px;
        }
        .success-banner i { color: var(--success); font-size: 16px; }
        .success-banner p { font-size: 13px; color: var(--success); margin: 0; font-weight: 500; }

        /* ── Toast ────────────────────────────────────────────── */
        #toast-container {
            position: fixed; bottom: 24px; right: 24px;
            z-index: 9999; display: flex; flex-direction: column; gap: 8px;
        }
        .toast {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; border-radius: 10px; min-width: 260px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            font-size: 13px; font-family: 'DM Sans', sans-serif; font-weight: 500;
            animation: toastIn 0.25s ease;
        }
        @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }
        .toast.success { background: var(--success); color: #fff; }
        .toast.error   { background: var(--danger);  color: #fff; }
        .toast.info    { background: var(--primary);  color: #fff; }
        .toast i { font-size: 14px; }

        /* ── Loading overlay (same as data-management) ────────── */
        .loading-overlay {
            position: absolute; inset: 0; background: rgba(255,255,255,0.75);
            z-index: 50; display: flex; align-items: center; justify-content: center;
            border-radius: 14px;
        }
        .loading-overlay.hidden { display: none; }

        /* ── Feature tag list ─────────────────────────────────── */
        .feature-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .feature-tag {
            font-family: 'DM Mono', monospace; font-size: 11px;
            background: var(--bg); border: 1px solid var(--border);
            color: var(--ink-3); padding: 3px 9px; border-radius: 6px;
        }

        @media print {
            .sidebar, .topbar, .dm-tabs { display: none !important; }
            .main { margin-left: 0 !important; }
        }
        @media (max-width: 900px) {
            .charts-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app">

    <?php include 'sidebar.php'; ?>

    <div class="main" id="main">

        <!-- ── Topbar ──────────────────────────────────────────── -->
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="topbar-breadcrumb">
                <i class="fa-solid fa-brain"></i>
                <span>AI / ML</span>
                <i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--ink-4);"></i>
                <span style="color:var(--ink-4);font-size:12px;">Model Training</span>
            </div>
            <div class="topbar-right">
                <div class="topbar-date" id="topbarDate"></div>
                <button class="topbar-btn" id="refreshBtn" title="Reset" onclick="resetAll()">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>
        </header>

        <!-- ── Content ─────────────────────────────────────────── -->
        <div class="content">

            <!-- Page header -->
            <div style="margin-bottom:20px;">
                <div class="page-title" style="font-size:18px;font-weight:600;color:var(--ink);margin-bottom:4px;">
                    <i class="fa-solid fa-brain" style="color:var(--primary-mid);margin-right:8px;"></i>
                    Model Training
                </div>
                <p style="font-size:13px;color:var(--ink-3);">Upload a CSV dataset or use the system database to train and evaluate a classification model in real time.</p>
            </div>

            <!-- ════════ STEP 1 — Data Source ════════ -->
            <div class="ml-card">
                <div class="ml-card-header">
                    <div class="ml-card-title">
                        <span class="step-badge">1</span>
                        <i class="fa-solid fa-database"></i>
                        Choose Data Source
                    </div>
                </div>
                <div class="ml-card-body">

                    <!-- Source switcher tabs -->
                    <div class="dm-tabs">
                        <button class="dm-tab active" id="tab-csv" onclick="setSource('csv')">
                            <i class="fa-solid fa-file-csv"></i> Upload CSV
                        </button>
                        <button class="dm-tab" id="tab-db" onclick="setSource('db')">
                            <i class="fa-solid fa-server"></i> System Database
                        </button>
                    </div>

                    <!-- CSV panel -->
                    <div id="csv-panel">
                        <div class="import-drop" id="dropZone"
                             onclick="document.getElementById('csvFileInput').click()"
                             ondragover="event.preventDefault(); this.classList.add('drag-over')"
                             ondragleave="this.classList.remove('drag-over')"
                             ondrop="handleDrop(event)">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag &amp; drop your <strong>CSV file</strong> here</p>
                            <p style="font-size:11.5px;margin-top:6px;color:var(--ink-4);">Any tabular CSV — numeric columns will be used as features</p>
                        </div>
                        <input type="file" id="csvFileInput" accept=".csv" style="display:none" onchange="handleFileInput(this)">
                        <div id="csv-meta" style="font-size:12px;color:var(--ink-4);margin-top:-8px;margin-bottom:8px;font-family:'DM Mono',monospace;"></div>
                    </div>

                    <!-- DB panel -->
                    <div id="db-panel" style="display:none;">
                        <div class="db-info-box">
                            <p><i class="fa-solid fa-table" style="color:var(--primary-mid);margin-right:6px;"></i>
                                Using table: <code>transactions</code></p>
                            <p>Fetches up to 5,000 recent rows with features: day-of-week, hour, grand_total, discount, VAT, branch_id, payment method, and status label.</p>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ════════ STEP 2 — Data Preview ════════ -->
            <div class="ml-card" id="preview-card" style="display:none;">
                <div class="ml-card-header">
                    <div class="ml-card-title">
                        <span class="step-badge">2</span>
                        <i class="fa-solid fa-table"></i>
                        Data Preview
                    </div>
                    <span style="font-size:12px;color:var(--ink-4);font-family:'DM Mono',monospace;" id="preview-meta"></span>
                </div>
                <div class="ml-card-body" style="padding:0;">
                    <div class="import-preview">
                        <table id="preview-table"></table>
                    </div>
                </div>
            </div>

            <!-- ════════ STEP 3 — Start Training ════════ -->
            <div class="ml-card">
                <div class="ml-card-header">
                    <div class="ml-card-title">
                        <span class="step-badge">3</span>
                        <i class="fa-solid fa-play"></i>
                        Train Model
                    </div>
                </div>
                <div class="ml-card-body">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <button class="btn-primary" id="train-btn" onclick="startTraining()">
                            <i class="fa-solid fa-bolt"></i> Start Training
                        </button>
                        <button class="btn-danger" id="cancel-btn" onclick="cancelTraining()" style="display:none;">
                            <i class="fa-solid fa-xmark"></i> Cancel
                        </button>
                        <span id="train-status" style="font-size:12.5px;color:var(--ink-4);font-family:'DM Mono',monospace;"></span>
                    </div>
                </div>
            </div>

            <!-- ════════ Training Progress (hidden until started) ════════ -->
            <div class="ml-card" id="training-panel" style="display:none;">
                <div class="ml-card-header">
                    <div class="ml-card-title">
                        <i class="fa-solid fa-circle-notch fa-spin" id="train-spinner"></i>
                        Training Progress
                    </div>
                    <span style="font-size:12px;color:var(--ink-4);font-family:'DM Mono',monospace;" id="epoch-badge">Epoch 0 / 30</span>
                </div>
                <div class="ml-card-body">

                    <!-- Progress bar -->
                    <div class="progress-header">
                        <span id="prog-label">Initialising…</span>
                        <span id="prog-pct">0%</span>
                    </div>
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" id="prog-bar"></div>
                    </div>

                    <!-- Live stats -->
                    <div class="epoch-stats">
                        <div class="epoch-stat">
                            <div class="es-label">Epoch</div>
                            <div class="es-value" id="stat-epoch">—</div>
                        </div>
                        <div class="epoch-stat">
                            <div class="es-label">Loss</div>
                            <div class="es-value" id="stat-loss">—</div>
                        </div>
                        <div class="epoch-stat">
                            <div class="es-label">Train Acc</div>
                            <div class="es-value" id="stat-acc">—</div>
                        </div>
                    </div>

                    <!-- Live charts -->
                    <div class="charts-row">
                        <div class="chart-box">
                            <div class="chart-box-label"><i class="fa-solid fa-chart-line" style="color:var(--danger);margin-right:5px;"></i>Loss over Epochs</div>
                            <canvas id="loss-chart" height="130"></canvas>
                        </div>
                        <div class="chart-box">
                            <div class="chart-box-label"><i class="fa-solid fa-chart-line" style="color:var(--success);margin-right:5px;"></i>Accuracy over Epochs</div>
                            <canvas id="acc-chart" height="130"></canvas>
                        </div>
                    </div>

                    <!-- Log console -->
                    <div style="font-size:10.5px;font-weight:600;color:var(--ink-4);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">
                        <i class="fa-solid fa-terminal" style="margin-right:5px;"></i>Training Log
                    </div>
                    <div class="log-console" id="log-console"></div>

                </div>
            </div>

            <!-- ════════ Results (hidden until done) ════════ -->
            <div class="ml-card" id="results-panel" style="display:none;">
                <div class="ml-card-header">
                    <div class="ml-card-title">
                        <i class="fa-solid fa-trophy" style="color:var(--primary-mid);"></i>
                        Evaluation Results
                    </div>
                </div>
                <div class="ml-card-body">

                    <div class="success-banner">
                        <i class="fa-solid fa-circle-check"></i>
                        <p>Training completed successfully. Metrics below are evaluated on the held-out test set.</p>
                    </div>

                    <!-- Metric cards -->
                    <div class="metrics-grid" id="metrics-grid"></div>

                    <!-- Confusion matrix -->
                    <div class="section-divider">Confusion Matrix</div>
                    <div class="cm-wrap" id="cm-wrap"></div>

                    <!-- Feature summary -->
                    <div class="section-divider">Training Summary</div>
                    <div id="feature-summary" style="font-size:12.5px;color:var(--ink-3);line-height:1.8;"></div>
                    <div class="feature-tags" id="feature-tags"></div>

                    <!-- Reset -->
                    <div style="margin-top:20px;">
                        <button class="btn-secondary" onclick="resetAll()">
                            <i class="fa-solid fa-rotate-left"></i> Train Another Model
                        </button>
                    </div>

                </div>
            </div>

        </div><!-- .content -->
    </div><!-- .main -->
</div><!-- .app -->

<!-- Toast container -->
<div id="toast-container"></div>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->

<script src="../assets/js/sidebar.js"></script>
<script>
const API = '/backend/api_proxy.php';
const FLASK = '/backend/api_proxy.php'; // routed through proxy when available
// For direct Flask calls (SSE streams must bypass PHP proxy — SSE can't be buffered)
const FLASK_DIRECT = '';

/* ── State ────────────────────────────────────────────────── */
let currentSource = 'csv';
let csvData       = null;
let currentJobId  = null;
let evtSource     = null;
let lossChart     = null;
let accChart      = null;

/* ── Init ─────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    updateTopbarDate();
});

/* ── Source tab ───────────────────────────────────────────── */
function setSource(src) {
    currentSource = src;
    document.getElementById('tab-csv').classList.toggle('active', src === 'csv');
    document.getElementById('tab-db').classList.toggle('active', src === 'db');
    document.getElementById('csv-panel').style.display = src === 'csv' ? '' : 'none';
    document.getElementById('db-panel').style.display  = src === 'db'  ? '' : 'none';

    if (src === 'db') {
        showDbSchema();
    } else {
        if (!csvData) document.getElementById('preview-card').style.display = 'none';
    }
}

/* ── DB schema preview ────────────────────────────────────── */
function showDbSchema() {
    const cols = ['dow','hour_of_day','grand_total','final_discount','vat','branch_id','overall_payment_method_id','is_ok'];
    const mockRows = Array.from({ length: 5 }, () => {
        const obj = {};
        cols.forEach(c => obj[c] = Math.floor(Math.random() * 1000) / 10);
        return obj;
    });
    renderPreview(cols, mockRows, '≤ 5,000 rows (live database)');
}

/* ── File handling ────────────────────────────────────────── */
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) processFile(file);
}

function handleFileInput(input) {
    const file = input.files[0];
    if (file) processFile(file);
}

function processFile(file) {
    if (!file.name.toLowerCase().endsWith('.csv')) {
        showToast('Please upload a .csv file.', 'error'); return;
    }
    const reader = new FileReader();
    reader.onload = async (ev) => {
        csvData = ev.target.result;
        document.getElementById('csv-meta').textContent =
            `📎 ${file.name}  ·  ${(file.size / 1024).toFixed(1)} KB`;

        // Try proxy upload first, fall back to browser-side parse
        try {
            const fd = new FormData();
            fd.append('file', file);
            const res  = await fetch(`${API}?endpoint=ml/upload-csv`, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            renderPreview(data.columns, data.preview, `${data.total_rows.toLocaleString()} rows`);
        } catch (err) {
            // Browser-side CSV parse fallback
            const lines   = csvData.trim().split('\n');
            const headers = parseCsvLine(lines[0]);
            const rows    = lines.slice(1, 11).map(l => {
                const vals = parseCsvLine(l);
                const obj  = {};
                headers.forEach((h, i) => obj[h] = vals[i] ?? '');
                return obj;
            });
            renderPreview(headers, rows, `${(lines.length - 1).toLocaleString()} rows (preview)`);
        }
    };
    reader.readAsText(file);
}

function parseCsvLine(line) {
    return line.split(',').map(v => v.trim().replace(/^"|"$/g, ''));
}

/* ── Render preview table ─────────────────────────────────── */
function renderPreview(columns, rows, meta) {
    document.getElementById('preview-card').style.display = '';
    document.getElementById('preview-meta').textContent =
        `${columns.length} columns  ·  ${meta}  ·  showing first ${rows.length}`;

    const thead = `<thead><tr>${columns.map(c => `<th>${c}</th>`).join('')}</tr></thead>`;
    const tbody = `<tbody>${rows.map(r =>
        `<tr>${columns.map(c => `<td>${r[c] ?? ''}</td>`).join('')}</tr>`
    ).join('')}</tbody>`;
    document.getElementById('preview-table').innerHTML = thead + tbody;
}

/* ── Start training ───────────────────────────────────────── */
async function startTraining() {
    if (currentSource === 'csv' && !csvData) {
        showToast('Please upload a CSV file first.', 'error'); return;
    }

    resetProgress();
    document.getElementById('training-panel').style.display = '';
    document.getElementById('results-panel').style.display  = 'none';
    document.getElementById('train-btn').disabled = true;
    document.getElementById('cancel-btn').style.display = 'inline-flex';
    document.getElementById('train-status').textContent = 'Training in progress…';

    initCharts();

    const body = { source: currentSource };
    if (currentSource === 'csv') body.csv_data = csvData;

    try {
        const res  = await fetch(`${API}?endpoint=ml/train`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (data.error) { showToast(data.error, 'error'); resetUI(); return; }

        currentJobId = data.job_id;
        openStream(currentJobId);
    } catch (err) {
        showToast('Could not reach backend: ' + err.message, 'error');
        resetUI();
    }
}

/* ── SSE stream ───────────────────────────────────────────── */
function openStream(jobId) {
    const sseUrl = `${API}?endpoint=ml/stream/${jobId}`;
    evtSource = new EventSource(sseUrl);
    evtSource.onmessage = (e) => {
        try { handleEvent(JSON.parse(e.data)); }
        catch(err) { addLog('Parse error: ' + err.message, true); }
    };
    evtSource.onerror = () => {
        if (_cancelling) return; // suppress error noise during intentional cancel
        addLog('Stream error — check that app.py is running on port 8800.', true);
        evtSource.close();
        resetUI();
    };
}

function handleEvent(ev) {
    if (ev.type === 'log') {
        addLog(ev.data.msg);

    } else if (ev.type === 'progress') {
        const d = ev.data;
        document.getElementById('prog-bar').style.width   = d.pct + '%';
        document.getElementById('prog-pct').textContent   = d.pct + '%';
        document.getElementById('prog-label').textContent = `Epoch ${d.epoch} of ${d.total_epochs}`;
        document.getElementById('epoch-badge').textContent= `Epoch ${d.epoch} / ${d.total_epochs}`;
        document.getElementById('stat-epoch').textContent = d.epoch;
        document.getElementById('stat-loss').textContent  = d.loss.toFixed(4);
        document.getElementById('stat-acc').textContent   = (d.accuracy * 100).toFixed(1) + '%';

        lossChart.data.labels.push(d.epoch);
        lossChart.data.datasets[0].data.push(d.loss);
        lossChart.update('none');

        accChart.data.labels.push(d.epoch);
        accChart.data.datasets[0].data.push(+(d.accuracy * 100).toFixed(2));
        accChart.update('none');

    } else if (ev.type === 'done') {
        evtSource.close();
        document.getElementById('train-spinner').className = 'fa-solid fa-check';
        showResults(ev.data.metrics);
        document.getElementById('train-status').textContent = '✓ Complete';
        document.getElementById('cancel-btn').style.display = 'none';
        document.getElementById('train-btn').disabled = false;
        showToast('Training completed successfully!', 'success');

    } else if (ev.type === 'error') {
        addLog('ERROR: ' + ev.data.msg, true);
        showToast(ev.data.msg, 'error');
        evtSource.close(); resetUI();

    } else if (ev.type === 'cancelled') {
        addLog('⚠ ' + ev.data.msg);
        evtSource.close();
        _cancelling = false;
        resetUI();
        showToast('Training cancelled.', 'info');
    }
}

/* ── Cancel ───────────────────────────────────────────────── */
let _cancelling = false;
async function cancelTraining() {
    if (!currentJobId || _cancelling) return;
    _cancelling = true;
    document.getElementById('cancel-btn').disabled = true;

    try {
        await fetch(`${API}?endpoint=ml/cancel/${currentJobId}`, { method: 'POST' });
    } catch(e) {}
}

/* ── Show results ─────────────────────────────────────────── */
function showResults(m) {
    document.getElementById('results-panel').style.display = '';

    const defs = [
        { key: 'accuracy',  label: 'Accuracy'  },
        { key: 'f1_score',  label: 'F1 Score'  },
        { key: 'precision', label: 'Precision' },
        { key: 'recall',    label: 'Recall'    },
        { key: 'roc_auc',   label: 'ROC-AUC'   },
    ];

    document.getElementById('metrics-grid').innerHTML = defs.map(d => `
        <div class="metric-card">
            <div class="mc-label">${d.label}</div>
            <div class="mc-val">${(m[d.key] * 100).toFixed(1)}<span style="font-size:.9rem;font-weight:600;">%</span></div>
            <div class="mc-raw">${m[d.key].toFixed(4)}</div>
        </div>`).join('');

    // Confusion matrix
    if (m.confusion_matrix && m.confusion_matrix.length === 2) {
        const [[tn, fp], [fn, tp]] = m.confusion_matrix;
        document.getElementById('cm-wrap').innerHTML = `
            <table class="confusion-matrix">
                <thead>
                    <tr><th></th><th>Predicted 0</th><th>Predicted 1</th></tr>
                </thead>
                <tbody>
                    <tr><th>Actual 0</th>
                        <td class="cm-tn">${tn}<br><small>TN</small></td>
                        <td class="cm-fp">${fp}<br><small>FP</small></td>
                    </tr>
                    <tr><th>Actual 1</th>
                        <td class="cm-fn">${fn}<br><small>FN</small></td>
                        <td class="cm-tp">${tp}<br><small>TP</small></td>
                    </tr>
                </tbody>
            </table>`;
    }

    // Feature summary text
    document.getElementById('feature-summary').innerHTML =
        `<strong>Rows trained:</strong> ${m.rows_trained?.toLocaleString() ?? '—'}
         &nbsp;·&nbsp; <strong>Rows tested:</strong> ${m.rows_tested?.toLocaleString() ?? '—'}
         &nbsp;·&nbsp; <strong>Epochs:</strong> ${m.epochs ?? '—'}`;

    const feats = m.features_used || [];
    document.getElementById('feature-tags').innerHTML =
        feats.map(f => `<span class="feature-tag">${f}</span>`).join('');

    document.getElementById('results-panel').scrollIntoView({ behavior: 'smooth' });
}

/* ── Charts ───────────────────────────────────────────────── */
function initCharts() {
    if (lossChart) { lossChart.destroy(); lossChart = null; }
    if (accChart)  { accChart.destroy();  accChart  = null; }

    const base = {
        responsive: true, animation: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { font: { size: 10 }, color: '#9ca3af' },
                 title: { display: true, text: 'Epoch', font: { size: 10 }, color: '#9ca3af' } },
            y: { ticks: { font: { size: 10 }, color: '#9ca3af' } },
        },
    };

    lossChart = new Chart(document.getElementById('loss-chart').getContext('2d'), {
        type: 'line',
        data: { labels: [], datasets: [{ data: [], borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.08)', borderWidth: 2, pointRadius: 0, tension: .35, fill: true }] },
        options: base,
    });

    accChart = new Chart(document.getElementById('acc-chart').getContext('2d'), {
        type: 'line',
        data: { labels: [], datasets: [{ data: [], borderColor: '#0f766e', backgroundColor: 'rgba(15,118,110,.08)', borderWidth: 2, pointRadius: 0, tension: .35, fill: true }] },
        options: { ...base, scales: { ...base.scales, y: { ticks: { font: { size: 10 }, color: '#9ca3af', callback: v => v + '%' } } } },
    });
}

/* ── Log helpers ──────────────────────────────────────────── */
function addLog(msg, isErr = false) {
    const el   = document.getElementById('log-console');
    const line = document.createElement('p');
    line.className = isErr ? 'log-err' : '';
    line.textContent = '> ' + msg;
    el.appendChild(line);
    el.scrollTop = el.scrollHeight;
}

/* ── Reset helpers ────────────────────────────────────────── */
function resetProgress() {
    document.getElementById('prog-bar').style.width    = '0%';
    document.getElementById('prog-pct').textContent    = '0%';
    document.getElementById('prog-label').textContent  = 'Initialising…';
    document.getElementById('epoch-badge').textContent = 'Epoch 0 / 30';
    document.getElementById('stat-epoch').textContent  = '—';
    document.getElementById('stat-loss').textContent   = '—';
    document.getElementById('stat-acc').textContent    = '—';
    document.getElementById('log-console').innerHTML   = '';
    document.getElementById('train-spinner').className = 'fa-solid fa-circle-notch fa-spin';
}

function resetUI() {
    document.getElementById('train-btn').disabled = false;
    document.getElementById('cancel-btn').style.display = 'none';
    document.getElementById('train-status').textContent = '';
}

function resetAll() {
    _cancelling  = false;
    resetUI(); resetProgress();
    document.getElementById('training-panel').style.display = 'none';
    document.getElementById('results-panel').style.display  = 'none';
    document.getElementById('preview-card').style.display   = 'none';
    csvData      = null;
    currentJobId = null;
    document.getElementById('csv-meta').textContent = '';
    document.getElementById('csvFileInput').value   = '';
    setSource('csv');
}

/* ── Toast ────────────────────────────────────────────────── */
function showToast(msg, type = 'info') {
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' };
    const el    = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i> ${msg}`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

/* ── Topbar date ──────────────────────────────────────────── */
function updateTopbarDate() {
    document.getElementById('topbarDate').textContent = new Date().toLocaleDateString('en-PH', {
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
    });
}

</script>
</body>
</html>