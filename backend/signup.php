<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

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
    if (!empty($_SESSION['user'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Sign out before creating a student account.']);
        exit;
    }

    $sapId = trim((string)($_POST['sap_id'] ?? ''));
    $contact = preg_replace('/\D+/', '', (string)($_POST['parent_contact'] ?? ''));
    $email = strtolower(trim((string)($_POST['parent_email'] ?? '')));

    if (!preg_match('/^\d{11}$/', $sapId)
        || strlen($contact) < 7
        || strlen($contact) > 20
        || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a valid SAP ID, parent contact number, and parent email address.']);
        exit;
    }

    $sapDetailExpression = DB_DRIVER === 'pgsql'
        ? "details->>'sap_id' = ?"
        : "JSON_UNQUOTE(JSON_EXTRACT(details, '$.sap_id')) = ?";
    $lockSql = 'SELECT created_at FROM audit_log WHERE action = ? AND entity = ? AND '
        . $sapDetailExpression . ' ORDER BY created_at DESC LIMIT 1';
    $lockStmt = $pdo->prepare($lockSql);
    $lockStmt->execute(['student_signup_locked', 'users', $sapId]);
    $lastLock = $lockStmt->fetch();

    if ($lastLock) {
        $lockEndsAt = (new DateTimeImmutable((string)$lastLock['created_at']))->modify('+3 hours');
        if (new DateTimeImmutable('now') < $lockEndsAt) {
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
        'SELECT sap_id, parent_contact, parent_email FROM students WHERE sap_id = ? AND is_active = TRUE LIMIT 1'
    );
    $studentStmt->execute([$sapId]);
    $student = $studentStmt->fetch();
    $matches = $student
        && hash_equals(
            preg_replace('/\D+/', '', (string)$student['parent_contact']),
            $contact
        )
        && hash_equals(
            strtolower(trim((string)$student['parent_email'])),
            $email
        );

    if (!$matches) {
        writeAudit($pdo, null, 'student_signup_failed', 'users', null, ['sap_id' => $sapId], $_SERVER['REMOTE_ADDR'] ?? null);

        $cutoff = gmdate('Y-m-d H:i:s', time() - (3 * 60 * 60));
        $failureSql = 'SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity = ? AND '
            . $sapDetailExpression . ' AND created_at >= ?';
        $failureStmt = $pdo->prepare($failureSql);
        $failureStmt->execute(['student_signup_failed', 'users', $sapId, $cutoff]);
        if ((int)$failureStmt->fetchColumn() >= 5) {
            writeAudit($pdo, null, 'student_signup_locked', 'users', null, ['sap_id' => $sapId], $_SERVER['REMOTE_ADDR'] ?? null);
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'retry_after' => (new DateTimeImmutable('now'))->modify('+3 hours')->format(DateTimeInterface::ATOM),
                'message' => 'Too many incorrect attempts. Account setup is locked for three hours.',
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'The details do not match an active student record. Check them or contact the administrator.']);
        exit;
    }

    $accountStmt = $pdo->prepare(
        'SELECT id, role, must_change_password, is_active FROM users WHERE login_id = ? LIMIT 1'
    );
    $accountStmt->execute([$sapId]);
    $account = $accountStmt->fetch();
    if ($account && (
        $account['role'] !== 'student'
        || !databaseBool($account['is_active'])
        || !databaseBool($account['must_change_password'])
    )) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This student account is already set up. Sign in or use Forgot password.']);
        exit;
    }

    session_regenerate_id(true);
    $signupToken = bin2hex(random_bytes(32));
    $_SESSION['student_signup_grant'] = [
        'sap_id' => $sapId,
        'token_hash' => hash('sha256', $signupToken),
        'expires_at' => time() + 600,
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        'success' => true,
        'signup_token' => $signupToken,
        'csrf_token' => $_SESSION['csrf_token'],
        'message' => 'Details matched. Create a password of at least 8 characters.',
    ]);
    exit;
}

if ($action === 'create') {
    requireCsrfToken();

    $grant = $_SESSION['student_signup_grant'] ?? null;
    $signupToken = (string)($_POST['signup_token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if (!is_array($grant)
        || (int)($grant['expires_at'] ?? 0) < time()
        || $signupToken === ''
        || !hash_equals((string)($grant['token_hash'] ?? ''), hash('sha256', $signupToken))) {
        unset($_SESSION['student_signup_grant']);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account setup verification expired. Verify your details again.']);
        exit;
    }

    if (strlen($password) < 8 || strlen($password) > 72) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose a password between 8 and 72 characters.']);
        exit;
    }
    if ($password !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password and confirmation do not match.']);
        exit;
    }

    $sapId = (string)$grant['sap_id'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $pdo->beginTransaction();
        $studentStmt = $pdo->prepare(
            'SELECT name FROM students WHERE sap_id = ? AND is_active = TRUE LIMIT 1 FOR UPDATE'
        );
        $studentStmt->execute([$sapId]);
        $student = $studentStmt->fetch();
        if (!$student) {
            $pdo->rollBack();
            unset($_SESSION['student_signup_grant']);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Student record is no longer active. Contact the administrator.']);
            exit;
        }

        $accountStmt = $pdo->prepare(
            'SELECT id, role, must_change_password, is_active FROM users WHERE login_id = ? LIMIT 1 FOR UPDATE'
        );
        $accountStmt->execute([$sapId]);
        $account = $accountStmt->fetch();

        if ($account) {
            if (
                $account['role'] !== 'student'
                || !databaseBool($account['is_active'])
                || !databaseBool($account['must_change_password'])
            ) {
                $pdo->rollBack();
                unset($_SESSION['student_signup_grant']);
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'This student account is already set up. Sign in or use Forgot password.']);
                exit;
            }
            $update = $pdo->prepare(
                'UPDATE users SET password_hash = ?, name = ?, must_change_password = FALSE, updated_at = NOW() WHERE id = ?'
            );
            $update->execute([$passwordHash, $student['name'], (int)$account['id']]);
            $userId = (int)$account['id'];
        } else {
            $insertSql = DB_DRIVER === 'pgsql'
                ? 'INSERT INTO users (login_id, password_hash, role, name, must_change_password, is_active) VALUES (?, ?, ?, ?, FALSE, TRUE) RETURNING id'
                : 'INSERT INTO users (login_id, password_hash, role, name, must_change_password, is_active) VALUES (?, ?, ?, ?, 0, 1)';
            $insert = $pdo->prepare($insertSql);
            $insert->execute([$sapId, $passwordHash, 'student', $student['name']]);
            $userId = DB_DRIVER === 'pgsql' ? (int)$insert->fetchColumn() : (int)$pdo->lastInsertId();
        }

        writeAudit(
            $pdo,
            $userId,
            'student_signup_completed',
            'users',
            $userId,
            ['sap_id' => $sapId],
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        unset($_SESSION['student_signup_grant']);
        if (in_array((string)$error->getCode(), ['23000', '23505'], true)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This student account was just set up. Sign in or use Forgot password.']);
            exit;
        }
        error_log('Student account setup failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Account setup failed. Please try again or contact the administrator.']);
        exit;
    }

    unset($_SESSION['student_signup_grant']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    echo json_encode(['success' => true, 'message' => 'Account created. Sign in with your SAP ID and new password.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown account setup action.']);
