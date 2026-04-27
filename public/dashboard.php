<?php
$current = 'dashboard';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header('Location: ../index.php');
    exit;
}

$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — ChronoSales</title>
        <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">

</head>
<body>

<div class="app">

    <?php include 'sidebar.php'; ?>

    <div class="main" id="main">

        <!-- Topbar -->
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="topbar-breadcrumb">
                <i class="fa-solid fa-gauge"></i>
                <span>Dashboard</span>
            </div>

            <div class="topbar-right">
                <div class="topbar-date" id="topbarDate"></div>
                <button class="topbar-btn" id="refreshBtn" title="Refresh data">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>
        </header>

        <!-- Dashboard content -->
        <div class="content" id="dashboardContent">

            <!-- Loading overlay -->
            <div class="loading-overlay" id="loadingOverlay">
                <div class="loading-spinner">
                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                    <span>Loading analytics...</span>
                </div>
            </div>

            <!-- Forecast Alert banner -->
            <div class="alert-banner hidden" id="alertBanner">
                <div class="alert-icon-wrap">
                    <i class="fa-solid fa-triangle-exclamation" id="alertIcon"></i>
                </div>
                <div class="alert-body">
                    <p class="alert-title" id="alertTitle">Forecast Alert</p>
                    <p class="alert-msg"   id="alertMsg"></p>
                </div>
                <div class="alert-badge-wrap">
                    <span class="alert-ml-badge" id="alertMlBadge"></span>
                </div>
            </div>

            <!-- Metric cards -->
            <div class="metrics-grid">
                <div class="metric-card" id="cardToday">
                    <div class="metric-label">
                        <i class="fa-regular fa-calendar-day"></i> Today's Revenue
                    </div>
                    <div class="metric-value" id="valToday">—</div>
                    <div class="metric-sub"   id="subToday">— transactions</div>
                </div>
                <div class="metric-card" id="cardWeek">
                    <div class="metric-label">
                        <i class="fa-regular fa-calendar-week"></i> This Week
                    </div>
                    <div class="metric-value" id="valWeek">—</div>
                    <div class="metric-sub"   id="subWeek">— transactions</div>
                </div>
                <div class="metric-card accent" id="cardMonth">
                    <div class="metric-label">
                        <i class="fa-regular fa-calendar"></i> This Month
                    </div>
                    <div class="metric-value" id="valMonth">—</div>
                    <div class="metric-sub"   id="subMonth">vs last month</div>
                </div>
                <div class="metric-card" id="cardAvg">
                    <div class="metric-label">
                        <i class="fa-solid fa-receipt"></i> Avg Ticket (Month)
                    </div>
                    <div class="metric-value" id="valAvg">—</div>
                    <div class="metric-sub"   id="subAvg">per transaction</div>
                </div>
            </div>

            <!-- Charts row 1 -->
            <div class="charts-row">

                <!-- Sparkline -->
                <div class="chart-card wide">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-chart-area"></i>
                            Sales Trend — Last 30 Days
                        </div>
                        <div class="chart-legend">
                            <span class="leg-dot" style="background:#374151"></span> Revenue
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="sparklineChart"></canvas>
                    </div>
                </div>

                <!-- Payment breakdown -->
                <div class="chart-card narrow">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-credit-card"></i>
                            Payment Methods
                        </div>
                    </div>
                    <div class="chart-wrap donut-wrap">
                        <canvas id="paymentChart"></canvas>
                    </div>
                    <div class="payment-legend" id="paymentLegend"></div>
                </div>

            </div>

            <!-- Charts row 2 -->
            <div class="charts-row">

                <!-- Top branches -->
                <div class="chart-card mid">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-code-branch"></i>
                            Top Branches — This Month
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="branchChart"></canvas>
                    </div>
                </div>

                <!-- Tx count vs avg ticket -->
                <div class="chart-card mid">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-arrow-trend-up"></i>
                            Transactions vs Avg Ticket
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="txTrendChart"></canvas>
                    </div>
                </div>

            </div>

            <!-- SHAP explanation -->
            <div class="chart-card shap-card hidden" id="shapCard">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <i class="fa-solid fa-wand-magic"></i>
                        Forecast Drivers — SHAP Feature Importance
                    </div>
                    <span class="shap-badge">ML-powered</span>
                </div>
                <p class="shap-desc">
                    SHAP values show which factors pushed tomorrow's revenue prediction above or below the baseline.
                    Positive values push revenue up; negative values pull it down.
                </p>
                <div class="shap-bars" id="shapBars"></div>
            </div>

            <!-- Quick links -->
            <div class="quick-links">
                <p class="quick-links-label">Quick Actions</p>
                <div class="quick-links-row">
                    <a href="?page=reports"  class="quick-link">
                        <i class="fa-solid fa-file-lines"></i>
                        <span>View Reports</span>
                        <i class="fa-solid fa-arrow-right quick-arrow"></i>
                    </a>
                    <a href="?page=forecast" class="quick-link">
                        <i class="fa-solid fa-wand-magic"></i>
                        <span>Open Forecast</span>
                        <i class="fa-solid fa-arrow-right quick-arrow"></i>
                    </a>
                    <a href="?page=branch-performance" class="quick-link">
                        <i class="fa-solid fa-code-branch"></i>
                        <span>Branch Performance</span>
                        <i class="fa-solid fa-arrow-right quick-arrow"></i>
                    </a>
                    <a href="?page=payment-insights" class="quick-link">
                        <i class="fa-solid fa-credit-card"></i>
                        <span>Payment Insights</span>
                        <i class="fa-solid fa-arrow-right quick-arrow"></i>
                    </a>
                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /app -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>