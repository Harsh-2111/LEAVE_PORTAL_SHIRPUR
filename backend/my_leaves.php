<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$user = requireRole(['student']);

$stmt = $pdo->prepare(
    'SELECT id, sap_id, start_date, start_time, end_date, end_time, leave_days, reason, home_address, school, hostel, room_no, status, pass_token, qr_code_data, granted_at, created_at '
    . 'FROM leave_requests WHERE sap_id = ? '
    . "AND NOT (status IN ('Granted', 'CHECKED_OUT', 'CHECKED_IN') AND end_date < CURRENT_DATE) "
    . 'ORDER BY created_at DESC'
);
$stmt->execute([$user['login_id']]);
$rows = $stmt->fetchAll();

echo json_encode(['success' => true, 'items' => $rows]);
