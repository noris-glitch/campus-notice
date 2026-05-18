<?php
require_once 'config/database.php';

$success = '';
$errors = array();

// Get faculties for dropdown
$faculties = [];
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'faculties'");
    if($checkTable->rowCount() > 0) {
        $faculties = $pdo->query("SELECT * FROM faculties WHERE name <> 'Dean of Students' ORDER BY name")->fetchAll();
    }
} catch (PDOException $e) {
    $faculties = [];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $student_id = trim($_POST['student_id']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'] ?? '';
    $year = $_POST['year'] ?? null;
    $faculty_id = $_POST['faculty_id'] ?? null;
    $membership = trim($_POST['membership'] ?? '');

    // Validation
    if (empty($name)) {
        $errors[] = "Full name is required!";
    }
    if (empty($email)) {
        $errors[] = "Email address is required!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format!";
    }
    if (empty($student_id)) {
        $errors[] = "Student ID is required!";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match!";
    }
    
    // Password strength validation
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long!";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter!";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter!";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number!";
    }
    if (!preg_match('/[@$!%*?&#]/', $password)) {
        $errors[] = "Password must contain at least one special character (@$!%*?&#)!";
    }
    
    if (empty($year)) {
        $errors[] = "Please select year of study!";
    }
    
    if (empty($faculty_id) && count($faculties) > 0) {
        $errors[] = "Please select a faculty!";
    }
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = "Email address already registered!";
    }
    
    // Check if student ID already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE student_id = ?");
    $stmt->execute([$student_id]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = "Student ID already registered!";
    }
    
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Always register as a student from the public registration page
        $is_approved = 1;
        $is_active = 1;
        $role = 'student';
        $success = "Registration successful! You can now login as a student.";

        $fields = ['name', 'email', 'student_id', 'password', 'role', 'is_approved', 'is_active'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
        $params = [$name, $email, $student_id, $hashed_password, $role, $is_approved, $is_active];

        if (!empty($year)) {
            $fields[] = 'year';
            $placeholders[] = '?';
            $params[] = $year;
        }

        if (!empty($faculty_id)) {
            $fields[] = 'faculty_id';
            $placeholders[] = '?';
            $params[] = $faculty_id;
        }

        if (!empty($membership) && columnExists($pdo, 'users', 'membership')) {
            $fields[] = 'membership';
            $placeholders[] = '?';
            $params[] = $membership;
        }

        if (!empty($admin_type)) {
            $fields[] = 'admin_type';
            $placeholders[] = '?';
            $params[] = $admin_type;
        }

        $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $_POST = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - JOOUST Campus Notice System</title>
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
            padding: 40px 20px;
        }
        
        .register-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            max-width: 550px;
            width: 100%;
            margin: 0 auto;
            padding: 40px;
        }
        
        h2 {
            text-align: center;
            margin-bottom: 10px;
            color: #333;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input, 
        .form-group select {
            width: 100%;
            padding: 12px;
            padding-left: 45px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .form-group input:focus, 
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
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
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        /* Password Requirements Box */
        .password-requirements {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 15px;
            margin-top: 8px;
            border-left: 4px solid #667eea;
        }
        
        .password-requirements p {
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 8px;
            color: #333;
        }
        
        .requirements-list {
            list-style: none;
            padding: 0;
        }
        
        .requirements-list li {
            font-size: 11px;
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .requirements-list li.valid {
            color: #28a745;
        }
        
        .requirements-list li.invalid {
            color: #dc3545;
        }
        
        .req-icon {
            font-size: 12px;
            width: 16px;
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
            font-weight: bold;
        }
        
        button:hover {
            transform: translateY(-2px);
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .error ul {
            margin: 10px 0 0 20px;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
        }
        
        .info-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .university-name {
            text-align: center;
            font-size: 11px;
            color: #667eea;
            margin-top: 10px;
            letter-spacing: 1px;
        }
        
        .strength-meter {
            height: 4px;
            background: #ddd;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-bar.weak { background: #dc3545; width: 25%; }
        .strength-bar.fair { background: #ffc107; width: 50%; }
        .strength-bar.good { background: #17a2b8; width: 75%; }
        .strength-bar.strong { background: #28a745; width: 100%; }
        
        .strength-text {
            font-size: 11px;
            margin-top: 5px;
            text-align: right;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2>📢 Create Student Account</h2>
        <div class="subtitle">Join JOOUST Campus Notice System</div>
        
        <?php if($success): ?>
            <div class="success">
                ✅ <?php echo htmlspecialchars($success); ?>
                <br><br>
                <a href="login.php" style="color: #155724; font-weight: bold;">Click here to login →</a>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($errors)): ?>
            <div class="error">
                <strong>❌ Please fix the following errors:</strong>
                <ul>
                    <?php foreach($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="registerForm">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label id="idLabel">Student / Staff ID *</label>
                    <input type="text" name="student_id" value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>" required>
                    <div class="info-text">Your unique student registration number</div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" id="password" required onkeyup="checkPasswordStrength()">
                    <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                        👁️
                    </button>
                    
                    <!-- Password Strength Meter -->
                    <div class="strength-meter">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <div class="strength-text" id="strengthText"></div>
                    
                    <!-- Password Requirements Box -->
                    <div class="password-requirements">
                        <p>Password must contain:</p>
                        <ul class="requirements-list">
                            <li id="req-length" class="invalid">
                                <span class="req-icon">❌</span> At least 8 characters
                            </li>
                            <li id="req-upper" class="invalid">
                                <span class="req-icon">❌</span> One uppercase letter (A-Z)
                            </li>
                            <li id="req-lower" class="invalid">
                                <span class="req-icon">❌</span> One lowercase letter (a-z)
                            </li>
                            <li id="req-number" class="invalid">
                                <span class="req-icon">❌</span> One number (0-9)
                            </li>
                            <li id="req-special" class="invalid">
                                <span class="req-icon">❌</span> One special character (@$!%*?&#)
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" id="confirm_password" required onkeyup="checkPasswordMatch()">
                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                        👁️
                    </button>
                    <div class="info-text" id="matchMessage"></div>
                </div>
            </div>
            
            <?php if(count($faculties) > 0): ?>
            <div class="form-group">
                <label>Faculty *</label>
                <select name="faculty_id" id="faculty_id" required>
                    <option value="">Select Faculty</option>
                    <?php foreach($faculties as $faculty): ?>
                        <option value="<?php echo $faculty['id']; ?>" <?php echo (isset($_POST['faculty_id']) && $_POST['faculty_id'] == $faculty['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($faculty['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Membership</label>
                <select name="membership">
                    <option value="">No Membership</option>
                    <option value="Sports Club" <?php echo (isset($_POST['membership']) && $_POST['membership'] === 'Sports Club') ? 'selected' : ''; ?>>Sports Club</option>
                    <option value="Drama Club" <?php echo (isset($_POST['membership']) && $_POST['membership'] === 'Drama Club') ? 'selected' : ''; ?>>Drama Club</option>
                    <option value="Science Club" <?php echo (isset($_POST['membership']) && $_POST['membership'] === 'Science Club') ? 'selected' : ''; ?>>Science Club</option>
                    <option value="Debate Club" <?php echo (isset($_POST['membership']) && $_POST['membership'] === 'Debate Club') ? 'selected' : ''; ?>>Debate Club</option>
                    <option value="Art Club" <?php echo (isset($_POST['membership']) && $_POST['membership'] === 'Art Club') ? 'selected' : ''; ?>>Art Club</option>
                    <option value="Tech Club" <?php echo (isset($_POST['membership']) && $_POST['membership'] === 'Tech Club') ? 'selected' : ''; ?>>Tech Club</option>
                </select>
            </div>
            
            <div class="form-group">
                <label id="yearLabel">Year of Study *</label>
                <select name="year" id="year" required>
                    <option value="">Select Year</option>
                    <option value="1" <?php echo (isset($_POST['year']) && $_POST['year'] == 1) ? 'selected' : ''; ?>>1st Year</option>
                    <option value="2" <?php echo (isset($_POST['year']) && $_POST['year'] == 2) ? 'selected' : ''; ?>>2nd Year</option>
                    <option value="3" <?php echo (isset($_POST['year']) && $_POST['year'] == 3) ? 'selected' : ''; ?>>3rd Year</option>
                    <option value="4" <?php echo (isset($_POST['year']) && $_POST['year'] == 4) ? 'selected' : ''; ?>>4th Year</option>
                </select>
            </div>
            
            <button type="submit" id="submitButton">Register as Student</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
        
        <div class="university-name">
            Jaramogi Oginga Odinga University of Science and Technology
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
        
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            
            // Check requirements
            const hasLength = password.length >= 8;
            const hasUpper = /[A-Z]/.test(password);
            const hasLower = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const hasSpecial = /[@$!%*?&#]/.test(password);
            
            // Update requirement icons
            updateRequirement('req-length', hasLength);
            updateRequirement('req-upper', hasUpper);
            updateRequirement('req-lower', hasLower);
            updateRequirement('req-number', hasNumber);
            updateRequirement('req-special', hasSpecial);
            
            // Calculate strength
            let strength = 0;
            if (hasLength) strength++;
            if (hasUpper) strength++;
            if (hasLower) strength++;
            if (hasNumber) strength++;
            if (hasSpecial) strength++;
            
            // Update strength meter
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            strengthBar.className = 'strength-bar';
            
            if (strength === 0) {
                strengthBar.style.width = '0%';
                strengthText.innerHTML = '';
            } else if (strength <= 2) {
                strengthBar.classList.add('weak');
                strengthText.innerHTML = 'Weak password';
                strengthText.style.color = '#dc3545';
            } else if (strength <= 3) {
                strengthBar.classList.add('fair');
                strengthText.innerHTML = 'Fair password';
                strengthText.style.color = '#ffc107';
            } else if (strength <= 4) {
                strengthBar.classList.add('good');
                strengthText.innerHTML = 'Good password';
                strengthText.style.color = '#17a2b8';
            } else {
                strengthBar.classList.add('strong');
                strengthText.innerHTML = 'Strong password!';
                strengthText.style.color = '#28a745';
            }
            
            // Check password match
            checkPasswordMatch();
        }
        
        function updateRequirement(id, isValid) {
            const element = document.getElementById(id);
            const icon = element.querySelector('.req-icon');
            
            if (isValid) {
                element.classList.remove('invalid');
                element.classList.add('valid');
                icon.innerHTML = '✅';
            } else {
                element.classList.remove('valid');
                element.classList.add('invalid');
                icon.innerHTML = '❌';
            }
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchMessage = document.getElementById('matchMessage');
            
            if (confirm.length > 0) {
                if (password === confirm) {
                    matchMessage.innerHTML = '✅ Passwords match!';
                    matchMessage.style.color = '#28a745';
                } else {
                    matchMessage.innerHTML = '❌ Passwords do not match!';
                    matchMessage.style.color = '#dc3545';
                }
            } else {
                matchMessage.innerHTML = '';
            }
        }
        
        // Real-time validation
        document.getElementById('password').addEventListener('input', checkPasswordStrength);
        document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);
    </script>
</body>
</html>