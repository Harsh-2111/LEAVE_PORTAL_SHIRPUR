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

if ($leaveId <= 0 || $passToken === '' || !in_array($decision, ['accept', 'reject', 'exit', 'entry'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A verified pass and gate decision are required.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT sap_id, qr_code_data, status, end_date, gate_out_time, gate_in_time FROM leave_requests WHERE id = ? AND pass_token = ? AND status IN (\'Granted\', \'CHECKED_OUT\', \'CHECKED_IN\') LIMIT 1 FOR UPDATE');
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

    if ($decision === 'exit' || $decision === 'entry') {
        if ($decision === 'exit') {
            if ($pass['status'] !== 'Granted' || $pass['gate_out_time'] !== null || $pass['gate_in_time'] !== null) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'This pass cannot be used to record a gate exit.']);
                exit;
            }

            $rejectedStmt = $pdo->prepare(
                'SELECT a.id FROM audit_log a INNER JOIN users u ON u.id = a.user_id '
                . 'WHERE a.entity = ? AND a.entity_id = ? AND a.action = ? AND u.role = ? LIMIT 1'
            );
            $rejectedStmt->execute(['leave_requests', $leaveId, 'gate_pass_rejected', 'security']);
            if ($rejectedStmt->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'This pass was rejected at the gate and cannot be used for exit.']);
                exit;
            }

            $update = $pdo->prepare(
                'UPDATE leave_requests SET gate_out_time = NOW(), status = ?, return_timing_status = ?, variance_summary = NULL '
                . 'WHERE id = ? AND gate_out_time IS NULL AND gate_in_time IS NULL AND status = ?'
            );
            $update->execute(['CHECKED_OUT', 'OUTSIDE', $leaveId, 'Granted']);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Gate exit state changed while recording the scan.');
            }

            writeAudit($pdo, (int)$user['id'], 'gate_pass_accepted', 'leave_requests', $leaveId, ['sap_id' => trim((string)$pass['sap_id']), 'decision' => 'exit'], $_SERVER['REMOTE_ADDR'] ?? null);
            writeAudit($pdo, (int)$user['id'], 'gate_exit_recorded', 'leave_requests', $leaveId, ['sap_id' => trim((string)$pass['sap_id'])], $_SERVER['REMOTE_ADDR'] ?? null);
            $pdo->commit();
            echo json_encode(['success' => true, 'decision' => 'exit', 'already_decided' => false, 'message' => 'Gate exit recorded.']);
            exit;
        }

        if ($pass['status'] !== 'CHECKED_OUT' || $pass['gate_out_time'] === null || $pass['gate_in_time'] !== null) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This pass cannot be used to record a gate entry.']);
            exit;
        }

        $clockStmt = $pdo->query('SELECT NOW() AS gate_time');
        $gateTimeValue = $clockStmt->fetchColumn();
        $gateTime = new DateTimeImmutable((string)$gateTimeValue);
        $gateOutTime = new DateTimeImmutable((string)$pass['gate_out_time'], $gateTime->getTimezone());
        if ($gateTime < $gateOutTime->modify('+24 hours')) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Gate entry can only be recorded after 24 hours have elapsed since the gate exit.']);
            exit;
        }

        $scheduledReturn = new DateTimeImmutable((string)$pass['end_date'], $gateTime->getTimezone());
        $bufferDeadline = $scheduledReturn->modify('+30 minutes');
        $varianceSummary = null;
        if ($gateTime > $bufferDeadline) {
            $timingStatus = 'LATE_RETURN';
            $varianceSeconds = $gateTime->getTimestamp() - $scheduledReturn->getTimestamp();
            $varianceLabel = 'Late by ';
        } elseif ($gateTime < $scheduledReturn) {
            $timingStatus = 'EARLY_RETURN';
            $varianceSeconds = $scheduledReturn->getTimestamp() - $gateTime->getTimestamp();
            $varianceLabel = 'Early by ';
        } else {
            $timingStatus = 'ON_TIME';
            $varianceSummary = 'On Time';
            $varianceSeconds = 0;
            $varianceLabel = '';
        }

        if ($timingStatus !== 'ON_TIME') {
            $days = intdiv($varianceSeconds, 86400);
            $hours = intdiv($varianceSeconds % 86400, 3600);
            $minutes = intdiv($varianceSeconds % 3600, 60);
            $parts = [];
            if ($days > 0) {
                $parts[] = $days . 'd';
            }
            if ($hours > 0) {
                $parts[] = $hours . 'h';
            }
            if ($varianceSeconds < 60) {
                $parts[] = '<1m';
            } elseif ($minutes > 0) {
                $parts[] = $minutes . 'm';
            }
            $varianceSummary = substr($varianceLabel . implode(' ', $parts), 0, 100);
        }

        $update = $pdo->prepare(
            'UPDATE leave_requests SET gate_in_time = NOW(), status = ?, return_timing_status = ?, variance_summary = ? '
            . 'WHERE id = ? AND gate_out_time IS NOT NULL AND gate_in_time IS NULL AND status = ?'
        );
        $update->execute(['CHECKED_IN', $timingStatus, $varianceSummary, $leaveId, 'CHECKED_OUT']);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Gate entry state changed while recording the scan.');
        }

        writeAudit($pdo, (int)$user['id'], 'gate_entry_recorded', 'leave_requests', $leaveId, ['sap_id' => trim((string)$pass['sap_id']), 'return_timing_status' => $timingStatus, 'variance_summary' => $varianceSummary], $_SERVER['REMOTE_ADDR'] ?? null);
        $pdo->commit();
        echo json_encode(['success' => true, 'decision' => 'entry', 'return_timing_status' => $timingStatus, 'variance_summary' => $varianceSummary, 'already_decided' => false, 'message' => 'Gate entry recorded: ' . $varianceSummary . '.']);
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

    if ($pass['status'] !== 'Granted') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This pass is no longer eligible for a gate decision.']);
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