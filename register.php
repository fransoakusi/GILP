<?php
/**
 * Registration Page for Girls Leadership Program
 * Beautiful, responsive registration interface with validation
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(__FILE__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Check if already logged in
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

// Handle registration form submission
$registerError = '';
$registerSuccess = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // Store form data for repopulation
    $formData = [
        'username' => sanitizeInput($_POST['username'] ?? ''),
        'email' => sanitizeInput($_POST['email'] ?? ''),
        'first_name' => sanitizeInput($_POST['first_name'] ?? ''),
        'last_name' => sanitizeInput($_POST['last_name'] ?? ''),
        'phone' => sanitizeInput($_POST['phone'] ?? ''),
        'role' => sanitizeInput($_POST['role'] ?? 'participant')
    ];
    
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $registerError = 'Security token mismatch. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Basic validation
        if (empty($formData['username']) || empty($formData['email']) || 
            empty($formData['first_name']) || empty($formData['last_name']) || 
            empty($password) || empty($confirmPassword)) {
            $registerError = 'Please fill in all required fields.';
        } elseif ($password !== $confirmPassword) {
            $registerError = 'Passwords do not match.';
        } elseif (!isset($_POST['terms'])) {
            $registerError = 'Please accept the terms and conditions.';
        } else {
            // Prepare registration data
            $registrationData = array_merge($formData, ['password' => $password]);
            
            $result = auth()->register($registrationData);
            
            if ($result['success']) {
                $registerSuccess = $result['message'] . ' You can now log in with your credentials.';
                $formData = []; // Clear form data on success
                // Redirect to login after short delay
                header("refresh:3;url=login.php");
            } else {
                $registerError = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo APP_NAME; ?></title>
    
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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
            --shadow: 0 10px 30px rgba(108, 92, 231, 0.15);
            --shadow-hover: 0 15px 40px rgba(108, 92, 231, 0.25);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-color);
            overflow-x: hidden;
            padding: 20px 0;
        }
        
        /* Animated background elements */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }
        
        .bg-animation::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="0.5" fill="rgba(255,255,255,0.1)"/></svg>') repeat;
            animation: float 25s linear infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); }
            100% { transform: translateY(-100px) rotate(360deg); }
        }
        
        .register-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 40px;
            width: 100%;
            max-width: 600px;
            margin: 20px;
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.8s ease-out;
        }
        
        .register-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--accent-color), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo {
            font-size: 64px;
            margin-bottom: 10px;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        
        .logo-text {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .logo-subtitle {
            color: var(--secondary-color);
            font-size: 16px;
            font-weight: 500;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-group label .required {
            color: var(--danger-color);
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-color);
            transition: all 0.3s ease;
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #E0E6ED;
            border-radius: 12px;
            font-size: 16px;
            background: var(--white);
            transition: all 0.3s ease;
            outline: none;
        }
        
        .form-select {
            cursor: pointer;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
            transform: translateY(-2px);
        }
        
        .form-control:focus + .input-wrapper i,
        .form-select:focus + .input-wrapper i {
            color: var(--primary-color);
            transform: translateY(-50%) scale(1.1);
        }
        
        .password-strength {
            margin-top: 8px;
            font-size: 12px;
        }
        
        .strength-meter {
            height: 4px;
            background: #E0E6ED;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak { background: var(--danger-color); width: 25%; }
        .strength-fair { background: var(--warning-color); width: 50%; }
        .strength-good { background: var(--success-color); width: 75%; }
        .strength-strong { background: var(--primary-color); width: 100%; }
        
        .checkbox-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 20px 0;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
            margin-top: 2px;
        }
        
        .checkbox-wrapper label {
            font-size: 14px;
            color: var(--dark-color);
            margin: 0;
            cursor: pointer;
            line-height: 1.4;
        }
        
        .checkbox-wrapper a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        
        .checkbox-wrapper a:hover {
            text-decoration: underline;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid;
            animation: slideInRight 0.5s ease-out;
        }
        
        .alert-success {
            background: rgba(0, 184, 148, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }
        
        .alert-danger {
            background: rgba(232, 67, 147, 0.1);
            border-color: var(--danger-color);
            color: var(--danger-color);
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .divider {
            text-align: center;
            margin: 30px 0;
            position: relative;
            color: var(--secondary-color);
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #E0E6ED;
        }
        
        .divider span {
            background: rgba(255, 255, 255, 0.95);
            padding: 0 20px;
            font-weight: 500;
        }
        
        .links {
            text-align: center;
            margin-top: 25px;
        }
        
        .links a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent-color);
            transition: width 0.3s ease;
        }
        
        .links a:hover::after {
            width: 100%;
        }
        
        .back-to-home {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.9);
            color: var(--primary-color);
            padding: 10px 15px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .back-to-home:hover {
            background: var(--white);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        /* Loading animation for form submission */
        .btn.loading {
            pointer-events: none;
            position: relative;
        }
        
        .btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid var(--white);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .register-container {
                margin: 10px;
                padding: 30px 25px;
            }
            
            .logo {
                font-size: 48px;
            }
            
            .logo-text {
                font-size: 24px;
            }
            
            .back-to-home {
                top: 10px;
                left: 10px;
                padding: 8px 12px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .form-control, .form-select {
                padding: 12px 12px 12px 40px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation"></div>
    
    <a href="index.php" class="back-to-home">
        <i class="fas fa-arrow-left"></i> Back to Home
    </a>
    
    <div class="register-container">
        <div class="logo-section">
            <div class="logo">🌟</div>
            <h1 class="logo-text">Join the Journey</h1>
            <p class="logo-subtitle">Start your leadership development today</p>
        </div>
        
        <?php if (!empty($registerError)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($registerError); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($registerSuccess)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($registerSuccess); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="registerForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input 
                            type="text" 
                            id="first_name" 
                            name="first_name" 
                            class="form-control" 
                            placeholder="Your first name"
                            value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>"
                            required
                            autofocus
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Last Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input 
                            type="text" 
                            id="last_name" 
                            name="last_name" 
                            class="form-control" 
                            placeholder="Your last name"
                            value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>"
                            required
                        >
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="username">Username <span class="required">*</span></label>
                <div class="input-wrapper">
                    <i class="fas fa-at"></i>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-control" 
                        placeholder="Choose a unique username"
                        value="<?php echo htmlspecialchars($formData['username'] ?? ''); ?>"
                        required
                    >
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address <span class="required">*</span></label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="your.email@example.com"
                        value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"
                        required
                    >
                </div>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <div class="input-wrapper">
                    <i class="fas fa-phone"></i>
                    <input 
                        type="tel" 
                        id="phone" 
                        name="phone" 
                        class="form-control" 
                        placeholder="Your phone number"
                        value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>"
                    >
                </div>
            </div>
            
            <div class="form-group">
                <label for="role">Role <span class="required">*</span></label>
                <div class="input-wrapper">
                    <i class="fas fa-users"></i>
                    <select id="role" name="role" class="form-select" required>
                        <option value="participant" <?php echo ($formData['role'] ?? 'participant') === 'participant' ? 'selected' : ''; ?>>
                            Participant - Join leadership programs
                        </option>
                        <option value="mentor" <?php echo ($formData['role'] ?? '') === 'mentor' ? 'selected' : ''; ?>>
                            Mentor - Guide and support participants
                        </option>
                        <option value="volunteer" <?php echo ($formData['role'] ?? '') === 'volunteer' ? 'selected' : ''; ?>>
                            Volunteer - Assist with programs
                        </option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="Create a strong password"
                            required
                        >
                    </div>
                    <div class="password-strength">
                        <div class="strength-text">Password strength: <span id="strengthText">Enter password</span></div>
                        <div class="strength-meter">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock-check"></i>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-control" 
                            placeholder="Confirm your password"
                            required
                        >
                    </div>
                    <div id="passwordMatch" style="font-size: 12px; margin-top: 5px;"></div>
                </div>
            </div>
            
            <div class="checkbox-wrapper">
                <input type="checkbox" id="terms" name="terms" required>
                <label for="terms">
                    I agree to the <a href="#" onclick="alert('Terms and Conditions would be displayed here')">Terms and Conditions</a> 
                    and <a href="#" onclick="alert('Privacy Policy would be displayed here')">Privacy Policy</a> 
                    of the Girls Leadership Program. <span class="required">*</span>
                </label>
            </div>
            
            <button type="submit" name="register" class="btn" id="registerBtn">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>
        
        <div class="divider">
            <span>Already have an account?</span>
        </div>
        
        <div class="links">
            <a href="login.php">
                <i class="fas fa-sign-in-alt"></i> Sign In Instead
            </a>
        </div>
    </div>
    
    <script>
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            
            strengthFill.className = 'strength-fill strength-' + strength.level;
            strengthText.textContent = strength.text;
        });
        
        function calculatePasswordStrength(password) {
            let score = 0;
            let text = 'Weak';
            let level = 'weak';
            
            if (password.length >= 8) score += 1;
            if (password.length >= 12) score += 1;
            if (/[a-z]/.test(password)) score += 1;
            if (/[A-Z]/.test(password)) score += 1;
            if (/[0-9]/.test(password)) score += 1;
            if (/[^A-Za-z0-9]/.test(password)) score += 1;
            
            if (score < 3) {
                text = 'Weak';
                level = 'weak';
            } else if (score < 4) {
                text = 'Fair';
                level = 'fair';
            } else if (score < 5) {
                text = 'Good';
                level = 'good';
            } else {
                text = 'Strong';
                level = 'strong';
            }
            
            return { text, level };
        }
        
        // Password confirmation checker
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordMatchDiv = document.getElementById('passwordMatch');
        
        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (confirmPassword === '') {
                passwordMatchDiv.textContent = '';
                return;
            }
            
            if (password === confirmPassword) {
                passwordMatchDiv.innerHTML = '<span style="color: var(--success-color);"><i class="fas fa-check"></i> Passwords match</span>';
            } else {
                passwordMatchDiv.innerHTML = '<span style="color: var(--danger-color);"><i class="fas fa-times"></i> Passwords do not match</span>';
            }
        }
        
        passwordInput.addEventListener('input', checkPasswordMatch);
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        
        // Form submission handling
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return;
            }
            
            const btn = document.getElementById('registerBtn');
            btn.classList.add('loading');
            btn.innerHTML = '<i class="fas fa-user-plus"></i> Creating Account...';
        });
        
        // Add focus effects
        const inputs = document.querySelectorAll('.form-control, .form-select');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
        
        // Username availability check (placeholder for future enhancement)
        const usernameInput = document.getElementById('username');
        let usernameTimeout;
        
        usernameInput.addEventListener('input', function() {
            clearTimeout(usernameTimeout);
            const username = this.value;
            
            if (username.length >= 3) {
                usernameTimeout = setTimeout(() => {
                    // Here you would implement AJAX call to check username availability
                    console.log('Checking availability for:', username);
                }, 500);
            }
        });
        
        // Shake animation for errors
        <?php if (!empty($registerError)): ?>
        document.querySelector('.register-container').style.animation = 'shake 0.5s ease-in-out';
        
        const shakeKeyframes = `
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-5px); }
                75% { transform: translateX(5px); }
            }
        `;
        
        const style = document.createElement('style');
        style.textContent = shakeKeyframes;
        document.head.appendChild(style);
        <?php endif; ?>
        
        // Auto-redirect on success
        <?php if (!empty($registerSuccess)): ?>
        setTimeout(() => {
            const btn = document.getElementById('registerBtn');
            btn.classList.add('loading');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Redirecting to Login...';
        }, 2000);
        <?php endif; ?>
    </script>
</body>
</html>