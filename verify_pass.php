<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$user = requireRole(['security', 'warden', 'admin']);

$sapId = trim((string)($_GET['sap_id'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

if ($sapId === '' && $token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Provide either a SAP ID or a pass token.']);
    exit;
}

$sql = 'SELECT lr.id, lr.sap_id, s.name AS student_name, s.gender, lr.status, lr.pass_token, lr.qr_code_data, lr.start_date, lr.end_date, lr.reason FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id WHERE lr.status = \'Granted\' AND lr.pass_token IS NOT NULL';
$params = [];

if ($user['role'] === 'warden') {
    if (!in_array($user['warden_gender'] ?? '', ['male', 'female'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Warden account has no hostel-group assignment.']);
        exit;
    }
    $sql .= ' AND s.gender = ?';
    $params[] = $user['warden_gender'];
}

if ($sapId !== '') {
    $sql .= ' AND lr.sap_id = ?';
    $params[] = $sapId;
}

if ($token !== '') {
    $sql .= ' AND lr.pass_token = ?';
    $params[] = $token;
}

$sql .= ' ORDER BY lr.granted_at DESC LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No valid pass found for the supplied SAP ID or token.']);
    exit;
}

$validQr = false;
$secret = defined('QR_HMAC_SECRET') ? QR_HMAC_SECRET : 'change-me';
$qrCodeData = (string)($record['qr_code_data'] ?? '');

if ($qrCodeData !== '') {
    $lines = preg_split('/\R/', $qrCodeData);
    if (is_array($lines) && count($lines) >= 2) {
        $signatureLine = array_pop($lines);
        $signatureMatch = [];
        if (preg_match('/^Signature:\s*([a-fA-F0-9]{64})$/', trim((string)$signatureLine), $signatureMatch) === 1) {
            $message = implode("\n", $lines);
            $signature = hash_hmac('sha256', $message, $secret);
            $validQr = hash_equals($signature, $signatureMatch[1]);
        }
    }
}

$payload = [
    'success' => true,
    'leave_id' => (int)$record['id'],
    'sap_id' => $record['sap_id'],
    'student_name' => $record['student_name'],
    'status' => $record['status'],
    'pass_token' => $record['pass_token'],
    'valid_qr' => $validQr,
    'start_date' => $record['start_date'],
    'end_date' => $record['end_date'],
    'reason' => $record['reason'],
];

echo json_encode($payload);
