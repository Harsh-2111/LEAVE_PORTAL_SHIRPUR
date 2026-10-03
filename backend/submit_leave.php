<?php
// submit_leave.php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

// Read fields (basic sanitization)
$student_name = $_POST['student_name'] ?? '';
$attendance = $_POST['attendance'] ?? 0;
$year = $_POST['year'] ?? '';
$student_id = $_POST['student_id'] ?? '';
$branch = $_POST['branch'] ?? '';
$batch = $_POST['batch'] ?? '';
$parent_email = $_POST['parent_email'] ?? '';
$parent_contact = $_POST['parent_contact'] ?? '';
$leave_days = intval($_POST['leave_days'] ?? 0);
$start_date = $_POST['start_date'] ?? null;
$end_date = $_POST['end_date'] ?? null;
$reason = $_POST['reason'] ?? '';
$teacher = $_POST['teacher'] ?? '';
$hod_assigned = $_POST['hod_assigned'] ?? '';
$timestamp = isset($_POST['timestamp']) ? intval($_POST['timestamp']) : round(microtime(true)*1000);

// Basic required checks
if (!$student_name || !$student_id || !$start_date || !$end_date || !$reason) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Prevent duplicates: same student, same start,end and pending
$dupStmt = $conn->prepare("SELECT id FROM leave_requests WHERE student_id=? AND start_date=? AND end_date=? AND status='Pending' LIMIT 1");
$dupStmt->bind_param('sss', $student_id, $start_date, $end_date);
$dupStmt->execute();
$dupRes = $dupStmt->get_result();
if ($dupRes->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Duplicate pending request exists for these dates']);
    $dupStmt->close();
    exit;
}
$dupStmt->close();

$insert = $conn->prepare("INSERT INTO leave_requests
(student_name, attendance, year, student_id, branch, batch, parent_email, parent_contact, leave_days, start_date, end_date, reason, teacher, hod_assigned, dean_assigned, timestamp, status)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, 'Pending')");
$insert->bind_param('sdssssssisssssi', $student_name, $attendance, $year, $student_id, $branch, $batch, $parent_email, $parent_contact, $leave_days, $start_date, $end_date, $reason, $teacher, $hod_assigned, $timestamp);
$ok = $insert->execute();
if ($ok) {
    echo json_encode(['success' => true, 'message' => 'Leave request submitted']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $conn->error]);
}
$insert->close();
