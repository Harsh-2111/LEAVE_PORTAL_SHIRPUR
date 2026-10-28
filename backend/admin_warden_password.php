<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$admin = requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, name, warden_hostel FROM users '
        . 'WHERE role = ? AND is_active = TRUE AND warden_hostel IN (?, ?, ?, ?) '
        . 'ORDER BY warden_hostel, name'
    );
    $stmt->execute([
        'warden',
        'BOYS HOSTEL 1',
        'NEW BOYS HOSTEL',
        'NEW GIRLS HOSTEL',
        'OLD GIRLS HOSTEL',
    ]);
    echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET and POST requests are allowed.']);
    exit;
}

requireCsrfToken();
$wardenUserId = filter_var($_POST['warden_user_id'] ?? null, FILTER_VALIDATE_INT);
if ($wardenUserId === false || $wardenUserId === null || $wardenUserId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid warden account.']);
    exit;
}

$wardenStmt = $pdo->prepare(
    'SELECT id, name, warden_hostel FROM users '
    . 'WHERE id = ? AND role = ? AND is_active = TRUE AND warden_hostel IN (?, ?, ?, ?) LIMIT 1'
);
$wardenStmt->execute([
    $wardenUserId,
    'warden',
    'BOYS HOSTEL 1',
    'NEW BOYS HOSTEL',
    'NEW GIRLS HOSTEL',
    'OLD GIRLS HOSTEL',
]);
$warden = $wardenStmt->fetch();
if (!$warden) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'The selected active warden account was not found.']);
    exit;
}

$temporaryPassword = bin2hex(random_bytes(8));
$passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
$update = $pdo->prepare(
    'UPDATE users SET password_hash = ?, must_change_password = '
    . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1')
    . ', updated_at = NOW() WHERE id = ? AND role = ? AND is_active = TRUE'
);

try {
    $pdo->beginTransaction();
    $update->execute([$passwordHash, (int)$warden['id'], 'warden']);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Warden account changed while resetting its password.');
    }
    writeAudit(
        $pdo,
        (int)$admin['id'],
        'admin_reset_warden_password',
        'users',
        (int)$warden['id'],
        ['warden_hostel' => $warden['warden_hostel']],
        $_SERVER['REMOTE_ADDR'] ?? null
    );
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Unable to reset warden password: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to reset the warden password. Please try again.']);
    exit;
}

echo json_encode([
    'success' => true,
    'warden_name' => $warden['name'],
    'temporary_password' => $temporaryPassword,
    'message' => 'Temporary password created. The warden must change it at next login.',
]);
