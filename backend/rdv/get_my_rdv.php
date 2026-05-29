<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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

    $idUtilisateur = (int) $currentUser['id'];

    // Get patient id linked to current user
    $patientStmt = $pdo->prepare(
        'SELECT idPatient FROM patient WHERE idUtilisateur = :idUtilisateur LIMIT 1'
    );
    $patientStmt->execute(['idUtilisateur' => $idUtilisateur]);
    $patientRow = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patientRow) {
        respond(404, [
            'success' => true,
            'rdv' => []
        ]);
    }

    $idPatient = (int) $patientRow['idPatient'];

    // Get RDVs for this patient only
    $stmt = $pdo->prepare(
        'SELECT
            r.idRDV,
            r.idPatient,
            r.idMedecin,
            r.dateHeure,
            r.statut,
            r.typeConsultation,
            r.duree,
            m.nom AS medecin_nom,
            m.prenom AS medecin_prenom,
            m.specialite AS medecin_specialite
         FROM rdv r
         LEFT JOIN medecin m ON r.idMedecin = m.idMedecin
         WHERE r.idPatient = :idPatient
         ORDER BY r.dateHeure DESC'
    );

    $stmt->execute(['idPatient' => $idPatient]);
    $rdvs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'rdv' => $rdvs
    ]);
} catch (Throwable $e) {
    error_log('Get patient RDV error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
