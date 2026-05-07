'use strict';

const API_PROXY = '../backend/api_proxy.php';
const CURRENCY  = '₱';

const PALETTE  = ['#0f766e','#14b8a6','#f59e0b','#7c3aed','#0284c7','#dc2626','#16a34a','#d97706'];
const DONUT_PAL = ['#0f766e','#14b8a6','#f59e0b','#7c3aed','#0284c7','#16a34a','#dc2626','#d97706'];

let sparklineChart = null, paymentChart = null, branchChart = null, txTrendChart = null;
let _data = null;
let sparklineDays = 30, branchMetric = 'revenue', paymentMetric = 'revenue', txTrendMetric = 'both';
let mlEnabled = localStorage.getItem('dash_ml_enabled') !== 'false';

const fmt  = (n) => CURRENCY + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const fmtK = (n) => { if (n >= 1_000_000) return CURRENCY + (n/1_000_000).toFixed(1)+'M'; if (n >= 1_000) return CURRENCY + (n/1_000).toFixed(1)+'K'; return fmt(n); };
const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
const showEl  = (id) => { const el = document.getElementById(id); if (el) el.classList.remove('hidden'); };
const hideEl  = (id) => { const el = document.getElementById(id); if (el) el.classList.add('hidden'); };

// ── Topbar date ──────────────────────────────────────────────
function setTopbarDate() {
    const el = document.getElementById('topbarDate');
    if (el) el.textContent = new Date().toLocaleDateString('en-PH', { weekday:'short', year:'numeric', month:'short', day:'numeric' });
}

// ── ML Toggle ────────────────────────────────────────────────
function initMlToggle() {
    const btn   = document.getElementById('mlToggleBtn');
    const label = document.getElementById('mlToggleLabel');
    if (!btn) return;

    function applyState() {
        label.textContent = mlEnabled ? 'ML ON' : 'ML OFF';
        btn.classList.toggle('ml-toggle-off', !mlEnabled);
    }
    applyState();

    btn.addEventListener('click', () => {
        mlEnabled = !mlEnabled;
        localStorage.setItem('dash_ml_enabled', mlEnabled ? 'true' : 'false');
        applyState();
        loadDashboard();
    });
}

// ── Metric Cards with anomaly dots ──────────────────────────
function renderMetrics(metrics, anomalyKpi) {
    const { date_from, date_to } = dashGetDateRange();
    const today = new Date().toISOString().slice(0, 10);
    const isDefaultMonth =
        date_from === new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10) &&
        date_to === today;

    setText('valToday', fmtK(metrics.today.revenue));
    setText('subToday', metrics.today.tx + ' transactions');

    const weekLabel = document.querySelector('#cardWeek .metric-label');
    if (weekLabel) weekLabel.innerHTML = isDefaultMonth
        ? '<i class="fa-regular fa-calendar-week"></i> This Week'
        : '<i class="fa-regular fa-calendar-week"></i> This Week (in range)';
    setText('valWeek', fmtK(metrics.week.revenue));
    setText('subWeek', metrics.week.tx + ' transactions');

    const monthLabel = document.querySelector('#cardMonth .metric-label');
    if (monthLabel) monthLabel.innerHTML = isDefaultMonth
        ? '<i class="fa-regular fa-calendar"></i> This Month'
        : '<i class="fa-regular fa-calendar"></i> Selected Period';
    setText('valMonth', fmtK(metrics.month.revenue));

    const mom   = metrics.mom_change_pct;
    const badge = document.createElement('span');
    badge.className = 'metric-change ' + (mom > 0 ? 'up' : mom < 0 ? 'down' : 'flat');
    badge.innerHTML = (mom > 0 ? '<i class="fa-solid fa-arrow-trend-up"></i> +'+mom : mom < 0 ? '<i class="fa-solid fa-arrow-trend-down"></i> '+mom : '— '+mom) + '% vs prior period';
    const subMonth = document.getElementById('subMonth');
    if (subMonth) { subMonth.innerHTML = ''; subMonth.appendChild(badge); }

    const avgLabel = document.querySelector('#cardAvg .metric-label');
    if (avgLabel) avgLabel.innerHTML = isDefaultMonth
        ? '<i class="fa-solid fa-receipt"></i> Avg Ticket (Month)'
        : '<i class="fa-solid fa-receipt"></i> Avg Ticket (Period)';
    const avgTicket = metrics.month.tx > 0 ? metrics.month.revenue / metrics.month.tx : 0;
    setText('valAvg', fmtK(avgTicket));
    setText('subAvg', 'per transaction');

    // Anomaly dots
    if (anomalyKpi && mlEnabled) {
        _setAnomalyDot('cardToday', anomalyKpi.today);
        _setAnomalyDot('cardWeek',  anomalyKpi.week);
        _setAnomalyDot('cardMonth', anomalyKpi.month);
        _setAnomalyDot('cardAvg',   anomalyKpi.avg);
    } else {
        ['cardToday','cardWeek','cardMonth','cardAvg'].forEach(id => _removeAnomalyDot(id));
    }
}

