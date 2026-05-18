<?php
require_once '../config/database.php';

// Check if user is admin or super admin
if(!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'super_admin')) {
    header("Location: ../login.php");
    exit();
}

// Handle user deletion
if(isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'super_admin'");
    $stmt->execute([$user_id]);
    header("Location: manage_users.php?msg=deleted");
    exit();
}

// Handle user approval
if(isset($_GET['approve'])) {
    $user_id = $_GET['approve'];
    $stmt = $pdo->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
    $stmt->execute([$user_id]);
    header("Location: manage_users.php?msg=approved");
    exit();
}

// Get all users
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .users-table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .users-table th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
        }
        .users-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        .users-table tr:hover {
            background: #f8f9fa;
        }
        .btn-approve {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
        }
        .btn-delete {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
        }
        .badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-super_admin { background: #ff4757; color: white; }
        .badge-admin { background: #ffa502; color: white; }
        .badge-student { background: #1e90ff; color: white; }
        .badge-approved { background: #2ed573; color: white; }
        .badge-pending { background: #ff6348; color: white; }
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">👥 Manage Users</h2>
                <div class="user-info">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
                    <span><?= $_SESSION['user_name'] ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if(isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
                    <div class="success-msg">✅ User deleted successfully!</div>
                <?php endif; ?>
                
                <?php if(isset($_GET['msg']) && $_GET['msg'] == 'approved'): ?>
                    <div class="success-msg">✅ User approved successfully!</div>
                <?php endif; ?>
                
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['name']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $user['role'] ?>">
                                        <?= ucfirst($user['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($user['is_approved']): ?>
                                        <span class="badge badge-approved">Approved</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!$user['is_approved'] && $user['role'] != 'super_admin'): ?>
                                        <a href="?approve=<?= $user['id'] ?>" class="btn-approve" onclick="return confirm('Approve this user?')">✓ Approve</a>
                                    <?php endif; ?>
                                    
                                    <?php if($user['role'] != 'super_admin' && $user['id'] != $_SESSION['user_id']): ?>
                                        <a href="?delete=<?= $user['id'] ?>" class="btn-delete" onclick="return confirm('Delete this user?')">✗ Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>