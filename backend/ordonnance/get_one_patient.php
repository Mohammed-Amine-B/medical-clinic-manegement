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

    $idUtilisateur = (int) $currentUser['id'];

    // Find patient ID for this user
    $patientStmt = $pdo->prepare(
        'SELECT idPatient
         FROM patient
         WHERE idUtilisateur = :idUtilisateur
         LIMIT 1'
    );
    $patientStmt->execute(['idUtilisateur' => $idUtilisateur]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        respond(404, [
            'success' => false,
            'message' => 'Patient profile not found'
        ]);
    }

    $idPatient = (int) $patient['idPatient'];

    function tableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string) $column['Field']] = true;
        }

        return $columns;
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

    // Get ordonnance details
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
            m.nom AS medecin_nom,
            m.prenom AS medecin_prenom,
            m.specialite AS medecin_specialite,
            p.nom AS patient_nom,
            p.prenom AS patient_prenom,
            p.date_naissance,
            p.sexe,
            p.telephone,
            p.adresse
         FROM ordonnance o
         LEFT JOIN medecin m ON m.idMedecin = o.idMedecin
         LEFT JOIN patient p ON p.idPatient = o.idPatient
         {$joinRdv}
         WHERE o.idOrdonnance = :idOrdonnance
           AND o.idPatient = :idPatient
         LIMIT 1"
    );
    $stmt->execute([
        'idOrdonnance' => (int) $idOrdonnanceRaw,
        'idPatient' => $idPatient
    ]);
    $ordonnance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ordonnance) {
        respond(404, [
            'success' => false,
            'message' => 'Ordonnance not found'
        ]);
    }

    // Get ordonnance items
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
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'id' => (int) $ordonnance['idOrdonnance'],
        'num' => trim((string) $ordonnance['numeroOrdonnance']) !== ''
            ? $ordonnance['numeroOrdonnance']
            : 'ORD-' . str_pad((string) $ordonnance['idOrdonnance'], 4, '0', STR_PAD_LEFT),
        'date' => $ordonnance['date'] ? date('d/m/Y', strtotime($ordonnance['date'])) : null,
        'type' => trim((string) $ordonnance['typeOrdonnance']) !== '' ? $ordonnance['typeOrdonnance'] : null,
        'validite' => '3 mois',
        'statut' => trim((string) $ordonnance['statut']) !== '' ? $ordonnance['statut'] : null,
        'medecin' => $ordonnance['medecin_nom'] && $ordonnance['medecin_prenom']
            ? 'Dr. ' . $ordonnance['medecin_prenom'] . ' ' . $ordonnance['medecin_nom']
            : null,
        'specialite' => trim((string) $ordonnance['medecin_specialite']) !== '' ? $ordonnance['medecin_specialite'] : null,
        'cnom' => 'ALG-31-00412',
        'cabinet' => 'Cabinet LYS Médical · 12 Rue Krim Belkacem, Oran 31000',
        'patient' => $ordonnance['patient_nom'] && $ordonnance['patient_prenom']
            ? $ordonnance['patient_prenom'] . ' ' . $ordonnance['patient_nom']
            : null,
        'age' => $ordonnance['date_naissance']
            ? date_diff(date_create($ordonnance['date_naissance']), date_create('today'))->y . ' ans'
            : null,
        'sexe' => trim((string) $ordonnance['sexe']) !== '' ? $ordonnance['sexe'] : null,
        'naissance' => $ordonnance['date_naissance']
            ? date('d/m/Y', strtotime($ordonnance['date_naissance']))
            : null,
        'telephone' => trim((string) $ordonnance['telephone']) !== '' ? $ordonnance['telephone'] : null,
        'groupe' => null,
        'allergies' => null,
        'drugs' => array_map(function($item) {
            return [
                'name' => trim((string) $item['nom']),
                'dose' => trim((string) $item['posologie']) ?: null,
                'freq' => trim((string) $item['frequence']) ?: null,
                'dur' => trim((string) $item['duree']) ?: null,
                'note' => trim((string) $item['note']) ?: null
            ];
        }, $items),
        'conseils' => [],
        'renew' => 'Non renouvelable',
        'signed' => true,
        'rdv' => isset($ordonnance['idRDV']) && $ordonnance['idRDV'] !== null ? [
            'idRDV' => (int) $ordonnance['idRDV'],
            'dateHeure' => $ordonnance['rdvDateHeure'] ?? null,
            'typeConsultation' => $ordonnance['rdvType'] ?? null
        ] : null
    ];

    respond(200, [
        'success' => true,
        'ordonnance' => $response
    ]);
} catch (Throwable $e) {
    error_log('Get patient ordonnance error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
