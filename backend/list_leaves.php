<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$user = requireRole(['warden', 'admin', 'student']);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$status = trim((string)($_GET['status'] ?? ''));
$sapId = trim((string)($_GET['sap_id'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));

$where = [];
$params = [];

if ($user['role'] === 'student') {
    $where[] = 'lr.sap_id = ?';
    $params[] = $user['login_id'];
}

if ($user['role'] === 'warden') {
    if (!in_array($user['warden_gender'] ?? '', ['male', 'female'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Warden account has no hostel-group assignment.']);
        exit;
    }
    $where[] = 's.gender = ? AND lr.hostel = ?';
    $params[] = $user['warden_gender'];
    $params[] = requireWardenHostel($user);
}

if ($sapId !== '') {
    $where[] = 'lr.sap_id = ?';
    $params[] = $sapId;
}

if ($status !== '') {
    if ($status === 'Granted' && $user['role'] === 'warden') {
        $where[] = 'lr.status IN (?, ?, ?)';
        array_push($params, 'Granted', 'CHECKED_OUT', 'CHECKED_IN');
    } else {
        $where[] = 'lr.status = ?';
        $params[] = $status;
    }
}

if ($status === 'Pending Verification' && $user['role'] === 'warden') {
    $where[] = 'NOT EXISTS ('
        . 'SELECT 1 FROM leave_call_log lcl '
        . 'WHERE lcl.leave_id = lr.id '
        . 'AND lcl.id = (SELECT MAX(latest_call.id) FROM leave_call_log latest_call WHERE latest_call.leave_id = lr.id) '
        . 'AND lcl.outcome IN (?, ?)'
        . ')';
    array_push($params, 'Not Reachable', 'Wrong Person');
}

if ($startDate !== '') {
    $where[] = 'lr.start_date >= ?';
    $params[] = $startDate;
}

if ($endDate !== '') {
    $where[] = 'lr.end_date <= ?';
    $params[] = $endDate;
}

if ($search !== '') {
    $where[] = '(s.name LIKE ? OR lr.sap_id LIKE ? OR lr.reason LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql = 'SELECT lr.id, lr.sap_id, s.name, s.gender, s.student_contact, s.course, s.year, s.branch, s.batch, s.parent_email, s.parent_contact, lr.room_no, lr.start_date, lr.start_time, lr.end_date, lr.end_time, lr.leave_days, lr.reason, lr.home_address, lr.school, lr.hostel, lr.status, lr.email_from, lr.parent_email_matched, lr.granted_by, lr.granted_at, lr.created_at FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY lr.created_at DESC LIMIT ? OFFSET ?';
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$countSql = 'SELECT COUNT(*) AS total FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id';
if (!empty($where)) {
    $countSql .= ' WHERE ' . implode(' AND ', $where);
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute(array_slice($params, 0, -2));
$total = (int)$countStmt->fetch()['total'];

echo json_encode(['success' => true, 'page' => $page, 'limit' => $limit, 'total' => $total, 'items' => $rows]);
