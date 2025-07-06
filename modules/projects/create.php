 <?php
/**
 * Project Create/Edit Form - Girls Leadership Program
 * Beautiful interface for creating and editing leadership projects
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Require project management permission
requirePermission('project_management');

// Get current user
$currentUser = getCurrentUser();

// Determine if we're editing or creating
$projectId = intval($_GET['id'] ?? 0);
$isEdit = $projectId > 0;
$pageTitle = $isEdit ? 'Edit Project' : 'Create New Project';

// Initialize form data
$formData = [
    'title' => '',
    'description' => '',
    'status' => 'planning',
    'priority' => 'medium',
    'start_date' => '',
    'end_date' => '',
];

$errors = [];
$message = '';
$messageType = 'info';

// If editing, load existing project data
if ($isEdit) {
    try {
        $db = Database::getInstance();
        $project = $db->fetchRow(
            "SELECT * FROM projects WHERE project_id = :id",
            [':id' => $projectId]
        );
        
        if (!$project) {
            redirect('list.php', 'Project not found.', 'error');
        }
        
        // Check if user can edit this project (creator or admin)
        if ($project['created_by'] !== $currentUser['user_id'] && !hasUserPermission('user_management')) {
            redirect('list.php', 'Access denied.', 'error');
        }
        
        // Populate form data
        $formData = [
            'title' => $project['title'],
            'description' => $project['description'] ?? '',
            'status' => $project['status'],
            'priority' => $project['priority'],
            'start_date' => $project['start_date'] ? date('Y-m-d', strtotime($project['start_date'])) : '',
            'end_date' => $project['end_date'] ? date('Y-m-d', strtotime($project['end_date'])) : '',
        ];
        
    } catch (Exception $e) {
        error_log("Error loading project: " . $e->getMessage());
        redirect('list.php', 'Error loading project data.', 'error');
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
            'status' => sanitizeInput($_POST['status'] ?? ''),
            'priority' => sanitizeInput($_POST['priority'] ?? ''),
            'start_date' => sanitizeInput($_POST['start_date'] ?? ''),
            'end_date' => sanitizeInput($_POST['end_date'] ?? ''),
        ];
        
        // Validation
        if (empty($formData['title'])) {
            $errors[] = 'Project title is required.';
        } elseif (strlen($formData['title']) < 3) {
            $errors[] = 'Project title must be at least 3 characters long.';
        } elseif (strlen($formData['title']) > 200) {
            $errors[] = 'Project title must not exceed 200 characters.';
        }
        
        if (empty($formData['description'])) {
            $errors[] = 'Project description is required.';
        } elseif (strlen($formData['description']) < 10) {
            $errors[] = 'Project description must be at least 10 characters long.';
        }
        
        // Validate status
        $validStatuses = array_keys(PROJECT_STATUSES);
        if (!in_array($formData['status'], $validStatuses)) {
            $errors[] = 'Please select a valid project status.';
        }
        
        // Validate priority
        $validPriorities = array_keys(PRIORITY_LEVELS);
        if (!in_array($formData['priority'], $validPriorities)) {
            $errors[] = 'Please select a valid priority level.';
        }
        
        // Validate dates
        if (!empty($formData['start_date']) && !empty($formData['end_date'])) {
            $startDate = new DateTime($formData['start_date']);
            $endDate = new DateTime($formData['end_date']);
            
            if ($endDate <= $startDate) {
                $errors[] = 'End date must be after start date.';
            }
        }
        
        // If no errors, save the project
        if (empty($errors)) {
            try {
                $db = Database::getInstance();
                
                $projectData = $formData;
                $projectData['updated_at'] = date('Y-m-d H:i:s');
                
                // Convert empty dates to NULL
                if (empty($projectData['start_date'])) {
                    $projectData['start_date'] = null;
                }
                if (empty($projectData['end_date'])) {
                    $projectData['end_date'] = null;
                }
                
                if ($isEdit) {
                    // Update existing project
                    $db->update('projects', $projectData, 'project_id = :id', [':id' => $projectId]);
                    
                    $message = 'Project updated successfully.';
                    $messageType = 'success';
                    
                    logActivity("Project updated: {$formData['title']}", $currentUser['user_id']);
                    
                } else {
                    // Create new project
                    $projectData['created_by'] = $currentUser['user_id'];
                    $projectData['created_at'] = date('Y-m-d H:i:s');
                    
                    $newProjectId = $db->insert('projects', $projectData);
                    
                    // Add creator as project participant with leader role
                    $db->insert('project_participants', [
                        'project_id' => $newProjectId,
                        'user_id' => $currentUser['user_id'],
                        'role_in_project' => 'leader',
                        'joined_date' => date('Y-m-d H:i:s')
                    ]);
                    
                    $message = 'Project created successfully.';
                    $messageType = 'success';
                    
                    logActivity("Project created: {$formData['title']}", $currentUser['user_id']);
                    
                    // Redirect to view the new project
                    redirect("view.php?id={$newProjectId}", $message, $messageType);
                }
                
            } catch (Exception $e) {
                error_log("Error saving project: " . $e->getMessage());
                $errors[] = 'Error saving project data. Please try again.';
            }
        }
    }
}

// Get project status and priority definitions
$statusOptions = PROJECT_STATUSES;
$priorityOptions = PRIORITY_LEVELS;
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
        
        .breadcrumb a:hover {
            text-decoration: underline;
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
        
        /* Form Styles */
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
            font-size: 0.9rem;
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
        
        .form-control.error:focus {
            box-shadow: 0 0 0 3px rgba(232, 67, 147, 0.1);
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
        
        .character-counter {
            font-size: 0.8rem;
            color: var(--gray-600);
            text-align: right;
            margin-top: 0.25rem;
        }
        
        .character-counter.warning {
            color: var(--warning-color);
        }
        
        .character-counter.danger {
            color: var(--danger-color);
        }
        
        /* Custom Select Styling */
        .select-wrapper {
            position: relative;
        }
        
        .select-wrapper::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
            pointer-events: none;
        }
        
        select.form-control {
            appearance: none;
            background-image: none;
            padding-right: 3rem;
            cursor: pointer;
        }
        
        /* Status and Priority Preview */
        .status-preview {
            margin-top: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }
        
        .priority-preview {
            margin-top: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
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
        
        /* Date Input Styling */
        .date-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .date-input {
            position: relative;
        }
        
        .date-input input[type="date"] {
            padding-right: 3rem;
        }
        
        .date-input::after {
            content: '\f073';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
            pointer-events: none;
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
        
        .btn-danger {
            background: var(--danger-color);
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
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .date-group {
                grid-template-columns: 1fr;
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
            .page-header {
                text-align: center;
            }
            
            .form-container {
                padding: 1.5rem;
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
            <div class="breadcrumb">
                <a href="../../dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="list.php">Projects</a>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo $isEdit ? 'Edit Project' : 'New Project'; ?></span>
            </div>
            <h1 class="page-title"><?php echo $pageTitle; ?></h1>
            <p class="page-subtitle">
                <?php echo $isEdit ? 'Update project information and settings' : 'Create a new leadership project for participants'; ?>
            </p>
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
        
        <!-- Project Form -->
        <div class="form-container">
            <form method="POST" action="" id="projectForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-grid">
                    <!-- Project Title -->
                    <div class="form-group full-width">
                        <label for="title">Project Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" class="form-control" 
                               placeholder="Enter a clear, descriptive project title" 
                               value="<?php echo htmlspecialchars($formData['title']); ?>" 
                               maxlength="200"
                               required>
                        <div class="character-counter">
                            <span id="titleCount">0</span> / 200 characters
                        </div>
                    </div>
                    
                    <!-- Project Description -->
                    <div class="form-group full-width">
                        <label for="description">Project Description <span class="required">*</span></label>
                        <textarea id="description" name="description" class="form-control" 
                                  placeholder="Describe the project goals, activities, and expected outcomes..."
                                  required><?php echo htmlspecialchars($formData['description']); ?></textarea>
                        <div class="form-help">Provide a detailed description that will help participants understand the project's purpose and activities.</div>
                    </div>
                    
                    <!-- Status -->
                    <div class="form-group">
                        <label for="status">Project Status <span class="required">*</span></label>
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
                        <div id="statusPreview" class="status-preview"></div>
                    </div>
                    
                    <!-- Priority -->
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
                        <div id="priorityPreview" class="priority-preview"></div>
                    </div>
                    
                    <!-- Date Range -->
                    <div class="form-group full-width">
                        <label>Project Timeline</label>
                        <div class="date-group">
                            <div class="date-input">
                                <label for="start_date" style="font-size: 0.8rem; margin-bottom: 0.25rem;">Start Date</label>
                                <input type="date" id="start_date" name="start_date" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['start_date']); ?>">
                            </div>
                            <div class="date-input">
                                <label for="end_date" style="font-size: 0.8rem; margin-bottom: 0.25rem;">End Date</label>
                                <input type="date" id="end_date" name="end_date" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['end_date']); ?>">
                            </div>
                        </div>
                        <div class="form-help">Set optional start and end dates to help participants plan their involvement.</div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="list.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    
                    <?php if ($isEdit): ?>
                        <button type="submit" class="btn btn-success" id="submitBtn">
                            <i class="fas fa-save"></i> Update Project
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus"></i> Create Project
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        // Status and priority options for JavaScript
        const statusOptions = <?php echo json_encode($statusOptions); ?>;
        const priorityOptions = <?php echo json_encode($priorityOptions); ?>;
        
        // Character counter for title
        const titleInput = document.getElementById('title');
        const titleCounter = document.getElementById('titleCount');
        
        function updateTitleCounter() {
            const length = titleInput.value.length;
            titleCounter.textContent = length;
            
            if (length > 180) {
                titleCounter.parentElement.className = 'character-counter danger';
            } else if (length > 150) {
                titleCounter.parentElement.className = 'character-counter warning';
            } else {
                titleCounter.parentElement.className = 'character-counter';
            }
        }
        
        titleInput.addEventListener('input', updateTitleCounter);
        updateTitleCounter(); // Initialize
        
        // Status preview
        const statusSelect = document.getElementById('status');
        const statusPreview = document.getElementById('statusPreview');
        
        function updateStatusPreview() {
            const selectedStatus = statusSelect.value;
            if (statusOptions[selectedStatus]) {
                statusPreview.textContent = statusOptions[selectedStatus].label;
                statusPreview.className = 'status-preview status-' + selectedStatus;
            }
        }
        
        statusSelect.addEventListener('change', updateStatusPreview);
        updateStatusPreview(); // Initialize
        
        // Priority preview
        const prioritySelect = document.getElementById('priority');
        const priorityPreview = document.getElementById('priorityPreview');
        
        function updatePriorityPreview() {
            const selectedPriority = prioritySelect.value;
            if (priorityOptions[selectedPriority]) {
                priorityPreview.textContent = selectedPriority.charAt(0).toUpperCase() + selectedPriority.slice(1) + ' Priority';
                priorityPreview.className = 'priority-preview priority-' + selectedPriority;
            }
        }
        
        prioritySelect.addEventListener('change', updatePriorityPreview);
        updatePriorityPreview(); // Initialize
        
        // Date validation
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        
        function validateDates() {
            if (startDateInput.value && endDateInput.value) {
                const startDate = new Date(startDateInput.value);
                const endDate = new Date(endDateInput.value);
                
                if (endDate <= startDate) {
                    endDateInput.setCustomValidity('End date must be after start date');
                } else {
                    endDateInput.setCustomValidity('');
                }
            } else {
                endDateInput.setCustomValidity('');
            }
        }
        
        startDateInput.addEventListener('change', validateDates);
        endDateInput.addEventListener('change', validateDates);
        
        // Set minimum date to today for new projects
        <?php if (!$isEdit): ?>
        const today = new Date().toISOString().split('T')[0];
        startDateInput.setAttribute('min', today);
        endDateInput.setAttribute('min', today);
        <?php endif; ?>
        
        // Auto-update end date minimum when start date changes
        startDateInput.addEventListener('change', function() {
            if (this.value) {
                endDateInput.setAttribute('min', this.value);
            }
        });
        
        // Form submission handling
        document.getElementById('projectForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            
            // Validate required fields
            if (!titleInput.value.trim()) {
                e.preventDefault();
                titleInput.focus();
                alert('Please enter a project title.');
                return;
            }
            
            if (!document.getElementById('description').value.trim()) {
                e.preventDefault();
                document.getElementById('description').focus();
                alert('Please enter a project description.');
                return;
            }
            
            // Validate dates
            validateDates();
            if (!endDateInput.checkValidity()) {
                e.preventDefault();
                alert('Please ensure the end date is after the start date.');
                return;
            }
            
            // Add loading state
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });
        
        // Enhanced textarea auto-resize
        const textarea = document.getElementById('description');
        
        function autoResize() {
            textarea.style.height = 'auto';
            textarea.style.height = Math.max(120, textarea.scrollHeight) + 'px';
        }
        
        textarea.addEventListener('input', autoResize);
        autoResize(); // Initialize
        
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
        
        document.querySelectorAll('input, textarea, select').forEach(input => {
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
        document.getElementById('projectForm').addEventListener('submit', () => {
            formDirty = false;
        });
    </script>
</body>
</html>
