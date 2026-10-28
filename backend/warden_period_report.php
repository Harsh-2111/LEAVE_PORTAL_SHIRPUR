<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$user = requireRole(['warden']);
if (!in_array($user['warden_gender'] ?? '', ['male', 'female'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Warden account has no hostel-group assignment.']);
    exit;
}
$wardenHostel = requireWardenHostel($user);

$period = trim((string)($_GET['period'] ?? ''));
if (!in_array($period, ['weekly', 'monthly'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Choose a weekly or monthly report.']);
    exit;
}

$timezone = new DateTimeZone('Asia/Kolkata');
$today = new DateTimeImmutable('today', $timezone);
if ($period === 'weekly') {
    $startDate = $today->modify('-' . ((int)$today->format('N') - 1) . ' days');
    $endDate = $startDate->modify('+6 days');
    $filename = 'Hostel_Weekly_Leave_Gate_Report_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.csv';
} else {
    $startDate = $today->modify('first day of this month');
    $endDate = $today->modify('last day of this month');
    $filename = 'Hostel_Monthly_Leave_Gate_Report_' . $today->format('Y-m') . '.csv';
}

$columns = [
    'SAP ID',
    'Student Name',
    'Hostel Block',
    'Room No',
    'Parent Phone',
    'Destination',
    'School',
    'Leave Reason',
    'Scheduled Leave Start',
    'Scheduled Return',
    'Leave Status',
    'Approved At',
    'Approved By',
    'Gate Exit Time',
    'Exit Recorded By',
    'Gate Entry Time',
    'Entry Recorded By',
    'Return Timing Status',
    'Variance',
];

$sql = 'SELECT lr.sap_id, s.name AS student_name, s.hostel_block, COALESCE(lr.room_no, s.room_no) AS room_no, s.parent_contact, '
    . 'lr.hostel AS destination, lr.school, lr.reason, lr.start_date, lr.start_time, lr.end_date, lr.end_time, '
    . 'lr.status, lr.granted_at, approver.name AS approved_by, lr.gate_out_time, lr.gate_in_time, lr.return_timing_status, lr.variance_summary, '
    . '(SELECT u.name FROM audit_log a INNER JOIN users u ON u.id = a.user_id '
    . 'WHERE a.entity = ? AND a.entity_id = lr.id AND a.action = ? AND u.role = ? '
    . 'ORDER BY a.created_at DESC, a.id DESC LIMIT 1) AS exit_recorded_by, '
    . '(SELECT u.name FROM audit_log a INNER JOIN users u ON u.id = a.user_id '
    . 'WHERE a.entity = ? AND a.entity_id = lr.id AND a.action = ? AND u.role = ? '
    . 'ORDER BY a.created_at DESC, a.id DESC LIMIT 1) AS entry_recorded_by '
    . 'FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id '
    . 'LEFT JOIN users approver ON approver.id = lr.granted_by '
    . 'WHERE s.gender = ? AND lr.hostel = ? AND lr.status IN (?, ?, ?) '
    . 'AND lr.start_date <= ? AND lr.end_date >= ? '
    . 'ORDER BY lr.start_date, s.name';
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'leave_requests',
    'gate_exit_recorded',
    'security',
    'leave_requests',
    'gate_entry_recorded',
    'security',
    $user['warden_gender'],
    $wardenHostel,
    'Granted',
    'CHECKED_OUT',
    'CHECKED_IN',
    $endDate->format('Y-m-d'),
    $startDate->format('Y-m-d'),
]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, private');

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit;
}

fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $columns);

while ($record = $stmt->fetch()) {
    $leaveStart = trim((string)$record['start_date'] . ' ' . (string)($record['start_time'] ?? ''));
    $scheduledReturn = trim((string)$record['end_date'] . ' ' . (string)($record['end_time'] ?? ''));
    $values = [
        $record['sap_id'],
        $record['student_name'],
        $record['hostel_block'],
        $record['room_no'],
        $record['parent_contact'],
        $record['destination'],
        $record['school'],
        $record['reason'],
        $leaveStart,
        $scheduledReturn,
        $record['status'],
        $record['granted_at'],
        $record['approved_by'],
        $record['gate_out_time'],
        $record['exit_recorded_by'],
        $record['gate_in_time'],
        $record['entry_recorded_by'],
        $record['return_timing_status'],
        $record['variance_summary'],
    ];
    $values = array_map(static function ($value): string {
        $text = (string)($value ?? '');
        return preg_match('/^\s*[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }, $values);
    fputcsv($output, $values, ',', '"', '');
}

fclose($output);
