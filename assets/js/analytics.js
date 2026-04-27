'use strict';

const API_BASE   = '../backend/api_proxy.php';
const ANALYTICS_EP = '../backend/api_proxy.php?endpoint=analytics';
const FILTERS_EP   = '../backend/api_proxy.php?endpoint=analytics/filters';
const CSV_EP       = '../backend/api_proxy.php?endpoint=analytics/export/csv';
const CURRENCY   = '₱';

// ── Palette (matches dashboard CSS vars) ──────────────────────
const PAL = [
    '#0f766e','#14b8a6','#f59e0b','#7c3aed',
    '#0284c7','#dc2626','#16a34a','#d97706',
    '#ec4899','#6366f1','#84cc16','#f97316',
];
const DONUT_PAL = [...PAL];

// ── Chart instances ───────────────────────────────────────────
let branchChart   = null;
let trendChart    = null;

// ── Global data cache ─────────────────────────────────────────
let _analyticsData = null;

// ── Current filter state ──────────────────────────────────────
let _filters = {
    preset:      'monthly',
    date_from:   '',
    date_to:     '',
    branch_id:   'all',
    payment_id:  'all',
    discount_id: 'all',
    status:      'OK',
};

// ── Utilities ─────────────────────────────────────────────────
const fmt    = n => CURRENCY + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const fmtK   = n => {
    if (n >= 1_000_000) return CURRENCY + (n / 1_000_000).toFixed(2) + 'M';
    if (n >= 1_000)     return CURRENCY + (n / 1_000).toFixed(1) + 'K';
    return fmt(n);
};
const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
const showEl  = id => { const el = document.getElementById(id); if (el) el.classList.remove('hidden'); };
const hideEl  = id => { const el = document.getElementById(id); if (el) el.classList.add('hidden'); };

// ── Topbar date ───────────────────────────────────────────────
function setTopbarDate() {
    const el = document.getElementById('topbarDate');
    if (!el) return;
    el.textContent = new Date().toLocaleDateString('en-PH', {
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
    });
}

// ── Build query string from filters ──────────────────────────
function buildQueryString(extra = {}) {
    const p = { ..._filters, ...extra };
    const qs = new URLSearchParams();
    Object.entries(p).forEach(([k, v]) => { if (v !== '' && v !== null) qs.set(k, v); });
    return qs.toString();
}

// ── Fetch analytics data ──────────────────────────────────────
async function fetchAnalytics() {
    showOverlay();
    try {
        const qs  = buildQueryString();
        const res = await fetch(`${ANALYTICS_EP}&${qs}`);
        if (!res.ok) throw new Error('Network error');
        const data = await res.json();
        _analyticsData = data;
        renderAll(data);
    } catch (e) {
        console.error('Analytics fetch error:', e);
        showToast('Failed to load analytics data. Check your connection.', 'error');
    } finally {
        hideOverlay();
    }
}

// ── Fetch filter options and populate selects ─────────────────
async function fetchFilters() {
    try {
        const res  = await fetch(FILTERS_EP);
        const data = await res.json();
        populateSelect('filterBranch',   data.branches,  'id', 'name');
        populateSelect('filterPayment',  data.payments,  'id', 'name');
        populateSelect('filterDiscount', data.discounts, 'id', 'name');
        populateStatusButtons(data.statuses);
    } catch (e) {
        console.error('Filter fetch error:', e);
    }
}

function populateSelect(id, items, valKey, labelKey) {
    const el = document.getElementById(id);
    if (!el) return;
    items.forEach(item => {
        const opt = document.createElement('option');
        opt.value       = item[valKey];
        opt.textContent = item[labelKey];
        el.appendChild(opt);
    });
}

function populateStatusButtons(statuses) {
    const wrap = document.getElementById('statusBtnRow');
    if (!wrap) return;
    const all = ['all', ...statuses];
    all.forEach(s => {
        const btn = document.createElement('button');
        btn.className   = 'preset-btn' + (s === _filters.status ? ' active' : '');
        btn.textContent = s === 'all' ? 'All' : s;
        btn.dataset.status = s;
        btn.addEventListener('click', () => {
            wrap.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _filters.status = s === 'all' ? 'all' : s;
        });
        wrap.appendChild(btn);
    });
}

