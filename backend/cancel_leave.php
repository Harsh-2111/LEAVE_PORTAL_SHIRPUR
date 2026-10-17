<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$user = requireRole(['student', 'warden', 'admin']);
requireCsrfToken();

$leaveId = (int)($_POST['leave_id'] ?? 0);
if ($leaveId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Leave ID is required.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM leave_requests WHERE id = ? LIMIT 1');
$stmt->execute([$leaveId]);
$leave = $stmt->fetch();
if (!$leave) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
    exit;
}

if ($user['role'] === 'student' && $leave['sap_id'] !== $user['login_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Students can only cancel their own requests.']);
    exit;
}

if ($leave['gate_out_time'] !== null || in_array($leave['status'], ['CHECKED_OUT', 'CHECKED_IN'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'A leave request cannot be cancelled after a gate exit has been recorded.']);
    exit;
}

$update = $pdo->prepare('UPDATE leave_requests SET status = \'Cancelled\' WHERE id = ? AND gate_out_time IS NULL AND status NOT IN (\'CHECKED_OUT\', \'CHECKED_IN\')');
$update->execute([$leaveId]);
if ($update->rowCount() !== 1) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This leave request can no longer be cancelled.']);
    exit;
}

writeAudit($pdo, (int)$user['id'], 'cancel_leave', 'leave_requests', $leaveId, ['status' => 'Cancelled'], $_SERVER['REMOTE_ADDR'] ?? null);

echo json_encode(['success' => true, 'message' => 'Leave request cancelled.']);
