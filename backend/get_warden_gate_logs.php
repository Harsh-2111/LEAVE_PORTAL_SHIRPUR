<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$user = requireRole(['warden']);
if (!in_array($user['warden_gender'] ?? '', ['male', 'female'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Warden account has no hostel-group assignment.']);
    exit;
}
$wardenHostel = requireWardenHostel($user);

$campusTimezone = new DateTimeZone('Asia/Kolkata');
$date = trim((string)($_GET['date'] ?? (new DateTimeImmutable('now', $campusTimezone))->format('Y-m-d')));
$filter = trim((string)($_GET['filter'] ?? 'all'));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$dateErrors = DateTimeImmutable::getLastErrors();
$validDate = $parsedDate !== false
    && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
    && $parsedDate->format('Y-m-d') === $date;
$filters = ['all', 'outside', 'exited_today', 'entered_today', 'defaulters'];
$nextDate = $validDate ? $parsedDate->modify('+1 day')->format('Y-m-d') : '';

if (!$validDate || !in_array($filter, $filters, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Provide a valid date and attendance filter.']);
    exit;
}

$where = ['s.gender = ?', 'lr.hostel = ?'];
$params = [$user['warden_gender'], $wardenHostel];
switch ($filter) {
    case 'outside':
        $where[] = 'lr.status = ? AND lr.gate_out_time IS NOT NULL AND lr.gate_in_time IS NULL';
        $params[] = 'CHECKED_OUT';
        break;
    case 'exited_today':
        $where[] = 'lr.gate_out_time >= ? AND lr.gate_out_time < ?';
        $params[] = $date;
        $params[] = $nextDate;
        break;
    case 'entered_today':
        $where[] = 'lr.gate_in_time >= ? AND lr.gate_in_time < ?';
        $params[] = $date;
        $params[] = $nextDate;
        break;
    case 'defaulters':
        $where[] = 'lr.status = ? AND lr.gate_out_time IS NOT NULL AND lr.gate_in_time IS NULL';
        $params[] = 'CHECKED_OUT';
        break;
    case 'all':
        $where[] = '((lr.gate_out_time >= ? AND lr.gate_out_time < ?) OR (lr.gate_in_time >= ? AND lr.gate_in_time < ?) OR (lr.status = ? AND lr.gate_in_time IS NULL))';
        $params[] = $date;
        $params[] = $nextDate;
        $params[] = $date;
        $params[] = $nextDate;
        $params[] = 'CHECKED_OUT';
        break;
}

$sql = 'SELECT lr.id, lr.sap_id, s.name AS student_name, COALESCE(lr.room_no, s.room_no) AS room_no, s.hostel_block, '
    . 'lr.hostel AS destination, s.parent_contact AS parent_phone, lr.reason, lr.end_date, lr.end_time, '
    . 'lr.gate_out_time, lr.gate_in_time, lr.return_timing_status, lr.variance_summary, '
    . 'lr.status, CURRENT_TIMESTAMP AS evaluated_at '
    . 'FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id '
    . 'WHERE ' . implode(' AND ', $where)
    . ' ORDER BY COALESCE(lr.gate_in_time, lr.gate_out_time) DESC, lr.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

foreach ($items as $index => &$item) {
    $item['live_status'] = $item['return_timing_status'];
    if ($item['status'] === 'CHECKED_OUT' && $item['gate_in_time'] === null) {
        $now = new DateTimeImmutable((string)$item['evaluated_at']);
        $deadline = new DateTimeImmutable(
            (string)$item['end_date'] . ' ' . ((string)($item['end_time'] ?? '') ?: '23:59:59'),
            $campusTimezone
        );
        $deadlineWithGrace = $deadline->modify('+30 minutes');
        $item['live_status'] = $now > $deadlineWithGrace ? 'OVERDUE' : 'CURRENTLY OUTSIDE';
    }

    if ($item['live_status'] === 'OVERDUE' && empty($item['variance_summary'])) {
        $now = new DateTimeImmutable((string)$item['evaluated_at']);
        $deadline = new DateTimeImmutable(
            (string)$item['end_date'] . ' ' . ((string)($item['end_time'] ?? '') ?: '23:59:59'),
            $campusTimezone
        );
        $seconds = max(0, $now->getTimestamp() - $deadline->getTimestamp());
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($seconds < 60) {
            $parts[] = '<1m';
        } elseif ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        $item['variance_summary'] = 'Late by ' . implode(' ', $parts);
    }
    unset($item['evaluated_at']);
    if ($filter === 'defaulters' && $item['live_status'] !== 'OVERDUE') {
        unset($items[$index]);
    }
}
unset($item);
$items = array_values($items);

echo json_encode(['success' => true, 'date' => $date, 'filter' => $filter, 'items' => $items]);
