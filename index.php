<?php
require_once 'config/database.php';

// Check if user is already logged in
if(isset($_SESSION['user_id'])) {
    if($_SESSION['user_role'] == 'super_admin') {
        header("Location: super_admin/dashboard.php");
    } elseif($_SESSION['user_role'] == 'admin') {
        header("Location: super_admin/dashboard.php");
    } else {
        header("Location: user/feed.php");
    }
    exit();
}

// Get recent public notices
$recent_notices = [];
try {
    $sql = "SELECT n.*, u.name as author_name 
            FROM notices n 
            JOIN users u ON n.posted_by = u.id
            WHERE n.status = 'published' 
            AND n.publish_at <= NOW()
            AND (n.expire_at IS NULL OR n.expire_at >= CURDATE())
            ORDER BY n.is_pinned DESC, n.publish_at DESC 
            LIMIT 6";
    $recent_notices = $pdo->query($sql)->fetchAll();
} catch (PDOException $e) {
    $recent_notices = [];
}

// Get statistics
$total_notices = 0;
$total_faculties = 0;
try {
    $total_notices = $pdo->query("SELECT COUNT(*) FROM notices WHERE status = 'published'")->fetchColumn();
    $total_faculties = $pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn();
} catch (PDOException $e) {
    $total_notices = 0;
    $total_faculties = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JOOUST Campus Notice System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fb;
        }

        /* Navigation Bar */
        .navbar {
            background: linear-gradient(135deg, #1a2a6c, #b21f1f, #fdbb4d);
            background-size: 200% 200%;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.3rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }

        .logo span {
            color: #fdbb4d;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            margin-left: 2rem;
            transition: color 0.3s;
        }

        .nav-links a:hover {
            color: #fdbb4d;
        }

        .btn-login {
            background: #fdbb4d;
            color: #1a2a6c !important;
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            font-weight: bold;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Hero Section */
        .hero {
            margin-top: 70px;
            position: relative;
            background: linear-gradient(135deg, rgba(26,42,108,0.9), rgba(178,31,31,0.8));
            background-size: cover;
            background-position: center;
            padding: 4rem 2rem;
            text-align: center;
            color: white;
        }

        .hero h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            animation: fadeInUp 0.8s ease;
        }

        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.95;
        }

        .btn-register {
            background: #fdbb4d;
            color: #1a2a6c;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: bold;
            display: inline-block;
            transition: transform 0.3s;
        }

        .btn-register:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }

        /* Stats Section */
        .stats {
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin-top: 3rem;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            background: rgba(255,255,255,0.2);
            padding: 1.5rem;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            min-width: 150px;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
        }

        .stat-label {
            margin-top: 0.5rem;
            opacity: 0.9;
        }

        /* Notices Section */
        .notices-section {
            background: #f8f9fa;
            padding: 4rem 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            text-align: center;
            font-size: 2rem;
            color: #333;
            margin-bottom: 3rem;
        }

        .notices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .notice-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .notice-card:hover {
            transform: translateY(-5px);
        }

        .notice-category {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }

        .category-Academic { background: #3498db; color: white; }
        .category-Event { background: #e67e22; color: white; }
        .category-Exam { background: #e74c3c; color: white; }
        .category-Placement { background: #27ae60; color: white; }
        .category-General { background: #95a5a6; color: white; }

        .notice-title {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .notice-excerpt {
            color: #666;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .notice-meta {
            font-size: 0.85rem;
            color: #999;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .btn-read {
            color: #667eea;
            text-decoration: none;
            font-weight: bold;
        }

        /* Features Section */
        .features {
            background: white;
            padding: 4rem 2rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .feature-card {
            text-align: center;
            padding: 2rem;
        }

        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .feature-title {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .feature-desc {
            color: #666;
        }

        /* Footer */
        .footer {
            background: #1a2a6c;
            color: white;
            text-align: center;
            padding: 2rem;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 1.8rem;
            }
            .nav-container {
                flex-direction: column;
                gap: 1rem;
            }
            .nav-links a {
                margin: 0 1rem;
            }
            .stats {
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="logo">
                JOOUST<span>Notice</span>
            </a>
            <div class="nav-links">
                <a href="#home">Home</a>
                <a href="#notices">Notices</a>
                <a href="#features">Features</a>
                <a href="login.php" class="btn-login">Login</a>
            </div>
        </div>
    </nav>

    <section class="hero" id="home">
        <h1>Welcome to JOOUST Campus Notice System</h1>
        <p>Real-time notices, announcements, and updates from Jaramogi Oginga Odinga University</p>
        <a href="register.php" class="btn-register">Get Started →</a>
        
        <div class="stats">
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_notices; ?></div>
                <div class="stat-label">Total Notices</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_faculties; ?></div>
                <div class="stat-label">Faculties</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">24/7</div>
                <div class="stat-label">Access</div>
            </div>
        </div>
    </section>

    <section class="notices-section" id="notices">
        <div class="container">
            <h2 class="section-title">📢 Recent Notices</h2>
            <div class="notices-grid">
                <?php if(count($recent_notices) > 0): ?>
                    <?php foreach($recent_notices as $notice): ?>
                        <div class="notice-card">
                            <span class="notice-category category-<?php echo $notice['category']; ?>">
                                <?php echo $notice['category']; ?>
                            </span>
                            <h3 class="notice-title"><?php echo htmlspecialchars($notice['title']); ?></h3>
                            <div class="notice-excerpt">
                                <?php echo substr(htmlspecialchars($notice['content']), 0, 120); ?>...
                            </div>
                            <a href="login.php" class="btn-read">Login to Read More →</a>
                            <div class="notice-meta">
                                📅 <?php echo date('d M Y', strtotime($notice['publish_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notice-card">
                        <p>No notices available yet. Check back later!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="features" id="features">
        <div class="container">
            <h2 class="section-title">✨ Why Choose JOOUST Notice System?</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">📱</div>
                    <h3 class="feature-title">Real-time Updates</h3>
                    <p class="feature-desc">Get instant notifications for new notices</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🎯</div>
                    <h3 class="feature-title">Targeted Notices</h3>
                    <p class="feature-desc">See notices relevant to your faculty</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🔖</div>
                    <h3 class="feature-title">Save Bookmarks</h3>
                    <p class="feature-desc">Save important notices for later</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🔍</div>
                    <h3 class="feature-title">Easy Search</h3>
                    <p class="feature-desc">Find any notice quickly with filters</p>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <p>&copy; 2024 Jaramogi Oginga Odinga University of Science and Technology</p>
        <p style="margin-top: 0.5rem; opacity: 0.8;">Stay Connected, Stay Informed</p>
    </footer>
</body>
</html>
