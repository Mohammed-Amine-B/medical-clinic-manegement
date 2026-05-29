<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $stmt->execute();
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (isset($column['Field'])) {
            $columns[strtolower((string) $column['Field'])] = (string) $column['Field'];
        }
    }

    return $columns;
}

function scalarCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['admin']);

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stats = [
        'total_utilisateurs' => scalarCount($pdo, 'SELECT COUNT(*) FROM utilisateur'),
        'total_medecins' => scalarCount($pdo, 'SELECT COUNT(*) FROM medecin'),
        'total_patients' => scalarCount($pdo, 'SELECT COUNT(*) FROM patient'),
        'total_secretaires' => scalarCount($pdo, 'SELECT COUNT(*) FROM secretaire'),
        'total_rendez_vous' => scalarCount($pdo, 'SELECT COUNT(*) FROM rdv'),
        'comptes_actifs' => null
    ];

    $userColumns = tableColumns($pdo, 'utilisateur');
    if (isset($userColumns['actif'])) {
        $column = $userColumns['actif'];
        $stats['comptes_actifs'] = scalarCount($pdo, "SELECT COUNT(*) FROM utilisateur WHERE `$column` = :active", ['active' => 1]);
    } elseif (isset($userColumns['isactive'])) {
        $column = $userColumns['isactive'];
        $stats['comptes_actifs'] = scalarCount($pdo, "SELECT COUNT(*) FROM utilisateur WHERE `$column` = :active", ['active' => 1]);
    } elseif (isset($userColumns['statut'])) {
        $column = $userColumns['statut'];
        $stats['comptes_actifs'] = scalarCount(
            $pdo,
            "SELECT COUNT(*) FROM utilisateur WHERE LOWER(COALESCE(`$column`, '')) IN ('actif', 'active', '1')"
        );
    } elseif (isset($userColumns['status'])) {
        $column = $userColumns['status'];
        $stats['comptes_actifs'] = scalarCount(
            $pdo,
            "SELECT COUNT(*) FROM utilisateur WHERE LOWER(COALESCE(`$column`, '')) IN ('actif', 'active', '1')"
        );
    }

    respond(200, [
        'success' => true,
        'stats' => $stats
    ]);
} catch (Throwable $e) {
    error_log('Get admin dashboard stats error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
