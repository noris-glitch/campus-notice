<?php
require_once '../config/database.php';

// Only super admin can access
if(!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'super_admin') {
    header("Location: ../login.php");
    exit();
}

$success = '';
$error = '';

// Get all faculties for targeting
$faculties = [];
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'faculties'");
    if($checkTable->rowCount() > 0) {
        $faculties = $pdo->query("SELECT * FROM faculties ORDER BY name")->fetchAll();
    }
} catch (PDOException $e) {
    $faculties = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $severity = $_POST['severity'];
    $target_faculty = !empty($_POST['target_faculty']) ? $_POST['target_faculty'] : null;
    $target_year = !empty($_POST['target_year']) ? $_POST['target_year'] : null;
    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    if(empty($title) || empty($message)) {
        $error = "Title and message are required!";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO emergency_alerts (title, message, severity, target_faculty, target_year, expires_at, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        if($stmt->execute([$title, $message, $severity, $target_faculty, $target_year, $expires_at, $_SESSION['user_id']])) {
            $alert_id = $pdo->lastInsertId();
            
            // Get target users
            $userQuery = "SELECT id FROM users WHERE role = 'student' AND is_active = 1";
            $params = [];
            
            if($target_faculty) {
                $userQuery .= " AND faculty_id = ?";
                $params[] = $target_faculty;
            }
            if($target_year) {
                $userQuery .= " AND year = ?";
                $params[] = $target_year;
            }
            
            $users = $pdo->prepare($userQuery);
            $users->execute($params);
            $target_users = $users->fetchAll();
            
            // Create receipts for target users
            $receiptStmt = $pdo->prepare("INSERT INTO emergency_alert_receipts (alert_id, user_id) VALUES (?, ?)");
            foreach($target_users as $user) {
                $receiptStmt->execute([$alert_id, $user['id']]);
            }
            
            $success = "Emergency alert sent to " . count($target_users) . " students!";
        } else {
            $error = "Failed to send emergency alert.";
        }
    }
}

// Get recent alerts
$alerts = $pdo->query("
    SELECT ea.*, u.name as author_name,
    (SELECT COUNT(*) FROM emergency_alert_receipts WHERE alert_id = ea.id) as total_recipients,
    (SELECT COUNT(*) FROM emergency_alert_receipts WHERE alert_id = ea.id AND is_read = 1) as read_count
    FROM emergency_alerts ea
    JOIN users u ON ea.created_by = u.id
    ORDER BY ea.created_at DESC
    LIMIT 20
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Alert - Super Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .alert-form-container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .emergency-badge {
            background: #dc3545;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-bottom: 15px;
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
        
        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            outline: none;
            border-color: #dc3545;
        }
        
        .severity-critical { background: #dc3545; color: white; }
        .severity-high { background: #fd7e14; color: white; }
        .severity-medium { background: #ffc107; color: #333; }
        .severity-low { background: #28a745; color: white; }
        
        .btn-emergency {
            background: #dc3545;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
        }
        
        .btn-emergency:hover {
            background: #c82333;
            transform: scale(1.02);
        }
        
        .alert-list {
            background: white;
            border-radius: 10px;
            padding: 20px;
        }
        
        .alert-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 10px;
        }
        
        .alert-title {
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .warning-sound-note {
            background: #fff3cd;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 12px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">🚨 Emergency Alert System</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <div class="alert-form-container">
                    <div class="emergency-badge">🚨 EMERGENCY ALERT</div>
                    
                    <?php if($success): ?>
                        <div style="background: #d4edda; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                            ✅ <?php echo $success; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if($error): ?>
                        <div style="background: #f8d7da; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                            ❌ <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label>Alert Title *</label>
                            <input type="text" name="title" placeholder="e.g., URGENT: Campus Lockdown" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Alert Message *</label>
                            <textarea name="message" rows="5" placeholder="Describe the emergency situation..." required></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Severity Level</label>
                                <select name="severity">
                                    <option value="critical">🔴 CRITICAL - Immediate Action Required</option>
                                    <option value="high">🟠 HIGH - Urgent Attention Needed</option>
                                    <option value="medium">🟡 MEDIUM - Important Notice</option>
                                    <option value="low">🟢 LOW - Informational</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Target Faculty (Optional)</label>
                                <select name="target_faculty">
                                    <option value="">All Faculties</option>
                                    <?php foreach($faculties as $faculty): ?>
                                        <option value="<?php echo $faculty['id']; ?>"><?php echo htmlspecialchars($faculty['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Target Year (Optional)</label>
                                <select name="target_year">
                                    <option value="">All Years</option>
                                    <option value="1">1st Year</option>
                                    <option value="2">2nd Year</option>
                                    <option value="3">3rd Year</option>
                                    <option value="4">4th Year</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Expires At</label>
                                <input type="datetime-local" name="expires_at" value="<?php echo date('Y-m-d\TH:i', strtotime('+24 hours')); ?>">
                            </div>
                        </div>
                        
                        <div class="warning-sound-note">
                            ⚠️ Students will receive this alert with a WARNING SOUND and a RED banner on their dashboard.
                        </div>
                        
                        <button type="submit" class="btn-emergency">🚨 SEND EMERGENCY ALERT</button>
                    </form>
                </div>
                
                <div class="alert-list">
                    <h3>Recent Emergency Alerts</h3>
                    <?php foreach($alerts as $alert): ?>
                        <div class="alert-item">
                            <div class="alert-title">
                                <span class="severity-<?php echo $alert['severity']; ?>" style="padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                    <?php echo strtoupper($alert['severity']); ?>
                                </span>
                                <?php echo htmlspecialchars($alert['title']); ?>
                            </div>
                            <div style="font-size: 13px; color: #666; margin-top: 5px;">
                                <?php echo htmlspecialchars(substr($alert['message'], 0, 100)); ?>...
                            </div>
                            <div style="font-size: 11px; color: #999; margin-top: 8px;">
                                Sent by: <?php echo htmlspecialchars($alert['author_name']); ?> | 
                                <?php echo date('d M Y, h:i A', strtotime($alert['created_at'])); ?> |
                                Read by: <?php echo $alert['read_count']; ?>/<?php echo $alert['total_recipients']; ?> students
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>