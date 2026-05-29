<?php
declare(strict_types=1);

function authRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensureSessionStarted(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax'
        ]);
    }
}

function requireAuth(): array
{
    ensureSessionStarted();

    if (
        !isset($_SESSION['user']) ||
        !is_array($_SESSION['user']) ||
        !isset($_SESSION['user']['id'], $_SESSION['user']['role'])
    ) {
        authRespond(401, [
            'success' => false,
            'message' => 'Session missing or invalid: $_SESSION["user"]["id"] and $_SESSION["user"]["role"] are required'
        ]);
    }

    return $_SESSION['user'];
}

function getLoggedUser(): array
{
    return requireAuth();
}

function requireRole($roles): array
{
    $user = requireAuth();
    $allowed = is_array($roles) ? $roles : [$roles];
    $role = strtolower((string) $user['role']);
    $allowed = array_map(static fn($item): string => strtolower((string) $item), $allowed);

    if (!in_array($role, $allowed, true)) {
        authRespond(403, [
            'success' => false,
            'message' => 'Forbidden: requireRole failed because current user role is "' . $role . '" but expected one of "' . implode(', ', $allowed) . '"'
        ]);
    }

    return $user;
}

function getLoggedMedecinId(): int
{
    $user = requireRole(['medecin']);

    global $pdo;
    require_once __DIR__ . '/../database.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        authRespond(500, [
            'success' => false,
            'message' => 'Doctor lookup failed: database connection is not available'
        ]);
    }

    $stmt = $pdo->prepare(
        'SELECT idMedecin
         FROM medecin
         WHERE idUtilisateur = :idUtilisateur
         LIMIT 1'
    );
    $stmt->execute(['idUtilisateur' => (int) $user['id']]);
    $medecin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$medecin) {
        $sessionEmail = trim((string) ($user['email'] ?? $user['login'] ?? ''));

        if ($sessionEmail !== '') {
            $doctorByEmailStmt = $pdo->prepare(
                'SELECT idMedecin, idUtilisateur
                 FROM medecin
                 WHERE email = :email
                 LIMIT 1'
            );
            $doctorByEmailStmt->execute(['email' => $sessionEmail]);
            $medecin = $doctorByEmailStmt->fetch(PDO::FETCH_ASSOC);

            if (
                $medecin &&
                (!isset($medecin['idUtilisateur']) || (int) $medecin['idUtilisateur'] === 0)
            ) {
                $updateDoctorStmt = $pdo->prepare(
                    'UPDATE medecin
                     SET idUtilisateur = :idUtilisateur
                     WHERE idMedecin = :idMedecin'
                );
                $updateDoctorStmt->execute([
                    'idUtilisateur' => (int) $user['id'],
                    'idMedecin' => (int) $medecin['idMedecin']
                ]);
            }
        }
    }

    if (!$medecin) {
        authRespond(404, [
            'success' => false,
            'message' => 'Doctor lookup failed: no medecin row found for this logged-in user id or session email'
        ]);
    }

    return (int) $medecin['idMedecin'];
}
