<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

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

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT lr.*, s.gender FROM leave_requests lr '
        . 'INNER JOIN students s ON s.sap_id = lr.sap_id WHERE lr.id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$leaveId]);
    $leave = $stmt->fetch();
    if (!$leave) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
        exit;
    }

    if ($user['role'] === 'student' && $leave['sap_id'] !== $user['login_id']) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Students can only cancel their own requests.']);
        exit;
    }
    if ($user['role'] === 'warden' && (
        ($user['warden_gender'] ?? '') !== $leave['gender']
        || requireWardenHostel($user) !== $leave['hostel']
    )) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This leave belongs to another hostel warden.']);
        exit;
    }
    if ($user['role'] === 'warden' && $leave['status'] !== 'Granted') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Wardens can cancel approved leaves only.']);
        exit;
    }
    if (!in_array($leave['status'], ['Pending Verification', 'Granted'], true) || $leave['gate_out_time'] !== null) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only pending or approved leaves can be cancelled before the leave starts.']);
        exit;
    }
    $campusTimezone = new DateTimeZone('Asia/Kolkata');
    $scheduledStart = new DateTimeImmutable((string)$leave['start_date'], $campusTimezone);
    if (new DateTimeImmutable('now', $campusTimezone) >= $scheduledStart) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This leave can no longer be cancelled because its start date has arrived.']);
        exit;
    }

    $update = $pdo->prepare(
        'UPDATE leave_requests SET status = ?, pass_token = NULL, qr_code_data = NULL '
        . 'WHERE id = ? AND status = ? AND gate_out_time IS NULL'
    );
    $update->execute(['Cancelled', $leaveId, $leave['status']]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Leave changed while cancellation was being recorded.');
    }
    writeAudit($pdo, (int)$user['id'], 'cancel_leave', 'leave_requests', $leaveId, [
        'previous_status' => $leave['status'],
        'status' => 'Cancelled',
    ], $_SERVER['REMOTE_ADDR'] ?? null);
    $pdo->commit();

    try {
        notifyLeave($pdo, $leaveId, 'leave_cancelled', 'Leave cancelled', 'The leave request was cancelled before its start date.');
    } catch (Throwable $notificationError) {
        error_log('Unable to create cancellation notification: ' . $notificationError->getMessage());
    }
    echo json_encode(['success' => true, 'message' => 'Leave request cancelled.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Unable to cancel leave request: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to cancel this leave request. Please try again.']);
}
