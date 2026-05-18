<?php
require_once 'config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

// Verify token
if(empty($token)) {
    header("Location: forgot_password.php");
    exit();
}

// First, display token for debugging
echo "<!-- Token: " . $token . " -->\n";

// Get user by token
$stmt = $pdo->prepare("SELECT id, email, name, reset_token, reset_expires FROM users WHERE reset_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if(!$user) {
    $error = "Invalid reset link. No user found with this token. Please request a new one.";
} else {
    // Compare expiration using PHP time
    $expires_time = strtotime($user['reset_expires']);
    $current_time = time();
    
    if($current_time > $expires_time) {
        $error = "Your reset link has expired. It expired on " . date('Y-m-d H:i:s', $expires_time) . ". Current time is " . date('Y-m-d H:i:s', $current_time) . ". Please request a new one.";
        // Clear expired token
        $stmt = $pdo->prepare("UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
    } else {
        $valid_token = true;
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($valid_token) && $valid_token) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if(strlen($password) < 6) {
        $error = "Password must be at least 6 characters!";
    } elseif($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        // Update password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt->execute([$hashed_password, $user['id']]);
        
        $success = "Password reset successfully! You can now login with your new password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Campus Notice System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 450px;
            max-width: 100%;
            padding: 40px;
        }
        h2 { text-align: center; margin-bottom: 10px; color: #333; }
        .subtitle { text-align: center; color: #666; margin-bottom: 30px; font-size: 14px; }
        .form-group { margin-bottom: 20px; position: relative; }
        .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        .form-group input { width: 100%; padding: 12px; padding-left: 45px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        .form-group input:focus { outline: none; border-color: #667eea; }
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
        }
        .password-toggle:hover { color: #667eea; }
        button { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; font-weight: bold; }
        button:hover { transform: translateY(-2px); }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; white-space: pre-wrap; }
        .login-link { text-align: center; margin-top: 20px; }
        .login-link a { color: #667eea; text-decoration: none; }
        .requirements { font-size: 12px; color: #666; margin-top: 5px; }
        .info-box { background: #e7f3ff; color: #0066cc; padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 13px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔑 Reset Password</h2>
        
        <?php if($error): ?>
            <div class="error">❌ <?php echo nl2br(htmlspecialchars($error)); ?></div>
            <div class="login-link">
                <a href="forgot_password.php">Request a new reset link →</a>
            </div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="success">✅ <?php echo $success; ?></div>
            <div class="login-link">
                <a href="login.php">Click here to login →</a>
            </div>
        <?php elseif(isset($valid_token) && $valid_token && !$success): ?>
            <div class="subtitle">Reset password for: <?php echo htmlspecialchars($user['email']); ?></div>
            
            <div class="info-box">
                ⏰ This link is valid until: <?php echo date('d M Y, h:i A', strtotime($user['reset_expires'])); ?>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" id="password" placeholder="Enter new password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                        👁️
                    </button>
                    <div class="requirements">Minimum 6 characters</div>
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                        👁️
                    </button>
                </div>
                
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
        
        <div class="login-link">
            <a href="login.php">← Back to Login</a>
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