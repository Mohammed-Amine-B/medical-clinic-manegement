<?php
declare(strict_types=1);

function ensureSystemLogsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_logs (
            idLog INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(255) NOT NULL,
            utilisateur VARCHAR(255) NULL,
            role VARCHAR(50) NULL,
            details TEXT NULL,
            dateAction DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function currentLogUser(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax'
        ]);
    }

    $user = $_SESSION['user'] ?? [];
    return is_array($user) ? $user : [];
}

function logAction(PDO $pdo, string $action, ?string $details = null, ?array $actor = null): void
{
    try {
        ensureSystemLogsTable($pdo);

        $actor = $actor ?? currentLogUser();
        $utilisateur = $actor['login'] ?? $actor['email'] ?? null;
        $role = isset($actor['role']) ? strtolower((string) $actor['role']) : null;

        $stmt = $pdo->prepare(
            'INSERT INTO system_logs (action, utilisateur, role, details)
             VALUES (:action, :utilisateur, :role, :details)'
        );
        $stmt->execute([
            'action' => $action,
            'utilisateur' => $utilisateur,
            'role' => $role,
            'details' => $details
        ]);
    } catch (Throwable $e) {
        error_log('System log write failed: ' . $e->getMessage());
    }
}
