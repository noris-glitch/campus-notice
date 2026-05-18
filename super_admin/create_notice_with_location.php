<?php
require_once '../config/database.php';

// Check if user is admin or super admin
if(!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'super_admin')) {
    header("Location: ../login.php");
    exit();
}

$admin_type = $_SESSION['admin_type'] ?? null;
$user_id = $_SESSION['user_id'];

$categories = ['Academic', 'Event', 'Exam', 'Placement', 'LostFound', 'General'];
$years = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $category = $_POST['category'];
    $year_target = $_POST['year_target'] ?: null;
    $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
    $expire_date = !empty($_POST['expire_date']) ? $_POST['expire_date'] : null;
    
    // Location fields
    $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
    $location_name = !empty($_POST['location_name']) ? $_POST['location_name'] : null;
    $location_address = !empty($_POST['location_address']) ? $_POST['location_address'] : null;
    $radius_km = !empty($_POST['radius_km']) ? $_POST['radius_km'] : 1;
    $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
    $event_end_date = !empty($_POST['event_end_date']) ? $_POST['event_end_date'] : null;
    
    // Validation
    if(empty($title)) $errors[] = "Notice title is required!";
    if(empty($content)) $errors[] = "Notice content is required!";
    
    // Handle file upload
    $attachment = null;
    if(isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        $filename = $_FILES['attachment']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(in_array($ext, $allowed)) {
            $attachment = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
            $target_dir = "../assets/uploads/";
            if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            move_uploaded_file($_FILES['attachment']['tmp_name'], $target_dir . $attachment);
        } else {
            $errors[] = "Invalid file type!";
        }
    }
    
    if(empty($errors)) {
        $publishAt = date('Y-m-d H:i:s');
        $status = $_SESSION['user_role'] === 'super_admin' ? 'published' : 'pending_review';
        $approvalStatus = $_SESSION['user_role'] === 'super_admin' ? 'approved' : 'pending';
        $reviewedBy = $_SESSION['user_role'] === 'super_admin' ? $user_id : null;
        $reviewedAt = $_SESSION['user_role'] === 'super_admin' ? date('Y-m-d H:i:s') : null;

        $sql = "INSERT INTO notices (
                title, content, category, attachment, posted_by, year_target,
                is_pinned, expire_at, latitude, longitude, location_name, location_address,
                radius_km, event_date, event_end_date, status, approval_status, reviewed_by, reviewed_at, delivery_channels, publish_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_app', ?)";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$title, $content, $category, $attachment, $user_id, $year_target, 
                                   $is_pinned, $expire_date, $latitude, $longitude, $location_name, $location_address, 
                                   $radius_km, $event_date, $event_end_date, $status, $approvalStatus, $reviewedBy, $reviewedAt, $publishAt]);
        
        if($result) {
            $notice_id = $pdo->lastInsertId();
            
            if($status === 'published') {
                deliverNoticeToAudience($pdo, (int) $notice_id);

                // Notify nearby users if location is set
                if($latitude && $longitude) {
                    notifyNearbyUsers($pdo, $notice_id, $latitude, $longitude, $radius_km, $title);
                }

                $success = "Location event created successfully!";
            } else {
                $success = "Location event submitted for approval successfully.";
            }
            
            $_POST = [];
        } else {
            $errors[] = "Failed to create notice.";
        }
    }
}

