<?php
declare(strict_types=1);

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

require_once __DIR__ . '/../auth/guard.php';
$currentUser = requireRole(['secretaire', 'admin']);

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idPatientRaw = $data['idPatient'] ?? null;

if ($idPatientRaw === null || $idPatientRaw === '' || !ctype_digit((string) $idPatientRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid patient id'
    ]);
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare('DELETE FROM patient WHERE idPatient = :idPatient');
    $stmt->execute([
        'idPatient' => (int) $idPatientRaw
    ]);

    if ($stmt->rowCount() < 1) {
        respond(404, [
            'success' => false,
            'message' => 'Patient not found'
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Patient deleted'
    ]);
} catch (Throwable $e) {
    error_log('Delete patient error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
