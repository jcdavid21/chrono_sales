<?php
$pages = [
    [
        'name'     => 'Dashboard',
        'slug'     => 'dashboard',
        'icon'     => 'fa-solid fa-gauge',
        'desc'     => 'Real-time snapshot of sales performance, revenue, and key alerts at a glance.',
        'features' => [
            'Total revenue today / this week / this month',
            'Sales trend sparkline for the last 30 days',
            'Top-performing branches ranked by revenue',
            'Payment method breakdown across all types',
            'Forecast alert: upcoming surge or dip detected',
        ],
    ],
    [
        'name'     => 'Sales Analytics',
        'slug'     => 'sales-analytics',
        'icon'     => 'fa-solid fa-chart-line',
        'desc'     => 'Deep-dive into historical sales data. Filter by branch, date range, payment method, or discount type.',
        'features' => [
            'Date range picker — daily, weekly, monthly, custom',
            'Revenue bar chart grouped by branch',
            'Sales heatmap by day-of-week and hour',
            'Discount type vs final revenue impact',
            'Top 10 customers by total spend',
            'Export to CSV or PDF',
        ],
    ],
    [
        'name'     => 'Forecast',
        'slug'     => 'forecast',
        'icon'     => 'fa-solid fa-wand-magic',
        'desc'     => 'Predicted sales and revenue for the next 7, 14, and 30 days using hybrid time-series models.',
        'features' => [
            'Forecasted revenue chart with confidence interval bands',
            'Model selector — Moving Average, Exponential Smoothing, ARIMA',
            'Best-model recommendation with MAE and RMSE scores',
            'Branch-level forecast filtered per location',
            'What-if slider to adjust expected growth rate',
            'Forecast export for planning documents',
        ],
    ],
    [
        'name'     => 'Branch Performance',
        'slug'     => 'branch-performance',
        'icon'     => 'fa-solid fa-code-branch',
        'desc'     => 'Compare all branches side by side. Identify growth, at-risk locations, and margin impact of discounts.',
        'features' => [
            'Comparison table — revenue, transaction count, avg ticket',
            'Branch revenue share donut chart',
            'Discount value vs grand total per branch',
            'Month-over-month growth rate per branch',
            'Branches with declining 30-day trend flagged',
            'Click-through to branch-level transaction list',
        ],
    ],
    [
        'name'     => 'Payment Insights',
        'slug'     => 'payment-insights',
        'icon'     => 'fa-solid fa-credit-card',
        'desc'     => 'Understand how customers pay across Cash, Card, QR, Bank Transfer, Check, and Multi-payment.',
        'features' => [
            'Payment method share and trend over time',
            'QR app breakdown — GCash, Maya, Instapay',
            'Card terminal type analysis',
            'Multi-payment combination frequency map',
            'Average transaction value by payment method',
            'Voided transaction tracking',
        ],
    ],
    [
        'name'     => 'Customer Insights',
        'slug'     => 'customer-insights',
        'icon'     => 'fa-solid fa-users',
        'desc'     => 'Track repeat buyers, high-value accounts, and purchase patterns across locations.',
        'features' => [
            'Customer list with total spend and visit count',
            'Top customers leaderboard by revenue contribution',
            'New vs returning customer ratio',
            'Average spend per customer per visit',
            'Customer spending heatmap by branch',
            'Search and filter by name, branch, or amount',
        ],
    ],
    [
        'name'     => 'Reports',
        'slug'     => 'reports',
        'icon'     => 'fa-solid fa-file-lines',
        'desc'     => 'Generate and export formal business reports for any date range, suitable for management or accountants.',
        'features' => [
            'Monthly, quarterly, and annual revenue summaries',
            'VAT summary report per branch and period',
            'Discount cost report — total discount value given',
            'Printable PDF report with branding',
            'Scheduled report delivery by email',
            'Comparison report — this period vs last period',
        ],
    ],
    [
        'name' => 'Data Management',
        'slug' => 'data-management',
        'icon' => 'fa-solid fa-database',
        'desc' => 'Admin interface for managing sales data, including manual adjustments and data integrity checks.',
        'features' => [
            'Manual transaction entry form for corrections',
            'Bulk upload of transactions via CSV',
            'Data validation rules and error reporting',
            'Audit log of all data changes with user attribution',
            'Automated integrity checks for missing or duplicate transactions',
            'Export raw transaction data for external analysis',
        ],
    ],
    [
        'name'     => 'Settings',
        'slug'     => 'settings',
        'icon'     => 'fa-solid fa-gear',
        'desc'     => 'System configuration for admins. Manage branches, users, forecasting preferences, and alert rules.',
        'features' => [
            'Branch management — add, edit, deactivate',
            'User roles — Admin, Analyst, Viewer',
            'Forecasting model preference and retraining schedule',
            'Alert rules for revenue drop thresholds',
            'Data import for new Sales_Report.xlsx files',
            'Audit log of all user actions and exports',
        ],
    ],
];


$activePage = null;
foreach ($pages as $page) {
    if ($page['slug'] === $current) {
        $activePage = $page;
        break;
    }
}
if (!$activePage) {
    $activePage = $pages[0];
    $current    = $pages[0]['slug'];
}
?>
<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <i class="fa-solid fa-chart-bar"></i>
        <div class="brand-text">
            <span class="brand-name">ChronoSales</span>
            <span class="brand-sub">Intelligence</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <p class="nav-group-label">Main Menu</p>
        <?php foreach ($pages as $page): ?>
            <?php $isActive = ($page['slug'] === $current); ?>
           <a href="index.php?page=<?= $page['slug'] ?>"
               class="nav-item<?= $isActive ? ' active' : '' ?>">
                <i class="<?= $page['icon'] ?> nav-icon"></i>
                <span class="nav-text"><?= htmlspecialchars($page['name']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-avatar">A</div>
        <div class="user-meta">
            <span class="user-name">Admin User</span>
            <span class="user-role">Administrator</span>
        </div>
        <a href="../backend/logout.php" class="logout-btn" title="Logout">
            <i class="fa-solid fa-right-from-bracket"></i>
        </a>
    </div>
</aside>