<?php
require_once '../config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$table_exists = false;

// Check if user_locations table exists
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'user_locations'");
    $table_exists = $checkTable->rowCount() > 0;
} catch (PDOException $e) {
    $table_exists = false;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $table_exists) {
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $location_name = $_POST['location_name'] ?? '';
    
    if($latitude && $longitude) {
        try {
            // Save or update user location
            $stmt = $pdo->prepare("INSERT INTO user_locations (user_id, latitude, longitude, location_name) 
                                   VALUES (?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE 
                                   latitude = VALUES(latitude), 
                                   longitude = VALUES(longitude), 
                                   location_name = VALUES(location_name),
                                   updated_at = NOW()");
            $stmt->execute([$user_id, $latitude, $longitude, $location_name]);
            $message = "Location shared successfully! You will now receive notifications about nearby events.";
        } catch (PDOException $e) {
            $error = "Failed to save location: " . $e->getMessage();
        }
    } else {
        $error = "Please select a location on the map.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Location - Campus Notice</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .map-container {
            height: 400px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        
        .location-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .btn-share {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 15px;
        }
        
        .btn-locate {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 10px;
        }
        
        .info-text {
            color: #666;
            margin-top: 15px;
            font-size: 14px;
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
        
        .setup-message {
            background: #e8f0fe;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">📍 Share My Location</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if(!$table_exists): ?>
                    <div class="setup-message">
                        <h3>📍 Location Features Coming Soon</h3>
                        <p>The location-based features are being set up. Please check back later!</p>
                        <a href="feed.php" class="btn-share" style="display: inline-block; margin-top: 15px;">Back to Feed →</a>
                    </div>
                <?php else: ?>
                    <div class="location-card">
                        <h3>📍 Share Your Location</h3>
                        <p>Enable location-based notifications to get alerts about events happening near you on campus.</p>
                        
                        <?php if($message): ?>
                            <div class="success-message">✅ <?php echo htmlspecialchars($message); ?></div>
                        <?php endif; ?>
                        
                        <?php if($error): ?>
                            <div class="error-message">❌ <?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        
                        <div class="map-container" id="map"></div>
                        
                        <button class="btn-locate" onclick="getCurrentLocation()">📍 Use My Current Location</button>
                        <p class="info-text">Click on the map to select your location, or use the button above to auto-detect.</p>
                        
                        <button class="btn-share" onclick="shareLocation()">✅ Share My Location</button>
                        
                        <div class="info-text">
                            🔒 Your location is only used to notify you about nearby campus events.<br>
                            You can update your location at any time by coming back to this page.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php if($table_exists): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map;
        let marker;
        let currentLat = -1.286389;
        let currentLng = 36.817223;
        
        function initMap() {
            map = L.map('map').setView([currentLat, currentLng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            map.on('click', function(e) {
                setMarker(e.latlng.lat, e.latlng.lng);
            });
        }
        
        function setMarker(lat, lng) {
            currentLat = lat;
            currentLng = lng;
            
            if(marker) {
                map.removeLayer(marker);
            }
            
            marker = L.marker([lat, lng]).addTo(map)
                .bindPopup('Your Location')
                .openPopup();
        }
        
        function getCurrentLocation() {
            if(navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    currentLat = position.coords.latitude;
                    currentLng = position.coords.longitude;
                    map.setView([currentLat, currentLng], 16);
                    setMarker(currentLat, currentLng);
                }, function(error) {
                    alert("Unable to get your location. Please click on the map to select your location.");
                });
            } else {
                alert("Geolocation is not supported by this browser.");
            }
        }
        
        function shareLocation() {
            if(currentLat && currentLng) {
                // Get location name from reverse geocoding
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${currentLat}&lon=${currentLng}`)
                    .then(response => response.json())
                    .then(data => {
                        let locationName = data.display_name ? data.display_name.split(',')[0] : 'Shared Location';
                        
                        // Submit the location
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.innerHTML = `
                            <input type="hidden" name="latitude" value="${currentLat}">
                            <input type="hidden" name="longitude" value="${currentLng}">
                            <input type="hidden" name="location_name" value="${locationName}">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    })
                    .catch(() => {
                        // Fallback without location name
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.innerHTML = `
                            <input type="hidden" name="latitude" value="${currentLat}">
                            <input type="hidden" name="longitude" value="${currentLng}">
                            <input type="hidden" name="location_name" value="Shared Location">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    });
            } else {
                alert('Please select a location on the map first.');
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
    </script>
    <?php endif; ?>
</body>
</html>