<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = requireAuth();
$csrfToken = issueCsrfToken();

echo json_encode([
    'success' => true,
    'user' => $user,
    'csrf_token' => $csrfToken,
]);