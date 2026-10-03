<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$cronSecret = (string)getenv('CRON_SECRET');
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($cronSecret === '' || !hash_equals('Bearer ' . $cronSecret, $authorization)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Scheduled cleanup authorization failed.']);
    exit;
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$month = (int)$now->format('n');
if ($now->format('j') !== '1' || !in_array($month, [1, 3, 5, 7, 9, 11], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Cleanup only runs on the first day of each scheduled two-month period.']);
    exit;
}

$cutoff = $now->format('Y-m-d 00:00:00');

try {
    $pdo->beginTransaction();

    $audit = $pdo->prepare(
        'DELETE FROM audit_log WHERE entity = ? '
        . 'AND entity_id IN (SELECT id FROM leave_requests WHERE created_at < ?)'
    );
    $audit->execute(['leave_requests', $cutoff]);
    $deletedAuditRows = $audit->rowCount();

    $calls = $pdo->prepare('DELETE FROM leave_call_log WHERE leave_id IN (SELECT id FROM leave_requests WHERE created_at < ?)');
    $calls->execute([$cutoff]);

    $leaves = $pdo->prepare('DELETE FROM leave_requests WHERE created_at < ?');
    $leaves->execute([$cutoff]);
    $deletedLeaveRows = $leaves->rowCount();

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'cutoff' => $cutoff,
        'deleted_leave_requests' => $deletedLeaveRows,
        'deleted_audit_rows' => $deletedAuditRows,
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Scheduled history cleanup failed.']);
}