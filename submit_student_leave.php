<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$user = requireRole(['student']);
requireCsrfToken();

$sapId = trim((string)($_POST['sap_id'] ?? ''));
$startDate = trim((string)($_POST['start_date'] ?? ''));
$endDate = trim((string)($_POST['end_date'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$school = trim((string)($_POST['school'] ?? ''));
$hostel = trim((string)($_POST['hostel'] ?? ''));
$detailsConfirmed = ($_POST['details_confirmed'] ?? '') === '1';

if ($sapId !== $user['login_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You can only submit a request for your own SAP ID.']);
    exit;
}

if (!$detailsConfirmed) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Confirm that your profile details are correct before submitting.']);
    exit;
}

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
$end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
if (!preg_match($datePattern, $startDate) || !preg_match($datePattern, $endDate) || !$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate || $start >= $end || $start < new DateTimeImmutable('today')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a future start date and an end date after the start date.']);
    exit;
}

if ($reason === '' || strlen($reason) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a reason of up to 2,000 characters.']);
    exit;
}

$studentStmt = $pdo->prepare('SELECT gender, course FROM students WHERE sap_id = ? AND is_active = TRUE LIMIT 1');
$studentStmt->execute([$sapId]);
$student = $studentStmt->fetch();
if (!$student || !in_array($student['gender'], ['male', 'female'], true) || !in_array($student['course'], ['BTech', 'MBATech', 'BPharm', 'MPharm', 'Agriculture'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Your student profile is incomplete. Contact the administrator to update it.']);
    exit;
}

$expectedSchool = in_array($student['course'], ['BTech', 'MBATech'], true) ? 'MPSTME' : 'SPTM';
$allowedHostels = $student['gender'] === 'female'
    ? ['NEW GIRLS HOSTEL', 'OLD GIRLS HOSTEL']
    : ['BOYS HOSTEL 1', 'NEW BOYS HOSTEL'];
if ($school !== $expectedSchool || !in_array($hostel, $allowedHostels, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'School or hostel does not match your student profile.']);
    exit;
}

$overlap = $pdo->prepare('SELECT id FROM leave_requests WHERE sap_id = ? AND status NOT IN (\'Rejected\', \'Cancelled\') AND start_date <= ? AND end_date >= ? LIMIT 1');
$overlap->execute([$sapId, $endDate, $startDate]);
if ($overlap->fetch()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'You already have an overlapping leave request.']);
    exit;
}

$leaveDays = (int)$start->diff($end)->format('%a') + 1;
$insertSql = DB_DRIVER === 'pgsql'
    ? 'INSERT INTO leave_requests (sap_id, start_date, end_date, leave_days, reason, school, hostel, email_from, email_received_at, parent_email_matched, logged_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, FALSE, ?, \'Pending Verification\', NOW()) RETURNING id'
    : 'INSERT INTO leave_requests (sap_id, start_date, end_date, leave_days, reason, school, hostel, email_from, email_received_at, parent_email_matched, logged_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, \'Pending Verification\', NOW())';
$insert = $pdo->prepare($insertSql);
$insert->execute([$sapId, $startDate, $endDate, $leaveDays, $reason, $school, $hostel, (int)$user['id']]);
$leaveId = DB_DRIVER === 'pgsql' ? (int)$insert->fetchColumn() : (int)$pdo->lastInsertId();

writeAudit($pdo, (int)$user['id'], 'submit_student_leave', 'leave_requests', $leaveId, ['sap_id' => $sapId, 'school' => $school, 'hostel' => $hostel], $_SERVER['REMOTE_ADDR'] ?? null);

echo json_encode(['success' => true, 'leave_id' => $leaveId, 'message' => 'Leave request submitted to the warden for parent-call verification.']);