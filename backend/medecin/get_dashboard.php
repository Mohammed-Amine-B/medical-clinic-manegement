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
        'SELECT
            idMedecin,
            nom,
            prenom,
            specialite,
            numtelephone,
            email,
            idUtilisateur
         FROM medecin
         WHERE idUtilisateur = :user_id
         LIMIT 1'
    );
    $doctorStmt->execute(['user_id' => $userId]);
    $medecin = $doctorStmt->fetch(PDO::FETCH_ASSOC);

    if (!$medecin) {
        $sessionEmail = trim((string) ($user['email'] ?? $user['login'] ?? ''));

        if ($sessionEmail !== '') {
            $doctorByEmailStmt = $pdo->prepare(
                'SELECT
                    idMedecin,
                    nom,
                    prenom,
                    specialite,
                    numtelephone,
                    email,
                    idUtilisateur
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

                $medecin['idUtilisateur'] = $userId;
            }
        }
    }

    if (!$medecin) {
        echo json_encode([
            'success' => false,
            'message' => 'Medecin not found'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statsStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN DATE(r.dateHeure) = CURDATE() THEN 1 ELSE 0 END) AS today_rdv,
            SUM(
                CASE
                    WHEN LOWER(REPLACE(COALESCE(r.statut, ''), ' ', '_')) IN ('en_attente', 'pending')
                    THEN 1
                    ELSE 0
                END
            ) AS pending_rdv,
            SUM(
                CASE
                    WHEN LOWER(REPLACE(COALESCE(r.statut, ''), ' ', '_')) IN ('confirme', 'confirmé', 'confirm', 'planifie', 'planifié')
                    THEN 1
                    ELSE 0
                END
            ) AS planned_rdv,
            SUM(
                CASE
                    WHEN LOWER(REPLACE(COALESCE(r.statut, ''), ' ', '_')) IN ('present', 'venu', 'termine', 'terminé', 'done')
                    THEN 1
                    ELSE 0
                END
            ) AS termines_rdv,
            COUNT(DISTINCT r.idPatient) AS total_patients,
            (
                SELECT COUNT(*)
                FROM notification n
                WHERE n.idUtilisateur = :user_id
                  AND n.isRead = 0
            ) AS unread_notifications
         FROM rdv r
         WHERE r.idMedecin = :id_medecin"
    );
    $statsStmt->execute([
        'id_medecin' => (int) $medecin['idMedecin'],
        'user_id' => $userId
    ]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $rdvStmt = $pdo->prepare(
        'SELECT
            r.idRDV,
            p.nom AS patient_nom,
            p.prenom AS patient_prenom,
            p.telephone,
            r.dateHeure,
            r.statut,
            r.typeConsultation,
            r.duree
         FROM rdv r
         INNER JOIN patient p ON p.idPatient = r.idPatient
         WHERE r.idMedecin = :id_medecin
         ORDER BY r.dateHeure DESC, r.idRDV DESC'
    );
    $rdvStmt->execute(['id_medecin' => (int) $medecin['idMedecin']]);

    respond(200, [
        'success' => true,
        'medecin' => [
            'idMedecin' => (int) $medecin['idMedecin'],
            'nom' => (string) $medecin['nom'],
            'prenom' => (string) $medecin['prenom'],
            'specialite' => (string) ($medecin['specialite'] ?? ''),
            'numtelephone' => (string) ($medecin['numtelephone'] ?? ''),
            'email' => (string) ($medecin['email'] ?? ''),
            'idUtilisateur' => (int) $medecin['idUtilisateur']
        ],
        'stats' => [
            'today_rdv' => (int) ($stats['today_rdv'] ?? 0),
            'pending_rdv' => (int) ($stats['pending_rdv'] ?? 0),
            'planned_rdv' => (int) ($stats['planned_rdv'] ?? 0),
            'termines_rdv' => (int) ($stats['termines_rdv'] ?? 0),
            'unread_notifications' => (int) ($stats['unread_notifications'] ?? 0),
            'total_patients' => (int) ($stats['total_patients'] ?? 0)
        ],
        'rdv' => $rdvStmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
