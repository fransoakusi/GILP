 <?php
/**
 * Survey Create/Edit Form - Girls Leadership Program
 * Complete module with notification integration
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';
require_once APP_ROOT . '/includes/functions.php';

// Require survey management permission
if (!hasPermission('survey_management')) {
    redirect('../../dashboard.php', 'Access denied. You do not have permission to manage surveys.', 'error');
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    redirect('../../login.php', 'Please log in to continue.', 'error');
}

// Determine if we're editing or creating
$surveyId = intval($_GET['id'] ?? 0);
$isEdit = $surveyId > 0;
$pageTitle = $isEdit ? 'Edit Survey' : 'Create New Survey';

// Initialize form data
$formData = [
    'title' => '',
    'description' => '',
    'start_date' => '',
    'end_date' => '',
    'is_active' => 1,
    'is_anonymous' => 0
];

$questions = [];
$errors = [];
$message = '';
$messageType = 'info';

// If editing, load existing survey data
if ($isEdit) {
    try {
        $db = Database::getInstance();
        $survey = $db->fetchRow(
            "SELECT * FROM surveys WHERE survey_id = :id",
            [':id' => $surveyId]
        );
        
        if (!$survey) {
            redirect('list.php', 'Survey not found.', 'error');
        }
        
        // Check if user can edit this survey
        if ($survey['created_by'] !== $currentUser['user_id'] && !hasPermission('user_management')) {
            redirect('list.php', 'Access denied.', 'error');
        }
        
        // Populate form data
        $formData = [
            'title' => $survey['title'],
            'description' => $survey['description'] ?? '',
            'start_date' => $survey['start_date'] ? date('Y-m-d\TH:i', strtotime($survey['start_date'])) : '',
            'end_date' => $survey['end_date'] ? date('Y-m-d\TH:i', strtotime($survey['end_date'])) : '',
            'is_active' => $survey['is_active'],
            'is_anonymous' => $survey['is_anonymous']
        ];
        
        // Load existing questions
        $questions = $db->fetchAll(
            "SELECT * FROM survey_questions WHERE survey_id = :survey_id ORDER BY question_order",
            [':survey_id' => $surveyId]
        );
        
    } catch (Exception $e) {
        error_log("Error loading survey: " . $e->getMessage());
        redirect('list.php', 'Error loading survey data.', 'error');
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
            'start_date' => sanitizeInput($_POST['start_date'] ?? ''),
            'end_date' => sanitizeInput($_POST['end_date'] ?? ''),
            'is_active' => intval($_POST['is_active'] ?? 0),
            'is_anonymous' => intval($_POST['is_anonymous'] ?? 0)
        ];
        
        // Get questions data
        $questionsData = [];
        if (isset($_POST['questions']) && is_array($_POST['questions'])) {
            foreach ($_POST['questions'] as $index => $questionData) {
                $questionsData[] = [
                    'question_text' => sanitizeInput($questionData['question_text'] ?? ''),
                    'question_type' => sanitizeInput($questionData['question_type'] ?? 'text'),
                    'options' => sanitizeInput($questionData['options'] ?? ''),
                    'is_required' => intval($questionData['is_required'] ?? 0),
                    'question_order' => $index + 1
                ];
            }
        }
        
        // Validation
        if (empty($formData['title'])) {
            $errors[] = 'Survey title is required.';
        } elseif (strlen($formData['title']) < 3) {
            $errors[] = 'Survey title must be at least 3 characters long.';
        } elseif (strlen($formData['title']) > 200) {
            $errors[] = 'Survey title must not exceed 200 characters.';
        }
        
        if (empty($formData['description'])) {
            $errors[] = 'Survey description is required.';
        } elseif (strlen($formData['description']) < 10) {
            $errors[] = 'Survey description must be at least 10 characters long.';
        }
        
        // Validate dates
        if (!empty($formData['start_date']) && !empty($formData['end_date'])) {
            try {
                $startDate = new DateTime($formData['start_date']);
                $endDate = new DateTime($formData['end_date']);
                
                if ($endDate <= $startDate) {
                    $errors[] = 'End date must be after start date.';
                }
            } catch (Exception $e) {
                $errors[] = 'Invalid date format.';
            }
        }
        
        // Validate questions
        if (empty($questionsData)) {
            $errors[] = 'At least one question is required.';
        } else {
            foreach ($questionsData as $index => $question) {
                if (empty($question['question_text'])) {
                    $errors[] = "Question " . ($index + 1) . " text is required.";
                }
                
                // Validate options for certain question types
                if (in_array($question['question_type'], ['radio', 'checkbox', 'select'])) {
                    if (empty($question['options'])) {
                        $errors[] = "Question " . ($index + 1) . " requires options for the selected question type.";
                    }
                }
            }
        }
        
        // If no errors, save the survey
        if (empty($errors)) {
            try {
                $db = Database::getInstance();
                $db->beginTransaction();
                
                $surveyData = [
                    'title' => $formData['title'],
                    'description' => $formData['description'],
                    'start_date' => !empty($formData['start_date']) ? $formData['start_date'] : null,
                    'end_date' => !empty($formData['end_date']) ? $formData['end_date'] : null,
                    'is_active' => $formData['is_active'],
                    'is_anonymous' => $formData['is_anonymous'],
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if ($isEdit) {
                    // Update existing survey
                    $db->update('surveys', $surveyData, 'survey_id = :id', [':id' => $surveyId]);
                    
                    // Delete existing questions
                    $db->delete('survey_questions', 'survey_id = :survey_id', [':survey_id' => $surveyId]);
                    
                    $message = 'Survey updated successfully.';
                    $newSurveyId = $surveyId;
                    
                } else {
                    // Create new survey
                    $surveyData['created_by'] = $currentUser['user_id'];
                    $surveyData['created_at'] = date('Y-m-d H:i:s');
                    
                    $newSurveyId = $db->insert('surveys', $surveyData);
                    $message = 'Survey created successfully.';
                }
                
                // Insert questions
                foreach ($questionsData as $questionData) {
                    $questionData['survey_id'] = $newSurveyId;
                    
                    // Convert options to JSON if needed
                    if (!empty($questionData['options'])) {
                        $options = array_map('trim', explode("\n", $questionData['options']));
                        $questionData['options'] = json_encode($options);
                    } else {
                        $questionData['options'] = null;
                    }
                    
                    $db->insert('survey_questions', $questionData);
                }
                
                $db->commit();
                
                // Create comprehensive notifications for new surveys
                if (!$isEdit && $formData['is_active']) {
                    try {
                        // Notify all participants about new survey
                        $participants = getUsersByRole(['participant', 'mentor', 'volunteer']);
                        $notified = 0;
                        
                        foreach ($participants as $user) {
                            if ($user['user_id'] !== $currentUser['user_id']) {
                                if (createNotification(
                                    $user['user_id'],
                                    'New Survey Available',
                                    "New survey created: {$formData['title']}",
                                    'info',
                                    "modules/surveys/take.php?id={$newSurveyId}"
                                )) {
                                    $notified++;
                                }
                            }
                        }
                        
                        if ($notified > 0) {
                            $message .= " {$notified} participants have been notified.";
                        }
                        
                    } catch (Exception $e) {
                        error_log("Survey notification error: " . $e->getMessage());
                        $message .= ' However, notification system encountered an error.';
                    }
                }
                
                // Enhanced logging
                if (function_exists('logSurveyActivity')) {
                    logSurveyActivity($isEdit ? "updated" : "created", $currentUser['user_id'], $formData['title'], $newSurveyId, count($questionsData) . " questions");
                } else {
                    logActivity("Survey " . ($isEdit ? "updated" : "created") . ": {$formData['title']}", $currentUser['user_id']);
                }
                
                $messageType = 'success';
                
                // Redirect to survey list
                if (!$isEdit) {
                    redirect("list.php", $message, $messageType);
                }
                
            } catch (Exception $e) {
                $db->rollback();
                error_log("Error saving survey: " . $e->getMessage());
                $errors[] = 'Error saving survey data. Please try again.';
            }
        }
    }
}

// Question types
$questionTypes = [
    'text' => 'Short Text',
    'textarea' => 'Long Text',
    'radio' => 'Multiple Choice (Single)',
    'checkbox' => 'Multiple Choice (Multiple)',
    'select' => 'Dropdown',
    'rating' => 'Rating (1-5)'
];

$pageTitle = $isEdit ? 'Edit Survey' : 'Create New Survey';
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
            margin-bottom: 2rem;
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
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
            min-height: 100px;
        }
        
        .form-help {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-top: 0.25rem;
        }
        
        .checkbox-group {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
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
        
        /* Questions Section */
        .questions-container {
            background: var(--gray-100);
            border-radius: 12px;
            padding: 1.5rem;
        }
        
        .question-item {
            background: var(--white);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .question-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .question-number {
            background: var(--primary-color);
            color: var(--white);
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .question-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-remove {
            background: var(--danger-color);
            color: var(--white);
            border: none;
            border-radius: 6px;
            padding: 0.5rem;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .options-input {
            margin-top: 1rem;
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
        
        .btn-secondary {
            background: var(--gray-600);
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
            
            .checkbox-group {
                flex-direction: column;
                gap: 1rem;
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
                    <i class="fas fa-poll"></i> Surveys
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
                <a href="list.php">Surveys</a>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo $isEdit ? 'Edit Survey' : 'New Survey'; ?></span>
            </div>
            <h1 class="page-title"><?php echo $pageTitle; ?></h1>
            <p class="page-subtitle">
                <?php echo $isEdit ? 'Update survey details and questions' : 'Create a new survey to gather feedback and insights'; ?>
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
            <form method="POST" action="" id="surveyForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <!-- Survey Details -->
                <div class="form-section">
                    <h2 class="section-title">
                        <i class="fas fa-info-circle" style="color: var(--primary-color);"></i>
                        Survey Details
                    </h2>
                    
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="title">Survey Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" class="form-control" 
                                   placeholder="Enter survey title" 
                                   value="<?php echo htmlspecialchars($formData['title']); ?>" 
                                   maxlength="200" required>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="description">Description <span class="required">*</span></label>
                            <textarea id="description" name="description" class="form-control" 
                                      placeholder="Describe the purpose and goals of this survey..."
                                      required><?php echo htmlspecialchars($formData['description']); ?></textarea>
                            <div class="form-help">Explain what this survey is about and why participants should complete it.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="start_date">Start Date & Time</label>
                            <input type="datetime-local" id="start_date" name="start_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($formData['start_date']); ?>">
                            <div class="form-help">When should this survey become available?</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date">End Date & Time</label>
                            <input type="datetime-local" id="end_date" name="end_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($formData['end_date']); ?>">
                            <div class="form-help">When should this survey stop accepting responses?</div>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="is_active" name="is_active" value="1" 
                                   <?php echo $formData['is_active'] ? 'checked' : ''; ?>>
                            <label for="is_active">Survey is active</label>
                        </div>
                        
                        <div class="checkbox-item">
                            <input type="checkbox" id="is_anonymous" name="is_anonymous" value="1" 
                                   <?php echo $formData['is_anonymous'] ? 'checked' : ''; ?>>
                            <label for="is_anonymous">Anonymous responses</label>
                        </div>
                    </div>
                </div>
                
                <!-- Survey Questions -->
                <div class="form-section">
                    <h2 class="section-title">
                        <i class="fas fa-question-circle" style="color: var(--primary-color);"></i>
                        Survey Questions
                    </h2>
                    
                    <div class="questions-container" id="questionsContainer">
                        <?php if (!empty($questions)): ?>
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="question-item" data-question="<?php echo $index; ?>">
                                    <div class="question-header">
                                        <div class="question-number"><?php echo $index + 1; ?></div>
                                        <div class="question-actions">
                                            <button type="button" class="btn-remove" onclick="removeQuestion(this)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Question Text <span class="required">*</span></label>
                                        <input type="text" name="questions[<?php echo $index; ?>][question_text]" 
                                               class="form-control" placeholder="Enter your question" 
                                               value="<?php echo htmlspecialchars($question['question_text']); ?>" required>
                                    </div>
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label>Question Type</label>
                                            <div class="select-wrapper">
                                                <select name="questions[<?php echo $index; ?>][question_type]" class="form-control question-type-select">
                                                    <?php foreach ($questionTypes as $typeKey => $typeLabel): ?>
                                                        <option value="<?php echo $typeKey; ?>" 
                                                                <?php echo $question['question_type'] === $typeKey ? 'selected' : ''; ?>>
                                                            <?php echo $typeLabel; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <div class="checkbox-item">
                                                <input type="checkbox" name="questions[<?php echo $index; ?>][is_required]" 
                                                       value="1" <?php echo $question['is_required'] ? 'checked' : ''; ?>>
                                                <label>Required question</label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="options-input" style="<?php echo in_array($question['question_type'], ['radio', 'checkbox', 'select']) ? '' : 'display: none;'; ?>">
                                        <label>Options (one per line)</label>
                                        <textarea name="questions[<?php echo $index; ?>][options]" class="form-control" 
                                                  placeholder="Option 1&#10;Option 2&#10;Option 3"><?php 
                                            if ($question['options']) {
                                                $options = json_decode($question['options'], true);
                                                echo htmlspecialchars(implode("\n", $options));
                                            }
                                        ?></textarea>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div style="margin-top: 1rem;">
                        <button type="button" class="btn btn-secondary" onclick="addQuestion()">
                            <i class="fas fa-plus"></i> Add Question
                        </button>
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="list.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    
                    <?php if ($isEdit): ?>
                        <button type="submit" class="btn btn-success" id="submitBtn">
                            <i class="fas fa-save"></i> Update Survey
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus"></i> Create Survey
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        let questionCount = <?php echo !empty($questions) ? count($questions) : 0; ?>;
        
        function addQuestion() {
            const container = document.getElementById('questionsContainer');
            const questionHTML = `
                <div class="question-item" data-question="${questionCount}">
                    <div class="question-header">
                        <div class="question-number">${questionCount + 1}</div>
                        <div class="question-actions">
                            <button type="button" class="btn-remove" onclick="removeQuestion(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Question Text <span class="required">*</span></label>
                        <input type="text" name="questions[${questionCount}][question_text]" 
                               class="form-control" placeholder="Enter your question" required>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Question Type</label>
                            <div class="select-wrapper">
                                <select name="questions[${questionCount}][question_type]" class="form-control question-type-select">
                                    <option value="text">Short Text</option>
                                    <option value="textarea">Long Text</option>
                                    <option value="radio">Multiple Choice (Single)</option>
                                    <option value="checkbox">Multiple Choice (Multiple)</option>
                                    <option value="select">Dropdown</option>
                                    <option value="rating">Rating (1-5)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="questions[${questionCount}][is_required]" value="1">
                                <label>Required question</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="options-input" style="display: none;">
                        <label>Options (one per line)</label>
                        <textarea name="questions[${questionCount}][options]" class="form-control" 
                                  placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', questionHTML);
            questionCount++;
            updateQuestionNumbers();
            
            // Add event listener for the new question type select
            const newQuestionItem = container.lastElementChild;
            const typeSelect = newQuestionItem.querySelector('.question-type-select');
            typeSelect.addEventListener('change', function() {
                toggleOptionsInput(this);
            });
        }
        
        function removeQuestion(button) {
            button.closest('.question-item').remove();
            updateQuestionNumbers();
        }
        
        function updateQuestionNumbers() {
            const questions = document.querySelectorAll('.question-item');
            questions.forEach((question, index) => {
                question.querySelector('.question-number').textContent = index + 1;
            });
        }
        
        function toggleOptionsInput(select) {
            const questionItem = select.closest('.question-item');
            const optionsInput = questionItem.querySelector('.options-input');
            
            if (['radio', 'checkbox', 'select'].includes(select.value)) {
                optionsInput.style.display = 'block';
            } else {
                optionsInput.style.display = 'none';
            }
        }
        
        // Add event listeners for existing question type selects
        document.querySelectorAll('.question-type-select').forEach(select => {
            select.addEventListener('change', function() {
                toggleOptionsInput(this);
            });
        });
        
        // Form submission
        document.getElementById('surveyForm').addEventListener('submit', function(e) {
            const questions = document.querySelectorAll('.question-item');
            if (questions.length === 0) {
                e.preventDefault();
                alert('Please add at least one question to the survey.');
                return;
            }
            
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
