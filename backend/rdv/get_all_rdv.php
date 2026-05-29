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
requireRole(['secretaire', 'admin']);

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->query(
        'SELECT
            r.idRDV,
            r.idPatient,
            r.idMedecin,
            r.dateHeure,
            r.statut,
            r.typeConsultation,
            r.duree,
            p.nom AS patient_nom,
            p.prenom AS patient_prenom,
            m.nom AS medecin_nom,
            m.prenom AS medecin_prenom
         FROM rdv r
         LEFT JOIN patient p ON r.idPatient = p.idPatient
         LEFT JOIN medecin m ON r.idMedecin = m.idMedecin
         ORDER BY r.dateHeure ASC, r.idRDV ASC'
    );

    respond(200, [
        'success' => true,
        'rdv' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    error_log('Get all rdv error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
