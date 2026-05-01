/**
 * reports.js  —  Reports Module for ChronoSales
 * Handles: Revenue, VAT, Discount, Comparison, Data Integrity, Scheduled Reports
 * PDF export uses html2canvas + jsPDF (loaded in report.php)
 */
;(function () {
  'use strict';

  // ── API base ──────────────────────────────────────────────────────────────
  const API = (endpoint, params = {}) => {
    const qs = new URLSearchParams({ endpoint, ...params }).toString();
    return fetch(`../backend/api_proxy.php?${qs}`).then(r => r.json());
  };
  const API_POST = (endpoint, body) =>
    fetch(`../backend/api_proxy.php?endpoint=${endpoint}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(r => r.json());
  const API_PUT = (endpoint, body) =>
    fetch(`../backend/api_proxy.php?endpoint=${endpoint}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(r => r.json());
  const API_DELETE = (endpoint) =>
    fetch(`../backend/api_proxy.php?endpoint=${endpoint}`, { method: 'DELETE' }).then(r => r.json());

  // ── Formatters ────────────────────────────────────────────────────────────
  const fmt  = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtN = n => Number(n || 0).toLocaleString('en-PH');
  const fmtPct = n => (n === null || n === undefined ? '—' : (n >= 0 ? '+' : '') + Number(n).toFixed(1) + '%');
  const fmtDate = s => s ? new Date(s).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' }) : '—';
  const fmtMonth = s => {  // "2026-04" → "Apr 2026"
    if (!s) return '—';
    const [y, m] = s.split('-');
    return new Date(y, parseInt(m, 10) - 1, 1).toLocaleDateString('en-PH', { year:'numeric', month:'short' });
  };

  // ── State ─────────────────────────────────────────────────────────────────
  let state = {
    preset: 'monthly',
    dateFrom: '',
    dateTo: '',
    branch: 'all',
    payment: 'all',
    activeReport: 'revenue',
    revGroupBy: 'monthly',
    charts: {},      // Chart.js instances keyed by canvas id
    reportData: {},  // last fetched data per report type
    schedules: [],
  };

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const $  = id => document.getElementById(id);
  const $$ = sel => document.querySelectorAll(sel);

  // ── Toast ─────────────────────────────────────────────────────────────────
  function toast(msg, type = 'info') {
    const c = $('toastContainer');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fa-solid fa-${type === 'success' ? 'circle-check' : type === 'error' ? 'circle-xmark' : 'circle-info'}"></i> ${msg}`;
    c.appendChild(t);
    setTimeout(() => t.classList.add('show'), 10);
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3500);
  }

  // ── Loading overlay ───────────────────────────────────────────────────────
    let overlay, showLoading, hideLoading;

  // ── Topbar date ───────────────────────────────────────────────────────────
  (function setTopbarDate() {
    const el = $('topbarDate');
    if (el) el.textContent = new Date().toLocaleDateString('en-PH', { weekday:'short', year:'numeric', month:'short', day:'numeric' });
  })();

  // ── Chart helpers ─────────────────────────────────────────────────────────
  function destroyChart(id) {
    if (state.charts[id]) { state.charts[id].destroy(); delete state.charts[id]; }
  }
  function mkChart(id, type, data, options = {}) {
    destroyChart(id);
    const ctx = $(id);
    if (!ctx) return;
    const style = getComputedStyle(document.documentElement);
    const primary = style.getPropertyValue('--primary-mid').trim() || '#0f766e';
    const border  = style.getPropertyValue('--border').trim()      || '#e5e7eb';
    const ink4    = style.getPropertyValue('--ink-4').trim()       || '#9ca3af';
    const defaults = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: ink4, font: { size: 11, family: "'DM Sans', sans-serif" } } },
        tooltip: { mode: 'index', intersect: false },
      },
      scales: type !== 'pie' && type !== 'doughnut' ? {
        x: { grid: { color: border }, ticks: { color: ink4, font: { size: 10 } } },
        y: { grid: { color: border }, ticks: { color: ink4, font: { size: 10 },
             callback: v => '₱' + Number(v).toLocaleString('en-PH', { notation: 'compact' }) } },
      } : undefined,
    };
    state.charts[id] = new Chart(ctx, {
      type,
      data,
      options: Chart.helpers.merge(defaults, options),
    });
  }

  // ── CSS var colour palette ────────────────────────────────────────────────
  const PALETTE = ['#0f766e','#6366f1','#f59e0b','#ef4444','#10b981','#8b5cf6','#f97316','#06b6d4'];

  // ══════════════════════════════════════════════════════════════════════════
  //  INITIALISE
  // ══════════════════════════════════════════════════════════════════════════
  async function init() {
    overlay     = $('reportsLoadingOverlay');
    showLoading = () => overlay && overlay.classList.add('active');
    hideLoading = () => overlay && overlay.classList.remove('active');
    await loadFilters();
    bindPresetButtons();
    bindReportTabs();
    bindRevGroupTabs();
    bindActionButtons();
    bindScheduleModal();
    updateDateRangeLabel();
    generateReport();
}

  // ── Filters ───────────────────────────────────────────────────────────────
  async function loadFilters() {
    try {
      const data = await API('reports/filters');
      if (!data.branches || !data.payment_methods) return;
      // Branches
      const branchSel  = $('filterBranch');
      const schBranchSel = $('schBranch');
      data.branches.forEach(b => {
        [branchSel, schBranchSel].forEach(sel => {
          const o = document.createElement('option');
          o.value = b.id; o.textContent = b.name;
          sel.appendChild(o);
        });
      });
      // Payments
      const pmSel = $('filterPayment');
      data.payment_methods.forEach(p => {
        const o = document.createElement('option');
        o.value = p.id; o.textContent = p.name;
        pmSel.appendChild(o);
      });
    } catch (e) { console.error('Filter load failed', e); }
  }

  // ── Date presets ──────────────────────────────────────────────────────────
  function bindPresetButtons() {
    $$('#presetBtnRow .preset-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        $$('#presetBtnRow .preset-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        state.preset = btn.dataset.preset;
        const wrap = $('customRangeWrap');
        wrap.style.display = state.preset === 'custom' ? 'flex' : 'none';
        if (state.preset !== 'custom') {
          computePresetDates();
          updateDateRangeLabel();
        }
      });
    });
    $('dateFrom').addEventListener('change', e => { state.dateFrom = e.target.value; updateDateRangeLabel(); });
    $('dateTo').addEventListener('change', e => { state.dateTo = e.target.value; updateDateRangeLabel(); });
    $('resetFilterBtn').addEventListener('click', resetFilters);
    $('applyFilterBtn').addEventListener('click', generateReport);
    $('refreshBtn').addEventListener('click', generateReport);
  }

  function computePresetDates() {
    const today = new Date();
    const fmt = d => d.toISOString().split('T')[0];
    if (state.preset === 'daily') {
      state.dateFrom = state.dateTo = fmt(today);
    } else if (state.preset === 'weekly') {
      const start = new Date(today); start.setDate(today.getDate() - today.getDay() + 1);
      state.dateFrom = fmt(start); state.dateTo = fmt(today);
    } else if (state.preset === 'monthly') {
      state.dateFrom = fmt(new Date(today.getFullYear(), today.getMonth(), 1));
      state.dateTo   = fmt(today);
    } else if (state.preset === 'quarterly') {
      const q = Math.floor(today.getMonth() / 3);
      state.dateFrom = fmt(new Date(today.getFullYear(), q * 3, 1));
      state.dateTo   = fmt(today);
    } else if (state.preset === 'annual') {
      state.dateFrom = fmt(new Date(today.getFullYear(), 0, 1));
      state.dateTo   = fmt(today);
    }
  }

  function resetFilters() {
    $$('#presetBtnRow .preset-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.preset-btn[data-preset="monthly"]').classList.add('active');
    state.preset = 'monthly';
    $('customRangeWrap').style.display = 'none';
    $('filterBranch').value  = 'all'; state.branch = 'all';
    $('filterPayment').value = 'all'; state.payment = 'all';
    computePresetDates();
    updateDateRangeLabel();
  }

  function updateDateRangeLabel() {
    computePresetDates();
    const label = $('dateRangeLabel');
    if (state.preset === 'custom' && state.dateFrom && state.dateTo) {
      label.textContent = `${fmtDate(state.dateFrom)} – ${fmtDate(state.dateTo)}`;
    } else {
      label.textContent = `${fmtDate(state.dateFrom)} – ${fmtDate(state.dateTo)}`;
    }
  }

  function buildParams(extra = {}) {
    const p = { preset: state.preset };
    if (state.preset === 'custom') {
      p.date_from = state.dateFrom;
      p.date_to   = state.dateTo;
    }
    const branchEl  = $('filterBranch');
    const paymentEl = $('filterPayment');
    if (branchEl  && branchEl.value  !== 'all') p.branch_id  = branchEl.value;
    if (paymentEl && paymentEl.value !== 'all') p.payment_id = paymentEl.value;
    return { ...p, ...extra };
}

  // ── Report tabs ───────────────────────────────────────────────────────────
  function bindReportTabs() {
        $$('.report-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            $$('.report-tab').forEach(t => t.classList.remove('active'));
            $$('.report-section').forEach(s => s.classList.remove('active'));
            tab.classList.add('active');
            state.activeReport = tab.dataset.report;
            $(`section-${state.activeReport}`).classList.add('active');

            if (state.activeReport === 'schedule') {
            loadSchedules();
            } else if (state.activeReport === 'integrity') {
            // Only auto-run integrity if not already loaded
            if (!state.reportData.integrity) runIntegrityCheck();
            } else {
            // Auto-fetch the report if not already loaded for current params
            if (!state.reportData[state.activeReport]) generateReport();
            }
        });
        });
    }

  function bindRevGroupTabs() {
    $$('.rev-group-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        $$('.rev-group-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        state.revGroupBy = tab.dataset.group;
        if (state.reportData.revenue) renderRevenue(state.reportData.revenue, state.revGroupBy);
      });
    });
  }

  // ── Generate / dispatch ───────────────────────────────────────────────────
  async function generateReport() {
    const report = state.activeReport;
    if (report === 'schedule') { loadSchedules(); return; }
    if (report === 'integrity') { runIntegrityCheck(); return; }

    showLoading();
    try {
      const params = buildParams();
      let data;
      if (report === 'revenue') {
        data = await API('reports/revenue', { ...params, group_by: state.revGroupBy });
        state.reportData.revenue = data;
        renderRevenue(data, state.revGroupBy);
      } else if (report === 'vat') {
        data = await API('reports/vat', params);
        state.reportData.vat = data;
        renderVAT(data);
      } else if (report === 'discount') {
        data = await API('reports/discount', params);
        state.reportData.discount = data;
        renderDiscount(data);
      } else if (report === 'comparison') {
        data = await API('reports/comparison', params);
        state.reportData.comparison = data;
        renderComparison(data);
      }
    } catch (e) {
      console.error(e);
      toast('Failed to fetch report data. Is the Flask server running?', 'error');
    } finally {
      hideLoading();
    }
  }

  function bindActionButtons() {
    // Revenue
    $('revExportCsvBtn').addEventListener('click', () => exportCSV('reports/revenue/export/csv'));
    $('revExportPdfBtn').addEventListener('click', () => exportPDF('revenue'));
    // VAT
    $('vatExportCsvBtn').addEventListener('click', () => exportCSV('reports/vat/export/csv'));
    $('vatExportPdfBtn').addEventListener('click', () => exportPDF('vat'));
    // Discount
    $('discExportCsvBtn').addEventListener('click', () => exportCSV('reports/discount/export/csv'));
    $('discExportPdfBtn').addEventListener('click', () => exportPDF('discount'));
    // Comparison
    $('cmpExportPdfBtn').addEventListener('click', () => exportPDF('comparison'));
    // Integrity
    $('intRunCheckBtn').addEventListener('click', runIntegrityCheck);
    $('intExportPdfBtn').addEventListener('click', () => exportPDF('integrity'));
    // Schedule
    $('addScheduleBtn').addEventListener('click', () => openScheduleModal(null));
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  REVENUE
  // ══════════════════════════════════════════════════════════════════════════
  function renderRevenue(data, groupBy) {
    const { kpi, period_breakdown: periods, branch_breakdown: branches, date_range: dr } = data;
    const badgeText = `${fmtDate(dr.from)} – ${fmtDate(dr.to)}`;
    $('revPeriodBadge').textContent = badgeText;

    // KPI cards
    $('revTotal').textContent      = fmt(kpi.total_revenue);
    $('revTxCount').textContent    = fmtN(kpi.tx_count);
    $('revAOV').textContent        = fmt(kpi.avg_order_value);
    $('revBranchCount').textContent= kpi.branch_count;
    $('revTopDay').textContent     = fmt(kpi.top_day_revenue);
    $('revTopDaySub').textContent  = kpi.top_day ? fmtDate(kpi.top_day) : 'best day';
    $('revChartBadge').textContent = groupBy.toUpperCase();

    // Revenue trend chart
    const labels = periods.map(p => groupBy === 'monthly' ? fmtMonth(p.period) : String(p.period));
    const revVals  = periods.map(p => p.net_revenue);
    const discVals = periods.map(p => p.discounts);
    mkChart('revTrendChart', 'bar', {
      labels,
      datasets: [
        { label: 'Net Revenue', data: revVals, backgroundColor: PALETTE[0] + 'cc', borderColor: PALETTE[0], borderWidth: 1.5, borderRadius: 4 },
        { label: 'Discounts',   data: discVals, backgroundColor: PALETTE[2] + 'aa', borderColor: PALETTE[2], borderWidth: 1.5, borderRadius: 4 },
      ],
    });

    // Period breakdown table
    const tbody = $('revTableBody');
    const tfoot = $('revTableFoot');
    if (!periods.length) {
      tbody.innerHTML = `<tr><td colspan="7" class="empty-cell">No data for this period</td></tr>`;
      tfoot.innerHTML = '';
      return;
    }
    tbody.innerHTML = periods.map(p => `
      <tr>
        <td>${groupBy === 'monthly' ? fmtMonth(p.period) : String(p.period)}</td>
        <td class="num">${fmtN(p.tx_count)}</td>
        <td class="num">${fmt(p.gross_revenue)}</td>
        <td class="num" style="color:var(--warning)">${fmt(p.discounts)}</td>
        <td class="num">${fmt(p.vat)}</td>
        <td class="num" style="font-weight:600">${fmt(p.net_revenue)}</td>
        <td class="num">${fmt(p.avg_ticket)}</td>
      </tr>`).join('');

    const totTx  = periods.reduce((s, p) => s + p.tx_count, 0);
    const totGr  = periods.reduce((s, p) => s + p.gross_revenue, 0);
    const totDi  = periods.reduce((s, p) => s + p.discounts, 0);
    const totVat = periods.reduce((s, p) => s + p.vat, 0);
    const totNet = periods.reduce((s, p) => s + p.net_revenue, 0);
    tfoot.innerHTML = `<tr class="tfoot-total">
      <td><strong>Total</strong></td>
      <td class="num"><strong>${fmtN(totTx)}</strong></td>
      <td class="num"><strong>${fmt(totGr)}</strong></td>
      <td class="num"><strong>${fmt(totDi)}</strong></td>
      <td class="num"><strong>${fmt(totVat)}</strong></td>
      <td class="num"><strong>${fmt(totNet)}</strong></td>
      <td class="num">—</td>
    </tr>`;

    // Branch breakdown table
    const bTbody = $('revBranchTableBody');
    bTbody.innerHTML = branches.map(b => `
      <tr>
        <td>${b.rank}</td>
        <td>${b.branch}</td>
        <td class="num">${fmtN(b.tx_count)}</td>
        <td class="num" style="font-weight:600">${fmt(b.revenue)}</td>
        <td class="num" style="color:var(--warning)">${fmt(b.discounts)}</td>
        <td class="num">${fmt(b.vat)}</td>
        <td class="num">${fmt(b.avg_ticket)}</td>
        <td class="num">${b.pct_total.toFixed(1)}%</td>
      </tr>`).join('');
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  VAT
  // ══════════════════════════════════════════════════════════════════════════
  function renderVAT(data) {
    const { kpi, branch_breakdown: branches, period_breakdown: periods, date_range: dr } = data;
    $('vatPeriodBadge').textContent = `${fmtDate(dr.from)} – ${fmtDate(dr.to)}`;

    $('vatTotal').textContent      = fmt(kpi.total_vat);
    $('vatTxCount').textContent    = fmtN(kpi.tx_count);
    $('vatBranchCount').textContent= kpi.branch_count;
    $('vatAvg').textContent        = fmt(kpi.avg_vat);
    $('vatTopBranch').textContent  = kpi.top_branch;
    $('vatTopBranchAmt').textContent = fmt(kpi.top_branch_vat);

    const totVat = kpi.total_vat;
    const bTbody = $('vatBranchTableBody');
    const bFoot  = $('vatTableFoot');

    bTbody.innerHTML = branches.map(b => `
      <tr>
        <td>${b.rank}</td>
        <td>${b.branch}</td>
        <td class="num">${fmtN(b.vat_txns)}</td>
        <td class="num">${fmt(b.total_gross)}</td>
        <td class="num" style="font-weight:600;color:var(--primary)">${fmt(b.vat_amount)}</td>
        <td class="num">${fmt(b.net_of_vat)}</td>
        <td class="num">${fmt(b.avg_vat_per_txn)}</td>
        <td class="num">${b.pct_total.toFixed(1)}%</td>
      </tr>`).join('') || `<tr><td colspan="8" class="empty-cell">No VAT data</td></tr>`;

    const totTxns  = branches.reduce((s, b) => s + b.vat_txns, 0);
    const totGross = branches.reduce((s, b) => s + b.total_gross, 0);
    const totNet   = branches.reduce((s, b) => s + b.net_of_vat, 0);
    bFoot.innerHTML = `<tr class="tfoot-total">
      <td colspan="2"><strong>Total</strong></td>
      <td class="num"><strong>${fmtN(totTxns)}</strong></td>
      <td class="num"><strong>${fmt(totGross)}</strong></td>
      <td class="num"><strong>${fmt(totVat)}</strong></td>
      <td class="num"><strong>${fmt(totNet)}</strong></td>
      <td class="num">—</td><td class="num">100%</td>
    </tr>`;

    $('vatPeriodTableBody').innerHTML = periods.map(p => `
      <tr>
        <td>${fmtMonth(p.month)}</td>
        <td class="num">${fmtN(p.tx_count)}</td>
        <td class="num">${fmt(p.total_revenue)}</td>
        <td class="num" style="font-weight:600;color:var(--primary)">${fmt(p.vat_collected)}</td>
        <td class="num">${fmt(p.net_of_vat)}</td>
      </tr>`).join('') || `<tr><td colspan="5" class="empty-cell">—</td></tr>`;
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  DISCOUNT
  // ══════════════════════════════════════════════════════════════════════════
  function renderDiscount(data) {
    const { kpi, monthly_trend: trend, by_type: types, branch_breakdown: branches, date_range: dr } = data;
    $('discPeriodBadge').textContent = `${fmtDate(dr.from)} – ${fmtDate(dr.to)}`;

    $('discTotal').textContent    = fmt(kpi.total_discount);
    $('discTxCount').textContent  = fmtN(kpi.disc_tx_count);
    $('discRate').textContent     = kpi.disc_rate.toFixed(2) + '%';
    $('discAvg').textContent      = fmt(kpi.avg_discount);
    $('discMax').textContent      = fmt(kpi.max_discount);
    $('discMaxInv').textContent   = kpi.max_invoice ? `Invoice #${kpi.max_invoice}` : '—';

    // Monthly trend chart
    mkChart('discTrendChart', 'bar', {
      labels: trend.map(t => fmtMonth(t.month)),
      datasets: [{
        label: 'Discount Value',
        data: trend.map(t => t.disc_value),
        backgroundColor: PALETTE[2] + 'cc',
        borderColor: PALETTE[2],
        borderWidth: 1.5,
        borderRadius: 4,
      }],
    });

    // Discount type doughnut
    mkChart('discTypeChart', 'doughnut', {
      labels: types.map(t => t.type),
      datasets: [{
        data: types.map(t => t.disc_value),
        backgroundColor: PALETTE,
        borderWidth: 2,
      }],
    }, { plugins: { legend: { position: 'bottom' } }, scales: undefined });

    // Branch table
    const tbody = $('discBranchTableBody');
    const tfoot = $('discTableFoot');
    tbody.innerHTML = branches.map(b => `
      <tr>
        <td>${b.rank}</td>
        <td>${b.branch}</td>
        <td class="num">${fmtN(b.total_txns)}</td>
        <td class="num">${fmtN(b.disc_count)}</td>
        <td class="num" style="font-weight:600;color:var(--warning)">${fmt(b.disc_value)}</td>
        <td class="num">${fmt(b.gross_revenue)}</td>
        <td class="num">${b.disc_pct.toFixed(2)}%</td>
        <td class="num">${fmt(b.avg_disc)}</td>
      </tr>`).join('') || `<tr><td colspan="8" class="empty-cell">No discount data</td></tr>`;

    const totTxns = branches.reduce((s, b) => s + b.total_txns, 0);
    const totDisc = branches.reduce((s, b) => s + b.disc_count, 0);
    const totVal  = branches.reduce((s, b) => s + b.disc_value, 0);
    const totGross= branches.reduce((s, b) => s + b.gross_revenue, 0);
    tfoot.innerHTML = `<tr class="tfoot-total">
      <td colspan="2"><strong>Total</strong></td>
      <td class="num"><strong>${fmtN(totTxns)}</strong></td>
      <td class="num"><strong>${fmtN(totDisc)}</strong></td>
      <td class="num"><strong>${fmt(totVal)}</strong></td>
      <td class="num"><strong>${fmt(totGross)}</strong></td>
      <td class="num">${totGross ? (totVal/totGross*100).toFixed(2) : 0}%</td>
      <td class="num">—</td>
    </tr>`;
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  COMPARISON
  // ══════════════════════════════════════════════════════════════════════════
  function renderComparison(data) {
    const { metrics, daily_trend, branch_comparison, date_range: dr } = data;
    const curr = dr.current, prev = dr.previous;
    $('cmpPeriodBadge').textContent = `${fmtDate(curr.from)} – ${fmtDate(curr.to)} vs ${fmtDate(prev.from)} – ${fmtDate(prev.to)}`;

    // Metric cards
    const grid = $('cmpMetricGrid');
    grid.innerHTML = metrics.map(m => {
      const pct = m.delta_pct;
      const up  = pct !== null && pct >= 0;
      const pctTxt = pct === null ? '—' : `${up ? '+' : ''}${pct.toFixed(1)}%`;
      const isCurrency = m.unit === 'currency';
      const fmtVal = v => isCurrency ? fmt(v) : fmtN(v);
      return `
        <div class="comparison-card">
          <div class="cmp-label">${m.label}</div>
          <div class="cmp-row">
            <div class="cmp-col">
              <div class="cmp-sub">Current</div>
              <div class="cmp-val">${fmtVal(m.current)}</div>
            </div>
            <div class="cmp-arrow ${up ? 'up' : 'down'}"><i class="fa-solid fa-arrow-${up ? 'up' : 'down'}"></i></div>
            <div class="cmp-col">
              <div class="cmp-sub">Previous</div>
              <div class="cmp-val secondary">${fmtVal(m.previous)}</div>
            </div>
          </div>
          <div class="cmp-badge ${pct === null ? '' : up ? 'up' : 'down'}">${pctTxt}</div>
        </div>`;
    }).join('');

    // Trend chart – overlay current vs previous
    const maxLen = Math.max(daily_trend.current.length, daily_trend.previous.length);
    const labels = Array.from({ length: maxLen }, (_, i) => `Day ${i + 1}`);
    mkChart('cmpTrendChart', 'line', {
      labels,
      datasets: [
        {
          label: `Current (${fmtDate(curr.from)} – ${fmtDate(curr.to)})`,
          data: daily_trend.current.map(d => d.rev),
          borderColor: PALETTE[0], backgroundColor: PALETTE[0] + '22',
          tension: 0.3, fill: true, pointRadius: 2,
        },
        {
          label: `Previous (${fmtDate(prev.from)} – ${fmtDate(prev.to)})`,
          data: daily_trend.previous.map(d => d.rev),
          borderColor: PALETTE[1], backgroundColor: PALETTE[1] + '22',
          tension: 0.3, fill: true, pointRadius: 2, borderDash: [4, 4],
        },
      ],
    });

    // Branch comparison table
    const tbody = $('cmpBranchTableBody');
    tbody.innerHTML = branch_comparison.map(b => {
      const up = b.delta_pct !== null && b.delta_pct >= 0;
      return `<tr>
        <td>${b.branch}</td>
        <td class="num">${fmt(b.curr_revenue)}</td>
        <td class="num" style="color:var(--ink-3)">${fmt(b.prev_revenue)}</td>
        <td class="num ${up ? 'text-success' : 'text-danger'}">${up ? '+' : ''}${fmt(b.delta_amt)}</td>
        <td class="num ${up ? 'text-success' : 'text-danger'}">${b.delta_pct !== null ? fmtPct(b.delta_pct) : '—'}</td>
        <td class="num">${fmt(b.curr_vat)}</td>
        <td class="num" style="color:var(--ink-3)">${fmt(b.prev_vat)}</td>
        <td class="num" style="color:var(--warning)">${fmt(b.curr_disc)}</td>
        <td class="num" style="color:var(--ink-3)">${fmt(b.prev_disc)}</td>
      </tr>`;
    }).join('') || `<tr><td colspan="9" class="empty-cell">No comparison data</td></tr>`;
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  DATA INTEGRITY
  // ══════════════════════════════════════════════════════════════════════════
  async function runIntegrityCheck() {
    showLoading();
    try {
      const data = await API('reports/integrity', buildParams());
      state.reportData.integrity = data;
      renderIntegrity(data);
    } catch (e) {
      console.error(e);
      toast('Integrity check failed', 'error');
    } finally {
      hideLoading();
    }
  }

  function renderIntegrity(data) {
    const { summary: s, issues, date_range: dr } = data;
    $('intPeriodBadge').textContent = `${fmtDate(dr.from)} – ${fmtDate(dr.to)}`;
    $('intCleanCount').textContent  = fmtN(s.clean_records);
    $('intWarnCount').textContent   = fmtN(s.warning_count);
    $('intErrorCount').textContent  = fmtN(s.error_count);
    $('intTotalCount').textContent  = fmtN(s.total_checked);

    const flagsList = $('intFlagsList');
    if (!issues.length) {
      flagsList.innerHTML = `<div class="empty-state"><i class="fa-solid fa-shield-check" style="color:var(--success)"></i>
        <h4 style="color:var(--success)">All Clear</h4>
        <p>No issues found in the selected period.</p></div>`;
      $('intSuspiciousCard').style.display = 'none';
      return;
    }

    // Group by issue type for flag summary
    const grouped = {};
    issues.forEach(i => {
      grouped[i.issue] = (grouped[i.issue] || []);
      grouped[i.issue].push(i);
    });

    flagsList.innerHTML = Object.entries(grouped).map(([msg, rows]) => {
      const sev = rows[0].severity;
      const icon = sev === 'error' ? 'fa-circle-xmark' : 'fa-triangle-exclamation';
      const cls  = sev === 'error' ? 'flag-error' : 'flag-warn';
      return `<div class="integrity-flag ${cls}">
        <i class="fa-solid ${icon}"></i>
        <div class="flag-body">
          <div class="flag-msg">${msg}</div>
          <div class="flag-count">${rows.length} record${rows.length !== 1 ? 's' : ''} affected</div>
        </div>
        <span class="flag-badge ${sev}">${sev.toUpperCase()}</span>
      </div>`;
    }).join('');

    // Suspicious transactions table
    const card = $('intSuspiciousCard');
    card.style.display = 'block';
    const tbody = $('intSuspiciousBody');
    tbody.innerHTML = issues.slice(0, 200).map(i => `
      <tr class="${i.severity === 'error' ? 'row-error' : 'row-warn'}">
        <td>${i.invoice_number || '—'}</td>
        <td>${fmtDate(i.txn_date)}</td>
        <td>${i.branch_name || '—'}</td>
        <td class="num">${i.grand_total != null ? fmt(i.grand_total) : '—'}</td>
        <td class="num">${i.vat != null ? fmt(i.vat) : '—'}</td>
        <td class="num">${i.final_discount != null ? fmt(i.final_discount) : '—'}</td>
        <td><span class="status-badge ${i.transaction_status === 'OK' ? 'ok' : 'void'}">${i.transaction_status || '—'}</span></td>
        <td><span class="issue-tag ${i.severity}">${i.issue}</span></td>
      </tr>`).join('');
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  SCHEDULES
  // ══════════════════════════════════════════════════════════════════════════
  async function loadSchedules() {
    try {
      const data = await API('reports/schedules');
      state.schedules = data.schedules || [];
      renderSchedules();
    } catch (e) { console.error(e); }
  }

  function renderSchedules() {
    const grid = $('scheduleGrid');
    if (!state.schedules.length) {
      grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">
        <i class="fa-solid fa-calendar-clock"></i>
        <h4>No schedules configured</h4>
        <p>Click "New Schedule" to set up automated report delivery.</p>
      </div>`;
      return;
    }
    grid.innerHTML = state.schedules.map(s => `
      <div class="schedule-card ${s.active ? 'active' : 'inactive'}">
        <div class="sch-header">
          <div class="sch-name">${s.name}</div>
          <div class="sch-actions">
            <button class="sch-btn edit" data-id="${s.id}" title="Edit"><i class="fa-solid fa-pen"></i></button>
            <button class="sch-btn toggle" data-id="${s.id}" title="${s.active ? 'Pause' : 'Resume'}">
              <i class="fa-solid fa-${s.active ? 'pause' : 'play'}"></i></button>
            <button class="sch-btn delete" data-id="${s.id}" title="Delete"><i class="fa-solid fa-trash"></i></button>
          </div>
        </div>
        <div class="sch-meta">
          <span class="sch-badge type">${s.report_type}</span>
          <span class="sch-badge freq"><i class="fa-solid fa-clock"></i> ${s.frequency}</span>
          <span class="sch-badge format"><i class="fa-solid fa-file"></i> ${s.format}</span>
          ${s.active ? '<span class="sch-badge ok">Active</span>' : '<span class="sch-badge paused">Paused</span>'}
        </div>
        <div class="sch-email"><i class="fa-solid fa-envelope"></i> ${s.emails || '—'}</div>
        <div class="sch-dates">
          Created: ${s.created_at} &nbsp;|&nbsp; Last run: ${s.last_run || 'Never'}
        </div>
      </div>`).join('');

    // Bind actions
    $$('.sch-btn.edit').forEach(btn => {
      btn.addEventListener('click', () => {
        const s = state.schedules.find(x => x.id === +btn.dataset.id);
        if (s) openScheduleModal(s);
      });
    });
    $$('.sch-btn.toggle').forEach(btn => {
      btn.addEventListener('click', async () => {
        const s = state.schedules.find(x => x.id === +btn.dataset.id);
        if (!s) return;
        await API_PUT(`reports/schedules/${s.id}`, { active: !s.active });
        loadSchedules();
      });
    });
    $$('.sch-btn.delete').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this schedule?')) return;
        await API_DELETE(`reports/schedules/${btn.dataset.id}`);
        loadSchedules();
        toast('Schedule deleted', 'success');
      });
    });
  }

  // ── Schedule modal ────────────────────────────────────────────────────────
  let _editingScheduleId = null;

  function openScheduleModal(schedule) {
    _editingScheduleId = schedule ? schedule.id : null;
    $('schName').value       = schedule ? schedule.name       : '';
    $('schReportType').value = schedule ? schedule.report_type : 'revenue';
    $('schFrequency').value  = schedule ? schedule.frequency   : 'weekly';
    $('schEmails').value     = schedule ? schedule.emails      : '';
    $('schBranch').value     = schedule ? schedule.branch_id   : 'all';
    $('schFormat').value     = schedule ? schedule.format      : 'pdf';
    $('scheduleModal').classList.add('active');
  }

  function bindScheduleModal() {
    const closeModal = () => $('scheduleModal').classList.remove('active');
    $('scheduleModalClose').addEventListener('click', closeModal);
    $('scheduleModalCancel').addEventListener('click', closeModal);
    $('scheduleModal').addEventListener('click', e => { if (e.target === $('scheduleModal')) closeModal(); });

    $('scheduleModalSave').addEventListener('click', async () => {
      const body = {
        name:        $('schName').value.trim(),
        report_type: $('schReportType').value,
        frequency:   $('schFrequency').value,
        emails:      $('schEmails').value.trim(),
        branch_id:   $('schBranch').value,
        format:      $('schFormat').value,
      };
      if (!body.name) { toast('Please enter a schedule name', 'error'); return; }
      if (!body.emails) { toast('Please enter at least one recipient email', 'error'); return; }

      try {
        if (_editingScheduleId) {
          await API_PUT(`reports/schedules/${_editingScheduleId}`, body);
          toast('Schedule updated', 'success');
        } else {
          await API_POST('reports/schedules', body);
          toast('Schedule created', 'success');
        }
        closeModal();
        loadSchedules();
      } catch (e) {
        toast('Failed to save schedule', 'error');
      }
    });
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  CSV EXPORT
  // ══════════════════════════════════════════════════════════════════════════
  function exportCSV(endpoint) {
    const params = buildParams();
    const qs = new URLSearchParams({ endpoint, ...params }).toString();
    window.location.href = `../backend/api_proxy.php?${qs}`;
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  PDF EXPORT  — custom branded HTML → html2canvas → jsPDF
  // ══════════════════════════════════════════════════════════════════════════
  async function exportPDF(reportType) {
    const data = state.reportData[reportType];
    if (!data && reportType !== 'integrity') {
      toast('Generate the report first', 'error'); return;
    }
    toast('Opening print preview…', 'info');
    const html = buildPDFHtml(reportType, data);
    const win = window.open('', '_blank');
    if (!win) { toast('Popup blocked — please allow popups for this site', 'error'); return; }
    win.document.open();
    win.document.write(html);
    win.document.close();
  }

  // ── PDF HTML builder ──────────────────────────────────────────────────────
  function buildPDFHtml(reportType, data) {
    const now = new Date().toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' });
    const dr  = data?.date_range;
    const rangeLabel = dr
      ? `${fmtDate(dr.from || dr.current?.from)} – ${fmtDate(dr.to || dr.current?.to)}`
      : '—';

    const titleMap = {
      revenue:    'Revenue Summary Report',
      vat:        'VAT Summary Report',
      discount:   'Discount Cost Report',
      comparison: 'Period-over-Period Comparison',
      integrity:  'Data Integrity Report',
    };

    let body = '';

    if (reportType === 'revenue' && data) {
      const { kpi, period_breakdown: periods, branch_breakdown: branches } = data;
      body = `
        <div class="pdf-kpi-row">
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Total Revenue</div><div class="pdf-kpi-val">${fmt(kpi.total_revenue)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Transactions</div><div class="pdf-kpi-val">${fmtN(kpi.tx_count)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Avg Order Value</div><div class="pdf-kpi-val">${fmt(kpi.avg_order_value)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Active Branches</div><div class="pdf-kpi-val">${kpi.branch_count}</div></div>
        </div>
        ${buildTable(['Period','Transactions','Gross Revenue','Discounts','VAT','Net Revenue','Avg Ticket'],
          periods.map(p => [groupByLabel(p.period, data.group_by), fmtN(p.tx_count), fmt(p.gross_revenue), fmt(p.discounts), fmt(p.vat), fmt(p.net_revenue), fmt(p.avg_ticket)]))}
        <h3 style="margin:18px 0 8px;font-size:13px;color:#0f766e;">Revenue by Branch</h3>
        ${buildTable(['#','Branch','Transactions','Revenue','Discounts','VAT','Avg Ticket','% Total'],
          branches.map(b => [b.rank, b.branch, fmtN(b.tx_count), fmt(b.revenue), fmt(b.discounts), fmt(b.vat), fmt(b.avg_ticket), b.pct_total.toFixed(1)+'%']))}`;
    }

    if (reportType === 'vat' && data) {
      const { kpi, branch_breakdown: branches, period_breakdown: periods } = data;
      body = `
        <div class="pdf-kpi-row">
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Total VAT</div><div class="pdf-kpi-val">${fmt(kpi.total_vat)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">VAT Transactions</div><div class="pdf-kpi-val">${fmtN(kpi.tx_count)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Branches Covered</div><div class="pdf-kpi-val">${kpi.branch_count}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Avg VAT/Txn</div><div class="pdf-kpi-val">${fmt(kpi.avg_vat)}</div></div>
        </div>
        ${buildTable(['#','Branch','VAT Txns','Total Gross','VAT Amount','Net of VAT','Avg VAT/Txn','% Total'],
          branches.map(b => [b.rank, b.branch, fmtN(b.vat_txns), fmt(b.total_gross), fmt(b.vat_amount), fmt(b.net_of_vat), fmt(b.avg_vat_per_txn), b.pct_total.toFixed(1)+'%']))}
        <h3 style="margin:18px 0 8px;font-size:13px;color:#0f766e;">VAT by Month</h3>
        ${buildTable(['Month','Transactions','Total Revenue','VAT Collected','Net of VAT'],
          periods.map(p => [fmtMonth(p.month), fmtN(p.tx_count), fmt(p.total_revenue), fmt(p.vat_collected), fmt(p.net_of_vat)]))}`;
    }

    if (reportType === 'discount' && data) {
      const { kpi, branch_breakdown: branches, monthly_trend: trend } = data;
      body = `
        <div class="pdf-kpi-row">
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Total Discount</div><div class="pdf-kpi-val">${fmt(kpi.total_discount)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Discounted Txns</div><div class="pdf-kpi-val">${fmtN(kpi.disc_tx_count)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Discount Rate</div><div class="pdf-kpi-val">${kpi.disc_rate.toFixed(2)}%</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Avg Discount</div><div class="pdf-kpi-val">${fmt(kpi.avg_discount)}</div></div>
        </div>
        ${buildTable(['#','Branch','Total Txns','Discounted','Discount Value','Gross Revenue','Disc %','Avg Disc'],
          branches.map(b => [b.rank, b.branch, fmtN(b.total_txns), fmtN(b.disc_count), fmt(b.disc_value), fmt(b.gross_revenue), b.disc_pct.toFixed(2)+'%', fmt(b.avg_disc)]))}
        <h3 style="margin:18px 0 8px;font-size:13px;color:#0f766e;">Monthly Trend</h3>
        ${buildTable(['Month','Discount Value','# Discounted Txns'],
          trend.map(t => [fmtMonth(t.month), fmt(t.disc_value), fmtN(t.disc_count)]))}`;
    }

    if (reportType === 'comparison' && data) {
      const { metrics, branch_comparison } = data;
      const currDr = data.date_range.current;
      const prevDr = data.date_range.previous;
      body = `
        <p style="font-size:11px;color:#6b7280;margin:0 0 12px;">Current: ${fmtDate(currDr.from)} – ${fmtDate(currDr.to)}&nbsp;&nbsp;|&nbsp;&nbsp;Previous: ${fmtDate(prevDr.from)} – ${fmtDate(prevDr.to)}</p>
        ${buildTable(['Metric','Current','Previous','Change %'],
          metrics.map(m => [m.label, m.unit==='currency'?fmt(m.current):fmtN(m.current), m.unit==='currency'?fmt(m.previous):fmtN(m.previous), fmtPct(m.delta_pct)]))}
        <h3 style="margin:18px 0 8px;font-size:13px;color:#0f766e;">Branch-level Comparison</h3>
        ${buildTable(['Branch','Curr Revenue','Prev Revenue','Δ Amount','Δ %','Curr VAT','Prev VAT','Curr Disc','Prev Disc'],
          branch_comparison.map(b => [b.branch, fmt(b.curr_revenue), fmt(b.prev_revenue), fmt(b.delta_amt), fmtPct(b.delta_pct), fmt(b.curr_vat), fmt(b.prev_vat), fmt(b.curr_disc), fmt(b.prev_disc)]))}`;
    }

    if (reportType === 'integrity' && data) {
      const { summary: s, issues } = data;
      body = `
        <div class="pdf-kpi-row">
          <div class="pdf-kpi ok"><div class="pdf-kpi-lbl">Clean Records</div><div class="pdf-kpi-val">${fmtN(s.clean_records)}</div></div>
          <div class="pdf-kpi warn"><div class="pdf-kpi-lbl">Warnings</div><div class="pdf-kpi-val">${fmtN(s.warning_count)}</div></div>
          <div class="pdf-kpi error"><div class="pdf-kpi-lbl">Critical Issues</div><div class="pdf-kpi-val">${fmtN(s.error_count)}</div></div>
          <div class="pdf-kpi"><div class="pdf-kpi-lbl">Total Checked</div><div class="pdf-kpi-val">${fmtN(s.total_checked)}</div></div>
        </div>
        ${buildTable(['Invoice #','Date','Branch','Grand Total','VAT','Discount','Status','Issue','Severity'],
          issues.slice(0,200).map(i => [i.invoice_number||'—', fmtDate(i.txn_date), i.branch_name||'—', i.grand_total!=null?fmt(i.grand_total):'—', i.vat!=null?fmt(i.vat):'—', i.final_discount!=null?fmt(i.final_discount):'—', i.transaction_status||'—', i.issue, i.severity.toUpperCase()]))}`;
    }

    return `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>${titleMap[reportType] || reportType} — ChronoSales</title>
    <style>
      @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap');
      * { box-sizing: border-box; margin:0; padding:0; }
      body { font-family: 'DM Sans', Arial, sans-serif; font-size:12px; color:#111827; background:#fff; padding:32px; }

      /* ── Screen-only toolbar ── */
      .print-toolbar {
        position: fixed; top:0; left:0; right:0; z-index:9999;
        background:#0f766e; color:#fff;
        display:flex; align-items:center; justify-content:space-between;
        padding:10px 24px; gap:12px;
        box-shadow:0 2px 8px rgba(0,0,0,.25);
      }
      .print-toolbar-title { font-size:14px; font-weight:700; letter-spacing:-.3px; }
      .print-toolbar-title span { color:#a7f3d0; font-weight:400; font-size:12px; margin-left:8px; }
      .print-toolbar-btns { display:flex; gap:10px; }
      .btn-print, .btn-close {
        border:none; border-radius:6px; padding:7px 18px;
        font-size:12px; font-weight:600; cursor:pointer; font-family:inherit;
      }
      .btn-print { background:#fff; color:#0f766e; }
      .btn-print:hover { background:#ecfdf5; }
      .btn-close  { background:rgba(255,255,255,.15); color:#fff; }
      .btn-close:hover { background:rgba(255,255,255,.28); }

      /* ── Report body pushed below toolbar on screen ── */
      .report-wrap { margin-top:56px; }

      .pdf-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #0f766e; padding-bottom:16px; margin-bottom:20px; }
      .pdf-logo { font-size:22px; font-weight:800; color:#0f766e; letter-spacing:-0.5px; }
      .pdf-logo span { color:#6366f1; }
      .pdf-meta { text-align:right; font-size:10.5px; color:#6b7280; line-height:1.6; }
      .pdf-title { font-size:17px; font-weight:700; color:#111827; margin-bottom:4px; }
      .pdf-period { font-size:11px; color:#6b7280; margin-bottom:18px; }
      .pdf-kpi-row { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
      .pdf-kpi { flex:1; min-width:140px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px; }
      .pdf-kpi.ok   { border-color:#10b981; background:#ecfdf5; }
      .pdf-kpi.warn { border-color:#f59e0b; background:#fffbeb; }
      .pdf-kpi.error{ border-color:#ef4444; background:#fef2f2; }
      .pdf-kpi-lbl { font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px; }
      .pdf-kpi-val { font-size:15px; font-weight:700; color:#0f766e; }
      table { width:100%; border-collapse:collapse; margin-bottom:20px; font-size:10.5px; }
      th { background:#0f766e; color:#fff; padding:7px 10px; text-align:left; font-weight:600; }
      th:last-child, td:last-child { text-align:right; }
      td { padding:6px 10px; border-bottom:1px solid #f3f4f6; }
      tr:nth-child(even) td { background:#f9fafb; }
      .pdf-footer { margin-top:24px; border-top:1px solid #e5e7eb; padding-top:10px; font-size:9.5px; color:#9ca3af; display:flex; justify-content:space-between; }
      h3 { font-size:13px; font-weight:600; color:#0f766e; margin:18px 0 8px; }

      /* ── Print media — hide toolbar, reset margins ── */
      @media print {
        .print-toolbar { display:none !important; }
        .report-wrap   { margin-top:0 !important; }
        body { padding:18px 24px; }
        @page { margin:15mm 12mm; size: A4 portrait; }
        table { page-break-inside:auto; }
        tr    { page-break-inside:avoid; page-break-after:auto; }
        thead { display:table-header-group; }
        .pdf-kpi-row { break-inside:avoid; }
        .pdf-footer  { position:fixed; bottom:0; left:24px; right:24px; background:#fff; }
      }
    </style></head><body>
    <div class="print-toolbar">
      <div class="print-toolbar-title">
        ${titleMap[reportType] || reportType}
        <span>ChronoSales Report Preview</span>
      </div>
      <div class="print-toolbar-btns">
        <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
        <button class="btn-close" onclick="window.close()">✕ Close</button>
      </div>
    </div>
    <div class="report-wrap">
    <div class="pdf-header">
      <div>
        <div class="pdf-logo">Chrono<span>Sales</span></div>
        <div style="font-size:10px;color:#6b7280;margin-top:2px;">Business Intelligence &amp; Reports</div>
      </div>
      <div class="pdf-meta">
        <div>Generated: ${now}</div>
        <div>Report Type: ${titleMap[reportType] || reportType}</div>
        <div>Period: ${rangeLabel}</div>
      </div>
    </div>
    <div class="pdf-title">${titleMap[reportType] || reportType}</div>
    <div class="pdf-period">Reporting Period: ${rangeLabel}</div>
    ${body}
    <div class="pdf-footer">
      <span>ChronoSales — Confidential. For internal use only.</span>
      <span>Auto-generated on ${now}</span>
    </div>
    </div></body></html>`;
  }

  function buildTable(headers, rows) {
    if (!rows.length) return '<p style="color:#9ca3af;font-size:11px;margin-bottom:16px;">No records.</p>';
    return `<table>
      <thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>
      <tbody>${rows.map(row => `<tr>${row.map(c => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody>
    </table>`;
  }

  function groupByLabel(period, groupBy) {
    if (groupBy === 'monthly') return fmtMonth(period);
    return String(period);
  }

  // ── Start ─────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', init);
})();