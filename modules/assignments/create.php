<?php
/**
 * Assignment Create/Edit Form - Girls Leadership Program
 * Complete error-free module with notification integration
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';
require_once APP_ROOT . '/includes/functions.php';

// Require assignment management permission
if (!hasPermission('assignment_management')) {
    redirect('../../dashboard.php', 'Access denied. You do not have permission to manage assignments.', 'error');
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    redirect('../../login.php', 'Please log in to continue.', 'error');
}

// Determine if we're editing or creating
$assignmentId = intval($_GET['id'] ?? 0);
$projectId = intval($_GET['project_id'] ?? 0);
$isEdit = $assignmentId > 0;
$pageTitle = $isEdit ? 'Edit Assignment' : 'Create New Assignment';

// Initialize form data
$formData = [
    'title' => '',
    'description' => '',
    'assigned_to' => '',
    'project_id' => $projectId,
    'due_date' => '',
    'priority' => 'medium',
    'status' => 'assigned',
    'points' => 0
];

$errors = [];
$message = '';
$messageType = 'info';

// If editing, load existing assignment data
if ($isEdit) {
    try {
        $db = Database::getInstance();
        $assignment = $db->fetchRow(
            "SELECT * FROM assignments WHERE assignment_id = :id",
            [':id' => $assignmentId]
        );
        
        if (!$assignment) {
            redirect('list.php', 'Assignment not found.', 'error');
        }
        
        // Check if user can edit this assignment
        if ($assignment['assigned_by'] !== $currentUser['user_id'] && !hasPermission('user_management')) {
            redirect('list.php', 'Access denied.', 'error');
        }
        
        // Populate form data
        $formData = [
            'title' => $assignment['title'],
            'description' => $assignment['description'] ?? '',
            'assigned_to' => $assignment['assigned_to'],
            'project_id' => $assignment['project_id'] ?? 0,
            'due_date' => $assignment['due_date'] ? date('Y-m-d\TH:i', strtotime($assignment['due_date'])) : '',
            'priority' => $assignment['priority'],
            'status' => $assignment['status'],
            'points' => $assignment['points'] ?? 0
        ];
        
    } catch (Exception $e) {
        error_log("Error loading assignment: " . $e->getMessage());
        redirect('list.php', 'Error loading assignment data.', 'error');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        // Sanitize and validate input
        $formData = [
            'title' => sanitizeInput($_POST['title'] ?? ''),
            'description' => sanitizeInput($_POST['description'] ?? ''),
            'assigned_to' => intval($_POST['assigned_to'] ?? 0),
            'project_id' => intval($_POST['project_id'] ?? 0),
            'due_date' => sanitizeInput($_POST['due_date'] ?? ''),
            'priority' => sanitizeInput($_POST['priority'] ?? ''),
            'status' => sanitizeInput($_POST['status'] ?? ''),
            'points' => intval($_POST['points'] ?? 0)
        ];
        
        // Validation
        if (empty($formData['title'])) {
            $errors[] = 'Assignment title is required.';
        } elseif (strlen($formData['title']) < 3) {
            $errors[] = 'Assignment title must be at least 3 characters long.';
        } elseif (strlen($formData['title']) > 200) {
            $errors[] = 'Assignment title must not exceed 200 characters.';
        }
        
        if (empty($formData['description'])) {
            $errors[] = 'Assignment description is required.';
        } elseif (strlen($formData['description']) < 10) {
            $errors[] = 'Assignment description must be at least 10 characters long.';
        }
        
        if ($formData['assigned_to'] <= 0) {
            $errors[] = 'Please select a participant to assign this to.';
        }
        
        // Validate priority
        $validPriorities = ['low', 'medium', 'high', 'urgent'];
        if (!in_array($formData['priority'], $validPriorities)) {
            $errors[] = 'Please select a valid priority level.';
        }
        
        // Validate status
        $validStatuses = ['assigned', 'in_progress', 'submitted', 'reviewed', 'completed'];
        if (!in_array($formData['status'], $validStatuses)) {
            $errors[] = 'Please select a valid assignment status.';
        }
        
        // Validate due date
        if (!empty($formData['due_date'])) {
            try {
                $dueDate = new DateTime($formData['due_date']);
                $now = new DateTime();
                
                if ($dueDate <= $now) {
                    $errors[] = 'Due date must be in the future.';
                }
            } catch (Exception $e) {
                $errors[] = 'Invalid due date format.';
            }
        }
        
        // Validate points
        if ($formData['points'] < 0 || $formData['points'] > 1000) {
            $errors[] = 'Points must be between 0 and 1000.';
        }
        
        // Check if assigned user exists and has appropriate role
        $assignedUser = null;
        if ($formData['assigned_to'] > 0) {
            try {
                $db = Database::getInstance();
                $assignedUser = $db->fetchRow(
                    "SELECT user_id, role, first_name, last_name FROM users WHERE user_id = :id AND is_active = 1",
                    [':id' => $formData['assigned_to']]
                );
                
                if (!$assignedUser) {
                    $errors[] = 'Selected user not found or inactive.';
                } elseif (!in_array($assignedUser['role'], ['participant', 'mentor', 'volunteer'])) {
                    $errors[] = 'Assignments can only be assigned to participants, mentors, or volunteers.';
                }
            } catch (Exception $e) {
                $errors[] = 'Error validating assigned user.';
                error_log("User validation error: " . $e->getMessage());
            }
        }
        
        // If no errors, save the assignment
        if (empty($errors) && $assignedUser) {
            try {
                $db = Database::getInstance();
                
                $assignmentData = [
                    'title' => $formData['title'],
                    'description' => $formData['description'],
                    'assigned_to' => $formData['assigned_to'],
                    'project_id' => $formData['project_id'] > 0 ? $formData['project_id'] : null,
                    'due_date' => !empty($formData['due_date']) ? $formData['due_date'] : null,
                    'priority' => $formData['priority'],
                    'status' => $formData['status'],
                    'points' => $formData['points'],
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if ($isEdit) {
                    // Update existing assignment
                    $db->update('assignments', $assignmentData, 'assignment_id = :id', [':id' => $assignmentId]);
                    
                    $message = 'Assignment updated successfully.';
                    $messageType = 'success';
                    
                    // Enhanced logging
                    if (function_exists('logAssignmentActivity')) {
                        logAssignmentActivity("updated", $currentUser['user_id'], $formData['title'], $assignmentId, "Form updated via web interface");
                    } else {
                        logActivity("Assignment updated: {$formData['title']}", $currentUser['user_id']);
                    }
                    
                } else {
                    // Create new assignment
                    $assignmentData['assigned_by'] = $currentUser['user_id'];
                    $assignmentData['created_at'] = date('Y-m-d H:i:s');
                    
                    $newAssignmentId = $db->insert('assignments', $assignmentData);
                    
                    // Create comprehensive notification
                    try {
                        $notificationResult = notifyAssignmentCreated($newAssignmentId, $assignmentData, $currentUser);
                        
                        if ($notificationResult) {
                            $message = 'Assignment created successfully and notifications sent.';
                        } else {
                            $message = 'Assignment created successfully, but notification failed.';
                        }
                        
                    } catch (Exception $e) {
                        error_log("Assignment notification error: " . $e->getMessage());
                        $message = 'Assignment created successfully, but notification system encountered an error.';
                    }
                    
                    // Enhanced logging
                    if (function_exists('logAssignmentActivity')) {
                        logAssignmentActivity("created", $currentUser['user_id'], $formData['title'], $newAssignmentId, "Assigned to: {$assignedUser['first_name']} {$assignedUser['last_name']}");
                    } else {
                        logActivity("Assignment created: {$formData['title']}", $currentUser['user_id']);
                    }
                    
                    $messageType = 'success';
                    
                    // Redirect to assignment list
                    redirect("list.php", $message, $messageType);
                }
                
            } catch (Exception $e) {
                error_log("Error saving assignment: " . $e->getMessage());
                $errors[] = 'Error saving assignment data. Please try again.';
            }
        }
    }
}

// Get users for assignment dropdown
$users = [];
$projects = [];

try {
    $db = Database::getInstance();
    
    $users = $db->fetchAll(
        "SELECT user_id, first_name, last_name, role 
         FROM users 
         WHERE is_active = 1 AND role IN ('participant', 'mentor', 'volunteer') 
         ORDER BY role, first_name, last_name"
    );
    
    // Get projects for dropdown
    $projects = $db->fetchAll(
        "SELECT project_id, title 
         FROM projects 
         WHERE status IN ('planning', 'active') 
         ORDER BY title"
    );
    
} catch (Exception $e) {
    error_log("Error loading users/projects: " . $e->getMessage());
    $errors[] = 'Error loading form data. Please refresh the page.';
}

// Status and priority options
$statusOptions = [
    'assigned' => ['label' => 'Assigned', 'color' => '#74B9FF'],
    'in_progress' => ['label' => 'In Progress', 'color' => '#FDCB6E'],
    'submitted' => ['label' => 'Submitted', 'color' => '#A29BFE'],
    'reviewed' => ['label' => 'Reviewed', 'color' => '#FD79A8'],
    'completed' => ['label' => 'Completed', 'color' => '#00B894']
];

$priorityOptions = [
    'low' => ['label' => 'Low', 'color' => '#74B9FF'],
    'medium' => ['label' => 'Medium', 'color' => '#FDCB6E'],
    'high' => ['label' => 'High', 'color' => '#FD79A8'],
    'urgent' => ['label' => 'Urgent', 'color' => '#E84393']
];
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
        
        .page-header {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray-600);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
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
        
        .form-container {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
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
        }
        
        .required {
            color: var(--danger-color);
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
        
        .form-control.error {
            border-color: var(--danger-color);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }
        
        .form-help {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-top: 0.25rem;
        }
        
        .select-wrapper {
            position: relative;
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
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
            
            .form-grid {
                grid-template-columns: 1fr;
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
        <div class="page-header">
            <div class="breadcrumb">
                <a href="../../dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="list.php">Assignments</a>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo $isEdit ? 'Edit Assignment' : 'New Assignment'; ?></span>
            </div>
            <h1 class="page-title"><?php echo $pageTitle; ?></h1>
            <p class="page-subtitle">
                <?php echo $isEdit ? 'Update assignment details and requirements' : 'Create a new assignment for participants to complete'; ?>
            </p>
        </div>
        
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
        
        <div class="form-container">
            <form method="POST" action="" id="assignmentForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="title">Assignment Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" class="form-control" 
                               placeholder="Enter a clear, descriptive assignment title" 
                               value="<?php echo htmlspecialchars($formData['title']); ?>" 
                               maxlength="200" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="description">Assignment Description <span class="required">*</span></label>
                        <textarea id="description" name="description" class="form-control" 
                                  placeholder="Describe the assignment requirements, goals, and deliverables..."
                                  required><?php echo htmlspecialchars($formData['description']); ?></textarea>
                        <div class="form-help">Provide clear instructions and expectations for this assignment.</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="assigned_to">Assign To <span class="required">*</span></label>
                        <div class="select-wrapper">
                            <select id="assigned_to" name="assigned_to" class="form-control" required>
                                <option value="">Select participant...</option>
                                <?php 
                                $currentRole = '';
                                foreach ($users as $user): 
                                    if ($currentRole !== $user['role']) {
                                        if ($currentRole !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . ucfirst($user['role']) . 's">';
                                        $currentRole = $user['role'];
                                    }
                                ?>
                                    <option value="<?php echo $user['user_id']; ?>" 
                                            <?php echo $formData['assigned_to'] == $user['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($currentRole !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="project_id">Related Project</label>
                        <div class="select-wrapper">
                            <select id="project_id" name="project_id" class="form-control">
                                <option value="">No specific project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['project_id']; ?>" 
                                            <?php echo $formData['project_id'] == $project['project_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="due_date">Due Date & Time</label>
                        <input type="datetime-local" id="due_date" name="due_date" class="form-control" 
                               value="<?php echo htmlspecialchars($formData['due_date']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="priority">Priority Level <span class="required">*</span></label>
                        <div class="select-wrapper">
                            <select id="priority" name="priority" class="form-control" required>
                                <?php foreach ($priorityOptions as $priorityKey => $priorityData): ?>
                                    <option value="<?php echo $priorityKey; ?>" 
                                            <?php echo $formData['priority'] === $priorityKey ? 'selected' : ''; ?>>
                                        <?php echo $priorityData['label']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Assignment Status <span class="required">*</span></label>
                        <div class="select-wrapper">
                            <select id="status" name="status" class="form-control" required>
                                <?php foreach ($statusOptions as $statusKey => $statusData): ?>
                                    <option value="<?php echo $statusKey; ?>" 
                                            <?php echo $formData['status'] === $statusKey ? 'selected' : ''; ?>>
                                        <?php echo $statusData['label']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="points">Points Value</label>
                        <input type="number" id="points" name="points" class="form-control" 
                               min="0" max="1000" step="5"
                               value="<?php echo htmlspecialchars($formData['points']); ?>"
                               placeholder="0">
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="list.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    
                    <?php if ($isEdit): ?>
                        <button type="submit" class="btn btn-success" id="submitBtn">
                            <i class="fas fa-save"></i> Update Assignment
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus"></i> Create Assignment
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        document.getElementById('assignmentForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (<?php echo $isEdit ? 'true' : 'false'; ?> ? 'Updating...' : 'Creating...');
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