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
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

require_once __DIR__ . '/../auth/guard.php';
$currentUser = requireRole(['admin']);

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) {
    respond(400, ['success' => false, 'message' => 'Invalid JSON request']);
}

$idRaw = $data['idUtilisateur'] ?? null;
if ($idRaw === null || $idRaw === '' || !ctype_digit((string) $idRaw)) {
    respond(400, ['success' => false, 'message' => 'Invalid user id']);
}

$idUtilisateur = (int) $idRaw;
if ($idUtilisateur === (int) $currentUser['id']) {
    respond(400, ['success' => false, 'message' => 'You cannot delete your own account']);
}

try {
    require_once __DIR__ . '/../database.php';
    require_once __DIR__ . '/log_action.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $pdo->beginTransaction();

    $existingStmt = $pdo->prepare('SELECT idUtilisateur, login, role FROM utilisateur WHERE idUtilisateur = :idUtilisateur LIMIT 1');
    $existingStmt->execute(['idUtilisateur' => $idUtilisateur]);
    $existingUser = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingUser) {
        $pdo->rollBack();
        respond(404, ['success' => false, 'message' => 'User not found']);
    }

    foreach (['medecin', 'patient', 'secretaire'] as $table) {
        try {
            $unlinkStmt = $pdo->prepare("UPDATE `$table` SET idUtilisateur = NULL WHERE idUtilisateur = :idUtilisateur");
            $unlinkStmt->execute(['idUtilisateur' => $idUtilisateur]);
        } catch (Throwable $e) {
            error_log("Unable to unlink {$table} profile before deleting user {$idUtilisateur}: " . $e->getMessage());
        }
    }

    $stmt = $pdo->prepare('DELETE FROM utilisateur WHERE idUtilisateur = :idUtilisateur');
    $stmt->execute(['idUtilisateur' => $idUtilisateur]);

    if ($stmt->rowCount() < 1) {
        $pdo->rollBack();
        respond(500, ['success' => false, 'message' => 'Unable to delete user']);
    }

    $pdo->commit();
    logAction($pdo, 'delete user', 'Utilisateur supprimé: ' . $existingUser['login'] . ' (' . $existingUser['role'] . ')');
    respond(200, ['success' => true, 'message' => 'User deleted']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Delete user error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Unable to delete user. It may still be linked to existing clinical data.'
    ]);
}
