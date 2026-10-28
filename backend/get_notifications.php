<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

$user = requireRole(['student', 'warden']);

try {
    $tableCheck = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = 'notifications'");
    $tableCheck->execute([DB_DRIVER === 'pgsql' ? 'public' : DB_NAME]);
    if (!$tableCheck->fetchColumn()) {
        ensureNotificationsTable($pdo);
    }
} catch (Throwable $tableError) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Notification storage is unavailable.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'clear') {
        $delete = $pdo->prepare('DELETE FROM notifications WHERE user_id = ?');
        $delete->execute([(int)$user['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
    $id = (int)($_POST['notification_id'] ?? 0);
    if ($action === 'read' && $id > 0) {
        $update = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
        $update->execute([$id, (int)$user['id']]);
    } elseif ($action === 'read_all') {
        $update = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $update->execute([(int)$user['id']]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown notification action.']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET or POST requests are allowed.']);
    exit;
}
if ($user['role'] === 'warden' || $user['role'] === 'student') {
    try {
        $overdueSql = 'SELECT lr.id, lr.sap_id, lr.end_date, lr.end_time FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id '
            . 'WHERE lr.status = ? AND lr.gate_out_time IS NOT NULL AND lr.gate_in_time IS NULL';
        $overdueParams = ['CHECKED_OUT'];
        if ($user['role'] === 'student') {
            $overdueSql .= ' AND lr.sap_id = ?';
            $overdueParams[] = (string)$user['login_id'];
        } else {
            $overdueSql .= ' AND s.gender = ? AND lr.hostel = ?';
            $overdueParams[] = (string)($user['warden_gender'] ?? '');
            $overdueParams[] = requireWardenHostel($user);
        }
        $overdueSql .= ' ORDER BY lr.end_date ASC, lr.end_time ASC LIMIT 500';
        $overdueStmt = $pdo->prepare($overdueSql);
        $overdueStmt->execute($overdueParams);
        $alreadyNotified = $pdo->prepare('SELECT 1 FROM notifications WHERE user_id = ? AND leave_id = ? AND type = ? LIMIT 1');
        $campusTimezone = new DateTimeZone('Asia/Kolkata');
        $now = new DateTimeImmutable('now', $campusTimezone);
        foreach ($overdueStmt->fetchAll() as $overdue) {
            $scheduledReturn = new DateTimeImmutable(
                (string)$overdue['end_date'] . ' ' . ((string)($overdue['end_time'] ?? '') ?: '23:59:59'),
                $campusTimezone
            );
            if ($now <= $scheduledReturn->modify('+30 minutes')) continue;
            foreach (notificationRecipients($pdo, (int)$overdue['id']) as $recipientId) {
                $alreadyNotified->execute([(int)$recipientId, (int)$overdue['id'], 'overdue_return']);
                if (!$alreadyNotified->fetchColumn()) {
                    insertNotifications(
                        $pdo,
                        [(int)$recipientId],
                        (int)$overdue['id'],
                        'overdue_return',
                        'Student overdue',
                        'The student has not checked back in after the scheduled return time.'
                    );
                }
            }
        }
    } catch (Throwable $overdueError) {
        error_log('Unable to process overdue return notifications: ' . $overdueError->getMessage());
    }
}
$notificationTable = DB_DRIVER === 'pgsql' ? 'public.notifications' : 'notifications';
$profileJoin = ' LEFT JOIN leave_requests lr ON lr.id = n.leave_id LEFT JOIN students s ON s.sap_id = lr.sap_id';
$notificationScope = '';
$notificationParams = [(int)$user['id']];
if ($user['role'] === 'warden') {
    $notificationScope = ' AND s.gender = ? AND lr.hostel = ?';
    $notificationParams[] = (string)($user['warden_gender'] ?? '');
    $notificationParams[] = requireWardenHostel($user);
}
$stmt = $pdo->prepare(
    'SELECT n.id, n.leave_id, n.type, n.title, n.message, n.link, n.read_at, n.created_at, '
    . 's.name AS student_name, s.year AS student_year, s.hostel_block, COALESCE(lr.room_no, s.room_no) AS room_no, lr.sap_id '
    . 'FROM ' . $notificationTable . ' n' . $profileJoin
    . ' WHERE n.user_id = ?' . $notificationScope
    . ' ORDER BY n.created_at DESC, n.id DESC LIMIT 50'
);
$stmt->execute($notificationParams);
$items = $stmt->fetchAll();
$count = $pdo->prepare(
    'SELECT COUNT(*) FROM ' . $notificationTable . ' n' . $profileJoin
    . ' WHERE n.user_id = ? AND n.read_at IS NULL' . $notificationScope
);
$count->execute($notificationParams);
$unread = (int)$count->fetchColumn();
echo json_encode(['success' => true, 'unread_count' => $unread, 'items' => $items]);
