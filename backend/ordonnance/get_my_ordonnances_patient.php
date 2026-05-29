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

    $stmt = $pdo->prepare(
        "SELECT
            o.idOrdonnance,
            {$dateSelect} AS date,
            {$numeroSelect} AS numeroOrdonnance,
            {$typeSelect} AS typeOrdonnance,
            {$statutSelect} AS statut,
            m.nom AS medecin_nom,
            m.prenom AS medecin_prenom
         FROM ordonnance o
         LEFT JOIN medecin m ON m.idMedecin = o.idMedecin
         WHERE o.idPatient = :idPatient
         ORDER BY {$dateSelect} DESC, o.idOrdonnance DESC"
    );
    $stmt->execute(['idPatient' => $idPatient]);
    $ordonnances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format medecin name
    foreach ($ordonnances as &$ord) {
        $ord['medecin_name'] = $ord['medecin_nom'] && $ord['medecin_prenom']
            ? 'Dr. ' . $ord['medecin_prenom'] . ' ' . $ord['medecin_nom']
            : 'Médecin inconnu';
        unset($ord['medecin_nom'], $ord['medecin_prenom']);
    }

    respond(200, [
        'success' => true,
        'ordonnances' => $ordonnances
    ]);
} catch (Throwable $e) {
    error_log('Get patient ordonnances error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
