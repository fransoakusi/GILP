<?php
/**
 * Logout Handler for Girls Leadership Program
 * Secure logout with session cleanup
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(__FILE__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Handle logout
$loggedOut = false;
$userName = '';

if (isLoggedIn()) {
    $currentUser = getCurrentUser();
    $userName = $currentUser ? $currentUser['first_name'] : 'User';
    
    // Perform logout
    $result = auth()->logout();
    $loggedOut = $result['success'];
} else {
    $loggedOut = true; // Already logged out
}

// Auto-redirect after 3 seconds
$redirectDelay = 3;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out - <?php echo APP_NAME; ?></title>
    
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Auto-redirect after delay -->
    <meta http-equiv="refresh" content="<?php echo $redirectDelay; ?>;url=index.php">
    
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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-color);
            overflow: hidden;
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
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="1" fill="rgba(255,255,255,0.1)"/></svg>') repeat;
            animation: float 20s linear infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); }
            100% { transform: translateY(-100px) rotate(360deg); }
        }
        
        .logout-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 60px 40px;
            width: 100%;
            max-width: 500px;
            text-align: center;
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.8s ease-out;
        }
        
        .logout-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--success-color), transparent);
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
        
        .logout-icon {
            font-size: 80px;
            color: var(--success-color);
            margin-bottom: 30px;
            animation: bounceIn 1s ease-out;
        }
        
        @keyframes bounceIn {
            0% {
                opacity: 0;
                transform: scale(0.3);
            }
            50% {
                opacity: 1;
                transform: scale(1.05);
            }
            70% {
                transform: scale(0.9);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .logout-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 15px;
            animation: fadeIn 1s ease-out 0.3s both;
        }
        
        .logout-message {
            font-size: 1.2rem;
            color: var(--secondary-color);
            margin-bottom: 30px;
            line-height: 1.6;
            animation: fadeIn 1s ease-out 0.6s both;
        }
        
        .personal-message {
            background: linear-gradient(135deg, var(--success-color), #00a085);
            color: var(--white);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            animation: fadeIn 1s ease-out 0.9s both;
        }
        
        .personal-message .icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .redirect-info {
            background: var(--light-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
            animation: fadeIn 1s ease-out 1.2s both;
        }
        
        .countdown {
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 10px 0;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .redirect-text {
            color: var(--dark-color);
            font-weight: 500;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeIn 1s ease-out 1.5s both;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: var(--white);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }
        
        .btn-outline:hover {
            background: var(--primary-color);
            color: var(--white);
            transform: translateY(-2px);
        }
        
        .security-note {
            margin-top: 30px;
            padding: 15px;
            background: rgba(108, 92, 231, 0.1);
            border-radius: 10px;
            font-size: 14px;
            color: var(--dark-color);
            animation: fadeIn 1s ease-out 1.8s both;
        }
        
        .security-note i {
            color: var(--primary-color);
            margin-right: 8px;
        }
        
        /* Progress ring for countdown */
        .countdown-ring {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 20px auto;
        }
        
        .countdown-ring svg {
            width: 120px;
            height: 120px;
            transform: rotate(-90deg);
        }
        
        .countdown-ring circle {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
        }
        
        .countdown-ring .background {
            stroke: var(--light-color);
        }
        
        .countdown-ring .progress {
            stroke: var(--primary-color);
            stroke-dasharray: 314;
            stroke-dashoffset: 314;
            animation: countdown 3s linear;
        }
        
        @keyframes countdown {
            from { stroke-dashoffset: 314; }
            to { stroke-dashoffset: 0; }
        }
        
        .countdown-number {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        /* Mobile responsive */
        @media (max-width: 480px) {
            .logout-container {
                margin: 20px;
                padding: 40px 30px;
            }
            
            .logout-title {
                font-size: 2rem;
            }
            
            .logout-message {
                font-size: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation"></div>
    
    <div class="logout-container">
        <?php if ($loggedOut): ?>
            <div class="logout-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            
            <h1 class="logout-title">Successfully Logged Out</h1>
            
            <?php if (!empty($userName)): ?>
                <div class="personal-message">
                    <div class="icon">👋</div>
                    <div>Thank you, <strong><?php echo htmlspecialchars($userName); ?></strong>!</div>
                    <div style="font-size: 0.9rem; margin-top: 5px; opacity: 0.9;">
                        We hope to see you back soon on your leadership journey.
                    </div>
                </div>
            <?php endif; ?>
            
            <p class="logout-message">
                Your session has been securely terminated. All your data and progress have been saved.
            </p>
            
            <div class="redirect-info">
                <div class="countdown-ring">
                    <svg>
                        <circle class="background" cx="60" cy="60" r="50"></circle>
                        <circle class="progress" cx="60" cy="60" r="50"></circle>
                    </svg>
                    <div class="countdown-number" id="countdown"><?php echo $redirectDelay; ?></div>
                </div>
                <div class="redirect-text">
                    Redirecting to home page in <span id="countdownText"><?php echo $redirectDelay; ?></span> seconds...
                </div>
            </div>
            
            <div class="action-buttons">
                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i>
                    Login Again
                </a>
                
                <a href="index.php" class="btn btn-outline">
                    <i class="fas fa-home"></i>
                    Go to Home
                </a>
            </div>
            
            <div class="security-note">
                <i class="fas fa-shield-alt"></i>
                <strong>Security Note:</strong> For your protection, please close all browser windows if you're using a shared computer.
            </div>
            
        <?php else: ?>
            <div class="logout-icon" style="color: var(--warning-color);">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            
            <h1 class="logout-title">Logout Issue</h1>
            
            <p class="logout-message">
                There was an issue with the logout process. You may already be logged out.
            </p>
            
            <div class="action-buttons">
                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i>
                    Try Login
                </a>
                
                <a href="index.php" class="btn btn-outline">
                    <i class="fas fa-home"></i>
                    Go to Home
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Countdown timer
        let timeLeft = <?php echo $redirectDelay; ?>;
        const countdownElement = document.getElementById('countdown');
        const countdownTextElement = document.getElementById('countdownText');
        
        function updateCountdown() {
            if (timeLeft > 0) {
                countdownElement.textContent = timeLeft;
                countdownTextElement.textContent = timeLeft;
                timeLeft--;
                setTimeout(updateCountdown, 1000);
            } else {
                countdownElement.textContent = '0';
                countdownTextElement.textContent = '0';
                window.location.href = 'index.php';
            }
        }
        
        // Start countdown after page loads
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(updateCountdown, 1000);
        });
        
        // Add click effects to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                // Create ripple effect
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    background: rgba(255, 255, 255, 0.5);
                    border-radius: 50%;
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    pointer-events: none;
                `;
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });
        
        // Add ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
        
        // Log activity for analytics
        console.log('User logged out at:', new Date().toISOString());
        
        // Clear any cached data
        if ('caches' in window) {
            caches.keys().then(names => {
                names.forEach(name => {
                    if (name.includes('user-data')) {
                        caches.delete(name);
                    }
                });
            });
        }
        
        // Clear localStorage if it contains user data
        try {
            const userKeys = ['user_preferences', 'user_cache', 'dashboard_state'];
            userKeys.forEach(key => {
                if (localStorage.getItem(key)) {
                    localStorage.removeItem(key);
                }
            });
        } catch (e) {
            // localStorage might not be available
            console.log('Could not clear localStorage:', e.message);
        }
    </script>
</body>
</html>