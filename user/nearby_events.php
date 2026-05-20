<?php
require_once '../config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_faculty = $_SESSION['faculty_id'] ?? null;
$user_department = $_SESSION['department_id'] ?? null;
$user_year = $_SESSION['year'] ?? null;
$user_role = $_SESSION['user_role'] ?? 'student';
$nearby_events = [];
$user_location = null;
$table_exists = false;
$viewerProfile = [
    'role' => $user_role,
    'faculty_id' => $user_faculty,
    'department_id' => $user_department,
    'year' => $user_year,
    'admin_type' => $_SESSION['admin_type'] ?? null,
];

// Check if user_locations table exists
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'user_locations'");
    if($checkTable->rowCount() > 0) {
        $table_exists = true;
        
        // Get user's location
        $stmt = $pdo->prepare("SELECT latitude, longitude FROM user_locations WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_location = $stmt->fetch();
    }
} catch (PDOException $e) {
    $table_exists = false;
}

// Get nearby events if user has location
if($user_location && $table_exists) {
    try {
        [$audienceConditions, $audienceParams] = buildNoticeAudienceConditions($pdo, 'n', $viewerProfile);
        // Calculate nearby events using SQL
        $sql = "SELECT n.*, u.name as author_name,
                (6371 * acos(cos(radians(?)) * cos(radians(n.latitude)) 
                * cos(radians(n.longitude) - radians(?)) + sin(radians(?)) 
                * sin(radians(n.latitude)))) AS distance
                FROM notices n
                JOIN users u ON n.posted_by = u.id
                WHERE n.latitude IS NOT NULL 
                AND n.longitude IS NOT NULL
                AND n.status = 'published'
                AND (n.publish_at IS NULL OR n.publish_at <= NOW())
                AND (n.expire_at IS NULL OR n.expire_at > NOW())
                AND " . implode(' AND ', $audienceConditions) . "
                HAVING distance <= 10
                ORDER BY distance ASC
                LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([
            $user_location['latitude'],
            $user_location['longitude'],
            $user_location['latitude'],
        ], $audienceParams));
        $nearby_events = $stmt->fetchAll();
    } catch (PDOException $e) {
        $nearby_events = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nearby Events - Campus Notice</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .event-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .event-card:hover {
            transform: translateY(-2px);
        }
        
        .distance-badge {
            background: #27ae60;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .share-prompt {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
        }
        
        .btn-share {
            background: white;
            color: #667eea;
            padding: 10px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 15px;
            font-weight: bold;
        }
        
        .btn-share:hover {
            transform: translateY(-2px);
        }
        
        .event-title {
            font-size: 1.2rem;
            margin: 10px 0;
            color: #333;
        }
        
        .event-location {
            color: #666;
            margin: 5px 0;
        }
        
        .event-date {
            color: #667eea;
            font-size: 0.9rem;
        }
        
        .notice-meta {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .no-events {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
        }
        
        .no-events .icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">🗺️ Nearby Events</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if(!$table_exists): ?>
                    <div class="share-prompt">
                        <h3>📍 Location Features Coming Soon</h3>
                        <p>The location-based features are being set up. Please check back later!</p>
                        <a href="feed.php" class="btn-share">Back to Feed →</a>
                    </div>
                    
                <?php elseif(!$user_location): ?>
                    <div class="share-prompt">
                        <h3>📍 Share Your Location</h3>
                        <p>Share your location to see events happening near you on campus!</p>
                        <p>You'll get notified about events within your area.</p>
                        <a href="share_location.php" class="btn-share">Share My Location</a>
                    </div>
                    
                <?php elseif(count($nearby_events) > 0): ?>
                    <h2>📍 Events Near You</h2>
                    <p style="margin-bottom: 20px; color: #666;">Showing events within 10km of your location</p>
                    
                    <?php foreach($nearby_events as $event): ?>
                        <div class="event-card">
                            <span class="distance-badge">📍 <?php echo round($event['distance'], 1); ?> km away</span>
                            <h3 class="event-title">
                                <a href="notice_detail.php?id=<?php echo $event['id']; ?>" style="color: inherit; text-decoration: none;">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                </a>
                            </h3>
                            <div class="event-location">
                                📍 <?php echo htmlspecialchars($event['location_name'] ?: 'Location set'); ?>
                            </div>
                            <div class="event-date">
                                📅 <?php echo $event['event_date'] ? date('d M Y, h:i A', strtotime($event['event_date'])) : 'Date TBA'; ?>
                            </div>
                            <p class="event-content">
                                <?php echo nl2br(htmlspecialchars(substr($event['content'], 0, 200))); ?>
                                <?php if(strlen($event['content']) > 200) echo '...'; ?>
                            </p>
                            <div class="notice-meta">
                                <span>👤 Posted by: <?php echo htmlspecialchars($event['author_name']); ?></span>
                                <a href="notice_detail.php?id=<?php echo $event['id']; ?>" style="color: #667eea;">View Details →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                <?php else: ?>
                    <div class="no-events">
                        <div class="icon">📍</div>
                        <h3>No Nearby Events</h3>
                        <p>There are no events happening near your location right now.</p>
                        <p>Check back later for updates or explore all events on the map!</p>
                        <a href="event_map.php" class="btn-share" style="background: #667eea; color: white; margin-top: 20px;">View All Events Map</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
