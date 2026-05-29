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

    $idPatient = (int) $patient['idPatient'];

    $nextStmt = $pdo->prepare(
        "SELECT
            r.idRDV,
            r.dateHeure,
            r.typeConsultation,
            r.duree,
            r.statut,
            m.nom AS medecin_nom,
            m.prenom AS medecin_prenom,
            m.specialite AS medecin_specialite
         FROM rdv r
         LEFT JOIN medecin m ON m.idMedecin = r.idMedecin
         WHERE r.idPatient = :idPatient
           AND r.dateHeure >= NOW()
           AND LOWER(COALESCE(r.statut, '')) NOT IN ('annule', 'annulé', 'refuse', 'refusé')
         ORDER BY r.dateHeure ASC
         LIMIT 1"
    );
    $nextStmt->execute(['idPatient' => $idPatient]);
    $nextRdv = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $ordonnanceStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM ordonnance
         WHERE idPatient = :idPatient'
    );
    $ordonnanceStmt->execute(['idPatient' => $idPatient]);
    $ordonnancesActives = (int) $ordonnanceStmt->fetchColumn();

    $visitesStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM rdv
         WHERE idPatient = :idPatient
           AND LOWER(COALESCE(statut, '')) IN ('present', 'venu', 'termine')"
    );
    $visitesStmt->execute(['idPatient' => $idPatient]);
    $visitesTotales = (int) $visitesStmt->fetchColumn();

    respond(200, [
        'success' => true,
        'stats' => [
            'prochain_rdv' => $nextRdv,
            'ordonnances_actives' => $ordonnancesActives,
            'visites_totales' => $visitesTotales,
            'resultats_en_attente' => 0
        ]
    ]);
} catch (Throwable $e) {
    error_log('Get patient dashboard stats error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
