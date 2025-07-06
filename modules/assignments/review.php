 <?php
/**
 * Assignment Review & Grading - Girls Leadership Program
 * Beautiful interface for mentors/admins to review and grade assignment submissions
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Require assignment management permission
requirePermission('assignment_management');

// Get current user
$currentUser = getCurrentUser();

// Get assignment ID
$assignmentId = intval($_GET['id'] ?? 0);
if (!$assignmentId) {
    redirect('list.php', 'Assignment not found.', 'error');
}

// Initialize form data
$formData = [
    'grade' => '',
    'feedback' => ''
];

$errors = [];
$message = '';
$messageType = 'info';

// Load assignment and submission data
try {
    $db = Database::getInstance();
    
    // Get assignment details
    $assignment = $db->fetchRow(
        "SELECT a.*, u1.first_name as assigned_by_name, u1.last_name as assigned_by_lastname,
                u2.first_name as assigned_to_name, u2.last_name as assigned_to_lastname,
                p.title as project_title
         FROM assignments a 
         LEFT JOIN users u1 ON u1.user_id = a.assigned_by 
         LEFT JOIN users u2 ON u2.user_id = a.assigned_to 
         LEFT JOIN projects p ON p.project_id = a.project_id 
         WHERE a.assignment_id = :id",
        [':id' => $assignmentId]
    );
    
    if (!$assignment) {
        redirect('list.php', 'Assignment not found.', 'error');
    }
    
    // Check if current user can review this assignment
    if ($assignment['assigned_by'] !== $currentUser['user_id'] && !hasUserPermission('user_management')) {
        redirect('list.php', 'Access denied. You can only review assignments you created.', 'error');
    }
    
    // Get submission
    $submission = $db->fetchRow(
        "SELECT * FROM assignment_submissions WHERE assignment_id = :assignment_id",
        [':assignment_id' => $assignmentId]
    );
    
    if (!$submission) {
        redirect('list.php', 'No submission found for this assignment.', 'error');
    }
    
    // Load existing review data if any
    if ($submission['grade'] !== null || $submission['feedback']) {
        $formData = [
            'grade' => $submission['grade'] ?? '',
            'feedback' => $submission['feedback'] ?? ''
        ];
    }
    
} catch (Exception $e) {
    error_log("Assignment review load error: " . $e->getMessage());
    redirect('list.php', 'Error loading assignment.', 'error');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        // Sanitize and validate input
        $formData = [
            'grade' => intval($_POST['grade'] ?? 0),
            'feedback' => sanitizeInput($_POST['feedback'] ?? '')
        ];
        
        // Validation
        if ($formData['grade'] < 0 || $formData['grade'] > ($assignment['points'] ?: 100)) {
            $maxPoints = $assignment['points'] ?: 100;
            $errors[] = "Grade must be between 0 and {$maxPoints} points.";
        }
        
        if (empty($formData['feedback'])) {
            $errors[] = 'Feedback is required for grading.';
        } elseif (strlen($formData['feedback']) < 10) {
            $errors[] = 'Feedback must be at least 10 characters long.';
        }
        
        // If no errors, save the review
        if (empty($errors)) {
            try {
                $reviewData = [
                    'grade' => $formData['grade'],
                    'feedback' => $formData['feedback'],
                    'reviewed_by' => $currentUser['user_id'],
                    'reviewed_at' => date('Y-m-d H:i:s')
                ];
                
                // Update submission with review
                $db->update('assignment_submissions', $reviewData, 
                           'submission_id = :id', 
                           [':id' => $submission['submission_id']]);
                
                // Update assignment status to reviewed
                $db->update('assignments', ['status' => 'reviewed'], 
                           'assignment_id = :id', 
                           [':id' => $assignmentId]);
                
                // Create notification for the student
                $db->insert('notifications', [
                    'user_id' => $assignment['assigned_to'],
                    'title' => 'Assignment Reviewed',
                    'message' => "Your assignment '{$assignment['title']}' has been reviewed and graded",
                    'type' => 'success',
                    'action_url' => "/modules/assignments/list.php",
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                $message = 'Assignment reviewed and graded successfully.';
                $messageType = 'success';
                
                logActivity("Reviewed assignment: {$assignment['title']}", $currentUser['user_id']);
                
                // Update form data to show saved values
                $formData = $reviewData;
                
            } catch (Exception $e) {
                error_log("Error saving review: " . $e->getMessage());
                $errors[] = 'Error saving review. Please try again.';
            }
        }
    }
}

$pageTitle = 'Review Assignment - ' . $assignment['title'];
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
        
        /* Content Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        /* Assignment Details */
        .assignment-details {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .assignment-details::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .assignment-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
            line-height: 1.3;
        }
        
        .assignment-meta {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
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
            width: 16px;
        }
        
        .assignment-description {
            color: var(--gray-600);
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 1.5rem;
            background: var(--gray-100);
            padding: 1rem;
            border-radius: 8px;
        }
        
        .assignment-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        /* Status and Priority Badges */
        .status-badge, .priority-badge, .due-date-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-submitted {
            background: rgba(162, 155, 254, 0.1);
            color: var(--secondary-color);
        }
        
        .status-reviewed {
            background: rgba(253, 121, 168, 0.1);
            color: var(--accent-color);
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
            background: var(--gray-200);
            color: var(--gray-600);
        }
        
        .due-date-badge.overdue {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
        }
        
        /* Submission Details */
        .submission-details {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
        }
        
        .submission-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }
        
        .submission-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--success-color);
        }
        
        .submission-date {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .submission-content {
            margin-bottom: 2rem;
        }
        
        .submission-text {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            line-height: 1.7;
            white-space: pre-wrap;
        }
        
        .submission-file {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--gray-100);
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .file-icon {
            font-size: 2rem;
            color: var(--primary-color);
        }
        
        .file-info h4 {
            margin: 0;
            color: var(--dark-color);
        }
        
        .file-info p {
            margin: 0;
            color: var(--gray-600);
            font-size: 0.8rem;
        }
        
        .file-actions {
            margin-left: auto;
        }
        
        /* Review Form */
        .review-form {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
            grid-column: 1 / -1;
            margin-top: 2rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 1rem;
        }
        
        .form-group label .required {
            color: var(--danger-color);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
        }
        
        .form-control.error {
            border-color: var(--danger-color);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 150px;
        }
        
        .form-help {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-top: 0.5rem;
        }
        
        /* Grade Input */
        .grade-input {
            position: relative;
        }
        
        .grade-input input {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .grade-max {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
            font-size: 0.9rem;
        }
        
        /* Existing Review */
        .existing-review {
            background: rgba(0, 184, 148, 0.1);
            border: 1px solid var(--success-color);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .review-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-weight: 600;
            color: var(--success-color);
        }
        
        .review-grade {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .review-feedback {
            background: var(--white);
            padding: 1rem;
            border-radius: 6px;
            line-height: 1.7;
            white-space: pre-wrap;
        }
        
        .review-meta {
            margin-top: 1rem;
            font-size: 0.8rem;
            color: var(--gray-600);
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
        
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray-200);
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
        
        .error-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .error-list li {
            margin-bottom: 0.5rem;
        }
        
        .error-list li:before {
            content: "•";
            color: var(--danger-color);
            margin-right: 0.5rem;
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
        
        /* Loading States */
        .btn.loading {
            opacity: 0.8;
            pointer-events: none;
            position: relative;
        }
        
        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid var(--white);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 0.5rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .content-layout {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .assignment-meta {
                font-size: 0.8rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                text-align: center;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .assignment-details, .submission-details, .review-form {
                padding: 1.5rem;
            }
            
            .section-title {
                font-size: 1.25rem;
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
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="../../dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <a href="list.php">Assignments</a>
            <i class="fas fa-chevron-right"></i>
            <span>Review Assignment</span>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Please correct the following errors:</strong>
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- Content Layout -->
        <div class="content-layout">
            <!-- Assignment Details -->
            <div class="assignment-details">
                <h2 class="section-title">
                    <i class="fas fa-clipboard-list"></i>
                    Assignment Details
                </h2>
                
                <h3 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                
                <div class="assignment-meta">
                    <div class="meta-item">
                        <i class="fas fa-user meta-icon"></i>
                        Assigned to: <?php echo htmlspecialchars($assignment['assigned_to_name'] . ' ' . $assignment['assigned_to_lastname']); ?>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-calendar meta-icon"></i>
                        Created: <?php echo date('M j, Y', strtotime($assignment['created_at'])); ?>
                    </div>
                    <?php if ($assignment['project_title']): ?>
                        <div class="meta-item">
                            <i class="fas fa-project-diagram meta-icon"></i>
                            Project: <?php echo htmlspecialchars($assignment['project_title']); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($assignment['points'] > 0): ?>
                        <div class="meta-item">
                            <i class="fas fa-star meta-icon"></i>
                            Points: <?php echo $assignment['points']; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($assignment['due_date']): ?>
                        <div class="meta-item">
                            <i class="fas fa-clock meta-icon"></i>
                            Due: <?php echo date('M j, Y \a\t g:i A', strtotime($assignment['due_date'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="assignment-description">
                    <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                </div>
                
                <div class="assignment-badges">
                    <span class="status-badge status-<?php echo str_replace('_', '-', $assignment['status']); ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $assignment['status'])); ?>
                    </span>
                    <span class="priority-badge priority-<?php echo $assignment['priority']; ?>">
                        <?php echo ucfirst($assignment['priority']); ?> Priority
                    </span>
                </div>
            </div>
            
            <!-- Submission Details -->
            <div class="submission-details">
                <div class="submission-header">
                    <h2 class="section-title">
                        <i class="fas fa-file-upload"></i>
                        Submission
                    </h2>
                    <div class="submission-status">
                        <i class="fas fa-check-circle"></i>
                        Submitted
                    </div>
                </div>
                
                <div class="submission-date">
                    Submitted on <?php echo date('M j, Y \a\t g:i A', strtotime($submission['submitted_at'])); ?>
                </div>
                
                <div class="submission-content">
                    <?php if ($submission['submission_text']): ?>
                        <h4 style="margin-bottom: 1rem;">Written Submission:</h4>
                        <div class="submission-text">
                            <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($submission['file_path']): ?>
                        <h4 style="margin-bottom: 1rem;">Attached File:</h4>
                        <div class="submission-file">
                            <div class="file-icon">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="file-info">
                                <h4><?php echo htmlspecialchars(basename($submission['file_path'])); ?></h4>
                                <p>Uploaded file</p>
                            </div>
                            <div class="file-actions">
                                <a href="../../assets/uploads/<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                   target="_blank" class="btn btn-outline btn-sm">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Existing Review (if any) -->
        <?php if ($submission['reviewed_by']): ?>
            <div class="existing-review">
                <div class="review-header">
                    <i class="fas fa-star"></i>
                    Previous Review
                </div>
                
                <div class="review-grade">
                    <?php echo $submission['grade']; ?> / <?php echo $assignment['points'] ?: 100; ?> points
                </div>
                
                <div class="review-feedback">
                    <?php echo nl2br(htmlspecialchars($submission['feedback'])); ?>
                </div>
                
                <div class="review-meta">
                    Reviewed on <?php echo date('M j, Y \a\t g:i A', strtotime($submission['reviewed_at'])); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Review Form -->
        <div class="review-form">
            <h2 class="section-title">
                <i class="fas fa-clipboard-check"></i>
                <?php echo $submission['reviewed_by'] ? 'Update Review' : 'Grade Assignment'; ?>
            </h2>
            
            <form method="POST" action="" id="reviewForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-grid">
                    <!-- Grade -->
                    <div class="form-group">
                        <label for="grade">Grade <span class="required">*</span></label>
                        <div class="grade-input">
                            <input type="number" id="grade" name="grade" class="form-control" 
                                   min="0" max="<?php echo $assignment['points'] ?: 100; ?>" step="1"
                                   value="<?php echo htmlspecialchars($formData['grade']); ?>"
                                   placeholder="0" required>
                            <span class="grade-max">/ <?php echo $assignment['points'] ?: 100; ?></span>
                        </div>
                        <div class="form-help">Enter a score out of <?php echo $assignment['points'] ?: 100; ?> points.</div>
                    </div>
                    
                    <!-- Feedback -->
                    <div class="form-group">
                        <label for="feedback">Feedback <span class="required">*</span></label>
                        <textarea id="feedback" name="feedback" class="form-control" 
                                  placeholder="Provide detailed feedback on the student's work...&#10;&#10;• What did they do well?&#10;• Areas for improvement&#10;• Specific suggestions&#10;• Encouragement and next steps"
                                  required><?php echo htmlspecialchars($formData['feedback']); ?></textarea>
                        <div class="form-help">Provide constructive feedback to help the student learn and improve.</div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="list.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Assignments
                    </a>
                    
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-check"></i> 
                        <?php echo $submission['reviewed_by'] ? 'Update Review' : 'Submit Grade'; ?>
                    </button>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        // Enhanced textarea auto-resize
        const textarea = document.getElementById('feedback');
        
        function autoResize() {
            textarea.style.height = 'auto';
            textarea.style.height = Math.max(150, textarea.scrollHeight) + 'px';
        }
        
        textarea.addEventListener('input', autoResize);
        autoResize(); // Initialize
        
        // Grade input validation and visual feedback
        const gradeInput = document.getElementById('grade');
        const maxGrade = <?php echo $assignment['points'] ?: 100; ?>;
        
        gradeInput.addEventListener('input', function() {
            const value = parseInt(this.value);
            
            if (value > maxGrade) {
                this.value = maxGrade;
            } else if (value < 0) {
                this.value = 0;
            }
            
            // Visual feedback for grade ranges
            if (value >= maxGrade * 0.9) {
                this.style.color = 'var(--success-color)';
            } else if (value >= maxGrade * 0.7) {
                this.style.color = 'var(--warning-color)';
            } else if (value >= maxGrade * 0.5) {
                this.style.color = 'var(--accent-color)';
            } else {
                this.style.color = 'var(--danger-color)';
            }
        });
        
        // Trigger initial grade color
        if (gradeInput.value) {
            gradeInput.dispatchEvent(new Event('input'));
        }
        
        // Form submission handling
        document.getElementById('reviewForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const grade = document.getElementById('grade').value;
            const feedback = document.getElementById('feedback').value.trim();
            
            // Validate grade
            if (!grade || grade < 0 || grade > maxGrade) {
                e.preventDefault();
                alert(`Please enter a valid grade between 0 and ${maxGrade}.`);
                gradeInput.focus();
                return;
            }
            
            // Validate feedback
            if (!feedback || feedback.length < 10) {
                e.preventDefault();
                alert('Please provide detailed feedback (at least 10 characters).');
                textarea.focus();
                return;
            }
            
            // Add loading state
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });
        
        // Input validation feedback
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('blur', function() {
                if (this.hasAttribute('required') && !this.value.trim()) {
                    this.classList.add('error');
                } else {
                    this.classList.remove('error');
                }
            });
            
            input.addEventListener('input', function() {
                if (this.classList.contains('error') && this.value.trim()) {
                    this.classList.remove('error');
                }
            });
        });
        
        // Auto-hide success messages
        const alerts = document.querySelectorAll('.alert-success');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Form dirty state tracking
        let formDirty = false;
        
        document.querySelectorAll('input, textarea').forEach(input => {
            input.addEventListener('change', () => {
                formDirty = true;
            });
        });
        
        // Warn before leaving if form is dirty
        window.addEventListener('beforeunload', function(e) {
            if (formDirty && !document.getElementById('submitBtn').classList.contains('loading')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Don't warn when submitting form
        document.getElementById('reviewForm').addEventListener('submit', () => {
            formDirty = false;
        });
        
        // Grade suggestions based on feedback keywords
        textarea.addEventListener('input', function() {
            const feedbackText = this.value.toLowerCase();
            const gradeInput = document.getElementById('grade');
            
            // Only suggest if grade is empty
            if (!gradeInput.value) {
                if (feedbackText.includes('excellent') || feedbackText.includes('outstanding') || feedbackText.includes('perfect')) {
                    gradeInput.placeholder = Math.floor(maxGrade * 0.95);
                } else if (feedbackText.includes('good') || feedbackText.includes('well done') || feedbackText.includes('solid')) {
                    gradeInput.placeholder = Math.floor(maxGrade * 0.85);
                } else if (feedbackText.includes('satisfactory') || feedbackText.includes('adequate')) {
                    gradeInput.placeholder = Math.floor(maxGrade * 0.75);
                } else if (feedbackText.includes('needs improvement') || feedbackText.includes('weak')) {
                    gradeInput.placeholder = Math.floor(maxGrade * 0.65);
                } else {
                    gradeInput.placeholder = '0';
                }
            }
        });
    </script>
</body>
</html>
