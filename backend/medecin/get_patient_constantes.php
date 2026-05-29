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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
require_once __DIR__ . '/../database.php';

requireRole(['medecin']);
$loggedMedecinId = getLoggedMedecinId();

$idRdvRaw = $_GET['idRDV'] ?? null;
if ($idRdvRaw === null || $idRdvRaw === '' || !ctype_digit((string) $idRdvRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid RDV id'
    ]);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    ensureConstantesTable($pdo);

    $idRDV = (int) $idRdvRaw;
    $rdvStmt = $pdo->prepare(
        'SELECT idRDV, idPatient, idMedecin
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

    $idPatient = (int) $rdv['idPatient'];
    $stmt = $pdo->prepare(
        'SELECT idConstante, idRDV, poids, tension, spo2, dateMesure
         FROM patient_constantes
         WHERE idRDV = :idRDV AND idPatient = :idPatient
         ORDER BY dateMesure DESC, idConstante DESC
         LIMIT 1'
    );
    $stmt->execute([
        'idRDV' => $idRDV,
        'idPatient' => $idPatient
    ]);
    $constantes = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($constantes) {
        respond(200, [
            'success' => true,
            'exists' => true,
            'constantes' => $constantes
        ]);
    }

    $latestStmt = $pdo->prepare(
        'SELECT idConstante, idRDV, poids, tension, spo2, dateMesure
         FROM patient_constantes
         WHERE idPatient = :idPatient
         ORDER BY dateMesure DESC, idConstante DESC
         LIMIT 1'
    );
    $latestStmt->execute(['idPatient' => $idPatient]);
    $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'exists' => false,
        'constantes' => $latest ?: [
            'poids' => null,
            'tension' => null,
            'spo2' => null,
            'dateMesure' => null
        ]
    ]);
} catch (Throwable $e) {
    error_log('Get patient constantes for RDV error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
