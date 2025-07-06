 <?php
/**
 * Project Management List - Girls Leadership Program
 * Beautiful interface for managing leadership projects
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Require login
requireLogin();

// Get current user
$currentUser = getCurrentUser();
$userRole = $currentUser['role'];

// Check permissions - different roles have different access
$canCreateProjects = hasUserPermission('project_management');
$canViewAll = hasUserPermission('project_management') || hasUserPermission('mentee_management');

// Handle project actions (only for admins/mentors)
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canCreateProjects) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $projectId = intval($_POST['project_id'] ?? 0);
        
        if ($projectId > 0) {
            $db = Database::getInstance();
            
            switch ($action) {
                case 'activate':
                    $db->update('projects', ['status' => 'active'], 'project_id = :id', [':id' => $projectId]);
                    $message = 'Project activated successfully.';
                    $messageType = 'success';
                    logActivity("Project ID $projectId activated", $currentUser['user_id']);
                    break;
                    
                case 'complete':
                    $db->update('projects', ['status' => 'completed'], 'project_id = :id', [':id' => $projectId]);
                    $message = 'Project marked as completed.';
                    $messageType = 'success';
                    logActivity("Project ID $projectId completed", $currentUser['user_id']);
                    break;
                    
                case 'pause':
                    $db->update('projects', ['status' => 'on_hold'], 'project_id = :id', [':id' => $projectId]);
                    $message = 'Project paused successfully.';
                    $messageType = 'success';
                    logActivity("Project ID $projectId paused", $currentUser['user_id']);
                    break;
            }
        }
    }
}

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? '');
$priorityFilter = sanitizeInput($_GET['priority'] ?? '');
$myProjectsOnly = isset($_GET['my_projects']);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Build query conditions
$whereConditions = ['1 = 1'];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(p.title LIKE :search OR p.description LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($statusFilter)) {
    $whereConditions[] = "p.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($priorityFilter)) {
    $whereConditions[] = "p.priority = :priority";
    $params[':priority'] = $priorityFilter;
}

// Filter for user's projects (participants see only their projects by default)
if ($myProjectsOnly || (!$canViewAll && $userRole === 'participant')) {
    $whereConditions[] = "pp.user_id = :user_id";
    $params[':user_id'] = $currentUser['user_id'];
}

$whereClause = implode(' AND ', $whereConditions);

// Get projects with pagination
try {
    $db = Database::getInstance();
    
    $joinClause = ($myProjectsOnly || (!$canViewAll && $userRole === 'participant')) 
        ? "INNER JOIN project_participants pp ON pp.project_id = p.project_id"
        : "LEFT JOIN project_participants pp ON pp.project_id = p.project_id";
    
    // Count total projects
    $countQuery = "SELECT COUNT(DISTINCT p.project_id) as count 
                   FROM projects p $joinClause 
                   LEFT JOIN users u ON u.user_id = p.created_by 
                   WHERE $whereClause";
    
    $totalProjects = $db->fetchRow($countQuery, $params)['count'];
    
    // Get projects for current page
    $projectsQuery = "SELECT DISTINCT p.*, 
                             u.first_name as creator_first_name, 
                             u.last_name as creator_last_name,
                             (SELECT COUNT(*) FROM project_participants pp2 WHERE pp2.project_id = p.project_id) as participant_count,
                             (SELECT COUNT(*) FROM assignments a WHERE a.project_id = p.project_id) as assignment_count
                      FROM projects p 
                      $joinClause
                      LEFT JOIN users u ON u.user_id = p.created_by 
                      WHERE $whereClause 
                      ORDER BY p.created_at DESC 
                      LIMIT $perPage OFFSET $offset";
    
    $projects = $db->fetchAll($projectsQuery, $params);
    
    $totalPages = ceil($totalProjects / $perPage);
    
    // Get project statistics
    $stats = [
        'total_projects' => $db->count('projects'),
        'active_projects' => $db->count('projects', 'status = :status', [':status' => 'active']),
        'completed_projects' => $db->count('projects', 'status = :status', [':status' => 'completed']),
        'my_projects' => $db->count('project_participants', 'user_id = :user_id', [':user_id' => $currentUser['user_id']])
    ];
    
} catch (Exception $e) {
    error_log("Project list error: " . $e->getMessage());
    $projects = [];
    $totalProjects = 0;
    $totalPages = 0;
    $stats = ['total_projects' => 0, 'active_projects' => 0, 'completed_projects' => 0, 'my_projects' => 0];
}

$pageTitle = 'Project Management';
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-header {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: var(--gray-600);
            font-size: 1.1rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-value {
            font-size: 2.5rem;
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
        
        /* Filters and Search */
        .filters-section {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 1rem;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark-color);
        }
        
        .form-control {
            padding: 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
        }
        
        .checkbox-filter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-filter input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }
        
        /* Projects Grid */
        .projects-section {
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .section-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }
        
        .project-card {
            background: var(--white);
            border-radius: 12px;
            border: 2px solid var(--gray-200);
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .project-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--primary-color);
        }
        
        .project-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary-color);
        }
        
        .project-card.status-completed::before {
            background: var(--success-color);
        }
        
        .project-card.status-on-hold::before {
            background: var(--warning-color);
        }
        
        .project-card.status-cancelled::before {
            background: var(--danger-color);
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .project-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        
        .project-description {
            color: var(--gray-600);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .project-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        .stat-text {
            font-size: 0.7rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .project-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        /* Status and Priority Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-planning {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary-color);
        }
        
        .status-active {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
        }
        
        .status-completed {
            background: rgba(116, 185, 255, 0.1);
            color: #74B9FF;
        }
        
        .status-on-hold {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .status-cancelled {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
        }
        
        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .priority-low {
            background: rgba(116, 185, 255, 0.1);
            color: #74B9FF;
        }
        
        .priority-medium {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .priority-high {
            background: rgba(253, 121, 168, 0.1);
            color: var(--accent-color);
        }
        
        .priority-urgent {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
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
        
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-success {
            background: var(--success-color);
            color: var(--white);
        }
        
        .btn-warning {
            background: var(--warning-color);
            color: var(--dark-color);
        }
        
        .btn-danger {
            background: var(--danger-color);
            color: var(--white);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            background: var(--gray-100);
        }
        
        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination a {
            color: var(--primary-color);
        }
        
        .pagination a:hover {
            background: var(--primary-color);
            color: var(--white);
        }
        
        .pagination .current {
            background: var(--primary-color);
            color: var(--white);
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert-success {
            background: rgba(0, 184, 148, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }
        
        .alert-error {
            background: rgba(232, 67, 147, 0.1);
            border-color: var(--danger-color);
            color: var(--danger-color);
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-600);
        }
        
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }
        
        .empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark-color);
        }
        
        .empty-description {
            margin-bottom: 2rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
            }
            
            .projects-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                text-align: center;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .projects-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .project-stats {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
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
                    <a href="../users/list.php" class="nav-link">
                        <i class="fas fa-users"></i> Users
                    </a>
                <?php endif; ?>
                <a href="list.php" class="nav-link active">
                    <i class="fas fa-project-diagram"></i> Projects
                </a>
                <a href="../assignments/list.php" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="../../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Leadership Projects</h1>
                <p class="page-subtitle">Collaborative projects for skill development and impact</p>
            </div>
            <div class="header-actions">
                <?php if ($canCreateProjects): ?>
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Project
                    </a>
                <?php endif; ?>
                <a href="../../dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-project-diagram"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_projects']; ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-play-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['active_projects']; ?></div>
                <div class="stat-label">Active Projects</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['completed_projects']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['my_projects']; ?></div>
                <div class="stat-label">My Projects</div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="search">Search Projects</label>
                        <input type="text" id="search" name="search" class="form-control" 
                               placeholder="Search by title or description..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="planning" <?php echo $statusFilter === 'planning' ? 'selected' : ''; ?>>Planning</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="on_hold" <?php echo $statusFilter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority" class="form-control">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo $priorityFilter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priorityFilter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priorityFilter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $priorityFilter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="checkbox-filter">
                            <input type="checkbox" id="my_projects" name="my_projects" 
                                   <?php echo $myProjectsOnly ? 'checked' : ''; ?>>
                            <label for="my_projects">My Projects Only</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Projects Section -->
        <div class="projects-section">
            <div class="section-header">
                <h2 class="section-title">
                    Projects (<?php echo number_format($totalProjects); ?>)
                </h2>
                <div>
                    <?php if ($totalPages > 1): ?>
                        <span style="font-size: 0.9rem; color: var(--gray-600);">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($projects)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-project-diagram"></i>
                    </div>
                    <h3 class="empty-title">No projects found</h3>
                    <p class="empty-description">
                        <?php if ($myProjectsOnly): ?>
                            You haven't joined any projects yet. Browse available projects or create a new one to get started.
                        <?php else: ?>
                            No projects match your current filters. Try adjusting your search criteria or create the first project.
                        <?php endif; ?>
                    </p>
                    <?php if ($canCreateProjects): ?>
                        <a href="create.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create First Project
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="projects-grid">
                    <?php foreach ($projects as $project): ?>
                        <div class="project-card status-<?php echo $project['status']; ?>">
                            <div class="project-header">
                                <div>
                                    <div class="status-badge status-<?php echo $project['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                                    </div>
                                </div>
                                <div class="priority-badge priority-<?php echo $project['priority']; ?>">
                                    <?php echo ucfirst($project['priority']); ?>
                                </div>
                            </div>
                            
                            <h3 class="project-title">
                                <?php echo htmlspecialchars($project['title']); ?>
                            </h3>
                            
                            <p class="project-description">
                                <?php echo htmlspecialchars($project['description'] ?? 'No description provided.'); ?>
                            </p>
                            
                            <div class="project-meta">
                                <div class="meta-item">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($project['creator_first_name'] . ' ' . $project['creator_last_name']); ?>
                                </div>
                                <?php if ($project['start_date']): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-calendar-start"></i>
                                        <?php echo date('M j, Y', strtotime($project['start_date'])); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($project['end_date']): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-calendar-check"></i>
                                        <?php echo date('M j, Y', strtotime($project['end_date'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="project-stats">
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $project['participant_count']; ?></div>
                                    <div class="stat-text">Participants</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $project['assignment_count']; ?></div>
                                    <div class="stat-text">Tasks</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number">
                                        <?php 
                                        $days = 0;
                                        if ($project['start_date'] && $project['end_date']) {
                                            $start = new DateTime($project['start_date']);
                                            $end = new DateTime($project['end_date']);
                                            $days = $start->diff($end)->days;
                                        }
                                        echo $days;
                                        ?>
                                    </div>
                                    <div class="stat-text">Days</div>
                                </div>
                            </div>
                            
                            <div class="project-actions">
                                <a href="view.php?id=<?php echo $project['project_id']; ?>" class="btn btn-outline btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                
                                <?php if ($canCreateProjects): ?>
                                    <a href="create.php?id=<?php echo $project['project_id']; ?>" class="btn btn-outline btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    
                                    <?php if ($project['status'] === 'planning'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                            <button type="submit" class="btn-icon btn-success" title="Activate Project">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($project['status'] === 'active'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="complete">
                                            <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                            <button type="submit" class="btn-icon btn-success" title="Mark Complete">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="pause">
                                            <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                                            <button type="submit" class="btn-icon btn-warning" title="Pause Project">
                                                <i class="fas fa-pause"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $myProjectsOnly ? '&my_projects=1' : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $myProjectsOnly ? '&my_projects=1' : ''; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <?php if ($i === $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $myProjectsOnly ? '&my_projects=1' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $myProjectsOnly ? '&my_projects=1' : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $myProjectsOnly ? '&my_projects=1' : ''; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
        // Auto-submit search form on input change with debounce
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
        
        // Auto-submit form on filter change
        document.getElementById('status').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('priority').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('my_projects').addEventListener('change', function() {
            this.form.submit();
        });
        
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Add loading states to action buttons
        document.querySelectorAll('form button').forEach(button => {
            button.addEventListener('click', function() {
                this.style.opacity = '0.6';
                this.disabled = true;
            });
        });
        
        // Add hover effects to project cards
        document.querySelectorAll('.project-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>
