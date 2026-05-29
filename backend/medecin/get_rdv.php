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
        $sessionEmail = trim((string) ($user['email'] ?? $user['login'] ?? ''));

        if ($sessionEmail !== '') {
            $doctorByEmailStmt = $pdo->prepare(
                'SELECT idMedecin, idUtilisateur, email
                 FROM medecin
                 WHERE email = :email
                 LIMIT 1'
            );
            $doctorByEmailStmt->execute(['email' => $sessionEmail]);
            $medecin = $doctorByEmailStmt->fetch(PDO::FETCH_ASSOC);

            if (
                $medecin &&
                (!isset($medecin['idUtilisateur']) || (int) $medecin['idUtilisateur'] === 0)
            ) {
                $updateDoctorStmt = $pdo->prepare(
                    'UPDATE medecin
                     SET idUtilisateur = :user_id
                     WHERE idMedecin = :id_medecin'
                );
                $updateDoctorStmt->execute([
                    'user_id' => $userId,
                    'id_medecin' => (int) $medecin['idMedecin']
                ]);
            }
        }
    }

    if (!$medecin) {
        respond(404, [
            'success' => false,
            'message' => 'Medecin not found'
        ]);
    }

    $idMedecin = (int) $medecin['idMedecin'];
    error_log('Doctor ID: ' . $idMedecin);

    $rdvStmt = $pdo->prepare(
        'SELECT
            r.idRDV,
            r.idPatient,
            r.dateHeure,
            r.statut,
            r.typeConsultation,
            r.duree,
            p.nom AS patient_nom,
            p.prenom AS patient_prenom,
            p.telephone
         FROM rdv r
         LEFT JOIN patient p ON p.idPatient = r.idPatient
         WHERE r.idMedecin = :idMedecin
         ORDER BY r.dateHeure ASC'
    );
    $rdvStmt->execute(['idMedecin' => $idMedecin]);
    $rdv = $rdvStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log('RDV count: ' . count($rdv));

    respond(200, [
        'success' => true,
        'rdv' => $rdv
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
