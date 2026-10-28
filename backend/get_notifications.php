<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

$user = requireRole(['student', 'warden']);

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
$stmt = $pdo->prepare('SELECT id, leave_id, type, title, message, link, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute([(int)$user['id']]);
$items = $stmt->fetchAll();
$unread = 0;
foreach ($items as $item) if ($item['read_at'] === null) $unread++;
echo json_encode(['success' => true, 'unread_count' => $unread, 'items' => $items]);
