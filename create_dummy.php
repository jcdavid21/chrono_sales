<?php

require_once __DIR__ . '/backend/db.php';

$db = getDbConnection();

$email = "admin@chrono.sales.com";
$full_name = "Admin User";
$role = "admin";
$password_hash = password_hash("admin123", PASSWORD_DEFAULT);

// asia/manila timezon
date_default_timezone_set('Asia/Manila');
$created_at = date('Y-m-d H:i:s');

$query = "INSERT INTO users (email, full_name, role, password_hash, created_at)
VALUES (?, ?, ?, ?, ?)";

$stmt = $db->prepare($query);
$stmt->execute([$email, $full_name, $role, $password_hash, $created_at]);

if($stmt->rowCount() > 0) {
    echo "Admin user created successfully.";
} else {
    echo "Failed to create admin user.";
}

?>