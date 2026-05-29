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

function getDoctorSettings(PDO $pdo, int $idMedecin): array
{
    ensureSettingsTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT *
         FROM medecin_settings
         WHERE idMedecin = :idMedecin
         LIMIT 1'
    );
    $stmt->execute(['idMedecin' => $idMedecin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
        'morningStart' => substr((string) ($row['morningStart'] ?? $defaults['morningStart']), 0, 5),
        'morningEnd' => substr((string) ($row['morningEnd'] ?? $defaults['morningEnd']), 0, 5),
        'afternoonStart' => substr((string) ($row['afternoonStart'] ?? $defaults['afternoonStart']), 0, 5),
        'afternoonEnd' => substr((string) ($row['afternoonEnd'] ?? $defaults['afternoonEnd']), 0, 5),
        'consultationDuration' => (int) ($row['consultationDuration'] ?? $defaults['consultationDuration']),
        'onlineBooking' => (bool) ($row['onlineBooking'] ?? $defaults['onlineBooking']),
        'manualValidation' => (bool) ($row['manualValidation'] ?? $defaults['manualValidation'])
    ];
}

function timeToMinutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hours * 60) + $minutes;
}

function fitsRange(int $start, int $duration, string $rangeStart, string $rangeEnd): bool
{
    $rangeStartMinutes = timeToMinutes($rangeStart);
    $rangeEndMinutes = timeToMinutes($rangeEnd);

    return $start >= $rangeStartMinutes && ($start + $duration) <= $rangeEndMinutes;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
$currentUser = requireRole(['patient']);

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idMedecinRaw = $data['idMedecin'] ?? null;
$dateRdvRaw = $data['dateRdv'] ?? null;
$dureeRaw = $data['duree'] ?? null;
$typeConsultation = trim((string)($data['typeConsultation'] ?? ''));

// Validate inputs
if ($idMedecinRaw === null || $idMedecinRaw === '' || !ctype_digit((string) $idMedecinRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid doctor id'
    ]);
}

if ($dateRdvRaw === null || $dateRdvRaw === '') {
    respond(400, [
        'success' => false,
        'message' => 'Date is required'
    ]);
}

if ($dureeRaw === null || !ctype_digit((string) $dureeRaw) || (int) $dureeRaw <= 0) {
    respond(400, [
        'success' => false,
        'message' => 'Duration must be positive'
    ]);
}

if ($typeConsultation === '') {
    respond(400, [
        'success' => false,
        'message' => 'Type of consultation is required'
    ]);
}

// Parse date (accept both date only and datetime formats)
$dateHeure = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRdvRaw) === 1
    ? $dateRdvRaw . ' 09:00:00'
    : $dateRdvRaw;

// Validate datetime format
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateHeure)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid datetime format'
    ]);
}

$bookingDateTime = 
    DateTime::createFromFormat('Y-m-d H:i:s', $dateHeure, new DateTimeZone('UTC'));
if (!$bookingDateTime) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid datetime format'
    ]);
}

$now = new DateTime('now', new DateTimeZone('UTC'));
if ($bookingDateTime < $now) {
    respond(400, [
        'success' => false,
        'message' => 'Impossible de réserver une date passée'
    ]);
}

$idMedecin = (int) $idMedecinRaw;
$duree = (int) $dureeRaw;
$idUtilisateur = (int) $currentUser['id'];

