'use strict';

const API_PROXY = '../backend/api_proxy.php';
const CURRENCY  = '₱';

// ── Colour palette (matches CSS vars) ────────────────────────
const PALETTE = [
    '#0f766e','#14b8a6','#f59e0b','#7c3aed',
    '#0284c7','#dc2626','#16a34a','#d97706'
];
const DONUT_PAL = [
    '#0f766e','#14b8a6','#f59e0b','#7c3aed',
    '#0284c7','#16a34a','#dc2626','#d97706'
];

// ── Chart instances ──────────────────────────────────────────
let sparklineChart = null;
let paymentChart   = null;
let branchChart    = null;
let txTrendChart   = null;

// ── Raw data cache (for client-side filtering) ───────────────
let _data = null;

// ── Active filters ───────────────────────────────────────────
let sparklineDays  = 30;   // 7 | 14 | 30
let branchMetric   = 'revenue'; // 'revenue' | 'tx_count' | 'avg_ticket'
let paymentMetric  = 'revenue'; // 'revenue' | 'tx_count'
let txTrendMetric  = 'both';    // 'both' | 'tx' | 'ticket'

// ── Utilities ────────────────────────────────────────────────
const fmt  = (n) => CURRENCY + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const fmtK = (n) => {
    if (n >= 1_000_000) return CURRENCY + (n / 1_000_000).toFixed(1) + 'M';
    if (n >= 1_000)     return CURRENCY + (n / 1_000).toFixed(1) + 'K';
    return fmt(n);
};
const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
const showEl  = (id)      => { const el = document.getElementById(id); if (el) el.classList.remove('hidden'); };
const hideEl  = (id)      => { const el = document.getElementById(id); if (el) el.classList.add('hidden'); };

// ── Topbar date ──────────────────────────────────────────────
function setTopbarDate() {
    const el = document.getElementById('topbarDate');
    if (!el) return;
    el.textContent = new Date().toLocaleDateString('en-PH', {
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
    });
}

// ── Metric Cards ─────────────────────────────────────────────
function renderMetrics(metrics) {
    const { date_from, date_to } = dashGetDateRange();
    const today = new Date().toISOString().slice(0, 10);
    const isDefaultMonth =
        date_from === new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10) &&
        date_to   === today;

    // Today card — label stays "Today's Revenue" but value is 0 if out of range
    setText('valToday', fmtK(metrics.today.revenue));
    setText('subToday', metrics.today.tx + ' transactions');

    // Week card — relabel if filtered
    const weekLabel = document.querySelector('#cardWeek .metric-label');
    if (weekLabel) {
        weekLabel.innerHTML = isDefaultMonth
            ? '<i class="fa-regular fa-calendar-week"></i> This Week'
            : '<i class="fa-regular fa-calendar-week"></i> This Week (in range)';
    }
    setText('valWeek', fmtK(metrics.week.revenue));
    setText('subWeek', metrics.week.tx + ' transactions');

    // Month card — relabel to "Selected Period" when a custom range is applied
    const monthLabel = document.querySelector('#cardMonth .metric-label');
    if (monthLabel) {
        monthLabel.innerHTML = isDefaultMonth
            ? '<i class="fa-regular fa-calendar"></i> This Month'
            : '<i class="fa-regular fa-calendar"></i> Selected Period';
    }
    setText('valMonth', fmtK(metrics.month.revenue));

    const mom   = metrics.mom_change_pct;
    const badge = document.createElement('span');
    badge.className = 'metric-change ' + (mom > 0 ? 'up' : mom < 0 ? 'down' : 'flat');
    badge.innerHTML = (mom > 0
        ? '<i class="fa-solid fa-arrow-trend-up"></i> +' + mom
        : mom < 0
        ? '<i class="fa-solid fa-arrow-trend-down"></i> ' + mom
        : '— ' + mom) + '% vs prior period';
    const subMonth = document.getElementById('subMonth');
    if (subMonth) { subMonth.innerHTML = ''; subMonth.appendChild(badge); }

    // Avg ticket — based on selected period
    const avgLabel = document.querySelector('#cardAvg .metric-label');
    if (avgLabel) {
        avgLabel.innerHTML = isDefaultMonth
            ? '<i class="fa-solid fa-receipt"></i> Avg Ticket (Month)'
            : '<i class="fa-solid fa-receipt"></i> Avg Ticket (Period)';
    }
    const avgTicket = metrics.month.tx > 0 ? metrics.month.revenue / metrics.month.tx : 0;
    setText('valAvg', fmtK(avgTicket));
    setText('subAvg', 'per transaction');
}