function _setAnomalyDot(cardId, hasAnomaly) {
    const card = document.getElementById(cardId);
    if (!card) return;
    _removeAnomalyDot(cardId);
    const dot = document.createElement('span');
    dot.className = 'anomaly-dot ' + (hasAnomaly ? 'anomaly-dot--red' : 'anomaly-dot--green');
    dot.title = hasAnomaly ? '⚠ Anomaly detected in last 24h' : 'No anomalies detected';
    card.style.position = 'relative';
    card.appendChild(dot);
}

function _removeAnomalyDot(cardId) {
    document.getElementById(cardId)?.querySelectorAll('.anomaly-dot').forEach(d => d.remove());
}

// ── Sparkline with LSTM overlay ──────────────────────────────
function renderSparkline(data, lstmForecast) {
    const sliced   = data.slice(-sparklineDays);
    const labels   = sliced.map(d => new Date(d.date).toLocaleDateString('en-PH', { month:'short', day:'numeric' }));
    const revenues = sliced.map(d => d.revenue);

    const ctx = document.getElementById('sparklineChart');
    if (!ctx) return;
    if (sparklineChart) sparklineChart.destroy();

    const grd = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
    grd.addColorStop(0, 'rgba(20,184,166,0.22)');
    grd.addColorStop(1, 'rgba(20,184,166,0.00)');

    const datasets = [{
        label: 'Revenue',
        data: revenues,
        borderColor: '#0f766e',
        borderWidth: 2.5,
        fill: true,
        backgroundColor: grd,
        pointRadius: 0,
        pointHitRadius: 12,
        tension: 0.38,
    }];

    // LSTM forecast overlay
    if (mlEnabled && lstmForecast?.available && lstmForecast.next_7_days?.length) {
        const forecastPts = lstmForecast.next_7_days;
        const anchorIdx   = sliced.length - 1;

        // Extend x-axis labels with forecast dates
        forecastPts.forEach(p => {
            labels.push(new Date(p.date).toLocaleDateString('en-PH', { month:'short', day:'numeric' }));
        });

        // Pad with nulls up to anchor, then anchor value, then forecasts
        const pad       = new Array(anchorIdx).fill(null);
        const anchor    = revenues[anchorIdx];
        const lstmData  = [...pad, anchor, ...forecastPts.map(p => p.predicted)];
        const upperData = [...pad, anchor, ...forecastPts.map(p => p.upper)];
        const lowerData = [...pad, anchor, ...forecastPts.map(p => p.lower)];

        // Upper band (invisible border, used as fill target)
        datasets.push({
            label: 'Confidence Upper',
            data: upperData,
            borderColor: 'transparent',
            borderWidth: 0,
            fill: false,
            pointRadius: 0,
            tension: 0.3,
            spanGaps: true,
        });
        // Lower band fills up to Upper
        datasets.push({
            label: 'Confidence Lower',
            data: lowerData,
            borderColor: 'transparent',
            backgroundColor: 'rgba(124,58,237,0.10)',
            borderWidth: 0,
            fill: '-1',
            pointRadius: 0,
            tension: 0.3,
            spanGaps: true,
        });
        // Forecast line — segment.borderDash required in Chart.js v4
        datasets.push({
            label: 'LSTM Forecast',
            data: lstmData,
            borderColor: '#7c3aed',
            borderWidth: 2.5,
            segment: { borderDash: () => [6, 4] },
            pointRadius: (ctx) => ctx.dataIndex > anchorIdx ? 3 : 0,
            pointBackgroundColor: '#7c3aed',
            fill: false,
            tension: 0.3,
            spanGaps: true,
        });
    }

    sparklineChart = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: mlEnabled && lstmForecast?.available,
                    position: 'top', align: 'end',
                    labels: {
                        font: { family: 'DM Sans', size: 11 }, color: '#9ca3af',
                        boxWidth: 10, boxHeight: 10, padding: 12, usePointStyle: true,
                        filter: (item) => !['Confidence Upper','Confidence Lower'].includes(item.text),
                    }
                },
                tooltip: {
                    backgroundColor: '#111827', titleColor: '#9ca3af', bodyColor: '#ffffff',
                    titleFont: { family: 'DM Mono', size: 11 }, bodyFont: { family: 'DM Sans', size: 12 },
                    padding: 10,
                    callbacks: {
                        title: (items) => items[0].label,
                        label: (item) => {
                            if (['Confidence Upper','Confidence Lower'].includes(item.dataset.label)) return null;
                            const prefix = item.dataset.label === 'LSTM Forecast' ? '  Forecast: ' : '  Revenue: ';
                            return item.raw !== null ? prefix + fmt(item.raw) : null;
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: '#9ca3af', font: { family: 'DM Sans', size: 11 }, maxTicksLimit: 10, maxRotation: 0 } },
                y: { grid: { color: '#f0f5f4' }, border: { display: false, dash: [4,4] }, ticks: { color: '#9ca3af', font: { family: 'DM Mono', size: 11 }, callback: (v) => fmtK(v), maxTicksLimit: 5 } }
            }
        }
    });
}

