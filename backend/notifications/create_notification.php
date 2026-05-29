<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/guard.php';
require_once __DIR__ . '/../database.php';
$currentUser = requireRole(['admin', 'secretaire', 'medecin']);

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

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idUtilisateur = (int) ($data['idUtilisateur'] ?? 0);
$message = trim((string) ($data['message'] ?? ''));
$type = trim((string) ($data['type'] ?? ''));

if ($idUtilisateur <= 0) {
    respond(400, [
        'success' => false,
        'message' => 'Valid idUtilisateur required'
    ]);
}

if ($message === '') {
    respond(400, [
        'success' => false,
        'message' => 'Message required'
    ]);
}

if ($type === '') {
    respond(400, [
        'success' => false,
        'message' => 'Type required'
    ]);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notification (idUtilisateur, message, type)
         VALUES (:idUtilisateur, :message, :type)'
    );

    $stmt->execute([
        'idUtilisateur' => $idUtilisateur,
        'message' => $message,
        'type' => $type
    ]);

    $idNotification = (int) $pdo->lastInsertId();

    respond(201, [
        'success' => true,
        'idNotification' => $idNotification
    ]);
} catch (Throwable $e) {
    error_log('Create notification error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
?>
