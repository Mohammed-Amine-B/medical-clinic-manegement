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

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$email = trim((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($email === '' || $password === '') {
    respond(400, [
        'success' => false,
        'message' => 'Email and password are required'
    ]);
}



try {
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/admin/log_action.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare(
        'SELECT idUtilisateur, login, motdepasse, role
         FROM utilisateur
         WHERE login = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log('LOGIN DEBUG email=' . $email . ' password=' . $password . ' user=' . json_encode($user));

   if (!$user) {
    respond(401, [
        'success' => false,
        'message' => 'User not found for login: ' . $email
    ]);
}

if (!password_verify($password, (string)$user['motdepasse'])) {
    respond(401, [
        'success' => false,
        'message' => 'Password incorrect for login: ' . $email
    ]);
}

    $role = strtolower(trim((string)$user['role']));
    $allowedRoles = ['admin', 'medecin', 'secretaire', 'patient'];

    if (!in_array($role, $allowedRoles, true)) {
        respond(403, [
            'success' => false,
            'message' => 'Unauthorized role'
        ]);
    }

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ]);
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int)$user['idUtilisateur'],
        'login' => (string)$user['login'],
        'email' => (string)$user['login'],
        'role' => $role
    ];

    logAction($pdo, 'login', 'Connexion utilisateur', $_SESSION['user']);

    $redirects = [
        'admin' => 'admin.html',
        'medecin' => 'dashboard-medecin.html',
        'secretaire' => 'dashboard-secretaire.html',
        'patient' => 'dashboard-patient.html'
    ];

    respond(200, [
        'success' => true,
        'role' => $role,
        'redirect' => $redirects[$role]
    ]);
} catch (Throwable $e) {
    error_log('Login error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
