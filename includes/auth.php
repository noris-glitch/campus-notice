<?php
// includes/auth.php - Authentication check for all protected pages

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin';
}

// Check if user is faculty
function isFaculty() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'faculty';
}

// Check if user is student
function isStudent() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'student';
}

// Require login - redirects to login page if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . getBaseUrl() . "/login.php");
        exit();
    }
}

// Require admin - redirects if not admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: " . getBaseUrl() . "/user/feed.php");
        exit();
    }
}

// Get base URL for redirects
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = dirname($_SERVER['SCRIPT_NAME']);
    
    // Remove trailing slash
    $base = rtrim($protocol . '://' . $host . $script, '/');
    
    // Go up one level if in subdirectory
    if (strpos($base, '/user') !== false || strpos($base, '/admin') !== false) {
        $base = dirname($base);
    }
    
    return $base;
}

// Get current user data
function getCurrentUser($pdo) {
    if (!isLoggedIn()) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// Auto-check on every page that includes this file
// This ensures user is logged in for protected pages
if (basename($_SERVER['PHP_SELF']) != 'login.php' && 
    basename($_SERVER['PHP_SELF']) != 'register.php' &&
    basename($_SERVER['PHP_SELF']) != 'index.php') {
    
    // For pages in user/ or admin/ folders, require login
    $path = $_SERVER['SCRIPT_NAME'];
    if (strpos($path, '/user/') !== false || strpos($path, '/admin/') !== false) {
        requireLogin();
    }
}
?>