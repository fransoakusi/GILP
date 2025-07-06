<?php
/**
 * Beautiful Login Page for Girls Leadership Program
 * Modern UI with animations and secure authentication
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(__FILE__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirect('dashboard.php'); // Fixed: relative path instead of absolute
}

// Handle login form submission
$loginError = '';
$loginSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $loginError = 'Security token mismatch. Please try again.';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember_me']);
        
        if (empty($username) || empty($password)) {
            $loginError = 'Please enter both username and password.';
        } else {
            $result = auth()->login($username, $password, $rememberMe);
            
            if ($result['success']) {
                $loginSuccess = $result['message'];
                // Fixed: Use PHP redirect instead of JavaScript for better reliability
                header("refresh:2;url=dashboard.php");
            } else {
                $loginError = $result['message'];
            }
        }
    }
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $email = sanitizeInput($_POST['reset_email'] ?? '');
    
    if (empty($email)) {
        $loginError = 'Please enter your email address.';
    } else {
        $result = auth()->resetPassword($email);
        $loginSuccess = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #6C5CE7;
            --secondary-color: #A29BFE;
            --accent-color: #FD79A8;
            --success-color: #00B894;
            --warning-color: #FDCB6E;
            --danger-color: #E84393;
            --dark-color: #2D3436;
            --light-color: #F8F9FA;
            --white: #FFFFFF;
            --shadow: 0 10px 30px rgba(108, 92, 231, 0.2);
            --shadow-hover: 0 15px 40px rgba(108, 92, 231, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #6C5CE7 0%, #A29BFE 50%, #FD79A8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background elements */
        .bg-animation {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
        }

        .floating-shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        .shape-1 {
            width: 80px;
            height: 80px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 120px;
            height: 120px;
            top: 60%;
            right: 10%;
            animation-delay: 2s;
        }

        .shape-3 {
            width: 60px;
            height: 60px;
            top: 10%;
            right: 20%;
            animation-delay: 4s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(10deg); }
            66% { transform: translateY(-10px) rotate(-5deg); }
        }

        .login-container {
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            min-height: 600px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            position: relative;
            z-index: 2;
            animation: slideIn 0.8s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-form-section {
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-hero-section {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: var(--white);
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
        }

        .hero-icon {
            font-size: 80px;
            margin-bottom: 30px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .hero-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.2;
        }

        .hero-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 30px;
            line-height: 1.5;
        }

        .hero-features {
            list-style: none;
            text-align: left;
        }

        .hero-features li {
            margin: 10px 0;
            display: flex;
            align-items: center;
        }

        .hero-features i {
            margin-right: 10px;
            color: var(--accent-color);
        }

        .form-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .form-header h1 {
            color: var(--dark-color);
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .form-header p {
            color: #636E72;
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark-color);
            font-weight: 500;
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 15px 20px 15px 50px;
            border: 2px solid #E9ECEF;
            border-radius: 10px;
            font-size: 16px;
            background: var(--white);
            transition: all 0.3s ease;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
            transform: translateY(-2px);
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #ADB5BD;
            font-size: 16px;
            transition: color 0.3s ease;
        }

        .form-control:focus + .input-icon {
            color: var(--primary-color);
        }

        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #ADB5BD;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .remember-me {
            display: flex;
            align-items: center;
        }

        .remember-me input[type="checkbox"] {
            margin-right: 8px;
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }

        .forgot-password {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-loading {
            opacity: 0.8;
            cursor: not-allowed;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid transparent;
            border-top: 2px solid var(--white);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .divider {
            text-align: center;
            margin: 30px 0;
            position: relative;
            color: #ADB5BD;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #E9ECEF;
        }

        .divider span {
            background: var(--white);
            padding: 0 20px;
        }

        .register-link {
            text-align: center;
            color: #636E72;
        }

        .register-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .register-link a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border: none;
            font-size: 14px;
            font-weight: 500;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: #FEF2F2;
            color: #DC2626;
            border-left: 4px solid #DC2626;
        }

        .alert-success {
            background: #ECFDF5;
            color: #059669;
            border-left: 4px solid #059669;
        }

        .reset-form {
            display: none;
        }

        .reset-form.active {
            display: block;
            animation: fadeIn 0.3s ease-out;
        }

        .login-form.hidden {
            display: none;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 20px;
            transition: color 0.3s ease;
        }

        .back-link:hover {
            color: var(--secondary-color);
        }

        .back-link i {
            margin-right: 5px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                grid-template-columns: 1fr;
                max-width: 400px;
                margin: 20px;
            }

            .login-hero-section {
                display: none;
            }

            .login-form-section {
                padding: 40px 30px;
            }

            .hero-title {
                font-size: 24px;
            }

            .form-header h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .login-form-section {
                padding: 30px 20px;
            }

            .form-control {
                padding: 12px 15px 12px 40px;
            }

            .btn {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="floating-shape shape-1"></div>
        <div class="floating-shape shape-2"></div>
        <div class="floating-shape shape-3"></div>
    </div>

    <div class="login-container">
        <!-- Hero Section -->
        <div class="login-hero-section">
            <div class="hero-icon">
                <i class="fas fa-users"></i>
            </div>
            <h1 class="hero-title">Girls Leadership Program</h1>
            <p class="hero-subtitle">Empowering the next generation of female leaders through mentorship, training, and collaborative projects.</p>
            
            <ul class="hero-features">
                <li><i class="fas fa-check-circle"></i> Project Management</li>
                <li><i class="fas fa-check-circle"></i> Mentor-Mentee Matching</li>
                <li><i class="fas fa-check-circle"></i> Skill Development</li>
                <li><i class="fas fa-check-circle"></i> Progress Tracking</li>
                <li><i class="fas fa-check-circle"></i> Community Building</li>
            </ul>
        </div>

        <!-- Login Form Section -->
        <div class="login-form-section">
            <!-- Login Form -->
            <div class="login-form" id="loginForm">
                <div class="form-header">
                    <h1>Welcome Back!</h1>
                    <p>Sign in to continue your leadership journey</p>
                </div>

                <?php if (!empty($loginError)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($loginError); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($loginSuccess)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($loginSuccess); ?>
                        <div style="margin-top: 10px; font-size: 12px;">
                            <i class="fas fa-spinner fa-spin"></i> Redirecting to dashboard...
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <div class="input-wrapper">
                            <input type="text" id="username" name="username" class="form-control" 
                                   placeholder="Enter your username or email" 
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                            <i class="fas fa-user input-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password" class="form-control" 
                                   placeholder="Enter your password" required>
                            <i class="fas fa-lock input-icon"></i>
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('password')"></i>
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember_me">
                            Remember me
                        </label>
                        <a href="#" class="forgot-password" onclick="showResetForm()">Forgot Password?</a>
                    </div>

                    <button type="submit" name="login" class="btn" id="loginBtn">
                        <span>Sign In</span>
                    </button>
                </form>

                <div class="divider">
                    <span>New to our program?</span>
                </div>

                <div class="register-link">
                    <p>Don't have an account? <a href="register.php">Create Account</a></p>
                </div>
            </div>

            <!-- Password Reset Form -->
            <div class="reset-form" id="resetForm">
                <a href="#" class="back-link" onclick="showLoginForm()">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>

                <div class="form-header">
                    <h1>Reset Password</h1>
                    <p>Enter your email to receive reset instructions</p>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="reset_email">Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" id="reset_email" name="reset_email" class="form-control" 
                                   placeholder="Enter your email address" required>
                            <i class="fas fa-envelope input-icon"></i>
                        </div>
                    </div>

                    <button type="submit" name="reset_password" class="btn">
                        Send Reset Instructions
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Password visibility toggle
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.parentElement.querySelector('.password-toggle');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Show password reset form
        function showResetForm() {
            document.getElementById('loginForm').classList.add('hidden');
            document.getElementById('resetForm').classList.add('active');
        }

        // Show login form
        function showLoginForm() {
            document.getElementById('resetForm').classList.remove('active');
            document.getElementById('loginForm').classList.remove('hidden');
        }

        // Form submission with loading state
        document.querySelector('form').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            if (btn) {
                btn.classList.add('btn-loading');
                btn.querySelector('span').textContent = 'Signing In...';
            }
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });

        // Enhanced form validation
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('.form-control');
            
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (this.value.trim() === '' && this.hasAttribute('required')) {
                        this.style.borderColor = '#E84393';
                        this.style.boxShadow = '0 0 0 3px rgba(232, 67, 147, 0.1)';
                    } else {
                        this.style.borderColor = '#E9ECEF';
                        this.style.boxShadow = 'none';
                    }
                });
                
                input.addEventListener('input', function() {
                    if (this.style.borderColor === 'rgb(232, 67, 147)') {
                        this.style.borderColor = '#E9ECEF';
                        this.style.boxShadow = 'none';
                    }
                });
            });
        });
    </script>
</body>
</html>