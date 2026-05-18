<?php
require_once '../config/database.php';

if(!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'super_admin') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_GET['id'] ?? 0;
$action = $_GET['action'] ?? '';

if($action == 'approve') {
    $stmt = $pdo->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
    $stmt->execute([$user_id]);
    $message = "Admin approved successfully!";
} elseif($action == 'reject') {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $message = "Admin rejected and removed.";
}

header("Location: dashboard.php?message=" . urlencode($message));
?>