// ── Sparkline Chart ──────────────────────────────────────────
function renderSparkline(data) {
    const sliced  = data.slice(-sparklineDays);
    const labels  = sliced.map(d => {
        const dt = new Date(d.date);
        return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
    });
    const revenues = sliced.map(d => d.revenue);

    const ctx = document.getElementById('sparklineChart');
    if (!ctx) return;
    if (sparklineChart) sparklineChart.destroy();

    const grd = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
    grd.addColorStop(0, 'rgba(20,184,166,0.22)');
    grd.addColorStop(1, 'rgba(20,184,166,0.00)');

    sparklineChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Revenue',
                data: revenues,
                borderColor: '#0f766e',
                borderWidth: 2.5,
                fill: true,
                backgroundColor: grd,
                pointRadius: 0,
                pointHitRadius: 12,
                tension: 0.38,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111827',
                    titleColor: '#9ca3af',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'DM Mono', size: 11 },
                    bodyFont:  { family: 'DM Sans',  size: 12 },
                    padding: 10,
                    callbacks: {
                        title: (items) => items[0].label,
                        label: (item)  => '  Revenue: ' + fmt(item.raw),
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: '#9ca3af', font: { family: 'DM Sans', size: 11 }, maxTicksLimit: 8, maxRotation: 0 }
                },
                y: {
                    grid: { color: '#f0f5f4' },
                    border: { display: false, dash: [4,4] },
                    ticks: { color: '#9ca3af', font: { family: 'DM Mono', size: 11 }, callback: (v) => fmtK(v), maxTicksLimit: 5 }
                }
            }
        }
    });
}

