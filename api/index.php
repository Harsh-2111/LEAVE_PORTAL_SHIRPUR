<?php
header('Cache-Control: no-store');

$endpoints = [
    'login.php' => 'login.php',
    'logout.php' => 'logout.php',
    'forgot_password.php' => 'forgot_password.php',
    'change_password.php' => 'change_password.php',
    'student_lookup.php' => 'student_lookup.php',
    'my_leaves.php' => 'my_leaves.php',
    'submit_student_leave.php' => 'submit_student_leave.php',
    'list_leaves.php' => 'list_leaves.php',
    'record_call.php' => 'record_call.php',
    'verify_pass.php' => 'verify_pass.php',
    'gate_decision.php' => 'gate_decision.php',
    'gate_history.php' => 'gate_history.php',
    'cleanup_history.php' => 'cleanup_history.php',
    'import_students.php' => 'import_students.php',
];

$endpoint = (string)($_GET['endpoint'] ?? '');
if (!isset($endpoints[$endpoint])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'API endpoint not found.']);
    exit;
}

unset($_GET['endpoint']);
require dirname(__DIR__) . '/backend/' . $endpoints[$endpoint];
