<?php
/**
 * Project View Page - Girls Leadership Program
 * Complete error-free module with notification integration
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';
require_once APP_ROOT . '/includes/functions.php';

// Require login
requireLogin();

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    redirect('../../login.php', 'Please log in to continue.', 'error');
}

$userRole = $currentUser['role'];

// Get project ID
$projectId = intval($_GET['id'] ?? 0);
if (!$projectId) {
    redirect('list.php', 'Project not found.', 'error');
}

// Handle project actions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            $db = Database::getInstance();
            
            switch ($action) {
                case 'join_project':
                    // Check if user is already a participant
                    $existing = $db->fetchRow(
                        "SELECT * FROM project_participants WHERE project_id = :project_id AND user_id = :user_id",
                        [':project_id' => $projectId, ':user_id' => $currentUser['user_id']]
                    );
                    
                    if (!$existing) {
                        $db->insert('project_participants', [
                            'project_id' => $projectId,
                            'user_id' => $currentUser['user_id'],
                            'role_in_project' => 'member',
                            'joined_date' => date('Y-m-d H:i:s')
                        ]);
                        
                        // Get project details for notification
                        $projectForNotification = $db->fetchRow(
                            "SELECT project_id, title, created_by FROM projects WHERE project_id = :id",
                            [':id' => $projectId]
                        );
                        
                        // Send comprehensive project join notifications
                        try {
                            $notificationsCreated = notifyProjectJoined($projectId, $projectForNotification, $currentUser);
                            
                            if ($notificationsCreated > 0) {
                                $message = "Successfully joined the project! {$notificationsCreated} team members have been notified.";
                            } else {
                                $message = 'Successfully joined the project!';
                            }
                            
                        } catch (Exception $e) {
                            error_log("Project join notification error: " . $e->getMessage());
                            $message = 'Successfully joined the project, but notification system encountered an error.';
                        }
                        
                        // Enhanced logging
                        if (function_exists('logProjectActivity')) {
                            logProjectActivity("joined", $currentUser['user_id'], $projectForNotification['title'], $projectId, "Role: member");
                        } else {
                            logActivity("Joined project: {$projectForNotification['title']}", $currentUser['user_id']);
                        }
                        
                        $messageType = 'success';
                    } else {
                        $message = 'You are already a participant in this project.';
                        $messageType = 'warning';
                    }
                    break;
                    
                case 'leave_project':
                    $db->delete(
                        'project_participants',
                        'project_id = :project_id AND user_id = :user_id',
                        [':project_id' => $projectId, ':user_id' => $currentUser['user_id']]
                    );
                    
                    $message = 'You have left the project.';
                    $messageType = 'success';
                    
                    // Enhanced logging
                    if (function_exists('logProjectActivity')) {
                        logProjectActivity("left", $currentUser['user_id'], "Project ID {$projectId}", $projectId);
                    } else {
                        logActivity("Left project ID $projectId", $currentUser['user_id']);
                    }
                    break;
                    
                case 'update_status':
                    if (hasPermission('project_management')) {
                        $newStatus = sanitizeInput($_POST['new_status'] ?? '');
                        
                        // Get valid project statuses
                        $validStatuses = array_keys(PROJECT_STATUSES);
                        
                        if (in_array($newStatus, $validStatuses)) {
                            // Get project details before update
                            $projectForNotification = $db->fetchRow(
                                "SELECT project_id, title, created_by, status as old_status FROM projects WHERE project_id = :id",
                                [':id' => $projectId]
                            );
                            
                            $oldStatus = $projectForNotification['old_status'];
                            
                            $db->update('projects', ['status' => $newStatus], 'project_id = :id', [':id' => $projectId]);
                            
                            // Send comprehensive project status change notifications
                            try {
                                $notificationsCreated = notifyProjectStatusChanged($projectId, $projectForNotification, $newStatus, $currentUser);
                                
                                if ($notificationsCreated > 0) {
                                    $message = "Project status updated successfully. {$notificationsCreated} team members have been notified.";
                                } else {
                                    $message = 'Project status updated successfully.';
                                }
                                
                            } catch (Exception $e) {
                                error_log("Project status notification error: " . $e->getMessage());
                                $message = 'Project status updated successfully, but notification system encountered an error.';
                            }
                            
                            // Enhanced logging
                            if (function_exists('logProjectActivity')) {
                                logProjectActivity("status_changed", $currentUser['user_id'], $projectForNotification['title'], $projectId, "From {$oldStatus} to {$newStatus}");
                            } else {
                                logActivity("Updated project '{$projectForNotification['title']}' status from {$oldStatus} to {$newStatus}", $currentUser['user_id']);
                            }
                            
                            $messageType = 'success';
                        } else {
                            $message = 'Invalid status selected.';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Access denied. You do not have permission to update project status.';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'update_role':
                    if (hasPermission('project_management')) {
                        $userId = intval($_POST['user_id'] ?? 0);
                        $newRole = sanitizeInput($_POST['new_role'] ?? '');
                        
                        $validRoles = ['leader', 'member', 'observer'];
                        
                        if ($userId > 0 && in_array($newRole, $validRoles)) {
                            $db->update('project_participants', 
                                       ['role_in_project' => $newRole], 
                                       'project_id = :project_id AND user_id = :user_id',
                                       [':project_id' => $projectId, ':user_id' => $userId]);
                            
                            // Create role change notification
                            try {
                                $targetUser = $db->fetchRow(
                                    "SELECT first_name, last_name FROM users WHERE user_id = :id",
                                    [':id' => $userId]
                                );
                                
                                $projectTitle = $db->fetchRow(
                                    "SELECT title FROM projects WHERE project_id = :id",
                                    [':id' => $projectId]
                                )['title'];
                                
                                createNotification(
                                    $userId,
                                    'Project Role Updated',
                                    "Your role in project '{$projectTitle}' has been updated to " . ucfirst($newRole),
                                    'info',
                                    "modules/projects/view.php?id={$projectId}"
                                );
                                
                                $message = "Role updated successfully for {$targetUser['first_name']} {$targetUser['last_name']}.";
                                
                            } catch (Exception $e) {
                                error_log("Role update notification error: " . $e->getMessage());
                                $message = 'Role updated successfully.';
                            }
                            
                            $messageType = 'success';
                        } else {
                            $message = 'Invalid role or user selected.';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Access denied. You do not have permission to update roles.';
                        $messageType = 'error';
                    }
                    break;
                    
                default:
                    $message = 'Invalid action.';
                    $messageType = 'error';
                    break;
            }
        } catch (Exception $e) {
            error_log("Project action error: " . $e->getMessage());
            $message = 'An error occurred. Please try again.';
            $messageType = 'error';
        }
    }
}

// Load project data
$project = null;
$isParticipant = null;
$participants = [];
$assignments = [];
$stats = [];
$canEdit = false;
$canJoin = false;
$canManageRoles = false;

try {
    $db = Database::getInstance();
    
    // Get project details
    $project = $db->fetchRow(
        "SELECT p.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
         FROM projects p 
         LEFT JOIN users u ON u.user_id = p.created_by 
         WHERE p.project_id = :id",
        [':id' => $projectId]
    );
    
    if (!$project) {
        redirect('list.php', 'Project not found.', 'error');
    }
    
    // Check if current user is a participant
    $isParticipant = $db->fetchRow(
        "SELECT * FROM project_participants WHERE project_id = :project_id AND user_id = :user_id",
        [':project_id' => $projectId, ':user_id' => $currentUser['user_id']]
    );
    
    // Get project participants
    $participants = $db->fetchAll(
        "SELECT pp.*, u.first_name, u.last_name, u.email, u.role as user_role 
         FROM project_participants pp 
         JOIN users u ON u.user_id = pp.user_id 
         WHERE pp.project_id = :id 
         ORDER BY pp.role_in_project DESC, pp.joined_date ASC",
        [':id' => $projectId]
    );
    
    // Get project assignments
    $assignments = $db->fetchAll(
        "SELECT a.*, u1.first_name as assigned_by_name, u1.last_name as assigned_by_lastname,
                u2.first_name as assigned_to_name, u2.last_name as assigned_to_lastname
         FROM assignments a 
         LEFT JOIN users u1 ON u1.user_id = a.assigned_by 
         LEFT JOIN users u2 ON u2.user_id = a.assigned_to 
         WHERE a.project_id = :id 
         ORDER BY a.created_at DESC 
         LIMIT 10",
        [':id' => $projectId]
    );
    
    // Get project statistics
    $stats = [
        'total_participants' => count($participants),
        'total_assignments' => $db->count('assignments', 'project_id = :id', [':id' => $projectId]),
        'completed_assignments' => $db->count('assignments', 'project_id = :id AND status = :status', [':id' => $projectId, ':status' => 'completed']),
        'days_running' => 0
    ];
    
    // Calculate days running
    if ($project['start_date']) {
        try {
            $start = new DateTime($project['start_date']);
            $now = new DateTime();
            $stats['days_running'] = max(0, $start->diff($now)->days);
        } catch (Exception $e) {
            $stats['days_running'] = 0;
        }
    }
    
    // Check permissions
    $canEdit = hasPermission('project_management') || $project['created_by'] === $currentUser['user_id'];
    $canJoin = !$isParticipant && in_array($project['status'], ['planning', 'active']);
    $canManageRoles = hasPermission('project_management');
    
} catch (Exception $e) {
    error_log("Project view error: " . $e->getMessage());
    redirect('list.php', 'Error loading project.', 'error');
}

$pageTitle = ($project ? $project['title'] : 'Project') . ' - Project Details';
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
            --success-color: #00B894;
            --warning-color: #FDCB6E;
            --danger-color: #E84393;
            --dark-color: #2D3436;
            --white: #FFFFFF;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --shadow: 0 4px 20px rgba(108, 92, 231, 0.1);
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
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 1rem 2rem;
            box-shadow: var(--shadow);
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
        
        .project-header {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .project-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 2rem;
        }
        
        .project-info {
            flex: 1;
        }
        
        .project-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
        }
        
        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .meta-icon {
            color: var(--primary-color);
        }
        
        .project-description {
            color: var(--gray-600);
            font-size: 1.1rem;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        
        .project-badges {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .header-actions {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: flex-end;
        }
        
        .status-badge, .priority-badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-planning { background: rgba(108, 92, 231, 0.1); color: var(--primary-color); }
        .status-active { background: rgba(0, 184, 148, 0.1); color: var(--success-color); }
        .status-completed { background: rgba(116, 185, 255, 0.1); color: #74B9FF; }
        .status-on-hold { background: rgba(253, 203, 110, 0.1); color: var(--warning-color); }
        .status-cancelled { background: rgba(232, 67, 147, 0.1); color: var(--danger-color); }
        
        .priority-low { background: rgba(116, 185, 255, 0.1); color: #74B9FF; }
        .priority-medium { background: rgba(253, 203, 110, 0.1); color: var(--warning-color); }
        .priority-high { background: rgba(253, 121, 168, 0.1); color: #FD79A8; }
        .priority-urgent { background: rgba(232, 67, 147, 0.1); color: var(--danger-color); }
        
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
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
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
        }
        
        .content-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        .content-section {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-action {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .participants-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .participant-card {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .participant-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .participant-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
        }
        
        .participant-details h4 {
            margin: 0;
            color: var(--dark-color);
            font-size: 0.95rem;
        }
        
        .participant-details p {
            margin: 0;
            color: var(--gray-600);
            font-size: 0.8rem;
        }
        
        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 0.5rem;
            display: inline-block;
        }
        
        .role-leader { background: rgba(232, 67, 147, 0.1); color: var(--danger-color); }
        .role-member { background: rgba(108, 92, 231, 0.1); color: var(--primary-color); }
        .role-observer { background: rgba(253, 203, 110, 0.1); color: var(--warning-color); }
        
        .role-selector {
            padding: 0.25rem;
            border: 1px solid var(--gray-300);
            border-radius: 4px;
            font-size: 0.7rem;
            margin-top: 0.5rem;
        }
        
        .assignment-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .assignment-item {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        
        .assignment-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }
        
        .assignment-meta {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .assignment-status {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-assigned { background: rgba(116, 185, 255, 0.1); color: #74B9FF; }
        .status-in-progress { background: rgba(253, 203, 110, 0.1); color: var(--warning-color); }
        .status-submitted { background: rgba(162, 155, 254, 0.1); color: var(--secondary-color); }
        .status-completed { background: rgba(0, 184, 148, 0.1); color: var(--success-color); }
        
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
        
        .btn-success {
            background: var(--success-color);
            color: var(--white);
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
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray-600);
        }
        
        .empty-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
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
        
        .alert-warning {
            background: rgba(253, 203, 110, 0.1);
            border-color: var(--warning-color);
            color: var(--warning-color);
        }
        
        .status-update {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .status-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .status-form select {
            padding: 0.5rem;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        @media (max-width: 1024px) {
            .content-layout {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                align-items: flex-start;
                flex-direction: row;
                flex-wrap: wrap;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .project-title {
                font-size: 2rem;
            }
            
            .project-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .participants-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
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
    
    <main class="main-content">
        <div class="breadcrumb">
            <a href="../../dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <a href="list.php">Projects</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo htmlspecialchars($project['title'] ?? 'Unknown'); ?></span>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($project): ?>
        <div class="project-header">
            <div class="header-content">
                <div class="project-info">
                    <h1 class="project-title"><?php echo htmlspecialchars($project['title']); ?></h1>
                    
                    <div class="project-meta">
                        <div class="meta-item">
                            <i class="fas fa-user meta-icon"></i>
                            Created by <?php echo htmlspecialchars($project['creator_first_name'] . ' ' . $project['creator_last_name']); ?>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-calendar meta-icon"></i>
                            <?php echo date('M j, Y', strtotime($project['created_at'])); ?>
                        </div>
                        <?php if ($project['start_date']): ?>
                            <div class="meta-item">
                                <i class="fas fa-calendar-start meta-icon"></i>
                                Starts <?php echo date('M j, Y', strtotime($project['start_date'])); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($project['end_date']): ?>
                            <div class="meta-item">
                                <i class="fas fa-calendar-check meta-icon"></i>
                                Ends <?php echo date('M j, Y', strtotime($project['end_date'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="project-description">
                        <?php echo nl2br(htmlspecialchars($project['description'])); ?>
                    </div>
                    
                    <div class="project-badges">
                        <span class="status-badge status-<?php echo str_replace('_', '-', $project['status']); ?>">
                            <?php echo PROJECT_STATUSES[$project['status']]['label'] ?? ucfirst($project['status']); ?>
                        </span>
                        <span class="priority-badge priority-<?php echo $project['priority']; ?>">
                            <?php echo PRIORITY_LEVELS[$project['priority']]['label'] ?? ucfirst($project['priority']); ?> Priority
                        </span>
                    </div>
                </div>
                
                <div class="header-actions">
                    <?php if ($canEdit): ?>
                        <a href="create.php?id=<?php echo $project['project_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Project
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($canJoin): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="join_project">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> Join Project
                            </button>
                        </form>
                    <?php elseif ($isParticipant && $isParticipant['role_in_project'] !== 'leader'): ?>
                        <form method="POST" style="display: inline;" 
                              onsubmit="return confirm('Are you sure you want to leave this project?')">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="leave_project">
                            <button type="submit" class="btn btn-outline">
                                <i class="fas fa-sign-out-alt"></i> Leave Project
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="list.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Projects
                    </a>
                </div>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_participants']; ?></div>
                <div class="stat-label">Participants</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_assignments']; ?></div>
                <div class="stat-label">Total Tasks</div>
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
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-value"><?php echo $stats['days_running']; ?></div>
                <div class="stat-label">Days Running</div>
            </div>
        </div>
        
        <div class="content-layout">
            <div class="main-content-area">
                <div class="content-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-tasks" style="color: var(--primary-color);"></i>
                            Recent Assignments
                        </h2>
                        <?php if (hasPermission('assignment_management')): ?>
                            <a href="../assignments/create.php?project_id=<?php echo $project['project_id']; ?>" class="section-action">
                                <i class="fas fa-plus"></i> Add Assignment
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($assignments)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h4>No assignments yet</h4>
                            <p>Assignments will appear here as they are created for this project.</p>
                        </div>
                    <?php else: ?>
                        <div class="assignment-list">
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="assignment-item">
                                    <div class="assignment-header">
                                        <div>
                                            <h4 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h4>
                                            <div class="assignment-meta">
                                                Assigned to <?php echo htmlspecialchars($assignment['assigned_to_name'] . ' ' . $assignment['assigned_to_lastname']); ?>
                                                by <?php echo htmlspecialchars($assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_lastname']); ?>
                                                <?php if ($assignment['due_date']): ?>
                                                    • Due <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="assignment-status status-<?php echo str_replace('_', '-', $assignment['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $assignment['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($stats['total_assignments'] > 10): ?>
                            <div style="text-align: center; margin-top: 1rem;">
                                <a href="../assignments/list.php?project_id=<?php echo $project['project_id']; ?>" class="btn btn-outline btn-sm">
                                    View All Assignments
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="sidebar-area">
                <?php if ($canEdit): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h3 class="section-title">
                                <i class="fas fa-cog" style="color: var(--primary-color);"></i>
                                Project Management
                            </h3>
                        </div>
                        
                        <div class="status-update">
                            <form method="POST" class="status-form">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="update_status">
                                <select name="new_status" onchange="this.form.submit()">
                                    <?php foreach (PROJECT_STATUSES as $statusKey => $statusData): ?>
                                        <option value="<?php echo $statusKey; ?>" 
                                                <?php echo $project['status'] === $statusKey ? 'selected' : ''; ?>>
                                            <?php echo $statusData['label']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">Update</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="content-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-users" style="color: var(--primary-color);"></i>
                            Participants (<?php echo count($participants); ?>)
                        </h3>
                    </div>
                    
                    <?php if (empty($participants)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-user-friends"></i>
                            </div>
                            <h4>No participants yet</h4>
                            <p>Be the first to join this project!</p>
                        </div>
                    <?php else: ?>
                        <div class="participants-grid">
                            <?php foreach ($participants as $participant): ?>
                                <div class="participant-card">
                                    <div class="participant-info">
                                        <div class="participant-avatar">
                                            <?php echo strtoupper(substr($participant['first_name'], 0, 1)); ?>
                                        </div>
                                        <div class="participant-details">
                                            <h4><?php echo htmlspecialchars($participant['first_name'] . ' ' . $participant['last_name']); ?></h4>
                                            <p><?php echo ucfirst($participant['user_role']); ?></p>
                                            <span class="role-badge role-<?php echo $participant['role_in_project']; ?>">
                                                <?php echo ucfirst($participant['role_in_project']); ?>
                                            </span>
                                            
                                            <?php if ($canManageRoles && $participant['user_id'] !== $currentUser['user_id']): ?>
                                                <form method="POST" style="display: inline; margin-top: 0.5rem;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="update_role">
                                                    <input type="hidden" name="user_id" value="<?php echo $participant['user_id']; ?>">
                                                    <select name="new_role" class="role-selector" onchange="this.form.submit()">
                                                        <option value="observer" <?php echo $participant['role_in_project'] === 'observer' ? 'selected' : ''; ?>>Observer</option>
                                                        <option value="member" <?php echo $participant['role_in_project'] === 'member' ? 'selected' : ''; ?>>Member</option>
                                                        <option value="leader" <?php echo $participant['role_in_project'] === 'leader' ? 'selected' : ''; ?>>Leader</option>
                                                    </select>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
    
    <script>
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Add loading states to form buttons
        document.querySelectorAll('form button').forEach(button => {
            button.addEventListener('click', function() {
                if (this.type === 'submit') {
                    this.style.opacity = '0.6';
                    this.disabled = true;
                }
            });
        });
        
        // Dynamic status update confirmation
        document.querySelectorAll('.status-form select').forEach(select => {
            select.addEventListener('change', function() {
                const newStatus = this.value;
                const currentStatus = '<?php echo $project['status'] ?? ''; ?>';
                
                if (newStatus !== currentStatus) {
                    const statusNames = <?php echo json_encode(array_map(function($s) { return $s['label']; }, PROJECT_STATUSES)); ?>;
                    const newStatusName = statusNames[newStatus] || newStatus;
                    
                    if (!confirm(`Change project status to "${newStatusName}"? All team members will be notified.`)) {
                        this.value = currentStatus;
                        return false;
                    }
                }
            });
        });
        
        // Role update confirmation
        document.querySelectorAll('.role-selector').forEach(select => {
            select.addEventListener('change', function() {
                const newRole = this.value;
                const participantCard = this.closest('.participant-card');
                const participantName = participantCard.querySelector('.participant-details h4').textContent;
                
                if (!confirm(`Change ${participantName}'s role to ${newRole}? They will be notified of this change.`)) {
                    // Reset to original value
                    this.selectedIndex = 0;
                    return false;
                }
            });
        });
    </script>
</body>
</html>