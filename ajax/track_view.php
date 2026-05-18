<?php
require_once '../config/database.php';

if(!isset($_SESSION['user_id'])) {
    exit();
}

$notice_id = $_POST['notice_id'] ?? 0;
$user_id = $_SESSION['user_id'];

// Check if already viewed
$stmt = $pdo->prepare("SELECT * FROM notice_views WHERE notice_id = ? AND user_id = ?");
$stmt->execute([$notice_id, $user_id]);

if($stmt->rowCount() == 0) {
    // Add view
    $stmt = $pdo->prepare("INSERT INTO notice_views (notice_id, user_id) VALUES (?, ?)");
    $stmt->execute([$notice_id, $user_id]);
    
    echo json_encode(['success' => true, 'message' => 'View tracked']);
} else {
    echo json_encode(['success' => false, 'message' => 'Already viewed']);
}
?>