 <?php
/**
 * Assignment Management List - Girls Leadership Program
 * Beautiful interface for managing assignments and submissions
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
$canCreateAssignments = hasUserPermission('assignment_management');
$canViewAll = hasUserPermission('assignment_management') || hasUserPermission('mentee_management');

// Handle assignment actions (only for admins/mentors)
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canCreateAssignments) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        
        if ($assignmentId > 0) {
            $db = Database::getInstance();
            
            switch ($action) {
                case 'mark_reviewed':
                    $db->update('assignments', ['status' => 'reviewed'], 'assignment_id = :id', [':id' => $assignmentId]);
                    $message = 'Assignment marked as reviewed.';
                    $messageType = 'success';
                    logActivity("Assignment ID $assignmentId marked as reviewed", $currentUser['user_id']);
                    break;
                    
                case 'mark_completed':
                    $db->update('assignments', ['status' => 'completed'], 'assignment_id = :id', [':id' => $assignmentId]);
                    $message = 'Assignment marked as completed.';
                    $messageType = 'success';
                    logActivity("Assignment ID $assignmentId completed", $currentUser['user_id']);
                    break;
                    
                case 'reopen':
                    $db->update('assignments', ['status' => 'in_progress'], 'assignment_id = :id', [':id' => $assignmentId]);
                    $message = 'Assignment reopened for revision.';
                    $messageType = 'success';
                    logActivity("Assignment ID $assignmentId reopened", $currentUser['user_id']);
                    break;
            }
        }
    }
}

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? '');
$priorityFilter = sanitizeInput($_GET['priority'] ?? '');
$projectFilter = intval($_GET['project_id'] ?? 0);
$myAssignmentsOnly = isset($_GET['my_assignments']);
$assignedByMe = isset($_GET['assigned_by_me']);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Build query conditions
$whereConditions = ['1 = 1'];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(a.title LIKE :search OR a.description LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($statusFilter)) {
    $whereConditions[] = "a.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($priorityFilter)) {
    $whereConditions[] = "a.priority = :priority";
    $params[':priority'] = $priorityFilter;
}

if ($projectFilter > 0) {
    $whereConditions[] = "a.project_id = :project_id";
    $params[':project_id'] = $projectFilter;
}

// Filter for user's assignments (participants see only their assignments by default)
if ($myAssignmentsOnly || (!$canViewAll && $userRole === 'participant')) {
    $whereConditions[] = "a.assigned_to = :user_id";
    $params[':user_id'] = $currentUser['user_id'];
}

// Filter for assignments created by current user
if ($assignedByMe) {
    $whereConditions[] = "a.assigned_by = :assigned_by";
    $params[':assigned_by'] = $currentUser['user_id'];
}

$whereClause = implode(' AND ', $whereConditions);

// Get assignments with pagination
try {
    $db = Database::getInstance();
    
    // Count total assignments
    $countQuery = "SELECT COUNT(*) as count 
                   FROM assignments a 
                   LEFT JOIN users u1 ON u1.user_id = a.assigned_by 
                   LEFT JOIN users u2 ON u2.user_id = a.assigned_to 
                   LEFT JOIN projects p ON p.project_id = a.project_id 
                   WHERE $whereClause";
    
    $totalAssignments = $db->fetchRow($countQuery, $params)['count'];
    
    // Get assignments for current page
    $assignmentsQuery = "SELECT a.*, 
                                u1.first_name as assigned_by_name, u1.last_name as assigned_by_lastname,
                                u2.first_name as assigned_to_name, u2.last_name as assigned_to_lastname,
                                p.title as project_title,
                                (SELECT COUNT(*) FROM assignment_submissions sub WHERE sub.assignment_id = a.assignment_id) as submission_count
                         FROM assignments a 
                         LEFT JOIN users u1 ON u1.user_id = a.assigned_by 
                         LEFT JOIN users u2 ON u2.user_id = a.assigned_to 
                         LEFT JOIN projects p ON p.project_id = a.project_id 
                         WHERE $whereClause 
                         ORDER BY a.created_at DESC 
                         LIMIT $perPage OFFSET $offset";
    
    $assignments = $db->fetchAll($assignmentsQuery, $params);
    
    $totalPages = ceil($totalAssignments / $perPage);
    
    // Get assignment statistics
    $stats = [
        'total_assignments' => $db->count('assignments'),
        'assigned_assignments' => $db->count('assignments', 'status = :status', [':status' => 'assigned']),
        'in_progress_assignments' => $db->count('assignments', 'status = :status', [':status' => 'in_progress']),
        'completed_assignments' => $db->count('assignments', 'status = :status', [':status' => 'completed']),
        'my_assignments' => $db->count('assignments', 'assigned_to = :user_id', [':user_id' => $currentUser['user_id']]),
        'assigned_by_me' => $db->count('assignments', 'assigned_by = :user_id', [':user_id' => $currentUser['user_id']])
    ];
    
    // Get projects for filter dropdown
    $projects = $db->fetchAll("SELECT project_id, title FROM projects ORDER BY title");
    
} catch (Exception $e) {
    error_log("Assignment list error: " . $e->getMessage());
    $assignments = [];
    $totalAssignments = 0;
    $totalPages = 0;
    $stats = ['total_assignments' => 0, 'assigned_assignments' => 0, 'in_progress_assignments' => 0, 'completed_assignments' => 0, 'my_assignments' => 0, 'assigned_by_me' => 0];
    $projects = [];
}

$pageTitle = 'Assignment Management';
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto auto;
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
        
        /* Assignments Section */
        .assignments-section {
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
        
        .assignments-list {
            display: flex;
            flex-direction: column;
        }
        
        .assignment-item {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .assignment-item:last-child {
            border-bottom: none;
        }
        
        .assignment-item:hover {
            background: var(--gray-100);
        }
        
        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }
        
        .assignment-info {
            flex: 1;
        }
        
        .assignment-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        
        .assignment-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .assignment-description {
            color: var(--gray-600);
            font-size: 0.9rem;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .assignment-badges {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-end;
        }
        
        .assignment-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            justify-content: flex-end;
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
        
        .status-assigned {
            background: rgba(116, 185, 255, 0.1);
            color: #74B9FF;
        }
        
        .status-in-progress {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .status-submitted {
            background: rgba(162, 155, 254, 0.1);
            color: var(--secondary-color);
        }
        
        .status-reviewed {
            background: rgba(253, 121, 168, 0.1);
            color: var(--accent-color);
        }
        
        .status-completed {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
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
        
        .due-date-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--gray-200);
            color: var(--gray-600);
        }
        
        .due-date-badge.overdue {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
        }
        
        .due-date-badge.due-soon {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
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
                grid-template-columns: 1fr 1fr 1fr;
                gap: 1rem;
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
            
            .assignment-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .assignment-badges {
                align-items: flex-start;
                flex-direction: row;
                flex-wrap: wrap;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .assignment-actions {
                flex-direction: column;
                align-items: stretch;
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
                <a href="../projects/list.php" class="nav-link">
                    <i class="fas fa-project-diagram"></i> Projects
                </a>
                <a href="list.php" class="nav-link active">
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
                <h1 class="page-title">Assignment Management</h1>
                <p class="page-subtitle">Track tasks, submissions, and progress across all projects</p>
            </div>
            <div class="header-actions">
                <?php if ($canCreateAssignments): ?>
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Assignment
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
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_assignments']; ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['in_progress_assignments']; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['completed_assignments']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['my_assignments']; ?></div>
                <div class="stat-label">My Assignments</div>
            </div>
            
            <?php if ($canCreateAssignments): ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['assigned_by_me']; ?></div>
                    <div class="stat-label">Assigned by Me</div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="search">Search Assignments</label>
                        <input type="text" id="search" name="search" class="form-control" 
                               placeholder="Search by title or description..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="assigned" <?php echo $statusFilter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                            <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                            <option value="reviewed" <?php echo $statusFilter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
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
                        <label for="project_id">Project</label>
                        <select id="project_id" name="project_id" class="form-control">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['project_id']; ?>" 
                                        <?php echo $projectFilter === $project['project_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="checkbox-filter">
                            <input type="checkbox" id="my_assignments" name="my_assignments" 
                                   <?php echo $myAssignmentsOnly ? 'checked' : ''; ?>>
                            <label for="my_assignments">My Assignments</label>
                        </div>
                    </div>
                    
                    <?php if ($canCreateAssignments): ?>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div class="checkbox-filter">
                                <input type="checkbox" id="assigned_by_me" name="assigned_by_me" 
                                       <?php echo $assignedByMe ? 'checked' : ''; ?>>
                                <label for="assigned_by_me">Assigned by Me</label>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Assignments Section -->
        <div class="assignments-section">
            <div class="section-header">
                <h2 class="section-title">
                    Assignments (<?php echo number_format($totalAssignments); ?>)
                </h2>
                <div>
                    <?php if ($totalPages > 1): ?>
                        <span style="font-size: 0.9rem; color: var(--gray-600);">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($assignments)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3 class="empty-title">No assignments found</h3>
                    <p class="empty-description">
                        <?php if ($myAssignmentsOnly): ?>
                            You don't have any assignments yet. Check back later or contact your mentor.
                        <?php else: ?>
                            No assignments match your current filters. Try adjusting your search criteria or create the first assignment.
                        <?php endif; ?>
                    </p>
                    <?php if ($canCreateAssignments): ?>
                        <a href="create.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create First Assignment
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="assignments-list">
                    <?php foreach ($assignments as $assignment): 
                        $dueSoon = false;
                        $overdue = false;
                        if ($assignment['due_date']) {
                            $dueDate = new DateTime($assignment['due_date']);
                            $now = new DateTime();
                            $daysDiff = $now->diff($dueDate)->days;
                            
                            if ($dueDate < $now) {
                                $overdue = true;
                            } elseif ($daysDiff <= 3) {
                                $dueSoon = true;
                            }
                        }
                    ?>
                        <div class="assignment-item">
                            <div class="assignment-header">
                                <div class="assignment-info">
                                    <h3 class="assignment-title">
                                        <?php echo htmlspecialchars($assignment['title']); ?>
                                    </h3>
                                    
                                    <div class="assignment-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-user"></i>
                                            Assigned to <?php echo htmlspecialchars($assignment['assigned_to_name'] . ' ' . $assignment['assigned_to_lastname']); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-user-edit"></i>
                                            By <?php echo htmlspecialchars($assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_lastname']); ?>
                                        </div>
                                        <?php if ($assignment['project_title']): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-project-diagram"></i>
                                                <?php echo htmlspecialchars($assignment['project_title']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($assignment['created_at'])); ?>
                                        </div>
                                        <?php if ($assignment['submission_count'] > 0): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-file-upload"></i>
                                                <?php echo $assignment['submission_count']; ?> submission(s)
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($assignment['description']): ?>
                                        <p class="assignment-description">
                                            <?php echo htmlspecialchars($assignment['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="assignment-badges">
                                    <span class="status-badge status-<?php echo str_replace('_', '-', $assignment['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $assignment['status'])); ?>
                                    </span>
                                    
                                    <span class="priority-badge priority-<?php echo $assignment['priority']; ?>">
                                        <?php echo ucfirst($assignment['priority']); ?>
                                    </span>
                                    
                                    <?php if ($assignment['due_date']): ?>
                                        <span class="due-date-badge <?php echo $overdue ? 'overdue' : ($dueSoon ? 'due-soon' : ''); ?>">
                                            <i class="fas fa-calendar-alt"></i>
                                            Due <?php echo date('M j', strtotime($assignment['due_date'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="assignment-actions">
                                <!-- View/Edit Assignment -->
                                <?php if ($canCreateAssignments): ?>
                                    <a href="create.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-outline btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Submit Assignment (for assigned user) -->
                                <?php if ($assignment['assigned_to'] === $currentUser['user_id'] && in_array($assignment['status'], ['assigned', 'in_progress'])): ?>
                                    <a href="submit.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-upload"></i> Submit Work
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Review Submission (for mentors/admins) -->
                                <?php if ($canCreateAssignments && $assignment['status'] === 'submitted'): ?>
                                    <a href="review.php?id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-warning btn-sm">
                                        <i class="fas fa-eye"></i> Review
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Quick Actions for Mentors/Admins -->
                                <?php if ($canCreateAssignments): ?>
                                    <?php if ($assignment['status'] === 'submitted'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="mark_reviewed">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                            <button type="submit" class="btn-icon btn-success" title="Mark as Reviewed">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($assignment['status'] === 'reviewed'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="mark_completed">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                            <button type="submit" class="btn-icon btn-success" title="Mark as Completed">
                                                <i class="fas fa-check-double"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($assignment['status'] === 'completed'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="reopen">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                            <button type="submit" class="btn-icon btn-warning" title="Reopen for Revision">
                                                <i class="fas fa-redo"></i>
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
                            <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $projectFilter ? '&project_id=' . $projectFilter : ''; ?><?php echo $myAssignmentsOnly ? '&my_assignments=1' : ''; ?><?php echo $assignedByMe ? '&assigned_by_me=1' : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $projectFilter ? '&project_id=' . $projectFilter : ''; ?><?php echo $myAssignmentsOnly ? '&my_assignments=1' : ''; ?><?php echo $assignedByMe ? '&assigned_by_me=1' : ''; ?>">
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
                                <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $projectFilter ? '&project_id=' . $projectFilter : ''; ?><?php echo $myAssignmentsOnly ? '&my_assignments=1' : ''; ?><?php echo $assignedByMe ? '&assigned_by_me=1' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $projectFilter ? '&project_id=' . $projectFilter : ''; ?><?php echo $myAssignmentsOnly ? '&my_assignments=1' : ''; ?><?php echo $assignedByMe ? '&assigned_by_me=1' : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($priorityFilter) ? '&priority=' . urlencode($priorityFilter) : ''; ?><?php echo $projectFilter ? '&project_id=' . $projectFilter : ''; ?><?php echo $myAssignmentsOnly ? '&my_assignments=1' : ''; ?><?php echo $assignedByMe ? '&assigned_by_me=1' : ''; ?>">
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
        ['status', 'priority', 'project_id', 'my_assignments', 'assigned_by_me'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', function() {
                    this.form.submit();
                });
            }
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
        
        // Add hover effects to assignment items
        document.querySelectorAll('.assignment-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.backgroundColor = 'var(--gray-100)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
    </script>
</body>
</html>
