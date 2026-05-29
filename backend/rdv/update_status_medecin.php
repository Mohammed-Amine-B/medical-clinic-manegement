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
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../admin/log_action.php';

$currentUser = requireRole(['medecin']);
$loggedMedecinId = getLoggedMedecinId();

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idRdvRaw = $data['idRDV'] ?? null;
$statutRaw = trim((string) ($data['statut'] ?? ''));

if ($idRdvRaw === null || $idRdvRaw === '' || !ctype_digit((string) $idRdvRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid RDV id'
    ]);
}

if ($statutRaw === '') {
    respond(400, [
        'success' => false,
        'message' => 'Status is required'
    ]);
}

function normalizeStatus(string $status): string
{
    $status = trim(strtolower($status));
    $status = preg_replace('/\s+/', '_', $status);

    if (in_array($status, ['confirme', 'confirmé'], true)) {
        return 'confirme';
    }
    if ($status === 'present') {
        return 'present';
    }
    if (in_array($status, ['termine', 'terminé', 'venu'], true)) {
        return 'termine';
    }
    if (in_array($status, ['annule', 'annulé'], true)) {
        return 'annule';
    }
    if (in_array($status, ['refuse', 'refusé'], true)) {
        return 'refuse';
    }
    if (in_array($status, ['en_attente', 'en attente', 'pending'], true)) {
        return 'en_attente';
    }

    return $status;
}

$newStatut = normalizeStatus($statutRaw);
$allowedNew = ['present', 'termine'];
if (!in_array($newStatut, $allowedNew, true)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid status transition for médecin'
    ]);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $idRdv = (int) $idRdvRaw;
    $stmt = $pdo->prepare(
        'SELECT idRDV, idMedecin, statut
         FROM rdv
         WHERE idRDV = :idRDV
         LIMIT 1'
    );
    $stmt->execute(['idRDV' => $idRdv]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        respond(404, [
            'success' => false,
            'message' => 'RDV not found'
        ]);
    }

    if ((int) $existing['idMedecin'] !== $loggedMedecinId) {
        respond(403, [
            'success' => false,
            'message' => 'Forbidden'
        ]);
    }

    $currentStatut = normalizeStatus((string) $existing['statut']);
    $valid = false;

    if ($currentStatut === 'confirme' && $newStatut === 'present') {
        $valid = true;
    }
    if ($currentStatut === 'present' && $newStatut === 'termine') {
        $valid = true;
    }

    if (!$valid) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid status transition'
        ]);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE rdv SET statut = :statut WHERE idRDV = :idRDV AND idMedecin = :idMedecin'
    );
    $updateStmt->execute([
        'statut' => $newStatut,
        'idRDV' => $idRdv,
        'idMedecin' => $loggedMedecinId
    ]);

    if ($updateStmt->rowCount() < 1) {
        respond(500, [
            'success' => false,
            'message' => 'Unable to update RDV status'
        ]);
    }

    logAction($pdo, 'update RDV status', 'RDV #' . $idRdv . ' statut médecin: ' . $newStatut, $currentUser);

    respond(200, [
        'success' => true,
        'message' => 'Status updated'
    ]);
} catch (Throwable $e) {
    error_log('Medecin update RDV status error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
