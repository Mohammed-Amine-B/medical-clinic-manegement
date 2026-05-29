<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['admin']);
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/log_action.php';

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    ensureSystemLogsTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT idLog, action, utilisateur, role, details, dateAction
         FROM system_logs
         ORDER BY dateAction DESC, idLog DESC
         LIMIT 200'
    );
    $stmt->execute();

    respond(200, [
        'success' => true,
        'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    error_log('Get system logs error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Server error']);
}
