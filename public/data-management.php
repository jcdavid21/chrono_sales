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
        .import-preview {
            background: var(--bg); border-radius: 8px; overflow: hidden;
            border: 1px solid var(--border); max-height: 260px; overflow-y: auto;
        }
        .import-preview table { width: 100%; font-size: 11.5px; border-collapse: collapse; }
        .import-preview th { padding: 7px 10px; background: #f1f5f9; color: var(--ink-4); font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em; border-bottom: 1px solid var(--border); }
        .import-preview td { padding: 7px 10px; color: var(--ink-2); border-bottom: 1px solid #f8fafc; }
        .import-preview tr:last-child td { border-bottom: none; }

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
                        <i class="fa-solid fa-file-arrow-up"></i> Import CSV
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

<!-- Import Modal -->
<div class="modal-overlay" id="importModalOverlay">
    <div class="modal wide">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-file-csv"></i> Import CSV</div>
            <button class="modal-close" onclick="closeImportModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="import-drop" id="importDrop" onclick="document.getElementById('csvFileInput').click()">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <p>Click or drag & drop your <strong>CSV file</strong> here</p>
                <p style="font-size:11.5px;margin-top:6px;color:var(--ink-4);">First row must be column headers matching the table fields</p>
            </div>
            <input type="file" id="csvFileInput" accept=".csv" style="display:none" onchange="handleCSVFile(event)">
            <div id="importPreviewWrap" style="display:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:12px;font-weight:600;color:var(--ink-2);" id="importPreviewLabel"></span>
                    <button class="btn-secondary" style="padding:4px 10px;font-size:11.5px;" onclick="clearImport()">
                        <i class="fa-solid fa-xmark"></i> Clear
                    </button>
                </div>
                <div class="import-preview" id="importPreview"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeImportModal()">Cancel</button>
            <button class="btn-primary" id="importSubmitBtn" disabled onclick="submitImport()">
                <i class="fa-solid fa-file-import"></i> Import Records
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

/* ── CSV Import ───────────────────────────────────────────── */
function openImportModal() {
    clearImport();
    document.getElementById('importModalOverlay').classList.add('open');
}
function closeImportModal() { document.getElementById('importModalOverlay').classList.remove('open'); }

function handleCSVFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = ev => parseCSVPreview(ev.target.result, file.name);
    reader.readAsText(file);
}

function parseCSVPreview(text, name) {
    const lines  = text.split('\n').filter(l => l.trim());
    if (lines.length < 2) return showToast('CSV must have at least a header row and one data row.', 'error');
    const headers = lines[0].split(',').map(h => h.trim().replace(/^"|"$/g,''));
    const rows    = lines.slice(1, 11).map(l => l.split(',').map(v => v.trim().replace(/^"|"$/g,'')));
    state.importRows = lines.slice(1).map(l => {
        const vals = l.split(',').map(v => v.trim().replace(/^"|"$/g,''));
        const obj  = {};
        headers.forEach((h,i) => { obj[h] = vals[i] ?? ''; });
        return obj;
    });
    document.getElementById('importPreviewLabel').textContent = `${name} — ${state.importRows.length} row(s) to import`;
    document.getElementById('importPreview').innerHTML = `<table>
        <thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead>
        <tbody>${rows.map(r=>`<tr>${r.map(v=>`<td>${v}</td>`).join('')}</tr>`).join('')}</tbody>
    </table>`;
    document.getElementById('importPreviewWrap').style.display = 'block';
    document.getElementById('importSubmitBtn').disabled = false;
}

function clearImport() {
    state.importRows = [];
    document.getElementById('csvFileInput').value = '';
    document.getElementById('importPreviewWrap').style.display = 'none';
    document.getElementById('importSubmitBtn').disabled = true;
}

async function submitImport() {
    if (!state.importRows.length) return;
    const cfg = TABS[state.tab];
    const btn = document.getElementById('importSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Importing…';
    try {
        const r = await fetch(`${API}?endpoint=${cfg.endpoint}/import`, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ rows: state.importRows }),
        });
        const res = await r.json();
        if (!r.ok) throw new Error(res.error || 'Import failed');
        showToast(`Imported ${res.inserted} record(s) successfully.`, 'success');
        closeImportModal();
        await loadData();
    } catch(e) {
        showToast('Import error: ' + e.message, 'error');
    } finally {
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-file-import"></i> Import Records';
    }
}

// Drag & drop for import
const importDrop = document.getElementById('importDrop');
importDrop.addEventListener('dragover',  e => { e.preventDefault(); importDrop.classList.add('drag-over'); });
importDrop.addEventListener('dragleave', () => importDrop.classList.remove('drag-over'));
importDrop.addEventListener('drop', e => {
    e.preventDefault(); importDrop.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) { const r = new FileReader(); r.onload = ev => parseCSVPreview(ev.target.result, file.name); r.readAsText(file); }
});

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