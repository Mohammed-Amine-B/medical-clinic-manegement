<?php
require_once '../auth/guard.php';
require_once '../database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

$user = requireRole(['patient', 'medecin', 'secretaire', 'admin']);

try {
    $stmt = $pdo->prepare("UPDATE notification SET isRead = 1 WHERE idUtilisateur = ?");
    $stmt->execute([$user['idUtilisateur']]);
    
    respond(['success' => true, 'message' => 'Toutes les notifications marquées comme lues']);
} catch (Exception $e) {
    error_log("Erreur mark_all_read: " . $e->getMessage());
    respond(['success' => false, 'message' => 'Erreur serveur'], 500);
}
?>