function notifyNearbyUsers($pdo, $notice_id, $lat, $lng, $radius_km, $title) {
    // Check if user_locations table exists
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'user_locations'");
        if($checkTable->rowCount() == 0) {
            return 0;
        }
        
        // Get users with saved locations within radius
        $sql = "SELECT ul.user_id, ul.latitude, ul.longitude,
                (6371 * acos(cos(radians(?)) * cos(radians(ul.latitude)) 
                * cos(radians(ul.longitude) - radians(?)) + sin(radians(?)) 
                * sin(radians(ul.latitude)))) AS distance
                FROM user_locations ul
                HAVING distance <= ?
                ORDER BY distance";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$lat, $lng, $lat, $radius_km]);
        $nearby_users = $stmt->fetchAll();
        
        // Check if nearby_notifications table exists
        $checkNotifTable = $pdo->query("SHOW TABLES LIKE 'nearby_notifications'");
        $hasNearbyTable = $checkNotifTable->rowCount() > 0;
        
        // Check if notifications table exists
        $checkNotif = $pdo->query("SHOW TABLES LIKE 'notifications'");
        $hasNotifications = $checkNotif->rowCount() > 0;
        
        if($hasNearbyTable) {
            $notif_stmt = $pdo->prepare("INSERT INTO nearby_notifications (user_id, notice_id, distance_km) VALUES (?, ?, ?)");
        }
        
        if($hasNotifications) {
            $notif_stmt2 = $pdo->prepare("INSERT INTO notifications (user_id, notice_id, title, message) VALUES (?, ?, ?, ?)");
        }
        
        foreach($nearby_users as $user) {
            if($hasNearbyTable) {
                $notif_stmt->execute([$user['user_id'], $notice_id, $user['distance']]);
            }
            if($hasNotifications) {
                $message = "📍 Event near you! \"" . $title . "\" is happening " . round($user['distance'], 1) . "km from your location.";
                $notif_stmt2->execute([$user['user_id'], $notice_id, 'Nearby Event Alert', $message]);
            }
        }
        
        return count($nearby_users);
    } catch (PDOException $e) {
        return 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Location Event - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <!-- Leaflet CSS for Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .form-container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input, 
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .map-container {
            height: 400px;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 15px;
            border: 1px solid #ddd;
        }
        
        .location-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .btn-locate {
            background: #667eea;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .location-badge {
            background: #e8f0fe;
            color: #1967d2;
            padding: 8px 12px;
            border-radius: 5px;
            margin-top: 10px;
            display: inline-block;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input {
            width: auto;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">📍 Create Location Event</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <div class="form-container">
                    <?php if($success): ?>
                        <div class="success-message">✅ <?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <?php if(!empty($errors)): ?>
                        <div class="error-message">
                            <strong>❌ Errors:</strong>
                            <ul><?php foreach($errors as $error) echo "<li>$error</li>"; ?></ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" id="noticeForm">
                        <!-- Basic Notice Fields -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Event Title *</label>
                                <input type="text" name="title" required placeholder="e.g., Tech Conference 2024">
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category">
                                    <?php foreach($categories as $cat): ?>
                                        <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Event Description *</label>
                            <textarea name="content" rows="6" required placeholder="Describe the event details..."></textarea>
                        </div>
                        
                        <!-- Location Section -->
                        <div class="form-group">
                            <label>📍 Event Location (Click on Map to Select)</label>
                            <div class="map-container" id="map"></div>
                            
                            <div class="location-info">
                                <button type="button" class="btn-locate" onclick="getCurrentLocation()">📍 Use My Current Location</button>
                                <button type="button" class="btn-locate" onclick="searchLocation()">🔍 Search Location</button>
                                <input type="text" id="searchInput" placeholder="Search for a place..." style="width: 300px; padding: 8px; margin-top: 10px;">
                            </div>
                            
                            <div id="locationData">
                                <input type="hidden" name="latitude" id="latitude">
                                <input type="hidden" name="longitude" id="longitude">
                                <input type="hidden" name="location_name" id="location_name">
                                <input type="hidden" name="location_address" id="location_address">
                            </div>
                            
                            <div id="selectedLocationDisplay" class="location-badge" style="display: none;"></div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Notification Radius (km)</label>
                                <input type="number" name="radius_km" value="1" step="0.5" min="0.5">
                                <small>Students within this radius will be notified</small>
                            </div>
                            <div class="form-group">
                                <label>Event Start Date & Time</label>
                                <input type="datetime-local" name="event_date">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Event End Date & Time</label>
                                <input type="datetime-local" name="event_end_date">
                            </div>
                            <div class="form-group">
                                <label>Target Year</label>
                                <select name="year_target">
                                    <option value="">All Years</option>
                                    <?php foreach($years as $key => $year): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $year; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Attachment (Optional)</label>
                                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                            </div>
                            <div class="form-group">
                                <label>Expiry Date (Optional)</label>
                                <input type="date" name="expire_date">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_pinned" id="is_pinned">
                                <label for="is_pinned">📌 Pin this event (appears at top of feed)</label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-primary">📍 Publish Location Event</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Leaflet JavaScript -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map;
        let marker;
        
        // Initialize map (default to Nairobi coordinates)
        function initMap(lat = -1.286389, lng = 36.817223) {
            map = L.map('map').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            // Add click handler
            map.on('click', function(e) {
                setLocation(e.latlng.lat, e.latlng.lng);
            });
        }
        
        // Set location on map
        function setLocation(lat, lng) {
            if(marker) {
                map.removeLayer(marker);
            }
            
            marker = L.marker([lat, lng]).addTo(map)
                .bindPopup('📍 Event Location')
                .openPopup();
            
            map.setView([lat, lng], 15);
            
            // Update form fields
            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;
            
            // Reverse geocoding to get address
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                .then(response => response.json())
                .then(data => {
                    if(data.display_name) {
                        document.getElementById('location_address').value = data.display_name;
                        document.getElementById('location_name').value = data.name || data.display_name.split(',')[0];
                        displayLocation(data.display_name);
                    }
                });
        }
        
        // Get user's current location
        function getCurrentLocation() {
            if(navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    setLocation(position.coords.latitude, position.coords.longitude);
                }, function(error) {
                    alert("Unable to get your location. Please click on the map to select location.");
                });
            } else {
                alert("Geolocation is not supported by this browser.");
            }
        }
        
        // Search for location
        function searchLocation() {
            let query = document.getElementById('searchInput').value;
            if(!query) {
                alert("Please enter a location to search");
                return;
            }
            
            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if(data && data.length > 0) {
                        setLocation(parseFloat(data[0].lat), parseFloat(data[0].lon));
                        document.getElementById('location_name').value = data[0].display_name.split(',')[0];
                        document.getElementById('location_address').value = data[0].display_name;
                        displayLocation(data[0].display_name);
                    } else {
                        alert("Location not found. Please try a different search term.");
                    }
                });
        }
        
        // Display selected location
        function displayLocation(address) {
            const displayDiv = document.getElementById('selectedLocationDisplay');
            displayDiv.innerHTML = `📍 Selected: ${address.substring(0, 100)}...`;
            displayDiv.style.display = 'inline-block';
        }
        
        // Initialize map on load
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
    </script>
</body>
</html>
