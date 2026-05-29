<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function bytesToHuman($bytes): string
{
    if ($bytes === false || $bytes === null) {
        return 'N/A';
    }

    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return round($bytes, $index === 0 ? 0 : 1) . ' ' . $units[$index];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['admin']);

$mysqlStatus = 'disconnected';
$mysqlVersion = null;
$databaseName = null;
$totalUsers = 0;
$totalLogs = 0;

try {
    require_once __DIR__ . '/../database.php';
    require_once __DIR__ . '/log_action.php';

    if (isset($pdo) && $pdo instanceof PDO) {
        $mysqlStatus = 'connected';
        $mysqlVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM utilisateur')->fetchColumn();
        ensureSystemLogsTable($pdo);
        $totalLogs = (int) $pdo->query('SELECT COUNT(*) FROM system_logs')->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('System status DB check failed: ' . $e->getMessage());
}

$rootPath = dirname(__DIR__, 2);
$diskFree = @disk_free_space($rootPath);
$diskTotal = @disk_total_space($rootPath);

respond(200, [
    'success' => true,
    'status' => [
        'phpVersion' => PHP_VERSION,
        'serverSoftware' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'),
        'mysqlStatus' => $mysqlStatus,
        'mysqlVersion' => $mysqlVersion,
        'databaseName' => $databaseName,
        'diskFreeSpace' => bytesToHuman($diskFree),
        'diskTotalSpace' => bytesToHuman($diskTotal),
        'diskFreeBytes' => $diskFree === false ? null : (float) $diskFree,
        'diskTotalBytes' => $diskTotal === false ? null : (float) $diskTotal,
        'currentServerTime' => date('Y-m-d H:i:s'),
        'totalUsers' => $totalUsers,
        'totalLogs' => $totalLogs,
        'demo' => [
            'cpu' => true,
            'ram' => true,
            'network' => true
        ]
    ]
]);
