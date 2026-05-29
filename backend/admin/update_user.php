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
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $stmt->execute();
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (isset($column['Field'])) {
            $columns[strtolower((string) $column['Field'])] = (string) $column['Field'];
        }
    }
    return $columns;
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
$login = trim((string) ($data['login'] ?? ''));
$role = strtolower(trim((string) ($data['role'] ?? '')));
$statut = strtolower(trim((string) ($data['statut'] ?? '')));
$allowedRoles = ['admin', 'medecin', 'secretaire', 'patient'];
$allowedStatuses = ['actif', 'inactif', 'active', 'inactive', '1', '0'];

if ($idRaw === null || $idRaw === '' || !ctype_digit((string) $idRaw)) {
    respond(400, ['success' => false, 'message' => 'Invalid user id']);
}

if ($login === '') {
    respond(400, ['success' => false, 'message' => 'Login is required']);
}

if (!in_array($role, $allowedRoles, true)) {
    respond(400, ['success' => false, 'message' => 'Invalid role']);
}

try {
    require_once __DIR__ . '/../database.php';
    require_once __DIR__ . '/log_action.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $idUtilisateur = (int) $idRaw;
    $existingStmt = $pdo->prepare('SELECT idUtilisateur FROM utilisateur WHERE idUtilisateur = :idUtilisateur LIMIT 1');
    $existingStmt->execute(['idUtilisateur' => $idUtilisateur]);
    if (!$existingStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(404, ['success' => false, 'message' => 'User not found']);
    }

    $duplicateStmt = $pdo->prepare(
        'SELECT idUtilisateur FROM utilisateur WHERE login = :login AND idUtilisateur <> :idUtilisateur LIMIT 1'
    );
    $duplicateStmt->execute([
        'login' => $login,
        'idUtilisateur' => $idUtilisateur
    ]);
    if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(409, ['success' => false, 'message' => 'Login already exists']);
    }

    $columns = tableColumns($pdo, 'utilisateur');
    $sets = ['login = :login', 'role = :role'];
    $params = [
        'login' => $login,
        'role' => $role,
        'idUtilisateur' => $idUtilisateur
    ];

    $statusColumn = null;
    $statusColumnKey = null;
    foreach (['statut', 'status', 'actif', 'isActive'] as $candidate) {
        $key = strtolower($candidate);
        if (isset($columns[$key])) {
            $statusColumn = $columns[$key];
            $statusColumnKey = $key;
            break;
        }
    }

    if ($statusColumn !== null && $statut !== '') {
        if (!in_array($statut, $allowedStatuses, true)) {
            respond(400, ['success' => false, 'message' => 'Invalid status']);
        }
        if ($idUtilisateur === (int) $currentUser['id'] && in_array($statut, ['inactif', 'inactive', '0'], true)) {
            respond(400, ['success' => false, 'message' => 'You cannot deactivate your own account']);
        }
        $sets[] = "`{$statusColumn}` = :statut";
        if (in_array($statusColumnKey, ['actif', 'isactive'], true)) {
            $params['statut'] = in_array($statut, ['actif', 'active', '1'], true) ? 1 : 0;
        } else {
            $params['statut'] = in_array($statut, ['1', 'active'], true) ? 'actif' : (in_array($statut, ['0', 'inactive'], true) ? 'inactif' : $statut);
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE utilisateur SET ' . implode(', ', $sets) . ' WHERE idUtilisateur = :idUtilisateur'
    );
    $stmt->execute($params);

    logAction($pdo, 'update user', 'Utilisateur #' . $idUtilisateur . ' mis à jour: ' . $login . ' (' . $role . ')');

    respond(200, ['success' => true, 'message' => 'User updated']);
} catch (Throwable $e) {
    error_log('Update user error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Server error']);
}
