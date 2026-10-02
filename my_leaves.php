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

$stmt = $pdo->prepare('SELECT id, sap_id, start_date, end_date, leave_days, reason, school, hostel, status, pass_token, qr_code_data, granted_at, created_at FROM leave_requests WHERE sap_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['login_id']]);
$rows = $stmt->fetchAll();

echo json_encode(['success' => true, 'items' => $rows]);