// ── Render everything ─────────────────────────────────────────
function renderAll(data) {
    renderSummary(data.summary);
    renderBranchChart(data.branch_revenue);
    renderHeatmap(data.heatmap);
    renderDiscountAnalysis(data.discount_analysis);
    renderTopCustomers(data.top_customers);
    renderDailyTrend(data.daily_trend);
    updateDateRangeLabel(data.date_range);
}

// ── Summary Metric Cards ──────────────────────────────────────
function renderSummary(s) {
    setText('sumRevenue', fmtK(s.total_revenue));
    setText('sumTx',      s.total_transactions.toLocaleString('en-PH'));
    setText('sumAOV',     fmtK(s.avg_order_value));
    setText('sumDiscount',fmtK(s.total_discounts_given));
    setText('sumVAT',     fmtK(s.total_vat));
}

// ── Branch Revenue Bar Chart ──────────────────────────────────
let _branchMetric = 'revenue';

function renderBranchChart(branches) {
    const canvas = document.getElementById('branchChart');
    if (!canvas) return;
    if (branchChart) branchChart.destroy();

    const sorted = [...branches].sort((a, b) => b[_branchMetric] - a[_branchMetric]).slice(0, 12);
    const labels = sorted.map(b => b.name.length > 20 ? b.name.slice(0, 20) + '…' : b.name);
    const values = sorted.map(b => _branchMetric === 'tx_count' ? b[_branchMetric] : b[_branchMetric]);

    const colors = sorted.map((_, i) => PAL[i % PAL.length]);

    branchChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: _branchMetric === 'revenue' ? 'Revenue' : _branchMetric === 'tx_count' ? 'Transactions' : 'Avg Ticket',
                data: values,
                backgroundColor: colors.map(c => c + 'cc'),
                borderColor: colors,
                borderWidth: 1.5,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => _branchMetric === 'tx_count'
                            ? ctx.raw.toLocaleString() + ' txns'
                            : fmtK(ctx.raw),
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        font: { family: 'DM Mono', size: 11 },
                        color: '#9ca3af',
                        callback: v => _branchMetric === 'tx_count' ? v : fmtK(v),
                    }
                },
                y: {
                    grid: { display: false },
                    ticks: { font: { family: 'DM Sans', size: 11 }, color: '#374151' }
                }
            }
        }
    });
}

// ── Daily Trend Mini Sparkline (under branch chart) ───────────
function renderDailyTrend(trend) {
    const canvas = document.getElementById('dailyTrendChart');
    if (!canvas || !trend.length) return;
    if (trendChart) trendChart.destroy();

    const labels   = trend.map(d => {
        const dt = new Date(d.date);
        return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
    });
    const revenues = trend.map(d => d.revenue);
    const txCounts = trend.map(d => d.tx_count);

    const ctx = canvas.getContext('2d');
    const grd = ctx.createLinearGradient(0, 0, 0, 200);
    grd.addColorStop(0, 'rgba(20,184,166,0.25)');
    grd.addColorStop(1, 'rgba(20,184,166,0.00)');

    trendChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: revenues,
                    borderColor: '#0f766e',
                    backgroundColor: grd,
                    tension: 0.4,
                    fill: true,
                    pointRadius: revenues.length > 20 ? 0 : 3,
                    pointHoverRadius: 5,
                    borderWidth: 2,
                    yAxisID: 'y',
                },
                {
                    label: 'Transactions',
                    data: txCounts,
                    borderColor: '#f59e0b',
                    backgroundColor: 'transparent',
                    tension: 0.4,
                    fill: false,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 1.5,
                    borderDash: [4, 3],
                    yAxisID: 'y2',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    labels: { font: { family: 'DM Sans', size: 11 }, color: '#6b7280', boxWidth: 10, boxHeight: 2 }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.datasetIndex === 0
                            ? 'Revenue: ' + fmtK(ctx.raw)
                            : 'Txns: ' + ctx.raw.toLocaleString(),
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: '#f8fafc' },
                    ticks: { font: { family: 'DM Mono', size: 10 }, color: '#9ca3af', maxTicksLimit: 8 }
                },
                y: {
                    position: 'left',
                    grid: { color: '#f1f5f9' },
                    ticks: { font: { family: 'DM Mono', size: 10 }, color: '#9ca3af', callback: v => fmtK(v) }
                },
                y2: {
                    position: 'right',
                    grid: { display: false },
                    ticks: { font: { family: 'DM Mono', size: 10 }, color: '#d97706' }
                }
            }
        }
    });
}

