<?php
require_once '../config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT b.*, n.title, n.content, n.created_at as notice_date 
    FROM bookmarks b 
    JOIN notices n ON b.notice_id = n.id 
    WHERE b.user_id = ? 
    ORDER BY b.created_at DESC
");
$stmt->execute([$user_id]);
$bookmarks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Bookmarks</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">🔖 My Bookmarks</h2>
                <div class="user-info">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
                    <span><?= $_SESSION['user_name'] ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if(count($bookmarks) > 0): ?>
                    <?php foreach($bookmarks as $bookmark): ?>
                        <div class="notice-card">
                            <h3><?= htmlspecialchars($bookmark['title']) ?></h3>
                            <p><?= substr(htmlspecialchars($bookmark['content']), 0, 200) ?>...</p>
                            <div class="notice-meta">
                                <span>📅 Saved: <?= date('d M Y', strtotime($bookmark['saved_at'])) ?></span>
                                <a href="notice_detail.php?id=<?= $bookmark['notice_id'] ?>">Read More →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notice-card">
                        <p>No bookmarks yet. Save notices to see them here!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>