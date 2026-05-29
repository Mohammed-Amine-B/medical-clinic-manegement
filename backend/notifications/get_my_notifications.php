<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/guard.php';
require_once __DIR__ . '/../database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = requireRole(['patient', 'medecin', 'secretaire', 'admin']);

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare(
        'SELECT idNotification, message, type, isRead, createdAt
         FROM notification
         WHERE idUtilisateur = :idUtilisateur
         ORDER BY createdAt DESC'
    );

    $stmt->execute(['idUtilisateur' => (int) $currentUser['id']]);

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_my_notifications error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur'
    ], JSON_UNESCAPED_UNICODE);
}
?>

try {
    $tableStmt = $pdo->prepare(
        'SELECT TABLE_NAME
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ("notification", "notifications")
         ORDER BY FIELD(TABLE_NAME, "notifications", "notification")
         LIMIT 1'
    );
    $tableStmt->execute();
    $tableName = $tableStmt->fetchColumn();

    if (!$tableName) {
        echo json_encode([
            'success' => true,
            'notifications' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $columnsStmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tableName'
    );
    $columnsStmt->execute(['tableName' => $tableName]);
    $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
    $columnSet = array_flip($columns);

    $medecinColumn = null;
    foreach (['idMedecin', 'medecin_id', 'id_medecin'] as $candidate) {
        if (isset($columnSet[$candidate])) {
            $medecinColumn = $candidate;
            break;
        }
    }

    if ($medecinColumn === null) {
        echo json_encode([
            'success' => true,
            'notifications' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $selectMap = [
        'idNotification' => ['idNotification', 'id_notification', 'id'],
        'titre' => ['titre', 'title', 'sujet', 'subject'],
        'description' => ['description', 'message', 'contenu', 'body'],
        'categorie' => ['categorie', 'category', 'type', 'typeNotification'],
        'statut' => ['statut', 'status', 'etat'],
        'lu' => ['lu', 'is_read', 'read', 'vue'],
        'dateNotification' => ['dateNotification', 'date_notification', 'created_at', 'dateCreation', 'date']
    ];

    $selects = [];
    foreach ($selectMap as $alias => $candidates) {
        $found = null;
        foreach ($candidates as $candidate) {
            if (isset($columnSet[$candidate])) {
                $found = $candidate;
                break;
            }
        }
        $selects[] = $found !== null
            ? "`{$found}` AS `{$alias}`"
            : "NULL AS `{$alias}`";
    }

    $orderColumn = null;
    foreach (['dateNotification', 'date_notification', 'created_at', 'dateCreation', 'date', 'idNotification', 'id'] as $candidate) {
        if (isset($columnSet[$candidate])) {
            $orderColumn = $candidate;
            break;
        }
    }

    $sql = 'SELECT ' . implode(', ', $selects) .
        " FROM `{$tableName}` WHERE `{$medecinColumn}` = :idMedecin";
    if ($orderColumn !== null) {
        $sql .= " ORDER BY `{$orderColumn}` DESC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['idMedecin' => $idMedecin]);

    echo json_encode([
        'success' => true,
        'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_my_notifications error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur'
    ], JSON_UNESCAPED_UNICODE);
}