try {
    require_once __DIR__ . '/../database.php';
    require_once __DIR__ . '/../admin/log_action.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    // Get patient id linked to current user
    $patientStmt = $pdo->prepare(
        'SELECT idPatient FROM patient WHERE idUtilisateur = :idUtilisateur LIMIT 1'
    );
    $patientStmt->execute(['idUtilisateur' => $idUtilisateur]);
    $patientRow = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patientRow) {
        respond(403, [
            'success' => false,
            'message' => 'Patient profile not found'
        ]);
    }

    $idPatient = (int) $patientRow['idPatient'];

    // Verify doctor exists
    $doctorStmt = $pdo->prepare(
        'SELECT idMedecin FROM medecin WHERE idMedecin = :idMedecin LIMIT 1'
    );
    $doctorStmt->execute(['idMedecin' => $idMedecin]);
    if (!$doctorStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(400, [
            'success' => false,
            'message' => 'Doctor not found'
        ]);
    }

    $settings = getDoctorSettings($pdo, $idMedecin);
    if (!$settings['onlineBooking']) {
        respond(400, [
            'success' => false,
            'message' => 'Online booking is disabled for this doctor'
        ]);
    }

    $bookingWeekday = (int) $bookingDateTime->format('w');
    if (!in_array($bookingWeekday, $settings['workingDays'], true)) {
        respond(400, [
            'success' => false,
            'message' => 'Selected date is outside doctor working days'
        ]);
    }

    $settingsDuration = (int) $settings['consultationDuration'];
    $bookingStart = timeToMinutes($bookingDateTime->format('H:i'));
    if (
        !fitsRange($bookingStart, $settingsDuration, $settings['morningStart'], $settings['morningEnd']) &&
        !fitsRange($bookingStart, $settingsDuration, $settings['afternoonStart'], $settings['afternoonEnd'])
    ) {
        respond(400, [
            'success' => false,
            'message' => 'Selected time is outside doctor working hours'
        ]);
    }

    $duree = $settingsDuration;
    $statut = $settings['manualValidation'] ? 'en_attente' : 'confirme';

    // Check for doctor conflicts (doctor can't have two appointments at same time)
    $conflictStmt = $pdo->prepare(
        "SELECT idRDV
         FROM rdv
         WHERE idMedecin = :idMedecin
           AND dateHeure < DATE_ADD(:dateHeureStart, INTERVAL :duree MINUTE)
           AND DATE_ADD(dateHeure, INTERVAL COALESCE(duree, 30) MINUTE) > :dateHeureEnd
           AND statut NOT IN ('annule', 'refuse')
         LIMIT 1"
    );
    $conflictStmt->execute([
        'idMedecin' => $idMedecin,
        'dateHeureStart' => $dateHeure,
        'dateHeureEnd' => $dateHeure,
        'duree' => $duree
    ]);

    if ($conflictStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(409, [
            'success' => false,
            'message' => 'This doctor is already booked at this time'
        ]);
    }

    // Create RDV with status based on doctor validation preference
    $insertStmt = $pdo->prepare(
        'INSERT INTO rdv (
            dateHeure,
            statut,
            idPatient,
            idMedecin,
            typeConsultation,
            duree
        ) VALUES (
            :dateHeure,
            :statut,
            :idPatient,
            :idMedecin,
            :typeConsultation,
            :duree
        )'
    );

    $insertStmt->execute([
        ':dateHeure' => $dateHeure,
        ':statut' => $statut,
        ':idPatient' => $idPatient,
        ':idMedecin' => $idMedecin,
        ':typeConsultation' => $typeConsultation,
        ':duree' => $duree
    ]);

    $idRDV = (int) $pdo->lastInsertId();
    logAction($pdo, 'create RDV', 'RDV patient #' . $idRDV . ' créé avec médecin #' . $idMedecin . ' le ' . $dateHeure, $currentUser);

    // Create notifications (optional, don't fail if table missing)
    try {
        // 1. Notify patient that RDV was created
        $patientNotificationStmt = $pdo->prepare(
            'INSERT INTO notification (idUtilisateur, message, type)
             VALUES (:idUtilisateur, :message, :type)'
        );
        $patientNotificationStmt->execute([
            'idUtilisateur' => $idUtilisateur,
            'message' => 'Votre demande de rendez-vous a été enregistrée et est en attente de confirmation.',
            'type' => 'rdv_created'
        ]);

        // 2. Notify doctor of new RDV request
        $doctorUserStmt = $pdo->prepare(
            'SELECT idUtilisateur FROM medecin WHERE idMedecin = :idMedecin LIMIT 1'
        );
        $doctorUserStmt->execute(['idMedecin' => $idMedecin]);
        $doctorUser = $doctorUserStmt->fetch(PDO::FETCH_ASSOC);

        if ($doctorUser) {
            $doctorNotificationStmt = $pdo->prepare(
                'INSERT INTO notification (idUtilisateur, message, type)
                 VALUES (:idUtilisateur, :message, :type)'
            );
            $doctorNotificationStmt->execute([
                'idUtilisateur' => (int) $doctorUser['idUtilisateur'],
                'message' => 'Nouvelle demande de rendez-vous reçue.',
                'type' => 'rdv_assigned'
            ]);
        }
    } catch (Throwable $e) {
        // Log but don't fail the RDV creation
        error_log('Notification creation failed: ' . $e->getMessage());
    }

    respond(201, [
        'success' => true,
        'message' => 'Appointment request created successfully',
        'idRDV' => $idRDV
    ]);
} catch (Throwable $e) {
    error_log('Create patient RDV error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
