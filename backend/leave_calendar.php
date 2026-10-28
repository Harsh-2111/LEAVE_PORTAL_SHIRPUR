<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = requireRole(['student', 'warden', 'admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to = trim((string)($_GET['to'] ?? date('Y-m-t', strtotime('+2 months'))));
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$validDate($from) || !$validDate($to) || $to < $from) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid calendar date range.']);
    exit;
}

$where = [
    'lr.start_date <= ? AND lr.end_date >= ?',
    'lr.status NOT IN (?, ?)',
];
$params = [$to, $from, 'Rejected', 'Cancelled'];
if ($user['role'] === 'student') {
    $where[] = 'lr.sap_id = ?';
    $params[] = $user['login_id'];
} elseif ($user['role'] === 'warden') {
    $where[] = 's.gender = ? AND lr.hostel = ?';
    $params[] = (string)($user['warden_gender'] ?? '');
    $params[] = requireWardenHostel($user);
}
$overlapSelect = $user['role'] === 'warden'
    ? ', (SELECT COUNT(*) FROM leave_requests other_lr '
        . 'WHERE other_lr.id <> lr.id AND other_lr.hostel = lr.hostel '
        . 'AND other_lr.status NOT IN (\'Rejected\', \'Cancelled\', \'CHECKED_IN\') '
        . 'AND other_lr.start_date <= lr.end_date AND other_lr.end_date >= lr.start_date) AS overlap_count '
    : ', 0 AS overlap_count ';
$stmt = $pdo->prepare(
    'SELECT lr.id, lr.sap_id, s.name, lr.start_date, lr.end_date, lr.hostel, lr.status' . $overlapSelect
    . 'FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id WHERE '
    . implode(' AND ', $where)
    . ' ORDER BY lr.start_date, s.name LIMIT 300'
);
$stmt->execute($params);
echo json_encode(['success' => true, 'from' => $from, 'to' => $to, 'items' => $stmt->fetchAll()]);
