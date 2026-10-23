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
$allowed = ['Confirmed', 'Denied', 'Not Reachable', 'Wrong Person'];

if ($leaveId <= 0 || !in_array($outcome, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Leave ID and a valid call outcome are required.']);
    exit;
}

if (strlen($remarks) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Remarks must be 2,000 characters or fewer.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $leaveStmt = $pdo->prepare(
        'SELECT lr.*, s.gender, s.parent_contact, s.name AS student_name '
        . 'FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id '
        . 'WHERE lr.id = ? LIMIT 1 FOR UPDATE'
    );
    $leaveStmt->execute([$leaveId]);
    $leave = $leaveStmt->fetch();

    if (!$leave) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        exit;
    }

    if ($leave['gender'] !== $user['warden_gender']) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This request belongs to the other warden group.']);
        exit;
    }

    if ($leave['status'] !== 'Pending Verification') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request is no longer awaiting verification.']);
        exit;
    }

    $latestCallStmt = $pdo->prepare('SELECT outcome FROM leave_call_log WHERE leave_id = ? ORDER BY id DESC LIMIT 1');
    $latestCallStmt->execute([$leaveId]);
    $latestOutcome = $latestCallStmt->fetchColumn();
    $isFollowup = in_array($latestOutcome, ['Not Reachable', 'Wrong Person'], true);
    $needsRemarks = in_array($outcome, ['Not Reachable', 'Wrong Person'], true)
        || ($isFollowup && in_array($outcome, ['Confirmed', 'Denied'], true));

    if ($needsRemarks && $remarks === '') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please enter remarks explaining this call outcome.']);
        exit;
    }

    if ($calledNumber === '') {
        $calledNumber = (string)$leave['parent_contact'];
    }

    $log = $pdo->prepare(
        'INSERT INTO leave_call_log (leave_id, called_by, called_number, called_at, outcome, remarks) '
        . 'VALUES (?, ?, ?, NOW(), ?, ?)'
    );
    $log->execute([$leaveId, (int)$user['id'], $calledNumber, $outcome, $remarks]);

    $details = ['outcome' => $outcome, 'called_number' => $calledNumber, 'remarks' => $remarks];

    if ($outcome === 'Confirmed') {
        $secret = defined('QR_HMAC_SECRET') ? QR_HMAC_SECRET : 'change-me';
        $token = bin2hex(random_bytes(16));
        $message = "GATE PASS\nStudent ID: {$leave['sap_id']}\nName: {$leave['student_name']}\nFrom: {$leave['start_date']}\nTill: {$leave['end_date']}\nApproved by Warden: {$user['name']}\nPass ID: {$token}";
        $signature = hash_hmac('sha256', $message, $secret);
        $qrData = $message . "\nSignature: {$signature}";

        $update = $pdo->prepare(
            'UPDATE leave_requests SET status = ?, granted_by = ?, granted_at = NOW(), pass_token = ?, qr_code_data = ? '
            . 'WHERE id = ? AND status = ?'
        );
        $update->execute(['Granted', (int)$user['id'], $token, $qrData, $leaveId, 'Pending Verification']);
        $details['pass_token'] = $token;
    } elseif ($outcome === 'Denied') {
        $update = $pdo->prepare('UPDATE leave_requests SET status = ? WHERE id = ? AND status = ?');
        $update->execute(['Rejected', $leaveId, 'Pending Verification']);
    }

    if (isset($update) && $update->rowCount() !== 1) {
        throw new RuntimeException('Leave request status changed while recording the call outcome.');
    }

    writeAudit($pdo, (int)$user['id'], 'record_call', 'leave_requests', $leaveId, $details, $_SERVER['REMOTE_ADDR'] ?? null);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $outcome === 'Confirmed' ? 'Leave approved and gate pass created.' : ($outcome === 'Denied' ? 'Leave request rejected.' : 'Call outcome recorded.'),
        'outcome' => $outcome,
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Unable to record leave call outcome: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the call outcome. Please try again.']);
}