// ── Payment Donut ────────────────────────────────────────────
function renderPaymentChart(data) {
    const ctx = document.getElementById('paymentChart');
    if (!ctx) return;
    const labels = data.map(d => d.method);
    const values = paymentMetric === 'revenue' ? data.map(d => d.revenue) : data.map(d => d.tx_count);
    const total  = values.reduce((s, v) => s + v, 0);
    if (paymentChart) paymentChart.destroy();
    paymentChart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: DONUT_PAL.slice(0, data.length), borderWidth: 3, borderColor: '#ffffff', hoverOffset: 8 }] },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '70%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111827', titleColor: '#9ca3af', bodyColor: '#ffffff',
                    titleFont: { family: 'DM Mono', size: 11 }, bodyFont: { family: 'DM Sans', size: 12 }, padding: 10,
                    callbacks: { label: (item) => { const pct = total > 0 ? ((item.raw/total)*100).toFixed(1) : 0; const val = paymentMetric === 'revenue' ? fmt(item.raw) : item.raw+' txns'; return '  '+val+'  ('+pct+'%)'; } }
                }
            }
        }
    });
    const legendEl = document.getElementById('paymentLegend');
    if (legendEl) {
        legendEl.innerHTML = '';
        data.forEach((d, i) => {
            const val = paymentMetric === 'revenue' ? d.revenue : d.tx_count;
            const pct = total > 0 ? ((val/total)*100).toFixed(1) : '0.0';
            legendEl.innerHTML += `<div class="payment-legend-item"><span class="payment-legend-dot" style="background:${DONUT_PAL[i]}"></span><span class="payment-legend-name">${d.method}</span><span class="payment-legend-pct">${pct}%</span></div>`;
        });
    }
}

