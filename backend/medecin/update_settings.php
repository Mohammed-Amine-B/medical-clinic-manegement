<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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

function validTime(string $value): bool
{
    return preg_match('/^\d{2}:\d{2}$/', $value) === 1;
}

function normalizeTime(string $value): string
{
    return $value . ':00';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['medecin']);
$idMedecin = getLoggedMedecinId();

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$workingDays = $data['workingDays'] ?? [0, 1, 2, 3, 4];
if (!is_array($workingDays)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid working days'
    ]);
}

$workingDays = array_values(array_unique(array_filter(
    array_map('intval', $workingDays),
    static fn(int $day): bool => $day >= 0 && $day <= 6
)));

$morningStart = trim((string) ($data['morningStart'] ?? '08:00'));
$morningEnd = trim((string) ($data['morningEnd'] ?? '12:00'));
$afternoonStart = trim((string) ($data['afternoonStart'] ?? '14:00'));
$afternoonEnd = trim((string) ($data['afternoonEnd'] ?? '18:00'));
$consultationDuration = (int) ($data['consultationDuration'] ?? 30);
$onlineBooking = !empty($data['onlineBooking']) ? 1 : 0;
$manualValidation = !empty($data['manualValidation']) ? 1 : 0;

if (
    !validTime($morningStart) ||
    !validTime($morningEnd) ||
    !validTime($afternoonStart) ||
    !validTime($afternoonEnd)
) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid time format'
    ]);
}

if ($consultationDuration < 5 || $consultationDuration > 240) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid consultation duration'
    ]);
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    ensureSettingsTable($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO medecin_settings (
            idMedecin,
            workingDays,
            morningStart,
            morningEnd,
            afternoonStart,
            afternoonEnd,
            consultationDuration,
            onlineBooking,
            manualValidation
        ) VALUES (
            :idMedecin,
            :workingDays,
            :morningStart,
            :morningEnd,
            :afternoonStart,
            :afternoonEnd,
            :consultationDuration,
            :onlineBooking,
            :manualValidation
        )
        ON DUPLICATE KEY UPDATE
            workingDays = VALUES(workingDays),
            morningStart = VALUES(morningStart),
            morningEnd = VALUES(morningEnd),
            afternoonStart = VALUES(afternoonStart),
            afternoonEnd = VALUES(afternoonEnd),
            consultationDuration = VALUES(consultationDuration),
            onlineBooking = VALUES(onlineBooking),
            manualValidation = VALUES(manualValidation)"
    );

    $stmt->execute([
        'idMedecin' => $idMedecin,
        'workingDays' => json_encode($workingDays),
        'morningStart' => normalizeTime($morningStart),
        'morningEnd' => normalizeTime($morningEnd),
        'afternoonStart' => normalizeTime($afternoonStart),
        'afternoonEnd' => normalizeTime($afternoonEnd),
        'consultationDuration' => $consultationDuration,
        'onlineBooking' => $onlineBooking,
        'manualValidation' => $manualValidation
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Settings saved'
    ]);
} catch (Throwable $e) {
    error_log('Update medecin settings error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