// ── Heatmap: day-of-week × hour ───────────────────────────────
function renderHeatmap(heatmap) {
    const container = document.getElementById('heatmapContainer');
    if (!container) return;
    container.innerHTML = '';

    const DAYS  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const HOURS = Array.from({ length: 24 }, (_, i) => {
        const h = i % 12 || 12;
        return h + (i < 12 ? 'a' : 'p');
    });

    // Build matrix [dow][hr]
    const matrix = Array.from({ length: 7 }, () => Array(24).fill(0));
    let maxVal = 0;
    heatmap.forEach(cell => {
        const v = cell.tx_count;
        matrix[cell.dow][cell.hr] = v;
        if (v > maxVal) maxVal = v;
    });

    const getColor = (v) => {
        if (maxVal === 0 || v === 0) return '#f0fdfa';
        const t   = v / maxVal;
        const r   = Math.round(15  + t * (20  - 15));
        const g   = Math.round(118 + t * (85  - 118));
        const b   = Math.round(110 + t * (78  - 110));
        // teal gradient: #f0fdfa → #0f766e
        const rs = Math.round(240 - t * (240 - 15));
        const gs = Math.round(253 - t * (253 - 118));
        const bs = Math.round(250 - t * (250 - 110));
        return `rgb(${rs},${gs},${bs})`;
    };

    // Grid: col 0 = day labels, cols 1-24 = hour cells
    const grid = document.createElement('div');
    grid.style.cssText = `
        display: grid;
        grid-template-columns: 36px repeat(24, 1fr);
        gap: 3px;
        min-width: 600px;
    `;

    // Header row: empty + hour labels
    const emptyTh = document.createElement('div');
    grid.appendChild(emptyTh);
    HOURS.forEach(h => {
        const th = document.createElement('div');
        th.className   = 'heatmap-label-row';
        th.textContent = h;
        th.style.fontSize = '9px';
        grid.appendChild(th);
    });

    // Data rows
    DAYS.forEach((day, dow) => {
        const dayLabel = document.createElement('div');
        dayLabel.className   = 'heatmap-label-col';
        dayLabel.textContent = day;
        grid.appendChild(dayLabel);

        for (let hr = 0; hr < 24; hr++) {
            const val  = matrix[dow][hr];
            const cell = document.createElement('div');
            cell.className       = 'heatmap-cell';
            cell.style.height    = '22px';
            cell.style.background = getColor(val);
            cell.title = `${day} ${HOURS[hr]}: ${val} transactions`;
            cell.setAttribute('data-tippy', `${day} ${HOURS[hr]} — ${val} txns`);
            // Simple tooltip via title (works without extra lib)
            grid.appendChild(cell);
        }
    });

    container.appendChild(grid);

    // Legend
    const legend = document.createElement('div');
    legend.className = 'heatmap-legend';
    legend.innerHTML = `
        <span>Low</span>
        <div class="heatmap-legend-bar"></div>
        <span>High (${maxVal} txns)</span>
    `;
    container.appendChild(legend);
}

