<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = requireRole(['student', 'warden', 'admin']);
$hostels = ['BOYS HOSTEL 1', 'NEW BOYS HOSTEL', 'NEW GIRLS HOSTEL', 'OLD GIRLS HOSTEL'];
$table = $pdo->prepare(
    'SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = ? LIMIT 1'
);
$table->execute(['leave_policies', DB_DRIVER === 'pgsql' ? 'public' : DB_NAME]);
if (!$table->fetchColumn()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Leave policy storage is not configured. Apply the portal-features database migration.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only administrators can change leave policies.']);
        exit;
    }
    requireCsrfToken();
    $hostel = trim((string)($_POST['hostel'] ?? ''));
    if (!in_array($hostel, $hostels, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose a valid hostel.']);
        exit;
    }
    $values = [];
    foreach (['max_leave_days', 'max_requests_per_month', 'minimum_notice_hours'] as $field) {
        $raw = trim((string)($_POST[$field] ?? ''));
        if ($raw === '') {
            $values[$field] = null;
            continue;
        }
        if (!ctype_digit($raw) || (int)$raw > 3650 || ($field !== 'minimum_notice_hours' && (int)$raw < 1)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Policy values must be valid whole numbers. Leave blank for no limit.']);
            exit;
        }
        $values[$field] = (int)$raw;
    }
    $sql = DB_DRIVER === 'pgsql'
        ? 'INSERT INTO leave_policies (hostel, max_leave_days, max_requests_per_month, minimum_notice_hours, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON CONFLICT (hostel) DO UPDATE SET max_leave_days = EXCLUDED.max_leave_days, max_requests_per_month = EXCLUDED.max_requests_per_month, minimum_notice_hours = EXCLUDED.minimum_notice_hours, updated_by = EXCLUDED.updated_by, updated_at = NOW()'
        : 'INSERT INTO leave_policies (hostel, max_leave_days, max_requests_per_month, minimum_notice_hours, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE max_leave_days = VALUES(max_leave_days), max_requests_per_month = VALUES(max_requests_per_month), minimum_notice_hours = VALUES(minimum_notice_hours), updated_by = VALUES(updated_by), updated_at = NOW()';
    $save = $pdo->prepare($sql);
    $save->execute([$hostel, $values['max_leave_days'], $values['max_requests_per_month'], $values['minimum_notice_hours'], (int)$user['id']]);
    writeAudit($pdo, (int)$user['id'], 'update_leave_policy', 'leave_policies', null, ['hostel' => $hostel] + $values, $_SERVER['REMOTE_ADDR'] ?? null);
    echo json_encode(['success' => true, 'message' => 'Leave policy saved.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET or POST requests are allowed.']);
    exit;
}
$hostel = trim((string)($_GET['hostel'] ?? ''));
if ($user['role'] === 'warden') {
    $hostel = requireWardenHostel($user);
}
if ($hostel !== '' && !in_array($hostel, $hostels, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid hostel.']);
    exit;
}
$sql = 'SELECT hostel, max_leave_days, max_requests_per_month, minimum_notice_hours, updated_at FROM leave_policies';
$params = [];
if ($hostel !== '') {
    $sql .= ' WHERE hostel = ?';
    $params[] = $hostel;
}
$sql .= ' ORDER BY hostel';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