// ── Branch Bar Chart ─────────────────────────────────────────
function renderBranchChart(data) {
    const ctx = document.getElementById('branchChart');
    if (!ctx) return;
    const sorted = [...data].sort((a, b) => b[branchMetric] - a[branchMetric]).slice(0, 8);
    const labels  = sorted.map(d => d.name.length > 20 ? d.name.substring(0, 18)+'…' : d.name);
    const values  = sorted.map(d => d[branchMetric]);
    const labelMap = { revenue: 'Revenue', tx_count: 'Transactions', avg_ticket: 'Avg Ticket' };
    const colors = sorted.map((_, i) => `rgba(15,118,110,${1 - (i/sorted.length)*0.45})`);
    if (branchChart) branchChart.destroy();
    branchChart = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [{ label: labelMap[branchMetric], data: values, backgroundColor: colors, borderRadius: 6, borderSkipped: false, hoverBackgroundColor: '#0f766e' }] },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#111827', titleColor: '#9ca3af', bodyColor: '#ffffff', titleFont: { family: 'DM Mono', size: 11 }, bodyFont: { family: 'DM Sans', size: 12 }, padding: 10, callbacks: { label: (item) => '  ' + (branchMetric === 'tx_count' ? item.raw+' txns' : fmt(item.raw)) } } },
            scales: { x: { grid: { color: '#f0f5f4' }, border: { display: false }, ticks: { color: '#9ca3af', font: { family: 'DM Mono', size: 11 }, callback: (v) => branchMetric === 'tx_count' ? v : fmtK(v), maxTicksLimit: 5 } }, y: { grid: { display: false }, border: { display: false }, ticks: { color: '#374151', font: { family: 'DM Sans', size: 11.5, weight: '500' } } } }
        }
    });
}

// ── Tx Trend Dual-axis ───────────────────────────────────────
function renderTxTrendChart(data) {
    const ctx = document.getElementById('txTrendChart');
    if (!ctx) return;
    const labels     = data.map(d => 'Wk ' + new Date(d.week).toLocaleDateString('en-PH', { month:'short', day:'numeric' }));
    const txCounts   = data.map(d => d.tx_count);
    const avgTickets = data.map(d => d.avg_ticket);
    if (txTrendChart) txTrendChart.destroy();
    const datasets = [];
    if (txTrendMetric === 'both' || txTrendMetric === 'tx') datasets.push({ label: 'Transactions', data: txCounts, backgroundColor: 'rgba(20,184,166,0.18)', borderColor: '#14b8a6', borderWidth: 1, borderRadius: 5, borderSkipped: false, yAxisID: 'yLeft', order: 2 });
    if (txTrendMetric === 'both' || txTrendMetric === 'ticket') datasets.push({ label: 'Avg Ticket', data: avgTickets, type: 'line', borderColor: '#f59e0b', borderWidth: 2.5, pointRadius: 4, pointBackgroundColor: '#f59e0b', pointBorderColor: '#fff', pointBorderWidth: 2, fill: false, tension: 0.3, yAxisID: txTrendMetric === 'ticket' ? 'yLeft' : 'yRight', order: 1 });
    txTrendChart = new Chart(ctx, {
        type: 'bar', data: { labels, datasets },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true, position: 'top', align: 'end', labels: { font: { family: 'DM Sans', size: 11 }, color: '#9ca3af', boxWidth: 10, boxHeight: 10, padding: 12, usePointStyle: true } }, tooltip: { backgroundColor: '#111827', titleColor: '#9ca3af', bodyColor: '#ffffff', titleFont: { family: 'DM Mono', size: 11 }, bodyFont: { family: 'DM Sans', size: 12 }, padding: 10, callbacks: { label: (item) => item.dataset.label === 'Avg Ticket' ? '  Avg Ticket: '+fmt(item.raw) : '  Transactions: '+item.raw } } },
            scales: { x: { grid: { display: false }, border: { display: false }, ticks: { color: '#9ca3af', font: { family: 'DM Sans', size: 10.5 }, maxRotation: 0 } }, yLeft: { position: 'left', grid: { color: '#f0f5f4' }, border: { display: false }, ticks: { color: '#9ca3af', font: { family: 'DM Mono', size: 11 }, maxTicksLimit: 5, callback: (v) => txTrendMetric === 'ticket' ? fmtK(v) : v } }, yRight: { position: 'right', display: txTrendMetric === 'both', grid: { drawOnChartArea: false }, border: { display: false }, ticks: { color: '#9ca3af', font: { family: 'DM Mono', size: 11 }, callback: (v) => fmtK(v), maxTicksLimit: 5 } } }
        }
    });
}

