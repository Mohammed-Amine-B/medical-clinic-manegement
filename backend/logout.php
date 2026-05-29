<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

$logoutUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
try {
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/admin/log_action.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        logAction($pdo, 'logout', 'Déconnexion utilisateur', $logoutUser);
    }
} catch (Throwable $e) {
    error_log('Logout audit log failed: ' . $e->getMessage());
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

echo json_encode([
    'success' => true
], JSON_UNESCAPED_UNICODE);
