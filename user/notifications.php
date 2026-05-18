<?php
require_once '../config/database.php';

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$user_name = $_SESSION['user_name'];

// Get all notifications for this user
$notifications = [];

try {
    // Check if notifications table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE 'notifications'");
    if($checkTable->rowCount() > 0) {
        $stmt = $pdo->prepare("
            SELECT n.*, nt.title as notice_title, nt.id as notice_id 
            FROM notifications n 
            JOIN notices nt ON n.notice_id = nt.id 
            WHERE n.user_id = ? 
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll();
        
        // Mark all as read when viewing the notifications page
        $updateStmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $updateStmt->execute([$user_id]);
    }
} catch (PDOException $e) {
    $notifications = [];
}

// Function to calculate time ago
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .notifications-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .notification-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
            border-left: 4px solid #ddd;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .notification-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .notification-item.unread {
            border-left-color: #667eea;
            background: linear-gradient(135deg, #f8f9ff 0%, #ffffff 100%);
        }
        
        .notification-title {
            font-weight: bold;
            font-size: 1rem;
            margin-bottom: 8px;
            color: #333;
        }
        
        .notification-message {
            color: #666;
            margin-bottom: 8px;
            line-height: 1.5;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #999;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .notice-link {
            color: #667eea;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .notice-link:hover {
            text-decoration: underline;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
        }
        
        .empty-state .icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #999;
        }
        
        .mark-all-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
            font-size: 0.9rem;
            transition: transform 0.3s;
        }
        
        .mark-all-btn:hover {
            transform: translateY(-2px);
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .delete-all-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: transform 0.3s;
        }
        
        .delete-all-btn:hover {
            transform: translateY(-2px);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            background: #f0f2ff;
        }
        
        .notification-content-wrapper {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }
        
        @media (max-width: 768px) {
            .notifications-container {
                padding: 0 10px;
            }
            .header-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">🔔 Notifications</h2>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <div class="notifications-container">
                    <div class="header-actions">
                        <h3>All Notifications</h3>
                        <?php if(count($notifications) > 0): ?>
                            <div>
                                <button class="mark-all-btn" onclick="markAllAsRead()">✓ Mark all as read</button>
                                <button class="delete-all-btn" onclick="deleteAllNotifications()">🗑️ Delete all</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if(count($notifications) > 0): ?>
                        <?php foreach($notifications as $notif): ?>
                            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" 
                                 onclick="viewNotice(<?php echo $notif['notice_id']; ?>, <?php echo $notif['id']; ?>)">
                                <div class="notification-content-wrapper">
                                    <div class="notification-icon">📢</div>
                                    <div style="flex: 1;">
                                        <div class="notification-title">
                                            <?php echo htmlspecialchars($notif['title']); ?>
                                        </div>
                                        <div class="notification-message">
                                            <?php echo htmlspecialchars($notif['message']); ?>
                                        </div>
                                        <div class="notification-time">
                                            <span>🕐 <?php echo timeAgo($notif['created_at']); ?></span>
                                            <a href="notice_detail.php?id=<?php echo $notif['notice_id']; ?>" 
                                               class="notice-link" 
                                               onclick="event.stopPropagation();">View Notice →</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="icon">🔔</div>
                            <h3>No Notifications Yet</h3>
                            <p>When you receive new notifications, they will appear here.</p>
                            <p style="margin-top: 10px;">Check back later for updates!</p>
                            <a href="feed.php" style="display: inline-block; margin-top: 20px; color: #667eea;">← Back to Feed</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function viewNotice(noticeId, notificationId) {
            // Mark notification as read
            fetch('../ajax/mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                window.location.href = `notice_detail.php?id=${noticeId}`;
            })
            .catch(error => {
                window.location.href = `notice_detail.php?id=${noticeId}`;
            });
        }
        
        function markAllAsRead() {
            if(confirm('Mark all notifications as read?')) {
                fetch('../ajax/mark_all_notifications_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    location.reload();
                });
            }
        }
        
        function deleteAllNotifications() {
            if(confirm('Are you sure you want to delete ALL notifications? This action cannot be undone.')) {
                fetch('../ajax/delete_all_notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    location.reload();
                });
            }
        }
    </script>
</body>
</html>