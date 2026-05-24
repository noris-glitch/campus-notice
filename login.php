<?php
require_once 'config/database.php';

// If already logged in, redirect
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

$error = '';
$landingPage = featureLandingPageSettings($pdo);
$landingBackgroundColor = htmlspecialchars($landingPage['background_color'] ?? '#17324D', ENT_QUOTES, 'UTF-8');
$landingBackgroundImage = !empty($landingPage['background_image_url'])
    ? "url('" . htmlspecialchars($landingPage['background_image_url'], ENT_QUOTES, 'UTF-8') . "')"
    : 'none';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['admin_type'] = $user['admin_type'] ?? null;
        $_SESSION['faculty_id'] = $user['faculty_id'] ?? null;
        $_SESSION['department_id'] = $user['department_id'] ?? null;
        $_SESSION['year'] = $user['year'] ?? null;
        
        if($user['role'] == 'super_admin') {
            header("Location: super_admin/dashboard.php");
        } elseif($user['role'] == 'admin') {
            header("Location: super_admin/dashboard.php");
        } else {
            header("Location: user/feed.php");
        }
        exit();
    } else {
        $error = "Invalid email or password!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - JOOUST Campus Notice System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background-color: <?php echo $landingBackgroundColor; ?>;
            background-image: <?php echo $landingBackgroundImage; ?>;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        
        /* University Name Overlay */
        body::before {
            content: 'JARAMOGI OGINGA ODINGA UNIVERSITY OF SCIENCE AND TECHNOLOGY';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            text-align: center;
            color: white;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 2px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            z-index: 0;
            background: rgba(0,0,0,0.5);
            padding: 10px;
            font-family: monospace;
        }
        
        /* Dark overlay for better text readability */
        body::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0,0,0,0.75), rgba(0,0,0,0.55));
            z-index: 0;
        }
        
        .login-container {
            background: rgba(255,255,255,0.96);
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            width: 400px;
            padding: 40px;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(5px);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .university-name {
            font-size: 12px;
            color: #667eea;
            margin-top: 5px;
            letter-spacing: 1px;
        }
        
        .login-header h2 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            padding-left: 45px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .password-toggle {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: #999;
            padding: 0;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.3s;
        }
        
        button:hover {
            transform: translateY(-2px);
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .forgot-link {
            text-align: center;
            margin-top: 15px;
            margin-bottom: 20px;
        }
        
        .forgot-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        
        .forgot-link a:hover {
            text-decoration: underline;
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .register-link a {
            color: #667eea;
            text-decoration: none;
        }
        
        .role-badge {
            text-align: center;
            margin-top: 15px;
            font-size: 11px;
            color: #999;
        }
        
        @media (max-width: 480px) {
            .login-container {
                width: 90%;
                padding: 30px 20px;
            }
            body::before {
                font-size: 8px;
                top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2>📢 JOOUST Notice System</h2>
            <div class="university-name">Jaramogi Oginga Odinga University of Science and Technology</div>
            <p>Login to access your dashboard</p>
        </div>
        
        <?php if($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <input type="email" name="email" placeholder="Email Address" required>
            </div>
            <div class="form-group">
                <input type="password" name="password" id="password" placeholder="Password" required>
                <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                    👁️
                </button>
            </div>
            <button type="submit">Login</button>
        </form>
        
        <div class="forgot-link">
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
        
        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
        
        <div class="role-badge">
            Super Admin | Admin | Student
        </div>
    </div>
    
    <script>
        function togglePassword(inputId, button) {
            const passwordInput = document.getElementById(inputId);
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'text') {
                button.innerHTML = '🙈';
            } else {
                button.innerHTML = '👁️';
            }
        }
    </script>
</body>
</html>
