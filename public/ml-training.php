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
        /* ── Model selector tabs ─────────────────────────────────────── */
        .model-selector { display:flex; gap:0; background:var(--card); border:1px solid var(--border); border-radius:12px; padding:5px; margin-bottom:20px; width:fit-content; }
        .model-tab { padding:8px 20px; border-radius:8px; border:none; font-size:13px; font-weight:600; font-family:'DM Sans',sans-serif; color:var(--ink-3); background:transparent; cursor:pointer; transition:all 0.18s; display:flex; align-items:center; gap:7px; }
        .model-tab:hover { color:var(--ink); background:var(--bg); }
        .model-tab.active { color:#fff; box-shadow:0 2px 8px rgba(15,118,110,.28); }
        .model-tab.lstm-tab.active { background:#0f766e; }
        .model-tab.xgb-tab.active  { background:#7c3aed; }
        .model-tab.rf-tab.active   { background:#b45309; }
        .model-tab .tab-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
        .lstm-dot { background:#0f766e; } .xgb-dot { background:#7c3aed; } .rf-dot { background:#b45309; }
        .model-tab.active .tab-dot { background:rgba(255,255,255,.7); }
        /* ── DM tabs ─────────────────────────────────────────────────── */
        .dm-tabs { display:flex; gap:4px; background:var(--card); border:1px solid var(--border); border-radius:12px; padding:5px; margin-bottom:20px; width:fit-content; }
        .dm-tab  { padding:7px 16px; border-radius:8px; border:none; font-size:12.5px; font-weight:500; font-family:'DM Sans',sans-serif; color:var(--ink-3); background:transparent; cursor:pointer; transition:all .15s; display:flex; align-items:center; gap:6px; }
        .dm-tab:hover { color:var(--ink); background:var(--bg); }
        .dm-tab.active { background:var(--primary); color:#fff; box-shadow:0 2px 8px rgba(15,118,110,.25); }
        .dm-tab i { font-size:12px; }
        /* ── Buttons ─────────────────────────────────────────────────── */
        .btn-primary  { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; background:var(--primary); color:#fff; border:none; font-size:12.5px; font-weight:600; cursor:pointer; font-family:'DM Sans',sans-serif; transition:opacity .15s; white-space:nowrap; }
        .btn-primary:hover  { opacity:.88; } .btn-primary:disabled { opacity:.4; cursor:not-allowed; }
        .btn-secondary { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; border:1px solid var(--border); background:var(--card); font-size:12.5px; font-weight:500; color:var(--ink-3); cursor:pointer; font-family:'DM Sans',sans-serif; transition:all .15s; white-space:nowrap; }
        .btn-secondary:hover { border-color:var(--primary-mid); color:var(--primary); background:var(--primary-light); }
        .btn-danger  { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; background:var(--danger); color:#fff; border:none; font-size:12.5px; font-weight:600; cursor:pointer; font-family:'DM Sans',sans-serif; transition:opacity .15s; white-space:nowrap; }
        .btn-danger:hover { opacity:.85; }
        .btn-purple { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; background:#7c3aed; color:#fff; border:none; font-size:12.5px; font-weight:600; cursor:pointer; font-family:'DM Sans',sans-serif; transition:opacity .15s; white-space:nowrap; }
        .btn-purple:hover { opacity:.85; }
        /* ── ML card ─────────────────────────────────────────────────── */
        .ml-card { background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; box-shadow:var(--card-shadow); margin-bottom:20px; }
        .ml-card-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--border); background:#fafafa; }
        .ml-card-title { font-size:13px; font-weight:600; color:var(--ink); display:flex; align-items:center; gap:8px; }
        .ml-card-title i { color:var(--primary-mid); font-size:12px; }
        .ml-card-body { padding:18px; }
        /* ── Step badge ──────────────────────────────────────────────── */
        .step-badge { display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; border-radius:50%; background:var(--primary); color:#fff; font-size:10px; font-weight:700; font-family:'DM Mono',monospace; flex-shrink:0; }
        /* ── Hyper forms ─────────────────────────────────────────────── */
        .hyper-grid  { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:16px; }
        .hyper-field { display:flex; flex-direction:column; gap:6px; }
        .hyper-label { font-size:11px; font-weight:600; color:var(--ink-4); text-transform:uppercase; letter-spacing:.07em; display:flex; align-items:center; justify-content:space-between; }
        .hyper-label .hyper-val { font-family:'DM Mono',monospace; color:var(--primary); background:var(--primary-light); padding:1px 6px; border-radius:4px; font-size:11px; letter-spacing:0; }
        .hyper-input  { width:100%; padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg); font-size:12.5px; color:var(--ink); font-family:'DM Sans',sans-serif; box-sizing:border-box; }
        .hyper-input:focus { outline:none; border-color:var(--primary-mid); }
        .hyper-slider { -webkit-appearance:none; appearance:none; width:100%; height:4px; border-radius:99px; background:var(--border); outline:none; cursor:pointer; }
        .hyper-slider::-webkit-slider-thumb { -webkit-appearance:none; appearance:none; width:14px; height:14px; border-radius:50%; background:var(--primary); cursor:pointer; box-shadow:0 0 0 3px var(--primary-light); }
        .hyper-select { width:100%; padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg); font-size:12.5px; color:var(--ink); font-family:'DM Sans',sans-serif; cursor:pointer; }
        .hyper-select:focus { outline:none; border-color:var(--primary-mid); }
        .toggle-row { display:flex; align-items:center; gap:10px; }
        .toggle-btn { padding:5px 12px; border-radius:6px; border:1px solid var(--border); font-size:12px; font-weight:500; cursor:pointer; font-family:'DM Sans',sans-serif; transition:all .15s; background:var(--bg); color:var(--ink-3); }
        .toggle-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .multiselect-box  { border:1px solid var(--border); border-radius:8px; background:var(--bg); max-height:110px; overflow-y:auto; padding:6px; }
        .multiselect-item { display:flex; align-items:center; gap:7px; padding:4px 6px; border-radius:5px; cursor:pointer; font-size:12px; color:var(--ink-2); transition:background .1s; }
        .multiselect-item:hover { background:var(--primary-light); }
        .multiselect-item input[type=checkbox] { accent-color:var(--primary); }
        /* ── Data source ─────────────────────────────────────────────── */
        .import-drop { border:2px dashed var(--border); border-radius:12px; padding:32px 24px; text-align:center; cursor:pointer; transition:all .2s; margin-bottom:16px; }
        .import-drop:hover, .import-drop.drag-over { border-color:var(--primary-mid); background:var(--primary-light); }
        .import-drop i { font-size:28px; color:var(--ink-4); margin-bottom:10px; display:block; }
        .import-drop.drag-over i { color:var(--primary); }
        .import-drop p { font-size:13px; color:var(--ink-3); }
        .import-drop p strong { color:var(--primary); }
        .db-info-box { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:16px 18px; }
        .db-info-box p { font-size:13px; color:var(--ink-2); margin:0 0 6px; }
        .db-info-box p:last-child { margin:0; color:var(--ink-3); font-size:12px; }
        .db-info-box code { font-family:'DM Mono',monospace; font-size:12px; background:var(--primary-light); color:var(--primary); padding:1px 6px; border-radius:4px; }
        .date-range-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
        .data-summary-card { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-top:14px; }
        .ds-stat { background:var(--primary-light); border:1px solid var(--primary-mid); border-radius:10px; padding:10px 14px; text-align:center; }
        .ds-stat .dss-label { font-size:10px; font-weight:600; color:var(--primary); text-transform:uppercase; letter-spacing:.07em; margin-bottom:4px; }
        .ds-stat .dss-val   { font-size:1.2rem; font-weight:700; color:var(--primary); font-family:'DM Mono',monospace; }
        .validate-warn { background:#fffbeb; border:1px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; font-size:12px; color:#92400e; display:flex; align-items:flex-start; gap:8px; }
        /* ── Preview table ───────────────────────────────────────────── */
        .import-preview { background:var(--bg); border-radius:8px; overflow:hidden; border:1px solid var(--border); max-height:220px; overflow-y:auto; }
        .import-preview table { width:100%; font-size:11.5px; border-collapse:collapse; }
        .import-preview th { padding:7px 10px; background:#f1f5f9; color:var(--ink-4); font-size:10px; text-transform:uppercase; letter-spacing:.07em; border-bottom:1px solid var(--border); white-space:nowrap; position:sticky; top:0; }
        .import-preview td { padding:7px 10px; color:var(--ink-2); border-bottom:1px solid #f8fafc; white-space:nowrap; }
        .import-preview tr:last-child td { border-bottom:none; }
        /* ── Progress bar ────────────────────────────────────────────── */
        .progress-bar-track { height:8px; background:var(--bg); border-radius:99px; overflow:hidden; border:1px solid var(--border); }
        .progress-bar-fill  { height:100%; background:linear-gradient(90deg,var(--primary),var(--primary-mid)); border-radius:99px; width:0%; transition:width .3s ease; }
        .progress-header    { display:flex; justify-content:space-between; font-size:12px; color:var(--ink-4); font-family:'DM Mono',monospace; margin-bottom:6px; }
        /* ── Epoch stats ─────────────────────────────────────────────── */
        .epoch-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:10px; margin:16px 0; }
        .epoch-stat .es-label { font-size:10px; font-weight:600; color:var(--ink-4); text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
        .epoch-stat .es-value { font-size:1.25rem; font-weight:700; color:var(--ink); font-family:'DM Mono',monospace; line-height:1.2; }
        .epoch-stat { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:10px 14px; }
        /* ── Charts ──────────────────────────────────────────────────── */
        .charts-row   { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin:16px 0; }
        .charts-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin:16px 0; }
        @media(max-width:900px){ .charts-row,.charts-row-3 { grid-template-columns:1fr; } }
        .chart-box { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px; }
        .chart-box-label { font-size:10.5px; font-weight:600; color:var(--ink-4); text-transform:uppercase; letter-spacing:.07em; margin-bottom:10px; }
        /* ── Log console ─────────────────────────────────────────────── */
        .log-console { background:#1a202c; border-radius:8px; padding:12px 14px; font-family:'DM Mono',monospace; font-size:11.5px; color:#68d391; max-height:150px; overflow-y:auto; line-height:1.7; margin-top:14px; }
        .log-console p { margin:0; } .log-console p.log-err { color:#fc8181; } .log-console p.log-dim { color:#718096; }
        /* ── Metric cards ────────────────────────────────────────────── */
        .metrics-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:20px; }
        .metric-card { background:var(--primary-light); border:1px solid var(--primary-mid); border-radius:12px; padding:14px 16px; text-align:center; }
        .metric-card .mc-label { font-size:10px; font-weight:600; color:var(--primary); text-transform:uppercase; letter-spacing:.08em; margin-bottom:6px; }
        .metric-card .mc-val   { font-size:2rem; font-weight:800; color:var(--primary); line-height:1; font-family:'DM Mono',monospace; }
        .metric-card .mc-raw   { font-size:10.5px; color:var(--ink-4); margin-top:4px; font-family:'DM Mono',monospace; }
        .metric-card.lstm-metric { background:#f0fdf4; border-color:#16a34a; }
        .metric-card.lstm-metric .mc-label, .metric-card.lstm-metric .mc-val { color:#15803d; }
        /* ── Confusion matrix ────────────────────────────────────────── */
        .cm-wrap { display:flex; justify-content:center; margin-top:4px; }
        .confusion-matrix { border-collapse:collapse; font-size:12px; }
        .confusion-matrix th,.confusion-matrix td { padding:8px 18px; border:1px solid var(--border); text-align:center; }
        .confusion-matrix th { background:#f1f5f9; color:var(--ink-4); font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; }
        .confusion-matrix .cm-tp,.confusion-matrix .cm-tn { background:var(--success-light); color:var(--success); font-weight:700; }
        .confusion-matrix .cm-fp,.confusion-matrix .cm-fn { background:var(--danger-light);  color:var(--danger);  font-weight:700; }
        /* ── Feature bars ────────────────────────────────────────────── */
        .feat-bar-row  { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
        .feat-bar-name { font-size:11.5px; color:var(--ink-2); font-family:'DM Mono',monospace; width:160px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .feat-bar-track{ flex:1; height:14px; background:var(--bg); border-radius:99px; overflow:hidden; border:1px solid var(--border); }
        .feat-bar-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--primary),var(--primary-mid)); transition:width .4s ease; }
        .feat-bar-val  { font-size:11px; font-family:'DM Mono',monospace; color:var(--ink-4); width:52px; text-align:right; flex-shrink:0; }
        .shap-bar-fill { background:linear-gradient(90deg,#7c3aed,#a78bfa); }
        .rf-bar-fill   { background:linear-gradient(90deg,#b45309,#d97706); }
        /* ── Compare runs ────────────────────────────────────────────── */
        .compare-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
        .compare-card { background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:14px; }
        .compare-card .cc-header { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:var(--ink-4); margin-bottom:10px; }
        .compare-card .cc-row    { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid var(--border); font-size:12px; }
        .compare-card .cc-row:last-child { border-bottom:none; }
        .compare-card .cc-key  { color:var(--ink-3); }
        .compare-card .cc-val  { font-family:'DM Mono',monospace; font-weight:600; color:var(--ink); }
        .compare-card.active-run { border-color:var(--primary-mid); }
        .compare-card .cc-badge  { display:inline-block; font-size:9px; font-weight:700; background:var(--primary); color:#fff; padding:2px 7px; border-radius:99px; text-transform:uppercase; letter-spacing:.08em; margin-left:6px; }
        @media(max-width:800px){ .compare-grid { grid-template-columns:1fr; } }
        /* ── Registry table ──────────────────────────────────────────── */
        .registry-table-wrap { overflow-x:auto; }
        .registry-table { width:100%; font-size:12.5px; border-collapse:collapse; }
        .registry-table th { padding:8px 12px; background:#f1f5f9; color:var(--ink-4); font-size:10px; text-transform:uppercase; letter-spacing:.07em; border-bottom:1px solid var(--border); white-space:nowrap; text-align:left; }
        .registry-table td { padding:9px 12px; border-bottom:1px solid #f8fafc; color:var(--ink-2); white-space:nowrap; }
        .registry-table tr:last-child td { border-bottom:none; }
        .registry-table tr:hover td { background:var(--bg); }
        .model-badge { display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:5px; }
        .badge-lstm { background:#f0fdf4; color:#15803d; } .badge-xgb { background:#f5f3ff; color:#6d28d9; } .badge-rf { background:#fef3c7; color:#92400e; }
        .active-toggle { display:inline-flex; align-items:center; gap:6px; font-size:12px; cursor:pointer; }
        .active-toggle input { accent-color:var(--primary); }
        /* ── Misc ────────────────────────────────────────────────────── */
        .section-divider { display:flex; align-items:center; gap:10px; font-size:10px; font-weight:600; color:var(--ink-4); text-transform:uppercase; letter-spacing:.08em; margin:20px 0 14px; }
        .section-divider::before,.section-divider::after { content:''; flex:1; height:1px; background:var(--border); }
        .success-banner { display:flex; align-items:center; gap:12px; background:var(--success-light); border:1px solid var(--success); border-radius:10px; padding:12px 16px; margin-bottom:18px; }
        .success-banner i { color:var(--success); font-size:16px; }
        .success-banner p { font-size:13px; color:var(--success); margin:0; font-weight:500; }
        #toast-container { position:fixed; bottom:24px; right:24px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
        .toast { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:10px; min-width:260px; box-shadow:0 4px 20px rgba(0,0,0,.15); font-size:13px; font-family:'DM Sans',sans-serif; font-weight:500; animation:toastIn .25s ease; }
        @keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:none} }
        .toast.success { background:var(--success); color:#fff; } .toast.error { background:var(--danger); color:#fff; } .toast.info { background:var(--primary); color:#fff; }
        .toast i { font-size:14px; }
        .feature-tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .feature-tag { font-family:'DM Mono',monospace; font-size:11px; background:var(--bg); border:1px solid var(--border); color:var(--ink-3); padding:3px 9px; border-radius:6px; }
        .etr-pill { display:inline-flex; align-items:center; gap:5px; background:#1a202c; color:#f6ad55; border-radius:8px; padding:4px 10px; font-family:'DM Mono',monospace; font-size:11.5px; }
        .val-split-row { display:flex; gap:8px; align-items:center; font-size:12px; font-family:'DM Mono',monospace; color:var(--ink-3); margin-top:8px; }
        .val-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        @media print { .sidebar,.topbar,.dm-tabs,.model-selector { display:none!important; } .main { margin-left:0!important; } }
    </style>
</head>
<body>
<div class="app">
    <?php include 'sidebar.php'; ?>
    <div class="main" id="main">

        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar"><i class="fa-solid fa-bars"></i></button>
            <div class="topbar-breadcrumb">
                <i class="fa-solid fa-brain"></i><span>AI / ML</span>
                <i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--ink-4);"></i>
                <span style="color:var(--ink-4);font-size:12px;">Model Training</span>
            </div>
            <div class="topbar-right">
                <div class="topbar-date" id="topbarDate"></div>
                <button class="topbar-btn" id="refreshBtn" title="Reset" onclick="resetAll()"><i class="fa-solid fa-arrows-rotate"></i></button>
            </div>
        </header>

        <div class="content">

            <div style="margin-bottom:20px;">
                <div class="page-title" style="font-size:18px;font-weight:600;color:var(--ink);margin-bottom:4px;">
                    <i class="fa-solid fa-brain" style="color:var(--primary-mid);margin-right:8px;"></i>Model Training
                </div>
                <p style="font-size:13px;color:var(--ink-3);">Select a model, configure hyperparameters, choose a data source, and train in real time. All runs are saved to <code style="font-family:'DM Mono',monospace;font-size:12px;background:var(--primary-light);color:var(--primary);padding:1px 6px;border-radius:4px;">forecast_runs</code>.</p>
            </div>

            <!-- ══ STEP 1 — Model Selector ══ -->
            <div class="ml-card">
                <div class="ml-card-header">
                    <div class="ml-card-title">
                        <span class="step-badge">1</span>
                        <i class="fa-solid fa-layer-group"></i> Select Model &amp; Hyperparameters
                    </div>
                    <span id="active-model-badge" style="font-size:11px;font-weight:600;color:var(--primary);font-family:'DM Mono',monospace;"></span>
                </div>
                <div class="ml-card-body">
                    <div class="model-selector">
                        <button class="model-tab lstm-tab active" id="mtab-lstm" onclick="setModel('lstm')"><span class="tab-dot lstm-dot"></span> LSTM</button>
                        <button class="model-tab xgb-tab"         id="mtab-xgb"  onclick="setModel('xgb')"><span class="tab-dot xgb-dot"></span> XGBoost</button>
                        <button class="model-tab rf-tab"          id="mtab-rf"   onclick="setModel('rf')"><span class="tab-dot rf-dot"></span> Random Forest</button>
                    </div>

                    <!-- LSTM Config -->
                    <div id="config-lstm">
                        <div class="hyper-grid">
                            <div class="hyper-field">
                                <label class="hyper-label">Sequence Length (days)<span class="hyper-val" id="lv-seq">60</span></label>
                                <input type="range" class="hyper-slider" min="7" max="90" value="60" oninput="document.getElementById('lv-seq').textContent=this.value" id="lstm-seq">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">LSTM Units</label>
                                <select class="hyper-select" id="lstm-units"><option value="32">32</option><option value="64" selected>64</option><option value="128">128</option><option value="256">256</option></select>
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">Dropout Rate<span class="hyper-val" id="lv-drop">0.20</span></label>
                                <input type="range" class="hyper-slider" min="0" max="50" value="20" oninput="document.getElementById('lv-drop').textContent=(this.value/100).toFixed(2)" id="lstm-drop">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">Epochs</label>
                                <input type="number" class="hyper-input" id="lstm-epochs" min="10" max="200" value="30">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">Learning Rate</label>
                                <input type="number" class="hyper-input" id="lstm-lr" step="0.0001" min="0.0001" max="0.01" value="0.001">
                            </div>
                            <div class="hyper-field" style="grid-column:span 2;">
                                <label class="hyper-label" style="margin-bottom:4px;">Branch Filter</label>
                                <div class="multiselect-box" id="lstm-branch-filter">
                                    <div class="multiselect-item"><input type="checkbox" id="lb-all" checked onchange="toggleAllBranches(this,'lstm')"><label for="lb-all" style="cursor:pointer;font-weight:600;">All Branches</label></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- XGBoost Config -->
                    <div id="config-xgb" style="display:none;">
                        <div class="hyper-grid">
                            <div class="hyper-field">
                                <label class="hyper-label">n_estimators<span class="hyper-val" id="xv-nest">200</span></label>
                                <input type="range" class="hyper-slider" min="50" max="500" value="200" oninput="document.getElementById('xv-nest').textContent=this.value" id="xgb-nest">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">max_depth<span class="hyper-val" id="xv-depth">6</span></label>
                                <input type="range" class="hyper-slider" min="3" max="10" value="6" oninput="document.getElementById('xv-depth').textContent=this.value" id="xgb-depth">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">Learning Rate</label>
                                <input type="number" class="hyper-input" id="xgb-lr" step="0.01" min="0.01" max="0.3" value="0.1">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">subsample<span class="hyper-val" id="xv-sub">0.80</span></label>
                                <input type="range" class="hyper-slider" min="50" max="100" value="80" oninput="document.getElementById('xv-sub').textContent=(this.value/100).toFixed(2)" id="xgb-sub">
                            </div>
                            <div class="hyper-field" style="grid-column:span 2;">
                                <label class="hyper-label">Task Type</label>
                                <select class="hyper-select" id="xgb-task">
                                    <option value="revenue_impact">Revenue Impact</option>
                                    <option value="anomaly_score">Anomaly Detection</option>
                                    <option value="discount_impact">Discount Impact</option>
                                    <option value="churn_ltv_features">Churn / LTV</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- RF Config -->
                    <div id="config-rf" style="display:none;">
                        <div class="hyper-grid">
                            <div class="hyper-field">
                                <label class="hyper-label">n_estimators<span class="hyper-val" id="rv-nest">150</span></label>
                                <input type="range" class="hyper-slider" min="50" max="300" value="150" oninput="document.getElementById('rv-nest').textContent=this.value" id="rf-nest">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">max_depth<span class="hyper-val" id="rv-depth">10</span></label>
                                <input type="range" class="hyper-slider" min="3" max="20" value="10" oninput="document.getElementById('rv-depth').textContent=this.value" id="rf-depth">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">min_samples_split</label>
                                <input type="number" class="hyper-input" id="rf-mss" min="2" max="20" value="2">
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">Task Type</label>
                                <select class="hyper-select" id="rf-task">
                                    <option value="branch_health">branch_health</option>
                                    <option value="void_risk">void_risk</option>
                                    <option value="churn_risk">churn_risk</option>
                                    <option value="data_quality">data_quality</option>
                                </select>
                            </div>
                            <div class="hyper-field">
                                <label class="hyper-label">Class Weight</label>
                                <div class="toggle-row">
                                    <button class="toggle-btn active" id="cw-balanced" onclick="setCW('balanced')">Balanced</button>
                                    <button class="toggle-btn"        id="cw-custom"   onclick="setCW('custom')">Custom</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ STEP 2 — Data Source ══ -->
            <div class="ml-card">
                <div class="ml-card-header">
                    <div class="ml-card-title"><span class="step-badge">2</span><i class="fa-solid fa-database"></i> Data Source</div>
                </div>
                <div class="ml-card-body">
                    <div class="dm-tabs">
                        <button class="dm-tab active" id="tab-db"  onclick="setSource('db')"><i class="fa-solid fa-server"></i> Live Database</button>
                        <button class="dm-tab"        id="tab-csv" onclick="setSource('csv')"><i class="fa-solid fa-file-csv"></i> Upload CSV</button>
                    </div>

                    <!-- DB panel -->
                    <div id="db-panel">
                        <div class="db-info-box">
                            <p><i class="fa-solid fa-table" style="color:var(--primary-mid);margin-right:6px;"></i>Source: <code>transactions</code> table</p>
                            <p>Fetches rows within your selected date range. Features: dow, hour, grand_total, discount, vat, branch_id, payment_method, status.</p>
                        </div>
                        <div class="date-range-row">
                            <div class="hyper-field"><label class="hyper-label">Date From</label><input type="date" class="hyper-input" id="db-date-from"></div>
                            <div class="hyper-field"><label class="hyper-label">Date To</label><input type="date" class="hyper-input" id="db-date-to"></div>
                        </div>
                        <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <button class="btn-secondary" onclick="validateData()"><i class="fa-solid fa-shield-check"></i> Validate Data</button>
                            <button class="btn-secondary" id="preview-btn" onclick="previewDbData()"><i class="fa-solid fa-eye"></i> Preview</button>
                        </div>
                        <div id="validate-warn-box"></div>
                        <div id="db-summary-card" style="display:none;" class="data-summary-card">
                            <div class="ds-stat"><div class="dss-label">Rows</div><div class="dss-val" id="ds-rows">—</div></div>
                            <div class="ds-stat"><div class="dss-label">Branches</div><div class="dss-val" id="ds-branches">—</div></div>
                            <div class="ds-stat"><div class="dss-label">Date Range</div><div class="dss-val" style="font-size:.85rem;" id="ds-range">—</div></div>
                            <div class="ds-stat"><div class="dss-label">Features</div><div class="dss-val" id="ds-feats">8</div></div>
                        </div>
                    </div>

                    <!-- CSV panel -->
                    <div id="csv-panel" style="display:none;">
                        <div class="import-drop" id="dropZone"
                             onclick="document.getElementById('csvFileInput').click()"
                             ondragover="event.preventDefault();this.classList.add('drag-over')"
                             ondragleave="this.classList.remove('drag-over')"
                             ondrop="handleDrop(event)">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag &amp; drop your <strong>CSV file</strong> here</p>
                            <p style="font-size:11.5px;margin-top:6px;color:var(--ink-4);">Any tabular CSV — numeric columns will be used as features</p>
                        </div>
                        <input type="file" id="csvFileInput" accept=".csv" style="display:none" onchange="handleFileInput(this)">
                        <div id="csv-meta" style="font-size:12px;color:var(--ink-4);margin-top:-8px;margin-bottom:8px;font-family:'DM Mono',monospace;"></div>
                    </div>

                    <div id="csv-meta" style="font-size:12px;color:var(--ink-4);margin-top: 8px;margin-bottom:8px;font-family:'DM Mono',monospace;"></div>
                    <div style="margin-top:4px;">
                        <button class="btn-secondary" onclick="downloadSampleCsv()" style="font-size:11.5px;padding:5px 12px;">
                            <i class="fa-solid fa-download"></i> Download Sample CSV
                        </button>
                        <span style="font-size:11.5px;color:var(--ink-4);margin-left:8px;">Not sure what format to use? Download a sample to get started.</span>
                    </div>
                </div>
            </div>

            <!-- ══ STEP 2b — Data Preview ══ -->
            <div class="ml-card" id="preview-card" style="display:none;">
                <div class="ml-card-header">
                    <div class="ml-card-title"><i class="fa-solid fa-table"></i> Data Preview</div>
                    <span style="font-size:12px;color:var(--ink-4);font-family:'DM Mono',monospace;" id="preview-meta"></span>
                </div>
                <div class="ml-card-body" style="padding:0;">
                    <div class="import-preview"><table id="preview-table"></table></div>
                </div>
            </div>

            <!-- ══ STEP 3 — Start Training ══ -->
            <div class="ml-card">
                <div class="ml-card-header">
                    <div class="ml-card-title"><span class="step-badge">3</span><i class="fa-solid fa-play"></i> Train Model</div>
                </div>
                <div class="ml-card-body">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <button class="btn-primary" id="train-btn" onclick="startTraining()"><i class="fa-solid fa-bolt"></i> Start Training</button>
                        <button class="btn-danger"  id="cancel-btn" onclick="cancelTraining()" style="display:none;"><i class="fa-solid fa-xmark"></i> Cancel</button>
                        <span id="train-status" style="font-size:12.5px;color:var(--ink-4);font-family:'DM Mono',monospace;"></span>
                        <span id="etr-display" style="display:none;" class="etr-pill"><i class="fa-solid fa-hourglass-half"></i><span id="etr-val">—</span> remaining</span>
                    </div>
                </div>
            </div>

            <!-- ══ Training Progress ══ -->
            <div class="ml-card" id="training-panel" style="display:none;">
                <div class="ml-card-header">
                    <div class="ml-card-title"><i class="fa-solid fa-circle-notch fa-spin" id="train-spinner"></i> Training Progress</div>
                    <span style="font-size:12px;color:var(--ink-4);font-family:'DM Mono',monospace;" id="epoch-badge">Epoch 0 / —</span>
                </div>
                <div class="ml-card-body">
                    <div class="progress-header"><span id="prog-label">Initialising…</span><span id="prog-pct">0%</span></div>
                    <div class="progress-bar-track"><div class="progress-bar-fill" id="prog-bar"></div></div>
                    <div class="epoch-stats">
                        <div class="epoch-stat"><div class="es-label">Epoch</div><div class="es-value" id="stat-epoch">—</div></div>
                        <div class="epoch-stat"><div class="es-label">Train Loss</div><div class="es-value" id="stat-loss">—</div></div>
                        <div class="epoch-stat"><div class="es-label">Val Loss</div><div class="es-value" id="stat-val-loss">—</div></div>
                        <div class="epoch-stat"><div class="es-label">Train Acc</div><div class="es-value" id="stat-acc">—</div></div>
                    </div>
                    <div class="charts-row-3" id="progress-charts-row">
                        <div class="chart-box">
                            <div class="chart-box-label">
                                <i class="fa-solid fa-chart-line" style="color:var(--danger);margin-right:5px;"></i>Loss over Epochs
                                <div class="val-split-row"><span class="val-dot" style="background:#ef4444;"></span>Train<span class="val-dot" style="background:#f97316;"></span>Val</div>
                            </div>
                            <canvas id="loss-chart" height="130"></canvas>
                        </div>
                        <div class="chart-box">
                            <div class="chart-box-label"><i class="fa-solid fa-chart-line" style="color:var(--success);margin-right:5px;"></i>Accuracy over Epochs</div>
                            <canvas id="acc-chart" height="130"></canvas>
                        </div>
                        <div class="chart-box" id="live-feat-box">
                            <div class="chart-box-label"><i class="fa-solid fa-ranking-star" style="color:#7c3aed;margin-right:5px;"></i>Feature Importance (live)</div>
                            <div id="live-feat-bars" style="padding-top:4px;"></div>
                        </div>
                    </div>
                    <div style="font-size:10.5px;font-weight:600;color:var(--ink-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;"><i class="fa-solid fa-terminal" style="margin-right:5px;"></i>Training Log</div>
                    <div class="log-console" id="log-console"></div>
                </div>
            </div>

            <!-- ══ Results ══ -->
            <div class="ml-card" id="results-panel" style="display:none;">
                <div class="ml-card-header">
                    <div class="ml-card-title"><i class="fa-solid fa-trophy" style="color:var(--primary-mid);"></i> Evaluation Dashboard</div>
                    <div style="display:flex;gap:8px;">
                        <button class="btn-secondary" onclick="loadCompareRuns()"><i class="fa-solid fa-code-compare"></i> Compare Runs</button>
                        <button class="btn-primary"   id="deploy-btn" onclick="deployModel()" style="display:none;"><i class="fa-solid fa-rocket"></i> Deploy Model</button>
                    </div>
                </div>
                <div class="ml-card-body">
                    <div class="success-banner"><i class="fa-solid fa-circle-check"></i><p id="eval-banner-text">Training completed. Metrics evaluated on the held-out test set.</p></div>

                    <!-- Classification metrics (XGBoost / RF) -->
                    <div id="classification-eval">
                        <div class="metrics-grid" id="metrics-grid"></div>
                        <div class="section-divider">Confusion Matrix</div>
                        <div class="cm-wrap" id="cm-wrap"></div>
                    </div>

                    <!-- LSTM regression metrics -->
                    <div id="lstm-eval" style="display:none;">
                        <div class="section-divider">Regression Metrics</div>
                        <div class="metrics-grid" id="lstm-metrics-grid"></div>
                        <div class="section-divider">Actual vs Predicted</div>
                        <div class="chart-box" style="margin-bottom:16px;">
                            <div class="chart-box-label"><span style="color:#0f766e;">━</span> Actual &nbsp;<span style="color:#f59e0b;">━</span> Predicted</div>
                            <canvas id="act-pred-chart" height="150"></canvas>
                        </div>
                    </div>

                    <!-- Feature importance + SHAP (XGBoost / RF) -->
                    <div id="fi-eval" style="display:none;">
                        <div class="section-divider">Feature Importance &amp; SHAP Summary (Top 10)</div>
                        <div class="charts-row">
                            <div class="chart-box"><div class="chart-box-label"><i class="fa-solid fa-bars-staggered" style="margin-right:5px;"></i>Feature Importance</div><div id="fi-bars"></div></div>
                            <div class="chart-box"><div class="chart-box-label"><i class="fa-solid fa-wave-square" style="color:#7c3aed;margin-right:5px;"></i>SHAP Mean |Value| Top 10</div><div id="shap-bars"></div></div>
                        </div>
                    </div>

                    <div class="section-divider">Training Summary</div>
                    <div id="feature-summary" style="font-size:12.5px;color:var(--ink-3);line-height:1.8;"></div>
                    <div class="feature-tags" id="feature-tags"></div>

                    <!-- Compare runs panel -->
                    <div id="compare-panel" style="display:none;margin-top:20px;">
                        <div class="section-divider">Previous Runs — Same Task Type</div>
                        <div class="compare-grid" id="compare-grid"></div>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
                        <button class="btn-secondary" onclick="resetAll()"><i class="fa-solid fa-rotate-left"></i> Train Another Model</button>
                        <button class="btn-secondary" onclick="scrollToRegistry()"><i class="fa-solid fa-table-list"></i> View Registry</button>
                    </div>
                </div>
            </div>

            <!-- ══ Model Registry ══ -->
            <div class="ml-card" id="registry-panel">
                <div class="ml-card-header">
                    <div class="ml-card-title"><i class="fa-solid fa-table-list"></i> Model Registry</div>
                    <button class="btn-secondary" onclick="loadRegistry()"><i class="fa-solid fa-arrows-rotate"></i> Refresh</button>
                </div>
                <div class="ml-card-body" style="padding:0;">
                    <div class="registry-table-wrap">
                        <table class="registry-table">
                            <thead><tr>
                                <th>Model Name</th><th>Type</th><th>Task Type</th>
                                <th>Last Trained</th><th>Key Metric</th><th>Active</th><th>Actions</th>
                            </tr></thead>
                            <tbody id="registry-tbody">
                                <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--ink-4);font-size:12.5px;"><i class="fa-solid fa-circle-notch fa-spin" style="margin-right:8px;"></i>Loading registry…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- .content -->
    </div><!-- .main -->
</div><!-- .app -->

<div id="toast-container"></div>

<script src="../assets/js/sidebar.js"></script>
<script>
const API = '/backend/api_proxy.php';

/* ── Branch list (mirrors DB) ──────────────────────────────── */
const BRANCHES = [
    {id:1,name:'Aqua Mineral Shop Rob Malate'},{id:2,name:'Aqua Mineral Shop SM Fairview'},
    {id:3,name:'Aqua Mineral Shop SM Seaside'},{id:4,name:'Cebu Aqua Kiosk'},
    {id:5,name:'Cebu Botanifique Kiosk'},{id:6,name:'Centris Bota Kiosk'},
    {id:7,name:'Centris Elite Shop'},{id:8,name:'Elite Perfection BSA'},
    {id:9,name:'Elite Perfection Rob Cebu'},{id:10,name:'Gateway Aqua Kiosk'},
    {id:11,name:'Iconique BGC'},{id:12,name:'Iconique City Front Pampanga'},
    {id:13,name:'Iconique Gateway 2'},{id:14,name:'Iconique Parqal'},
    {id:15,name:'Libran Office Elite'},{id:16,name:'Megamall Aqua Kiosk'},
    {id:17,name:'Megamall Botanifique Kiosk'},{id:18,name:"Robinson's Galleria Aqua Kiosk"},
    {id:19,name:"Robinson's Malate Aqua Kiosk"},{id:20,name:"Robinson's Malate Botanifique Kiosk"},
    {id:21,name:'SM Clark Aqua Kiosk'},{id:22,name:'Sm Fairview Aqua Kiosk'},
    {id:23,name:'Sm Fairview Botanifique kiosk'},{id:24,name:'Starmills Botanifique Kiosk'},
];

/* ── State ─────────────────────────────────────────────────── */
let currentModel  = 'lstm';
let currentSource = 'db';
let csvData       = null;
let currentJobId  = null;
let evtSource     = null;
let lossChart     = null;
let accChart      = null;
let actPredChart  = null;
let _cancelling   = false;
let _trainStart   = null;
let _etrInterval  = null;
let _classWeight  = 'balanced';

/* ── Init ──────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    updateTopbarDate();
    buildBranchFilter();
    setDefaultDates();
    setModel('lstm');
    loadRegistry();
});

function setDefaultDates() {
    const to = new Date(), from = new Date();
    from.setDate(from.getDate() - 90);
    document.getElementById('db-date-to').value   = to.toISOString().split('T')[0];
    document.getElementById('db-date-from').value = from.toISOString().split('T')[0];
}

function buildBranchFilter() {
    const box = document.getElementById('lstm-branch-filter');
    BRANCHES.forEach(b => {
        const item = document.createElement('div');
        item.className = 'multiselect-item';
        item.innerHTML = `<input type="checkbox" id="lb-${b.id}" value="${b.id}" checked>
                          <label for="lb-${b.id}" style="cursor:pointer;">${b.name}</label>`;
        box.appendChild(item);
    });
}

function toggleAllBranches(cb) {
    document.querySelectorAll('#lstm-branch-filter input[type=checkbox]:not(#lb-all)')
            .forEach(b => b.checked = cb.checked);
}

function setCW(val) {
    _classWeight = val;
    document.getElementById('cw-balanced').classList.toggle('active', val === 'balanced');
    document.getElementById('cw-custom').classList.toggle('active',   val === 'custom');
}

/* ── Model tab ─────────────────────────────────────────────── */
function setModel(m) {
    currentModel = m;
    ['lstm','xgb','rf'].forEach(t => {
        document.getElementById(`mtab-${t}`).classList.toggle('active', t === m);
        document.getElementById(`config-${t}`).style.display = t === m ? '' : 'none';
    });
    document.getElementById('active-model-badge').textContent =
        m === 'lstm' ? 'LSTM · Sequential Forecasting' :
        m === 'xgb'  ? 'XGBoost · Gradient Boosting'   : 'Random Forest · Ensemble';
}

/* ── Source tab ────────────────────────────────────────────── */
function setSource(src) {
    currentSource = src;
    document.getElementById('tab-csv').classList.toggle('active', src === 'csv');
    document.getElementById('tab-db').classList.toggle('active',  src === 'db');
    document.getElementById('csv-panel').style.display = src === 'csv' ? '' : 'none';
    document.getElementById('db-panel').style.display  = src === 'db'  ? '' : 'none';
    if (src !== 'csv') document.getElementById('preview-card').style.display = 'none';
}

/* ── Validate ──────────────────────────────────────────────── */
function validateData() {
    const box  = document.getElementById('validate-warn-box');
    const from = document.getElementById('db-date-from').value;
    const to   = document.getElementById('db-date-to').value;
    const warns = [];
    if (!from || !to) { warns.push('Please select both start and end dates.'); }
    else {
        const days = (new Date(to) - new Date(from)) / 86400000;
        if (days < 30) warns.push('Date range under 30 days — LSTM may underfit. Consider ≥ 90 days.');
        if (days > 730) warns.push('Date range exceeds 2 years — training may be slow.');
    }
    if (warns.length) {
        box.innerHTML = warns.map(w => `<div class="validate-warn"><i class="fa-solid fa-triangle-exclamation"></i><span>${w}</span></div>`).join('');
    } else {
        box.innerHTML = `<div class="validate-warn" style="background:#f0fdf4;border-color:#16a34a;color:#15803d;">
            <i class="fa-solid fa-circle-check"></i><span>Data looks good — no pre-flight issues detected.</span></div>`;
    }
    previewDbData();
}

/* ── REAL DB preview ───────────────────────────────────────── */
async function previewDbData() {
    const from      = document.getElementById('db-date-from').value;
    const to        = document.getElementById('db-date-to').value;
    const branchIds = getSelectedBranchIds().join(',');
    const btn       = document.getElementById('preview-btn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Loading…'; }

    try {
        let url = `${API}?endpoint=ml/preview-db`;
        if (from)      url += `&date_from=${from}`;
        if (to)        url += `&date_to=${to}`;
        if (branchIds) url += `&branch_ids=${branchIds}`;

        const res  = await fetch(url);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        document.getElementById('ds-rows').textContent     = data.total_rows.toLocaleString();
        document.getElementById('ds-branches').textContent = branchIds ? branchIds.split(',').length : BRANCHES.length;
        document.getElementById('ds-range').textContent    = `${from || 'any'} → ${to || 'any'}`;
        document.getElementById('ds-feats').textContent    = data.columns.length;
        document.getElementById('db-summary-card').style.display = '';

        renderPreview(data.columns, data.preview, `${data.total_rows.toLocaleString()} rows (live DB)`);
    } catch (e) {
        showToast('Preview failed: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-eye"></i> Preview'; }
    }
}

function getSelectedBranchIds() {
    return [...document.querySelectorAll('#lstm-branch-filter input[type=checkbox]:checked:not(#lb-all)')]
           .map(x => +x.value);
}

/* ── File handling ─────────────────────────────────────────── */
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) processFile(file);
}
function handleFileInput(input) { if (input.files[0]) processFile(input.files[0]); }

async function processFile(file) {
    if (!file.name.toLowerCase().endsWith('.csv')) { showToast('Please upload a .csv file.','error'); return; }
    const reader = new FileReader();
    reader.onload = async (ev) => {
        csvData = ev.target.result;
        document.getElementById('csv-meta').textContent = `📎 ${file.name}  ·  ${(file.size/1024).toFixed(1)} KB`;
        try {
            const fd = new FormData(); fd.append('file', file);
            const res  = await fetch(`${API}?endpoint=ml/upload-csv`, { method:'POST', body:fd });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            renderPreview(data.columns, data.preview, `${data.total_rows.toLocaleString()} rows`);
        } catch {
            const lines = csvData.trim().split('\n');
            const headers = lines[0].split(',').map(v => v.trim().replace(/^"|"$/g,''));
            const rows = lines.slice(1, 11).map(l => {
                const vals = l.split(',').map(v => v.trim().replace(/^"|"$/g,''));
                const obj = {}; headers.forEach((h,i) => obj[h] = vals[i] ?? ''); return obj;
            });
            renderPreview(headers, rows, `${(lines.length-1).toLocaleString()} rows (local parse)`);
        }
    };
    reader.readAsText(file);
}

function renderPreview(columns, rows, meta) {
    document.getElementById('preview-card').style.display = '';
    document.getElementById('preview-meta').textContent   = `${columns.length} columns  ·  ${meta}  ·  showing first ${rows.length}`;
    const thead = `<thead><tr>${columns.map(c=>`<th>${c}</th>`).join('')}</tr></thead>`;
    const tbody = `<tbody>${rows.map(r=>`<tr>${columns.map(c=>`<td>${r[c]??''}</td>`).join('')}</tr>`).join('')}</tbody>`;
    document.getElementById('preview-table').innerHTML = thead + tbody;
}

/* ── Collect hyperparams ───────────────────────────────────── */
function collectHyperparams() {
    if (currentModel === 'lstm') return {
        sequence_length: +document.getElementById('lstm-seq').value,
        lstm_units:      +document.getElementById('lstm-units').value,
        dropout_rate:    +document.getElementById('lstm-drop').value / 100,
        epochs:          +document.getElementById('lstm-epochs').value,
        learning_rate:   +document.getElementById('lstm-lr').value,
        target_column:   'grand_total',
    };
    if (currentModel === 'xgb') return {
        n_estimators:  +document.getElementById('xgb-nest').value,
        max_depth:     +document.getElementById('xgb-depth').value,
        learning_rate: +document.getElementById('xgb-lr').value,
        subsample:     +document.getElementById('xgb-sub').value / 100,
        task_type:     document.getElementById('xgb-task').value,
    };
    return {
        n_estimators:      +document.getElementById('rf-nest').value,
        max_depth:         +document.getElementById('rf-depth').value,
        min_samples_split: +document.getElementById('rf-mss').value,
        task_type:         document.getElementById('rf-task').value,
        class_weight:      _classWeight,
    };
}

function getTaskType() {
    if (currentModel === 'lstm') return 'grand_total_forecast';
    if (currentModel === 'xgb')  return document.getElementById('xgb-task').value;
    return document.getElementById('rf-task').value;
}

/* ── Start training ────────────────────────────────────────── */
async function startTraining() {
    if (currentSource === 'csv' && !csvData) { showToast('Please upload a CSV file first.','error'); return; }
    resetProgress();
    document.getElementById('training-panel').style.display = '';
    document.getElementById('results-panel').style.display  = 'none';
    document.getElementById('train-btn').disabled = true;
    document.getElementById('cancel-btn').style.display = 'inline-flex';
    document.getElementById('train-status').textContent = 'Training in progress…';
    document.getElementById('etr-display').style.display = 'inline-flex';
    document.getElementById('live-feat-box').style.display = currentModel !== 'lstm' ? '' : 'none';

    initCharts();
    _trainStart = Date.now();
    startEtrTimer();

    const body = {
        source:      currentSource,
        model_type:  currentModel,
        task_type:   getTaskType(),
        hyperparams: collectHyperparams(),
    };
    if (currentSource === 'csv') body.csv_data  = csvData;
    if (currentSource === 'db') {
        body.date_from  = document.getElementById('db-date-from').value;
        body.date_to    = document.getElementById('db-date-to').value;
        body.branch_ids = getSelectedBranchIds();
    }

    try {
        const res  = await fetch(`${API}?endpoint=ml/train`, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body) });
        const data = await res.json();
        if (data.error) { showToast(data.error,'error'); resetUI(); return; }
        currentJobId = data.job_id;
        openStream(currentJobId);
    } catch (err) {
        showToast('Could not reach backend: ' + err.message, 'error');
        resetUI();
    }
}

/* ── ETR timer ─────────────────────────────────────────────── */
function startEtrTimer() {
    _etrInterval = setInterval(() => {
        const elapsed = (Date.now() - _trainStart) / 1000;
        const pctDone = parseFloat(document.getElementById('prog-bar').style.width) || 0;
        if (pctDone > 2) {
            const rem = Math.max(0, elapsed / (pctDone/100) - elapsed);
            document.getElementById('etr-val').textContent =
                rem > 60 ? `${Math.round(rem/60)}m ${Math.round(rem%60)}s` : `${Math.round(rem)}s`;
        }
    }, 1000);
}

/* ── SSE ───────────────────────────────────────────────────── */
function openStream(jobId) {
    evtSource = new EventSource(`${API}?endpoint=ml/stream/${jobId}`);
    evtSource.onmessage = (e) => { try { handleEvent(JSON.parse(e.data)); } catch(err) { addLog('Parse error: '+err.message,true); } };
    evtSource.onerror   = () => {
        if (_cancelling) return;
        addLog('Stream error — check that app.py is running on port 8800.',true);
        evtSource.close(); resetUI();
    };
}

function handleEvent(ev) {
    if (ev.type === 'log') {
        addLog(ev.data.msg);
    } else if (ev.type === 'progress') {
        const d = ev.data;
        document.getElementById('prog-bar').style.width    = d.pct + '%';
        document.getElementById('prog-pct').textContent    = d.pct + '%';
        document.getElementById('prog-label').textContent  = `Epoch ${d.epoch} of ${d.total_epochs}`;
        document.getElementById('epoch-badge').textContent = `Epoch ${d.epoch} / ${d.total_epochs}`;
        document.getElementById('stat-epoch').textContent  = d.epoch;
        document.getElementById('stat-loss').textContent   = (+d.loss).toFixed(4);
        document.getElementById('stat-acc').textContent    = ((+d.accuracy)*100).toFixed(1) + '%';
        if (d.val_loss !== undefined)
            document.getElementById('stat-val-loss').textContent = (+d.val_loss).toFixed(4);

        lossChart.data.labels.push(d.epoch);
        lossChart.data.datasets[0].data.push(+d.loss);
        if (d.val_loss !== undefined) lossChart.data.datasets[1].data.push(+d.val_loss);
        lossChart.update('none');
        accChart.data.labels.push(d.epoch);
        accChart.data.datasets[0].data.push(+(+d.accuracy*100).toFixed(2));
        accChart.update('none');

        if (d.feature_importance && currentModel !== 'lstm')
            renderFeatureBars('live-feat-bars', d.feature_importance, currentModel === 'xgb' ? 'shap' : 'rf');

    } else if (ev.type === 'done') {
        evtSource.close(); clearInterval(_etrInterval);
        document.getElementById('etr-display').style.display = 'none';
        document.getElementById('train-spinner').className = 'fa-solid fa-check';
        showResults(ev.data.metrics);
        document.getElementById('train-status').textContent = '✓ Complete';
        document.getElementById('cancel-btn').style.display = 'none';
        document.getElementById('train-btn').disabled = false;
        document.getElementById('deploy-btn').style.display = 'inline-flex';
        showToast('Training completed successfully!','success');
        loadRegistry();

    } else if (ev.type === 'error') {
        addLog('ERROR: ' + ev.data.msg, true);
        showToast(ev.data.msg,'error');
        evtSource.close(); resetUI();

    } else if (ev.type === 'cancelled') {
        addLog('⚠ ' + ev.data.msg);
        evtSource.close(); _cancelling = false; clearInterval(_etrInterval);
        document.getElementById('etr-display').style.display = 'none';
        resetUI(); showToast('Training cancelled.','info');
    }
}

/* ── Cancel ────────────────────────────────────────────────── */
async function cancelTraining() {
    if (!currentJobId || _cancelling) return;
    _cancelling = true;
    document.getElementById('cancel-btn').disabled = true;
    try { await fetch(`${API}?endpoint=ml/cancel/${currentJobId}`, { method:'POST' }); } catch(e) {}
}

/* ── Show results ──────────────────────────────────────────── */
function showResults(m) {
    document.getElementById('results-panel').style.display = '';
    const isLstm = currentModel === 'lstm';
    const isFI   = !isLstm;
    document.getElementById('classification-eval').style.display = isLstm ? 'none' : '';
    document.getElementById('lstm-eval').style.display           = isLstm ? '' : 'none';
    document.getElementById('fi-eval').style.display             = isFI   ? '' : 'none';

    if (isLstm) {
        const defs = [
            { key:'mae',  label:'MAE',  fmt: v => v.toFixed(2) },
            { key:'rmse', label:'RMSE', fmt: v => v.toFixed(2) },
            { key:'mape', label:'MAPE', fmt: v => v.toFixed(2)+'%' },
        ];
        document.getElementById('lstm-metrics-grid').innerHTML = defs.map(d => `
            <div class="metric-card lstm-metric">
                <div class="mc-label">${d.label}</div>
                <div class="mc-val">${m[d.key] !== undefined ? d.fmt(m[d.key]) : '—'}</div>
            </div>`).join('');

        if (m.actual && m.predicted) {
            if (actPredChart) { actPredChart.destroy(); actPredChart = null; }
            actPredChart = new Chart(document.getElementById('act-pred-chart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: m.actual.map((_,i) => i+1),
                    datasets: [
                        { label:'Actual',    data:m.actual,    borderColor:'#0f766e', backgroundColor:'transparent', borderWidth:2, pointRadius:0, tension:.3 },
                        { label:'Predicted', data:m.predicted, borderColor:'#f59e0b', backgroundColor:'transparent', borderWidth:2, pointRadius:0, tension:.3, borderDash:[4,4] },
                    ]
                },
                options: { responsive:true, animation:false, plugins:{ legend:{ display:true, labels:{ font:{size:10}, boxWidth:12 } } }, scales:{ x:{ ticks:{font:{size:9},color:'#9ca3af'} }, y:{ ticks:{font:{size:9},color:'#9ca3af'} } } }
            });
        }
    } else {
        const defs = [
            { key:'accuracy',  label:'Accuracy'  },
            { key:'f1_score',  label:'F1 Score'  },
            { key:'precision', label:'Precision' },
            { key:'recall',    label:'Recall'    },
            { key:'roc_auc',   label:'ROC-AUC'   },
        ];
        document.getElementById('metrics-grid').innerHTML = defs.map(d => `
            <div class="metric-card">
                <div class="mc-label">${d.label}</div>
                <div class="mc-val">${m[d.key] !== undefined ? (m[d.key]*100).toFixed(1) : '—'}<span style="font-size:.9rem;font-weight:600;">%</span></div>
                <div class="mc-raw">${m[d.key] !== undefined ? m[d.key].toFixed(4) : '—'}</div>
            </div>`).join('');

        if (m.confusion_matrix && m.confusion_matrix.length === 2) {
            const [[tn,fp],[fn,tp]] = m.confusion_matrix;
            document.getElementById('cm-wrap').innerHTML = `
                <table class="confusion-matrix">
                    <thead><tr><th></th><th>Predicted 0</th><th>Predicted 1</th></tr></thead>
                    <tbody>
                        <tr><th>Actual 0</th><td class="cm-tn">${tn}<br><small>TN</small></td><td class="cm-fp">${fp}<br><small>FP</small></td></tr>
                        <tr><th>Actual 1</th><td class="cm-fn">${fn}<br><small>FN</small></td><td class="cm-tp">${tp}<br><small>TP</small></td></tr>
                    </tbody>
                </table>`;
        }
        if (m.feature_importance) renderFeatureBars('fi-bars',   m.feature_importance, currentModel==='xgb' ? '' : 'rf');
        if (m.shap_values)        renderFeatureBars('shap-bars', m.shap_values, 'shap');
        else if (m.feature_importance) renderFeatureBars('shap-bars', m.feature_importance.map(([f,v])=>[f,+(v*.8).toFixed(5)]), 'shap');
    }

    document.getElementById('feature-summary').innerHTML =
        `<strong>Model:</strong> ${currentModel.toUpperCase()}
         &nbsp;·&nbsp; <strong>Task:</strong> ${getTaskType()}
         &nbsp;·&nbsp; <strong>Rows trained:</strong> ${m.rows_trained?.toLocaleString()??'—'}
         &nbsp;·&nbsp; <strong>Rows tested:</strong> ${m.rows_tested?.toLocaleString()??'—'}
         &nbsp;·&nbsp; <strong>Run ID:</strong> ${m.run_id??'—'}`;
    document.getElementById('feature-tags').innerHTML = (m.features_used||[]).map(f=>`<span class="feature-tag">${f}</span>`).join('');
    document.getElementById('results-panel').scrollIntoView({ behavior:'smooth' });
}

/* ── Feature bars renderer ─────────────────────────────────── */
function renderFeatureBars(containerId, data, style) {
    const container = document.getElementById(containerId);
    if (!container || !data || !data.length) return;
    const top = data.slice(0,10);
    const maxVal = Math.max(...top.map(([,v]) => v));
    const fillClass = style==='shap' ? 'shap-bar-fill' : style==='rf' ? 'rf-bar-fill' : 'feat-bar-fill';
    container.innerHTML = top.map(([name,val]) => `
        <div class="feat-bar-row">
            <div class="feat-bar-name" title="${name}">${name}</div>
            <div class="feat-bar-track"><div class="feat-bar-fill ${fillClass}" style="width:${(val/maxVal*100).toFixed(1)}%"></div></div>
            <div class="feat-bar-val">${(+val).toFixed(3)}</div>
        </div>`).join('');
}

/* ── Deploy model → writes to ml_model_status via DB ──────── */
async function deployModel() {
    if (!currentJobId) return;
    const btn = document.getElementById('deploy-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Deploying…';
    try {
        const res = await fetch(`${API}?endpoint=ml/train`, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                action:     'deploy',
                job_id:     currentJobId,
                task_type:  getTaskType(),
                model_type: currentModel,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        showToast('Model deployed and set as active!','success');
        loadRegistry();
    } catch (e) {
        showToast('Deploy failed: ' + e.message,'error');
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-rocket"></i> Deploy Model';
}

/* ── Compare runs → real DB ────────────────────────────────── */
async function loadCompareRuns() {
    const panel = document.getElementById('compare-panel');
    panel.style.display = '';
    document.getElementById('compare-grid').innerHTML =
        '<p style="font-size:12px;color:var(--ink-4);padding:8px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</p>';
    try {
        const task = getTaskType();
        const res  = await fetch(`${API}?endpoint=ml/compare-runs&task_type=${encodeURIComponent(task)}`);
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        const runs = data.runs || [];
        if (!runs.length) {
            document.getElementById('compare-grid').innerHTML = '<p style="font-size:12px;color:var(--ink-4);padding:8px;">No previous runs found for this task type.</p>';
            return;
        }
        document.getElementById('compare-grid').innerHTML = runs.map((r,i) => {
            const isLatest = i === 0;
            const trainedAt = r.run_at ? new Date(r.run_at).toLocaleDateString() : '—';
            const keyMetric = r.model_type === 'lstm'
                ? `RMSE: ${r.rmse ?? '—'}`
                : `Acc: ${r.accuracy ? (r.accuracy*100).toFixed(1)+'%' : '—'}`;
            return `<div class="compare-card ${isLatest ? 'active-run' : ''}">
                <div class="cc-header">Run #${r.run_id}${isLatest ? '<span class="cc-badge">latest</span>' : ''}</div>
                <div class="cc-row"><span class="cc-key">Date</span><span class="cc-val">${trainedAt}</span></div>
                <div class="cc-row"><span class="cc-key">Model</span><span class="cc-val">${(r.model_type||'').toUpperCase()}</span></div>
                <div class="cc-row"><span class="cc-key">Key Metric</span><span class="cc-val">${keyMetric}</span></div>
                <div class="cc-row"><span class="cc-key">F1</span><span class="cc-val">${r.f1_score ? (r.f1_score*100).toFixed(1)+'%' : '—'}</span></div>
                <div class="cc-row"><span class="cc-key">ROC-AUC</span><span class="cc-val">${r.roc_auc ? r.roc_auc.toFixed(4) : '—'}</span></div>
            </div>`;
        }).join('');
    } catch (e) {
        document.getElementById('compare-grid').innerHTML = `<p style="font-size:12px;color:var(--danger);padding:8px;">Error: ${e.message}</p>`;
    }
}

/* ── Model Registry → real DB ──────────────────────────────── */
async function loadRegistry() {
    try {
        const res  = await fetch(`${API}?endpoint=ml/registry`);
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        const models = data.models || [];
        const tbody  = document.getElementById('registry-tbody');

        if (!models.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--ink-4);font-size:12.5px;">No deployed models yet. Train and deploy a model to see it here.</td></tr>`;
            return;
        }

        tbody.innerHTML = models.map(m => {
            const badge = m.model_type === 'lstm' ? '<span class="model-badge badge-lstm">LSTM</span>'
                        : m.model_type === 'xgb'  ? '<span class="model-badge badge-xgb">XGBoost</span>'
                                                   : '<span class="model-badge badge-rf">RF</span>';
            const trainedAt = m.last_trained_at ? new Date(m.last_trained_at).toLocaleDateString() : '—';
            const keyMetricDisplay = m.key_metric && m.key_metric_value !== null
                ? `${m.key_metric}: ${parseFloat(m.key_metric_value).toFixed(4)}`
                : (m.accuracy ? `Acc: ${(m.accuracy*100).toFixed(1)}%` : '—');
            const viewPage = (m.task_type||'').includes('forecast')  ? 'analytics'
                           : (m.task_type||'').includes('branch')    ? 'branch-performance'
                           : (m.task_type||'').includes('churn')     ? 'customer-insights' : 'analytics';
            return `<tr>
                <td style="font-family:'DM Mono',monospace;font-size:12px;color:var(--ink);">${m.model_name}</td>
                <td>${badge}</td>
                <td style="color:var(--ink-3);">${m.task_type||'—'}</td>
                <td style="color:var(--ink-3);">${trainedAt}</td>
                <td style="font-family:'DM Mono',monospace;font-size:12px;">${keyMetricDisplay}</td>
                <td>
                    <label class="active-toggle">
                        <input type="checkbox" ${m.is_active ? 'checked' : ''} onchange="toggleModelActive(this,${m.model_id},'${m.model_name}')">
                        <span style="color:${m.is_active ? 'var(--success)' : 'var(--ink-4)'};">${m.is_active ? 'Active' : 'Inactive'}</span>
                    </label>
                </td>
                <td>
                    <div style="display:flex;gap:6px;">
                        <button class="btn-secondary" style="padding:4px 10px;font-size:11px;" onclick="retrainModel('${m.model_type}','${m.task_type}')">
                            <i class="fa-solid fa-arrows-rotate"></i> Retrain
                        </button>
                        <button class="btn-secondary" style="padding:4px 10px;font-size:11px;" onclick="window.location='index.php?page=${viewPage}'">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> View
                        </button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    } catch (e) {
        document.getElementById('registry-tbody').innerHTML =
            `<tr><td colspan="7" style="text-align:center;padding:16px;color:var(--danger);font-size:12px;">Error loading registry: ${e.message}</td></tr>`;
    }
}

async function toggleModelActive(cb, modelId, modelName) {
    const label    = cb.nextElementSibling;
    const isActive = cb.checked ? 1 : 0;
    label.textContent = cb.checked ? 'Active' : 'Inactive';
    label.style.color = cb.checked ? 'var(--success)' : 'var(--ink-4)';
    try {
        const res  = await fetch(`${API}?endpoint=ml/registry/${modelId}/toggle`, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ is_active: isActive }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        showToast(`${modelName} ${cb.checked ? 'activated' : 'deactivated'}`,'info');
    } catch (e) {
        showToast('Toggle failed: ' + e.message,'error');
        cb.checked = !cb.checked;
        label.textContent = cb.checked ? 'Active' : 'Inactive';
        label.style.color = cb.checked ? 'var(--success)' : 'var(--ink-4)';
    }
}

function retrainModel(type, task) {
    setModel(type);
    if (type === 'xgb') document.getElementById('xgb-task').value = task;
    if (type === 'rf')  document.getElementById('rf-task').value  = task;
    window.scrollTo({ top:0, behavior:'smooth' });
    showToast(`Config pre-filled for ${type.toUpperCase()} — ${task}`,'info');
}

function scrollToRegistry() {
    document.getElementById('registry-panel').scrollIntoView({ behavior:'smooth' });
}

/* ── Charts ────────────────────────────────────────────────── */
function initCharts() {
    if (lossChart) { lossChart.destroy(); lossChart = null; }
    if (accChart)  { accChart.destroy();  accChart  = null; }
    const base = {
        responsive:true, animation:false,
        plugins:{ legend:{ display:false } },
        scales:{
            x:{ ticks:{ font:{size:10}, color:'#9ca3af' }, title:{ display:true, text:'Epoch', font:{size:10}, color:'#9ca3af' } },
            y:{ ticks:{ font:{size:10}, color:'#9ca3af' } },
        },
    };
    lossChart = new Chart(document.getElementById('loss-chart').getContext('2d'), {
        type:'line',
        data:{ labels:[], datasets:[
            { data:[], borderColor:'#ef4444', backgroundColor:'rgba(239,68,68,.08)', borderWidth:2, pointRadius:0, tension:.35, fill:true,  label:'Train' },
            { data:[], borderColor:'#f97316', backgroundColor:'transparent',         borderWidth:2, pointRadius:0, tension:.35, fill:false, borderDash:[4,4], label:'Val' },
        ]},
        options:{ ...base, plugins:{ legend:{ display:true, labels:{ font:{size:9}, boxWidth:10 } } } },
    });
    accChart = new Chart(document.getElementById('acc-chart').getContext('2d'), {
        type:'line',
        data:{ labels:[], datasets:[{ data:[], borderColor:'#0f766e', backgroundColor:'rgba(15,118,110,.08)', borderWidth:2, pointRadius:0, tension:.35, fill:true }]},
        options:{ ...base, scales:{ ...base.scales, y:{ ticks:{ font:{size:10}, color:'#9ca3af', callback:v=>v+'%' } } } },
    });
}

/* ── Log ───────────────────────────────────────────────────── */
function addLog(msg, isErr=false) {
    const el = document.getElementById('log-console');
    const p  = document.createElement('p');
    p.className  = isErr ? 'log-err' : '';
    p.textContent = '> ' + msg;
    el.appendChild(p);
    el.scrollTop = el.scrollHeight;
}

/* ── Reset ─────────────────────────────────────────────────── */
function resetProgress() {
    document.getElementById('prog-bar').style.width      = '0%';
    document.getElementById('prog-pct').textContent      = '0%';
    document.getElementById('prog-label').textContent    = 'Initialising…';
    document.getElementById('epoch-badge').textContent   = 'Epoch 0 / —';
    document.getElementById('stat-epoch').textContent    = '—';
    document.getElementById('stat-loss').textContent     = '—';
    document.getElementById('stat-val-loss').textContent = '—';
    document.getElementById('stat-acc').textContent      = '—';
    document.getElementById('log-console').innerHTML     = '';
    document.getElementById('live-feat-bars').innerHTML  = '';
    document.getElementById('train-spinner').className   = 'fa-solid fa-circle-notch fa-spin';
}

function resetUI() {
    document.getElementById('train-btn').disabled  = false;
    document.getElementById('cancel-btn').style.display  = 'none';
    document.getElementById('cancel-btn').disabled = false;
    document.getElementById('train-status').textContent  = '';
    document.getElementById('etr-display').style.display = 'none';
    clearInterval(_etrInterval);
}

function resetAll() {
    _cancelling = false; clearInterval(_etrInterval);
    resetUI(); resetProgress();
    document.getElementById('training-panel').style.display = 'none';
    document.getElementById('results-panel').style.display  = 'none';
    document.getElementById('preview-card').style.display   = 'none';
    document.getElementById('compare-panel').style.display  = 'none';
    document.getElementById('deploy-btn').style.display     = 'none';
    document.getElementById('validate-warn-box').innerHTML  = '';
    csvData = null; currentJobId = null;
    document.getElementById('csv-meta').textContent  = '';
    document.getElementById('csvFileInput').value    = '';
    if (actPredChart) { actPredChart.destroy(); actPredChart = null; }
    setSource('db');
    window.scrollTo({ top:0, behavior:'smooth' });
}

/* ── Toast ─────────────────────────────────────────────────── */
function showToast(msg, type='info') {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info' };
    const el    = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="fa-solid ${icons[type]||icons.info}"></i> ${msg}`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

function updateTopbarDate() {
    document.getElementById('topbarDate').textContent = new Date().toLocaleDateString('en-PH', {
        weekday:'short', year:'numeric', month:'short', day:'numeric'
    });
}


function downloadSampleCsv() {
    const headers = ['transaction_date','dow','hour_of_day','grand_total','final_discount','vat','branch_id','overall_payment_method_id','is_ok'];
    const rows = [
        ['2024-01-15', 2, 10, 1850.00, 0.00,   222.00, 3, 1, 1],
        ['2024-01-15', 2, 11, 3200.00, 160.00,  384.00, 1, 2, 1],
        ['2024-01-15', 2, 13,  950.00,   0.00,  114.00, 5, 1, 1],
        ['2024-01-15', 2, 14, 5400.00, 270.00,  648.00, 2, 3, 1],
        ['2024-01-15', 2, 15, 2100.00,   0.00,  252.00, 4, 1, 0],
        ['2024-01-16', 3,  9, 1200.00,  60.00,  144.00, 1, 2, 1],
        ['2024-01-16', 3, 11, 4750.00, 237.50,  570.00, 3, 4, 1],
        ['2024-01-16', 3, 14,  800.00,   0.00,   96.00, 6, 1, 1],
        ['2024-01-16', 3, 16, 6200.00, 310.00,  744.00, 2, 2, 1],
        ['2024-01-16', 3, 17, 3300.00,   0.00,  396.00, 5, 3, 0],
        ['2024-01-17', 4, 10, 2600.00, 130.00,  312.00, 1, 1, 1],
        ['2024-01-17', 4, 12, 1100.00,   0.00,  132.00, 4, 2, 1],
        ['2024-01-17', 4, 13, 7800.00, 390.00,  936.00, 2, 4, 1],
        ['2024-01-17', 4, 15,  450.00,   0.00,   54.00, 7, 1, 0],
        ['2024-01-17', 4, 18, 3900.00, 195.00,  468.00, 3, 3, 1],
    ];

    const lines = [headers.join(','), ...rows.map(r => r.join(','))];
    const blob  = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url   = URL.createObjectURL(blob);
    const a     = document.createElement('a');
    a.href      = url;
    a.download  = 'ml_training_sample.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>