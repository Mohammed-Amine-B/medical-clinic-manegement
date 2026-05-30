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
$currentUser = requireAuth();

$idOrdonnanceRaw = $_GET['id'] ?? $_GET['idOrdonnance'] ?? null;
if ($idOrdonnanceRaw === null || $idOrdonnanceRaw === '' || !ctype_digit((string) $idOrdonnanceRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Identifiant ordonnance manquant.'
    ]);
}

function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[(string) $column['Field']] = true;
    }

    return $columns;
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $role = strtolower((string) ($currentUser['role'] ?? ''));
    $where = 'o.idOrdonnance = :idOrdonnance';
    $params = ['idOrdonnance' => (int) $idOrdonnanceRaw];

    if ($role === 'medecin') {
        $idMedecin = getLoggedMedecinId();
        $where .= ' AND o.idMedecin = :idMedecin';
        $params['idMedecin'] = $idMedecin;
    } elseif ($role === 'patient') {
        $patientStmt = $pdo->prepare(
            'SELECT idPatient
             FROM patient
             WHERE idUtilisateur = :idUtilisateur
             LIMIT 1'
        );
        $patientStmt->execute(['idUtilisateur' => (int) $currentUser['id']]);
        $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            respond(403, [
                'success' => false,
                'message' => 'Accès non autorisé.'
            ]);
        }

        $where .= ' AND o.idPatient = :idPatient';
        $params['idPatient'] = (int) $patient['idPatient'];
    } elseif ($role !== 'admin') {
        respond(403, [
            'success' => false,
            'message' => 'Accès non autorisé.'
        ]);
    }

    $columns = tableColumns($pdo, 'ordonnance');
    $dateSelect = isset($columns['dateOrdonnance']) ? 'o.dateOrdonnance' : 'o.date';
    $typeSelect = isset($columns['typeOrdonnance']) ? 'o.typeOrdonnance' : (isset($columns['type']) ? 'o.type' : 'NULL');
    $numeroSelect = isset($columns['numeroOrdonnance']) ? 'o.numeroOrdonnance' : 'NULL';
    $statutSelect = isset($columns['statut']) ? 'o.statut' : 'NULL';
    $rdvIdSelect = isset($columns['idRDV']) ? 'o.idRDV' : 'NULL';
    $nomMedicamentSelect = isset($columns['nomMedicament']) ? 'o.nomMedicament' : 'NULL';
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
            {$nomMedicamentSelect} AS nomMedicament,
            p.idPatient,
            p.nom AS patient_nom,
            p.prenom AS patient_prenom,
            p.telephone AS patient_telephone,
            p.email AS patient_email,
            p.date_naissance,
            p.sexe,
            p.adresse AS patient_adresse,
            m.idMedecin,
            m.nom AS medecin_nom,
            m.prenom AS medecin_prenom,
            m.specialite AS medecin_specialite,
            m.email AS medecin_email,
            m.numtelephone AS medecin_telephone
         FROM ordonnance o
         LEFT JOIN patient p ON p.idPatient = o.idPatient
         LEFT JOIN medecin m ON m.idMedecin = o.idMedecin
         {$joinRdv}
         WHERE {$where}
         LIMIT 1"
    );
    $stmt->execute($params);
    $ordonnance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ordonnance) {
        respond(404, [
            'success' => false,
            'message' => 'Ordonnance introuvable.'
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
        'ordonnance' => [
            'idOrdonnance' => (int) $ordonnance['idOrdonnance'],
            'date' => $ordonnance['date'],
            'numeroOrdonnance' => $ordonnance['numeroOrdonnance'],
            'typeOrdonnance' => $ordonnance['typeOrdonnance'],
            'statut' => $ordonnance['statut'],
            'idRDV' => $ordonnance['idRDV'],
            'nomMedicament' => $ordonnance['nomMedicament']
        ],
        'patient' => [
            'idPatient' => $ordonnance['idPatient'],
            'nom' => $ordonnance['patient_nom'],
            'prenom' => $ordonnance['patient_prenom'],
            'telephone' => $ordonnance['patient_telephone'],
            'email' => $ordonnance['patient_email'],
            'date_naissance' => $ordonnance['date_naissance'],
            'sexe' => $ordonnance['sexe'],
            'adresse' => $ordonnance['patient_adresse']
        ],
        'medecin' => [
            'idMedecin' => $ordonnance['idMedecin'],
            'nom' => $ordonnance['medecin_nom'],
            'prenom' => $ordonnance['medecin_prenom'],
            'specialite' => $ordonnance['medecin_specialite'],
            'email' => $ordonnance['medecin_email'],
            'telephone' => $ordonnance['medecin_telephone']
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
        'message' => 'Erreur serveur.'
    ]);
}
