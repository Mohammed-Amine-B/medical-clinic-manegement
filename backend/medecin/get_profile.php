<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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

function ensureProfileColumns(PDO $pdo): array
{
    $columns = tableColumns($pdo, 'medecin');

    if (!isset($columns['adresseCabinet'])) {
        $pdo->exec('ALTER TABLE medecin ADD COLUMN adresseCabinet VARCHAR(255) NULL');
        $columns['adresseCabinet'] = true;
    }

    if (!isset($columns['bio'])) {
        $pdo->exec('ALTER TABLE medecin ADD COLUMN bio TEXT NULL');
        $columns['bio'] = true;
    }

    return $columns;
}

function firstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'success' => false,
        'message' => 'Request method check failed: get_profile.php only accepts GET'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['medecin']);
$idMedecin = getLoggedMedecinId();

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Profile load failed: database connection is not available');
    }

    $columns = ensureProfileColumns($pdo);
    $rppsColumn = firstExistingColumn($columns, ['rpps', 'RPPS', 'numRPPS', 'numeroRPPS']);
    $rppsSelect = $rppsColumn ? '`' . $rppsColumn . '` AS rpps' : 'NULL AS rpps';

    $stmt = $pdo->prepare(
        "SELECT
            idMedecin,
            prenom,
            nom,
            specialite,
            email,
            numtelephone,
            adresseCabinet,
            bio,
            {$rppsSelect}
         FROM medecin
         WHERE idMedecin = :idMedecin
         LIMIT 1"
    );
    $stmt->execute(['idMedecin' => $idMedecin]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        respond(404, [
            'success' => false,
            'message' => 'Doctor lookup failed: medecin profile row was not found after authentication'
        ]);
    }

    respond(200, [
        'success' => true,
        'profile' => $profile,
        'fields' => [
            'rpps' => $rppsColumn !== null
        ]
    ]);
} catch (Throwable $e) {
    error_log('Get medecin profile error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
