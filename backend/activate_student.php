<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$requestHost = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$originHost = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST));
if ($requestHost === '' || $originHost === '' || !hash_equals($requestHost, $originHost)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Request origin could not be verified.']);
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This activation link is invalid. Ask the administrator to resend it.']);
    exit;
}
if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a password between 8 and 72 characters long.']);
    exit;
}
if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password and confirmation do not match.']);
    exit;
}

try {
    $tokenHash = hash('sha256', $token);
    $pdo->beginTransaction();
    $lookup = $pdo->prepare(
        'SELECT t.user_id, u.login_id, u.must_change_password '
        . 'FROM student_activation_tokens t '
        . 'INNER JOIN users u ON u.id = t.user_id '
        . 'WHERE t.token_hash = ? AND t.expires_at > NOW() AND t.activated_at IS NULL '
        . 'AND u.role = ? AND u.is_active = TRUE '
        . 'LIMIT 1 FOR UPDATE'
    );
    $lookup->execute([$tokenHash, 'student']);
    $account = $lookup->fetch();
    if (!$account || !databaseBool($account['must_change_password'])) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This activation link is expired or has already been used. Ask the administrator to resend it.']);
        exit;
    }

    $userId = (int)$account['user_id'];
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateUser = $pdo->prepare(
        DB_DRIVER === 'pgsql'
            ? 'UPDATE users SET password_hash = ?, must_change_password = FALSE, updated_at = NOW() WHERE id = ? AND role = ? AND is_active = TRUE AND must_change_password = TRUE'
            : 'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ? AND role = ? AND is_active = 1 AND must_change_password = 1'
    );
    $updateUser->execute([$passwordHash, $userId, 'student']);
    if ($updateUser->rowCount() !== 1) {
        throw new RuntimeException('Student account could not be activated.');
    }

    $invalidateToken = hash('sha256', random_bytes(32));
    $updateToken = $pdo->prepare(
        'UPDATE student_activation_tokens SET token_hash = ?, activated_at = NOW() WHERE user_id = ? AND token_hash = ? AND activated_at IS NULL'
    );
    $updateToken->execute([$invalidateToken, $userId, $tokenHash]);
    if ($updateToken->rowCount() !== 1) {
        throw new RuntimeException('Activation link was already used.');
    }

    writeAudit($pdo, $userId, 'student_account_activated', 'users', $userId, [], $_SERVER['REMOTE_ADDR'] ?? null);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Your account is activated. Sign in with your SAP ID and new password.',
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Student account activation failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to activate the account. Try again or ask the administrator to resend the link.']);
}
