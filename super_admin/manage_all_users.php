<?php
require_once '../config/database.php';

// Only super admin can access
if(!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'super_admin') {
    header("Location: ../login.php");
    exit();
}

$message = '';
$error = '';
$edit_user = null;

// Handle Add New User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $student_id = trim($_POST['student_id']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $admin_type = $_POST['admin_type'] ?? null;
    $faculty_id = $_POST['faculty_id'] ?? null;
    $year = $_POST['year'] ?? null;
    
    $errors = [];
    
    if(empty($name)) $errors[] = "Name is required!";
    if(empty($email)) $errors[] = "Email is required!";
    if(empty($student_id)) $errors[] = "Student/Staff ID is required!";
    if(strlen($password) < 6) $errors[] = "Password must be at least 6 characters!";
    
    // Check if email exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if($stmt->fetchColumn() > 0) $errors[] = "Email already exists!";
    
    // Check if student ID exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE student_id = ?");
    $stmt->execute([$student_id]);
    if($stmt->fetchColumn() > 0) $errors[] = "Student ID already exists!";
    
    if(empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $is_approved = 1;
        
        $stmt = $pdo->prepare("INSERT INTO users (name, email, student_id, password, role, admin_type, faculty_id, year, is_approved, is_active) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$name, $email, $student_id, $hashed_password, $role, $admin_type, $faculty_id, $year, $is_approved]);
        
        $message = "User '{$name}' has been added successfully!";
    } else {
        $error = implode("<br>", $errors);
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    $user_id = $_POST['user_id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $admin_type = $_POST['admin_type'] ?? null;
    $faculty_id = $_POST['faculty_id'] ?? null;
    $year = $_POST['year'] ?? null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Don't allow changing own role
    if($user_id == $_SESSION['user_id'] && $role != 'super_admin') {
        $error = "You cannot change your own role!";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, admin_type = ?, faculty_id = ?, year = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $email, $role, $admin_type, $faculty_id, $year, $is_active, $user_id]);
        $message = "User updated successfully!";
    }
}

// Handle Delete User
if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    
    // Don't allow deleting yourself
    if($user_id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account!";
    } else {
        // Delete related records first
        $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM notice_views WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM user_locations WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        $message = "User deleted successfully!";
    }
}

// Get user for editing
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_user = $stmt->fetch();
}

// Get all faculties
$faculties = $pdo->query("SELECT * FROM faculties ORDER BY name")->fetchAll();

// Get filter parameters
$role_filter = $_GET['role_filter'] ?? 'all';
$status_filter = $_GET['status_filter'] ?? 'all';

// Build query
$sql = "SELECT u.*, f.name as faculty_name 
        FROM users u 
        LEFT JOIN faculties f ON u.faculty_id = f.id 
        WHERE u.id != ?";
$params = [$_SESSION['user_id']];

if($role_filter != 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $role_filter;
}
if($status_filter != 'all') {
    $sql .= " AND u.is_active = ?";
    $params[] = ($status_filter == 'active') ? 1 : 0;
}

