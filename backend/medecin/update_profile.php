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

function cleanText($value, int $maxLength): string
{
    return substr(trim((string) ($value ?? '')), 0, $maxLength);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Request method check failed: update_profile.php only accepts POST'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['medecin']);
$idMedecin = getLoggedMedecinId();

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Request body check failed: invalid JSON request'
    ]);
}

$prenom = cleanText($data['prenom'] ?? '', 100);
$nom = cleanText($data['nom'] ?? '', 100);
$specialite = cleanText($data['specialite'] ?? '', 100);
$email = cleanText($data['email'] ?? '', 100);
$numtelephone = cleanText($data['numtelephone'] ?? '', 20);
$adresseCabinet = cleanText($data['adresseCabinet'] ?? '', 255);
$bio = cleanText($data['bio'] ?? '', 2000);
$rpps = cleanText($data['rpps'] ?? '', 100);

if ($prenom === '' || $nom === '') {
    respond(400, [
        'success' => false,
        'message' => 'Missing fields check failed: prenom and nom are required'
    ]);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, [
        'success' => false,
        'message' => 'Field validation failed: invalid email'
    ]);
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Profile save failed: database connection is not available');
    }

    $columns = ensureProfileColumns($pdo);
    $rppsColumn = firstExistingColumn($columns, ['rpps', 'RPPS', 'numRPPS', 'numeroRPPS']);

    $assignments = [
        'prenom = :prenom',
        'nom = :nom',
        'specialite = :specialite',
        'email = :email',
        'numtelephone = :numtelephone',
        'adresseCabinet = :adresseCabinet',
        'bio = :bio'
    ];

    $params = [
        'prenom' => $prenom,
        'nom' => $nom,
        'specialite' => $specialite,
        'email' => $email,
        'numtelephone' => $numtelephone,
        'adresseCabinet' => $adresseCabinet,
        'bio' => $bio,
        'idMedecin' => $idMedecin
    ];

    if ($rppsColumn !== null) {
        $assignments[] = '`' . $rppsColumn . '` = :rpps';
        $params['rpps'] = $rpps;
    }

    $stmt = $pdo->prepare(
        'UPDATE medecin
         SET ' . implode(', ', $assignments) . '
         WHERE idMedecin = :idMedecin'
    );
    $stmt->execute($params);

    respond(200, [
        'success' => true,
        'message' => 'Profile saved',
        'profile' => [
            'prenom' => $prenom,
            'nom' => $nom,
            'specialite' => $specialite,
            'email' => $email,
            'numtelephone' => $numtelephone,
            'adresseCabinet' => $adresseCabinet,
            'bio' => $bio,
            'rpps' => $rppsColumn !== null ? $rpps : null
        ]
    ]);
} catch (Throwable $e) {
    error_log('Update medecin profile error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