// ── Discount Analysis ─────────────────────────────────────────
function renderDiscountAnalysis(discounts) {
    const wrap = document.getElementById('discountCards');
    if (!wrap) return;
    wrap.innerHTML = '';

    if (!discounts.length) {
        wrap.innerHTML = '<p style="color:var(--ink-4);font-size:12px;">No discount data available.</p>';
        return;
    }

    const maxRev = Math.max(...discounts.map(d => d.gross_revenue));

    discounts.forEach(d => {
        const pct = maxRev > 0 ? (d.gross_revenue / maxRev * 100) : 0;
        const isNoDiscount = d.discount_type === 'No Discount' || d.discount_type === 'fixed';

        const row = document.createElement('div');
        row.className = 'discount-row';
        row.innerHTML = `
            <div>
                <div class="discount-type-label">${escHtml(d.discount_type)}</div>
                <div style="font-size:10.5px;color:var(--ink-4);font-family:'DM Mono',monospace;margin-top:2px;">${d.tx_count.toLocaleString()} txns</div>
            </div>
            <div>
                <div class="discount-bar-track">
                    <div class="discount-bar-fill${isNoDiscount ? ' nodiscount' : ''}" style="width:${pct.toFixed(1)}%"></div>
                </div>
                <div style="font-size:10px;color:var(--ink-4);margin-top:3px;">
                    Avg discount: <span style="font-family:'DM Mono',monospace;">${fmtK(d.avg_discount)}</span>
                </div>
            </div>
            <div class="discount-stats">
                <div style="text-align:right;">
                    <div style="font-size:12.5px;font-weight:700;color:var(--ink-2);">${fmtK(d.gross_revenue)}</div>
                    <div style="font-size:10px;color:var(--ink-4);">Avg ticket: ${fmtK(d.avg_ticket)}</div>
                </div>
            </div>
        `;
        wrap.appendChild(row);
    });
}

