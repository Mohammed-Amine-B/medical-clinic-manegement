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
$currentUser = requireRole(['secretaire']);

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $idUtilisateur = (int) $currentUser['id'];

    $stmt = $pdo->prepare(
        'SELECT
            idUtilisateur,
            login,
            role
         FROM utilisateur
         WHERE idUtilisateur = :idUtilisateur
         LIMIT 1'
    );

    $stmt->execute(['idUtilisateur' => $idUtilisateur]);
    $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$utilisateur) {
        respond(404, [
            'success' => false,
            'message' => 'Secrétaire profile not found'
        ]);
    }

    // For secrétaire, we use login as the name (since there's no separate name field)
    // If login contains @, it's an email, otherwise it's a phone/name
    $login = $utilisateur['login'];
    $name = $login;

    // If it's an email, try to extract name part
    if (strpos($login, '@') !== false) {
        $namePart = explode('@', $login)[0];
        // Convert common formats like firstname.lastname to "Firstname Lastname"
        $name = ucwords(str_replace(['.', '_', '-'], ' ', $namePart));
    }

    respond(200, [
        'success' => true,
        'profile' => [
            'idUtilisateur' => (int) $utilisateur['idUtilisateur'],
            'login' => $login,
            'name' => $name,
            'role' => $utilisateur['role']
        ]
    ]);
} catch (Throwable $e) {
    error_log('Get secrétaire profile error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
