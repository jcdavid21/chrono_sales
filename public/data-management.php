<?php
$current = 'data-management';

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
    <title>Data Management — ChronoSales</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        /* ── Data Management specific styles ─────────────────────── */
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

        /* ── Toolbar ──────────────────────────────────────────────── */
        .dm-toolbar {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 16px; flex-wrap: wrap;
        }
        .dm-search-wrap {
            flex: 1; min-width: 220px; max-width: 360px;
            position: relative;
        }
        .dm-search-wrap i {
            position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
            font-size: 12px; color: var(--ink-4);
        }
        .dm-search {
            width: 100%; padding: 7px 10px 7px 30px;
            border: 1px solid var(--border); border-radius: 8px;
            background: var(--card); font-size: 12.5px;
            font-family: 'DM Sans', sans-serif; color: var(--ink);
            transition: border-color 0.15s;
        }
        .dm-search:focus { outline: none; border-color: var(--primary-mid); }

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

        .btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--primary); color: #fff; border: none;
            font-size: 12.5px; font-weight: 600; cursor: pointer;
            font-family: 'DM Sans', sans-serif; transition: opacity 0.15s; white-space: nowrap;
        }
        .btn-primary:hover { opacity: 0.88; }
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
        .btn-danger:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── Table ────────────────────────────────────────────────── */
        .dm-table-wrap {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 14px; overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        .dm-table {
            width: 100%; border-collapse: collapse; font-size: 12.5px;
        }
        .dm-table th {
            padding: 10px 14px; text-align: left;
            font-size: 10.5px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.07em; color: var(--ink-4);
            border-bottom: 1px solid var(--border);
            background: #fafafa; white-space: nowrap; cursor: pointer;
            user-select: none; transition: color 0.15s;
        }
        .dm-table th:hover { color: var(--primary); }
        .dm-table th .sort-icon { margin-left: 4px; font-size: 9px; opacity: 0.5; }
        .dm-table th.sorted .sort-icon { opacity: 1; color: var(--primary); }
        .dm-table td {
            padding: 11px 14px; color: var(--ink-2);
            border-bottom: 1px solid #f1f5f9; vertical-align: middle;
        }
        .dm-table tr:last-child td { border-bottom: none; }
        .dm-table tr:hover td { background: #f9fafb; }
        .dm-table tr.selected td { background: var(--primary-light); }

        .dm-table td.mono { font-family: 'DM Mono', monospace; font-size: 12px; }

        .cb-wrap { display: flex; align-items: center; justify-content: center; }
        .row-cb { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary); }

        .action-btns { display: flex; gap: 6px; align-items: center; }
        .icon-btn {
            width: 28px; height: 28px; border-radius: 6px; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 12px; cursor: pointer; transition: all 0.15s;
        }
        .icon-btn.edit { background: var(--primary-light); color: var(--primary); }
        .icon-btn.edit:hover { background: var(--primary); color: #fff; }
        .icon-btn.del { background: var(--danger-light); color: var(--danger); }
        .icon-btn.del:hover { background: var(--danger); color: #fff; }
        .icon-btn.view { background: var(--violet-light); color: var(--violet); }
        .icon-btn.view:hover { background: var(--violet); color: #fff; }

        /* ── Status badges ───────────────────────────────────────── */
        .status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px; border-radius: 99px;
            font-size: 10.5px; font-weight: 600; font-family: 'DM Mono', monospace;
        }
        .status-badge.ok      { background: var(--success-light); color: var(--success); }
        .status-badge.void    { background: var(--danger-light);  color: var(--danger); }
        .status-badge.pending { background: var(--accent-light);  color: #92400e; }
        .status-badge.active  { background: var(--success-light); color: var(--success); }
        .status-badge.inactive{ background: #f1f5f9;              color: var(--ink-3); }

        /* ── Pagination ───────────────────────────────────────────── */
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

        /* ── Modal ────────────────────────────────────────────────── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); z-index: 1000;
            align-items: center; justify-content: center;
            backdrop-filter: blur(3px); padding: 20px;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: var(--card); border-radius: 16px;
            width: 100%; max-width: 580px; max-height: 90vh;
            overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            animation: modalIn 0.2s ease;
        }
        .modal.wide { max-width: 800px; }
        @keyframes modalIn { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: none; } }
        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px; border-bottom: 1px solid var(--border);
            position: sticky; top: 0; background: var(--card); z-index: 1;
        }
        .modal-title {
            font-size: 14px; font-weight: 600; color: var(--ink);
            display: flex; align-items: center; gap: 8px;
        }
        .modal-title i { color: var(--primary-mid); }
        .modal-close {
            width: 30px; height: 30px; border-radius: 8px;
            border: none; background: var(--bg); color: var(--ink-3);
            font-size: 13px; cursor: pointer; display: flex;
            align-items: center; justify-content: center; transition: all 0.15s;
        }
        .modal-close:hover { background: var(--danger-light); color: var(--danger); }
        .modal-body { padding: 20px 22px; }
        .modal-footer {
            display: flex; align-items: center; justify-content: flex-end;
            gap: 8px; padding: 14px 22px; border-top: 1px solid var(--border);
            background: #fafafa; border-radius: 0 0 16px 16px;
        }

        /* ── Form fields ─────────────────────────────────────────── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid.single { grid-template-columns: 1fr; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.span2 { grid-column: 1 / -1; }
        .form-label {
            font-size: 10.5px; font-weight: 600; color: var(--ink-4);
            text-transform: uppercase; letter-spacing: 0.08em;
        }
        .form-label .required { color: var(--danger); margin-left: 2px; }
        .form-input, .form-select, .form-textarea {
            padding: 8px 11px; border-radius: 8px;
            border: 1px solid var(--border); background: var(--bg);
            font-size: 13px; color: var(--ink); font-family: 'DM Sans', sans-serif;
            transition: border-color 0.15s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: var(--primary-mid);
            background: #fff; box-shadow: 0 0 0 3px rgba(20,184,166,0.1);
        }
        .form-input.error, .form-select.error { border-color: var(--danger); }
        .form-error { font-size: 11px; color: var(--danger); margin-top: 2px; display: none; }
        .form-error.show { display: block; }
        .form-textarea { resize: vertical; min-height: 72px; }
        .form-select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%239ca3af' d='M0 0l5 6 5-6z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 10px center; padding-right: 28px;
        }

        /* ── Confirm modal ───────────────────────────────────────── */
        .confirm-icon {
            width: 52px; height: 52px; border-radius: 50%;
            background: var(--danger-light); color: var(--danger);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; margin: 0 auto 14px;
        }
        .confirm-title { font-size: 15px; font-weight: 600; color: var(--ink); text-align: center; margin-bottom: 8px; }
        .confirm-msg   { font-size: 13px; color: var(--ink-3); text-align: center; line-height: 1.6; }

        /* ── View detail modal ───────────────────────────────────── */
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .detail-field { display: flex; flex-direction: column; gap: 3px; }
        .detail-field.span2 { grid-column: 1 / -1; }
        .detail-key { font-size: 10.5px; font-weight: 600; color: var(--ink-4); text-transform: uppercase; letter-spacing: 0.07em; }
        .detail-val { font-size: 13px; color: var(--ink); }
        .detail-val.mono { font-family: 'DM Mono', monospace; }
        .detail-divider { grid-column: 1/-1; height: 1px; background: var(--border); margin: 4px 0; }

        /* ── Import area ─────────────────────────────────────────── */
        .import-drop {
            border: 2px dashed var(--border); border-radius: 12px;
            padding: 32px 24px; text-align: center; cursor: pointer;
            transition: all 0.2s; margin-bottom: 16px;
        }
        .import-drop:hover, .import-drop.drag-over {
            border-color: var(--primary-mid); background: var(--primary-light);
        }
        .import-drop i { font-size: 28px; color: var(--ink-4); margin-bottom: 10px; }
        .import-drop.drag-over i { color: var(--primary); }
        .import-drop p { font-size: 13px; color: var(--ink-3); }
        .import-drop p strong { color: var(--primary); }

        /* ── Import wizard steps ─────────────────────────────────── */
        .import-steps {
            display: flex; align-items: center; gap: 0;
            margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 16px;
        }
        .import-step {
            display: flex; align-items: center; gap: 8px;
            flex: 1; font-size: 12px; font-weight: 600; color: var(--ink-4);
        }
        .import-step-num {
            width: 24px; height: 24px; border-radius: 50%; border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; transition: all 0.2s; flex-shrink: 0;
        }
        .import-step.active { color: var(--primary); }
        .import-step.active .import-step-num { border-color: var(--primary); background: var(--primary); color: #fff; }
        .import-step.done { color: var(--ink-3); }
        .import-step.done .import-step-num { border-color: var(--success); background: var(--success); color: #fff; }
        .import-step-connector { flex: none; width: 24px; height: 1px; background: var(--border); margin: 0 4px; }

        /* ── Validation banner ───────────────────────────────────── */
        .import-validation {
            display: none; border-radius: 10px; padding: 12px 14px;
            margin-bottom: 14px; font-size: 12.5px; line-height: 1.5;
        }
        .import-validation.show { display: block; }
        .import-validation.ok { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; }
        .import-validation.warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .import-validation.err { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }
        .import-validation ul { margin: 6px 0 0 16px; padding: 0; }
        .import-validation li { margin-bottom: 3px; }
        .import-validation strong { display: flex; align-items: center; gap: 6px; font-size: 13px; }

        /* ── Column mapping ──────────────────────────────────────── */
        .col-map-grid {
            display: grid; grid-template-columns: 1fr auto 1fr; gap: 8px 12px;
            align-items: center; font-size: 12.5px; max-height: 320px; overflow-y: auto;
        }
        .col-map-header { font-weight: 700; color: var(--ink-4); text-transform: uppercase;
            font-size: 10px; letter-spacing: 0.08em; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
        .col-map-csv { font-family: 'DM Mono', monospace; font-size: 11.5px;
            background: var(--bg); border: 1px solid var(--border);
            padding: 5px 8px; border-radius: 6px; color: var(--ink-2); }
        .col-map-arrow { color: var(--ink-4); font-size: 12px; text-align: center; }
        .col-map-select {
            padding: 5px 8px; border-radius: 6px; border: 1px solid var(--border);
            background: var(--card); font-size: 12px; color: var(--ink);
            font-family: 'DM Sans', sans-serif; width: 100%;
        }
        .col-map-select.mapped { border-color: var(--primary-mid); background: var(--primary-light); color: var(--primary); }
        .col-map-select.required-missing { border-color: var(--danger); background: #fef2f2; }

        /* ── Import summary stats ────────────────────────────────── */
        .import-stats {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
            margin-bottom: 14px;
        }
        .import-stat {
            background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
            padding: 10px 12px; text-align: center;
        }
        .import-stat-num { font-size: 20px; font-weight: 700; font-family: 'DM Mono', monospace; color: var(--ink); }
        .import-stat-num.ok  { color: var(--success); }
        .import-stat-num.warn{ color: #f59e0b; }
        .import-stat-num.err { color: var(--danger); }
        .import-stat-label { font-size: 10px; color: var(--ink-4); margin-top: 2px; text-transform: uppercase; letter-spacing: 0.06em; }

        /* ── Preview table ───────────────────────────────────────── */
        .import-preview {
            background: var(--bg); border-radius: 8px; overflow: hidden;
            border: 1px solid var(--border); max-height: 280px; overflow-y: auto;
        }
        .import-preview table { width: 100%; font-size: 11.5px; border-collapse: collapse; min-width: 600px; }
        .import-preview th { padding: 7px 10px; background: #f1f5f9; color: var(--ink-4); font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.07em; border-bottom: 1px solid var(--border);
            position: sticky; top: 0; white-space: nowrap; }
        .import-preview td { padding: 7px 10px; color: var(--ink-2); border-bottom: 1px solid #f8fafc; white-space: nowrap; }
        .import-preview tr:last-child td { border-bottom: none; }
        .import-preview td.cell-err { background: #fef2f2; color: var(--danger); }
        .import-preview td.cell-warn { background: #fffbeb; color: #92400e; }
        .import-preview td.cell-ok { color: var(--success); font-weight: 600; }
        .import-preview tr.row-skip { opacity: 0.45; }

        /* ── Progress bar ─────────────────────────────────────────── */
        .import-progress-wrap {
            display: none; background: var(--bg); border: 1px solid var(--border);
            border-radius: 10px; padding: 20px; text-align: center;
        }
        .import-progress-wrap.show { display: block; }
        .import-progress-bar {
            height: 8px; background: var(--border); border-radius: 99px;
            overflow: hidden; margin: 12px 0;
        }
        .import-progress-fill {
            height: 100%; background: linear-gradient(90deg, var(--primary-mid), var(--primary));
            border-radius: 99px; transition: width 0.3s ease; width: 0%;
        }
        .import-progress-label { font-size: 12px; color: var(--ink-3); }

        /* ── Modal extra-wide for import ─────────────────────────── */
        .modal.import-modal { max-width: 900px; }

        /* ── Toast notification ──────────────────────────────────── */
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

        /* ── Bulk bar ─────────────────────────────────────────────── */
        .bulk-bar {
            display: none; align-items: center; gap: 10px;
            padding: 10px 16px; background: var(--primary-light);
            border: 1px solid var(--primary-mid); border-radius: 10px;
            margin-bottom: 12px; flex-wrap: wrap;
        }
        .bulk-bar.show { display: flex; }
        .bulk-bar-info { font-size: 12.5px; font-weight: 600; color: var(--primary); flex: 1; }

        /* ── Empty state ─────────────────────────────────────────── */
        .empty-state {
            padding: 48px 24px; text-align: center;
        }
        .empty-state i { font-size: 32px; color: var(--ink-4); margin-bottom: 12px; display: block; }
        .empty-state p { font-size: 13px; color: var(--ink-3); }
        .empty-state strong { display: block; font-size: 14px; color: var(--ink-2); margin-bottom: 4px; }

        /* ── Section count badge ──────────────────────────────────── */
        .count-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 18px; height: 18px; border-radius: 99px; padding: 0 5px;
            font-size: 10px; font-weight: 700; font-family: 'DM Mono', monospace;
            background: rgba(255,255,255,0.25); color: inherit;
        }
        .dm-tab:not(.active) .count-badge { background: var(--bg); color: var(--ink-4); }

        @media print {
            .sidebar, .topbar, .dm-toolbar, .dm-tabs, .bulk-bar, .action-btns,
            .cb-wrap, .dm-pagination { display: none !important; }
            .main { margin-left: 0 !important; }
        }
        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.span2 { grid-column: auto; }
            .detail-grid { grid-template-columns: 1fr; }
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
                <i class="fa-solid fa-database"></i>
                <span>Data Management</span>
                <i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--ink-4);"></i>
                <span id="activeTabLabel" style="color:var(--ink-4);font-size:12px;">Transactions</span>
            </div>
            <div class="topbar-right">
                <div class="topbar-date" id="topbarDate"></div>
                <button class="topbar-btn" id="refreshBtn" title="Refresh">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>
        </header>

        <!-- ── Content ─────────────────────────────────────────── -->
        <div class="content">

            <!-- Loading overlay -->
            <div class="loading-overlay" id="loadingOverlay">
                <div class="loading-spinner">
                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                    <span>Loading data…</span>
                </div>
            </div>

            <!-- Page header -->
            <div style="margin-bottom:20px;">
                <div class="page-title" style="font-size:18px;font-weight:600;color:var(--ink);margin-bottom:4px;">
                    <i class="fa-solid fa-database" style="color:var(--primary-mid);margin-right:8px;"></i>
                    Data Management
                </div>
                <p style="font-size:13px;color:var(--ink-3);">Create, edit, delete, and import records across all datasets. Changes are written directly to the database.</p>
            </div>

            <!-- Dataset Tabs -->
            <div class="dm-tabs">
                <button class="dm-tab active" data-tab="transactions" onclick="switchTab('transactions')">
                    <i class="fa-solid fa-receipt"></i> Transactions
                    <span class="count-badge" id="tab-count-transactions">—</span>
                </button>
                <button class="dm-tab" data-tab="customers" onclick="switchTab('customers')">
                    <i class="fa-solid fa-users"></i> Customers
                    <span class="count-badge" id="tab-count-customers">—</span>
                </button>
                <button class="dm-tab" data-tab="branches" onclick="switchTab('branches')">
                    <i class="fa-solid fa-store"></i> Branches
                    <span class="count-badge" id="tab-count-branches">—</span>
                </button>
            </div>

            <!-- Bulk action bar -->
            <div class="bulk-bar" id="bulkBar">
                <div class="bulk-bar-info"><span id="bulkCount">0</span> row(s) selected</div>
                <button class="btn-danger" id="bulkDeleteBtn" onclick="confirmBulkDelete()">
                    <i class="fa-solid fa-trash"></i> Delete Selected
                </button>
                <button class="btn-secondary" onclick="clearSelection()">
                    <i class="fa-solid fa-xmark"></i> Clear
                </button>
            </div>

            <!-- Toolbar -->
            <div class="dm-toolbar">
                <div class="dm-search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" class="dm-search" id="searchInput" placeholder="Search records…" oninput="onSearch()">
                </div>

                <!-- Per-tab filters injected here -->
                <div id="tabFilters"></div>

                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button class="btn-secondary" onclick="openImportModal()">
                        <i class="fa-solid fa-file-arrow-up"></i> Import CSV / XLSX
                    </button>
                    <button class="btn-secondary" onclick="exportCSV()">
                        <i class="fa-solid fa-file-arrow-down"></i> Export
                    </button>
                    <button class="btn-primary" id="addNewBtn" onclick="openAddModal()">
                        <i class="fa-solid fa-plus"></i> Add New
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="dm-table-wrap">
                <table class="dm-table" id="dmTable">
                    <thead id="dmTableHead"></thead>
                    <tbody id="dmTableBody">
                        <tr><td colspan="20"><div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><strong>Loading…</strong></div></td></tr>
                    </tbody>
                </table>
                <div class="dm-pagination">
                    <div class="page-info" id="pageInfo">—</div>
                    <div class="page-btns" id="pageBtns"></div>
                </div>
            </div>

        </div><!-- .content -->
    </div><!-- .main -->
</div><!-- .app -->

<!-- ════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════ -->

<!-- Add / Edit Modal -->
<div class="modal-overlay" id="formModalOverlay">
    <div class="modal wide" id="formModal">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-pen-to-square"></i> <span id="formModalTitle">Add Record</span></div>
            <button class="modal-close" onclick="closeFormModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="formModalBody"></div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeFormModal()">Cancel</button>
            <button class="btn-primary" id="formSubmitBtn" onclick="submitForm()">
                <i class="fa-solid fa-floppy-disk"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- View Detail Modal -->
<div class="modal-overlay" id="viewModalOverlay">
    <div class="modal wide" id="viewModal">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-eye"></i> Record Details</div>
            <button class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Confirm Delete Modal -->
<div class="modal-overlay" id="confirmModalOverlay">
    <div class="modal" style="max-width:400px;">
        <div class="modal-body" style="padding:28px 24px;">
            <div class="confirm-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="confirm-title">Delete Record?</div>
            <div class="confirm-msg" id="confirmMsg">This action cannot be undone.</div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn-danger" id="confirmDeleteBtn"><i class="fa-solid fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

<!-- Import Modal — Enhanced CSV Wizard -->
<div class="modal-overlay" id="importModalOverlay">
    <div class="modal import-modal">

        <!-- ── Header ── -->
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-file-excel"></i>
                <span id="importModalTitle">Import CSV</span>
            </div>
            <button class="modal-close" onclick="closeImportModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- ── Step indicator ── -->
        <div style="padding: 14px 22px 0;">
            <div class="import-steps">
                <div class="import-step active" id="stepIndicator1">
                    <div class="import-step-num" id="stepNum1">1</div>
                    <span>Upload</span>
                </div>
                <div class="import-step-connector"></div>
                <div class="import-step" id="stepIndicator2">
                    <div class="import-step-num" id="stepNum2">2</div>
                    <span>Map Columns</span>
                </div>
                <div class="import-step-connector"></div>
                <div class="import-step" id="stepIndicator3">
                    <div class="import-step-num" id="stepNum3">3</div>
                    <span>Validate</span>
                </div>
                <div class="import-step-connector"></div>
                <div class="import-step" id="stepIndicator4">
                    <div class="import-step-num" id="stepNum4">4</div>
                    <span>Confirm</span>
                </div>
            </div>
        </div>

        <!-- ── Body ── -->
        <div class="modal-body" id="importModalBody">

            <!-- STEP 1: Upload -->
            <div id="importStep1">
                <div class="import-drop" id="importDrop" onclick="document.getElementById('csvFileInput').click()">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <p>Click or drag &amp; drop your <strong>CSV file</strong> here</p>
                    <p style="font-size:11.5px;margin-top:6px;color:var(--ink-4);">
                        Accepted: .xlsx · .csv  ·  Max 20 MB  ·  Sales Report format (multi-sheet XLSX or single CSV)
                    </p>
                </div>
                <input type="file" id="csvFileInput" accept=".csv,.xlsx" style="display:none" onchange="handleSalesFile(event)">

                <!-- Quick-ref: expected columns per tab -->
                <div id="importColHints" style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--ink-3);">
                    <strong style="color:var(--ink-2);display:block;margin-bottom:6px;">
                        <i class="fa-solid fa-circle-info" style="color:var(--primary-mid);margin-right:6px;"></i>Expected columns for the current tab
                    </strong>
                    <span id="importColHintText" style="font-family:'DM Mono',monospace;font-size:11px;"></span>
                </div>
            </div>

            <!-- STEP 2: Sheet Preview -->
            <div id="importStep2" style="display:none;">
                <div id="sheetPreviewContainer"></div>
            </div>

            <!-- STEP 3: Validate & Preview -->
            <div id="importStep3" style="display:none;">
                <div class="import-stats" id="importStats"></div>
                <div class="import-validation" id="importValidation"></div>
                <div id="importPreviewWrap">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:12px;font-weight:600;color:var(--ink-2);" id="importPreviewLabel"></span>
                        <span style="font-size:11.5px;color:var(--ink-4);">Showing first 20 rows</span>
                    </div>
                    <div class="import-preview" id="importPreview"></div>
                </div>
            </div>

            <!-- STEP 4: Confirm -->
            <div id="importStep4" style="display:none;">
                <div id="importConfirmSummary" style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:16px;"></div>
                <div class="import-progress-wrap" id="importProgressWrap">
                    <div style="font-size:13px;font-weight:600;color:var(--ink-2);" id="importProgressTitle">Importing records…</div>
                    <div class="import-progress-bar">
                        <div class="import-progress-fill" id="importProgressFill"></div>
                    </div>
                    <div class="import-progress-label" id="importProgressLabel">Preparing…</div>
                </div>
            </div>

        </div><!-- /.modal-body -->

        <!-- ── Footer ── -->
        <div class="modal-footer">
            <button class="btn-secondary" id="importCancelBtn" onclick="closeImportModal()">Cancel</button>
            <button class="btn-secondary" id="importBackBtn" style="display:none;" onclick="importGoBack()">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <button class="btn-primary" id="importNextBtn" disabled onclick="importGoNext()">
                Next <i class="fa-solid fa-arrow-right"></i>
            </button>
            <button class="btn-primary" id="importSubmitBtn" style="display:none;" onclick="submitImport()">
                <i class="fa-solid fa-file-import"></i> Confirm &amp; Import
            </button>
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

/* ── State ────────────────────────────────────────────────── */
let state = {
    tab:          'transactions',
    page:         1,
    perPage:      20,
    search:       '',
    sortCol:      null,
    sortDir:      'asc',
    filters:      {},
    data:         [],
    total:        0,
    editId:       null,
    deleteId:     null,
    isBulkDelete: false,
    selected:     new Set(),
    importRows:   [],
    filterOptions: { branches: [], payments: [], discounts: [], statuses: [] },
};

/* ── Tab configs ──────────────────────────────────────────── */
const TABS = {
    transactions: {
        label: 'Transactions',
        endpoint: 'dm/transactions',
        pk: 'transaction_id',
        columns: [
            { key: 'transaction_id',    label: 'ID',        mono: true },
            { key: 'invoice_number',    label: 'Invoice',   mono: true },
            { key: 'transaction_date',  label: 'Date',      mono: true },
            { key: 'customer_name',     label: 'Customer' },
            { key: 'branch_name',       label: 'Branch' },
            { key: 'payment_method',    label: 'Payment' },
            { key: 'grand_total',       label: 'Total',     mono: true, fmt: 'peso' },
            { key: 'transaction_status',label: 'Status',    fmt: 'status' },
            { key: '_actions',          label: 'Actions',   nosort: true },
        ],
    },
    customers: {
        label: 'Customers',
        endpoint: 'dm/customers',
        pk: 'customer_id',
        columns: [
            { key: 'customer_id', label: 'ID',       mono: true },
            { key: 'full_name',   label: 'Name' },
            { key: 'contact',     label: 'Contact',  mono: true },
            { key: 'address',     label: 'Address' },
            { key: 'created_at',  label: 'Joined',   mono: true },
            { key: '_actions',    label: 'Actions',  nosort: true },
        ],
    },
    branches: {
        label: 'Branches',
        endpoint: 'dm/branches',
        pk: 'branch_id',
        columns: [
            { key: 'branch_id',   label: 'ID',     mono: true },
            { key: 'branch_name', label: 'Name' },
            { key: 'city',        label: 'City' },
            { key: 'region',      label: 'Region' },
            { key: 'is_active',   label: 'Status', fmt: 'active' },
            { key: 'created_at',  label: 'Created',mono: true },
            { key: '_actions',    label: 'Actions',nosort: true },
        ],
    },
};

/* ── Init ─────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', async () => {
    updateTopbarDate();
    await loadFilterOptions();
    renderTabFilters();
    await loadData();
    initSidebar();
});

document.getElementById('refreshBtn').addEventListener('click', loadData);

async function loadFilterOptions() {
    try {
        const r = await fetch(`${API}?endpoint=analytics/filters`);
        const d = await r.json();
        state.filterOptions = d;
    } catch(e) {}
}

/* ── Tab switching ────────────────────────────────────────── */
function switchTab(tab) {
    state.tab     = tab;
    state.page    = 1;
    state.search  = '';
    state.filters = {};
    state.selected.clear();
    document.getElementById('searchInput').value = '';
    document.getElementById('activeTabLabel').textContent = TABS[tab].label;
    document.querySelectorAll('.dm-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    renderTabFilters();
    renderBulkBar();
    loadData();
}

/* ── Render tab-specific filter selects ───────────────────── */
function renderTabFilters() {
    const wrap = document.getElementById('tabFilters');
    wrap.innerHTML = '';
    if (state.tab === 'transactions') {
        const fo = state.filterOptions;
        wrap.innerHTML = `
            <select class="dm-filter-select" onchange="setFilter('branch_id',this.value)">
                <option value="">All Branches</option>
                ${fo.branches.map(b=>`<option value="${b.id}">${b.name}</option>`).join('')}
            </select>
            <select class="dm-filter-select" onchange="setFilter('status',this.value)">
                <option value="">All Statuses</option>
                ${(fo.statuses||[]).map(s=>`<option value="${s}">${s}</option>`).join('')}
            </select>
        `;
    }
    if (state.tab === 'branches') {
        wrap.innerHTML = `
            <select class="dm-filter-select" onchange="setFilter('is_active',this.value)">
                <option value="">All</option>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
            </select>
        `;
    }
}

function setFilter(key, val) {
    if (val === '') delete state.filters[key];
    else state.filters[key] = val;
    state.page = 1;
    loadData();
}

function onSearch() {
    state.search = document.getElementById('searchInput').value;
    state.page   = 1;
    loadData();
}

/* ── Load data from API ───────────────────────────────────── */
async function loadData() {
    showLoading(true);
    const tab  = TABS[state.tab];
    const params = new URLSearchParams({
        page:    state.page,
        per_page:state.perPage,
        search:  state.search,
        ...(state.sortCol ? { sort: state.sortCol, dir: state.sortDir } : {}),
        ...state.filters,
    });
    try {
        const r = await fetch(`${API}?endpoint=${tab.endpoint}&${params}`);
        if (!r.ok) throw new Error('Server error');
        const d = await r.json();
        state.data  = d.rows  || [];
        state.total = d.total || 0;
        document.getElementById(`tab-count-${state.tab}`).textContent = state.total;
    } catch(e) {
        state.data  = [];
        state.total = 0;
        showToast('Failed to load data. Is the Flask server running?', 'error');
    }
    renderTable();
    renderPagination();
    showLoading(false);
}

/* ── Render table ─────────────────────────────────────────── */
function renderTable() {
    const cfg  = TABS[state.tab];
    const head = document.getElementById('dmTableHead');
    const body = document.getElementById('dmTableBody');

    head.innerHTML = `<tr>
        <th class="cb-wrap" style="width:36px;">
            <input type="checkbox" class="row-cb" id="selectAll" onchange="toggleSelectAll(this.checked)">
        </th>
        ${cfg.columns.map(c => {
            const sorted = state.sortCol === c.key;
            return `<th class="${sorted?'sorted':''}" ${c.nosort?'':`onclick="sortBy('${c.key}')"`}>
                ${c.label}
                ${c.nosort ? '' : `<i class="fa-solid ${sorted && state.sortDir==='desc' ? 'fa-sort-down' : sorted ? 'fa-sort-up' : 'fa-sort'} sort-icon"></i>`}
            </th>`;
        }).join('')}
    </tr>`;

    if (!state.data.length) {
        body.innerHTML = `<tr><td colspan="${cfg.columns.length+1}">
            <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <strong>No records found</strong>
                <p>Try adjusting your filters or search term.</p>
            </div>
        </td></tr>`;
        return;
    }

    body.innerHTML = state.data.map(row => {
        const pk  = row[cfg.pk];
        const sel = state.selected.has(pk);
        return `<tr class="${sel?'selected':''}" id="row-${pk}">
            <td class="cb-wrap"><input type="checkbox" class="row-cb" ${sel?'checked':''} onchange="toggleRow(${pk},this.checked)"></td>
            ${cfg.columns.map(c => {
                if (c.key === '_actions') return `<td>
                    <div class="action-btns">
                        <button class="icon-btn view" onclick="viewRecord(${pk})" title="View"><i class="fa-solid fa-eye"></i></button>
                        <button class="icon-btn edit" onclick="editRecord(${pk})" title="Edit"><i class="fa-solid fa-pen"></i></button>
                        <button class="icon-btn del"  onclick="confirmDelete(${pk})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </td>`;
                const val = row[c.key];
                let cell = val ?? '—';
                if (c.fmt === 'peso')   cell = val != null ? `₱${parseFloat(val).toLocaleString('en-PH',{minimumFractionDigits:2})}` : '—';
                if (c.fmt === 'status') cell = statusBadge(val);
                if (c.fmt === 'active') cell = val == 1 ? '<span class="status-badge active">Active</span>' : '<span class="status-badge inactive">Inactive</span>';
                return `<td class="${c.mono?'mono':''}">${cell}</td>`;
            }).join('')}
        </tr>`;
    }).join('');
}

function statusBadge(s) {
    const cls = s === 'OK' ? 'ok' : s === 'VOID' ? 'void' : 'pending';
    return `<span class="status-badge ${cls}">${s ?? '—'}</span>`;
}

/* ── Sorting ──────────────────────────────────────────────── */
function sortBy(col) {
    if (state.sortCol === col) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
    else { state.sortCol = col; state.sortDir = 'asc'; }
    state.page = 1;
    loadData();
}

/* ── Pagination ───────────────────────────────────────────── */
function renderPagination() {
    const total = state.total, per = state.perPage, cur = state.page;
    const pages = Math.ceil(total / per) || 1;
    const start = (cur - 1) * per + 1, end = Math.min(cur * per, total);
    document.getElementById('pageInfo').textContent = total ? `${start}–${end} of ${total} records` : 'No records';

    let btns = '';
    btns += `<button class="page-btn" ${cur===1?'disabled':''} onclick="goPage(${cur-1})"><i class="fa-solid fa-chevron-left"></i></button>`;
    const range = pageRange(cur, pages);
    range.forEach(p => {
        if (p === '…') btns += `<button class="page-btn" disabled>…</button>`;
        else btns += `<button class="page-btn ${p===cur?'active':''}" onclick="goPage(${p})">${p}</button>`;
    });
    btns += `<button class="page-btn" ${cur===pages?'disabled':''} onclick="goPage(${cur+1})"><i class="fa-solid fa-chevron-right"></i></button>`;
    document.getElementById('pageBtns').innerHTML = btns;
}

function pageRange(cur, total) {
    if (total <= 7) return Array.from({length:total},(_,i)=>i+1);
    if (cur <= 4)  return [1,2,3,4,5,'…',total];
    if (cur >= total-3) return [1,'…',total-4,total-3,total-2,total-1,total];
    return [1,'…',cur-1,cur,cur+1,'…',total];
}

function goPage(p) { state.page = p; loadData(); }

/* ── Selection ────────────────────────────────────────────── */
function toggleRow(pk, checked) {
    checked ? state.selected.add(pk) : state.selected.delete(pk);
    const row = document.getElementById(`row-${pk}`);
    if (row) row.classList.toggle('selected', checked);
    renderBulkBar();
}

function toggleSelectAll(checked) {
    const cfg = TABS[state.tab];
    state.data.forEach(r => {
        const pk = r[cfg.pk];
        checked ? state.selected.add(pk) : state.selected.delete(pk);
        const row = document.getElementById(`row-${pk}`);
        if (row) row.classList.toggle('selected', checked);
    });
    renderBulkBar();
}

function clearSelection() {
    state.selected.clear();
    document.querySelectorAll('.row-cb').forEach(c => c.checked = false);
    document.querySelectorAll('#dmTableBody tr').forEach(r => r.classList.remove('selected'));
    renderBulkBar();
}

function renderBulkBar() {
    const bar = document.getElementById('bulkBar');
    const n   = state.selected.size;
    bar.classList.toggle('show', n > 0);
    document.getElementById('bulkCount').textContent = n;
}

/* ── View record ──────────────────────────────────────────── */
function viewRecord(pk) {
    const cfg = TABS[state.tab];
    const row = state.data.find(r => r[cfg.pk] == pk);
    if (!row) return;
    const body = document.getElementById('viewModalBody');
    const entries = Object.entries(row).filter(([k]) => k !== cfg.pk);
    body.innerHTML = `<div class="detail-grid">
        <div class="detail-field"><div class="detail-key">${cfg.pk.replace(/_/g,' ')}</div><div class="detail-val mono">${pk}</div></div>
        ${entries.map(([k,v]) => `
            <div class="detail-field">
                <div class="detail-key">${k.replace(/_/g,' ')}</div>
                <div class="detail-val">${v ?? '—'}</div>
            </div>`).join('')}
    </div>`;
    document.getElementById('viewModalOverlay').classList.add('open');
}
function closeViewModal() { document.getElementById('viewModalOverlay').classList.remove('open'); }

/* ── Form modal (Add / Edit) ──────────────────────────────── */
function openAddModal() {
    state.editId = null;
    document.getElementById('formModalTitle').textContent = `Add ${TABS[state.tab].label.replace(/s$/,'')}`;
    renderForm(null);
    document.getElementById('formModalOverlay').classList.add('open');
}

function editRecord(pk) {
    const cfg = TABS[state.tab];
    const row = state.data.find(r => r[cfg.pk] == pk);
    if (!row) return;
    state.editId = pk;
    document.getElementById('formModalTitle').textContent = `Edit ${TABS[state.tab].label.replace(/s$/,'')}`;
    renderForm(row);
    document.getElementById('formModalOverlay').classList.add('open');
}

function renderForm(row) {
    const fo   = state.filterOptions;
    const body = document.getElementById('formModalBody');
    let html   = '';

    if (state.tab === 'transactions') {
        const v = row || {};
        html = `<div class="form-grid">
            <div class="form-group">
                <label class="form-label">Invoice Number</label>
                <input class="form-input" id="f_invoice_number" value="${v.invoice_number||''}" placeholder="e.g. INV-00123">
            </div>
            <div class="form-group">
                <label class="form-label">Transaction Date <span class="required">*</span></label>
                <input type="datetime-local" class="form-input" id="f_transaction_date" value="${v.transaction_date ? v.transaction_date.replace(' ','T').substring(0,16) : ''}">
                <span class="form-error" id="e_transaction_date">Required</span>
            </div>
            <div class="form-group" style="position:relative;">
                <label class="form-label">Customer <span class="required">*</span></label>
                <input class="form-input" id="f_customer_search"
                    value="${v.customer_name||''}"
                    placeholder="Type name to search…"
                    autocomplete="off"
                    oninput="customerSearch(this.value)"
                    onfocus="customerSearch(this.value)">
                <input type="hidden" id="f_customer_id" value="${v.customer_id||''}">
                <div id="customer_dropdown" style="
                    display:none; position:absolute; z-index:999; top:100%; left:0; right:0;
                    background:var(--card); border:1px solid var(--border); border-radius:8px;
                    box-shadow:0 4px 16px rgba(0,0,0,0.12); max-height:220px; overflow-y:auto;">
                </div>
                <span class="form-error" id="e_customer_id">Please select a customer</span>
            </div>
            <div class="form-group">
                <label class="form-label">Branch <span class="required">*</span></label>
                <select class="form-select" id="f_branch_id">
                    <option value="">— Select —</option>
                    ${fo.branches.map(b=>`<option value="${b.id}" ${v.branch_id==b.id?'selected':''}>${b.name}</option>`).join('')}
                </select>
                <span class="form-error" id="e_branch_id">Required</span>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method <span class="required">*</span></label>
                <select class="form-select" id="f_overall_payment_method_id">
                    <option value="">— Select —</option>
                    ${fo.payments.map(p=>`<option value="${p.id}" ${v.overall_payment_method_id==p.id?'selected':''}>${p.name}</option>`).join('')}
                </select>
                <span class="form-error" id="e_overall_payment_method_id">Required</span>
            </div>
            <div class="form-group">
                <label class="form-label">Discount Type</label>
                <select class="form-select" id="f_discount_type_id" onchange="computeTransactionTotals()">
                    <option value="">None</option>
                    ${fo.discounts.map(d=>`<option value="${d.id}" ${v.discount_type_id==d.id?'selected':''}>${d.name}</option>`).join('')}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Total Treatment (₱)</label>
                <input type="number" step="0.01" class="form-input" id="f_total_treatment" value="${v.total_treatment||''}" oninput="computeTransactionTotals()">
            </div>
            <div class="form-group">
                <label class="form-label">Total Product (₱)</label>
                <input type="number" step="0.01" class="form-input" id="f_total_product" value="${v.total_product||''}" oninput="computeTransactionTotals()">
            </div>
            <div class="form-group">
                <label class="form-label">Discount Value</label>
                <input type="number" step="0.01" class="form-input" id="f_discount_value" value="${v.discount_value||''}" oninput="computeTransactionTotals()" placeholder="Amount or %">
            </div>
            <div class="form-group">
                <label class="form-label">Final Discount (₱) <span style="font-size:11px;color:var(--ink-4);">(auto)</span></label>
                <input type="number" step="0.01" class="form-input" id="f_final_discount" value="${v.final_discount||''}" readonly style="background:var(--surface);color:var(--ink-3);">
            </div>
            <div class="form-group">
                <label class="form-label">VAT (₱)</label>
                <input type="number" step="0.01" class="form-input" id="f_vat" value="${v.vat||''}" oninput="computeTransactionTotals()" placeholder="0.00 if no VAT">
            </div>
            <div class="form-group">
                <label class="form-label">Grand Total (₱) <span class="required">*</span> <span style="font-size:11px;color:var(--ink-4);">(auto)</span></label>
                <input type="number" step="0.01" class="form-input" id="f_grand_total" value="${v.grand_total||''}" readonly style="background:var(--surface);color:var(--ink-3);">
                <span class="form-error" id="e_grand_total">Required numeric value</span>
            </div>
            <div class="form-group">
                <label class="form-label">Status <span class="required">*</span></label>
                <select class="form-select" id="f_transaction_status">
                    <option value="OK"      ${v.transaction_status==='OK'?'selected':''}>OK</option>
                    <option value="VOID"    ${v.transaction_status==='VOID'?'selected':''}>VOID</option>
                    <option value="PENDING" ${v.transaction_status==='PENDING'?'selected':''}>PENDING</option>
                </select>
            </div>
        </div>`;
    }

    else if (state.tab === 'customers') {
        const v = row || {};
        html = `<div class="form-grid">
            <div class="form-group span2">
                <label class="form-label">Full Name <span class="required">*</span></label>
                <input class="form-input" id="f_full_name" value="${v.full_name||''}" placeholder="e.g. Juan Dela Cruz">
                <span class="form-error" id="e_full_name">Required</span>
            </div>
            <div class="form-group">
                <label class="form-label">Contact</label>
                <input class="form-input" id="f_contact" value="${v.contact||''}" placeholder="+63 9XX XXX XXXX">
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <input class="form-input" id="f_address" value="${v.address||''}" placeholder="City, Province">
            </div>
        </div>`;
    }

    else if (state.tab === 'branches') {
        const v = row || {};
        html = `<div class="form-grid">
            <div class="form-group span2">
                <label class="form-label">Branch Name <span class="required">*</span></label>
                <input class="form-input" id="f_branch_name" value="${v.branch_name||''}" placeholder="e.g. SM Megamall Kiosk">
                <span class="form-error" id="e_branch_name">Required</span>
            </div>
            <div class="form-group">
                <label class="form-label">City</label>
                <input class="form-input" id="f_city" value="${v.city||''}" placeholder="Mandaluyong">
            </div>
            <div class="form-group">
                <label class="form-label">Region</label>
                <input class="form-input" id="f_region" value="${v.region||''}" placeholder="NCR">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select class="form-select" id="f_is_active">
                    <option value="1" ${v.is_active==1?'selected':''}>Active</option>
                    <option value="0" ${v.is_active==0?'selected':''}>Inactive</option>
                </select>
            </div>
        </div>`;
    }

    body.innerHTML = html;
}

function closeFormModal() {
    document.getElementById('formModalOverlay').classList.remove('open');
    state.editId = null;
}

/* ── Form validation & submission ─────────────────────────── */
function clearErrors() {
    document.querySelectorAll('.form-error.show').forEach(e => e.classList.remove('show'));
    document.querySelectorAll('.form-input.error, .form-select.error').forEach(e => e.classList.remove('error'));
}

function fieldError(id, errId) {
    const f = document.getElementById(id);
    const e = document.getElementById(errId);
    if (f) f.classList.add('error');
    if (e) e.classList.add('show');
    return false;
}

function getFormData() {
    clearErrors();
    let data = {}, valid = true;

    if (state.tab === 'transactions') {
        const dt = document.getElementById('f_transaction_date').value;
        if (!dt) return fieldError('f_transaction_date','e_transaction_date') && (valid=false);
        data.transaction_date = dt;
        data.invoice_number   = document.getElementById('f_invoice_number').value || null;
        const cid = document.getElementById('f_customer_id').value;
        if (!cid || isNaN(cid)) { fieldError('f_customer_id','e_customer_id'); valid=false; }
        else data.customer_id = parseInt(cid);
        const bid = document.getElementById('f_branch_id').value;
        if (!bid) { fieldError('f_branch_id','e_branch_id'); valid=false; }
        else data.branch_id = parseInt(bid);
        const pid = document.getElementById('f_overall_payment_method_id').value;
        if (!pid) { fieldError('f_overall_payment_method_id','e_overall_payment_method_id'); valid=false; }
        else data.overall_payment_method_id = parseInt(pid);
        const gt = document.getElementById('f_grand_total').value;
        if (!gt || isNaN(gt)) { fieldError('f_grand_total','e_grand_total'); valid=false; }
        else data.grand_total = parseFloat(gt);
        data.discount_type_id = document.getElementById('f_discount_type_id').value || null;
        data.total_treatment  = document.getElementById('f_total_treatment').value || null;
        data.total_product    = document.getElementById('f_total_product').value || null;
        data.discount_value   = document.getElementById('f_discount_value').value || null;
        data.final_discount   = document.getElementById('f_final_discount').value || null;
        data.vat              = document.getElementById('f_vat').value || null;
        data.transaction_status = document.getElementById('f_transaction_status').value;
    }

    else if (state.tab === 'customers') {
        const name = document.getElementById('f_full_name').value.trim();
        if (!name) { fieldError('f_full_name','e_full_name'); valid=false; }
        else data.full_name = name;
        data.contact = document.getElementById('f_contact').value || null;
        data.address = document.getElementById('f_address').value || null;
    }

    else if (state.tab === 'branches') {
        const name = document.getElementById('f_branch_name').value.trim();
        if (!name) { fieldError('f_branch_name','e_branch_name'); valid=false; }
        else data.branch_name = name;
        data.city      = document.getElementById('f_city').value || null;
        data.region    = document.getElementById('f_region').value || null;
        data.is_active = parseInt(document.getElementById('f_is_active').value);
    }

    return valid ? data : null;
}

async function submitForm() {
    const data = getFormData();
    if (!data) return;
    const cfg = TABS[state.tab];
    const btn = document.getElementById('formSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Saving…';

    try {
        const method = state.editId ? 'PUT' : 'POST';
        const url    = state.editId ? `${API}?endpoint=${cfg.endpoint}/${state.editId}` : `${API}?endpoint=${cfg.endpoint}`;
        const r      = await fetch(url, { method, headers: {'Content-Type':'application/json'}, body: JSON.stringify(data) });
        const res    = await r.json();
        if (!r.ok) throw new Error(res.error || 'Server error');
        showToast(state.editId ? 'Record updated successfully.' : 'Record created successfully.', 'success');
        closeFormModal();
        await loadData();
    } catch(e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
    }
}

/* ── Delete ───────────────────────────────────────────────── */
function confirmDelete(pk) {
    state.deleteId    = pk;
    state.isBulkDelete = false;
    document.getElementById('confirmMsg').textContent = `Delete record #${pk}? This cannot be undone.`;
    document.getElementById('confirmDeleteBtn').onclick = doDelete;
    document.getElementById('confirmModalOverlay').classList.add('open');
}

function confirmBulkDelete() {
    state.isBulkDelete = true;
    document.getElementById('confirmMsg').textContent = `Delete ${state.selected.size} selected record(s)? This cannot be undone.`;
    document.getElementById('confirmDeleteBtn').onclick = doBulkDelete;
    document.getElementById('confirmModalOverlay').classList.add('open');
}

function closeConfirmModal() { document.getElementById('confirmModalOverlay').classList.remove('open'); }

async function doDelete() {
    closeConfirmModal();
    const cfg = TABS[state.tab];
    try {
        const r = await fetch(`${API}?endpoint=${cfg.endpoint}/${state.deleteId}`, { method: 'DELETE' });
        if (!r.ok) throw new Error('Delete failed');
        showToast('Record deleted.', 'success');
        await loadData();
    } catch(e) {
        showToast('Delete failed: ' + e.message, 'error');
    }
}

async function doBulkDelete() {
    closeConfirmModal();
    const cfg = TABS[state.tab];
    const ids = Array.from(state.selected);
    try {
        const r = await fetch(`${API}?endpoint=${cfg.endpoint}/bulk-delete`, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ ids }),
        });
        if (!r.ok) throw new Error('Bulk delete failed');
        showToast(`${ids.length} record(s) deleted.`, 'success');
        state.selected.clear();
        await loadData();
    } catch(e) {
        showToast('Bulk delete failed: ' + e.message, 'error');
    }
}

/* ── CSV Export ───────────────────────────────────────────── */
function exportCSV() {
    const cfg    = TABS[state.tab];
    const params = new URLSearchParams({ search: state.search, ...state.filters });
    window.open(`${API}?endpoint=${cfg.endpoint}/export&${params}`, '_blank');
}

/* ════════════════════════════════════════════════════════════
   CSV IMPORT — ENHANCED WIZARD (4 steps)
   Step 1: Upload  →  Step 2: Map Columns  →  Step 3: Validate  →  Step 4: Confirm
════════════════════════════════════════════════════════════ */

/* ── Schema definitions per table tab ─────────────────────── */
const IMPORT_SCHEMA = {
    transactions: {
        title: 'Transactions',
        fields: [
            { key: 'invoice_number',           label: 'Invoice Number',    required: false, type: 'string'  },
            { key: 'transaction_date',          label: 'Transaction Date',  required: true,  type: 'date'    },
            { key: 'customer_id',               label: 'Customer ID',       required: false, type: 'int'     },
            { key: 'branch_id',                 label: 'Branch ID',         required: true,  type: 'int'     },
            { key: 'discount_type_id',          label: 'Discount Type ID',  required: false, type: 'int',    validValues: [1,2] },
            { key: 'discount_value',            label: 'Discount Value',    required: false, type: 'decimal' },
            { key: 'total_treatment',           label: 'Total Treatment',   required: false, type: 'decimal' },
            { key: 'total_product',             label: 'Total Product',     required: false, type: 'decimal' },
            { key: 'final_discount',            label: 'Final Discount',    required: false, type: 'decimal' },
            { key: 'vat',                       label: 'VAT',               required: false, type: 'decimal' },
            { key: 'grand_total',               label: 'Grand Total',       required: true,  type: 'decimal' },
            { key: 'overall_payment_method_id', label: 'Payment Method ID', required: false, type: 'int'     },
            { key: 'transaction_status',        label: 'Status',            required: false, type: 'string', validValues: ['OK','VOID','PENDING'] },
        ],
    },
    customers: {
        title: 'Customers',
        fields: [
            { key: 'full_name', label: 'Full Name', required: true,  type: 'string' },
            { key: 'contact',   label: 'Contact',   required: false, type: 'string' },
            { key: 'address',   label: 'Address',   required: false, type: 'string' },
        ],
    },
    branches: {
        title: 'Branches',
        fields: [
            { key: 'branch_name', label: 'Branch Name', required: true,  type: 'string' },
            { key: 'city',        label: 'City',         required: false, type: 'string' },
            { key: 'region',      label: 'Region',       required: false, type: 'string' },
            { key: 'is_active',   label: 'Is Active',    required: false, type: 'int', validValues: [0,1] },
        ],
    },
};

/* ── Import wizard state ──────────────────────────────────── */
const imp = {
    step:        1,          // current wizard step 1-4
    csvHeaders:  [],         // raw column headers from file (legacy csv path)
    csvRows:     [],         // all parsed raw rows (legacy csv path)
    fileName:    '',
    fileObject:  null,       // File object kept for multipart upload
    isXlsx:      false,
    previewData: null,       // response from /api/dm/dataset/preview
    mapping:     {},         // csvCol → dbField key  (or '__skip__')
    validated:   [],         // [{row, errors:[], warnings:[], mapped:{}}]
    readyRows:   [],         // rows that pass validation (after mapping)
    skipCount:   0,
    errCount:    0,
    warnCount:   0,
};

/* ── Utility: parse CSV text properly (handles quoted commas) ─ */
function parseCSVText(text) {
    const lines = [];
    const re = /("(?:[^"]|"")*"|[^,\n\r]*)(,|\r?\n|\r|$)/g;
    let row = [], match;
    while ((match = re.exec(text)) !== null) {
        let val = match[1];
        if (val.startsWith('"') && val.endsWith('"')) val = val.slice(1,-1).replace(/""/g,'"');
        row.push(val.trim());
        if (match[2] !== ',') {
            if (row.some(v => v !== '') || lines.length === 0) lines.push(row);
            row = [];
            if (match[2] === '') break;
        }
    }
    return lines;
}

/* ── Step indicator helper ────────────────────────────────── */
function setImportStep(n) {
    imp.step = n;
    for (let i = 1; i <= 4; i++) {
        const si = document.getElementById(`stepIndicator${i}`);
        const sn = document.getElementById(`stepNum${i}`);
        si.classList.remove('active','done');
        if (i < n)      { si.classList.add('done');   sn.innerHTML = '<i class="fa-solid fa-check" style="font-size:10px;"></i>'; }
        else if (i === n) si.classList.add('active');
        // restore numbers for forward steps
        if (i > n) sn.textContent = i;
        document.getElementById(`importStep${i}`).style.display = (i === n) ? '' : 'none';
    }
    // footer button visibility
    const nextBtn   = document.getElementById('importNextBtn');
    const backBtn   = document.getElementById('importBackBtn');
    const submitBtn = document.getElementById('importSubmitBtn');
    backBtn.style.display   = n > 1 && n < 4 ? '' : 'none';
    nextBtn.style.display   = n < 4 ? '' : 'none';
    submitBtn.style.display = n === 4 ? '' : 'none';
    // Steps 2+ enable nextBtn themselves after building content
    if (n === 1) nextBtn.disabled = true;
    document.getElementById('importModalTitle').textContent =
        ['', 'Import Sales Report — Upload', 'Import Sales Report — Preview',
         'Import Sales Report — Validate', 'Import Sales Report — Confirm & Import'][n];
}

/* ── Open / close ─────────────────────────────────────────── */
function openImportModal() {
    resetImportWizard();
    document.getElementById('importModalOverlay').classList.add('open');
}
function closeImportModal() {
    document.getElementById('importModalOverlay').classList.remove('open');
}
function resetImportWizard() {
    Object.assign(imp, { step:1, csvHeaders:[], csvRows:[], fileName:'', fileObject:null, isXlsx:false, previewData:null, mapping:{}, validated:[], readyRows:[], skipCount:0, errCount:0, warnCount:0 });
    document.getElementById('csvFileInput').value = '';
    setImportStep(1);
    // show column hint for current tab
    const schema = IMPORT_SCHEMA[state.tab];
    if (schema) {
        const reqKeys = schema.fields.filter(f=>f.required).map(f=>f.key);
        const optKeys = schema.fields.filter(f=>!f.required).map(f=>f.key);
        document.getElementById('importColHintText').innerHTML =
            `<span style="color:var(--danger);font-weight:600;">Required:</span> ${reqKeys.join(', ')}` +
            (optKeys.length ? `<br><span style="color:var(--ink-4);">Optional:</span> ${optKeys.join(', ')}` : '');
    }
    document.getElementById('importNextBtn').disabled = true;
}

/* ── STEP 1: file chosen ──────────────────────────────────── */
/* ── STEP 1: file chosen (xlsx or csv) ────────────────────── */
function handleSalesFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    const fname = file.name.toLowerCase();
    const isXlsx = fname.endsWith('.xlsx');
    const isCsv  = fname.endsWith('.csv');
    if (!isXlsx && !isCsv) return showToast('Only .xlsx or .csv files are accepted.', 'error');
    if (file.size > 20 * 1024 * 1024) return showToast('File is too large (max 20 MB).', 'error');

    imp.fileName   = file.name;
    imp.fileObject = file;          // keep reference for multipart upload
    imp.isXlsx     = isXlsx;

    // Show a loading state while we call /preview
    document.getElementById('importDrop').innerHTML =
        `<i class="fa-solid fa-spinner fa-spin" style="color:var(--primary);"></i>
         <p><strong>${file.name}</strong></p>
         <p style="font-size:11.5px;margin-top:4px;color:var(--ink-4);">Reading file…</p>`;
    document.getElementById('importNextBtn').disabled = true;

    // Send to backend /preview endpoint — it handles both xlsx and csv
    const fd = new FormData();
    fd.append('file', file);
    fetch(`${API}?endpoint=dm/dataset/preview`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                showToast('Preview failed: ' + data.error, 'error');
                document.getElementById('importDrop').innerHTML =
                    `<i class="fa-solid fa-triangle-exclamation" style="color:var(--danger);"></i>
                     <p style="color:var(--danger);">${data.error}</p>
                     <p style="font-size:11.5px;margin-top:4px;color:var(--primary);cursor:pointer;" onclick="document.getElementById('csvFileInput').click()">Click to choose another file</p>`;
                return;
            }
            // Store preview data — used to display sheet tabs in step 2
            imp.previewData = data;   // { sheets:[{name,columns,total_rows,preview}], total_rows, file_type }

            const totalRows = data.total_rows;
            const sheetSummary = data.sheets.length > 1
                ? `${data.sheets.length} sheets · ${totalRows.toLocaleString()} total rows`
                : `${totalRows.toLocaleString()} rows · ${data.sheets[0]?.columns?.length || 0} columns`;

            document.getElementById('importDrop').innerHTML =
                `<i class="fa-solid fa-file-circle-check" style="color:var(--success);"></i>
                 <p><strong>${file.name}</strong></p>
                 <p style="font-size:11.5px;margin-top:4px;color:var(--ink-4);">${sheetSummary}</p>
                 <p style="font-size:11.5px;margin-top:4px;color:var(--primary);cursor:pointer;" onclick="document.getElementById('csvFileInput').click()">Click to replace file</p>`;
            document.getElementById('importNextBtn').disabled = false;
        })
        .catch(err => {
            showToast('Could not reach server: ' + err.message, 'error');
            document.getElementById('importDrop').innerHTML =
                `<i class="fa-solid fa-triangle-exclamation" style="color:var(--danger);"></i>
                 <p style="color:var(--danger);">Server error. Try again.</p>`;
        });
}

/* keep old name as alias so any stray references still work */
function handleCSVFile(e) { handleSalesFile(e); }

/* ── Drag & drop wiring (set up after DOM ready) ──────────── */
document.addEventListener('DOMContentLoaded', () => {
    const drop = document.getElementById('importDrop');
    drop.addEventListener('dragover',  e => { e.preventDefault(); drop.classList.add('drag-over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
    drop.addEventListener('drop', e => {
        e.preventDefault(); drop.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (file) {
            const inp = document.getElementById('csvFileInput');
            // Create a DataTransfer to set the files property
            try {
                const dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files;
            } catch(x) {}
            handleSalesFile({ target: { files: [file] } });
        }
    });
});

/* ── STEP 2: build column mapping UI ─────────────────────── */
function buildMappingStep() {
    const schema = IMPORT_SCHEMA[state.tab];
    if (!schema) { importGoNext(); return; } // no schema, skip
    const grid = document.getElementById('colMapGrid');
    // clear old rows (keep 3 header divs)
    while (grid.children.length > 3) grid.removeChild(grid.lastChild);

    // Auto-detect mapping by normalising names
    const normalise = s => s.toLowerCase().replace(/[\s\-_]+/g,'');
    const fieldMap  = {};
    schema.fields.forEach(f => { fieldMap[normalise(f.key)] = f.key; fieldMap[normalise(f.label)] = f.key; });

    imp.csvHeaders.forEach(csvCol => {
        const norm    = normalise(csvCol);
        const autoKey = fieldMap[norm] || '__skip__';
        imp.mapping[csvCol] = autoKey;

        // CSV col label
        const divCol = document.createElement('div');
        divCol.className = 'col-map-csv';
        divCol.textContent = csvCol;

        // Arrow
        const divArr = document.createElement('div');
        divArr.className = 'col-map-arrow';
        divArr.innerHTML = '<i class="fa-solid fa-arrow-right"></i>';

        // Select
        const sel = document.createElement('select');
        sel.className = 'col-map-select' + (autoKey !== '__skip__' ? ' mapped' : '');
        sel.dataset.csvCol = csvCol;
        const skipOpt = document.createElement('option');
        skipOpt.value = '__skip__'; skipOpt.textContent = '— skip —';
        sel.appendChild(skipOpt);
        schema.fields.forEach(f => {
            const opt = document.createElement('option');
            opt.value = f.key;
            opt.textContent = `${f.label}${f.required ? ' *' : ''}`;
            sel.appendChild(opt);
        });
        sel.value = autoKey;
        sel.addEventListener('change', () => {
            imp.mapping[csvCol] = sel.value;
            sel.className = 'col-map-select' + (sel.value !== '__skip__' ? ' mapped' : '');
            checkMappingRequirements();
        });

        grid.appendChild(divCol);
        grid.appendChild(divArr);
        grid.appendChild(sel);
    });
    checkMappingRequirements();
}

function checkMappingRequirements() {
    const schema = IMPORT_SCHEMA[state.tab];
    if (!schema) return;
    const mappedDbFields = Object.values(imp.mapping);
    const missingRequired = schema.fields.filter(f => f.required && !mappedDbFields.includes(f.key));

    // highlight missing required selects
    document.querySelectorAll('.col-map-select').forEach(sel => {
        sel.classList.remove('required-missing');
    });

    const nextBtn = document.getElementById('importNextBtn');
    if (missingRequired.length) {
        nextBtn.disabled = true;
        nextBtn.title = 'Map required fields: ' + missingRequired.map(f=>f.label).join(', ');
    } else {
        nextBtn.disabled = false;
        nextBtn.title = '';
    }
}

/* ── STEP 3: validate all rows ────────────────────────────── */
function validateRows() {
    const schema = IMPORT_SCHEMA[state.tab];
    if (!schema) return;
    const fieldDef = {};
    schema.fields.forEach(f => { fieldDef[f.key] = f; });

    imp.validated = [];
    imp.skipCount = 0; imp.errCount = 0; imp.warnCount = 0;

    imp.csvRows.forEach((rawRow, idx) => {
        // build mapped object
        const mapped = {};
        imp.csvHeaders.forEach((h, i) => {
            const dbKey = imp.mapping[h];
            if (dbKey && dbKey !== '__skip__') mapped[dbKey] = (rawRow[i] ?? '').trim();
        });

        const errors = [];
        const warnings = [];

        schema.fields.forEach(f => {
            const val = mapped[f.key];
            const isEmpty = val === undefined || val === '' || val === null;

            if (f.required && isEmpty) {
                errors.push(`"${f.label}" is required`);
                return;
            }
            if (isEmpty) return; // optional empty → OK

            // type checks
            if (f.type === 'int') {
                if (!/^-?\d+$/.test(val)) errors.push(`"${f.label}" must be an integer (got "${val}")`);
                else if (f.validValues && !f.validValues.includes(parseInt(val)))
                    warnings.push(`"${f.label}" unexpected value ${val} (expected ${f.validValues.join('/')})`);
            } else if (f.type === 'decimal') {
                if (!/^-?\d+(\.\d+)?$/.test(val)) errors.push(`"${f.label}" must be a number (got "${val}")`);
                else if (parseFloat(val) < 0) warnings.push(`"${f.label}" is negative (${val})`);
            } else if (f.type === 'date') {
                const d = new Date(val);
                if (isNaN(d.getTime())) errors.push(`"${f.label}" is not a valid date (got "${val}")`);
            } else if (f.type === 'string') {
                if (f.validValues && !f.validValues.includes(val))
                    warnings.push(`"${f.label}" unrecognised value "${val}"`);
            }
        });

        // normalise types in mapped obj for final submit
        schema.fields.forEach(f => {
            if (mapped[f.key] === undefined || mapped[f.key] === '') return;
            if (f.type === 'int')     mapped[f.key] = parseInt(mapped[f.key]);
            if (f.type === 'decimal') mapped[f.key] = parseFloat(mapped[f.key]);
        });

        imp.validated.push({ rowNum: idx + 2, raw: rawRow, mapped, errors, warnings });
        if (errors.length)   imp.errCount++;
        else if (warnings.length) imp.warnCount++;
    });

    imp.readyRows = imp.validated.filter(v => v.errors.length === 0).map(v => v.mapped);
    imp.skipCount = imp.errCount;
}

function buildValidationUI() {
    validateRows();
    const total = imp.validated.length;

    // Stats bar
    document.getElementById('importStats').innerHTML = `
        <div class="import-stat"><div class="import-stat-num">${total.toLocaleString()}</div><div class="import-stat-label">Total Rows</div></div>
        <div class="import-stat"><div class="import-stat-num ok">${imp.readyRows.length.toLocaleString()}</div><div class="import-stat-label">Ready</div></div>
        <div class="import-stat"><div class="import-stat-num warn">${imp.warnCount.toLocaleString()}</div><div class="import-stat-label">Warnings</div></div>
        <div class="import-stat"><div class="import-stat-num err">${imp.errCount.toLocaleString()}</div><div class="import-stat-label">Errors (skip)</div></div>
    `;

    // Validation banner
    const vBanner = document.getElementById('importValidation');
    vBanner.className = 'import-validation';
    if (imp.errCount === 0 && imp.warnCount === 0) {
        vBanner.className += ' show ok';
        vBanner.innerHTML = `<strong><i class="fa-solid fa-circle-check"></i> All ${total} rows passed validation</strong>`;
    } else if (imp.readyRows.length === 0) {
        vBanner.className += ' show err';
        const allErrs = imp.validated.flatMap(v=>v.errors.map(e=>`Row ${v.rowNum}: ${e}`)).slice(0,5);
        vBanner.innerHTML = `<strong><i class="fa-solid fa-circle-xmark"></i> All rows have errors — cannot import</strong><ul>${allErrs.map(e=>`<li>${e}</li>`).join('')}${imp.errCount>5?`<li>…and ${imp.errCount-5} more</li>`:''}</ul>`;
    } else {
        vBanner.className += ' show warn';
        const msgs = [];
        if (imp.errCount)  msgs.push(`${imp.errCount} row(s) will be skipped due to errors`);
        if (imp.warnCount) msgs.push(`${imp.warnCount} row(s) have warnings but will be imported`);
        vBanner.innerHTML = `<strong><i class="fa-solid fa-triangle-exclamation"></i> ${imp.readyRows.length} of ${total} rows ready</strong><ul>${msgs.map(m=>`<li>${m}</li>`).join('')}</ul>`;
    }

    // Preview table (first 20 validated rows)
    const schema     = IMPORT_SCHEMA[state.tab];
    const mappedKeys = schema ? schema.fields.map(f=>f.key).filter(k => Object.values(imp.mapping).includes(k)) : [];
    const previewRows = imp.validated.slice(0, 20);
    let thead = `<tr><th>#</th><th>Status</th>${mappedKeys.map(k=>`<th>${k}</th>`).join('')}</tr>`;
    let tbody = previewRows.map(v => {
        const hasErr = v.errors.length > 0;
        const hasWarn = v.warnings.length > 0;
        const statusCell = hasErr
            ? `<td class="cell-err"><i class="fa-solid fa-xmark"></i> Skip</td>`
            : hasWarn
                ? `<td class="cell-warn"><i class="fa-solid fa-triangle-exclamation"></i> Warn</td>`
                : `<td class="cell-ok"><i class="fa-solid fa-check"></i> OK</td>`;
        const cells = mappedKeys.map(k => {
            const val = v.mapped[k] ?? '';
            const def = schema?.fields.find(f=>f.key===k);
            const isEmpty = val === '' || val === undefined;
            const cls = (def?.required && isEmpty) ? 'cell-err' : '';
            return `<td class="${cls}">${val !== undefined && val !== '' ? val : '<span style="color:var(--ink-4);">—</span>'}</td>`;
        }).join('');
        return `<tr class="${hasErr ? 'row-skip':''}"><td style="color:var(--ink-4);font-family:'DM Mono',monospace;font-size:10.5px;">${v.rowNum}</td>${statusCell}${cells}</tr>`;
    }).join('');

    document.getElementById('importPreviewLabel').textContent =
        `${imp.fileName} — ${imp.readyRows.length} of ${total} rows will be imported`;
    document.getElementById('importPreview').innerHTML =
        `<table><thead>${thead}</thead><tbody>${tbody}</tbody></table>`;

    document.getElementById('importNextBtn').disabled = imp.readyRows.length === 0;
}

/* ── STEP 4: confirm summary ──────────────────────────────── */
function buildConfirmStep() {
    const schema = IMPORT_SCHEMA[state.tab] || { title: state.tab };
    document.getElementById('importConfirmSummary').innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:44px;height:44px;border-radius:50%;background:var(--primary-light);
                display:flex;align-items:center;justify-content:center;font-size:20px;color:var(--primary);">
                <i class="fa-solid fa-database"></i>
            </div>
            <div>
                <div style="font-size:14px;font-weight:700;color:var(--ink);">Ready to import into <em>${schema.title}</em></div>
                <div style="font-size:12px;color:var(--ink-3);margin-top:2px;">This action will insert new records. Existing records are not affected.</div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px;">
            <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 12px;">
                <div style="color:var(--ink-4);font-size:10px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Source File</div>
                <div style="font-family:'DM Mono',monospace;font-size:12px;color:var(--ink-2);">${imp.fileName}</div>
            </div>
            <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 12px;">
                <div style="color:var(--ink-4);font-size:10px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Records to Insert</div>
                <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:var(--primary);">${imp.readyRows.length.toLocaleString()}</div>
            </div>
            ${imp.errCount ? `<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 12px;">
                <div style="color:var(--danger);font-size:10px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Rows Skipped (errors)</div>
                <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:var(--danger);">${imp.errCount.toLocaleString()}</div>
            </div>` : ''}
            ${imp.warnCount ? `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;">
                <div style="color:#92400e;font-size:10px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Rows with Warnings</div>
                <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:#f59e0b;">${imp.warnCount.toLocaleString()}</div>
            </div>` : ''}
        </div>
    `;
    document.getElementById('importSubmitBtn').disabled = false;
}

/* ── Wizard navigation ────────────────────────────────────── */
function importGoNext() {
    if (imp.step === 1) {
        if (!imp.previewData) return showToast('Please upload a file first.', 'error');
        buildSheetPreviewStep();
        setImportStep(2);
    } else if (imp.step === 2) {
        buildDatasetConfirmStep();
        setImportStep(3);
    } else if (imp.step === 3) {
        buildDatasetFinalStep();
        setImportStep(4);
        document.getElementById('importSubmitBtn').disabled = false;
    }
}
function importGoBack() {
    if (imp.step === 4) { setImportStep(3); }
    else if (imp.step > 1) { setImportStep(imp.step - 1); }
    document.getElementById('importNextBtn').disabled = false;
}

/* ── Final submit ─────────────────────────────────────────── */
/* ── Sheet preview step (step 2 for Sales Report) ─────────── */
function buildSheetPreviewStep() {
    const data = imp.previewData;
    if (!data) return;

    // Render into the dedicated container inside step2
    const container = document.getElementById('sheetPreviewContainer');

    // ── Header summary ────────────────────────────────────────
    let html = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <div style="font-size:13px;font-weight:600;color:var(--ink);">
            ${data.sheets.length} sheet(s) · ${data.total_rows.toLocaleString()} total rows
        </div>
        <div style="font-size:11px;color:var(--ink-4);">Click a sheet tab to preview its rows</div>
    </div>`;

    // ── Clickable sheet tabs ───────────────────────────────────
    html += `<div id="sheetTabBar" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;border-bottom:2px solid var(--border);padding-bottom:10px;">`;
    data.sheets.forEach((sh, i) => {
        const isActive = i === 0;
        html += `
        <button onclick="switchPreviewSheet(${i})" id="sheetTab_${i}"
            style="padding:5px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;
                   border:1.5px solid ${isActive ? 'var(--primary)' : 'var(--border)'};
                   background:${isActive ? 'var(--primary)' : 'var(--card)'};
                   color:${isActive ? '#fff' : 'var(--ink-2)'};
                   transition:all .15s;">
            ${sh.name}
            <span style="font-weight:400;font-size:11px;margin-left:6px;
                         opacity:${isActive ? '.85' : '.6'};">
                ${sh.total_rows.toLocaleString()} rows
            </span>
        </button>`;
    });
    html += `</div>`;

    // ── Preview table for first sheet ─────────────────────────
    html += `<div id="sheetPreviewTable"></div>`;

    container.innerHTML = html;

    // Render the first sheet's table
    renderSheetPreviewTable(0);

    document.getElementById('importNextBtn').disabled = false;
}

function switchPreviewSheet(idx) {
    const data = imp.previewData;
    if (!data) return;

    // Update tab button styles
    data.sheets.forEach((_, i) => {
        const btn = document.getElementById(`sheetTab_${i}`);
        if (!btn) return;
        const active = i === idx;
        btn.style.border      = `1.5px solid ${active ? 'var(--primary)' : 'var(--border)'}`;
        btn.style.background  = active ? 'var(--primary)' : 'var(--card)';
        btn.style.color       = active ? '#fff' : 'var(--ink-2)';
    });

    renderSheetPreviewTable(idx);
}

function renderSheetPreviewTable(idx) {
    const data = imp.previewData;
    const sh   = data.sheets[idx];
    const wrap = document.getElementById('sheetPreviewTable');
    if (!sh || !wrap) return;

    if (!sh.preview || sh.preview.length === 0) {
        wrap.innerHTML = `<div style="color:var(--ink-4);font-size:12px;padding:20px 0;text-align:center;">No preview rows available for this sheet.</div>`;
        return;
    }

    let html = `
    <div style="font-size:10px;color:var(--ink-4);text-transform:uppercase;letter-spacing:.06em;
                font-weight:600;margin-bottom:6px;">
        ${sh.name} — first ${sh.preview.length} of ${sh.total_rows.toLocaleString()} rows
    </div>
    <div class="import-preview" style="max-height:260px;overflow:auto;">
    <table><thead><tr>`;
    sh.columns.forEach(c => { html += `<th style="white-space:nowrap;">${c}</th>`; });
    html += `</tr></thead><tbody>`;
    sh.preview.forEach(row => {
        html += '<tr>';
        sh.columns.forEach(c => {
            const v = row[c] ?? '';
            html += `<td>${v !== '' ? v : '<span style="color:var(--ink-4)">—</span>'}</td>`;
        });
        html += '</tr>';
    });
    html += `</tbody></table></div>`;
    wrap.innerHTML = html;
}

/* ── Dataset confirm step (step 3) ────────────────────────── */
function buildDatasetConfirmStep() {
    const data  = imp.previewData;
    const step3 = document.getElementById('importStep3');
    const sheetRows = data.sheets.map(sh =>
        `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:12.5px;">
            <span style="color:var(--ink-2);">${sh.name}</span>
            <span style="font-family:'DM Mono',monospace;color:var(--ink);">${sh.total_rows.toLocaleString()} rows</span>
         </div>`
    ).join('');

    step3.innerHTML = `
        <div class="import-stats" style="margin-bottom:14px;">
            <div class="import-stat"><div class="import-stat-num">${data.total_rows.toLocaleString()}</div><div class="import-stat-label">Total Rows</div></div>
            <div class="import-stat"><div class="import-stat-num">${data.sheets.length}</div><div class="import-stat-label">Sheets</div></div>
        </div>
        <div class="import-validation show ok" style="margin-bottom:12px;">
            <strong><i class="fa-solid fa-circle-check"></i> File validated — ready to import into the database</strong>
        </div>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 14px;">
            <div style="font-size:10px;color:var(--ink-4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;font-weight:600;">Sheets to be imported</div>
            ${sheetRows}
        </div>`;
    document.getElementById('importNextBtn').disabled = false;
}

/* ── Build step 4 confirm for dataset import ─────────────── */
function buildDatasetFinalStep() {
    const data = imp.previewData;
    document.getElementById('importConfirmSummary').innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:44px;height:44px;border-radius:50%;background:var(--primary-light);
                display:flex;align-items:center;justify-content:center;font-size:20px;color:var(--primary);">
                <i class="fa-solid fa-database"></i>
            </div>
            <div>
                <div style="font-size:14px;font-weight:700;color:var(--ink);">Ready to import <em>${imp.fileName}</em></div>
                <div style="font-size:12px;color:var(--ink-3);margin-top:2px;">
                    ${data.total_rows.toLocaleString()} rows across ${data.sheets.length} sheet(s) will be inserted.
                    Duplicate invoices are automatically skipped.
                </div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px;">
            <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 12px;">
                <div style="color:var(--ink-4);font-size:10px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Source File</div>
                <div style="font-family:'DM Mono',monospace;font-size:12px;color:var(--ink-2);">${imp.fileName}</div>
            </div>
            <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 12px;">
                <div style="color:var(--ink-4);font-size:10px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Records to Process</div>
                <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:var(--primary);">${data.total_rows.toLocaleString()}</div>
            </div>
        </div>`;
    document.getElementById('importSubmitBtn').disabled = false;
}

async function submitImport() {
    if (!imp.fileObject) return showToast('No file selected.', 'error');
    const btn = document.getElementById('importSubmitBtn');
    btn.disabled = true;
    document.getElementById('importBackBtn').style.display = 'none';
    document.getElementById('importCancelBtn').style.display = 'none';

    const wrap = document.getElementById('importProgressWrap');
    const fill = document.getElementById('importProgressFill');
    const lbl  = document.getElementById('importProgressLabel');
    wrap.classList.add('show');
    document.getElementById('importConfirmSummary').style.opacity = '0.4';

    fill.style.width = '10%';
    lbl.textContent  = 'Uploading file and inserting records…';

    try {
        const fd = new FormData();
        fd.append('file', imp.fileObject);

        const r   = await fetch(`${API}?endpoint=dm/dataset/import`, { method: 'POST', body: fd });
        const res = await r.json();

        if (!r.ok) throw new Error(res.error || 'Server error');

        fill.style.width = '100%';
        const inserted = res.inserted ?? 0;
        const skipped  = res.skipped  ?? 0;
        lbl.textContent = `Done — ${inserted.toLocaleString()} inserted${skipped ? ', ' + skipped.toLocaleString() + ' skipped' : ''}.`;

        // Show per-sheet breakdown if available
        if (res.by_sheet && res.by_sheet.length > 1) {
            const breakdown = res.by_sheet.map(s =>
                `${s.name}: ${s.inserted} inserted${s.skipped ? ', '+s.skipped+' skipped':''}`
            ).join(' · ');
            lbl.textContent += '  |  ' + breakdown;
        }

        // Show any row-level errors below the bar
        if (res.errors && res.errors.length) {
            const errDiv = document.createElement('div');
            errDiv.style.cssText = 'margin-top:8px;font-size:11px;color:var(--danger);max-height:80px;overflow-y:auto;';
            errDiv.innerHTML = res.errors.slice(0, 10).map(e => `<div>⚠ ${e}</div>`).join('');
            wrap.appendChild(errDiv);
        }

        setTimeout(async () => {
            closeImportModal();
            if (inserted > 0) {
                showToast(`Imported ${inserted.toLocaleString()} record(s) successfully.`, 'success');
                await loadData();
            } else {
                showToast('Import finished but no records were inserted. Check errors.', 'error');
            }
        }, 1200);

    } catch(e) {
        fill.style.width = '100%';
        fill.style.background = 'var(--danger)';
        lbl.textContent = 'Import failed: ' + e.message;
        btn.disabled = false;
        console.error('Dataset import error:', e);
    }
}

/* ── Toast ────────────────────────────────────────────────── */
function showToast(msg, type='info') {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info' };
    const el    = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="fa-solid ${icons[type]||icons.info}"></i> ${msg}`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

/* ── Loading ──────────────────────────────────────────────── */
function showLoading(v) {
    document.getElementById('loadingOverlay').classList.toggle('hidden', !v);
}

/* ── Topbar date ──────────────────────────────────────────── */
function updateTopbarDate() {
    document.getElementById('topbarDate').textContent = new Date().toLocaleDateString('en-PH', {
        weekday:'short', year:'numeric', month:'short', day:'numeric'
    });
}

/* ── Sidebar toggle (reuse from other pages) ──────────────── */
function initSidebar() {
    const toggle = document.getElementById('menuToggle');
    const sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
}

/* ── Close modals on overlay click ───────────────────────── */
['formModalOverlay','viewModalOverlay','confirmModalOverlay','importModalOverlay'].forEach(id => {
    document.getElementById(id)?.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

function computeTransactionTotals() {
    const treatment   = parseFloat(document.getElementById('f_total_treatment')?.value) || 0;
    const product     = parseFloat(document.getElementById('f_total_product')?.value)   || 0;
    const discountVal = parseFloat(document.getElementById('f_discount_value')?.value)  || 0;
    const typeEl      = document.getElementById('f_discount_type_id');
    const discountType= typeEl ? typeEl.options[typeEl.selectedIndex]?.text?.toLowerCase() : '';

    const subtotal = treatment + product;

    let finalDiscount = 0;
    if (discountType === 'percent' || discountType === '%') {
        finalDiscount = subtotal * (discountVal / 100);
    } else if (discountType === 'fixed' && discountVal > 0) {
        finalDiscount = discountVal;
    }

    const afterDiscount = subtotal - finalDiscount;
    const vat           = parseFloat(document.getElementById('f_vat')?.value) || 0;
    const grandTotal    = afterDiscount + vat;

    const fdEl = document.getElementById('f_final_discount');
    const gtEl  = document.getElementById('f_grand_total');

    if (fdEl) fdEl.value = finalDiscount.toFixed(2);
    if (gtEl)  gtEl.value = grandTotal.toFixed(2);
}
/* ── Customer Autocomplete ──────────────────────────────── */
let customerSearchTimer = null;

async function customerSearch(query) {
    const dropdown = document.getElementById('customer_dropdown');
    if (!query || query.length < 1) {
        dropdown.style.display = 'none';
        return;
    }
    clearTimeout(customerSearchTimer);
    customerSearchTimer = setTimeout(async () => {
        try {
            const r = await fetch(`${API}?endpoint=dm/customers&search=${encodeURIComponent(query)}&per_page=10`);
            const d = await r.json();
            const rows = d.rows || [];
            if (!rows.length) {
                dropdown.innerHTML = `<div style="padding:9px 14px;cursor:pointer;font-size:13px;color:var(--primary);"
                    onclick="addNewCustomerFromSearch('${query.replace(/'/g,"\\'")}')"
                    onmouseenter="this.style.background='var(--primary-light)'"
                    onmouseleave="this.style.background='transparent'">
                    <i class="fa-solid fa-user-plus" style="margin-right:6px;"></i>
                    Add "<strong>${query}</strong>" as new customer
                </div>`;
            } else {
                dropdown.innerHTML = rows.map(c => `
                    <div onclick="selectCustomer(${c.customer_id}, '${c.full_name.replace(/'/g,"\\'")}');"
                        style="padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;"
                        onmouseenter="this.style.background='var(--primary-light)'"
                        onmouseleave="this.style.background='transparent'">
                        <strong>${c.full_name}</strong>
                        <span style="font-size:11px;color:var(--ink-4);margin-left:6px;">#${c.customer_id}</span>
                        ${c.contact ? `<span style="font-size:11px;color:var(--ink-4);margin-left:6px;">${c.contact}</span>` : ''}
                    </div>`).join('');
            }
            dropdown.style.display = 'block';
        } catch(e) {}
    }, 250);
}

function selectCustomer(id, name) {
    document.getElementById('f_customer_id').value = id;
    document.getElementById('f_customer_search').value = name;
    document.getElementById('customer_dropdown').style.display = 'none';
}

async function addNewCustomerFromSearch(name) {
    document.getElementById('customer_dropdown').style.display = 'none';
    try {
        const r = await fetch(`${API}?endpoint=dm/customers`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ full_name: name })
        });
        const d = await r.json();
        if (d.id) {
            selectCustomer(d.id, name);
            showToast(`Customer "${name}" added successfully`, 'success');
        } else {
            showToast('Failed to add customer: ' + (d.error || 'Unknown error'), 'error');
        }
    } catch(e) {
        showToast('Error adding customer', 'error');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('#customer_dropdown') && e.target.id !== 'f_customer_search') {
        const dd = document.getElementById('customer_dropdown');
        if (dd) dd.style.display = 'none';
    }
});
</script>
</body>
</html>