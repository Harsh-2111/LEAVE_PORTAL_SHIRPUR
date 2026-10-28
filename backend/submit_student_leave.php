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

$user = requireRole(['student']);
requireCsrfToken();

$sapId = trim((string)($_POST['sap_id'] ?? ''));
$startDate = trim((string)($_POST['start_date'] ?? ''));
$startTime = trim((string)($_POST['start_time'] ?? ''));
$endDate = trim((string)($_POST['end_date'] ?? ''));
$endTime = trim((string)($_POST['end_time'] ?? ''));
$studentContact = trim((string)($_POST['student_contact'] ?? ''));
$homeAddress = trim((string)($_POST['home_address'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$school = trim((string)($_POST['school'] ?? ''));
$hostel = trim((string)($_POST['hostel'] ?? ''));
$roomNo = trim((string)($_POST['room_no'] ?? ''));
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
$timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
$campusTimezone = new DateTimeZone('Asia/Kolkata');
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
$end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
$startTimeValue = DateTimeImmutable::createFromFormat('!H:i', $startTime);
$endTimeValue = DateTimeImmutable::createFromFormat('!H:i', $endTime);
if (
    !preg_match($datePattern, $startDate)
    || !preg_match($datePattern, $endDate)
    || !preg_match($timePattern, $startTime)
    || !preg_match($timePattern, $endTime)
    || !$start || !$end || !$startTimeValue || !$endTimeValue
    || $start->format('Y-m-d') !== $startDate
    || $end->format('Y-m-d') !== $endDate
    || $startTimeValue->format('H:i') !== $startTime
    || $endTimeValue->format('H:i') !== $endTime
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose valid leave and return dates and times.']);
    exit;
}
$startDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startDate . ' ' . $startTime, $campusTimezone);
$endDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $endDate . ' ' . $endTime, $campusTimezone);
if (
    !$startDateTime
    || !$endDateTime
    || $startDateTime < new DateTimeImmutable('now', $campusTimezone)
    || $endDateTime <= $startDateTime
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Leave must start now or later, and return date and time must be after the leave start.']);
    exit;
}

if (!preg_match('/^\d{10}$/', $studentContact)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter exactly 10 digits for your mobile number.']);
    exit;
}

$homeAddressLength = preg_match_all('/./us', $homeAddress);
if ($homeAddressLength === false || $homeAddressLength > 150) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Home address must be no more than 150 characters.']);
    exit;
}

if ($reason === '' || strlen($reason) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a reason of up to 2,000 characters.']);
    exit;
}

if ($roomNo === '' || strlen($roomNo) > 50) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a room number of up to 50 characters.']);
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

$leaveDays = (int)$start->diff($end)->format('%a') + 1;
try {
    $pdo->beginTransaction();

    $overlap = $pdo->prepare('SELECT id FROM leave_requests WHERE sap_id = ? AND status NOT IN (\'Rejected\', \'Cancelled\', \'CHECKED_IN\') AND start_date <= ? AND end_date >= ? LIMIT 1');
    $overlap->execute([$sapId, $endDate, $startDate]);
    if ($overlap->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You already have an overlapping leave request.']);
        exit;
    }

    $contactUpdate = $pdo->prepare('UPDATE students SET student_contact = ?, updated_at = NOW() WHERE sap_id = ? AND is_active = TRUE');
    $contactUpdate->execute(['+91' . $studentContact, $sapId]);

    $insertSql = DB_DRIVER === 'pgsql'
        ? 'INSERT INTO leave_requests (sap_id, start_date, start_time, end_date, end_time, leave_days, reason, home_address, school, hostel, room_no, email_from, email_received_at, parent_email_matched, logged_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, FALSE, ?, \'Pending Verification\', NOW()) RETURNING id'
        : 'INSERT INTO leave_requests (sap_id, start_date, start_time, end_date, end_time, leave_days, reason, home_address, school, hostel, room_no, email_from, email_received_at, parent_email_matched, logged_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, \'Pending Verification\', NOW())';
    $insert = $pdo->prepare($insertSql);
    $insert->execute([
        $sapId,
        $startDate,
        $startTime,
        $endDate,
        $endTime,
        $leaveDays,
        $reason,
        $homeAddress,
        $school,
        $hostel,
        $roomNo,
        (int)$user['id'],
    ]);
    $leaveId = DB_DRIVER === 'pgsql' ? (int)$insert->fetchColumn() : (int)$pdo->lastInsertId();

    writeAudit($pdo, (int)$user['id'], 'submit_student_leave', 'leave_requests', $leaveId, [
        'sap_id' => $sapId,
        'school' => $school,
        'hostel' => $hostel,
        'room_no' => $roomNo,
    ], $_SERVER['REMOTE_ADDR'] ?? null);
    $pdo->commit();
    try {
        notifyWarden($pdo, $leaveId, 'leave_submitted', 'New leave request', 'A student from your hostel submitted a leave request for review.');
    } catch (Throwable $notificationError) {
        error_log('Unable to create leave notification: ' . $notificationError->getMessage());
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Student leave submission failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the leave request. Please try again.']);
    exit;
}

echo json_encode(['success' => true, 'leave_id' => $leaveId, 'message' => 'Leave request submitted to the warden for parent-call verification.']);