<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$role = trim((string)($_POST['role'] ?? ''));
$loginId = trim((string)($_POST['login_id'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($role === '' || $loginId === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Role, login ID and password are required.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, login_id, password_hash, role, warden_gender, name, must_change_password, is_active FROM users WHERE login_id = ? AND role = ? AND is_active = TRUE LIMIT 1');
$stmt->execute([$loginId, $role]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid login credentials.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'login_id' => $user['login_id'],
    'role' => $user['role'],
    'warden_gender' => $user['warden_gender'],
    'name' => $user['name'],
    'must_change_password' => databaseBool($user['must_change_password']),
];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

writeAudit($pdo, (int)$user['id'], 'login', 'users', (int)$user['id'], ['role' => $user['role']], $_SERVER['REMOTE_ADDR'] ?? null);

echo json_encode([
    'success' => true,
    'user' => $_SESSION['user'],
    'csrf_token' => $_SESSION['csrf_token'],
]);
