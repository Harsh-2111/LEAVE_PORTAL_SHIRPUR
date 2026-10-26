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

$date = trim((string)($_GET['date'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$dateErrors = DateTimeImmutable::getLastErrors();
$validDate = $parsedDate !== false
    && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
    && $parsedDate->format('Y-m-d') === $date;

if (!$validDate || !in_array($type, ['left', 'entered'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Provide a valid date and export type.']);
    exit;
}

$eventColumn = $type === 'left' ? 'gate_out_time' : 'gate_in_time';
$columns = $type === 'left'
    ? ['SAP ID', 'Student Name', 'Hostel Block', 'Room No', 'Parent Phone', 'Destination', 'Reason', 'Gate Exit Time', 'Scheduled Return']
    : ['SAP ID', 'Student Name', 'Hostel Block', 'Room No', 'Parent Phone', 'Destination', 'Gate Exit Time', 'Scheduled Return', 'Gate Entry Time', 'Return Timing Status', 'Variance (Late/Early Details)'];

$sql = 'SELECT lr.sap_id, s.name AS student_name, s.hostel_block, COALESCE(lr.room_no, s.room_no) AS room_no, '
    . 's.parent_contact AS parent_phone, lr.hostel AS destination, lr.reason, '
    . 'lr.gate_out_time, lr.end_date, lr.gate_in_time, lr.return_timing_status, lr.variance_summary '
    . 'FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id '
    . 'WHERE s.gender = ? AND CAST(lr.' . $eventColumn . ' AS DATE) = ? '
    . 'ORDER BY lr.' . $eventColumn . ', s.name';
$stmt = $pdo->prepare($sql);
$stmt->execute([$user['warden_gender'], $date]);

$filename = $type === 'left'
    ? 'Hostel_Gate_Departures_' . $date . '.csv'
    : 'Hostel_Gate_Arrivals_' . $date . '.csv';
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
    $values = $type === 'left'
        ? [$record['sap_id'], $record['student_name'], $record['hostel_block'], $record['room_no'], $record['parent_phone'], $record['destination'], $record['reason'], $record['gate_out_time'], $record['end_date']]
        : [$record['sap_id'], $record['student_name'], $record['hostel_block'], $record['room_no'], $record['parent_phone'], $record['destination'], $record['gate_out_time'], $record['end_date'], $record['gate_in_time'], $record['return_timing_status'], $record['variance_summary']];
    $values = array_map(static function ($value): string {
        $text = (string)($value ?? '');
        return preg_match('/^\s*[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }, $values);
    fputcsv($output, $values, ',', '"', '');
}

fclose($output);
