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
$currentUser = requireRole(['medecin']);

$idOrdonnanceRaw = $_GET['id'] ?? $_GET['idOrdonnance'] ?? null;
if ($idOrdonnanceRaw === null || $idOrdonnanceRaw === '' || !ctype_digit((string) $idOrdonnanceRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid ordonnance id'
    ]);
}

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
    $doctorStmt->execute(['idUtilisateur' => (int) $currentUser['id']]);
    $medecin = $doctorStmt->fetch(PDO::FETCH_ASSOC);

    if (!$medecin) {
        respond(404, [
            'success' => false,
            'message' => 'Medecin not found'
        ]);
    }

    $columns = tableColumns($pdo, 'ordonnance');
    $dateSelect = isset($columns['dateOrdonnance']) ? 'o.dateOrdonnance' : 'o.date';
    $typeSelect = isset($columns['typeOrdonnance']) ? 'o.typeOrdonnance' : (isset($columns['type']) ? 'o.type' : 'NULL');
    $numeroSelect = isset($columns['numeroOrdonnance']) ? 'o.numeroOrdonnance' : 'NULL';
    $statutSelect = isset($columns['statut']) ? 'o.statut' : 'NULL';
    $rdvIdSelect = isset($columns['idRDV']) ? 'o.idRDV' : 'NULL';
    $joinRdv = isset($columns['idRDV']) ? 'LEFT JOIN rdv r ON r.idRDV = o.idRDV' : '';
    $rdvDateSelect = isset($columns['idRDV']) ? 'r.dateHeure AS rdvDateHeure' : 'NULL AS rdvDateHeure';
    $rdvTypeSelect = isset($columns['idRDV']) ? 'r.typeConsultation AS rdvType' : 'NULL AS rdvType';

    $stmt = $pdo->prepare(
        "SELECT
            o.idOrdonnance,
            {$dateSelect} AS date,
            {$numeroSelect} AS numeroOrdonnance,
            {$typeSelect} AS typeOrdonnance,
            {$statutSelect} AS statut,
            {$rdvIdSelect} AS idRDV,
            {$rdvDateSelect},
            {$rdvTypeSelect},
            o.nomMedicament,
            p.idPatient,
            p.nom AS patient_nom,
            p.prenom AS patient_prenom,
            p.telephone AS patient_telephone,
            p.date_naissance,
            p.sexe
         FROM ordonnance o
         LEFT JOIN patient p ON p.idPatient = o.idPatient
         {$joinRdv}
         WHERE o.idOrdonnance = :idOrdonnance
           AND o.idMedecin = :idMedecin
         LIMIT 1"
    );
    $stmt->execute([
        'idOrdonnance' => (int) $idOrdonnanceRaw,
        'idMedecin' => (int) $medecin['idMedecin']
    ]);
    $ordonnance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ordonnance) {
        respond(404, [
            'success' => false,
            'message' => 'Ordonnance not found'
        ]);
    }

    $itemsStmt = $pdo->prepare(
        'SELECT
            nom,
            posologie,
            frequence,
            duree,
            note
         FROM ordonnance_items
         WHERE idOrdonnance = :idOrdonnance
         ORDER BY idOrdonnanceItem ASC'
    );
    $itemsStmt->execute(['idOrdonnance' => (int) $idOrdonnanceRaw]);
    $medicaments = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$medicaments && !empty($ordonnance['nomMedicament'])) {
        $medicaments[] = [
            'nom' => $ordonnance['nomMedicament'],
            'posologie' => '',
            'frequence' => '',
            'duree' => '',
            'note' => ''
        ];
    }

    respond(200, [
        'success' => true,
        'ordonnance' => $ordonnance,
        'patient' => [
            'idPatient' => $ordonnance['idPatient'],
            'nom' => $ordonnance['patient_nom'],
            'prenom' => $ordonnance['patient_prenom'],
            'telephone' => $ordonnance['patient_telephone'],
            'date_naissance' => $ordonnance['date_naissance'],
            'sexe' => $ordonnance['sexe']
        ],
        'rdv' => isset($ordonnance['idRDV']) && $ordonnance['idRDV'] !== null ? [
            'idRDV' => (int) $ordonnance['idRDV'],
            'dateHeure' => $ordonnance['rdvDateHeure'] ?? null,
            'typeConsultation' => $ordonnance['rdvType'] ?? null
        ] : null,
        'medicaments' => $medicaments
    ]);
} catch (Throwable $e) {
    error_log('Get ordonnance error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
