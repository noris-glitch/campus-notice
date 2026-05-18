<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$notice_id = $_POST['notice_id'] ?? 0;

// Check if already bookmarked
$stmt = $pdo->prepare("SELECT * FROM bookmarks WHERE user_id = ? AND notice_id = ?");
$stmt->execute([$user_id, $notice_id]);

if($stmt->fetch()) {
    // Remove bookmark
    $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND notice_id = ?");
    $stmt->execute([$user_id, $notice_id]);
    echo json_encode(['success' => true, 'action' => 'removed']);
} else {
    // Add bookmark
    $stmt = $pdo->prepare("INSERT INTO bookmarks (user_id, notice_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $notice_id]);
    echo json_encode(['success' => true, 'action' => 'added']);
}
?>