// ── Payment Donut ────────────────────────────────────────────
function renderPaymentChart(data) {
    const ctx = document.getElementById('paymentChart');
    if (!ctx) return;

    const labels = data.map(d => d.method);
    const values = paymentMetric === 'revenue'
        ? data.map(d => d.revenue)
        : data.map(d => d.tx_count);
    const total  = values.reduce((s, v) => s + v, 0);

    if (paymentChart) paymentChart.destroy();

    paymentChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: DONUT_PAL.slice(0, data.length),
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111827',
                    titleColor: '#9ca3af',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'DM Mono', size: 11 },
                    bodyFont:  { family: 'DM Sans',  size: 12 },
                    padding: 10,
                    callbacks: {
                        label: (item) => {
                            const pct = total > 0 ? ((item.raw / total) * 100).toFixed(1) : 0;
                            const val = paymentMetric === 'revenue' ? fmt(item.raw) : item.raw + ' txns';
                            return '  ' + val + '  (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });

    // Custom legend
    const legendEl = document.getElementById('paymentLegend');
    if (legendEl) {
        legendEl.innerHTML = '';
        data.forEach((d, i) => {
            const val = paymentMetric === 'revenue' ? d.revenue : d.tx_count;
            const pct = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';
            legendEl.innerHTML += `
                <div class="payment-legend-item">
                    <span class="payment-legend-dot" style="background:${DONUT_PAL[i]}"></span>
                    <span class="payment-legend-name">${d.method}</span>
                    <span class="payment-legend-pct">${pct}%</span>
                </div>`;
        });
    }
}

// ── Branch Bar Chart ─────────────────────────────────────────
function renderBranchChart(data) {
    const ctx = document.getElementById('branchChart');
    if (!ctx) return;

    // Sort by selected metric desc, take top 8
    const sorted = [...data].sort((a, b) => b[branchMetric] - a[branchMetric]).slice(0, 8);
    const labels  = sorted.map(d => d.name.length > 20 ? d.name.substring(0, 18) + '…' : d.name);
    const values  = sorted.map(d => d[branchMetric]);

    const labelMap = { revenue: 'Revenue', tx_count: 'Transactions', avg_ticket: 'Avg Ticket' };

    // Gradient bar colours — teal shades
    const colors = sorted.map((_, i) => {
        const alpha = 1 - (i / sorted.length) * 0.45;
        return `rgba(15,118,110,${alpha})`;
    });

    if (branchChart) branchChart.destroy();

    branchChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: labelMap[branchMetric],
                data: values,
                backgroundColor: colors,
                borderRadius: 6,
                borderSkipped: false,
                hoverBackgroundColor: '#0f766e',
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111827',
                    titleColor: '#9ca3af',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'DM Mono', size: 11 },
                    bodyFont:  { family: 'DM Sans',  size: 12 },
                    padding: 10,
                    callbacks: {
                        label: (item) => {
                            const v = item.raw;
                            return '  ' + (branchMetric === 'tx_count' ? v + ' txns' : fmt(v));
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: '#f0f5f4' },
                    border: { display: false },
                    ticks: {
                        color: '#9ca3af',
                        font: { family: 'DM Mono', size: 11 },
                        callback: (v) => branchMetric === 'tx_count' ? v : fmtK(v),
                        maxTicksLimit: 5,
                    }
                },
                y: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: '#374151', font: { family: 'DM Sans', size: 11.5, weight: '500' } }
                }
            }
        }
    });
}

// ── Tx Trend Dual-axis ───────────────────────────────────────
function renderTxTrendChart(data) {
    const ctx = document.getElementById('txTrendChart');
    if (!ctx) return;

    const labels     = data.map(d => {
        const dt = new Date(d.week);
        return 'Wk ' + dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
    });
    const txCounts   = data.map(d => d.tx_count);
    const avgTickets = data.map(d => d.avg_ticket);

    if (txTrendChart) txTrendChart.destroy();

    const datasets = [];

    if (txTrendMetric === 'both' || txTrendMetric === 'tx') {
        datasets.push({
            label: 'Transactions',
            data: txCounts,
            backgroundColor: 'rgba(20,184,166,0.18)',
            borderColor: '#14b8a6',
            borderWidth: 1,
            borderRadius: 5,
            borderSkipped: false,
            yAxisID: 'yLeft',
            order: 2,
        });
    }
    if (txTrendMetric === 'both' || txTrendMetric === 'ticket') {
        datasets.push({
            label: 'Avg Ticket',
            data: avgTickets,
            type: 'line',
            borderColor: '#f59e0b',
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: '#f59e0b',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            fill: false,
            tension: 0.3,
            yAxisID: txTrendMetric === 'ticket' ? 'yLeft' : 'yRight',
            order: 1,
        });
    }

    txTrendChart = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        font: { family: 'DM Sans', size: 11 },
                        color: '#9ca3af',
                        boxWidth: 10, boxHeight: 10, padding: 12,
                        usePointStyle: true,
                    }
                },
                tooltip: {
                    backgroundColor: '#111827',
                    titleColor: '#9ca3af',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'DM Mono', size: 11 },
                    bodyFont:  { family: 'DM Sans',  size: 12 },
                    padding: 10,
                    callbacks: {
                        label: (item) => item.dataset.label === 'Avg Ticket'
                            ? '  Avg Ticket: ' + fmt(item.raw)
                            : '  Transactions: ' + item.raw
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: '#9ca3af', font: { family: 'DM Sans', size: 10.5 }, maxRotation: 0 }
                },
                yLeft: {
                    position: 'left',
                    grid: { color: '#f0f5f4' },
                    border: { display: false },
                    ticks: { color: '#9ca3af', font: { family: 'DM Mono', size: 11 }, maxTicksLimit: 5,
                             callback: (v) => txTrendMetric === 'ticket' ? fmtK(v) : v }
                },
                yRight: {
                    position: 'right',
                    display: txTrendMetric === 'both',
                    grid: { drawOnChartArea: false },
                    border: { display: false },
                    ticks: { color: '#9ca3af', font: { family: 'DM Mono', size: 11 }, callback: (v) => fmtK(v), maxTicksLimit: 5 }
                }
            }
        }
    });
}

