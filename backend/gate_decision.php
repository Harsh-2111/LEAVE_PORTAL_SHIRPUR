<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$user = requireRole(['security']);
requireCsrfToken();

$leaveId = (int)($_POST['leave_id'] ?? 0);
$passToken = trim((string)($_POST['pass_token'] ?? ''));
$decision = trim((string)($_POST['decision'] ?? ''));

if ($leaveId <= 0 || $passToken === '' || !in_array($decision, ['accept', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A verified pass and gate decision are required.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT sap_id, qr_code_data FROM leave_requests WHERE id = ? AND pass_token = ? AND status = \'Granted\' LIMIT 1 FOR UPDATE');
    $stmt->execute([$leaveId, $passToken]);
    $pass = $stmt->fetch();

    if (!$pass) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'This approved gate pass could not be confirmed. Scan it again.']);
        exit;
    }

    $qrCodeData = (string)($pass['qr_code_data'] ?? '');
    $lines = preg_split('/\R/', $qrCodeData);
    $signatureLine = is_array($lines) && count($lines) >= 2 ? array_pop($lines) : '';
    $signatureMatch = [];
    $validSignature = preg_match('/^Signature:\s*([a-fA-F0-9]{64})$/', trim((string)$signatureLine), $signatureMatch) === 1;
    if ($validSignature) {
        $secret = defined('QR_HMAC_SECRET') ? QR_HMAC_SECRET : 'change-me';
        $message = implode("\n", $lines);
        $expectedSignature = hash_hmac('sha256', $message, $secret);
        $validSignature = hash_equals($expectedSignature, $signatureMatch[1]);
    }

    if (!$validSignature) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This QR code is not a valid signed leave pass.']);
        exit;
    }

    $priorStmt = $pdo->prepare(
        'SELECT a.action FROM audit_log a INNER JOIN users u ON u.id = a.user_id '
        . 'WHERE a.entity = ? AND a.entity_id = ? AND a.action IN (?, ?) AND u.role = ? '
        . 'ORDER BY a.created_at DESC, a.id DESC LIMIT 1'
    );
    $priorStmt->execute(['leave_requests', $leaveId, 'gate_pass_accepted', 'gate_pass_rejected', 'security']);
    $prior = $priorStmt->fetch();

    if ($prior) {
        $pdo->commit();
        $savedDecision = $prior['action'] === 'gate_pass_accepted' ? 'accept' : 'reject';
        echo json_encode([
            'success' => true,
            'decision' => $savedDecision,
            'already_decided' => true,
            'message' => $savedDecision === 'accept' ? 'This pass was already accepted at the gate.' : 'This pass was already rejected at the gate.',
        ]);
        exit;
    }

    $action = $decision === 'accept' ? 'gate_pass_accepted' : 'gate_pass_rejected';
    writeAudit(
        $pdo,
        (int)$user['id'],
        $action,
        'leave_requests',
        $leaveId,
        ['sap_id' => trim((string)$pass['sap_id']), 'decision' => $decision],
        $_SERVER['REMOTE_ADDR'] ?? null
    );
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'decision' => $decision,
        'already_decided' => false,
        'message' => $decision === 'accept' ? 'Student accepted at the gate.' : 'Pass rejected at the gate.',
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the gate decision. Please try again.']);
}