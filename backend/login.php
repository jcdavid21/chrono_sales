<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload.'
    ]);
    exit;
}

$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');
$rememberMe = (bool) ($data['rememberMe'] ?? false);

if ($email === '' || $password === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Email and password are required.'
    ]);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT user_id, email, full_name, role, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    $isValid = false;

    if (is_array($user)) {
        $storedPassword = (string) ($user['password_hash'] ?? '');
        
        $isValid = password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);
    }

    if (!$isValid) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password.'
        ]);
        exit;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please contact administrator.'
    ]);
    exit;
}

$_SESSION['logged_in'] = true;
$_SESSION['email'] = $user['email'] ?? $email;
$_SESSION['full_name'] = $user['full_name'] ?? $email;
$_SESSION['role'] = $user['role'] ?? 'user';
$_SESSION["user_id"] = $user['user_id'] ?? null;
$_SESSION["is_admin"] = ($user['role'] ?? '') === 'Admin';


if ($rememberMe) {
    setcookie('remember_user', $email, [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Login successful. Redirecting...',
    'redirect' => './public/dashboard.php'
]);
