<?php
require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'admin/' : 'dashboard.php'));
    exit;
}

$error = '';
$success = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if ($username && $password) {
            $stmt = $db->prepare("SELECT id, username, email, password, role, status, credits_balance FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['credits_balance'] = $user['credits_balance'];
                
                // Update last login
                $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                logActivity('login', ['method' => 'web']);
                
                header('Location: ' . ($user['role'] === 'admin' ? 'admin/' : 'dashboard.php'));
                exit;
            } else {
                $error = 'Invalid username or password';
            }
        } else {
            $error = 'Please fill in all fields';
        }
    } elseif ($action === 'register') {
        $username = trim($_POST['reg_username'] ?? '');
        $email = trim($_POST['reg_email'] ?? '');
        $password = $_POST['reg_password'] ?? '';
        $confirmPassword = $_POST['reg_confirm_password'] ?? '';
        
        if ($username && $email && $password && $confirmPassword) {
            if ($password !== $confirmPassword) {
                $error = 'Passwords do not match';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters';
            } else {
                // Check if username/email exists
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                
                if ($stmt->fetch()) {
                    $error = 'Username or email already exists';
                } else {
                    // Create user
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $defaultCredits = getSetting('default_user_credits', 10000);
                    $maxCredits = getSetting('max_user_credits', 50000);
                    
                    $stmt = $db->prepare("
                        INSERT INTO users (username, email, password, credits_balance, max_credits) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    if ($stmt->execute([$username, $email, $hashedPassword, $defaultCredits, $maxCredits])) {
                        $success = 'Account created successfully! You can now login.';
                        logActivity('register', ['username' => $username], $db->lastInsertId());
                    } else {
                        $error = 'Failed to create account';
                    }
                }
            }
        } else {
            $error = 'Please fill in all fields';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --primary-dark: #5a6fd8;
            --secondary-color: #764ba2;
            --accent-color: #f093fb;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-color: #334155;
            --text-light: #64748b;
            --border-color: #e2e8f0;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            padding: 30px 30px 20px;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }
        
        .login-header p {
            opacity: 0.9;
            margin: 8px 0 0;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .nav-tabs {
            border: none;
            margin-bottom: 20px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--text-light);
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 8px;
            margin-right: 8px;
        }
        
        .nav-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        .form-control {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        .alert {
            border: none;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .features-list {
            background: var(--bg-color);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .features-list h6 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .features-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .features-list li {
            color: var(--text-light);
            font-size: 13px;
            margin-bottom: 6px;
            padding-left: 16px;
            position: relative;
        }
        
        .features-list li:before {
            content: "✨";
            position: absolute;
            left: 0;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1><i class="fas fa-microphone-alt me-2"></i><?= APP_NAME ?></h1>
                <p>Professional Voice Synthesis Platform</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#login-tab">
                            <i class="fas fa-sign-in-alt me-1"></i>Login
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#register-tab">
                            <i class="fas fa-user-plus me-1"></i>Register
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content">
                    <!-- Login Tab -->
                    <div class="tab-pane fade show active" id="login-tab">
                        <form method="POST">
                            <input type="hidden" name="action" value="login">
                            
                            <div class="mb-3">
                                <label class="form-label">Username or Email</label>
                                <input type="text" name="username" class="form-control" 
                                       placeholder="Enter username or email" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" 
                                       placeholder="Enter password" required>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-sign-in-alt me-2"></i>Login
                            </button>
                        </form>
                        
                        <div class="features-list">
                            <h6><i class="fas fa-star me-1"></i>Demo Credentials</h6>
                            <ul>
                                <li>Admin: <strong>admin</strong> / <strong>admin123</strong></li>
                                <li>Or create your own account above</li>
                                <li>Free <?= formatCredits(getSetting('default_user_credits', 10000)) ?> credits for new users</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Register Tab -->
                    <div class="tab-pane fade" id="register-tab">
                        <form method="POST">
                            <input type="hidden" name="action" value="register">
                            
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="reg_username" class="form-control" 
                                       placeholder="Choose username" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="reg_email" class="form-control" 
                                       placeholder="Enter email address" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="reg_password" class="form-control" 
                                       placeholder="Create password (min 6 chars)" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" name="reg_confirm_password" class="form-control" 
                                       placeholder="Confirm password" required>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-user-plus me-2"></i>Create Account
                            </button>
                        </form>
                        
                        <div class="features-list">
                            <h6><i class="fas fa-gift me-1"></i>New User Benefits</h6>
                            <ul>
                                <li><?= formatCredits(getSetting('default_user_credits', 10000)) ?> free credits monthly</li>
                                <li>Access to 50+ voice models</li>
                                <li>32+ language support</li>
                                <li>Professional audio quality</li>
                                <li>SRT subtitle export</li>
                                <li>Voice library import</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
