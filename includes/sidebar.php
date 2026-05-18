<?php
// Get current page to highlight active menu item
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['user_role'] ?? 'student';
$admin_type = $_SESSION['admin_type'] ?? null;
$faculty_id = $_SESSION['faculty_id'] ?? null;
$user_name = $_SESSION['user_name'] ?? 'User';

// Get faculty name for display (if exists)
$faculty_name = '';
if($faculty_id && ($user_role == 'student' || $user_role == 'admin')) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM faculties WHERE id = ?");
        $stmt->execute([$faculty_id]);
        $faculty = $stmt->fetch();
        $faculty_name = $faculty ? $faculty['name'] : '';
    } catch (PDOException $e) {
        $faculty_name = '';
    }
}

// Get unread notification count for the bell icon (only for students)
$unread_count = 0;
if(isset($_SESSION['user_id']) && $user_role == 'student') {
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'notifications'");
        if($checkTable && $checkTable->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$_SESSION['user_id']]);
            $unread_count = $stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        $unread_count = 0;
    }
}

// Get active emergency alerts count for super admin
$emergency_count = 0;
if(isset($_SESSION['user_id']) && $user_role == 'super_admin') {
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'emergency_alerts'");
        if($checkTable && $checkTable->rowCount() > 0) {
            $checkColumn = $pdo->query("SHOW COLUMNS FROM emergency_alerts LIKE 'is_active'");
            if($checkColumn && $checkColumn->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM emergency_alerts WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM emergency_alerts WHERE expires_at IS NULL OR expires_at > NOW()");
            }
            $stmt->execute();
            $emergency_count = $stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        $emergency_count = 0;
    }
}
?>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-sidebar-top" onclick="toggleSidebar()" title="Toggle Sidebar">
            <span id="toggle-icon">☰</span>
        </button>
        <h3>📢 JOOUSTNotice</h3>
    </div>
    
    <!-- Faculty Display for Students and Admins -->
    <?php if(($user_role == 'student' || $user_role == 'admin') && $faculty_name): ?>
        <div class="faculty-info">
            <div class="faculty-label">YOUR FACULTY</div>
            <div class="faculty-name"><?php echo htmlspecialchars(substr($faculty_name, 0, 35)); ?>...</div>
        </div>
    <?php endif; ?>
    
    <div class="sidebar-menu">
        
        <?php if($user_role == 'super_admin'): ?>
            <!-- ============================================ -->
            <!-- SUPER ADMIN MENU - Full System Access       -->
            <!-- ============================================ -->
            <div class="menu-section">Main</div>
            
            <a href="../super_admin/dashboard.php" class="menu-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <span class="menu-icon">👑</span>
                <span class="menu-text">Dashboard</span>
            </a>
            
            <!-- EMERGENCY ALERT - Prominent Menu Item -->
            <a href="../super_admin/emergency_alert.php" class="menu-item emergency-menu <?php echo ($current_page == 'emergency_alert.php') ? 'active' : ''; ?>">
                <span class="menu-icon">🚨</span>
                <span class="menu-text">Emergency Alert</span>
                <?php if($emergency_count > 0): ?>
                    <span class="emergency-badge"><?php echo $emergency_count; ?> ACTIVE</span>
                <?php endif; ?>
            </a>
            
            <div class="menu-section">User Management</div>
            
            <a href="../super_admin/manage_all_users.php" class="menu-item <?php echo ($current_page == 'manage_all_users.php') ? 'active' : ''; ?>">
                <span class="menu-icon">👥</span>
                <span class="menu-text">Manage All Users</span>
            </a>
            
            <div class="menu-section">Notice Management</div>
            
            <a href="../super_admin/create_notice.php" class="menu-item <?php echo ($current_page == 'create_notice.php') ? 'active' : ''; ?>">
                <span class="menu-icon">➕</span>
                <span class="menu-text">Create Notice</span>
            </a>
            
            <a href="../super_admin/create_notice_with_location.php" class="menu-item <?php echo ($current_page == 'create_notice_with_location.php') ? 'active' : ''; ?>">
                <span class="menu-icon">📍</span>
                <span class="menu-text">Create Location Event</span>
            </a>
            
            <a href="../super_admin/manage_notices.php" class="menu-item <?php echo ($current_page == 'manage_notices.php') ? 'active' : ''; ?>">
                <span class="menu-icon">📋</span>
                <span class="menu-text">Manage Notices</span>
            </a>

            <a href="../super_admin/student_sync.php" class="menu-item <?php echo ($current_page == 'student_sync.php') ? 'active' : ''; ?>">
                <span class="menu-icon">🔄</span>
                <span class="menu-text">Student Sync</span>
            </a>
            
            <a href="../super_admin/event_map.php" class="menu-item <?php echo ($current_page == 'event_map.php') ? 'active' : ''; ?>">
                <span class="menu-icon">🗺️</span>
                <span class="menu-text">Event Map</span>
            </a>
            
        <?php elseif($user_role == 'admin'): ?>
            <!-- ============================================ -->
            <!-- ADMIN MENU - Department/Faculty Management    -->
            <!-- ============================================ -->
            <div class="menu-section">Main</div>
            
            <a href="../super_admin/dashboard.php" class="menu-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <span class="menu-icon">📊</span>
                <span class="menu-text">Dashboard</span>
            </a>
            
            <div class="menu-section">Notice Management</div>
            
            <a href="../super_admin/create_notice.php" class="menu-item <?php echo ($current_page == 'create_notice.php') ? 'active' : ''; ?>">
                <span class="menu-icon">➕</span>
                <span class="menu-text">Create Notice</span>
            </a>
            
            <a href="../super_admin/create_notice_with_location.php" class="menu-item <?php echo ($current_page == 'create_notice_with_location.php') ? 'active' : ''; ?>">
                <span class="menu-icon">📍</span>
                <span class="menu-text">Create Location Event</span>
            </a>
            
            <a href="../super_admin/manage_notices.php" class="menu-item <?php echo ($current_page == 'manage_notices.php') ? 'active' : ''; ?>">
                <span class="menu-icon">📋</span>
                <span class="menu-text">My Notices</span>
            </a>
            
            <?php if($admin_type == 'dean_of_students' || $admin_type == 'hod' || $admin_type == 'faculty'): ?>
                <div class="menu-section">User Management</div>
                
                <a href="../super_admin/student_sync.php" class="menu-item <?php echo ($current_page == 'student_sync.php') ? 'active' : ''; ?>">
                    <span class="menu-icon">🔄</span>
                    <span class="menu-text">Sync Students</span>
                </a>
            <?php endif; ?>
            
            <div class="menu-section">Location & Events</div>
            
            <a href="../user/nearby_events.php" class="menu-item">
                <span class="menu-icon">🗺️</span>
                <span class="menu-text">Nearby Events</span>
            </a>
            
            <a href="../super_admin/event_map.php" class="menu-item <?php echo ($current_page == 'event_map.php') ? 'active' : ''; ?>">
                <span class="menu-icon">📍</span>
                <span class="menu-text">Event Map View</span>
            </a>
            
            
        <?php else: ?>
            <!-- ============================================ -->
            <!-- STUDENT MENU - Regular User Access           -->
            <!-- ============================================ -->
            <div class="menu-section">Main</div>
            
            <a href="../user/feed.php" class="menu-item <?php echo ($current_page == 'feed.php') ? 'active' : ''; ?>">
                <span class="menu-icon">🏠</span>
                <span class="menu-text">Home Feed</span>
            </a>
            
            <a href="../user/notifications.php" class="menu-item">
                <span class="menu-icon">🔔</span>
                <span class="menu-text">Notifications</span>
                <?php if($unread_count > 0): ?>
                    <span class="notification-badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="../user/bookmarks.php" class="menu-item">
                <span class="menu-icon">🔖</span>
                <span class="menu-text">Bookmarks</span>
            </a>

            <a href="../user/archive.php" class="menu-item <?php echo ($current_page == 'archive.php') ? 'active' : ''; ?>">
                <span class="menu-icon">🗂️</span>
                <span class="menu-text">Notice Archive</span>
            </a>
            
            <div class="menu-section">Location & Events</div>
            
            <a href="../user/share_location.php" class="menu-item">
                <span class="menu-icon">📍</span>
                <span class="menu-text">Share Location</span>
            </a>
            
            <a href="../user/nearby_events.php" class="menu-item">
                <span class="menu-icon">🗺️</span>
                <span class="menu-text">Nearby Events</span>
            </a>
            
            <a href="../user/event_map.php" class="menu-item">
                <span class="menu-icon">📍</span>
                <span class="menu-text">Event Map</span>
            </a>
            
            <div class="menu-section">Account</div>
            
            <a href="../user/profile.php" class="menu-item">
                <span class="menu-icon">👤</span>
                <span class="menu-text">My Profile</span>
            </a>
            
        <?php endif; ?>
        
        <?php if($user_role == 'student'): ?>
            <div class="menu-section">General</div>
            
            <a href="help.php" class="menu-item">
                <span class="menu-icon">❓</span>
                <span class="menu-text">Help & Support</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if($user_role == 'student'): ?>
    <!-- Help Desk Bar -->
    <?php include 'help_desk.php'; ?>
