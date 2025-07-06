 <?php
/**
 * Survey Taking Interface - Girls Leadership Program
 * Complete module for participants to take surveys
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

// Get survey ID
$surveyId = intval($_GET['id'] ?? 0);
if (!$surveyId) {
    redirect('list.php', 'Survey not found.', 'error');
}

$errors = [];
$message = '';
$messageType = 'info';

// Load survey data
$survey = null;
$questions = [];
$existingResponses = [];
$hasSubmitted = false;

try {
    $db = Database::getInstance();
    
    // Get survey details
    $survey = $db->fetchRow(
        "SELECT s.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
         FROM surveys s
         LEFT JOIN users u ON u.user_id = s.created_by 
         WHERE s.survey_id = :id",
        [':id' => $surveyId]
    );
    
    if (!$survey) {
        redirect('list.php', 'Survey not found.', 'error');
    }
    
    // Check if survey is active and within date range
    if (!$survey['is_active']) {
        redirect('list.php', 'This survey is not currently active.', 'error');
    }
    
    $now = new DateTime();
    if ($survey['start_date'] && new DateTime($survey['start_date']) > $now) {
        redirect('list.php', 'This survey is not yet available.', 'error');
    }
    
    if ($survey['end_date'] && new DateTime($survey['end_date']) < $now) {
        redirect('list.php', 'This survey has ended.', 'error');
    }
    
    // Get survey questions
    $questions = $db->fetchAll(
        "SELECT * FROM survey_questions WHERE survey_id = :survey_id ORDER BY question_order",
        [':survey_id' => $surveyId]
    );
    
    if (empty($questions)) {
        redirect('list.php', 'This survey has no questions configured.', 'error');
    }
    
    // Check if user has already submitted (for non-anonymous surveys)
    if (!$survey['is_anonymous']) {
        $existingResponses = $db->fetchAll(
            "SELECT sr.*, sq.question_text 
             FROM survey_responses sr
             JOIN survey_questions sq ON sr.question_id = sq.question_id
             WHERE sr.survey_id = :survey_id AND sr.user_id = :user_id",
            [':survey_id' => $surveyId, ':user_id' => $currentUser['user_id']]
        );
        
        $hasSubmitted = !empty($existingResponses);
    }
    
} catch (Exception $e) {
    error_log("Survey load error: " . $e->getMessage());
    redirect('list.php', 'Error loading survey.', 'error');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasSubmitted) {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $responses = $_POST['responses'] ?? [];
        
        // Validate responses
        foreach ($questions as $question) {
            $questionId = $question['question_id'];
            $response = $responses[$questionId] ?? '';
            
            // Check required questions
            if ($question['is_required'] && empty($response)) {
                $errors[] = "Question '" . htmlspecialchars($question['question_text']) . "' is required.";
            }
            
            // Validate rating responses
            if ($question['question_type'] === 'rating' && !empty($response)) {
                $rating = intval($response);
                if ($rating < 1 || $rating > 5) {
                    $errors[] = "Please provide a valid rating (1-5) for '" . htmlspecialchars($question['question_text']) . "'.";
                }
            }
        }
        
        // If no errors, save responses
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                foreach ($questions as $question) {
                    $questionId = $question['question_id'];
                    $response = $responses[$questionId] ?? '';
                    
                    if (!empty($response)) {
                        $responseData = [
                            'survey_id' => $surveyId,
                            'question_id' => $questionId,
                            'user_id' => $survey['is_anonymous'] ? null : $currentUser['user_id'],
                            'response_text' => null,
                            'response_value' => null,
                            'submitted_at' => date('Y-m-d H:i:s')
                        ];
                        
                        if ($question['question_type'] === 'rating') {
                            $responseData['response_value'] = intval($response);
                        } elseif ($question['question_type'] === 'checkbox') {
                            // Handle multiple selections
                            if (is_array($response)) {
                                $responseData['response_text'] = implode(', ', $response);
                            } else {
                                $responseData['response_text'] = $response;
                            }
                        } else {
                            $responseData['response_text'] = $response;
                        }
                        
                        $db->insert('survey_responses', $responseData);
                    }
                }
                
                $db->commit();
                
                // Create completion notification for survey creator
                try {
                    if (!$survey['is_anonymous']) {
                        createNotification(
                            $survey['created_by'],
                            'Survey Response Received',
                            "{$currentUser['first_name']} {$currentUser['last_name']} completed the survey: {$survey['title']}",
                            'success',
                            "modules/surveys/results.php?id={$surveyId}"
                        );
                    } else {
                        // For anonymous surveys, just notify about new response without user details
                        createNotification(
                            $survey['created_by'],
                            'New Survey Response',
                            "A new anonymous response was received for: {$survey['title']}",
                            'success',
                            "modules/surveys/results.php?id={$surveyId}"
                        );
                    }
                } catch (Exception $e) {
                    error_log("Survey completion notification error: " . $e->getMessage());
                }
                
                // Enhanced logging
                if (function_exists('logSurveyActivity')) {
                    logSurveyActivity("completed", $currentUser['user_id'], $survey['title'], $surveyId, $survey['is_anonymous'] ? "Anonymous response" : "Identified response");
                } else {
                    logActivity("Completed survey: {$survey['title']}", $currentUser['user_id']);
                }
                
                $message = 'Thank you! Your survey response has been submitted successfully.';
                $messageType = 'success';
                $hasSubmitted = true;
                
            } catch (Exception $e) {
                $db->rollback();
                error_log("Error saving survey responses: " . $e->getMessage());
                $errors[] = 'Error saving your responses. Please try again.';
            }
        }
    }
}

$pageTitle = 'Take Survey - ' . ($survey ? $survey['title'] : 'Unknown');
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
            max-width: 800px;
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
        
        .survey-header {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .survey-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .survey-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
        }
        
        .survey-meta {
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
        
        .survey-description {
            color: var(--gray-600);
            font-size: 1.1rem;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        
        .survey-badges {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-anonymous {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .badge-identified {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary-color);
        }
        
        .survey-form {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }
        
        .question-item {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .question-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .question-header {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
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
            flex-shrink: 0;
        }
        
        .question-content {
            flex: 1;
        }
        
        .question-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .required-indicator {
            color: var(--danger-color);
            font-size: 0.9rem;
        }
        
        .question-input {
            margin-top: 1rem;
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
            min-height: 100px;
        }
        
        .radio-group, .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .radio-item, .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .radio-item:hover, .checkbox-item:hover {
            border-color: var(--primary-color);
            background: rgba(108, 92, 231, 0.05);
        }
        
        .radio-item.selected, .checkbox-item.selected {
            border-color: var(--primary-color);
            background: rgba(108, 92, 231, 0.1);
        }
        
        .radio-item input, .checkbox-item input {
            margin: 0;
        }
        
        .radio-item label, .checkbox-item label {
            margin: 0;
            cursor: pointer;
            flex: 1;
        }
        
        .rating-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin: 1rem 0;
        }
        
        .rating-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .rating-item:hover {
            background: var(--gray-100);
        }
        
        .rating-item.selected {
            background: var(--primary-color);
            color: var(--white);
        }
        
        .rating-number {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .rating-label {
            font-size: 0.8rem;
            text-align: center;
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
        
        .submission-complete {
            background: var(--white);
            border-radius: 16px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: var(--shadow);
        }
        
        .completion-icon {
            font-size: 4rem;
            color: var(--success-color);
            margin-bottom: 1rem;
        }
        
        .completion-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
        }
        
        .completion-message {
            color: var(--gray-600);
            margin-bottom: 2rem;
        }
        
        .existing-responses {
            background: var(--gray-100);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .responses-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark-color);
        }
        
        .response-item {
            background: var(--white);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .response-question {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .response-answer {
            color: var(--gray-600);
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
            
            .survey-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .rating-group {
                flex-wrap: wrap;
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
                    <i class="fas fa-poll"></i> Surveys
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
            <a href="list.php">Surveys</a>
            <i class="fas fa-chevron-right"></i>
            <span>Take Survey</span>
        </div>
        
        <?php if ($survey): ?>
        <div class="survey-header">
            <h1 class="survey-title"><?php echo htmlspecialchars($survey['title']); ?></h1>
            
            <div class="survey-meta">
                <div class="meta-item">
                    <i class="fas fa-user meta-icon"></i>
                    Created by <?php echo htmlspecialchars($survey['creator_first_name'] . ' ' . $survey['creator_last_name']); ?>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar meta-icon"></i>
                    <?php echo date('M j, Y', strtotime($survey['created_at'])); ?>
                </div>
                <div class="meta-item">
                    <i class="fas fa-clock meta-icon"></i>
                    <?php echo count($questions); ?> questions
                </div>
                <?php if ($survey['end_date']): ?>
                    <div class="meta-item">
                        <i class="fas fa-hourglass-end meta-icon"></i>
                        Ends <?php echo date('M j, Y', strtotime($survey['end_date'])); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="survey-description">
                <?php echo nl2br(htmlspecialchars($survey['description'])); ?>
            </div>
            
            <div class="survey-badges">
                <?php if ($survey['is_anonymous']): ?>
                    <span class="badge badge-anonymous">
                        <i class="fas fa-user-secret"></i> Anonymous
                    </span>
                <?php else: ?>
                    <span class="badge badge-identified">
                        <i class="fas fa-user"></i> Your responses will be identified
                    </span>
                <?php endif; ?>
            </div>
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
        
        <?php if ($hasSubmitted): ?>
            <div class="submission-complete">
                <div class="completion-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2 class="completion-title">Thank You!</h2>
                <p class="completion-message">
                    Your response has been recorded. Thank you for taking the time to complete this survey.
                </p>
                <a href="list.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Surveys
                </a>
            </div>
            
            <?php if (!$survey['is_anonymous'] && !empty($existingResponses)): ?>
                <div class="existing-responses">
                    <h3 class="responses-title">Your Responses:</h3>
                    <?php 
                    $responsesByQuestion = [];
                    foreach ($existingResponses as $response) {
                        $responsesByQuestion[$response['question_id']] = $response;
                    }
                    ?>
                    
                    <?php foreach ($questions as $question): ?>
                        <?php $response = $responsesByQuestion[$question['question_id']] ?? null; ?>
                        <?php if ($response): ?>
                            <div class="response-item">
                                <div class="response-question"><?php echo htmlspecialchars($question['question_text']); ?></div>
                                <div class="response-answer">
                                    <?php 
                                    if ($response['response_value'] !== null) {
                                        echo "Rating: " . $response['response_value'] . "/5";
                                    } else {
                                        echo htmlspecialchars($response['response_text']);
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="survey-form">
                <form method="POST" action="" id="surveyForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="question-item">
                            <div class="question-header">
                                <div class="question-number"><?php echo $index + 1; ?></div>
                                <div class="question-content">
                                    <div class="question-text">
                                        <?php echo htmlspecialchars($question['question_text']); ?>
                                        <?php if ($question['is_required']): ?>
                                            <span class="required-indicator">*</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="question-input">
                                <?php
                                $questionName = "responses[{$question['question_id']}]";
                                $options = $question['options'] ? json_decode($question['options'], true) : [];
                                
                                switch ($question['question_type']):
                                    case 'text':
                                ?>
                                        <input type="text" name="<?php echo $questionName; ?>" class="form-control" 
                                               placeholder="Enter your answer..." 
                                               <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                <?php
                                        break;
                                    case 'textarea':
                                ?>
                                        <textarea name="<?php echo $questionName; ?>" class="form-control" 
                                                  placeholder="Enter your detailed response..." 
                                                  <?php echo $question['is_required'] ? 'required' : ''; ?>></textarea>
                                <?php
                                        break;
                                    case 'radio':
                                ?>
                                        <div class="radio-group">
                                            <?php foreach ($options as $optionIndex => $option): ?>
                                                <div class="radio-item" onclick="selectRadio(this)">
                                                    <input type="radio" name="<?php echo $questionName; ?>" 
                                                           value="<?php echo htmlspecialchars($option); ?>" 
                                                           id="<?php echo $question['question_id']; ?>_<?php echo $optionIndex; ?>"
                                                           <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                                    <label for="<?php echo $question['question_id']; ?>_<?php echo $optionIndex; ?>">
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                <?php
                                        break;
                                    case 'checkbox':
                                ?>
                                        <div class="checkbox-group">
                                            <?php foreach ($options as $optionIndex => $option): ?>
                                                <div class="checkbox-item" onclick="toggleCheckbox(this)">
                                                    <input type="checkbox" name="<?php echo $questionName; ?>[]" 
                                                           value="<?php echo htmlspecialchars($option); ?>" 
                                                           id="<?php echo $question['question_id']; ?>_<?php echo $optionIndex; ?>">
                                                    <label for="<?php echo $question['question_id']; ?>_<?php echo $optionIndex; ?>">
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                <?php
                                        break;
                                    case 'select':
                                ?>
                                        <div class="select-wrapper">
                                            <select name="<?php echo $questionName; ?>" class="form-control" 
                                                    <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                                <option value="">Select an option...</option>
                                                <?php foreach ($options as $option): ?>
                                                    <option value="<?php echo htmlspecialchars($option); ?>">
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                <?php
                                        break;
                                    case 'rating':
                                ?>
                                        <div class="rating-group">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <div class="rating-item" onclick="selectRating(this, <?php echo $i; ?>)">
                                                    <input type="radio" name="<?php echo $questionName; ?>" 
                                                           value="<?php echo $i; ?>" style="display: none;"
                                                           <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                                    <div class="rating-number"><?php echo $i; ?></div>
                                                    <div class="rating-label">
                                                        <?php 
                                                        $labels = ['Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];
                                                        echo $labels[$i-1];
                                                        ?>
                                                    </div>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                <?php
                                        break;
                                endswitch;
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="form-actions">
                        <a href="list.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Surveys
                        </a>
                        
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Submit Survey
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <script>
        function selectRadio(element) {
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;
            
            // Remove selected class from siblings
            const siblings = element.parentElement.querySelectorAll('.radio-item');
            siblings.forEach(sibling => sibling.classList.remove('selected'));
            
            // Add selected class to current element
            element.classList.add('selected');
        }
        
        function toggleCheckbox(element) {
            const checkbox = element.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                element.classList.add('selected');
            } else {
                element.classList.remove('selected');
            }
        }
        
        function selectRating(element, value) {
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;
            
            // Remove selected class from siblings
            const siblings = element.parentElement.querySelectorAll('.rating-item');
            siblings.forEach(sibling => sibling.classList.remove('selected'));
            
            // Add selected class to current and previous rating items
            const ratingItems = element.parentElement.querySelectorAll('.rating-item');
            for (let i = 0; i < value; i++) {
                ratingItems[i].classList.add('selected');
            }
        }
        
        // Form submission
        document.getElementById('surveyForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
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