// ── Forecast Alert ───────────────────────────────────────────
function renderForecastAlert(alert) {
    if (!alert || !alert.alert_type) return;

    const banner = document.getElementById('alertBanner');
    const icon   = document.getElementById('alertIcon');
    const title  = document.getElementById('alertTitle');
    const msg    = document.getElementById('alertMsg');
    const badge  = document.getElementById('alertMlBadge');
    if (!banner) return;

    banner.className = 'alert-banner ' + alert.alert_type;

    const icons  = { surge: 'fa-arrow-trend-up', dip: 'fa-arrow-trend-down', stable: 'fa-chart-line' };
    if (icon)  icon.className  = 'fa-solid ' + (icons[alert.alert_type] || 'fa-bell');

    const titles = { surge: 'Revenue Surge Expected', dip: 'Revenue Dip Expected', stable: 'Revenue Stable' };
    if (title) title.textContent = titles[alert.alert_type] || 'Forecast Alert';
    if (msg)   msg.textContent   = alert.message || '';
    if (badge) badge.textContent = alert.ml_powered ? 'ML-Powered' : 'Heuristic';

    showEl('alertBanner');
}

// ── SHAP label map ───────────────────────────────────────────
const SHAP_LABELS = {
    lag_1d:          { label: "Yesterday's Sales",  hint: "Revenue made yesterday. A strong day tends to carry momentum into today." },
    lag_7d:          { label: 'Same Day Last Week', hint: 'Revenue from the same weekday 7 days ago — captures weekly seasonality.' },
    rolling_mean_7d: { label: '7-Day Avg Revenue',  hint: 'Average daily revenue over the past 7 days — the model\'s baseline for "normal" performance.' },
    rolling_std_7d:  { label: '7-Day Volatility',   hint: 'How much revenue has been fluctuating. High volatility = less certain forecast.' },
    day_of_week:     { label: 'Day of the Week',    hint: 'The day tomorrow falls on (Mon–Sun). Some days are structurally busier than others.' },
    is_weekend:      { label: 'Weekend Effect',     hint: 'Whether tomorrow is Sat or Sun. Weekends often have a distinct spending pattern.' },
};

