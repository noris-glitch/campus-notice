<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$alert_id = $_POST['alert_id'] ?? 0;
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("UPDATE emergency_alert_receipts SET is_read = 1, read_at = NOW() WHERE alert_id = ? AND user_id = ?");
$stmt->execute([$alert_id, $user_id]);

echo json_encode(['success' => true]);
?>