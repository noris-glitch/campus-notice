<?php
require_once '../config/database.php';

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$errors = array();

// Get user current data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$notificationPrefs = getUserNotificationPreferences($pdo, $user_id);
$selectedCategories = csvValueToArray($notificationPrefs['categories_csv'] ?? '');

// Get faculties for dropdown (if table exists)
$faculties = [];
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'faculties'");
    if($checkTable->rowCount() > 0) {
        $faculties = $pdo->query("SELECT * FROM faculties ORDER BY name")->fetchAll();
    }
} catch (PDOException $e) {
    $faculties = [];
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if(isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $faculty_id = $_POST['faculty_id'] ?? null;
        $year = $_POST['year'];
        $membership = $_POST['membership'] ?? null;
        
        // Validation
        if(empty($name)) {
            $errors[] = "Name is required!";
        }
        if(empty($email)) {
            $errors[] = "Email is required!";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format!";
        }
        
        // Check if email already exists for another user
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if($stmt->fetchColumn() > 0) {
            $errors[] = "Email already used by another account!";
        }
        
        if(empty($errors)) {
            // Update based on available columns
            if(columnExists($pdo, 'users', 'membership')) {
                if(in_array('faculty_id', array_keys($user))) {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, faculty_id = ?, year = ?, membership = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $faculty_id, $year, $membership, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, year = ?, membership = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $year, $membership, $user_id]);
                }
            } else {
                if(in_array('faculty_id', array_keys($user))) {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, faculty_id = ?, year = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $faculty_id, $year, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, year = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $year, $user_id]);
                }
            }
            
            // Update session
            $_SESSION['user_name'] = $name;
            if(isset($faculty_id)) $_SESSION['faculty_id'] = $faculty_id;
            $_SESSION['year'] = $year;
            
            $success = "Profile updated successfully!";
            
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        }
    }

    if(isset($_POST['save_notification_preferences'])) {
        $categories = $_POST['categories'] ?? [];
        saveUserNotificationPreferences($pdo, $user_id, [
            'in_app_enabled' => isset($_POST['in_app_enabled']),
            'email_enabled' => isset($_POST['email_enabled']),
            'emergency_override' => isset($_POST['emergency_override']),
            'quiet_hours_start' => $_POST['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $_POST['quiet_hours_end'] ?? null,
            'categories' => $categories,
        ]);

        $notificationPrefs = getUserNotificationPreferences($pdo, $user_id);
        $selectedCategories = csvValueToArray($notificationPrefs['categories_csv'] ?? '');
        $success = "Notification preferences updated successfully!";
    }
    
    // Handle password change
    if(isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        if(!password_verify($current_password, $user['password'])) {
            $errors[] = "Current password is incorrect!";
        }
        
        if(strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters!";
        }
        
        if($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match!";
        }
        
        if(empty($errors)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            $success = "Password changed successfully! Please login again.";
            
            // Optional: Logout user after password change
            // session_destroy();
            // header("Location: ../login.php?msg=password_changed");
            // exit();
        }
    }
    
    // Handle profile picture upload
    if(isset($_POST['upload_picture']) && isset($_FILES['profile_picture'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_picture']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(in_array($ext, $allowed)) {
            $new_filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $target_dir = "../assets/uploads/profiles/";
            
            // Create directory if not exists
            if(!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            if(move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_dir . $new_filename)) {
                // Delete old profile picture if exists
                if(!empty($user['profile_picture']) && file_exists($target_dir . $user['profile_picture'])) {
                    unlink($target_dir . $user['profile_picture']);
                }
                
                $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$new_filename, $user_id]);
                $success = "Profile picture updated successfully!";
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                $errors[] = "Failed to upload image!";
            }
        } else {
            $errors[] = "Invalid file type! Allowed: JPG, PNG, GIF";
        }
    }
}

