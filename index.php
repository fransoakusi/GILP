<?php
/**
 * Landing Page for Girls Leadership Program
 * Monday.com inspired design with feature showcase
 */

// Define APP_ROOT
define('APP_ROOT', dirname(__FILE__));

// Basic error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if configuration exists
$configExists = file_exists(APP_ROOT . '/config/config.php');
$dbConfigExists = file_exists(APP_ROOT . '/config/database.php');
$authConfigExists = file_exists(APP_ROOT . '/config/auth.php');

$allConfigExists = $configExists && $dbConfigExists && $authConfigExists;

// If config exists, try to load it
if ($allConfigExists) {
    try {
        require_once APP_ROOT . '/config/config.php';
        $systemReady = true;
    } catch (Exception $e) {
        $systemReady = false;
        $configError = $e->getMessage();
    }
} else {
    $systemReady = false;
}

// Test database connection if system ready
$dbStatus = false;
$dbError = '';
if ($systemReady) {
    try {
        $dbTest = Database::testConnection();
        $dbStatus = $dbTest;
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $systemReady ? APP_NAME : 'Girls Leadership Program Manager'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-purple: #6c5ce7;
            --primary-pink: #fd79a8;
            --primary-blue: #74b9ff;
            --success-green: #00b894;
            --warning-orange: #fdcb6e;
            --danger-red: #e84393;
            --dark-text: #2d3436;
            --light-gray: #f8f9fa;
            --medium-gray: #636e72;
            --white: #ffffff;
            --shadow-light: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 40px rgba(0, 0, 0, 0.12);
            --shadow-heavy: 0 16px 60px rgba(0, 0, 0, 0.15);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.6;
            color: var(--dark-text);
            overflow-x: hidden;
            background: var(--white);
        }
        
        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            z-index: 1000;
            padding: 1rem 0;
            transition: all 0.3s ease;
        }
        
        .navbar.scrolled {
            box-shadow: var(--shadow-light);
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-purple);
            text-decoration: none;
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .nav-link {
            color: var(--dark-text);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .nav-link:hover {
            color: var(--primary-purple);
        }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--primary-pink) 50%, var(--primary-blue) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            padding-top: 80px;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="a" cx="50%" cy="50%"><stop offset="0%" stop-color="rgba(255,255,255,0.1)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient></defs><circle cx="200" cy="200" r="100" fill="url(%23a)"/><circle cx="800" cy="300" r="150" fill="url(%23a)"/><circle cx="400" cy="700" r="120" fill="url(%23a)"/></svg>');
            opacity: 0.3;
            animation: float 20s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(5deg); }
            66% { transform: translateY(10px) rotate(-3deg); }
        }
        
        .hero-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            position: relative;
            z-index: 2;
        }
        
        .hero-content {
            animation: slideInFromLeft 1s ease-out;
        }
        
        @keyframes slideInFromLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }
        
        .hero-subtitle {
            font-size: 1.3rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            font-weight: 400;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
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
            background: var(--white);
            color: var(--primary-purple);
            box-shadow: var(--shadow-medium);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-heavy);
        }
        
        .btn-secondary {
            background: transparent;
            color: var(--white);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-3px);
        }
        
        .hero-visual {
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            animation: slideInFromRight 1s ease-out;
        }
        
        @keyframes slideInFromRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .hero-icon {
            font-size: 15rem;
            color: rgba(255, 255, 255, 0.1);
            animation: pulse 3s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.1; transform: scale(1); }
            50% { opacity: 0.2; transform: scale(1.05); }
        }
        
        /* Status Section */
        .status-section {
            padding: 6rem 0;
            background: var(--light-gray);
        }
        
        .status-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }
        
        .status-card {
            background: var(--white);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--shadow-light);
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }
        
        .status-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-purple), var(--primary-pink));
        }
        
        .status-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-heavy);
        }
        
        .status-card.ready::before {
            background: linear-gradient(90deg, var(--success-green), #00d2a4);
        }
        
        .status-card.warning::before {
            background: linear-gradient(90deg, var(--warning-orange), #ffa726);
        }
        
        .status-card.error::before {
            background: linear-gradient(90deg, var(--danger-red), #ff6b9d);
        }
        
        .status-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            animation: bounceIn 1s ease-out;
        }
        
        @keyframes bounceIn {
            0% { opacity: 0; transform: scale(0.3); }
            50% { opacity: 1; transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { opacity: 1; transform: scale(1); }
        }
        
        .status-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark-text);
        }
        
        .status-description {
            color: var(--medium-gray);
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
        
        .checklist {
            text-align: left;
            margin: 1.5rem 0;
        }
        
        .checklist li {
            margin: 0.5rem 0;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .check-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            color: var(--white);
        }
        
        .check-good .check-icon {
            background: var(--success-green);
        }
        
        .check-bad .check-icon {
            background: var(--danger-red);
        }
        
        /* Features Section */
        .features-section {
            padding: 8rem 0;
            background: var(--white);
        }
        
        .features-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        
        .section-header {
            text-align: center;
            margin-bottom: 5rem;
        }
        
        .section-title {
            font-size: 3rem;
            font-weight: 800;
            color: var(--dark-text);
            margin-bottom: 1rem;
        }
        
        .section-subtitle {
            font-size: 1.2rem;
            color: var(--medium-gray);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 3rem;
        }
        
        .feature-card {
            background: var(--white);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--shadow-light);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-purple), var(--primary-pink));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .feature-card:hover::before {
            transform: scaleX(1);
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-heavy);
        }
        
        .feature-icon {
            font-size: 3rem;
            color: var(--primary-purple);
            margin-bottom: 1.5rem;
            display: block;
        }
        
        .feature-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark-text);
        }
        
        .feature-description {
            color: var(--medium-gray);
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        
        .feature-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .feature-list li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0;
            color: var(--medium-gray);
        }
        
        .feature-list li::before {
            content: '✓';
            color: var(--success-green);
            font-weight: bold;
        }
        
        /* CTA Section */
        .cta-section {
            padding: 6rem 0;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--primary-pink) 100%);
            position: relative;
            overflow: hidden;
        }
        
        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><circle cx="100" cy="100" r="50" fill="rgba(255,255,255,0.1)"/><circle cx="900" cy="200" r="80" fill="rgba(255,255,255,0.1)"/><circle cx="200" cy="800" r="60" fill="rgba(255,255,255,0.1)"/></svg>');
            animation: float 15s ease-in-out infinite reverse;
        }
        
        .cta-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .cta-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 1rem;
        }
        
        .cta-subtitle {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2.5rem;
        }
        
        /* Footer */
        .footer {
            padding: 2rem 0;
            background: var(--dark-text);
            color: var(--white);
            text-align: center;
        }
        
        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        
        .footer-text {
            opacity: 0.8;
            margin-bottom: 0.5rem;
        }
        
        .footer-tech {
            opacity: 0.6;
            font-size: 0.9rem;
        }
        
        /* Animations */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }
        
        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-container {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 2rem;
            }
            
            .hero-title {
                font-size: 2.5rem;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .cta-title {
                font-size: 2rem;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .nav-links {
                display: none;
            }
            
            .hero-icon {
                font-size: 8rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar" id="navbar">
        <div class="nav-container">
            <a href="#" class="logo">
                <span>👩‍💼</span>
                <span><?php echo $systemReady ? APP_NAME : 'Girls Leadership Program'; ?></span>
            </a>
            <div class="nav-links">
                <a href="#features" class="nav-link">Features</a>
                <a href="#status" class="nav-link">System Status</a>
                <?php if ($systemReady && $dbStatus): ?>
                    <a href="login.php" class="btn btn-primary">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-container">
            <div class="hero-content">
                <h1 class="hero-title">Learn in Girls Leadership</h1>
                <p class="hero-subtitle">
                    A comprehensive platform designed to nurture, guide, and track the development of future female leaders through structured programs and mentorship.
                </p>
                <div class="hero-buttons">
                    <?php if ($systemReady && $dbStatus): ?>
                        <a href="login.php" class="btn btn-primary">
                            <i class="fas fa-rocket"></i>
                            Get Started
                        </a>
                        <a href="register.php" class="btn btn-secondary">
                            <i class="fas fa-user-plus"></i>
                            Join Program
                        </a>
                    <?php else: ?>
                        <a href="#status" class="btn btn-primary">
                            <i class="fas fa-cog"></i>
                            System Setup
                        </a>
                        <a href="tests/foundation_test.php" class="btn btn-secondary">
                            <i class="fas fa-diagnostics"></i>
                            Run Diagnostics
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-visual">
                <i class="fas fa-users hero-icon"></i>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="features-container">
            <div class="section-header fade-in">
                <h2 class="section-title">Powerful Features for Leadership Development</h2>
                <p class="section-subtitle">
                    Everything you need to run a successful girls leadership program, from mentorship to progress tracking.
                </p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card fade-in">
                    <i class="fas fa-users-cog feature-icon"></i>
                    <h3 class="feature-title">User Management</h3>
                    <p class="feature-description">
                        Comprehensive role-based system supporting administrators, mentors, participants, and volunteers.
                    </p>
                    <ul class="feature-list">
                        <li>Role-based access control</li>
                        <li>Profile management</li>
                        <li>User activity tracking</li>
                        <li>Automated onboarding</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-tasks feature-icon"></i>
                    <h3 class="feature-title">Assignment Management</h3>
                    <p class="feature-description">
                        Create, assign, and track assignments with automated notifications and progress monitoring.
                    </p>
                    <ul class="feature-list">
                        <li>Assignment creation & distribution</li>
                        <li>Submission tracking</li>
                        <li>Automated grading system</li>
                        <li>Feedback management</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-project-diagram feature-icon"></i>
                    <h3 class="feature-title">Project Collaboration</h3>
                    <p class="feature-description">
                        Facilitate group projects with real-time collaboration tools and progress tracking.
                    </p>
                    <ul class="feature-list">
                        <li>Project creation & management</li>
                        <li>Team collaboration tools</li>
                        <li>Milestone tracking</li>
                        <li>Status updates</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-graduation-cap feature-icon"></i>
                    <h3 class="feature-title">Training Modules</h3>
                    <p class="feature-description">
                        Structured learning paths with interactive sessions and skill development tracking.
                    </p>
                    <ul class="feature-list">
                        <li>Session scheduling</li>
                        <li>Training materials</li>
                        <li>Attendance tracking</li>
                        <li>Skill assessments</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-heart feature-icon"></i>
                    <h3 class="feature-title">Mentorship Program</h3>
                    <p class="feature-description">
                        Connect participants with experienced mentors for personalized guidance and support.
                    </p>
                    <ul class="feature-list">
                        <li>Mentor-mentee matching</li>
                        <li>Progress monitoring</li>
                        <li>Communication tools</li>
                        <li>Goal setting & tracking</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-poll feature-icon"></i>
                    <h3 class="feature-title">Surveys & Feedback</h3>
                    <p class="feature-description">
                        Gather insights and feedback through customizable surveys and assessment tools.
                    </p>
                    <ul class="feature-list">
                        <li>Dynamic survey builder</li>
                        <li>Anonymous responses</li>
                        <li>Real-time analytics</li>
                        <li>Feedback analysis</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- System Status Section -->
    <section class="status-section" id="status">
        <div class="status-container">
            <div class="section-header fade-in">
                <h2 class="section-title">System Status</h2>
                <p class="section-subtitle">Current status of your Girls Leadership Program platform</p>
            </div>
            
            <div class="status-grid">
                <?php if (!$allConfigExists): ?>
                    <div class="status-card error fade-in">
                        <div class="status-icon">⚙️</div>
                        <h3 class="status-title">Setup Required</h3>
                        <p class="status-description">Configuration files are missing. Please create the required files first.</p>
                        
                        <div class="checklist">
                            <ul>
                                <li class="<?php echo $configExists ? 'check-good' : 'check-bad'; ?>">
                                    <span class="check-icon"><?php echo $configExists ? '✓' : '✗'; ?></span>
                                    config/config.php
                                </li>
                                <li class="<?php echo $dbConfigExists ? 'check-good' : 'check-bad'; ?>">
                                    <span class="check-icon"><?php echo $dbConfigExists ? '✓' : '✗'; ?></span>
                                    config/database.php
                                </li>
                                <li class="<?php echo $authConfigExists ? 'check-good' : 'check-bad'; ?>">
                                    <span class="check-icon"><?php echo $authConfigExists ? '✓' : '✗'; ?></span>
                                    config/auth.php
                                </li>
                            </ul>
                        </div>
                        
                        <a href="#" onclick="alert('Please create the configuration files first using the setup guide.')" class="btn btn-secondary">
                            <i class="fas fa-book"></i>
                            Setup Guide
                        </a>
                    </div>
                    
                <?php elseif (!$systemReady): ?>
                    <div class="status-card warning fade-in">
                        <div class="status-icon">⚠️</div>
                        <h3 class="status-title">Configuration Error</h3>
                        <p class="status-description">Configuration files exist but there's an error loading them:</p>
                        <code style="color: var(--danger-red); font-size: 0.9rem; background: var(--light-gray); padding: 0.5rem; border-radius: 5px; display: block; margin: 1rem 0;">
                            <?php echo htmlspecialchars($configError ?? 'Unknown error'); ?>
                        </code>
                        
                        <a href="tests/foundation_test.php" class="btn btn-secondary">
                            <i class="fas fa-bug"></i>
                            Run Diagnostics
                        </a>
                    </div>
                    
                <?php else: ?>
                    <?php if ($dbStatus): ?>
                        <div class="status-card ready fade-in">
                            <div class="status-icon">🎉</div>
                            <h3 class="status-title">System Ready!</h3>
                            <p class="status-description">
                                All core systems are operational and ready for use.
                            </p>
                            <p><strong>Version:</strong> <?php echo APP_VERSION; ?></p>
                            
                            <div style="margin-top: 2rem;">
                                <a href="login.php" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt"></i>
                                    Login to Dashboard
                                </a>
                            </div>
                        </div>
                        
                        <div class="status-card ready fade-in">
                            <div class="status-icon">🔐</div>
                            <h3 class="status-title">Security Active</h3>
                            <p class="status-description">
                                Authentication and security systems are fully operational.
                            </p>
                            
                            <div style="margin-top: 2rem;">
                                <a href="register.php" class="btn btn-secondary">
                                    <i class="fas fa-user-plus"></i>
                                    Register Account
                                </a>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <div class="status-card warning fade-in">
                            <div class="status-icon">🔧</div>
                            <h3 class="status-title">Database Setup Needed</h3>
                            <p class="status-description">Configuration loaded but database connection failed.</p>
                            <?php if ($dbError): ?>
                                <code style="color: var(--danger-red); font-size: 0.9rem; background: var(--light-gray); padding: 0.5rem; border-radius: 5px; display: block; margin: 1rem 0;">
                                    <?php echo htmlspecialchars($dbError); ?>
                                </code>
                            <?php endif; ?>
                            
                            <div class="checklist">
                                <h4>Setup Checklist:</h4>
                                <ul>
                                    <li class="check-bad">
                                        <span class="check-icon">✗</span>
                                        Create MySQL database 'girls_leadership_db'
                                    </li>
                                    <li class="check-bad">
                                        <span class="check-icon">✗</span>
                                        Import the SQL schema
                                    </li>
                                    <li class="check-bad">
                                        <span class="check-icon">✗</span>
                                        Update database credentials
                                    </li>
                                    <li class="check-bad">
                                        <span class="check-icon">✗</span>
                                        Ensure MySQL server is running
                                    </li>
                                </ul>
                            </div>
                            
                            <a href="tests/foundation_test.php" class="btn btn-secondary">
                                <i class="fas fa-database"></i>
                                Test Database
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="status-card fade-in">
                    <div class="status-icon">🧪</div>
                    <h3 class="status-title">System Diagnostics</h3>
                    <p class="status-description">
                        Run comprehensive tests to verify all components are working correctly.
                    </p>
                    
                    <div style="margin-top: 2rem;">
                        <a href="tests/foundation_test.php" class="btn btn-secondary">
                            <i class="fas fa-stethoscope"></i>
                            Run Full Test Suite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="cta-container">
            <h2 class="cta-title">Ready to Empower Future Leaders?</h2>
            <p class="cta-subtitle">
                Join thousands of mentors and participants building the next generation of female leaders.
            </p>
            
            <?php if ($systemReady && $dbStatus): ?>
                <div class="hero-buttons">
                    <a href="register.php" class="btn btn-primary">
                        <i class="fas fa-rocket"></i>
                        Join the Program
                    </a>
                    <a href="login.php" class="btn btn-secondary">
                        <i class="fas fa-sign-in-alt"></i>
                        Access Dashboard
                    </a>
                </div>
            <?php else: ?>
                <div class="hero-buttons">
                    <a href="#status" class="btn btn-primary">
                        <i class="fas fa-cog"></i>
                        Complete Setup
                    </a>
                    <a href="tests/foundation_test.php" class="btn btn-secondary">
                        <i class="fas fa-diagnostics"></i>
                        System Diagnostics
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <p class="footer-text">Built for empowering future female leaders 💪</p>
            <p class="footer-tech">
                PHP <?php echo PHP_VERSION; ?> | 
                Debug Mode: <?php echo defined('DEBUG_MODE') && DEBUG_MODE ? 'ON' : 'OFF'; ?> |
                <?php echo $systemReady ? APP_NAME . ' v' . APP_VERSION : 'System Setup Required'; ?>
            </p>
        </div>
    </footer>

    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);

        // Observe all fade-in elements
        document.querySelectorAll('.fade-in').forEach(el => {
            observer.observe(el);
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add loading states to buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.href && !this.href.includes('#')) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    this.style.pointerEvents = 'none';
                    
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.pointerEvents = 'auto';
                    }, 2000);
                }
            });
        });
    </script>
</body>
</html>