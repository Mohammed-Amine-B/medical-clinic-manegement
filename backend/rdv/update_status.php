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
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../admin/log_action.php';

$currentUser = requireRole(['medecin', 'secretaire', 'admin']);
$loggedMedecinId = null;
if ((string) $currentUser['role'] === 'medecin') {
    $loggedMedecinId = getLoggedMedecinId();
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idRdvRaw = $data['idRDV'] ?? null;
$statut = trim((string) ($data['statut'] ?? ''));

if ($idRdvRaw === null || $idRdvRaw === '' || !ctype_digit((string) $idRdvRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid RDV id'
    ]);
}

$allowedStatuses = ['confirme', 'refuse', 'annule', 'present', 'en_attente'];

if (!in_array($statut, $allowedStatuses, true)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid status'
    ]);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $idRdvInt = (int) $idRdvRaw;

    // Perform the update based on user role
    if ((string) $currentUser['role'] === 'medecin') {
        $stmt = $pdo->prepare(
            'UPDATE rdv SET statut = :statut WHERE idRDV = :idRDV AND idMedecin = :idMedecin'
        );
        $stmt->execute([
            'statut' => $statut,
            'idRDV' => $idRdvInt,
            'idMedecin' => $loggedMedecinId
        ]);
    } else {
        // Secretaire or admin can update any RDV
        $stmt = $pdo->prepare(
            'UPDATE rdv SET statut = :statut WHERE idRDV = :idRDV'
        );
        $stmt->execute([
            'statut' => $statut,
            'idRDV' => $idRdvInt
        ]);
    }

    // Check if update affected any rows
    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare('SELECT idRDV, idMedecin FROM rdv WHERE idRDV = :id');
        $check->execute(['id' => $idRdvInt]);
        $existingRdv = $check->fetch(PDO::FETCH_ASSOC);

        if (!$existingRdv) {
            respond(404, [
                'success' => false,
                'message' => 'RDV not found'
            ]);
        }

        if ((string) $currentUser['role'] === 'medecin' && (int) $existingRdv['idMedecin'] !== $loggedMedecinId) {
            respond(403, [
                'success' => false,
                'message' => 'Forbidden'
            ]);
        }
    }

    // Try to create notification for patient if status changed to confirme or refuse
    if (in_array($statut, ['confirme', 'refuse'], true)) {
        try {
            $patientStmt = $pdo->prepare(
                'SELECT p.idUtilisateur FROM patient p JOIN rdv r ON r.idPatient = p.idPatient WHERE r.idRDV = :idRDV LIMIT 1'
            );
            $patientStmt->execute(['idRDV' => $idRdvInt]);
            $patientUser = $patientStmt->fetch(PDO::FETCH_ASSOC);

            if ($patientUser && isset($patientUser['idUtilisateur'])) {
                $message = $statut === 'confirme'
                    ? 'Votre rendez-vous a été confirmé.'
                    : 'Votre demande de rendez-vous a été refusée.';

                $notificationStmt = $pdo->prepare(
                    'INSERT INTO notification (idUtilisateur, message, type) VALUES (:idUtilisateur, :message, :type)'
                );
                $notificationStmt->execute([
                    'idUtilisateur' => (int) $patientUser['idUtilisateur'],
                    'message' => $message,
                    'type' => $statut === 'confirme' ? 'rdv_confirmed' : 'rdv_cancelled'
                ]);
            }
        } catch (Throwable $notifError) {
            error_log('Notification creation (non-critical): ' . $notifError->getMessage());
            // Don't fail the whole request if notification fails (table might not exist)
        }
    }

    logAction($pdo, 'update RDV status', 'RDV #' . $idRdvInt . ' statut: ' . $statut, $currentUser);

    respond(200, [
        'success' => true,
        'message' => 'Status updated'
    ]);
} catch (Throwable $e) {
    error_log('Update RDV status error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
?>
