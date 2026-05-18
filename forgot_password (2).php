<?php
require_once 'config/database.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    if(empty($email)) {
        $error = "Please enter your email address!";
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if($user) {
            // Generate a simple token
            $token = md5($user['id'] . time() . $email . rand(1000, 9999));
            
            // Set expiration to 24 hours from now
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            // First, clear any existing token for this user
            $clear = $pdo->prepare("UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE id = ?");
            $clear->execute([$user['id']]);
            
            // Save new token
            $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $update->execute([$token, $expires, $user['id']]);
            
            // Verify it was saved
            $check = $pdo->prepare("SELECT reset_token, reset_expires FROM users WHERE id = ?");
            $check->execute([$user['id']]);
            $saved = $check->fetch();
            
            if($saved && $saved['reset_token'] == $token) {
                // Create reset link
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/campus_notice/reset_password.php?token=" . $token;
                
                $success = "Password reset link generated! <br><br>";
                $success .= "<strong>Reset Link:</strong> <a href='$reset_link' target='_blank'>$reset_link</a><br><br>";
                $success .= "<strong>This link expires at:</strong> " . $expires . "<br><br>";
                $success .= "<strong>Token:</strong> " . $token . "<br><br>";
                $success .= "<small>Click the link above to reset your password.</small>";
            } else {
                $error = "Failed to save reset token. Please try again.";
            }
        } else {
            $error = "No account found with that email address!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Campus Notice System</title>
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
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        .form-group input:focus { outline: none; border-color: #667eea; }
        button { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; font-weight: bold; }
        button:hover { transform: translateY(-2px); }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb; word-break: break-all; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        .login-link { text-align: center; margin-top: 20px; }
        .login-link a { color: #667eea; text-decoration: none; }
        .info-text { font-size: 12px; color: #999; margin-top: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔐 Forgot Password?</h2>
        <div class="subtitle">Enter your email to reset your password</div>
        
        <?php if($error): ?>
            <div class="error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="success">✅ <?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your registered email" required>
            </div>
            <button type="submit">Send Reset Link</button>
        </form>
        
        <div class="login-link">
            <a href="login.php">← Back to Login</a>
        </div>
        
        <div class="info-text">
            The reset link will expire in 24 hours.
        </div>
    </div>
</body>
</html>