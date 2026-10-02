<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$user = requireAuth();
requireCsrfToken();

$currentPassword = (string)($_POST['current_password'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Current password, new password, and confirmation are required.']);
    exit;
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
    exit;
}

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = TRUE LIMIT 1');
$stmt->execute([(int)$user['id']]);
$record = $stmt->fetch();

if (!$record || !password_verify($currentPassword, $record['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
    exit;
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$update = $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
$update->execute([$hash, (int)$user['id']]);

writeAudit($pdo, (int)$user['id'], 'change_password', 'users', (int)$user['id'], [], $_SERVER['REMOTE_ADDR'] ?? null);

echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
