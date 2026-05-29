<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensureConstantesTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS patient_constantes (
            idConstante INT AUTO_INCREMENT PRIMARY KEY,
            idPatient INT NOT NULL,
            idRDV INT NULL,
            poids DECIMAL(5,2) NULL,
            tension VARCHAR(20) NULL,
            spo2 INT NULL,
            dateMesure DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_patient_constantes_rdv (idRDV),
            INDEX idx_patient_constantes_patient_date (idPatient, dateMesure)
        )"
    );

    $columnStmt = $pdo->query("SHOW COLUMNS FROM patient_constantes LIKE 'idRDV'");
    if (!$columnStmt || !$columnStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE patient_constantes ADD COLUMN idRDV INT NULL AFTER idPatient');
    }

    $indexStmt = $pdo->query("SHOW INDEX FROM patient_constantes WHERE Key_name = 'idx_patient_constantes_rdv'");
    if (!$indexStmt || !$indexStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE patient_constantes ADD INDEX idx_patient_constantes_rdv (idRDV)');
    }
}

function normalizeStatus(string $status): string
{
    $status = trim(strtolower($status));
    $status = preg_replace('/\s+/', '_', $status);

    if (in_array($status, ['present', 'venu', 'termine', 'terminé'], true)) {
        return 'done';
    }

    return $status;
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
if ($idRdvRaw === null || $idRdvRaw === '' || !ctype_digit((string) $idRdvRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid RDV id'
    ]);
}

$poidsRaw = trim((string) ($data['poids'] ?? ''));
$tension = trim((string) ($data['tension'] ?? ''));
$spo2Raw = trim((string) ($data['spo2'] ?? ''));

$poids = $poidsRaw === '' ? null : (float) $poidsRaw;
$spo2 = $spo2Raw === '' ? null : (int) $spo2Raw;

if ($poids !== null && ($poids <= 0 || $poids > 999.99)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid poids value'
    ]);
}

if ($tension !== '' && strlen($tension) > 20) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid tension value'
    ]);
}

if ($spo2 !== null && ($spo2 < 0 || $spo2 > 100)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid SpO2 value'
    ]);
}

if ($poids === null && $tension === '' && $spo2 === null) {
    respond(400, [
        'success' => false,
        'message' => 'At least one constante is required'
    ]);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    ensureConstantesTable($pdo);

    $idRDV = (int) $idRdvRaw;
    $rdvStmt = $pdo->prepare(
        'SELECT idRDV, idPatient, idMedecin, statut
         FROM rdv
         WHERE idRDV = :idRDV
         LIMIT 1'
    );
    $rdvStmt->execute(['idRDV' => $idRDV]);
    $rdv = $rdvStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rdv) {
        respond(404, [
            'success' => false,
            'message' => 'RDV not found'
        ]);
    }

    if ((int) $rdv['idMedecin'] !== $loggedMedecinId) {
        respond(403, [
            'success' => false,
            'message' => 'Forbidden'
        ]);
    }

    if (normalizeStatus((string) $rdv['statut']) !== 'done') {
        respond(400, [
            'success' => false,
            'message' => 'Constants can only be added for present or completed RDV'
        ]);
    }

    $idPatient = (int) $rdv['idPatient'];
    $existingStmt = $pdo->prepare(
        'SELECT idConstante
         FROM patient_constantes
         WHERE idRDV = :idRDV AND idPatient = :idPatient
         ORDER BY dateMesure DESC, idConstante DESC
         LIMIT 1'
    );
    $existingStmt->execute([
        'idRDV' => $idRDV,
        'idPatient' => $idPatient
    ]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $updateStmt = $pdo->prepare(
            'UPDATE patient_constantes
             SET poids = :poids,
                 tension = :tension,
                 spo2 = :spo2,
                 dateMesure = CURRENT_TIMESTAMP
             WHERE idConstante = :idConstante AND idPatient = :idPatient'
        );
        $updateStmt->execute([
            'poids' => $poids,
            'tension' => $tension === '' ? null : $tension,
            'spo2' => $spo2,
            'idConstante' => (int) $existing['idConstante'],
            'idPatient' => $idPatient
        ]);
        $logVerb = 'update patient constantes';
    } else {
        $insertStmt = $pdo->prepare(
            'INSERT INTO patient_constantes (idPatient, idRDV, poids, tension, spo2)
             VALUES (:idPatient, :idRDV, :poids, :tension, :spo2)'
        );
        $insertStmt->execute([
            'idPatient' => $idPatient,
            'idRDV' => $idRDV,
            'poids' => $poids,
            'tension' => $tension === '' ? null : $tension,
            'spo2' => $spo2
        ]);
        $logVerb = 'add patient constantes';
    }

    logAction($pdo, $logVerb, 'Constantes patient #' . $idPatient . ' pour RDV #' . $idRDV, $currentUser);

    respond(201, [
        'success' => true,
        'message' => 'Constantes enregistrées'
    ]);
} catch (Throwable $e) {
    error_log('Add patient constantes error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
