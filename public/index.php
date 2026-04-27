<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION["user_id"])) {
    header('Location: ../index.php');
    exit;
}

$page = $_GET['page'] ?? 'dashboard';

$allowed = [
    'dashboard', 'sales-analytics', 'forecast',
    'branch-performance', 'payment-insights',
    'customer-insights', 'reports', 'settings',
    'data-management'
];

if (!in_array($page, $allowed)) $page = 'dashboard';

include $page . '.php';