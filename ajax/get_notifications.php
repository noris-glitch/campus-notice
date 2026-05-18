<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get unread notifications
$stmt = $pdo->prepare("
    SELECT n.*, nt.title as notice_title 
    FROM notifications n 
    JOIN notices nt ON n.notice_id = nt.id 
    WHERE n.user_id = ? 
    ORDER BY n.created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

// Format notifications for display
$formatted = [];
foreach($notifications as $notif) {
    $formatted[] = [
        'id' => $notif['id'],
        'title' => $notif['title'],
        'message' => $notif['message'],
        'notice_id' => $notif['notice_id'],
        'is_read' => $notif['is_read'],
        'time_ago' => timeAgo($notif['created_at'])
    ];
}

echo json_encode([
    'success' => true,
    'unread_count' => count(array_filter($formatted, function($n) { return !$n['is_read']; })),
    'notifications' => $formatted
]);

function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if ($seconds <= 60) {
        return "Just now";
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
    } else if ($hours <= 24) {
        return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
    } else if ($days <= 7) {
        return ($days == 1) ? "yesterday" : "$days days ago";
    } else if ($weeks <= 4.3) {
        return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
    } else if ($months <= 12) {
        return ($months == 1) ? "1 month ago" : "$months months ago";
    } else {
        return ($years == 1) ? "1 year ago" : "$years years ago";
    }
}
?>