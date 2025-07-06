<?php
/**
 * Dashboard for Girls Leadership Program
 * Role-based dashboard with beautiful interface
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(__FILE__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Require login
requireLogin();

// Get current user data
$currentUser = getCurrentUser();
if (!$currentUser) {
    redirect('/login.php', 'Session expired. Please log in again.', 'warning');
}

// Get user role for conditional content
$userRole = $currentUser['role'];
$userName = $currentUser['first_name'] . ' ' . $currentUser['last_name'];

// Get dashboard statistics based on role
$stats = [];
try {
    $db = Database::getInstance();
    
    if ($userRole === 'admin') {
        $stats = [
            'total_users' => $db->count('users'),
            'active_projects' => $db->count('projects', 'status = :status', [':status' => 'active']),
            'pending_assignments' => $db->count('assignments', 'status IN (:status1, :status2)', [
                ':status1' => 'assigned', 
                ':status2' => 'in_progress'
            ]),
            'total_sessions' => $db->count('training_sessions'),
        ];
    } elseif ($userRole === 'mentor') {
        $stats = [
            'my_mentees' => $db->count('mentor_assignments', 'mentor_id = :id AND status = :status', [
                ':id' => $currentUser['user_id'],
                ':status' => 'active'
            ]),
            'assignments_to_review' => $db->count('assignments', 'assigned_by = :id AND status = :status', [
                ':id' => $currentUser['user_id'],
                ':status' => 'submitted'
            ]),
            'my_projects' => $db->count('project_participants', 'user_id = :id', [
                ':id' => $currentUser['user_id']
            ]),
            'upcoming_sessions' => $db->count('training_sessions', 'instructor_id = :id AND session_date > NOW()', [
                ':id' => $currentUser['user_id']
            ]),
        ];
    } else { // participant or volunteer
        $stats = [
            'my_assignments' => $db->count('assignments', 'assigned_to = :id', [
                ':id' => $currentUser['user_id']
            ]),
            'completed_assignments' => $db->count('assignments', 'assigned_to = :id AND status = :status', [
                ':id' => $currentUser['user_id'],
                ':status' => 'completed'
            ]),
            'my_projects' => $db->count('project_participants', 'user_id = :id', [
                ':id' => $currentUser['user_id']
            ]),
            'upcoming_sessions' => $db->count('session_attendance', 'user_id = :id AND status = :status', [
                ':id' => $currentUser['user_id'],
                ':status' => 'registered'
            ]),
        ];
    }
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Get recent activities (placeholder - would be implemented with actual activity tracking)
$recentActivities = [
    ['icon' => 'fas fa-user-plus', 'text' => 'Welcome to the Girls Leadership Program!', 'time' => 'Just now', 'type' => 'success'],
    ['icon' => 'fas fa-book', 'text' => 'New training materials available', 'time' => '2 hours ago', 'type' => 'info'],
    ['icon' => 'fas fa-calendar', 'text' => 'Upcoming session: Leadership Basics', 'time' => '1 day ago', 'type' => 'warning'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    
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
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --shadow: 0 4px 20px rgba(108, 92, 231, 0.1);
            --shadow-hover: 0 8px 30px rgba(108, 92, 231, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--gray-100);
            color: var(--dark-color);
            line-height: 1.6;
        }
        
        /* Navigation */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 1rem 2rem;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .navbar-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--white);
            text-decoration: none;
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .navbar-brand .logo {
            font-size: 2rem;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .navbar-nav {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            transform: translateY(-2px);
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .user-avatar:hover {
            transform: scale(1.1);
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.3);
        }
        
        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .welcome-section {
            background: linear-gradient(135deg, var(--white), var(--gray-100));
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(108, 92, 231, 0.05) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(5deg); }
        }
        
        .welcome-content {
            position: relative;
            z-index: 2;
        }
        
        .welcome-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .welcome-subtitle {
            color: var(--gray-600);
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }
        
        .role-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: var(--accent-color);
            color: var(--white);
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-card.success::before { background: var(--success-color); }
        .stat-card.warning::before { background: var(--warning-color); }
        .stat-card.danger::before { background: var(--danger-color); }
        .stat-card.info::before { background: var(--secondary-color); }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--white);
        }
        
        .stat-icon.primary { background: var(--primary-color); }
        .stat-icon.success { background: var(--success-color); }
        .stat-icon.warning { background: var(--warning-color); }
        .stat-icon.danger { background: var(--danger-color); }
        .stat-icon.info { background: var(--secondary-color); }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-color);
            line-height: 1;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        .main-panel, .side-panel {
            background: var(--white);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            height: fit-content;
        }
        
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn:hover {
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
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .action-card {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--dark-color);
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
            color: var(--dark-color);
        }
        
        .action-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .action-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .action-desc {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        /* Recent Activities */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .activity-item:hover {
            background: var(--gray-100);
            border-left-color: var(--primary-color);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--white);
        }
        
        .activity-icon.success { background: var(--success-color); }
        .activity-icon.info { background: var(--secondary-color); }
        .activity-icon.warning { background: var(--warning-color); }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-text {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 4px;
            transition: width 0.8s ease;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .navbar-content {
                padding: 0 1rem;
            }
            
            .navbar-nav {
                gap: 1rem;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }
            
            .welcome-title {
                font-size: 1.5rem;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .navbar {
                padding: 0.75rem 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">
                <span class="logo">👩‍💼</span>
                <span><?php echo APP_NAME; ?></span>
            </a>
            
            <div class="navbar-nav">
                <?php if (hasUserPermission('project_management')): ?>
                    <a href="modules/projects/list.php" class="nav-link">
                        <i class="fas fa-project-diagram"></i> Projects
                    </a>
                <?php endif; ?>
                
                <?php if (hasUserPermission('assignment_management') || hasUserPermission('assignment_submission')): ?>
                    <a href="modules/assignments/list.php" class="nav-link">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                <?php endif; ?>
                
                <?php if (hasUserPermission('training_access')): ?>
                    <a href="modules/training/sessions.php" class="nav-link">
                        <i class="fas fa-graduation-cap"></i> Training
                    </a>
                <?php endif; ?>
                
                <?php if (hasUserPermission('communication')): ?>
                    <a href="modules/communication/messages.php" class="nav-link">
                        <i class="fas fa-comments"></i> Messages
                    </a>
                <?php endif; ?>
                
                <div class="user-menu">
                    <div class="user-avatar" onclick="toggleUserMenu()">
                        <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Welcome Section -->
        <section class="welcome-section">
            <div class="welcome-content">
                <h1 class="welcome-title">Welcome back, <?php echo htmlspecialchars($currentUser['first_name']); ?>! 👋</h1>
                <p class="welcome-subtitle">Ready to continue your leadership journey? Here's what's happening today.</p>
                <span class="role-badge"><?php echo getRoleDisplayName($userRole); ?></span>
            </div>
        </section>
        
        <!-- Statistics -->
        <section class="stats-grid">
            <?php if ($userRole === 'admin'): ?>
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-icon primary">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_users'] ?? 0; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-icon success">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_projects'] ?? 0; ?></div>
                    <div class="stat-label">Active Projects</div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-icon warning">
                            <i class="fas fa-tasks"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_assignments'] ?? 0; ?></div>
                    <div class="stat-label">Pending Tasks</div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-icon info">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_sessions'] ?? 0; ?></div>
                    <div class="stat-label">Training Sessions</div>
                </div>
                
            <?php elseif ($userRole === 'mentor'): ?>
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-icon primary">
                            <i class="fas fa-user-friends"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['my_mentees'] ?? 0; ?></div>
                    <div class="stat-label">My Mentees</div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-icon warning">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['assignments_to_review'] ?? 0; ?></div>
                    <div class="stat-label">To Review</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-icon success">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['my_projects'] ?? 0; ?></div>
                    <div class="stat-label">My Projects</div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-icon info">
                            <i class="fas fa-calendar"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['upcoming_sessions'] ?? 0; ?></div>
                    <div class="stat-label">Upcoming Sessions</div>
                </div>
                
            <?php else: ?>
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-icon primary">
                            <i class="fas fa-tasks"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['my_assignments'] ?? 0; ?></div>
                    <div class="stat-label">My Assignments</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['completed_assignments'] ?? 0; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-icon info">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['my_projects'] ?? 0; ?></div>
                    <div class="stat-label">My Projects</div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-icon warning">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['upcoming_sessions'] ?? 0; ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Quick Actions -->
        <section class="quick-actions">
            <?php if (hasUserPermission('user_management')): ?>
                <a href="modules/users/manage.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-users-cog"></i></div>
                    <div class="action-title">Manage Users</div>
                    <div class="action-desc">Add, edit, and manage user accounts</div>
                </a>
            <?php endif; ?>
            
            <?php if (hasUserPermission('project_management')): ?>
                <a href="modules/projects/create.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="action-title">New Project</div>
                    <div class="action-desc">Create a new leadership project</div>
                </a>
            <?php endif; ?>
            
            <?php if (hasUserPermission('assignment_management')): ?>
                <a href="modules/assignments/create.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-clipboard-list"></i></div>
                    <div class="action-title">Create Assignment</div>
                    <div class="action-desc">Assign tasks to participants</div>
                </a>
            <?php endif; ?>
            
            <?php if (hasUserPermission('training_management')): ?>
                <a href="modules/training/sessions.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="action-title">Schedule Training</div>
                    <div class="action-desc">Plan new training sessions</div>
                </a>
            <?php endif; ?>
            
            <a href="modules/users/profile.php" class="action-card">
                <div class="action-icon"><i class="fas fa-user-edit"></i></div>
                <div class="action-title">My Profile</div>
                <div class="action-desc">Update your profile information</div>
            </a>
            
            <?php if (hasUserPermission('survey_management')): ?>
                <a href="modules/surveys/create.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-poll"></i></div>
                    <div class="action-title">Create Survey</div>
                    <div class="action-desc">Gather feedback from participants</div>
                </a>
            <?php endif; ?>
        </section>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Main Panel -->
            <div class="main-panel">
                <div class="panel-header">
                    <h2 class="panel-title">Recent Activities</h2>
                    <a href="#" class="btn btn-outline">View All</a>
                </div>
                
                <div class="activities-list">
                    <?php foreach ($recentActivities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['type']; ?>">
                                <i class="<?php echo $activity['icon']; ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text"><?php echo htmlspecialchars($activity['text']); ?></div>
                                <div class="activity-time"><?php echo htmlspecialchars($activity['time']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Side Panel -->
            <div class="side-panel">
                <div class="panel-header">
                    <h2 class="panel-title">Progress Overview</h2>
                </div>
                
                <div style="margin-bottom: 2rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="font-weight: 600;">Leadership Journey</span>
                        <span style="color: var(--primary-color); font-weight: 600;">75%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 75%;"></div>
                    </div>
                    <small style="color: var(--gray-600);">Great progress! Keep up the excellent work.</small>
                </div>
                
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; color: var(--dark-color);">Quick Stats</h3>
                    <div>
                        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--gray-200); margin-bottom: 0.5rem;">
                            <span>Days Active</span>
                            <strong style="color: var(--primary-color);">15</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--gray-200); margin-bottom: 0.5rem;">
                            <span>Tasks Completed</span>
                            <strong style="color: var(--success-color);"><?php echo $stats['completed_assignments'] ?? 0; ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                            <span>Points Earned</span>
                            <strong style="color: var(--warning-color);">285</strong>
                        </div>
                    </div>
                </div>
                
                <a href="modules/reports/progress.php" class="btn" style="width: 100%;">
                    <i class="fas fa-chart-line"></i> View Detailed Report
                </a>
            </div>
        </div>
    </main>
    
    <script>
        // Animate statistics on page load
        document.addEventListener('DOMContentLoaded', function() {
            const statValues = document.querySelectorAll('.stat-value');
            
            statValues.forEach(stat => {
                const finalValue = parseInt(stat.textContent);
                let currentValue = 0;
                const increment = finalValue / 30;
                
                const counter = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        stat.textContent = finalValue;
                        clearInterval(counter);
                    } else {
                        stat.textContent = Math.floor(currentValue);
                    }
                }, 50);
            });
            
            // Animate progress bars
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
        });
        
        // User menu toggle (placeholder)
        function toggleUserMenu() {
            // This would open a dropdown menu
            const userMenuOptions = [
                { text: 'My Profile', href: 'modules/users/profile.php', icon: 'fas fa-user' },
                { text: 'Settings', href: '#', icon: 'fas fa-cog' },
                { text: 'Help', href: '#', icon: 'fas fa-question-circle' },
                { text: 'Logout', href: 'logout.php', icon: 'fas fa-sign-out-alt' }
            ];
            
            // Simple alert for now - would be replaced with actual dropdown
            const choice = prompt(
                "User Menu:\n" +
                "1. My Profile\n" +
                "2. Settings\n" +
                "3. Help\n" +
                "4. Logout\n" +
                "Enter choice (1-4):"
            );
            
            switch(choice) {
                case '1':
                    window.location.href = 'modules/users/profile.php';
                    break;
                case '2':
                    alert('Settings page coming soon!');
                    break;
                case '3':
                    alert('Help documentation coming soon!');
                    break;
                case '4':
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = 'logout.php';
                    }
                    break;
            }
        }
        
        // Add click animations to action cards
        document.querySelectorAll('.action-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Add click effect
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });
        
        // Auto-refresh stats every 5 minutes
        setInterval(() => {
            console.log('Refreshing dashboard stats...');
            // This would make an AJAX call to refresh statistics
        }, 300000);
    </script>
</body>
</html>