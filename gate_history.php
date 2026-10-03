<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

requireRole(['security']);

$stmt = $pdo->prepare(
    'SELECT a.id, a.action, a.created_at, lr.sap_id, s.name AS student_name, lr.start_date, lr.end_date '
    . 'FROM audit_log a '
    . 'INNER JOIN leave_requests lr ON lr.id = a.entity_id '
    . 'INNER JOIN students s ON s.sap_id = lr.sap_id '
    . 'INNER JOIN users u ON u.id = a.user_id '
    . 'WHERE a.entity = ? AND a.action IN (?, ?) AND u.role = ? '
    . 'ORDER BY a.created_at DESC LIMIT 200'
);
$stmt->execute(['leave_requests', 'gate_pass_accepted', 'gate_pass_rejected', 'security']);

echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);