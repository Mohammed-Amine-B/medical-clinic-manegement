<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('Landing stats table check failed: ' . $e->getMessage());
        return false;
    }
}

function safeCount(PDO $pdo, string $table): int
{
    if (!tableExists($pdo, $table)) {
        return 0;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Landing stats count failed: ' . $e->getMessage());
        return 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    respond(200, [
        'success' => true,
        'totalPatients' => safeCount($pdo, 'patient'),
        'totalMedecins' => safeCount($pdo, 'medecin'),
        'totalRDV' => safeCount($pdo, 'rdv'),
        'totalOrdonnances' => safeCount($pdo, 'ordonnance')
    ]);
} catch (Throwable $e) {
    error_log('Landing stats error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
