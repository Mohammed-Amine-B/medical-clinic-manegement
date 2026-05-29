<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

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
            'INSERT INTO medecin (nom, prenom, specialite, numtelephone, email, idUtilisateur)
             VALUES (:nom, :prenom, :specialite, :numtelephone, :email, :idUtilisateur)'
        );
        $medecinStmt->execute([
            'nom' => $nom,
            'prenom' => $prenom,
            'specialite' => $specialite,
            'numtelephone' => '',
            'email' => $login,
            'idUtilisateur' => $idUtilisateur
        ]);
    }

    if ($role === 'secretaire') {
        $stmtSec = $pdo->prepare(
            'INSERT INTO secretaire (nom, numTelephone, email, idUtilisateur)
             VALUES (:nom, :numTelephone, :email, :idUtilisateur)'
        );

        $stmtSec->execute([
            'nom' => trim($prenom . ' ' . $nom),
            'numTelephone' => '',
            'email' => $login,
            'idUtilisateur' => $idUtilisateur
        ]);
    }

    $pdo->commit();

    logAction($pdo, 'create user', 'Utilisateur créé: ' . $login . ' (' . $role . ')');

    echo json_encode([
        'success' => true,
        'message' => 'User created'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Add user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