// ── Top 10 Customers Table ────────────────────────────────────
function renderTopCustomers(customers) {
    const tbody = document.querySelector('#topCustomersTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!customers.length) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--ink-4);padding:24px;">No customer data for this filter.</td></tr>`;
        return;
    }

    customers.forEach((c, i) => {
        const badgeClass = i === 0 ? 'gold' : i === 1 ? 'silver' : i === 2 ? 'bronze' : 'plain';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><span class="rank-badge ${badgeClass}">${c.rank}</span></td>
            <td style="font-weight:600;color:var(--ink);">${escHtml(c.name)}</td>
            <td><span class="tag-tx"><i class="fa-solid fa-receipt" style="font-size:9px;"></i> ${c.tx_count}</span></td>
            <td class="mono" style="color:var(--primary);font-weight:700;">${fmtK(c.total_spent)}</td>
            <td class="mono">${fmtK(c.avg_ticket)}</td>
            <td class="mono" style="color:var(--ink-4);">${c.last_purchase}</td>
        `;
        tbody.appendChild(tr);
    });
}

// ── Date range label update ───────────────────────────────────
function updateDateRangeLabel(range) {
    const el = document.getElementById('dateRangeLabel');
    if (!el) return;
    const fmt = d => new Date(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    el.textContent = `${fmt(range.from)} — ${fmt(range.to)}`;
}

// ── Preset date buttons ───────────────────────────────────────
function initPresetButtons() {
    const row = document.getElementById('presetBtnRow');
    if (!row) return;

    row.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            row.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const preset = btn.dataset.preset;
            _filters.preset = preset;

            const customWrap = document.getElementById('customRangeWrap');
            if (customWrap) {
                customWrap.classList.toggle('visible', preset === 'custom');
            }
        });
    });
}

// ── Apply filter button ───────────────────────────────────────
function initApplyFilter() {
    const btn = document.getElementById('applyFilterBtn');
    if (!btn) return;
    btn.addEventListener('click', () => {
        _filters.branch_id   = document.getElementById('filterBranch')?.value   || 'all';
        _filters.payment_id  = document.getElementById('filterPayment')?.value  || 'all';
        _filters.discount_id = document.getElementById('filterDiscount')?.value || 'all';

        if (_filters.preset === 'custom') {
            _filters.date_from = document.getElementById('dateFrom')?.value || '';
            _filters.date_to   = document.getElementById('dateTo')?.value   || '';
        }

        fetchAnalytics();
    });
}

// ── Reset filter ──────────────────────────────────────────────
function initResetFilter() {
    const btn = document.getElementById('resetFilterBtn');
    if (!btn) return;
    btn.addEventListener('click', () => {
        _filters = {
            preset: 'monthly', date_from: '', date_to: '',
            branch_id: 'all', payment_id: 'all', discount_id: 'all', status: 'OK',
        };
        document.getElementById('filterBranch')  && (document.getElementById('filterBranch').value   = 'all');
        document.getElementById('filterPayment') && (document.getElementById('filterPayment').value  = 'all');
        document.getElementById('filterDiscount')&& (document.getElementById('filterDiscount').value = 'all');

        document.getElementById('presetBtnRow')?.querySelectorAll('.preset-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.preset === 'monthly');
        });
        document.getElementById('statusBtnRow')?.querySelectorAll('.preset-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.status === 'OK');
        });
        document.getElementById('customRangeWrap')?.classList.remove('visible');
        fetchAnalytics();
    });
}

// ── Branch metric toggle buttons ──────────────────────────────
function initBranchMetricToggle() {
    const wrap = document.getElementById('branchMetricToggle');
    if (!wrap) return;
    wrap.querySelectorAll('.cmt-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            wrap.querySelectorAll('.cmt-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _branchMetric = btn.dataset.metric;
            if (_analyticsData) renderBranchChart(_analyticsData.branch_revenue);
        });
    });
}

// ── CSV Export ────────────────────────────────────────────────
function initExportCSV() {
    const btn = document.getElementById('exportCsvBtn');
    if (!btn) return;
    btn.addEventListener('click', () => {
        const qs  = buildQueryString();
        const url = `${CSV_EP}&${qs}`;
        const a   = document.createElement('a');
        a.href     = url;
        a.download = '';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        showToast('CSV export started…', 'success');
    });
}

// ── PDF Export (client-side, print-based) ────────────────────
function initExportPDF() {
    const btn = document.getElementById('exportPdfBtn');
    if (!btn) return;
    btn.addEventListener('click', () => {
        showToast('Preparing PDF…', 'info');
        setTimeout(() => window.print(), 400);
    });
}

// ── Loading overlay ───────────────────────────────────────────
function showOverlay() {
    const el = document.getElementById('analyticsLoadingOverlay');
    if (el) el.classList.remove('hidden');
}
function hideOverlay() {
    const el = document.getElementById('analyticsLoadingOverlay');
    if (el) el.classList.add('hidden');
}

// ── Toast notification ────────────────────────────────────────
function showToast(msg, type = 'info') {
    const id    = 'analyticsToast';
    let   toast = document.getElementById(id);
    if (!toast) {
        toast = document.createElement('div');
        toast.id = id;
        toast.style.cssText = `
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            background: var(--card); border: 1px solid var(--border);
            border-radius: 10px; padding: 12px 18px; font-size: 13px;
            font-family: 'DM Sans',sans-serif; font-weight: 500;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
            display: flex; align-items: center; gap: 10px;
            transition: opacity 0.3s;
        `;
        document.body.appendChild(toast);
    }
    const icons = { success: '✅', error: '❌', info: 'ℹ️' };
    toast.innerHTML = `<span>${icons[type] || 'ℹ️'}</span><span>${escHtml(msg)}</span>`;
    toast.style.opacity = '1';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 3200);
}

// ── Refresh button ────────────────────────────────────────────
function initRefreshBtn() {
    const btn = document.getElementById('refreshBtn');
    if (btn) btn.addEventListener('click', fetchAnalytics);
}

// ── Escape HTML ───────────────────────────────────────────────
function escHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    setTopbarDate();
    initPresetButtons();
    initBranchMetricToggle();
    initApplyFilter();
    initResetFilter();
    initExportCSV();
    initExportPDF();
    initRefreshBtn();

    await fetchFilters();
    await fetchAnalytics();
});