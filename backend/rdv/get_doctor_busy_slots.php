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

$currentUser = requireRole(['patient', 'secretaire', 'medecin', 'admin']);

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$idMedecinRaw = $data['idMedecin'] ?? null;
$date = trim((string) ($data['date'] ?? ''));

if ($idMedecinRaw === null || $idMedecinRaw === '' || !ctype_digit((string) $idMedecinRaw)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid doctor id'
    ]);
}

if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid date format (YYYY-MM-DD)'
    ]);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare(
        "SELECT TIME(dateHeure) as time_slot, COALESCE(duree, 30) AS duree
         FROM rdv
         WHERE idMedecin = :idMedecin
           AND DATE(dateHeure) = :date
           AND statut NOT IN ('annule', 'refuse')
         ORDER BY dateHeure"
    );
    $stmt->execute([
        'idMedecin' => (int) $idMedecinRaw,
        'date' => $date
    ]);

    $busySlots = [];
    $busyIntervals = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $start = substr((string) $row['time_slot'], 0, 5);
        $duration = (int) ($row['duree'] ?? 30);
        $startDate = DateTime::createFromFormat('H:i', $start);
        $endDate = $startDate ? (clone $startDate)->modify('+' . $duration . ' minutes') : null;

        $busySlots[] = $row['time_slot'];
        if ($startDate && $endDate) {
            $busyIntervals[] = [
                'start' => str_replace(':', 'h', $startDate->format('H:i')),
                'end' => str_replace(':', 'h', $endDate->format('H:i'))
            ];
        }
    }

    respond(200, [
        'success' => true,
        'busySlots' => $busySlots,
        'busyIntervals' => $busyIntervals
    ]);
} catch (Throwable $e) {
    error_log('Get doctor busy slots error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
?>
