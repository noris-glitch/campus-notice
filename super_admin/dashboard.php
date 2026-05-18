<?php
require_once '../config/database.php';

// Check if user is admin or super admin
if(!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'super_admin' && $_SESSION['user_role'] != 'admin')) {
    header("Location: ../login.php");
    exit();
}

$admin_type = $_SESSION['admin_type'] ?? null;
$faculty_id = $_SESSION['faculty_id'] ?? null;
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

// Get faculty name
$faculty_name = '';
if($faculty_id) {
    $stmt = $pdo->prepare("SELECT name FROM faculties WHERE id = ?");
    $stmt->execute([$faculty_id]);
    $faculty = $stmt->fetch();
    $faculty_name = $faculty ? $faculty['name'] : 'Not Assigned';
}

// Get statistics (Only for Admin Dashboard)
$totalNotices = 0;
$totalStudents = 0;
$totalViews = 0;
$totalBookmarks = 0;
$pendingApprovals = 0;
$openQuestions = 0;
$recentNotices = [];
$recentStudents = [];

try {
    if($user_role == 'super_admin') {
        $totalNotices = $pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn();
        $totalStudents = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
        $totalViews = $pdo->query("SELECT COUNT(*) FROM notice_views")->fetchColumn();
        $totalBookmarks = $pdo->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn();
        $pendingApprovals = $pdo->query("SELECT COUNT(*) FROM notices WHERE status = 'pending_review'")->fetchColumn();
        $openQuestions = $pdo->query("SELECT COUNT(*) FROM notice_questions WHERE status = 'open'")->fetchColumn();

        $recentNotices = $pdo->query("
            SELECT * FROM notices
            ORDER BY created_at DESC
            LIMIT 5
        ")->fetchAll();

        $recentStudents = $pdo->query("
            SELECT * FROM users
            WHERE role = 'student'
            ORDER BY created_at DESC
            LIMIT 5
        ")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notices WHERE posted_by = ?");
        $stmt->execute([$user_id]);
        $totalNotices = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND faculty_id = ?");
        $stmt->execute([$faculty_id]);
        $totalStudents = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notice_views nv
            JOIN notices n ON nv.notice_id = n.id
            WHERE n.posted_by = ?
        ");
        $stmt->execute([$user_id]);
        $totalViews = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookmarks b
            JOIN notices n ON b.notice_id = n.id
            WHERE n.posted_by = ?
        ");
        $stmt->execute([$user_id]);
        $totalBookmarks = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notices
            WHERE posted_by = ? AND status = 'pending_review'
        ");
        $stmt->execute([$user_id]);
        $pendingApprovals = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notice_questions nq
            JOIN notices n ON nq.notice_id = n.id
            WHERE n.posted_by = ? AND nq.status = 'open'
        ");
        $stmt->execute([$user_id]);
        $openQuestions = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT * FROM notices
            WHERE posted_by = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $recentNotices = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT * FROM users
            WHERE role = 'student' AND faculty_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$faculty_id]);
        $recentStudents = $stmt->fetchAll();
    }
    
} catch (PDOException $e) {
    // Handle error silently
}

