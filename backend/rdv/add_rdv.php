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
$idMedecinRaw = $data['idMedecin'] ?? null;
$dureeRaw = $data['duree'] ?? null;
$motif = trim((string)($data['motif'] ?? ''));
$dateRdv = trim((string)($data['date_rdv'] ?? ''));

if ($idPatientRaw === null || $idPatientRaw === '' || !ctype_digit((string) $idPatientRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid patient id'
    ]);
}

if ($idMedecinRaw === null || $idMedecinRaw === '' || !ctype_digit((string) $idMedecinRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid doctor id'
    ]);
}

if ($dureeRaw === null || !ctype_digit((string)$dureeRaw) || (int)$dureeRaw <= 0) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid duration'
    ]);
}

if ($motif === '') {
    respond(400, [
        'success' => false,
        'message' => 'Motif is required'
    ]);
}

if ($dateRdv === '') {
    respond(400, [
        'success' => false,
        'message' => 'Date is required'
    ]);
}

$duree = (int)$dureeRaw;

$dateHeure = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRdv) === 1
    ? $dateRdv . ' 09:00:00'
    : $dateRdv;

try {
    require_once __DIR__ . '/../database.php';
    require_once __DIR__ . '/../admin/log_action.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $conflictStmt = $pdo->prepare(
        "SELECT idRDV
         FROM rdv
         WHERE idMedecin = :idMedecin
           AND dateHeure = :dateHeure
           AND statut NOT IN ('annule', 'refuse')
         LIMIT 1"
    );
    $conflictStmt->execute([
        'idMedecin' => (int) $idMedecinRaw,
        'dateHeure' => $dateHeure
    ]);

    if ($conflictStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(409, [
            'success' => false,
            'message' => 'Ce médecin a déjà un rendez-vous à cette heure'
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO rdv (
            dateHeure,
            statut,
            idPatient,
            idMedecin,
            typeConsultation,
            duree
        ) VALUES (
            :dateHeure,
            :statut,
            :idPatient,
            :idMedecin,
            :typeConsultation,
            :duree
        )'
    );

    $stmt->execute([
        'dateHeure' => $dateHeure,
        'statut' => 'en_attente',
        'idPatient' => (int) $idPatientRaw,
        'idMedecin' => (int) $idMedecinRaw,
        'typeConsultation' => $motif,
        'duree' => $duree
    ]);
    $idRDV = (int) $pdo->lastInsertId();
    logAction(
        $pdo,
        'create RDV',
        'RDV #' . $idRDV . ' créé pour patient #' . (int) $idPatientRaw . ' avec médecin #' . (int) $idMedecinRaw . ' le ' . $dateHeure,
        $currentUser
    );

    respond(201, [
        'success' => true,
        'message' => 'RDV created'
    ]);
} catch (Throwable $e) {
    error_log('Add rdv error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
