<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/guard.php';
ensureSessionStarted();

if (
    !isset($_SESSION['user']) ||
    !is_array($_SESSION['user']) ||
    !isset($_SESSION['user']['id'], $_SESSION['user']['role'])
) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $_SESSION['user'];

echo json_encode([
    'success' => true,
    'user' => [
        'id' => (int) $user['id'],
        'role' => (string) $user['role'],
        'login' => (string) ($user['login'] ?? $user['email'] ?? '')
    ]
], JSON_UNESCAPED_UNICODE);