// Get profile picture path
$profile_picture = '../assets/uploads/profiles/default-avatar.png';
if(!empty($user['profile_picture']) && file_exists("../assets/uploads/profiles/" . $user['profile_picture'])) {
    $profile_picture = '../assets/uploads/profiles/' . $user['profile_picture'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .profile-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .profile-header {
            background: white;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
            border: 4px solid #667eea;
        }
        
        .profile-name {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 5px;
        }
        
        .profile-role {
            color: #667eea;
            font-weight: bold;
        }
        
        .profile-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card-title {
            font-size: 1.2rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            padding-left: 45px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group input:disabled {
            background: #f5f5f5;
            color: #999;
        }
        
        /* Password toggle button */
        .password-toggle {
            position: absolute;
            left: 12px;
            bottom: 12px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: #999;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: transform 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .info-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .upload-btn {
            position: relative;
            overflow: hidden;
            display: inline-block;
            cursor: pointer;
        }
        
        .upload-btn input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .view-profile-btn {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">👤 My Profile</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                    <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <div class="profile-container">
                    
                    <?php if($success): ?>
                        <div class="success-message">
                            ✅ <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(!empty($errors)): ?>
                        <div class="error-message">
                            <strong>❌ Please fix the following:</strong>
                            <ul style="margin: 10px 0 0 20px;">
                                <?php foreach($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Profile Header with Picture -->
                    <div class="profile-header">
                        <img src="<?php echo $profile_picture; ?>" alt="Profile Picture" class="profile-avatar" id="profileAvatar"
                             onerror="this.src='../assets/uploads/profiles/default-avatar.png'">
                        <h3 class="profile-name"><?php echo htmlspecialchars($user['name']); ?></h3>
                        <p class="profile-role">
                            <?php 
                            if($user['role'] == 'student') echo "🎓 Student";
                            elseif($user['role'] == 'admin') echo "👔 Administrator";
                            elseif($user['role'] == 'super_admin') echo "👑 Super Administrator";
                            else echo "📝 User";
                            ?>
                        </p>
                        
                        <!-- Upload Picture Form -->
                        <form method="POST" enctype="multipart/form-data" style="margin-top: 15px;">
                            <div class="upload-btn btn-secondary" style="display: inline-block;">
                                📷 Change Photo
                                <input type="file" name="profile_picture" accept="image/*" onchange="this.form.submit()">
                            </div>
                            <input type="hidden" name="upload_picture" value="1">
                        </form>
                    </div>
                    
                    <!-- Edit Profile Form -->
                    <div class="profile-card">
                        <h3 class="card-title">📝 Edit Profile Information</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Full Name</label>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                </div>
                                <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                        </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Student / Staff ID</label>
                                    <input type="text" value="<?php echo htmlspecialchars($user['student_id']); ?>" disabled>
                                    <div class="info-text">ID cannot be changed</div>
                                </div>
                                <div class="form-group">
                                    <label>Year of Study</label>
                                    <select name="year" required>
                                        <option value="1" <?php echo ($user['year'] == 1) ? 'selected' : ''; ?>>1st Year</option>
                                        <option value="2" <?php echo ($user['year'] == 2) ? 'selected' : ''; ?>>2nd Year</option>
                                        <option value="3" <?php echo ($user['year'] == 3) ? 'selected' : ''; ?>>3rd Year</option>
                                        <option value="4" <?php echo ($user['year'] == 4) ? 'selected' : ''; ?>>4th Year</option>
                                    </select>
                                </div>
                            </div>
                            
                            <?php if(count($faculties) > 0): ?>
                            <div class="form-group">
                                <label>Faculty</label>
                                <select name="faculty_id">
                                    <option value="">Select Faculty</option>
                                    <?php foreach($faculties as $faculty): ?>
                                        <option value="<?php echo $faculty['id']; ?>" <?php echo ($user['faculty_id'] == $faculty['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($faculty['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <?php if(columnExists($pdo, 'users', 'membership')): ?>
                            <div class="form-group">
                                <label>Membership</label>
                                <select name="membership">
                                    <option value="">No Membership</option>
                                    <option value="Sports Club" <?php echo ($user['membership'] == 'Sports Club') ? 'selected' : ''; ?>>Sports Club</option>
                                    <option value="Drama Club" <?php echo ($user['membership'] == 'Drama Club') ? 'selected' : ''; ?>>Drama Club</option>
                                    <option value="Science Club" <?php echo ($user['membership'] == 'Science Club') ? 'selected' : ''; ?>>Science Club</option>
                                    <option value="Debate Club" <?php echo ($user['membership'] == 'Debate Club') ? 'selected' : ''; ?>>Debate Club</option>
                                    <option value="Art Club" <?php echo ($user['membership'] == 'Art Club') ? 'selected' : ''; ?>>Art Club</option>
                                    <option value="Tech Club" <?php echo ($user['membership'] == 'Tech Club') ? 'selected' : ''; ?>>Tech Club</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label>Member Since</label>
                                <input type="text" value="<?php echo date('d M Y', strtotime($user['created_at'])); ?>" disabled>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn-primary">💾 Save Changes</button>
                        </form>
                    </div>

                    <div class="profile-card">
                        <h3 class="card-title">🔔 Notification Preferences</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label style="display:flex; gap:10px; align-items:center;">
                                        <input type="checkbox" name="in_app_enabled" <?php echo !empty($notificationPrefs['in_app_enabled']) ? 'checked' : ''; ?> style="width:auto; padding:0;">
                                        In-app notifications
                                    </label>
                                    <div class="info-text">Controls whether normal notices create alerts inside the app.</div>
                                </div>
                                <div class="form-group">
                                    <label style="display:flex; gap:10px; align-items:center;">
                                        <input type="checkbox" name="email_enabled" <?php echo !empty($notificationPrefs['email_enabled']) ? 'checked' : ''; ?> style="width:auto; padding:0;">
                                        Email notifications
                                    </label>
                                    <div class="info-text">Email is sent when administrators select email delivery and the server mail setup is available.</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Quiet Hours Start</label>
                                    <input type="time" name="quiet_hours_start" value="<?php echo htmlspecialchars((string) ($notificationPrefs['quiet_hours_start'] ?? '')); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Quiet Hours End</label>
                                    <input type="time" name="quiet_hours_end" value="<?php echo htmlspecialchars((string) ($notificationPrefs['quiet_hours_end'] ?? '')); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label style="display:flex; gap:10px; align-items:center;">
                                    <input type="checkbox" name="emergency_override" <?php echo !empty($notificationPrefs['emergency_override']) ? 'checked' : ''; ?> style="width:auto; padding:0;">
                                    Allow urgent notices to bypass quiet hours and category filters
                                </label>
                            </div>

                            <div class="form-group">
                                <label>Subscribed Categories</label>
                                <div class="form-row">
                                    <?php foreach(getAvailableNoticeCategories() as $category): ?>
                                        <label style="display:flex; gap:10px; align-items:center; background:#f8f9fa; padding:12px; border-radius:6px;">
                                            <input type="checkbox" name="categories[]" value="<?php echo htmlspecialchars($category); ?>" <?php echo in_array($category, $selectedCategories, true) ? 'checked' : ''; ?> style="width:auto; padding:0;">
                                            <?php echo htmlspecialchars($category); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="info-text">Leave all selected to keep receiving every notice category.</div>
                            </div>

                            <button type="submit" name="save_notification_preferences" class="btn-primary">Save Notification Preferences</button>
                        </form>
                    </div>
                    
                    <!-- Change Password Form -->
                    <div class="profile-card">
                        <h3 class="card-title">🔒 Change Password</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" id="current_password" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('current_password', this)">
                                    👁️
                                </button>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" id="new_password" required>
                                    <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)">
                                        👁️
                                    </button>
                                    <div class="info-text">Minimum 6 characters</div>
                                </div>
                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" id="confirm_password" required>
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                                        👁️
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="change_password" class="btn-primary">🔐 Update Password</button>
                        </form>
                    </div>
                    
                    <!-- Account Statistics -->
                    <div class="profile-card">
                        <h3 class="card-title">📊 Account Statistics</h3>
                        <div class="stats-grid">
                            <div>
                                <div class="stat-number"><?php echo $user['id']; ?></div>
                                <div class="stat-label">User ID</div>
                            </div>
                            <div>
                                <?php
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = ?");
                                $stmt->execute([$user_id]);
                                $bookmarkCount = $stmt->fetchColumn();
                                ?>
                                <div class="stat-number"><?php echo $bookmarkCount; ?></div>
                                <div class="stat-label">Bookmarks</div>
                            </div>
                            <div>
                                <?php
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM notice_views WHERE user_id = ?");
                                $stmt->execute([$user_id]);
                                $viewCount = $stmt->fetchColumn();
                                ?>
                                <div class="stat-number"><?php echo $viewCount; ?></div>
                                <div class="stat-label">Notices Viewed</div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword(inputId, button) {
            const passwordInput = document.getElementById(inputId);
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'text') {
                button.innerHTML = '🙈';
                button.title = 'Hide password';
            } else {
                button.innerHTML = '👁️';
                button.title = 'Show password';
            }
        }
    </script>
</body>
</html>