// Get admin type display name
function getAdminTypeDisplay($type) {
    switch($type) {
        case 'dean_of_students':
            return 'Dean of Students';
        case 'hod':
            return 'Head of Faculty';
        case 'student_leader':
            return 'Student Leader';
        case 'club_leader':
            return 'Club Leader';
        case 'faculty':
            return 'Faculty Member';
        default:
            return 'Administrator';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .stat-card h3 {
            font-size: 2rem;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .stat-card p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .welcome-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .welcome-card h2 {
            margin-bottom: 10px;
        }
        
        .welcome-card p {
            opacity: 0.9;
        }
        
        .admin-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 10px;
        }
        
        .section-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
        }
        
        .notice-item, .student-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
            transition: background 0.3s;
        }
        
        .notice-item:hover, .student-item:hover {
            background: #f8f9fa;
        }
        
        .notice-title {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .notice-date {
            font-size: 0.75rem;
            color: #999;
        }
        
        .student-name {
            font-weight: bold;
            color: #333;
        }
        
        .student-email {
            font-size: 0.8rem;
            color: #666;
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
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .row {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
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
                <h2 class="page-title"><?php echo $user_role == 'super_admin' ? 'System Dashboard' : 'Admin Dashboard'; ?></h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                    <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <!-- Welcome Card -->
                <div class="welcome-card">
                    <h2>Welcome, <?php echo htmlspecialchars($user_name); ?>!</h2>
                    <p>
                        You are logged in as
                        <?php echo $user_role == 'super_admin' ? 'Super Administrator' : getAdminTypeDisplay($admin_type); ?>
                    </p>
                    <?php if($faculty_name): ?>
                        <div class="admin-badge">
                            🏛️ Faculty: <?php echo htmlspecialchars(substr($faculty_name, 0, 50)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Statistics Cards (Only visible to Admin) -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">📄</div>
                        <h3><?php echo $totalNotices; ?></h3>
                        <p>Your Notices</p>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <h3><?php echo $totalStudents; ?></h3>
                        <p>Students in Your Faculty</p>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👁️</div>
                        <h3><?php echo $totalViews; ?></h3>
                        <p>Total Views</p>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⭐</div>
                        <h3><?php echo $totalBookmarks; ?></h3>
                        <p>Bookmarks</p>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⏳</div>
                        <h3><?php echo $pendingApprovals; ?></h3>
                        <p>Pending Reviews</p>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">❓</div>
                        <h3><?php echo $openQuestions; ?></h3>
                        <p>Open Questions</p>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="create_notice.php" class="btn-primary">➕ Create New Notice</a>
                    <a href="create_notice_with_location.php" class="btn-primary">📍 Create Location Event</a>
                    <a href="manage_notices.php" class="btn-secondary">📋 Manage Notices</a>
                    <a href="student_sync.php" class="btn-secondary">🔄 Student Sync</a>
                    <?php if($user_role == 'super_admin'): ?>
                        <a href="analytics.php" class="btn-secondary">📈 Full Analytics</a>
                    <?php elseif($admin_type == 'dean_of_students' || $admin_type == 'hod' || $admin_type == 'faculty'): ?>
                        <a href="student_sync.php" class="btn-secondary">👥 Update Students</a>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Activity Row -->
                <div class="row" style="margin-top: 20px;">
                    <!-- Recent Notices -->
                    <div class="section-card">
                        <h3 class="section-title"><?php echo $user_role == 'super_admin' ? 'Recent Notices' : 'Your Recent Notices'; ?></h3>
                        <?php if(count($recentNotices) > 0): ?>
                            <?php foreach($recentNotices as $notice): ?>
                                <div class="notice-item">
                                    <div class="notice-title"><?php echo htmlspecialchars($notice['title']); ?></div>
                                    <div class="notice-date">
                                        📅 <?php echo date('d M Y, h:i A', strtotime($notice['created_at'])); ?>
                                        <span style="margin-left: 10px;">🏷️ <?php echo $notice['category']; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if(count($recentNotices) >= 5): ?>
                                <div style="text-align: center; margin-top: 10px;">
                                    <a href="manage_notices.php" style="color: #667eea;">View All →</a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No notices yet. <a href="create_notice.php">Create your first notice</a></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Students -->
                    <div class="section-card">
                        <h3 class="section-title">👥 Recent Students</h3>
                        <?php if(count($recentStudents) > 0): ?>
                            <?php foreach($recentStudents as $student): ?>
                                <div class="student-item">
                                    <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                                    <div class="student-email">
                                        📧 <?php echo htmlspecialchars($student['email']); ?>
                                        <span style="margin-left: 10px;">🎓 Year <?php echo $student['year']; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No students registered in your faculty yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Tips Card -->
                <div class="section-card">
                    <h3 class="section-title">💡 Quick Tips</h3>
                    <div style="display: grid; gap: 10px;">
                        <p>✅ <strong>Pin important notices</strong> - They will appear at the top of students' feeds</p>
                        <p>✅ <strong>Target specific audiences</strong> - Send notices to specific faculties or year levels</p>
                        <p>✅ <strong>Use categories</strong> - Helps students filter and find relevant notices</p>
                        <p>✅ <strong>Set expiry dates</strong> - Old notices will automatically be hidden</p>
                        <p>📍 <strong>Location events</strong> - Use "Create Location Event" for GIS-based notifications</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
