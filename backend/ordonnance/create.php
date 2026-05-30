<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') === false) {
    respond(415, [
        'success' => false,
        'message' => 'JSON request required'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
$currentUser = requireRole(['medecin']);

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$patientIdRaw = $data['patient_id'] ?? $data['idPatient'] ?? null;
$idRDVRaw = $data['idRDV'] ?? $data['rdv_id'] ?? null;
$dateOrdonnance = trim((string) ($data['date'] ?? $data['dateOrdonnance'] ?? ''));
$typeOrdonnance = trim((string) ($data['type'] ?? $data['typeOrdonnance'] ?? ''));
$statut = trim((string) ($data['statut'] ?? 'brouillon'));
$numeroOrdonnance = trim((string) ($data['numeroOrdonnance'] ?? $data['numero'] ?? ''));
$medicaments = $data['items'] ?? $data['medicaments'] ?? null;

if ($patientIdRaw === null || $patientIdRaw === '' || !ctype_digit((string) $patientIdRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Patient required'
    ]);
}

if ($dateOrdonnance === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOrdonnance) !== 1) {
    respond(400, [
        'success' => false,
        'message' => 'Date required'
    ]);
}

if ($typeOrdonnance === '') {
    respond(400, [
        'success' => false,
        'message' => 'Type required'
    ]);
}

if (!is_array($medicaments) || count($medicaments) === 0) {
    respond(400, [
        'success' => false,
        'message' => 'Medicaments required'
    ]);
}

$cleanMedicaments = [];
foreach ($medicaments as $medicament) {
    if (!is_array($medicament)) {
        continue;
    }

    $nom = trim((string) ($medicament['nom'] ?? ''));
    if ($nom === '') {
        continue;
    }

    $cleanMedicaments[] = [
        'nom' => $nom,
        'posologie' => trim((string) ($medicament['posologie'] ?? '')),
        'frequence' => trim((string) ($medicament['frequence'] ?? '')),
        'duree' => trim((string) ($medicament['duree'] ?? '')),
        'note' => trim((string) ($medicament['note'] ?? ''))
    ];
}

if (count($cleanMedicaments) === 0) {
    respond(400, [
        'success' => false,
        'message' => 'Medicaments required'
    ]);
}

