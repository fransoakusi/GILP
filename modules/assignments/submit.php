<?php
/**
 * Assignment Submission Page - Girls Leadership Program
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

// Get assignment ID
$assignmentId = intval($_GET['id'] ?? 0);
if (!$assignmentId) {
    redirect('list.php', 'Assignment not found.', 'error');
}

// Initialize form data
$formData = [
    'submission_text' => '',
    'file_path' => ''
];

$errors = [];
$message = '';
$messageType = 'info';

// Load assignment data
$assignment = null;
$existingSubmission = null;

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
    
    // Check if current user can submit this assignment
    if ($assignment['assigned_to'] !== $currentUser['user_id']) {
        redirect('list.php', 'Access denied. This assignment is not assigned to you.', 'error');
    }
    
    // Check if assignment can be submitted
    if (!in_array($assignment['status'], ['assigned', 'in_progress'])) {
        redirect('list.php', 'This assignment cannot be modified anymore.', 'error');
    }
    
    // Get existing submission if any
    $existingSubmission = $db->fetchRow(
        "SELECT * FROM assignment_submissions WHERE assignment_id = :assignment_id AND submitted_by = :user_id",
        [':assignment_id' => $assignmentId, ':user_id' => $currentUser['user_id']]
    );
    
    if ($existingSubmission) {
        $formData = [
            'submission_text' => $existingSubmission['submission_text'] ?? '',
            'file_path' => $existingSubmission['file_path'] ?? ''
        ];
    }
    
} catch (Exception $e) {
    error_log("Assignment submission load error: " . $e->getMessage());
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
            'submission_text' => sanitizeInput($_POST['submission_text'] ?? ''),
            'file_path' => ''
        ];
        
        // Validation
        if (empty($formData['submission_text']) && empty($_FILES['assignment_file']['name'])) {
            $errors[] = 'Please provide either text submission or upload a file.';
        }
        
        if (!empty($formData['submission_text']) && strlen($formData['submission_text']) < 10) {
            $errors[] = 'Text submission must be at least 10 characters long.';
        }
        
        // Handle file upload if provided
        $uploadedFile = null;
        if (!empty($_FILES['assignment_file']['name'])) {
            try {
                // Validate file
                $file = $_FILES['assignment_file'];
                $maxSize = 10 * 1024 * 1024; // 10MB
                $allowedTypes = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip'];
                
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'File upload error. Please try again.';
                } elseif ($file['size'] > $maxSize) {
                    $errors[] = 'File size must be less than 10MB.';
                } else {
                    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($fileExt, $allowedTypes)) {
                        $errors[] = 'File type not allowed. Allowed types: ' . implode(', ', $allowedTypes);
                    } else {
                        // Create upload directory if it doesn't exist
                        $uploadDir = APP_ROOT . '/assets/uploads/assignments/';
                        if (!file_exists($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        
                        // Generate unique filename
                        $fileName = uniqid() . '_' . time() . '.' . $fileExt;
                        $uploadPath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                            $formData['file_path'] = 'assignments/' . $fileName;
                            
                            // Save file record
                            try {
                                $fileRecord = [
                                    'filename' => $fileName,
                                    'original_filename' => $file['name'],
                                    'file_path' => $formData['file_path'],
                                    'file_size' => $file['size'],
                                    'file_type' => $file['type'],
                                    'uploaded_by' => $currentUser['user_id'],
                                    'upload_purpose' => 'assignment',
                                    'related_id' => $assignmentId,
                                    'uploaded_at' => date('Y-m-d H:i:s')
                                ];
                                
                                $uploadedFile = $db->insert('file_uploads', $fileRecord);
                            } catch (Exception $e) {
                                error_log("File record save error: " . $e->getMessage());
                            }
                        } else {
                            $errors[] = 'Error uploading file. Please try again.';
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("File upload error: " . $e->getMessage());
                $errors[] = 'Error processing file upload.';
            }
        }
        
        // If no errors, save the submission
        if (empty($errors)) {
            try {
                $submissionData = [
                    'assignment_id' => $assignmentId,
                    'submitted_by' => $currentUser['user_id'],
                    'submission_text' => $formData['submission_text'],
                    'file_path' => $formData['file_path'],
                    'submitted_at' => date('Y-m-d H:i:s')
                ];
                
                if ($existingSubmission) {
                    // Update existing submission
                    $db->update('assignment_submissions', $submissionData, 
                               'submission_id = :id', 
                               [':id' => $existingSubmission['submission_id']]);
                    
                    $message = 'Assignment submission updated successfully.';
                } else {
                    // Create new submission
                    $db->insert('assignment_submissions', $submissionData);
                    $message = 'Assignment submitted successfully.';
                }
                
                // Create comprehensive notification
                try {
                    $notificationResult = notifyAssignmentSubmitted($assignmentId, $assignment, $currentUser);
                    
                    if ($notificationResult) {
                        $message .= ' Notification sent to your instructor.';
                    } else {
                        $message .= ' However, notification failed to send.';
                    }
                    
                } catch (Exception $e) {
                    error_log("Assignment submission notification error: " . $e->getMessage());
                    $message .= ' However, notification system encountered an error.';
                }
                
                // Enhanced logging
                if (function_exists('logAssignmentActivity')) {
                    logAssignmentActivity("submitted", $currentUser['user_id'], $assignment['title'], $assignmentId, $existingSubmission ? "Updated submission" : "New submission");
                } else {
                    logActivity("Submitted assignment: {$assignment['title']}", $currentUser['user_id']);
                }
                
                $messageType = 'success';
                
            } catch (Exception $e) {
                error_log("Error saving submission: " . $e->getMessage());
                $errors[] = 'Error saving submission. Please try again.';
            }
        }
    }
}

$pageTitle = 'Submit Assignment - ' . ($assignment ? $assignment['title'] : 'Unknown');
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
            max-width: 1000px;
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
        
        .assignment-header {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .assignment-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .assignment-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
        }
        
        .assignment-meta {
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
        
        .assignment-description {
            color: var(--gray-600);
            font-size: 1.1rem;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        
        .assignment-badges {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .status-badge, .priority-badge, .due-date-badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-assigned {
            background: rgba(116, 185, 255, 0.1);
            color: #74B9FF;
        }
        
        .status-in-progress {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
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
            color: #FD79A8;
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
        
        .due-date-badge.due-soon {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .current-submission {
            background: var(--gray-100);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .submission-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }
        
        .submission-content {
            background: var(--white);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .submission-file {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: var(--gray-100);
            border-radius: 6px;
            margin-top: 1rem;
        }
        
        .submission-container {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
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
        
        .form-group {
            margin-bottom: 2rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 200px;
        }
        
        .form-help {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-top: 0.5rem;
        }
        
        .file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        
        .file-upload input[type="file"] {
            opacity: 0;
            position: absolute;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-upload-label {
            display: block;
            padding: 2rem;
            border: 2px dashed var(--gray-300);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--gray-100);
        }
        
        .file-upload-label:hover {
            border-color: var(--primary-color);
            background: rgba(108, 92, 231, 0.05);
        }
        
        .file-upload-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .file-upload-text {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .file-upload-hint {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .file-selected {
            background: rgba(0, 184, 148, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }
        
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
        
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray-200);
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
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .assignment-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .form-actions {
                flex-direction: column;
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
            <a href="list.php">Assignments</a>
            <i class="fas fa-chevron-right"></i>
            <span>Submit Assignment</span>
        </div>
        
        <?php if ($assignment): ?>
        <div class="assignment-header">
            <h1 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h1>
            
            <div class="assignment-meta">
                <div class="meta-item">
                    <i class="fas fa-user meta-icon"></i>
                    Assigned by <?php echo htmlspecialchars($assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_lastname']); ?>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar meta-icon"></i>
                    <?php echo date('M j, Y', strtotime($assignment['created_at'])); ?>
                </div>
                <?php if ($assignment['project_title']): ?>
                    <div class="meta-item">
                        <i class="fas fa-project-diagram meta-icon"></i>
                        <?php echo htmlspecialchars($assignment['project_title']); ?>
                    </div>
                <?php endif; ?>
                <?php if ($assignment['points'] > 0): ?>
                    <div class="meta-item">
                        <i class="fas fa-star meta-icon"></i>
                        <?php echo $assignment['points']; ?> points
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
                
                <?php if ($assignment['due_date']): 
                    $dueDate = new DateTime($assignment['due_date']);
                    $now = new DateTime();
                    $daysDiff = $now->diff($dueDate)->days;
                    
                    $dueBadgeClass = '';
                    if ($dueDate < $now) {
                        $dueBadgeClass = 'overdue';
                    } elseif ($daysDiff <= 3) {
                        $dueBadgeClass = 'due-soon';
                    }
                ?>
                    <span class="due-date-badge <?php echo $dueBadgeClass; ?>">
                        <i class="fas fa-clock"></i>
                        Due <?php echo $dueDate->format('M j, Y \a\t g:i A'); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
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
        
        <?php if ($existingSubmission): ?>
            <div class="current-submission">
                <div class="submission-status">
                    <i class="fas fa-file-check" style="color: var(--success-color);"></i>
                    You have already submitted this assignment
                    <span style="font-size: 0.8rem; color: var(--gray-600); margin-left: 0.5rem;">
                        (<?php echo date('M j, Y \a\t g:i A', strtotime($existingSubmission['submitted_at'])); ?>)
                    </span>
                </div>
                
                <?php if ($existingSubmission['submission_text']): ?>
                    <div class="submission-content">
                        <strong>Your Submission:</strong>
                        <p><?php echo nl2br(htmlspecialchars($existingSubmission['submission_text'])); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($existingSubmission['file_path']): ?>
                    <div class="submission-file">
                        <i class="fas fa-file"></i>
                        <span>Submitted file: <?php echo htmlspecialchars(basename($existingSubmission['file_path'])); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="submission-container">
            <h2 class="section-title">
                <i class="fas fa-upload"></i>
                <?php echo $existingSubmission ? 'Update Your Submission' : 'Submit Your Work'; ?>
            </h2>
            
            <form method="POST" action="" enctype="multipart/form-data" id="submissionForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="submission_text">Written Submission</label>
                    <textarea id="submission_text" name="submission_text" class="form-control" 
                              placeholder="Enter your assignment submission here...&#10;&#10;• Describe your work&#10;• Share your insights&#10;• Reflect on what you learned"><?php echo htmlspecialchars($formData['submission_text']); ?></textarea>
                    <div class="form-help">Provide a detailed written response to the assignment.</div>
                </div>
                
                <div class="form-group">
                    <label for="assignment_file">File Upload (Optional)</label>
                    <div class="file-upload">
                        <input type="file" id="assignment_file" name="assignment_file" 
                               accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip">
                        <label for="assignment_file" class="file-upload-label" id="fileLabel">
                            <div class="file-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="file-upload-text">Click to upload a file</div>
                            <div class="file-upload-hint">PDF, DOC, DOCX, TXT, JPG, PNG, ZIP (max 10MB)</div>
                        </label>
                    </div>
                    <div class="form-help">Upload supporting documents, images, or other files related to your assignment.</div>
                </div>
                
                <div class="form-actions">
                    <a href="list.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Assignments
                    </a>
                    
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-upload"></i> 
                        <?php echo $existingSubmission ? 'Update Submission' : 'Submit Assignment'; ?>
                    </button>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        // File upload handling
        const fileInput = document.getElementById('assignment_file');
        const fileLabel = document.getElementById('fileLabel');
        
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                const fileName = file.name;
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                fileLabel.className = 'file-upload-label file-selected';
                fileLabel.innerHTML = `
                    <div class="file-upload-icon">
                        <i class="fas fa-file-check"></i>
                    </div>
                    <div class="file-upload-text">File selected: ${fileName}</div>
                    <div class="file-upload-hint">Size: ${fileSize} MB</div>
                `;
            }
        });
        
        // Form submission handling
        document.getElementById('submissionForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const textSubmission = document.getElementById('submission_text').value.trim();
            const fileUpload = document.getElementById('assignment_file').files[0];
            
            if (!textSubmission && !fileUpload) {
                e.preventDefault();
                alert('Please provide either a written submission or upload a file.');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });
        
        // Auto-hide success messages
        const alerts = document.querySelectorAll('.alert-success');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
    </script>
</body>
</html>