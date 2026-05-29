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
$user = requireRole('medecin');
$userId = (int) $user['id'];

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $doctorStmt = $pdo->prepare(
        'SELECT idMedecin
         FROM medecin
         WHERE idUtilisateur = :idUtilisateur
         LIMIT 1'
    );
    $doctorStmt->execute(['idUtilisateur' => $userId]);
    $medecin = $doctorStmt->fetch(PDO::FETCH_ASSOC);

    if (!$medecin) {
        respond(404, [
            'success' => false,
            'message' => 'Medecin not found'
        ]);
    }

    $patientsStmt = $pdo->prepare(
        'SELECT DISTINCT
            p.idPatient,
            p.nom,
            p.prenom,
            p.telephone
         FROM patient p
         JOIN rdv r ON r.idPatient = p.idPatient
         WHERE r.idMedecin = :idMedecin
         ORDER BY p.nom ASC, p.prenom ASC'
    );
    $patientsStmt->execute(['idMedecin' => (int) $medecin['idMedecin']]);

    respond(200, [
        'success' => true,
        'patients' => $patientsStmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