// ── Legacy Forecast Alert ────────────────────────────────────
function renderForecastAlert(alert) {
    if (!alert?.alert_type) return;
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

// ── Hybrid Alert Card ────────────────────────────────────────
function renderHybridAlert(hybrid) {
    const card = document.getElementById('hybridAlertCard');
    if (!card || !hybrid?.direction) return;

    if (!mlEnabled) { hideEl('hybridAlertCard'); return; }

    const dirIcon  = { surge: '↑', dip: '↓', stable: '→' };
    const dirTitle = { surge: 'Revenue Surge Expected Tomorrow', dip: 'Revenue Dip Expected Tomorrow', stable: 'Revenue Stable Tomorrow' };
    const confCls  = { HIGH: 'conf-high', MEDIUM: 'conf-medium', LOW: 'conf-low' };

    document.getElementById('hybridDirectionIcon').textContent = dirIcon[hybrid.direction] || '→';
    document.getElementById('hybridAlertTitle').textContent    = dirTitle[hybrid.direction] || 'Forecast';
    document.getElementById('hybridAlertSub').textContent      =
        `${hybrid.magnitude_pct}% vs 7-day avg · Anomaly rate ${(hybrid.anomaly_rate * 100).toFixed(1)}% · Models: ${hybrid.models_used}`;

    const confBadge = document.getElementById('hybridConfidenceBadge');
    confBadge.textContent = (hybrid.confidence || 'LOW') + ' CONFIDENCE';
    confBadge.className   = 'hybrid-confidence-badge ' + (confCls[hybrid.confidence] || 'conf-low');

    const modelBadge = document.getElementById('hybridModelBadge');
    modelBadge.textContent = 'Ensemble · ' + (hybrid.models_used || '—');

    card.className = 'hybrid-alert-card ' + (hybrid.direction || 'stable');
    showEl('hybridAlertCard');
}

// ── Model Status Mini-card ───────────────────────────────────
function renderModelStatus(models) {
    const grid = document.getElementById('modelStatusGrid');
    const card = document.getElementById('modelStatusCard');
    if (!grid || !card) return;
    if (!mlEnabled || !models?.length) { hideEl('modelStatusCard'); return; }

    // Only show active models, or fall back to all if none are active
    const active = models.filter(m => m.is_active);
    const display = active.length ? active : models;

    const typeIcon = { lstm: '', xgb: '', rf: '' };
    const typeName = { lstm: 'LSTM', xgb: 'XGBoost', rf: 'Random Forest' };

    grid.innerHTML = display.map(m => {
        const trained = m.last_trained_at
            ? new Date(m.last_trained_at).toLocaleString('en-PH', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' })
            : 'Not yet trained';

        // Key metric display (rmse for LSTM, accuracy for XGB/RF)
        let metricHtml = '';
        if (m.key_metric && m.key_metric_value != null) {
            const val = m.key_metric === 'rmse' || m.key_metric === 'mae'
                ? '₱' + Number(m.key_metric_value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : (m.key_metric_value * 100).toFixed(1) + '%';
            metricHtml += `<span class="ms-metric" title="${m.key_metric.toUpperCase()}">${m.key_metric.toUpperCase()} ${val}</span>`;
        }
        if (m.f1_score) {
            metricHtml += `<span class="ms-metric" title="F1 Score">F1 ${(m.f1_score).toFixed(3)}</span>`;
        }
        if (!metricHtml) metricHtml = `<span class="ms-metric">No metrics yet</span>`;

        const taskLabel = m.task_type
            ? `<span class="ms-task">${m.task_type.replace(/_/g,' ')}</span>`
            : '';
        const modelLabel = m.model_type
            ? `<span class="ms-algo">${typeIcon[m.model_type] || ''} ${typeName[m.model_type] || m.model_type}</span>`
            : '';
        const dot = m.is_active
            ? '<span class="ms-dot ms-dot--active" title="Active"></span>'
            : '<span class="ms-dot ms-dot--inactive" title="Inactive"></span>';

        return `
        <div class="ms-item">
            ${dot}
            <div class="ms-info">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span class="ms-name">${m.model_name}</span>
                    ${modelLabel}
                    ${taskLabel}
                </div>
                <span class="ms-trained">Last trained: ${trained}</span>
            </div>
            <div class="ms-metrics">${metricHtml}</div>
        </div>`;
    }).join('');

    showEl('modelStatusCard');
}

// ── SHAP label map & bars ────────────────────────────────────
const SHAP_LABELS = {
    lag_1d:          { label: "Yesterday's Sales",  hint: "Revenue made yesterday." },
    lag_7d:          { label: 'Same Day Last Week', hint: 'Revenue from the same weekday 7 days ago.' },
    rolling_mean_7d: { label: '7-Day Avg Revenue',  hint: "The model's baseline for normal performance." },
    rolling_std_7d:  { label: '7-Day Volatility',   hint: 'How much revenue has been fluctuating.' },
    day_of_week:     { label: 'Day of the Week',    hint: 'The day tomorrow falls on.' },
    is_weekend:      { label: 'Weekend Effect',     hint: 'Whether tomorrow is Sat or Sun.' },
};

function renderShapBars(features) {
    const container = document.getElementById('shapBars');
    if (!container) return;
    if (!features || features.length === 0) {
        showEl('shapCard');
        container.innerHTML = `<div style="display:flex;align-items:center;gap:12px;padding:16px 0;color:#6b7280;"><i class="fa-solid fa-circle-info" style="font-size:18px;color:#9ca3af"></i><div><p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 3px">SHAP explanation unavailable</p><p style="font-size:12.5px;margin:0">Install <code style="background:#f1f5f9;padding:1px 5px;border-radius:4px">scikit-learn</code> and <code style="background:#f1f5f9;padding:1px 5px;border-radius:4px">shap</code> to enable ML-powered feature importance.</p></div></div>`;
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
        return `<div class="shap-row" title="${meta.hint}"><div class="shap-label-wrap"><span class="shap-feature-label">${meta.label}</span><span class="shap-feature-raw">${f.feature}</span></div><div class="shap-bar-track"><div class="shap-bar-fill ${dir}" style="width:${pct}%"></div></div><div class="shap-right">${impact}<span class="shap-val" title="${sign}${f.shap_value.toFixed(2)}">${sign}${fmtK(Math.abs(f.shap_value))}</span></div></div>`;
    }).join('');
}

// ── Filter bars ──────────────────────────────────────────────
function buildFilters() {
    const sparklineCard = document.getElementById('sparklineChart')?.closest('.chart-card');
    if (sparklineCard) {
        sparklineCard.querySelector('.chart-card-header').insertAdjacentHTML('afterend', `<div class="filter-bar" id="sparklineFilterBar"><span class="filter-label"><i class="fa-solid fa-sliders"></i> Period</span><button class="filter-btn" data-days="7">7 Days</button><button class="filter-btn" data-days="14">14 Days</button><button class="filter-btn active" data-days="30">30 Days</button></div>`);
        sparklineCard.querySelectorAll('[data-days]').forEach(btn => btn.addEventListener('click', () => {
            sparklineCard.querySelectorAll('[data-days]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            sparklineDays = parseInt(btn.dataset.days);
            if (_data) renderSparkline(_data.sparkline, _data.lstm_forecast);
        }));
    }

    const branchCard = document.getElementById('branchChart')?.closest('.chart-card');
    if (branchCard) {
        branchCard.querySelector('.chart-card-header').insertAdjacentHTML('afterend', `<div class="chart-filter-row" id="branchFilterRow"><button class="chart-filter-btn active" data-bmetric="revenue">Revenue</button><button class="chart-filter-btn" data-bmetric="tx_count">Transactions</button><button class="chart-filter-btn" data-bmetric="avg_ticket">Avg Ticket</button></div>`);
        branchCard.querySelectorAll('[data-bmetric]').forEach(btn => btn.addEventListener('click', () => {
            branchCard.querySelectorAll('[data-bmetric]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            branchMetric = btn.dataset.bmetric;
            if (_data) renderBranchChart(_data.top_branches);
        }));
    }

    const paymentCard = document.getElementById('paymentChart')?.closest('.chart-card');
    if (paymentCard) {
        paymentCard.querySelector('.chart-card-header').insertAdjacentHTML('afterend', `<div class="chart-filter-row" id="paymentFilterRow"><button class="chart-filter-btn active" data-pmetric="revenue">Revenue</button><button class="chart-filter-btn" data-pmetric="tx_count">Transactions</button></div>`);
        paymentCard.querySelectorAll('[data-pmetric]').forEach(btn => btn.addEventListener('click', () => {
            paymentCard.querySelectorAll('[data-pmetric]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            paymentMetric = btn.dataset.pmetric;
            if (_data) renderPaymentChart(_data.payment_breakdown);
        }));
    }

    const txTrendCard = document.getElementById('txTrendChart')?.closest('.chart-card');
    if (txTrendCard) {
        txTrendCard.querySelector('.chart-card-header').insertAdjacentHTML('afterend', `<div class="chart-filter-row" id="txTrendFilterRow"><button class="chart-filter-btn active" data-tmetric="both">Both</button><button class="chart-filter-btn" data-tmetric="tx">Transactions</button><button class="chart-filter-btn" data-tmetric="ticket">Avg Ticket</button></div>`);
        txTrendCard.querySelectorAll('[data-tmetric]').forEach(btn => btn.addEventListener('click', () => {
            txTrendCard.querySelectorAll('[data-tmetric]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            txTrendMetric = btn.dataset.tmetric;
            if (_data) renderTxTrendChart(_data.tx_trend);
        }));
    }
}

// ── Date helpers ─────────────────────────────────────────────
function dashResetDates() {
    const today = new Date();
    const from  = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('dashDateFrom').value = from.toISOString().slice(0, 10);
    document.getElementById('dashDateTo').value   = today.toISOString().slice(0, 10);
}
function dashGetDateRange() {
    return { date_from: document.getElementById('dashDateFrom').value, date_to: document.getElementById('dashDateTo').value };
}

// ── Main fetch & render ──────────────────────────────────────
async function loadDashboard() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('hidden');
    const { date_from, date_to } = dashGetDateRange();
    try {
        const res = await fetch(`${API_PROXY}?endpoint=dashboard&preset=custom&date_from=${date_from}&date_to=${date_to}&ml_enabled=${mlEnabled}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        _data = await res.json();

        renderMetrics(_data.metrics, _data.anomaly_kpi);
        renderSparkline(_data.sparkline, _data.lstm_forecast);

        const sparklineTitle = document.querySelector('#sparklineChart')?.closest('.chart-card')?.querySelector('.chart-card-title');
        if (sparklineTitle) sparklineTitle.innerHTML = `<i class="fa-solid fa-chart-area"></i> Sales Trend — ${date_from} to ${date_to}`;

        const branchTitle = document.querySelector('#branchChart')?.closest('.chart-card')?.querySelector('.chart-card-title');
        if (branchTitle) branchTitle.innerHTML = `<i class="fa-solid fa-code-branch"></i> Top Branches — ${date_from} to ${date_to}`;

        renderPaymentChart(_data.payment_breakdown);
        renderBranchChart(_data.top_branches);
        renderTxTrendChart(_data.tx_trend);
        renderForecastAlert(_data.forecast_alert);
        renderShapBars(_data.forecast_alert?.shap_features ?? []);
        renderHybridAlert(_data.hybrid_alert);
        renderModelStatus(_data.model_status);

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
    initMlToggle();
    dashResetDates();
    buildFilters();
    loadDashboard();

    document.getElementById('dashApplyBtn')?.addEventListener('click', () => loadDashboard());
    document.getElementById('dashResetBtn')?.addEventListener('click', () => { dashResetDates(); loadDashboard(); });

    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            const icon = refreshBtn.querySelector('i');
            if (icon) { icon.style.animation = 'spin 0.8s linear infinite'; setTimeout(() => { icon.style.animation = ''; }, 1200); }
            loadDashboard();
        });
    }
});

const _style = document.createElement('style');
_style.textContent = `
@keyframes spin { to { transform: rotate(360deg); } }

/* ML Toggle */
.ml-toggle-btn { display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:5px 10px;border-radius:6px;background:rgba(124,58,237,0.1);color:#7c3aed;border:1px solid rgba(124,58,237,0.25); }
.ml-toggle-btn.ml-toggle-off { background:rgba(156,163,175,0.1);color:#9ca3af;border-color:rgba(156,163,175,0.25); }

/* Anomaly dots */
.anomaly-dot { position:absolute;top:10px;right:10px;width:9px;height:9px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,0.08); }
.anomaly-dot--green { background:#16a34a; }
.anomaly-dot--red   { background:#dc2626;animation:pulse-red 1.6s infinite; }
@keyframes pulse-red { 0%,100%{box-shadow:0 0 0 1px rgba(220,38,38,0.3)} 50%{box-shadow:0 0 0 4px rgba(220,38,38,0.15)} }

/* Hybrid Alert Card */
.hybrid-alert-card { display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 18px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;margin-bottom:14px;transition:border-color .2s; }
.hybrid-alert-card.surge { border-color:rgba(22,163,74,0.35);background:rgba(22,163,74,0.04); }
.hybrid-alert-card.dip   { border-color:rgba(220,38,38,0.35);background:rgba(220,38,38,0.04); }
.hybrid-alert-card.stable{ border-color:rgba(59,130,246,0.3);background:rgba(59,130,246,0.03); }
.hybrid-alert-left  { display:flex;align-items:center;gap:12px; }
.hybrid-alert-right { display:flex;flex-direction:column;align-items:flex-end;gap:6px; }
.hybrid-model-badge { font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;background:#7c3aed;color:#fff;padding:2px 8px;border-radius:20px; }
.hybrid-direction-icon { font-size:22px;font-weight:700;color:#374151; }
.hybrid-alert-title { font-size:13.5px;font-weight:700;color:#111827;margin:0 0 2px; }
.hybrid-alert-sub   { font-size:11.5px;color:#6b7280;margin:0; }
.hybrid-confidence-badge { font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.04em; }
.conf-high   { background:#dcfce7;color:#15803d; }
.conf-medium { background:#fef9c3;color:#854d0e; }
.conf-low    { background:#f1f5f9;color:#64748b; }
.hybrid-detail-link { font-size:11.5px;color:#0f766e;text-decoration:none;display:flex;align-items:center;gap:4px; }
.hybrid-detail-link:hover { text-decoration:underline; }

/* Model Status Card */
.model-status-card { margin-bottom:16px; }
.model-status-grid { display:flex;flex-direction:column;gap:10px;padding:8px 0; }
.ms-item  { display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;background:#f9fafb;border:1px solid #f0f5f4; }
.ms-dot   { width:9px;height:9px;border-radius:50%;flex-shrink:0; }
.ms-dot--active   { background:#16a34a; }
.ms-dot--inactive { background:#9ca3af; }
.ms-info  { flex:1;display:flex;flex-direction:column;gap:3px; }
.ms-name  { font-size:12.5px;font-weight:600;color:#111827; }
.ms-algo  { font-size:10.5px;font-weight:600;color:#7c3aed;background:rgba(124,58,237,0.08);padding:1px 7px;border-radius:20px; }
.ms-task  { font-size:10.5px;color:#0f766e;background:rgba(15,118,110,0.08);padding:1px 7px;border-radius:20px;text-transform:capitalize; }
.ms-trained { font-size:11px;color:#9ca3af; }
.ms-metrics { display:flex;gap:6px;flex-wrap:wrap; }
.ms-metric  { font-size:11px;color:#6b7280;background:#fff;padding:2px 8px;border-radius:6px;border:1px solid #e5e7eb;white-space:nowrap; }
`;
document.head.appendChild(_style);