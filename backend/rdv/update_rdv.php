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
$currentUser = requireRole(['medecin', 'secretaire', 'admin']);
$loggedMedecinId = (string) $currentUser['role'] === 'medecin' ? getLoggedMedecinId() : null;

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idRdvRaw = $data['idRDV'] ?? null;
$idPatientRaw = $data['idPatient'] ?? null;
$idMedecinRaw = $data['idMedecin'] ?? null;
$dureeRaw = $data['duree'] ?? null;
$motif = trim((string)($data['motif'] ?? ''));
$dateRdv = trim((string)($data['date_rdv'] ?? ''));

if ($idRdvRaw === null || $idRdvRaw === '' || !ctype_digit((string)$idRdvRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid RDV id'
    ]);
}

if ($idPatientRaw === null || $idPatientRaw === '' || !ctype_digit((string)$idPatientRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid patient id'
    ]);
}

if ((string) $currentUser['role'] === 'medecin') {
    $idMedecin = $loggedMedecinId;
} elseif ($idMedecinRaw === null || $idMedecinRaw === '' || !ctype_digit((string)$idMedecinRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid doctor id'
    ]);
} else {
    $idMedecin = (int) $idMedecinRaw;
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

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $conflictStmt = $pdo->prepare(
        "SELECT idRDV
         FROM rdv
         WHERE idMedecin = :idMedecin
           AND dateHeure = :dateHeure
           AND idRDV <> :idRDV
           AND statut NOT IN ('annule', 'refuse')
         LIMIT 1"
    );
    $conflictStmt->execute([
        'idMedecin' => $idMedecin,
        'dateHeure' => $dateHeure,
        'idRDV' => (int) $idRdvRaw
    ]);

    if ($conflictStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(409, [
            'success' => false,
            'message' => 'Ce médecin a déjà un rendez-vous à cette heure'
        ]);
    }

    $stmt = $pdo->prepare(
        'UPDATE rdv
         SET idPatient = :idPatient,
             idMedecin = :idMedecin,
             typeConsultation = :typeConsultation,
             dateHeure = :dateHeure,
             duree = :duree
         WHERE idRDV = :idRDV' . ((string) $currentUser['role'] === 'medecin' ? ' AND idMedecin = :ownerIdMedecin' : '')
    );

    $params = [
        'idRDV' => (int)$idRdvRaw,
        'idPatient' => (int)$idPatientRaw,
        'idMedecin' => $idMedecin,
        'typeConsultation' => $motif,
        'dateHeure' => $dateHeure,
        'duree' => $duree
    ];
    if ((string) $currentUser['role'] === 'medecin') {
        $params['ownerIdMedecin'] = $loggedMedecinId;
    }
    $stmt->execute($params);

    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare('SELECT idRDV, idMedecin FROM rdv WHERE idRDV = :idRDV');
        $check->execute(['idRDV' => (int)$idRdvRaw]);
        $existingRdv = $check->fetch(PDO::FETCH_ASSOC);
        if (!$existingRdv) {
            respond(404, [
                'success' => false,
                'message' => 'RDV not found'
            ]);
        }
        if ((string) $currentUser['role'] === 'medecin' && (int) $existingRdv['idMedecin'] !== $loggedMedecinId) {
            respond(403, [
                'success' => false,
                'message' => 'Forbidden'
            ]);
        }
    }

    respond(200, [
        'success' => true,
        'message' => 'RDV updated'
    ]);
} catch (Throwable $e) {
    error_log('Update rdv error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
