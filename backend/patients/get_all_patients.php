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

$currentUser = null;
$loggedMedecinId = null;
require_once __DIR__ . '/../auth/guard.php';
$currentUser = requireRole(['secretaire', 'admin']);

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $sql = 'SELECT
            idPatient,
            nom,
            prenom,
            date_naissance,
            telephone,
            email,
            sexe,
            adresse,
            specialite,
            idMedecin,
            statut,
            notes
         FROM patient
         ';

    $sql .= ' ORDER BY idPatient DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'patients' => $patients
    ]);
} catch (Throwable $e) {
    error_log('Get all patients error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
