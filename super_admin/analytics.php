<?php
require_once '../config/database.php';

// Only super admin can access
if(!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'super_admin') {
    header("Location: ../login.php");
    exit();
}

// Get all statistics
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalStudents = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$totalAdmins = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$totalNotices = $pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn();
$totalViews = $pdo->query("SELECT COUNT(*) FROM notice_views")->fetchColumn();
$totalBookmarks = $pdo->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn();
$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM notices WHERE status = 'pending_review'")->fetchColumn();
$requiredAcknowledgements = $pdo->query("SELECT COUNT(*) FROM notice_acknowledgements")->fetchColumn();
$completedAcknowledgements = $pdo->query("SELECT COUNT(*) FROM notice_acknowledgements WHERE status = 'acknowledged'")->fetchColumn();
$openQuestions = $pdo->query("SELECT COUNT(*) FROM notice_questions WHERE status = 'open'")->fetchColumn();
$templateCount = $pdo->query("SELECT COUNT(*) FROM notice_templates")->fetchColumn();

// Get most viewed notices
$mostViewed = $pdo->query("
    SELECT n.title, n.id, COUNT(nv.id) as view_count 
    FROM notices n 
    LEFT JOIN notice_views nv ON n.id = nv.notice_id 
    GROUP BY n.id 
    ORDER BY view_count DESC 
    LIMIT 10
")->fetchAll();

// Get daily views for last 7 days
$dailyViews = $pdo->query("
    SELECT DATE(created_at) as date, COUNT(*) as count 
    FROM notice_views 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date DESC
")->fetchAll();

// Get user registration stats by month
$monthlyUsers = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
    FROM users 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Super Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            color: #666;
            margin-top: 10px;
            font-size: 0.9rem;
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
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .view-count {
            background: #667eea;
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">📊 Analytics Dashboard</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalUsers; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalStudents; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalAdmins; ?></div>
                        <div class="stat-label">Admins</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalNotices; ?></div>
                        <div class="stat-label">Total Notices</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalViews; ?></div>
                        <div class="stat-label">Total Views</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalBookmarks; ?></div>
                        <div class="stat-label">Bookmarks</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $pendingApprovals; ?></div>
                        <div class="stat-label">Pending Approvals</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $requiredAcknowledgements; ?></div>
                        <div class="stat-label">Acknowledgement Requests</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $completedAcknowledgements; ?></div>
                        <div class="stat-label">Acknowledged Notices</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $openQuestions; ?></div>
                        <div class="stat-label">Open Questions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $templateCount; ?></div>
                        <div class="stat-label">Saved Templates</div>
                    </div>
                </div>
                
                <!-- Most Viewed Notices -->
                <div class="section-card">
                    <h3 class="section-title">📈 Most Viewed Notices</h3>
                    <?php if(count($mostViewed) > 0): ?>
                        <table>
                            <thead>
                                <tr><th>Notice Title</th><th>Views</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($mostViewed as $notice): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($notice['title']); ?></td>
                                        <td><span class="view-count"><?php echo $notice['view_count']; ?> views</span></td>
                                        <td><a href="../user/notice_detail.php?id=<?php echo $notice['id']; ?>" target="_blank">View Notice</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No notices viewed yet.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Daily Views -->
                <div class="section-card">
                    <h3 class="section-title">📅 Daily Views (Last 7 Days)</h3>
                    <?php if(count($dailyViews) > 0): ?>
                        <table>
                            <thead>
                                <tr><th>Date</th><th>Views</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($dailyViews as $day): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($day['date'])); ?></td>
                                        <td><?php echo $day['count']; ?> views</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No view data available.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Monthly User Registrations -->
                <div class="section-card">
                    <h3 class="section-title">📊 User Registrations (Last 6 Months)</h3>
                    <?php if(count($monthlyUsers) > 0): ?>
                        <table>
                            <thead>
                                <tr><th>Month</th><th>New Users</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($monthlyUsers as $month): ?>
                                    <tr>
                                        <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                        <td><?php echo $month['count']; ?> users</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No registration data available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
