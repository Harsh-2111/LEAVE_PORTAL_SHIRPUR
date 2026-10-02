<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$user = requireAuth();
if (!in_array($user['role'], ['student', 'warden', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied for this role.']);
    exit;
}

$sapId = trim((string)($_GET['sap_id'] ?? ''));
if ($sapId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'SAP ID is required.']);
    exit;
}

$stmt = $pdo->prepare('SELECT sap_id, name, student_contact, gender, course, year, branch, batch, hostel_block, room_no, parent_name, parent_email, parent_contact, is_active FROM students WHERE sap_id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$sapId]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Student not found.']);
    exit;
}

if ($user['role'] === 'student' && $sapId !== $user['login_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Students can only access their own profile.']);
    exit;
}

if ($user['role'] === 'warden' && ($user['warden_gender'] ?? '') !== $student['gender']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This student belongs to the other warden group.']);
    exit;
}

echo json_encode(['success' => true, 'student' => $student]);
