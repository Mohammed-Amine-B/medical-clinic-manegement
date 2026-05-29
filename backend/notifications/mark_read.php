<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/guard.php';
require_once __DIR__ . '/../database.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') === false) {
    respond(415, [
        'success' => false,
        'message' => 'JSON request required'
    ]);
}

$currentUser = requireRole(['patient', 'medecin', 'secretaire', 'admin']);

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idNotification = (int) ($data['idNotification'] ?? 0);

if ($idNotification <= 0) {
    respond(400, [
        'success' => false,
        'message' => 'Valid idNotification required'
    ]);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare(
        'UPDATE notification
         SET isRead = 1
         WHERE idNotification = :idNotification
           AND idUtilisateur = :idUtilisateur'
    );

    $stmt->execute([
        'idNotification' => $idNotification,
        'idUtilisateur' => (int) $currentUser['id']
    ]);

    if ($stmt->rowCount() < 1) {
        respond(404, [
            'success' => false,
            'message' => 'Notification not found or not owned by user'
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Notification marked as read'
    ]);
} catch (Throwable $e) {
    error_log('Mark notification read error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
?>