<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['secretaire', 'admin']);

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rdv');
    $stmt->execute();
    $totalRdv = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rdv WHERE statut = 'en_attente'");
    $stmt->execute();
    $rdvEnAttente = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM patient');
    $stmt->execute();
    $totalPatients = (int) $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_rdv' => $totalRdv,
            'rdv_en_attente' => $rdvEnAttente,
            'total_patients' => $totalPatients
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ], JSON_UNESCAPED_UNICODE);
}
