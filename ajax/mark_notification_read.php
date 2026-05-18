<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$notification_id = $_POST['notification_id'] ?? 0;

$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
$stmt->execute([$notification_id, $user_id]);

echo json_encode(['success' => true]);
?>