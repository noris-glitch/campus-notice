<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT notice_id FROM bookmarks WHERE user_id = ?");
$stmt->execute([$user_id]);
$bookmarks = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'bookmarks' => $bookmarks
]);
?>