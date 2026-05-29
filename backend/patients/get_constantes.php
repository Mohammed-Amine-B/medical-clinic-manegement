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
$currentUser = requireRole(['patient']);

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    ensureConstantesTable($pdo);

    $patientStmt = $pdo->prepare(
        'SELECT idPatient
         FROM patient
         WHERE idUtilisateur = :idUtilisateur
         LIMIT 1'
    );
    $patientStmt->execute(['idUtilisateur' => (int) $currentUser['id']]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        respond(404, [
            'success' => false,
            'message' => 'Patient profile not found'
        ]);
    }

    $stmt = $pdo->prepare(
        'SELECT idConstante, poids, tension, spo2, dateMesure
         FROM patient_constantes
         WHERE idPatient = :idPatient
         ORDER BY dateMesure DESC, idConstante DESC
         LIMIT 1'
    );
    $stmt->execute(['idPatient' => (int) $patient['idPatient']]);
    $constantes = $stmt->fetch(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'constantes' => $constantes ?: [
            'poids' => null,
            'tension' => null,
            'spo2' => null,
            'dateMesure' => null
        ]
    ]);
} catch (Throwable $e) {
    error_log('Get patient constantes error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
