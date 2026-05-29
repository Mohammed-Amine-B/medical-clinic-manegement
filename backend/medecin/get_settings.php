<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function defaultMedecinSettings(): array
{
    return [
        'workingDays' => [0, 1, 2, 3, 4],
        'morningStart' => '08:00',
        'morningEnd' => '12:00',
        'afternoonStart' => '14:00',
        'afternoonEnd' => '18:00',
        'consultationDuration' => 30,
        'onlineBooking' => true,
        'manualValidation' => false
    ];
}

function ensureSettingsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS medecin_settings (
            idMedecin INT NOT NULL PRIMARY KEY,
            workingDays TEXT NOT NULL,
            morningStart TIME NOT NULL DEFAULT '08:00:00',
            morningEnd TIME NOT NULL DEFAULT '12:00:00',
            afternoonStart TIME NOT NULL DEFAULT '14:00:00',
            afternoonEnd TIME NOT NULL DEFAULT '18:00:00',
            consultationDuration INT NOT NULL DEFAULT 30,
            onlineBooking TINYINT(1) NOT NULL DEFAULT 1,
            manualValidation TINYINT(1) NOT NULL DEFAULT 0,
            updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );
}

function formatTimeValue(?string $value, string $fallback): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    return substr($value, 0, 5);
}

function normalizeSettings(?array $row): array
{
    $defaults = defaultMedecinSettings();

    if (!$row) {
        return $defaults;
    }

    $workingDays = json_decode((string) ($row['workingDays'] ?? ''), true);
    if (!is_array($workingDays)) {
        $workingDays = $defaults['workingDays'];
    }

    return [
        'workingDays' => array_values(array_map('intval', $workingDays)),
        'morningStart' => formatTimeValue($row['morningStart'] ?? null, $defaults['morningStart']),
        'morningEnd' => formatTimeValue($row['morningEnd'] ?? null, $defaults['morningEnd']),
        'afternoonStart' => formatTimeValue($row['afternoonStart'] ?? null, $defaults['afternoonStart']),
        'afternoonEnd' => formatTimeValue($row['afternoonEnd'] ?? null, $defaults['afternoonEnd']),
        'consultationDuration' => (int) ($row['consultationDuration'] ?? $defaults['consultationDuration']),
        'onlineBooking' => (bool) ($row['onlineBooking'] ?? $defaults['onlineBooking']),
        'manualValidation' => (bool) ($row['manualValidation'] ?? $defaults['manualValidation'])
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['medecin']);
$idMedecin = getLoggedMedecinId();

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    ensureSettingsTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT *
         FROM medecin_settings
         WHERE idMedecin = :idMedecin
         LIMIT 1'
    );
    $stmt->execute(['idMedecin' => $idMedecin]);

    respond(200, [
        'success' => true,
        'settings' => normalizeSettings($stmt->fetch(PDO::FETCH_ASSOC) ?: null)
    ]);
} catch (Throwable $e) {
    error_log('Get medecin settings error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
