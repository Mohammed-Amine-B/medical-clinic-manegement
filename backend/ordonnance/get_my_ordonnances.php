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
            p.nom AS patient_nom,
            p.prenom AS patient_prenom,
            p.idPatient
         FROM ordonnance o
         LEFT JOIN patient p ON p.idPatient = o.idPatient
         {$joinRdv}
         WHERE o.idMedecin = :idMedecin
         ORDER BY {$dateSelect} DESC, o.idOrdonnance DESC"
    );
    $stmt->execute(['idMedecin' => (int) $medecin['idMedecin']]);

    respond(200, [
        'success' => true,
        'ordonnances' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    error_log('Get my ordonnances error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
