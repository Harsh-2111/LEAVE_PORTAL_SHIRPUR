<?php
require_once __DIR__ . '/db.php';

$role = $_GET['role'] ?? '';
$name = $_GET['name'] ?? '';
$history = $_GET['history'] ?? ''; 

if (!$role || !$name) {
    http_response_code(400);
    echo json_encode(['error' => 'role & name required']);
    exit;
}

$rows = [];
$sql = '';
$types = '';
$params = [];

$one_month_ago = date('Y-m-d', strtotime('-30 days'));

if ($role === 'teacher') {
    $types = 's';
    $params[] = $name;
    if ($history === '1') {
        $sql = "SELECT * FROM leave_requests WHERE status!='Pending' AND teacher = ? AND end_date >= ? ORDER BY timestamp DESC";
        $types = 'ss';
        $params[] = $one_month_ago;
    } else {
        $sql = "SELECT * FROM leave_requests WHERE status='Pending' AND teacher = ? ORDER BY timestamp DESC";
    }

} elseif ($role === 'hod') {
    $types = 's';
    $params[] = $name;
    if ($history === '1') {
        $sql = "SELECT * FROM leave_requests WHERE (status='Granted' OR status='Rejected' OR status='HOD Approved') AND hod_assigned = ? AND end_date >= ? ORDER BY timestamp DESC";
        $types = 'ss';
        $params[] = $one_month_ago;
    } else {
        $sql = "SELECT * FROM leave_requests WHERE status='Teacher Approved' AND hod_assigned = ? ORDER BY timestamp DESC";
    }

} elseif ($role === 'dean') {
    if ($history === '1') {
        $sql = "SELECT * FROM leave_requests WHERE (status='Granted' OR status='Rejected') AND leave_days > 3 AND end_date >= ? ORDER BY timestamp DESC";
        $types = 's';
        $params[] = $one_month_ago;
    } else {
        $sql = "SELECT * FROM leave_requests WHERE status='HOD Approved' AND leave_days > 3 ORDER BY timestamp DESC";
    }

} else {
    echo json_encode(['success' => true, 'requests' => []]);
    exit;
}

if (empty($sql)) {
    http_response_code(500);
    echo json_encode(['error' => 'Logic error: SQL query not determined']);
    exit;
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL preparation failed: ' . $conn->error]);
    exit;
}

if (!empty($params)) {
    if (count($params) === 1) {
        $stmt->bind_param($types, $params[0]);
    } elseif (count($params) === 2) {
        $stmt->bind_param($types, $params[0], $params[1]);
    }
}

$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'requests' => $rows]);