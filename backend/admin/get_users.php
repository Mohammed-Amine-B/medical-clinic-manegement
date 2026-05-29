<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/guard.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $columnsStmt = $pdo->query('SHOW COLUMNS FROM utilisateur');
    $columns = [];
    foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (isset($column['Field'])) {
            $columns[strtolower((string) $column['Field'])] = (string) $column['Field'];
        }
    }

    $statusExpr = "'actif'";
    $supportsStatus = false;
    foreach (['statut', 'status', 'actif', 'isActive'] as $candidate) {
        $key = strtolower($candidate);
        if (isset($columns[$key])) {
            $statusExpr = '`' . $columns[$key] . '`';
            $supportsStatus = true;
            break;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT idUtilisateur, login, role, {$statusExpr} AS statut
         FROM utilisateur
         ORDER BY idUtilisateur DESC"
    );
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'supports_status' => $supportsStatus,
        'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Get users error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ], JSON_UNESCAPED_UNICODE);
}
