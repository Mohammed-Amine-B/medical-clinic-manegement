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

require_once __DIR__ . '/guard.php';
$user = requireAuth();

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$currentPassword = (string) ($data['currentPassword'] ?? '');
$newPassword = (string) ($data['newPassword'] ?? '');
$confirmPassword = (string) ($data['confirmPassword'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    respond(400, [
        'success' => false,
        'message' => 'Tous les champs sont requis'
    ]);
}

if (strlen($newPassword) < 8) {
    respond(400, [
        'success' => false,
        'message' => 'Le nouveau mot de passe doit contenir au moins 8 caracteres'
    ]);
}

if ($newPassword !== $confirmPassword) {
    respond(400, [
        'success' => false,
        'message' => 'La confirmation ne correspond pas au nouveau mot de passe'
    ]);
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $idUtilisateur = (int) $user['id'];

    $stmt = $pdo->prepare(
        'SELECT motdepasse
         FROM utilisateur
         WHERE idUtilisateur = :idUtilisateur
         LIMIT 1'
    );
    $stmt->execute(['idUtilisateur' => $idUtilisateur]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        respond(404, [
            'success' => false,
            'message' => 'Utilisateur introuvable'
        ]);
    }

    if (!password_verify($currentPassword, (string) $row['motdepasse'])) {
        respond(400, [
            'success' => false,
            'message' => 'Mot de passe actuel incorrect'
        ]);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE utilisateur
         SET motdepasse = :motdepasse
         WHERE idUtilisateur = :idUtilisateur'
    );
    $updateStmt->execute([
        'motdepasse' => password_hash($newPassword, PASSWORD_DEFAULT),
        'idUtilisateur' => $idUtilisateur
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Mot de passe mis a jour avec succes'
    ]);
} catch (Throwable $e) {
    error_log('Change password error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
