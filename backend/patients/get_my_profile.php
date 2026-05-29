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

    $stmt = $pdo->prepare(
        'SELECT
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
            notes,
            idUtilisateur
         FROM patient
         WHERE idUtilisateur = :idUtilisateur
         LIMIT 1'
    );

    $stmt->execute(['idUtilisateur' => $idUtilisateur]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        respond(404, [
            'success' => false,
            'message' => 'Patient profile not found'
        ]);
    }

    respond(200, [
        'success' => true,
        'patient' => $patient
    ]);
} catch (Throwable $e) {
    error_log('Get patient profile error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
