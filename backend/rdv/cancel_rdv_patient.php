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

$idRDVRaw = $data['idRDV'] ?? null;

if ($idRDVRaw === null || $idRDVRaw === '' || !ctype_digit((string) $idRDVRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid appointment id'
    ]);
}

$idRDV = (int) $idRDVRaw;
$idUtilisateur = (int) $currentUser['id'];

try {
    require_once __DIR__ . '/../database.php';

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

    // Verify RDV exists and belongs to this patient
    $rdvStmt = $pdo->prepare(
        'SELECT idRDV, idPatient, statut FROM rdv WHERE idRDV = :idRDV LIMIT 1'
    );
    $rdvStmt->execute(['idRDV' => $idRDV]);
    $rdv = $rdvStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rdv) {
        respond(404, [
            'success' => false,
            'message' => 'Appointment not found'
        ]);
    }

    if ((int) $rdv['idPatient'] !== $idPatient) {
        respond(403, [
            'success' => false,
            'message' => 'Not authorized to cancel this appointment'
        ]);
    }

    // Only allow cancellation of pending or confirmed appointments
    if (!in_array($rdv['statut'], ['en_attente', 'confirme'], true)) {
        respond(400, [
            'success' => false,
            'message' => 'Cannot cancel appointment with status: ' . $rdv['statut']
        ]);
    }

    // Update status to 'annule'
    $updateStmt = $pdo->prepare(
        'UPDATE rdv SET statut = :statut WHERE idRDV = :idRDV'
    );
    $updateStmt->execute([
        ':statut' => 'annule',
        ':idRDV' => $idRDV
    ]);

    // Create notifications if the notifications system is available
    try {
        $patientNotificationStmt = $pdo->prepare(
            'INSERT INTO notification (idUtilisateur, message, type)
             VALUES (:idUtilisateur, :message, :type)'
        );
        $patientNotificationStmt->execute([
            'idUtilisateur' => $idUtilisateur,
            'message' => 'Votre rendez-vous a été annulé.',
            'type' => 'rdv_cancelled'
        ]);

        $doctorStmt = $pdo->prepare(
            'SELECT m.idUtilisateur FROM medecin m JOIN rdv r ON r.idMedecin = m.idMedecin WHERE r.idRDV = :idRDV LIMIT 1'
        );
        $doctorStmt->execute(['idRDV' => $idRDV]);
        $doctorUser = $doctorStmt->fetch(PDO::FETCH_ASSOC);

        if ($doctorUser && isset($doctorUser['idUtilisateur'])) {
            $doctorNotificationStmt = $pdo->prepare(
                'INSERT INTO notification (idUtilisateur, message, type)
                 VALUES (:idUtilisateur, :message, :type)'
            );
            $doctorNotificationStmt->execute([
                'idUtilisateur' => (int) $doctorUser['idUtilisateur'],
                'message' => 'Un rendez-vous a été annulé par le patient.',
                'type' => 'rdv_cancelled_by_patient'
            ]);
        }
    } catch (Throwable $notificationError) {
        error_log('Cancel patient RDV notification error: ' . $notificationError->getMessage());
    }

    respond(200, [
        'success' => true,
        'message' => 'Appointment cancelled successfully'
    ]);
} catch (Throwable $e) {
    error_log('Cancel patient RDV error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
