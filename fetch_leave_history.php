<?php
// fetch_leave_history.php
require_once 'db.php';

$student_id = $_GET['student_id'] ?? '';
if (!$student_id) {
    http_response_code(400);
    echo json_encode(['error' => 'student_id required']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM leave_requests WHERE student_id = ? ORDER BY timestamp DESC");
$stmt->bind_param('s', $student_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'requests' => $rows]);
