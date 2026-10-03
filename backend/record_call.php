<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$user = requireRole(['warden']);
if (!in_array($user['warden_gender'] ?? '', ['male', 'female'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Warden account has no hostel-group assignment.']);
    exit;
}
requireCsrfToken();

$leaveId = (int)($_POST['leave_id'] ?? 0);
$outcome = trim((string)($_POST['outcome'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$calledNumber = trim((string)($_POST['called_number'] ?? ''));

if ($leaveId <= 0 || $outcome === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Leave ID and call outcome are required.']);
    exit;
}

$leaveStmt = $pdo->prepare('SELECT lr.*, s.gender, s.parent_contact, s.name AS student_name, s.parent_email FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id WHERE lr.id = ? LIMIT 1');
$leaveStmt->execute([$leaveId]);
$leave = $leaveStmt->fetch();

if (!$leave) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
    exit;
}

if ($leave['gender'] !== $user['warden_gender']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This request belongs to the other warden group.']);
    exit;
}

if ($leave['status'] !== 'Pending Verification') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This request is no longer awaiting verification.']);
    exit;
}

if ($calledNumber === '') {
    $calledNumber = (string)$leave['parent_contact'];
}

$allowed = ['Confirmed', 'Denied', 'Not Reachable', 'Wrong Person'];
if (!in_array($outcome, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid call outcome.']);
    exit;
}

$log = $pdo->prepare('INSERT INTO leave_call_log (leave_id, called_by, called_number, called_at, outcome, remarks) VALUES (?, ?, ?, NOW(), ?, ?)');
$log->execute([$leaveId, (int)$user['id'], $calledNumber, $outcome, $remarks]);

$update = null;
$details = ['outcome' => $outcome, 'called_number' => $calledNumber, 'remarks' => $remarks];

if ($outcome === 'Confirmed') {
    $secret = defined('QR_HMAC_SECRET') ? QR_HMAC_SECRET : 'change-me';
    $token = bin2hex(random_bytes(16));
    $message = "GATE PASS\nStudent ID: {$leave['sap_id']}\nName: {$leave['student_name']}\nFrom: {$leave['start_date']}\nTill: {$leave['end_date']}\nApproved by Warden: {$user['name']}\nPass ID: {$token}";
    $signature = hash_hmac('sha256', $message, $secret);
    $qrData = $message . "\nSignature: {$signature}";

    $update = $pdo->prepare('UPDATE leave_requests SET status = \'Granted\', granted_by = ?, granted_at = NOW(), pass_token = ?, qr_code_data = ? WHERE id = ?');
    $update->execute([(int)$user['id'], $token, $qrData, $leaveId]);
    $details['pass_token'] = $token;
} elseif ($outcome === 'Denied') {
    $update = $pdo->prepare('UPDATE leave_requests SET status = \'Rejected\' WHERE id = ?');
    $update->execute([$leaveId]);
} else {
    $update = $pdo->prepare('UPDATE leave_requests SET status = \'Pending Verification\' WHERE id = ?');
    $update->execute([$leaveId]);
}

writeAudit($pdo, (int)$user['id'], 'record_call', 'leave_requests', $leaveId, $details, $_SERVER['REMOTE_ADDR'] ?? null);

echo json_encode(['success' => true, 'message' => 'Call outcome recorded.', 'outcome' => $outcome]);
