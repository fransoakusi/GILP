 <?php
/**
 * User Profile Page - Girls Leadership Program
 * Beautiful profile view with activity and statistics
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Check if user is logged in
requireLogin();

// Get current user
$currentUser = getCurrentUser();

// Get profile user ID (default to current user)
$userId = intval($_GET['id'] ?? $currentUser['user_id']);

// Check permissions
$isOwnProfile = $userId === $currentUser['user_id'];
$canViewOtherProfiles = hasUserPermission('user_management') || hasUserPermission('mentee_management');

if (!$isOwnProfile && !$canViewOtherProfiles) {
    redirect('profile.php', 'Access denied.', 'error');
}

// Load user data
try {
    $db = Database::getInstance();
    
    $user = $db->fetchRow(
        "SELECT * FROM users WHERE user_id = :id",
        [':id' => $userId]
    );
    
    if (!$user) {
        redirect('list.php', 'User not found.', 'error');
    }
    
    // Get user statistics
    $stats = [
        'assignments_total' => $db->count('assignments', 'assigned_to = :id', [':id' => $userId]),
        'assignments_completed' => $db->count('assignments', 'assigned_to = :id AND status = :status', [':id' => $userId, ':status' => 'completed']),
        'projects_count' => $db->count('project_participants', 'user_id = :id', [':id' => $userId]),
        'mentorship_count' => 0,
    ];
    
    // Get mentorship information
    if ($user['role'] === 'mentor') {
        $stats['mentorship_count'] = $db->count('mentor_assignments', 'mentor_id = :id AND status = :status', [':id' => $userId, ':status' => 'active']);
        
        // Get mentees
        $mentees = $db->fetchAll(
            "SELECT u.user_id, u.first_name, u.last_name, u.email, ma.assigned_date 
             FROM mentor_assignments ma 
             JOIN users u ON u.user_id = ma.mentee_id 
             WHERE ma.mentor_id = :id AND ma.status = 'active' 
             ORDER BY ma.assigned_date DESC",
            [':id' => $userId]
        );
    } elseif ($user['role'] === 'participant') {
        $stats['mentorship_count'] = $db->count('mentor_assignments', 'mentee_id = :id AND status = :status', [':id' => $userId, ':status' => 'active']);
        
        // Get mentors
        $mentors = $db->fetchAll(
            "SELECT u.user_id, u.first_name, u.last_name, u.email, ma.assigned_date 
             FROM mentor_assignments ma 
             JOIN users u ON u.user_id = ma.mentor_id 
             WHERE ma.mentee_id = :id AND ma.status = 'active' 
             ORDER BY ma.assigned_date DESC",
            [':id' => $userId]
        );
    }
    
    // Get recent assignments
    $recentAssignments = $db->fetchAll(
        "SELECT a.*, u.first_name as assigned_by_name, u.last_name as assigned_by_lastname 
         FROM assignments a 
         LEFT JOIN users u ON u.user_id = a.assigned_by 
         WHERE a.assigned_to = :id 
         ORDER BY a.created_at DESC 
         LIMIT 5",
        [':id' => $userId]
    );
    
    // Get project participations
    $projects = $db->fetchAll(
        "SELECT p.*, pp.role_in_project, pp.joined_date 
         FROM project_participants pp 
         JOIN projects p ON p.project_id = pp.project_id 
         WHERE pp.user_id = :id 
         ORDER BY pp.joined_date DESC 
         LIMIT 5",
        [':id' => $userId]
    );
    
    // Get training sessions attended
    $trainingSessions = $db->fetchAll(
        "SELECT ts.*, sa.status as attendance_status, sa.attendance_date 
         FROM session_attendance sa 
         JOIN training_sessions ts ON ts.session_id = sa.session_id 
         WHERE sa.user_id = :id 
         ORDER BY ts.session_date DESC 
         LIMIT 5",
        [':id' => $userId]
    );
    
} catch (Exception $e) {
    error_log("Profile error: " . $e->getMessage());
    redirect('list.php', 'Error loading profile.', 'error');
}

$pageTitle = $user['first_name'] . ' ' . $user['last_name'] . ' - Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . APP_NAME; ?></title>
    
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
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
        }
        
        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray-600);
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* Profile Layout */
        .profile-layout {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
        }
        
        /* Profile Card */
        .profile-card {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
            height: fit-content;
            position: sticky;
            top: 120px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 3rem;
            font-weight: 700;
            margin: 0 auto 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .profile-name {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .profile-username {
            text-align: center;
            color: var(--gray-600);
            margin-bottom: 1rem;
        }
        
        .profile-role {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 auto 1.5rem;
            display: block;
            text-align: center;
            width: fit-content;
        }
        
        .role-admin {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
        }
        
        .role-mentor {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary-color);
        }
        
        .role-participant {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
        }
        
        .role-volunteer {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-icon {
            color: var(--primary-color);
            width: 20px;
            text-align: center;
        }
        
        .info-content {
            flex: 1;
        }
        
        .info-label {
            font-size: 0.8rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: var(--dark-color);
            font-weight: 500;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-active {
            color: var(--success-color);
        }
        
        .status-inactive {
            color: var(--gray-600);
        }
        
        .profile-actions {
            margin-top: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        /* Main Content Area */
        .content-area {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .stat-icon.primary { color: var(--primary-color); }
        .stat-icon.success { color: var(--success-color); }
        .stat-icon.warning { color: var(--warning-color); }
        .stat-icon.accent { color: var(--accent-color); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
        
        /* Content Sections */
        .content-section {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .section-action {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .section-action:hover {
            text-decoration: underline;
        }
        
        /* Bio Section */
        .bio-text {
            color: var(--gray-600);
            line-height: 1.7;
            font-style: italic;
        }
        
        .bio-empty {
            color: var(--gray-600);
            font-style: italic;
            text-align: center;
            padding: 2rem;
        }
        
        /* Lists */
        .item-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .list-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: var(--gray-100);
            transition: all 0.3s ease;
        }
        
        .list-item:hover {
            background: var(--gray-200);
        }
        
        .item-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1rem;
        }
        
        .item-icon.primary { background: var(--primary-color); }
        .item-icon.success { background: var(--success-color); }
        .item-icon.warning { background: var(--warning-color); }
        .item-icon.accent { background: var(--accent-color); }
        
        .item-content {
            flex: 1;
        }
        
        .item-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }
        
        .item-subtitle {
            color: var(--gray-600);
            font-size: 0.9rem;
        }
        
        .item-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-completed {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
        }
        
        .status-in-progress {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .status-pending {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary-color);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-600);
        }
        
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
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
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .profile-layout {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .profile-card {
                position: static;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .list-item {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-card {
                padding: 1.5rem;
            }
            
            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-content">
            <a href="../../dashboard.php" class="navbar-brand">
                <span style="font-size: 2rem;">👩‍💼</span>
                <span><?php echo APP_NAME; ?></span>
            </a>
            
            <div class="navbar-nav">
                <a href="../../dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <?php if (hasUserPermission('user_management')): ?>
                    <a href="list.php" class="nav-link active">
                        <i class="fas fa-users"></i> Users
                    </a>
                <?php endif; ?>
                <a href="../projects/list.php" class="nav-link">
                    <i class="fas fa-project-diagram"></i> Projects
                </a>
                <a href="../../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="../../dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <?php if (!$isOwnProfile && hasUserPermission('user_management')): ?>
                <a href="list.php">Users</a>
                <i class="fas fa-chevron-right"></i>
            <?php endif; ?>
            <span>Profile</span>
        </div>
        
        <!-- Profile Layout -->
        <div class="profile-layout">
            <!-- Profile Card -->
            <div class="profile-card">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                </div>
                
                <h1 class="profile-name">
                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                </h1>
                
                <div class="profile-username">
                    @<?php echo htmlspecialchars($user['username']); ?>
                </div>
                
                <div class="profile-role role-<?php echo $user['role']; ?>">
                    <?php echo getRoleDisplayName($user['role']); ?>
                </div>
                
                <div class="profile-info">
                    <div class="info-item">
                        <i class="fas fa-envelope info-icon"></i>
                        <div class="info-content">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                    </div>
                    
                    <?php if (!empty($user['phone'])): ?>
                        <div class="info-item">
                            <i class="fas fa-phone info-icon"></i>
                            <div class="info-content">
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($user['phone']); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <i class="fas fa-calendar info-icon"></i>
                        <div class="info-content">
                            <div class="info-label">Joined</div>
                            <div class="info-value"><?php echo date('M j, Y', strtotime($user['date_joined'])); ?></div>
                        </div>
                    </div>
                    
                    <?php if ($user['last_login']): ?>
                        <div class="info-item">
                            <i class="fas fa-clock info-icon"></i>
                            <div class="info-content">
                                <div class="info-label">Last Active</div>
                                <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($user['last_login'])); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <i class="fas fa-circle info-icon"></i>
                        <div class="info-content">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                    <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="profile-actions">
                    <?php if ($isOwnProfile): ?>
                        <a href="manage.php?id=<?php echo $user['user_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                    <?php elseif (hasUserPermission('user_management')): ?>
                        <a href="manage.php?id=<?php echo $user['user_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit User
                        </a>
                    <?php endif; ?>
                    
                    <?php if (!$isOwnProfile): ?>
                        <a href="mailto:<?php echo urlencode($user['email']); ?>" class="btn btn-outline">
                            <i class="fas fa-envelope"></i> Send Email
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Content Area -->
            <div class="content-area">
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['assignments_total']; ?></div>
                        <div class="stat-label">Total Assignments</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['assignments_completed']; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['projects_count']; ?></div>
                        <div class="stat-label">Projects</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon accent">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['mentorship_count']; ?></div>
                        <div class="stat-label">
                            <?php echo $user['role'] === 'mentor' ? 'Mentees' : 'Mentors'; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Bio Section -->
                <?php if (!empty($user['bio'])): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-user-circle" style="color: var(--primary-color); margin-right: 0.5rem;"></i>
                                About
                            </h2>
                        </div>
                        <div class="bio-text">
                            <?php echo nl2br(htmlspecialchars($user['bio'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Mentorship Relationships -->
                <?php if (isset($mentees) && !empty($mentees)): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-chalkboard-teacher" style="color: var(--primary-color); margin-right: 0.5rem;"></i>
                                My Mentees
                            </h2>
                            <span class="section-action"><?php echo count($mentees); ?> active</span>
                        </div>
                        <div class="item-list">
                            <?php foreach ($mentees as $mentee): ?>
                                <div class="list-item">
                                    <div class="item-icon success">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <div class="item-content">
                                        <div class="item-title">
                                            <?php echo htmlspecialchars($mentee['first_name'] . ' ' . $mentee['last_name']); ?>
                                        </div>
                                        <div class="item-subtitle">
                                            <?php echo htmlspecialchars($mentee['email']); ?> • 
                                            Since <?php echo date('M Y', strtotime($mentee['assigned_date'])); ?>
                                        </div>
                                    </div>
                                    <a href="profile.php?id=<?php echo $mentee['user_id']; ?>" class="btn btn-outline btn-sm">
                                        View Profile
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($mentors) && !empty($mentors)): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-user-tie" style="color: var(--primary-color); margin-right: 0.5rem;"></i>
                                My Mentors
                            </h2>
                            <span class="section-action"><?php echo count($mentors); ?> active</span>
                        </div>
                        <div class="item-list">
                            <?php foreach ($mentors as $mentor): ?>
                                <div class="list-item">
                                    <div class="item-icon primary">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                    </div>
                                    <div class="item-content">
                                        <div class="item-title">
                                            <?php echo htmlspecialchars($mentor['first_name'] . ' ' . $mentor['last_name']); ?>
                                        </div>
                                        <div class="item-subtitle">
                                            <?php echo htmlspecialchars($mentor['email']); ?> • 
                                            Since <?php echo date('M Y', strtotime($mentor['assigned_date'])); ?>
                                        </div>
                                    </div>
                                    <a href="profile.php?id=<?php echo $mentor['user_id']; ?>" class="btn btn-outline btn-sm">
                                        View Profile
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Recent Assignments -->
                <?php if (!empty($recentAssignments)): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-tasks" style="color: var(--primary-color); margin-right: 0.5rem;"></i>
                                Recent Assignments
                            </h2>
                            <a href="../assignments/list.php" class="section-action">View All</a>
                        </div>
                        <div class="item-list">
                            <?php foreach ($recentAssignments as $assignment): ?>
                                <div class="list-item">
                                    <div class="item-icon <?php echo $assignment['priority'] === 'high' ? 'accent' : ($assignment['status'] === 'completed' ? 'success' : 'primary'); ?>">
                                        <i class="fas fa-clipboard-list"></i>
                                    </div>
                                    <div class="item-content">
                                        <div class="item-title">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </div>
                                        <div class="item-subtitle">
                                            Assigned by <?php echo htmlspecialchars($assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_lastname']); ?>
                                            <?php if ($assignment['due_date']): ?>
                                                • Due <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="item-status status-<?php echo str_replace('_', '-', $assignment['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $assignment['status'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Project Participations -->
                <?php if (!empty($projects)): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-project-diagram" style="color: var(--primary-color); margin-right: 0.5rem;"></i>
                                Projects
                            </h2>
                            <a href="../projects/list.php" class="section-action">View All</a>
                        </div>
                        <div class="item-list">
                            <?php foreach ($projects as $project): ?>
                                <div class="list-item">
                                    <div class="item-icon warning">
                                        <i class="fas fa-folder-open"></i>
                                    </div>
                                    <div class="item-content">
                                        <div class="item-title">
                                            <?php echo htmlspecialchars($project['title']); ?>
                                        </div>
                                        <div class="item-subtitle">
                                            Role: <?php echo ucfirst($project['role_in_project']); ?> • 
                                            Joined <?php echo date('M Y', strtotime($project['joined_date'])); ?>
                                        </div>
                                    </div>
                                    <div class="item-status status-<?php echo str_replace('_', '-', $project['status']); ?>">
                                        <?php echo ucfirst($project['status']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- No Content State -->
                <?php if (empty($recentAssignments) && empty($projects) && empty($user['bio'])): ?>
                    <div class="content-section">
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-user-circle"></i>
                            </div>
                            <h3>Profile is getting started</h3>
                            <p>This user is just beginning their leadership journey. Check back soon for updates!</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <script>
        // Smooth scrolling for section navigation
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
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                if (this.href) {
                    this.style.opacity = '0.8';
                    this.style.pointerEvents = 'none';
                }
            });
        });
        
        // Auto-update "last active" time if it's the current user's profile
        <?php if ($isOwnProfile): ?>
        setInterval(() => {
            // This would make an AJAX call to update last activity
            console.log('User is active on profile page');
        }, 60000); // Every minute
        <?php endif; ?>
    </script>
</body>
</html>
