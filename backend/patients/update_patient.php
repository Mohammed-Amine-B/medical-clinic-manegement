<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
    header('Allow: POST, PUT');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
$currentUser = requireRole(['secretaire', 'admin']);

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idPatientRaw = $data['idPatient'] ?? null;
$prenom = trim((string)($data['prenom'] ?? ''));
$nom = trim((string)($data['nom'] ?? ''));
$dateNaissance = trim((string)($data['date_naissance'] ?? ''));
$sexe = trim((string)($data['sexe'] ?? ''));
$telephone = trim((string)($data['telephone'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$specialite = trim((string)($data['specialite'] ?? ''));
$idMedecinRaw = $data['idMedecin'] ?? null;
$adresse = trim((string)($data['adresse'] ?? ''));
$statut = trim((string)($data['statut'] ?? ''));
$notes = trim((string)($data['notes'] ?? ''));

if ($idPatientRaw === null || $idPatientRaw === '' || !ctype_digit((string) $idPatientRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Missing idPatient'
    ]);
}

if ($prenom === '' || $nom === '') {
    respond(400, [
        'success' => false,
        'message' => 'Missing required fields'
    ]);
}

if ($telephone === '') {
    respond(400, [
        'success' => false,
        'message' => 'Missing required fields'
    ]);
}

if ($specialite === '') {
    respond(400, [
        'success' => false,
        'message' => 'Missing required fields'
    ]);
}

if ($idMedecinRaw === '' || $idMedecinRaw === null) {
    $idMedecin = null;
} elseif (is_int($idMedecinRaw)) {
    $idMedecin = $idMedecinRaw;
} elseif (is_string($idMedecinRaw) && ctype_digit(trim($idMedecinRaw))) {
    $idMedecin = (int) trim($idMedecinRaw);
} else {
    respond(400, [
        'success' => false,
        'message' => 'Invalid doctor id'
    ]);
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare(
        'UPDATE patient SET
            nom = :nom,
            prenom = :prenom,
            date_naissance = :date_naissance,
            telephone = :telephone,
            email = :email,
            sexe = :sexe,
            adresse = :adresse,
            specialite = :specialite,
            idMedecin = :idMedecin,
            statut = :statut,
            notes = :notes
         WHERE idPatient = :idPatient'
    );

    $stmt->execute([
        'idPatient' => (int) $idPatientRaw,
        'nom' => $nom,
        'prenom' => $prenom,
        'date_naissance' => $dateNaissance !== '' ? $dateNaissance : null,
        'telephone' => $telephone,
        'email' => $email !== '' ? $email : null,
        'sexe' => $sexe !== '' ? $sexe : null,
        'adresse' => $adresse !== '' ? $adresse : null,
        'specialite' => $specialite,
        'idMedecin' => $idMedecin,
        'statut' => $statut !== '' ? $statut : null,
        'notes' => $notes
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Patient updated'
    ]);
} catch (Throwable $e) {
    error_log('Update patient error: ' . $e->getMessage());

    $message = $e instanceof PDOException
        ? 'SQL error: ' . $e->getMessage()
        : $e->getMessage();

    respond(500, [
        'success' => false,
        'message' => $message
    ]);
}