<?php endif; ?>

<style>
/* Sidebar Styles */
.sidebar {
    width: 280px;
    background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
    color: white;
    position: fixed;
    height: 100vh;
    left: 0;
    top: 0;
    transition: all 0.3s ease;
    z-index: 1000;
    overflow-y: auto;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
}

.sidebar.collapsed {
    width: 80px;
}

.sidebar-header {
    padding: 25px 20px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: relative;
}

.toggle-sidebar-top {
    position: absolute;
    left: 15px;
    top: 15px;
    background: none;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    padding: 5px;
    border-radius: 3px;
    transition: background 0.2s ease;
}

.toggle-sidebar-top:hover {
    background: rgba(255,255,255,0.1);
}

.sidebar-header h3 {
    font-size: 1.3rem;
    white-space: nowrap;
    margin: 0;
}

.sidebar.collapsed .sidebar-header h3 {
    font-size: 0;
}

.sidebar.collapsed .sidebar-header h3::first-letter {
    font-size: 1.5rem;
}

.sidebar.collapsed .toggle-sidebar-top {
    left: 50%;
    transform: translateX(-50%);
}

/* Faculty Info */
.faculty-info {
    padding: 15px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.05);
}

.faculty-label {
    font-size: 10px;
    opacity: 0.6;
    letter-spacing: 1px;
    margin-bottom: 5px;
    text-transform: uppercase;
}