$allowedStatuses = ['brouillon', 'validee'];
if (!in_array($statut, $allowedStatuses, true)) {
    $statut = 'brouillon';
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
    require_once __DIR__ . '/../admin/log_action.php';

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

    $patientStmt = $pdo->prepare(
        'SELECT idPatient
         FROM patient
         WHERE idPatient = :idPatient
         LIMIT 1'
    );
    $patientStmt->execute(['idPatient' => (int) $patientIdRaw]);

    if (!$patientStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(404, [
            'success' => false,
            'message' => 'Patient not found'
        ]);
    }

    $idRDV = null;
    if ($idRDVRaw !== null && $idRDVRaw !== '' && ctype_digit((string) $idRDVRaw)) {
        $idRDV = (int) $idRDVRaw;
    }

    if ($idRDV !== null) {
        $rdvStmt = $pdo->prepare(
            'SELECT idRDV, idPatient, idMedecin
             FROM rdv
             WHERE idRDV = :idRDV
             LIMIT 1'
        );
        $rdvStmt->execute(['idRDV' => $idRDV]);
        $rdv = $rdvStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rdv) {
            respond(404, [
                'success' => false,
                'message' => 'RDV not found'
            ]);
        }

        if ((int) $rdv['idPatient'] !== (int) $patientIdRaw) {
            respond(400, [
                'success' => false,
                'message' => 'RDV patient mismatch'
            ]);
        }

        if ((int) $rdv['idMedecin'] !== (int) $medecin['idMedecin']) {
            respond(403, [
                'success' => false,
                'message' => 'RDV does not belong to the current doctor'
            ]);
        }
    }

    $columns = tableColumns($pdo, 'ordonnance');
    foreach (['idPatient', 'idMedecin', 'statut', 'numeroOrdonnance'] as $requiredColumn) {
        if (!isset($columns[$requiredColumn])) {
            throw new RuntimeException('Missing ordonnance column: ' . $requiredColumn);
        }
    }

    $dateColumn = isset($columns['dateOrdonnance']) ? 'dateOrdonnance' : (isset($columns['date']) ? 'date' : null);
    if ($dateColumn === null) {
        throw new RuntimeException('Missing ordonnance date column');
    }

    $typeColumn = isset($columns['typeOrdonnance']) ? 'typeOrdonnance' : (isset($columns['type']) ? 'type' : null);
    if ($typeColumn === null) {
        throw new RuntimeException('Missing ordonnance type column');
    }

    $insertColumns = ['idPatient', 'idMedecin', $dateColumn, $typeColumn, 'statut', 'numeroOrdonnance'];
    $values = [
        'idPatient' => (int) $patientIdRaw,
        'idMedecin' => (int) $medecin['idMedecin'],
        $dateColumn => $dateOrdonnance,
        $typeColumn => $typeOrdonnance,
        'statut' => $statut,
        'numeroOrdonnance' => $numeroOrdonnance !== '' ? $numeroOrdonnance : null
    ];

    if (isset($columns['idRDV'])) {
        $insertColumns[] = 'idRDV';
        $values['idRDV'] = $idRDV !== null ? $idRDV : null;
    }

    if (isset($columns['nomMedicament'])) {
        $insertColumns[] = 'nomMedicament';
        $values['nomMedicament'] = $cleanMedicaments[0]['nom'];
    }

    $placeholders = array_map(static fn(string $column): string => ':' . $column, $insertColumns);

    $pdo->beginTransaction();

    $ordonnanceStmt = $pdo->prepare(
        'INSERT INTO ordonnance (`' . implode('`, `', $insertColumns) . '`)
         VALUES (' . implode(', ', $placeholders) . ')'
    );
    $ordonnanceStmt->execute($values);
    $idOrdonnance = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO ordonnance_items (
            idOrdonnance,
            nom,
            posologie,
            frequence,
            duree,
            note
        ) VALUES (
            :idOrdonnance,
            :nom,
            :posologie,
            :frequence,
            :duree,
            :note
        )'
    );

    foreach ($cleanMedicaments as $medicament) {
        $itemStmt->execute([
            'idOrdonnance' => $idOrdonnance,
            'nom' => $medicament['nom'],
            'posologie' => $medicament['posologie'],
            'frequence' => $medicament['frequence'],
            'duree' => $medicament['duree'],
            'note' => $medicament['note']
        ]);
    }

    $pdo->commit();
    logAction(
        $pdo,
        'create ordonnance',
        'Ordonnance #' . $idOrdonnance . ' créée pour patient #' . (int) $patientIdRaw,
        $currentUser
    );

    // Create notification for patient
    $patientUserStmt = $pdo->prepare(
        'SELECT idUtilisateur FROM patient WHERE idPatient = :idPatient LIMIT 1'
    );
    $patientUserStmt->execute(['idPatient' => (int) $patientIdRaw]);
    $patientUser = $patientUserStmt->fetch(PDO::FETCH_ASSOC);

    if ($patientUser) {
        $notificationStmt = $pdo->prepare(
            'INSERT INTO notification (idUtilisateur, message, type)
             VALUES (:idUtilisateur, :message, :type)'
        );
        $notificationStmt->execute([
            'idUtilisateur' => (int) $patientUser['idUtilisateur'],
            'message' => 'Une nouvelle ordonnance est disponible dans votre espace patient.',
            'type' => 'ordonnance_created'
        ]);
    }

    respond(201, [
        'success' => true,
        'idOrdonnance' => $idOrdonnance
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Create ordonnance error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
