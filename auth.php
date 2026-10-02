<?php
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_name('hostel_leave_sid');
        session_start();
    }
}

function issueCsrfToken(): string
{
    startSecureSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    startSecureSession();

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

function requireAuth(): array
{
    startSecureSession();

    if (empty($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }

    return $_SESSION['user'];
}

function requireRole(array $roles): array
{
    $user = requireAuth();

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied for this role.']);
        exit;
    }

    return $user;
}

function writeAudit(PDO $pdo, ?int $userId, string $action, string $entity, ?int $entityId = null, array $details = [], ?string $ip = null): void
{
    $sql = 'INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())';
    $stmt = $pdo->prepare($sql);
    $payload = json_encode($details, JSON_UNESCAPED_SLASHES);
    $stmt->execute([$userId, $action, $entity, $entityId, $payload, $ip]);
}
