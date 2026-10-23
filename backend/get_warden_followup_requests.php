<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$user = requireRole(['warden']);
if (!in_array($user['warden_gender'] ?? '', ['male', 'female'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Warden account has no hostel-group assignment.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT lr.id, lr.sap_id, s.name, s.gender, s.course, s.year, s.branch, s.batch, '
    . 's.parent_email, s.parent_contact, lr.start_date, lr.end_date, lr.leave_days, lr.reason, '
    . 'lr.school, lr.hostel, lr.status, latest.outcome AS followup_outcome, latest.remarks AS followup_remarks, '
    . 'latest.called_at AS followup_called_at, caller.name AS called_by_name '
    . 'FROM leave_requests lr '
    . 'INNER JOIN students s ON s.sap_id = lr.sap_id '
    . 'INNER JOIN leave_call_log latest ON latest.id = ('
    . 'SELECT MAX(latest_call.id) FROM leave_call_log latest_call WHERE latest_call.leave_id = lr.id'
    . ') '
    . 'INNER JOIN users caller ON caller.id = latest.called_by '
    . 'WHERE s.gender = ? AND lr.status = ? AND latest.outcome IN (?, ?) '
    . 'ORDER BY latest.called_at DESC, lr.created_at DESC'
);
$stmt->execute([$user['warden_gender'], 'Pending Verification', 'Not Reachable', 'Wrong Person']);

echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