.faculty-name {
    font-size: 11px;
    font-weight: 500;
    word-break: break-word;
}

.sidebar.collapsed .faculty-info {
    display: none;
}

/* Menu Sections */
.menu-section {
    padding: 15px 20px 5px 20px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.5;
    font-weight: 600;
}

.sidebar.collapsed .menu-section {
    text-align: center;
    padding: 15px 5px 5px 5px;
    font-size: 0;
}

.sidebar.collapsed .menu-section::first-letter {
    font-size: 11px;
}

/* Menu Items */
.menu-item {
    padding: 12px 20px;
    margin: 5px 0;
    transition: all 0.3s;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 15px;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    position: relative;
}

.menu-item:hover {
    background: rgba(255,255,255,0.1);
    padding-left: 25px;
    color: white;
}

.menu-item.active {
    background: rgba(255,255,255,0.15);
    border-left: 3px solid #667eea;
    color: white;
}

/* Emergency Menu Item Special Styling */
.emergency-menu {
    background: rgba(220, 53, 69, 0.15);
    border-left: 3px solid #dc3545;
    margin: 10px 0;
}

.emergency-menu:hover {
    background: rgba(220, 53, 69, 0.25);
}

.emergency-menu .menu-icon {
    animation: emergencyIcon 1s infinite;
}

@keyframes emergencyIcon {
    0% { transform: scale(1); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}

.emergency-badge {
    background: #dc3545;
    color: white;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 10px;
    margin-left: auto;
    animation: pulse 1s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}

.menu-icon {
    font-size: 1.2rem;
    min-width: 30px;
    text-align: center;
}

.menu-text {
    font-size: 0.9rem;
    white-space: nowrap;
}

.sidebar.collapsed .menu-text {
    display: none;
}

/* Notification Badge */
.notification-badge {
    background: #ff4757;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 10px;
    margin-left: auto;
    min-width: 18px;
    text-align: center;
    animation: pulse 1.5s infinite;
}

/* Scrollbar */
.sidebar::-webkit-scrollbar {
    width: 5px;
}

.sidebar::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}

.sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 5px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
}

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        width: 80px;
    }
    .sidebar .menu-text {
        display: none;
    }
    .sidebar .faculty-info {
        display: none;
    }
    .sidebar .menu-section {
        text-align: center;
        padding: 15px 5px 5px 5px;
        font-size: 0;
    }
    .sidebar .menu-section::first-letter {
        font-size: 11px;
    }
    .emergency-badge {
        display: none;
    }
}
</style>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const toggleIcon = document.getElementById('toggle-icon');
    
    sidebar.classList.toggle('collapsed');
    if(mainContent) {
        mainContent.classList.toggle('expanded');
    }
    
    if(sidebar.classList.contains('collapsed')) {
        if(toggleIcon) toggleIcon.textContent = '▶';
        localStorage.setItem('sidebarCollapsed', 'true');
    } else {
        if(toggleIcon) toggleIcon.textContent = '☰';
        localStorage.setItem('sidebarCollapsed', 'false');
    }
}

// Load sidebar state from localStorage
document.addEventListener('DOMContentLoaded', function() {
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if(isCollapsed) {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        const toggleIcon = document.getElementById('toggle-icon');
        
        if(sidebar) sidebar.classList.add('collapsed');
        if(mainContent) mainContent.classList.add('expanded');
        if(toggleIcon) toggleIcon.textContent = '▶';
    }
});
</script>
