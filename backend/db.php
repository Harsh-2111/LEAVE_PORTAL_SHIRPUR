<?php
require_once __DIR__ . '/config.php';

$dsn = match (DB_DRIVER) {
    'mysql' => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
    'pgsql' => sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', DB_HOST, DB_PORT, DB_NAME, DB_SSLMODE),
    default => null,
};

if ($dsn === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unsupported database driver.']);
    exit;
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => DB_DRIVER === 'pgsql',
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.',
        'detail' => APP_ENV === 'development' ? $e->getMessage() : null,
    ]);
    exit;
}
