<?php
final class PdoSessionHandler implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string|false
    {
        $stmt = $this->pdo->prepare('SELECT session_data FROM app_sessions WHERE session_id = ? AND expires_at > ? LIMIT 1');
        $stmt->execute([$sessionId, time()]);
        $data = $stmt->fetchColumn();
        return $data === false ? '' : (string)$data;
    }

    public function write(string $sessionId, string $sessionData): bool
    {
        $expiresAt = time() + (int)ini_get('session.gc_maxlifetime');
        $stmt = $this->pdo->prepare('INSERT INTO app_sessions (session_id, session_data, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE session_data = VALUES(session_data), expires_at = VALUES(expires_at)');
        return $stmt->execute([$sessionId, $sessionData, $expiresAt]);
    }

    public function destroy(string $sessionId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE session_id = ?');
        return $stmt->execute([$sessionId]);
    }

    public function gc(int $maxLifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE expires_at <= ?');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            require_once __DIR__ . '/db.php';
        }
        $pdo = $GLOBALS['pdo'];

        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
        session_name('hostel_leave_sid');

        session_set_save_handler(new PdoSessionHandler($pdo), true);
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
