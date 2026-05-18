<?php
require_once 'config/database.php';

$step = 1; // 1 = email entry, 2 = reset code entry
$success = '';
$error = '';
$email = '';

// Step 1: Request reset code
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_code'])) {
    $email = trim($_POST['email']);
    
    if(empty($email)) {
        $error = "Please enter your email address!";
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Save token to database
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
            $stmt->execute([$token, $expires, $email]);
            
            // Store email in session for step 2
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_token'] = $token;
            
            // Create reset link
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/campus_notice/reset_password.php?token=" . $token;
            
            $success = "Password reset link has been generated!<br><br>";
            $success .= "<strong>Your reset link:</strong><br>";
            $success .= "<a href='$reset_link' target='_blank'>$reset_link</a><br><br>";
            $success .= "<small>This link expires in 1 hour.</small>";
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        h2 {
            text-align: center;
            margin-bottom: 10px;
            color: #333;
            font-size: 1.8rem;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
            word-break: break-all;
            font-size: 14px;
        }
        
        .success a {
            color: #155724;
            text-decoration: underline;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        .info-text {
            font-size: 12px;
            color: #999;
            margin-top: 15px;
            text-align: center;
        }
        
        .resend-link {
            text-align: center;
            margin-top: 15px;
        }
        
        .resend-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 30px 20px;
            }
            
            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔐 Forgot Password?</h2>
        <div class="subtitle">Enter your email to reset your password</div>
        
        <?php if($error): ?>
            <div class="error">
                ❌ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="success">
                ✅ <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your registered email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            </div>
            <button type="submit" name="send_code">Send Reset Link</button>
        </form>
        
        <div class="login-link">
            <a href="login.php">← Back to Login</a>
        </div>
        
        <div class="info-text">
            <p>📧 We'll generate a reset link for your account.</p>
            <p>⏰ The link will expire in 1 hour.</p>
            <p>🔒 For security, the link can only be used once.</p>
        </div>
    </div>
</body>
</html>