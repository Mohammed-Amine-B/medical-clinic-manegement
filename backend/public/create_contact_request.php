<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensureContactRequestsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS contact_requests (
            idRequest INT AUTO_INCREMENT PRIMARY KEY,
            prenom VARCHAR(100),
            nom VARCHAR(100),
            email VARCHAR(150),
            telephone VARCHAR(30),
            typeConsultation VARCHAR(100),
            message TEXT,
            statut VARCHAR(30) DEFAULT 'nouveau',
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request'
    ]);
}

$prenom = trim((string) ($data['prenom'] ?? ''));
$nom = trim((string) ($data['nom'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$telephone = trim((string) ($data['telephone'] ?? ''));
$typeConsultation = trim((string) ($data['typeConsultation'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));

if ($prenom === '' || $nom === '' || $email === '' || $telephone === '' || $typeConsultation === '') {
    respond(400, [
        'success' => false,
        'message' => 'Veuillez remplir tous les champs obligatoires.'
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, [
        'success' => false,
        'message' => 'Adresse e-mail invalide.'
    ]);
}

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    ensureContactRequestsTable($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO contact_requests
            (prenom, nom, email, telephone, typeConsultation, message)
         VALUES
            (:prenom, :nom, :email, :telephone, :typeConsultation, :message)'
    );

    $stmt->execute([
        'prenom' => $prenom,
        'nom' => $nom,
        'email' => $email,
        'telephone' => $telephone,
        'typeConsultation' => $typeConsultation,
        'message' => $message
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Demande envoyée avec succès.'
    ]);
} catch (Throwable $e) {
    error_log('Create contact request error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
