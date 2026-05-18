<?php
require_once '../config/database.php';

// Check if user is admin or super admin
if(!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'super_admin')) {
    header("Location: ../login.php");
    exit();
}

$events = [];

// Check if location columns exist
try {
    $checkColumns = $pdo->query("SHOW COLUMNS FROM notices LIKE 'latitude'");
    $hasLocationColumns = $checkColumns->rowCount() > 0;
} catch (PDOException $e) {
    $hasLocationColumns = false;
}

// Get events with locations if columns exist
if($hasLocationColumns) {
    try {
        $stmt = $pdo->query("
            SELECT n.*, u.name as author_name 
            FROM notices n 
            JOIN users u ON n.posted_by = u.id 
            WHERE n.latitude IS NOT NULL 
            AND n.longitude IS NOT NULL
            ORDER BY n.created_at DESC
        ");
        $events = $stmt->fetchAll();
    } catch (PDOException $e) {
        $events = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Map View - Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .map-container {
            height: 60vh;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        
        .event-list {
            background: white;
            border-radius: 10px;
            padding: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .event-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .event-item:hover {
            background: #f8f9fa;
        }
        
        .event-title {
            font-weight: bold;
            color: #333;
        }
        
        .event-location {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .category-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 10px;
        }
        
        .no-location-message {
            background: #e8f0fe;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">📍 Event Map View</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if(!$hasLocationColumns): ?>
                    <div class="no-location-message">
                        <h3>📍 Location Features Coming Soon</h3>
                        <p>The database is being updated to support location-based events.</p>
                        <p>Please run the SQL to add location columns to the notices table.</p>
                        <a href="create_notice_with_location.php" class="btn-primary">Create Location Event →</a>
                    </div>
                    
                <?php elseif(count($events) == 0): ?>
                    <div class="no-location-message">
                        <h3>📍 No Location Events Yet</h3>
                        <p>Create an event with a location to see it on the map!</p>
                        <a href="create_notice_with_location.php" class="btn-primary">Create Location Event →</a>
                    </div>
                    
                <?php else: ?>
                    <div class="map-container" id="map"></div>
                    
                    <div class="event-list">
                        <h3>📍 Events on Map (<?php echo count($events); ?> events)</h3>
                        <?php foreach($events as $event): ?>
                            <div class="event-item" onclick="focusEvent(<?php echo $event['latitude']; ?>, <?php echo $event['longitude']; ?>, <?php echo $event['id']; ?>)">
                                <div class="event-title">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                    <span class="category-badge" style="background: #667eea; color: white;">
                                        <?php echo $event['category']; ?>
                                    </span>
                                </div>
                                <div class="event-location">
                                    📍 <?php echo htmlspecialchars($event['location_name'] ?: 'Location set'); ?>
                                </div>
                                <div class="event-location">
                                    📅 <?php echo $event['event_date'] ? date('d M Y, h:i A', strtotime($event['event_date'])) : 'Date TBA'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php if($hasLocationColumns && count($events) > 0): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map;
        let markers = [];
        
        const events = <?php echo json_encode($events); ?>;
        
        const categoryColors = {
            'Academic': '#3498db',
            'Event': '#e67e22',
            'Exam': '#e74c3c',
            'Placement': '#27ae60',
            'LostFound': '#f39c12',
            'General': '#95a5a6'
        };
        
        function initMap() {
            let centerLat = -1.286389;
            let centerLng = 36.817223;
            
            if(events.length > 0) {
                centerLat = parseFloat(events[0].latitude);
                centerLng = parseFloat(events[0].longitude);
            }
            
            map = L.map('map').setView([centerLat, centerLng], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            events.forEach(event => {
                const lat = parseFloat(event.latitude);
                const lng = parseFloat(event.longitude);
                const color = categoryColors[event.category] || '#667eea';
                
                const customIcon = L.divIcon({
                    html: `<div style="background-color: ${color}; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
                            <span style="color: white; font-size: 14px;">📍</span>
                          </div>`,
                    className: 'custom-marker',
                    iconSize: [30, 30],
                    popupAnchor: [0, -15]
                });
                
                const marker = L.marker([lat, lng], { icon: customIcon }).addTo(map);
                
                const popupContent = `
                    <div style="min-width: 200px;">
                        <h4 style="margin: 0 0 5px 0; color: #333;">${escapeHtml(event.title)}</h4>
                        <p style="margin: 5px 0; font-size: 12px; color: #666;">
                            📍 ${escapeHtml(event.location_name || 'Location set')}
                        </p>
                        <p style="margin: 5px 0; font-size: 12px;">
                            📅 ${event.event_date ? new Date(event.event_date).toLocaleString() : 'Date TBA'}
                        </p>
                        <p style="margin: 5px 0; font-size: 12px;">
                            👤 Posted by: ${escapeHtml(event.author_name)}
                        </p>
                        <a href="../user/notice_detail.php?id=${event.id}" style="color: #667eea; text-decoration: none;">View Details →</a>
                    </div>
                `;
                
                marker.bindPopup(popupContent);
                markers.push(marker);
            });
        }
        
        function focusEvent(lat, lng, eventId) {
            map.setView([lat, lng], 16);
            
            markers.forEach(marker => {
                const markerLatLng = marker.getLatLng();
                if(markerLatLng.lat === lat && markerLatLng.lng === lng) {
                    marker.openPopup();
                }
            });
        }
        
        function escapeHtml(text) {
            if(!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
    </script>
    <?php endif; ?>
</body>
</html>
