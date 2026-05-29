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

$password = (string)($data['password'] ?? '');

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

if ($password === '') {
    respond(400, [
        'success' => false,
        'message' => 'Password is required'
    ]);
}

if (strlen($password) < 6) {
    respond(400, [
        'success' => false,
        'message' => 'Password must contain at least 6 characters'
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

    $login = $email !== '' ? $email : $telephone;
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $checkLoginStmt = $pdo->prepare(
        'SELECT idUtilisateur FROM utilisateur WHERE login = :login LIMIT 1'
    );
    $checkLoginStmt->execute([
        'login' => $login
    ]);

    if ($checkLoginStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(409, [
            'success' => false,
            'message' => 'Login already exists'
        ]);
    }

    $pdo->beginTransaction();

    $userStmt = $pdo->prepare(
        'INSERT INTO utilisateur (
            login,
            motdepasse,
            role
        ) VALUES (
            :login,
            :motdepasse,
            :role
        )'
    );

    $userStmt->execute([
        'login' => $login,
        'motdepasse' => $passwordHash,
        'role' => 'patient'
    ]);

    $idUtilisateur = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO patient (
            nom,
            prenom,
            date_naissance,
            telephone,
            email,
            sexe,
            adresse,
            specialite,
            idMedecin,
            statut,
            notes,
            idUtilisateur
        ) VALUES (
            :nom,
            :prenom,
            :date_naissance,
            :telephone,
            :email,
            :sexe,
            :adresse,
            :specialite,
            :idMedecin,
            :statut,
            :notes,
            :idUtilisateur
        )'
    );

    $stmt->execute([
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
        'notes' => $notes,
        'idUtilisateur' => $idUtilisateur
    ]);

    $pdo->commit();

    $idPatient = (int) $stmt->rowCount() > 0 ? $pdo->lastInsertId() : null;

    respond(201, [
        'success' => true,
        'message' => 'Patient created',
        'idPatient' => $idPatient,
        'idUtilisateur' => $idUtilisateur,
        'login' => $login
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Add patient error: ' . $e->getMessage());

    $message = $e instanceof PDOException
        ? 'SQL error: ' . $e->getMessage()
        : $e->getMessage();

    respond(500, [
        'success' => false,
        'message' => $message
    ]);
}