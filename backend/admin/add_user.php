<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/guard.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON request'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$prenom = trim((string) ($data['prenom'] ?? ''));
$nom = trim((string) ($data['nom'] ?? ''));
$login = trim((string) ($data['login'] ?? ''));
$password = (string) ($data['password'] ?? '');
$role = trim((string) ($data['role'] ?? ''));
$specialite = trim((string) ($data['specialite'] ?? ''));
$numtelephone = trim((string) ($data['numtelephone'] ?? $data['numTelephone'] ?? ''));
$adresseCabinet = trim((string) ($data['adresseCabinet'] ?? ''));
$bio = trim((string) ($data['bio'] ?? ''));
$secretaireNom = trim((string) ($data['secretaire_nom'] ?? ''));
$secretaireTelephone = trim((string) ($data['numTelephone'] ?? ''));
$dateNaissance = trim((string) ($data['date_naissance'] ?? ''));
$sexe = trim((string) ($data['sexe'] ?? ''));
$telephone = trim((string) ($data['telephone'] ?? ''));
$adresse = trim((string) ($data['adresse'] ?? ''));
$patientSpecialite = trim((string) ($data['specialite'] ?? ''));
$idMedecinRaw = $data['idMedecin'] ?? null;
$statut = trim((string) ($data['statut'] ?? ''));
$notes = trim((string) ($data['notes'] ?? ''));
$idUtilisateur = null;
$allowedRoles = ['admin', 'medecin', 'secretaire', 'patient'];

if ($login === '' || $password === '' || $role === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'All fields are required'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid role'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($role === 'medecin' && ($prenom === '' || $nom === '')) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Doctor first name and last name are required'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($role === 'medecin' && ($specialite === '' || $numtelephone === '')) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Doctor specialty and phone are required'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($role === 'secretaire' && ($secretaireNom === '' || $secretaireTelephone === '')) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Secretary full name and phone are required'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($role === 'patient') {
    if (
        $prenom === '' ||
        $nom === '' ||
        $dateNaissance === '' ||
        $sexe === '' ||
        $telephone === '' ||
        $adresse === '' ||
        $patientSpecialite === '' ||
        $statut === ''
    ) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Patient dossier fields are required'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateNaissance) !== 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid birth date'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($nom === '') {
    $nom = trim($prenom . ' ' . $nom);
}

try {
    require_once __DIR__ . '/../database.php';
    require_once __DIR__ . '/log_action.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $pdo->beginTransaction();

    $idMedecin = null;
    if ($role === 'patient' && $idMedecinRaw !== null && $idMedecinRaw !== '') {
        if (is_int($idMedecinRaw)) {
            $idMedecin = $idMedecinRaw;
        } elseif (is_string($idMedecinRaw) && ctype_digit(trim($idMedecinRaw))) {
            $idMedecin = (int) trim($idMedecinRaw);
        } else {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid doctor id'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $doctorStmt = $pdo->prepare('SELECT idMedecin FROM medecin WHERE idMedecin = :idMedecin LIMIT 1');
        $doctorStmt->execute(['idMedecin' => $idMedecin]);
        if (!$doctorStmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Doctor not found'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $checkStmt = $pdo->prepare('SELECT idUtilisateur FROM utilisateur WHERE login = :login LIMIT 1');
    $checkStmt->execute(['login' => $login]);
    if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Login already exists'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO utilisateur (login, motdepasse, role)
         VALUES (:login, :motdepasse, :role)'
    );

    $stmt->execute([
        'login' => $login,
        'motdepasse' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role
    ]);

    $idUtilisateur = (int) $pdo->lastInsertId();

    if ($role === 'medecin') {
        $medecinStmt = $pdo->prepare(
            'INSERT INTO medecin (nom, prenom, specialite, numtelephone, email, idUtilisateur, adresseCabinet, bio)
             VALUES (:nom, :prenom, :specialite, :numtelephone, :email, :idUtilisateur, :adresseCabinet, :bio)'
        );
        $medecinStmt->execute([
            'nom' => $nom,
            'prenom' => $prenom,
            'specialite' => $specialite,
            'numtelephone' => $numtelephone,
            'email' => $login,
            'idUtilisateur' => $idUtilisateur,
            'adresseCabinet' => $adresseCabinet !== '' ? $adresseCabinet : null,
            'bio' => $bio !== '' ? $bio : null
        ]);
    }

    if ($role === 'secretaire') {
        $stmtSec = $pdo->prepare(
            'INSERT INTO secretaire (nom, numTelephone, email, idUtilisateur)
             VALUES (:nom, :numTelephone, :email, :idUtilisateur)'
        );

        $stmtSec->execute([
            'nom' => $secretaireNom,
            'numTelephone' => $secretaireTelephone,
            'email' => $login,
            'idUtilisateur' => $idUtilisateur
        ]);
    }

    if ($role === 'patient') {
        $patientStmt = $pdo->prepare(
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

        $patientStmt->execute([
            'nom' => $nom,
            'prenom' => $prenom,
            'date_naissance' => $dateNaissance,
            'telephone' => $telephone,
            'email' => $login,
            'sexe' => $sexe,
            'adresse' => $adresse,
            'specialite' => $patientSpecialite,
            'idMedecin' => $idMedecin,
            'statut' => $statut,
            'notes' => $notes,
            'idUtilisateur' => $idUtilisateur
        ]);
    }

    $pdo->commit();

    logAction($pdo, 'create user', 'Utilisateur créé: ' . $login . ' (' . $role . ')');

    echo json_encode([
        'success' => true,
        'message' => 'User created',
        'idUtilisateur' => $idUtilisateur
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Add user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
