<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = requireRole(['admin', 'warden', 'student']);
$hostels = ['BOYS HOSTEL 1', 'NEW BOYS HOSTEL', 'NEW GIRLS HOSTEL', 'OLD GIRLS HOSTEL'];
$schema = DB_DRIVER === 'pgsql' ? 'public' : DB_NAME;
$exists = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = ? LIMIT 1');
$exists->execute(['announcements', $schema]);
if (!$exists->fetchColumn()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Announcement storage is not configured. Apply the portal-features database migration.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only administrators can manage announcements.']);
        exit;
    }
    requireCsrfToken();
    $action = trim((string)($_POST['action'] ?? 'create'));
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Announcement ID is required.']);
            exit;
        }
        $delete = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
        $delete->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Announcement deleted.']);
        exit;
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $targetRole = trim((string)($_POST['target_role'] ?? 'all'));
    $targetHostel = trim((string)($_POST['target_hostel'] ?? ''));
    $expires = trim((string)($_POST['expires_at'] ?? ''));
    if ($title === '' || strlen($title) > 150 || $body === '' || strlen($body) > 5000
        || !in_array($targetRole, ['all', 'student', 'warden'], true)
        || ($targetHostel !== '' && !in_array($targetHostel, $hostels, true))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Check the title, message, audience, and hostel fields.']);
        exit;
    }
    $expiresAt = null;
    if ($expires !== '') {
        $expiry = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $expires, new DateTimeZone('Asia/Kolkata'));
        if (!$expiry || $expiry->format('Y-m-d\TH:i') !== $expires) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Choose a valid expiry time.']);
            exit;
        }
        if ($expiry <= new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Announcement expiry must be in the future.']);
            exit;
        }
        $expiresAt = $expiry->format(DB_DRIVER === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s');
    }
    $createSql = 'INSERT INTO announcements (title, body, target_role, target_hostel, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())';
    if (DB_DRIVER === 'pgsql') $createSql .= ' RETURNING id';
    $create = $pdo->prepare($createSql);
    $create->execute([$title, $body, $targetRole, $targetHostel !== '' ? $targetHostel : null, $expiresAt, (int)$user['id']]);
    $announcementId = DB_DRIVER === 'pgsql' ? (int)$create->fetchColumn() : (int)$pdo->lastInsertId();
    writeAudit($pdo, (int)$user['id'], 'create_announcement', 'announcements', $announcementId, ['target_role' => $targetRole, 'target_hostel' => $targetHostel], $_SERVER['REMOTE_ADDR'] ?? null);
    echo json_encode(['success' => true, 'message' => 'Announcement published.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET or POST requests are allowed.']);
    exit;
}

$params = [];
$conditions = ['(expires_at IS NULL OR expires_at > NOW())'];
if ($user['role'] !== 'admin') {
    $conditions[] = '(target_role = ? OR target_role = ?)';
    $params[] = 'all';
    $params[] = $user['role'];
    if ($user['role'] === 'warden') {
        $hostel = requireWardenHostel($user);
    } else {
        $studentStmt = $pdo->prepare(
            'SELECT COALESCE((SELECT lr.hostel FROM leave_requests lr WHERE lr.sap_id = s.sap_id ORDER BY lr.created_at DESC LIMIT 1), s.hostel_block) FROM students s WHERE s.sap_id = ? LIMIT 1'
        );
        $studentStmt->execute([$user['login_id']]);
        $hostel = (string)($studentStmt->fetchColumn() ?: '');
    }
    $conditions[] = '(target_hostel IS NULL OR target_hostel = ?)';
    $params[] = $hostel;
}
$stmt = $pdo->prepare('SELECT id, title, body, target_role, target_hostel, expires_at, created_at FROM announcements WHERE ' . implode(' AND ', $conditions) . ' ORDER BY created_at DESC, id DESC LIMIT 100');
$stmt->execute($params);
echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
