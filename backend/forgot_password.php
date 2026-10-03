<?php
header('Content-Type: application/json; charset=utf-8');
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

startSecureSession();
$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'verify') {
    $sapId = trim((string)($_POST['sap_id'] ?? ''));
    $contact = preg_replace('/\D+/', '', (string)($_POST['contact'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!preg_match('/^\d{11}$/', $sapId) || $contact === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a valid SAP ID, registered contact number, and email address.']);
        exit;
    }

    $lockSql = 'SELECT created_at FROM audit_log WHERE action = ? AND entity = ? AND ';
    $sapDetailExpression = DB_DRIVER === 'pgsql'
        ? "details->>'sap_id' = ?"
        : "JSON_UNQUOTE(JSON_EXTRACT(details, '$.sap_id')) = ?";
    $lockSql .= $sapDetailExpression . ' ORDER BY created_at DESC LIMIT 1';
    $lockStmt = $pdo->prepare($lockSql);
    $lockStmt->execute(['password_reset_locked', 'users', $sapId]);
    $lastLock = $lockStmt->fetch();

    if ($lastLock) {
        $lockEndsAt = (new DateTimeImmutable((string)$lastLock['created_at']))->modify('+3 hours');
        $now = new DateTimeImmutable('now');
        if ($now < $lockEndsAt) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'retry_after' => $lockEndsAt->format(DateTimeInterface::ATOM),
                'message' => 'Too many incorrect attempts. Try again after the three-hour lock expires.',
            ]);
            exit;
        }
    }

    $studentStmt = $pdo->prepare(
        'SELECT u.id, s.sap_id, s.student_contact, s.parent_contact, s.parent_email '
        . 'FROM students s INNER JOIN users u ON u.login_id = s.sap_id '
        . 'WHERE s.sap_id = ? AND s.is_active = TRUE AND u.role = ? AND u.is_active = TRUE LIMIT 1'
    );
    $studentStmt->execute([$sapId, 'student']);
    $student = $studentStmt->fetch();
    $registeredContacts = $student
        ? [
            preg_replace('/\D+/', '', (string)$student['student_contact']),
            preg_replace('/\D+/', '', (string)$student['parent_contact']),
        ]
        : [];
    $matches = $student
        && in_array($contact, $registeredContacts, true)
        && hash_equals(strtolower(trim((string)$student['parent_email'])), $email);

    if (!$matches) {
        writeAudit($pdo, null, 'password_reset_failed', 'users', null, ['sap_id' => $sapId], $_SERVER['REMOTE_ADDR'] ?? null);

        $cutoff = gmdate('Y-m-d H:i:s', time() - (3 * 60 * 60));
        $failureSql = 'SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity = ? AND '
            . $sapDetailExpression . ' AND created_at >= ?';
        $failureStmt = $pdo->prepare($failureSql);
        $failureStmt->execute(['password_reset_failed', 'users', $sapId, $cutoff]);
        $failureCount = (int)$failureStmt->fetchColumn();

        if ($failureCount >= 5) {
            writeAudit($pdo, null, 'password_reset_locked', 'users', null, ['sap_id' => $sapId], $_SERVER['REMOTE_ADDR'] ?? null);
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'retry_after' => (new DateTimeImmutable('now'))->modify('+3 hours')->format(DateTimeInterface::ATOM),
                'message' => 'Too many incorrect attempts. Password recovery is locked for three hours.',
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password cannot be retrieved or reset because the details do not match.']);
        exit;
    }

    session_regenerate_id(true);
    $resetToken = bin2hex(random_bytes(32));
    $_SESSION['password_reset_grant'] = [
        'user_id' => (int)$student['id'],
        'sap_id' => $sapId,
        'token_hash' => hash('sha256', $resetToken),
        'expires_at' => time() + 600,
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    writeAudit($pdo, (int)$student['id'], 'password_reset_verified', 'users', (int)$student['id'], ['sap_id' => $sapId], $_SERVER['REMOTE_ADDR'] ?? null);

    echo json_encode([
        'success' => true,
        'reset_token' => $resetToken,
        'csrf_token' => $_SESSION['csrf_token'],
        'message' => 'Details matched. Set a new password to recover access.',
    ]);
    exit;
}

if ($action === 'reset') {
    requireCsrfToken();

    $grant = $_SESSION['password_reset_grant'] ?? null;
    $resetToken = (string)($_POST['reset_token'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!is_array($grant)
        || (int)($grant['expires_at'] ?? 0) < time()
        || $resetToken === ''
        || !hash_equals((string)($grant['token_hash'] ?? ''), hash('sha256', $resetToken))) {
        unset($_SESSION['password_reset_grant']);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Recovery verification expired. Verify your details again.']);
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

    $userId = (int)$grant['user_id'];
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND login_id = ? AND role = ? AND is_active = TRUE LIMIT 1');
    $userStmt->execute([$userId, $grant['sap_id'], 'student']);
    if (!$userStmt->fetch()) {
        unset($_SESSION['password_reset_grant']);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Student account is unavailable. Contact the administrator.']);
        exit;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateSql = DB_DRIVER === 'pgsql'
        ? 'UPDATE users SET password_hash = ?, must_change_password = FALSE WHERE id = ?'
        : 'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?';
    $update = $pdo->prepare($updateSql);
    $update->execute([$hash, $userId]);
    writeAudit($pdo, $userId, 'password_reset_completed', 'users', $userId, ['sap_id' => $grant['sap_id']], $_SERVER['REMOTE_ADDR'] ?? null);

    unset($_SESSION['password_reset_grant']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    echo json_encode(['success' => true, 'message' => 'Password reset successfully. Sign in with your new password.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown password recovery action.']);