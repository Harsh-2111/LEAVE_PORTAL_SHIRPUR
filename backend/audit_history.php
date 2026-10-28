<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = requireRole(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;
$action = trim((string)($_GET['action'] ?? ''));
$params = [];
$where = '';
if ($action !== '') {
    $where = ' WHERE a.action LIKE ?';
    $params[] = '%' . $action . '%';
}
$query = 'SELECT a.id, a.action, a.entity, a.entity_id, a.details, a.ip, a.created_at, u.name AS actor_name, u.role AS actor_role '
    . 'FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . $where
    . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($query);
$stmt->execute(array_merge($params, [$limit, $offset]));
$items = $stmt->fetchAll();
$count = $pdo->prepare('SELECT COUNT(*) FROM audit_log a' . $where);
$count->execute($params);
echo json_encode(['success' => true, 'page' => $page, 'limit' => $limit, 'total' => (int)$count->fetchColumn(), 'items' => $items]);
