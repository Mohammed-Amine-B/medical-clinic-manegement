<?php
declare(strict_types=1);

$host = 'localhost';
$dbName = 'lys_medical';
$dbUser = 'amine';
$dbPass = 'amine02';

$dsn = "mysql:host={$host};port=3307;dbname={$dbName};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