$sql .= " ORDER BY FIELD(u.role, 'super_admin', 'admin', 'student'), u.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get statistics
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE id != {$_SESSION['user_id']}")->fetchColumn();
$total_super_admins = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND id != {$_SESSION['user_id']}")->fetchColumn();
$total_admins = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$active_users = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND id != {$_SESSION['user_id']}")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Super Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            font-size: 1.8rem;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-card p {
            color: #666;
            font-size: 0.85rem;
        }
        
        .section-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 1.3rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
            font-size: 13px;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 5px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
            padding: 5px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
            padding: 5px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .users-table th, .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .users-table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-super_admin { background: #dc3545; color: white; }
        .badge-admin { background: #ffc107; color: #333; }
        .badge-student { background: #28a745; color: white; }
        
        .badge-active { background: #28a745; color: white; }
        .badge-inactive { background: #6c757d; color: white; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-bar select, .filter-bar a {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: white;
            text-decoration: none;
            color: #333;
        }
        
        .filter-bar a:hover {
            background: #667eea;
            color: white;
        }
        
        .message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            width: 500px;
            max-width: 90%;
        }
        
        @media (max-width: 768px) {
            .users-table {
                display: block;
                overflow-x: auto;
            }
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
                <h2 class="page-title">👥 Manage All Users</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if($message): ?>
                    <div class="message">✅ <?php echo $message; ?></div>
                <?php endif; ?>
                
                <?php if($error): ?>
                    <div class="error">❌ <?php echo $error; ?></div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3><?php echo $total_users; ?></h3>
                        <p>Total Users</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $total_super_admins; ?></h3>
                        <p>Super Admins</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $total_admins; ?></h3>
                        <p>Admins</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $total_students; ?></h3>
                        <p>Students</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $active_users; ?></h3>
                        <p>Active Users</p>
                    </div>
                </div>
                
                <!-- Add New User Form -->
                <div class="section-card">
                    <h3 class="section-title">➕ Add New User</h3>
                    <form method="POST">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="name" required>
                            </div>
                            <div class="form-group">
                                <label>Email *</label>
                                <input type="email" name="email" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Student/Staff ID *</label>
                                <input type="text" name="student_id" required>
                            </div>
                            <div class="form-group">
                                <label>Password *</label>
                                <input type="password" name="password" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role" id="add_role" onchange="toggleAdminType('add')">
                                    <option value="student">Student</option>
                                    <option value="admin">Admin</option>
                                    <option value="super_admin">Super Admin</option>
                                </select>
                            </div>
                            <div class="form-group" id="add_admin_type_group" style="display: none;">
                                <label>Admin Type</label>
                                <select name="admin_type">
                                    <option value="faculty">Faculty Member</option>
                                    <option value="hod">Head of Faculty</option>
                                    <option value="dean_of_students">Dean of Students</option>
                                    <option value="student_leader">Student Leader</option>
                                    <option value="club_leader">Club Leader</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Faculty</label>
                                <select name="faculty_id">
                                    <option value="">Select Faculty</option>
                                    <?php foreach($faculties as $faculty): ?>
                                        <option value="<?php echo $faculty['id']; ?>"><?php echo htmlspecialchars($faculty['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Year (for students)</label>
                                <select name="year">
                                    <option value="">Select Year</option>
                                    <option value="1">1st Year</option>
                                    <option value="2">2nd Year</option>
                                    <option value="3">3rd Year</option>
                                    <option value="4">4th Year</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="add_user" class="btn-primary">➕ Add User</button>
                    </form>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <select id="roleFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $role_filter == 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="super_admin" <?php echo $role_filter == 'super_admin' ? 'selected' : ''; ?>>Super Admins</option>
                        <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admins</option>
                        <option value="student" <?php echo $role_filter == 'student' ? 'selected' : ''; ?>>Students</option>
                    </select>
                    <select id="statusFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <input type="text" id="searchInput" placeholder="🔍 Search users..." onkeyup="searchUsers()" style="padding: 8px; border: 1px solid #ddd; border-radius: 5px; width: 200px;">
                </div>
                
                <!-- Users List -->
                <div class="section-card">
                    <h3 class="section-title">📋 All Users</h3>
                    
                    <table class="users-table" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Student ID</th>
                                <th>Role</th>
                                <th>Faculty</th>
                                <th>Year</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['student_id']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['faculty_name'] ? htmlspecialchars($user['faculty_name']) : '-'; ?></td>
                                    <td><?php echo $user['year'] ? $user['year'] . 'st Year' : '-'; ?></td>
                                    <td>
                                        <?php if($user['is_active']): ?>
                                            <span class="badge badge-active">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $user['id']; ?>" class="btn-warning">✏️ Edit</a>
                                            <a href="?delete=<?php echo $user['id']; ?>" class="btn-danger" onclick="return confirm('Are you sure you want to delete this user?')">🗑️ Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <?php if($edit_user): ?>
    <div id="editModal" style="display: flex;">
        <div class="modal-content">
            <h3>✏️ Edit User</h3>
            <form method="POST">
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($edit_user['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($edit_user['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="edit_role" onchange="toggleAdminType('edit')">
                        <option value="student" <?php echo $edit_user['role'] == 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="admin" <?php echo $edit_user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="super_admin" <?php echo $edit_user['role'] == 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                    </select>
                </div>
                <div class="form-group" id="edit_admin_type_group" style="display: <?php echo $edit_user['role'] == 'admin' ? 'block' : 'none'; ?>">
                    <label>Admin Type</label>
                    <select name="admin_type">
                        <option value="faculty" <?php echo $edit_user['admin_type'] == 'faculty' ? 'selected' : ''; ?>>Faculty Member</option>
                        <option value="hod" <?php echo $edit_user['admin_type'] == 'hod' ? 'selected' : ''; ?>>Head of Faculty</option>
                        <option value="dean_of_students" <?php echo $edit_user['admin_type'] == 'dean_of_students' ? 'selected' : ''; ?>>Dean of Students</option>
                        <option value="student_leader" <?php echo $edit_user['admin_type'] == 'student_leader' ? 'selected' : ''; ?>>Student Leader</option>
                        <option value="club_leader" <?php echo $edit_user['admin_type'] == 'club_leader' ? 'selected' : ''; ?>>Club Leader</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Faculty</label>
                    <select name="faculty_id">
                        <option value="">Select Faculty</option>
                        <?php foreach($faculties as $faculty): ?>
                            <option value="<?php echo $faculty['id']; ?>" <?php echo $edit_user['faculty_id'] == $faculty['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($faculty['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <select name="year">
                        <option value="">Select Year</option>
                        <option value="1" <?php echo $edit_user['year'] == 1 ? 'selected' : ''; ?>>1st Year</option>
                        <option value="2" <?php echo $edit_user['year'] == 2 ? 'selected' : ''; ?>>2nd Year</option>
                        <option value="3" <?php echo $edit_user['year'] == 3 ? 'selected' : ''; ?>>3rd Year</option>
                        <option value="4" <?php echo $edit_user['year'] == 4 ? 'selected' : ''; ?>>4th Year</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?php echo $edit_user['is_active'] ? 'checked' : ''; ?>>
                        Active Account
                    </label>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="edit_user" class="btn-primary">💾 Save Changes</button>
                    <a href="manage_all_users.php" class="btn-danger">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        function toggleAdminType(formType) {
            const role = document.getElementById(formType + '_role').value;
            const adminTypeGroup = document.getElementById(formType + '_admin_type_group');
            if(adminTypeGroup) {
                adminTypeGroup.style.display = role === 'admin' ? 'block' : 'none';
            }
        }
        
        function applyFilters() {
            const role = document.getElementById('roleFilter').value;
            const status = document.getElementById('statusFilter').value;
            window.location.href = `?role_filter=${role}&status_filter=${status}`;
        }
        
        function searchUsers() {
            let input = document.getElementById('searchInput');
            let filter = input.value.toLowerCase();
            let table = document.getElementById('usersTable');
            let rows = table.getElementsByTagName('tr');
            
            for(let i = 1; i < rows.length; i++) {
                let row = rows[i];
                let text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(filter) > -1 ? "" : "none";
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('editModal')?.addEventListener('click', function(e) {
            if(e.target === this) {
                window.location.href = 'manage_all_users.php';
            }
        });
    </script>
</body>
</html>