function renderShapBars(features) {
    const container = document.getElementById('shapBars');
    if (!container) return;

    // If no ML features, show a friendly fallback inside the card
    if (!features || features.length === 0) {
        showEl('shapCard');
        container.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;padding:16px 0;color:#6b7280;">
                <i class="fa-solid fa-circle-info" style="font-size:18px;color:#9ca3af"></i>
                <div>
                    <p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 3px">SHAP explanation unavailable</p>
                    <p style="font-size:12.5px;margin:0">
                        Install <code style="background:#f1f5f9;padding:1px 5px;border-radius:4px">scikit-learn</code> and 
                        <code style="background:#f1f5f9;padding:1px 5px;border-radius:4px">shap</code> on your server 
                        to enable ML-powered feature importance.
                    </p>
                </div>
            </div>`;
        return;
    }

    showEl('shapCard');
    const maxAbs = Math.max(...features.map(f => Math.abs(f.shap_value)), 0.01);

    container.innerHTML = features.map(f => {
        const pct    = Math.abs(f.shap_value) / maxAbs * 100;
        const dir    = f.shap_value >= 0 ? 'pos' : 'neg';
        const sign   = f.shap_value >= 0 ? '+' : '';
        const meta   = SHAP_LABELS[f.feature] || { label: f.feature, hint: '' };
        const impact = f.shap_value >= 0
            ? `<span class="shap-impact-tag up"><i class="fa-solid fa-arrow-up" style="font-size:9px"></i> Pushing up</span>`
            : `<span class="shap-impact-tag down"><i class="fa-solid fa-arrow-down" style="font-size:9px"></i> Pulling down</span>`;

        return `
        <div class="shap-row" title="${meta.hint}">
            <div class="shap-label-wrap">
                <span class="shap-feature-label">${meta.label}</span>
                <span class="shap-feature-raw">${f.feature}</span>
            </div>
            <div class="shap-bar-track">
                <div class="shap-bar-fill ${dir}" style="width:${pct}%"></div>
            </div>
            <div class="shap-right">
                ${impact}
                <span class="shap-val" title="${sign}${f.shap_value.toFixed(2)}">${sign}${fmtK(Math.abs(f.shap_value))}</span>
            </div>
        </div>`;
    }).join('');
}

// ── Build filter bars ────────────────────────────────────────
function buildFilters() {
    // ── Global sparkline filter ──
    const sparklineFilterHtml = `
        <div class="filter-bar" id="sparklineFilterBar">
            <span class="filter-label"><i class="fa-solid fa-sliders"></i> Period</span>
            <button class="filter-btn" data-days="7">7 Days</button>
            <button class="filter-btn" data-days="14">14 Days</button>
            <button class="filter-btn active" data-days="30">30 Days</button>
        </div>`;
    const sparklineCard = document.getElementById('sparklineChart')?.closest('.chart-card');
    if (sparklineCard) {
        const header = sparklineCard.querySelector('.chart-card-header');
        header.insertAdjacentHTML('afterend', sparklineFilterHtml);
        sparklineCard.querySelectorAll('[data-days]').forEach(btn => {
            btn.addEventListener('click', () => {
                sparklineCard.querySelectorAll('[data-days]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                sparklineDays = parseInt(btn.dataset.days);
                if (_data) renderSparkline(_data.sparkline);
            });
        });
    }

    // ── Branch metric filter ──
    const branchFilterHtml = `
        <div class="chart-filter-row" id="branchFilterRow">
            <button class="chart-filter-btn active" data-bmetric="revenue">Revenue</button>
            <button class="chart-filter-btn" data-bmetric="tx_count">Transactions</button>
            <button class="chart-filter-btn" data-bmetric="avg_ticket">Avg Ticket</button>
        </div>`;
    const branchCard = document.getElementById('branchChart')?.closest('.chart-card');
    if (branchCard) {
        const header = branchCard.querySelector('.chart-card-header');
        header.insertAdjacentHTML('afterend', branchFilterHtml);
        branchCard.querySelectorAll('[data-bmetric]').forEach(btn => {
            btn.addEventListener('click', () => {
                branchCard.querySelectorAll('[data-bmetric]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                branchMetric = btn.dataset.bmetric;
                if (_data) renderBranchChart(_data.top_branches);
            });
        });
    }

    // ── Payment metric filter ──
    const paymentFilterHtml = `
        <div class="chart-filter-row" id="paymentFilterRow">
            <button class="chart-filter-btn active" data-pmetric="revenue">Revenue</button>
            <button class="chart-filter-btn" data-pmetric="tx_count">Transactions</button>
        </div>`;
    const paymentCard = document.getElementById('paymentChart')?.closest('.chart-card');
    if (paymentCard) {
        const header = paymentCard.querySelector('.chart-card-header');
        header.insertAdjacentHTML('afterend', paymentFilterHtml);
        paymentCard.querySelectorAll('[data-pmetric]').forEach(btn => {
            btn.addEventListener('click', () => {
                paymentCard.querySelectorAll('[data-pmetric]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                paymentMetric = btn.dataset.pmetric;
                if (_data) renderPaymentChart(_data.payment_breakdown);
            });
        });
    }

    // ── Tx Trend filter ──
    const txTrendFilterHtml = `
        <div class="chart-filter-row" id="txTrendFilterRow">
            <button class="chart-filter-btn active" data-tmetric="both">Both</button>
            <button class="chart-filter-btn" data-tmetric="tx">Transactions</button>
            <button class="chart-filter-btn" data-tmetric="ticket">Avg Ticket</button>
        </div>`;
    const txTrendCard = document.getElementById('txTrendChart')?.closest('.chart-card');
    if (txTrendCard) {
        const header = txTrendCard.querySelector('.chart-card-header');
        header.insertAdjacentHTML('afterend', txTrendFilterHtml);
        txTrendCard.querySelectorAll('[data-tmetric]').forEach(btn => {
            btn.addEventListener('click', () => {
                txTrendCard.querySelectorAll('[data-tmetric]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                txTrendMetric = btn.dataset.tmetric;
                if (_data) renderTxTrendChart(_data.tx_trend);
            });
        });
    }
}

// ── Date filter helpers ──────────────────────────────────────
function dashResetDates() {
    const today = new Date();
    const from  = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('dashDateFrom').value = from.toISOString().slice(0, 10);
    document.getElementById('dashDateTo').value   = today.toISOString().slice(0, 10);
}

function dashGetDateRange() {
    return {
        date_from: document.getElementById('dashDateFrom').value,
        date_to:   document.getElementById('dashDateTo').value,
    };
}

// ── Main fetch & render ──────────────────────────────────────
async function loadDashboard() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('hidden');

    const { date_from, date_to } = dashGetDateRange();

    try {
        const res  = await fetch(`${API_PROXY}?endpoint=dashboard&preset=custom&date_from=${date_from}&date_to=${date_to}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        _data = await res.json();

        renderMetrics(_data.metrics);
        renderSparkline(_data.sparkline);

        // Update sparkline chart title to reflect the active range
        const sparklineTitle = document.querySelector('#sparklineChart')
            ?.closest('.chart-card')
            ?.querySelector('.chart-card-title');
        if (sparklineTitle) {
            sparklineTitle.innerHTML = `<i class="fa-solid fa-chart-area"></i> Sales Trend — ${date_from} to ${date_to}`;
        }

        // Update branch chart title
        const branchTitle = document.querySelector('#branchChart')
            ?.closest('.chart-card')
            ?.querySelector('.chart-card-title');
        if (branchTitle) {
            branchTitle.innerHTML = `<i class="fa-solid fa-code-branch"></i> Top Branches — ${date_from} to ${date_to}`;
        }


        renderPaymentChart(_data.payment_breakdown);
        renderBranchChart(_data.top_branches);
        renderTxTrendChart(_data.tx_trend);
        renderForecastAlert(_data.forecast_alert);
        renderShapBars(_data.forecast_alert?.shap_features ?? []);

        const label = document.getElementById('dashPeriodLabel');
        if (label) label.textContent = `${date_from} → ${date_to}`;

    } catch (err) {
        console.error('Dashboard load error:', err);
        ['valToday','valWeek','valMonth','valAvg'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { el.textContent = '—'; el.style.color = '#ef4444'; }
        });
    } finally {
        if (overlay) overlay.classList.add('hidden');
    }
}

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    setTopbarDate();
    dashResetDates();   // set default date range on load
    buildFilters();
    loadDashboard();

    // Date filter buttons
    document.getElementById('dashApplyBtn')?.addEventListener('click', () => loadDashboard());
    document.getElementById('dashResetBtn')?.addEventListener('click', () => {
        dashResetDates();
        loadDashboard();
    });

    // Refresh button
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            const icon = refreshBtn.querySelector('i');
            if (icon) {
                icon.style.animation = 'spin 0.8s linear infinite';
                setTimeout(() => { icon.style.animation = ''; }, 1200);
            }
            loadDashboard();
        });
    }
});

const _style = document.createElement('style');
_style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(_style);