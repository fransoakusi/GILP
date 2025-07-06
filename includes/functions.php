<?php
/**
 * Complete Functions File - Girls Leadership Program
 * Contains ALL notification functions and utilities needed by the modules
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    exit('Direct access not allowed');
}

// =============================================================================
// CORE NOTIFICATION FUNCTIONS
// =============================================================================

/**
 * Create a notification for a specific user
 * 
 * @param int $userId - Target user ID
 * @param string $title - Notification title
 * @param string $message - Notification message
 * @param string $type - Notification type (info, success, warning, error)
 * @param string|null $actionUrl - Optional URL for action link
 * @return int|false - Notification ID on success, false on failure
 */
function createNotification($userId, $title, $message, $type = 'info', $actionUrl = null) {
    try {
        $db = Database::getInstance();
        
        // Validate inputs
        if (empty($userId) || empty($title) || empty($message)) {
            error_log("Notification creation failed: Missing required parameters");
            return false;
        }
        
        // Validate notification type
        $validTypes = ['info', 'success', 'warning', 'error'];
        if (!in_array($type, $validTypes)) {
            $type = 'info';
        }
        
        $notificationData = [
            'user_id' => intval($userId),
            'title' => sanitizeInput($title),
            'message' => sanitizeInput($message),
            'type' => $type,
            'action_url' => $actionUrl ? sanitizeInput($actionUrl) : null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $db->insert('notifications', $notificationData);
        
    } catch (Exception $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notifications for multiple users
 * 
 * @param array $userIds - Array of user IDs
 * @param string $title - Notification title
 * @param string $message - Notification message
 * @param string $type - Notification type
 * @param string|null $actionUrl - Optional action URL
 * @return int - Number of notifications created
 */
function createBulkNotifications($userIds, $title, $message, $type = 'info', $actionUrl = null) {
    $created = 0;
    
    if (!is_array($userIds) || empty($userIds)) {
        return 0;
    }
    
    foreach ($userIds as $userId) {
        if (createNotification($userId, $title, $message, $type, $actionUrl)) {
            $created++;
        }
    }
    
    return $created;
}

/**
 * Get notification counts for a user
 * 
 * @param int $userId - User ID
 * @return array - Array of notification counts
 */
function getNotificationCounts($userId) {
    try {
        $db = Database::getInstance();
        
        return [
            'unread_notifications' => $db->count('notifications', 'user_id = :user_id AND is_read = 0', [':user_id' => $userId]),
            'unread_messages' => $db->count('messages', 'receiver_id = :user_id AND is_read = 0', [':user_id' => $userId]),
            'overdue_assignments' => $db->count('assignments', 'assigned_to = :user_id AND due_date < NOW() AND status NOT IN ("completed", "submitted")', [':user_id' => $userId]),
            'upcoming_sessions' => 0 // Placeholder for training sessions
        ];
    } catch (Exception $e) {
        error_log("Notification counts error: " . $e->getMessage());
        return ['unread_notifications' => 0, 'unread_messages' => 0, 'overdue_assignments' => 0, 'upcoming_sessions' => 0];
    }
}

/**
 * Get users by role for targeted notifications
 * 
 * @param string|array $roles - Role(s) to filter by
 * @return array - Array of user records
 */
function getUsersByRole($roles) {
    try {
        $db = Database::getInstance();
        
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        $placeholders = str_repeat('?,', count($roles) - 1) . '?';
        $query = "SELECT user_id, first_name, last_name, email, role FROM users WHERE role IN ($placeholders) AND is_active = 1";
        
        return $db->fetchAll($query, $roles);
    } catch (Exception $e) {
        error_log("Get users by role error: " . $e->getMessage());
        return [];
    }
}

// =============================================================================
// ASSIGNMENT NOTIFICATION FUNCTIONS
// =============================================================================

/**
 * Notify when assignment is created
 */
function notifyAssignmentCreated($assignmentId, $assignmentData, $createdBy) {
    try {
        $db = Database::getInstance();
        
        // Notify the assigned user
        $result = createNotification(
            $assignmentData['assigned_to'],
            'New Assignment',
            "You have been assigned: {$assignmentData['title']}",
            'info',
            "modules/assignments/submit.php?id={$assignmentId}"
        );
        
        // If part of a project, notify project participants
        if (!empty($assignmentData['project_id'])) {
            $participants = $db->fetchAll(
                "SELECT pp.user_id FROM project_participants pp WHERE pp.project_id = :project_id AND pp.user_id != :assigned_to AND pp.user_id != :created_by",
                [
                    ':project_id' => $assignmentData['project_id'], 
                    ':assigned_to' => $assignmentData['assigned_to'],
                    ':created_by' => $createdBy['user_id']
                ]
            );
            
            foreach ($participants as $participant) {
                createNotification(
                    $participant['user_id'],
                    'Project Assignment Update',
                    "New assignment created in your project: {$assignmentData['title']}",
                    'info',
                    "modules/assignments/submit.php?id={$assignmentId}"
                );
            }
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Assignment creation notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify when assignment is submitted
 */
function notifyAssignmentSubmitted($assignmentId, $assignment, $submittedBy) {
    try {
        return createNotification(
            $assignment['assigned_by'],
            'Assignment Submitted',
            "{$submittedBy['first_name']} {$submittedBy['last_name']} submitted: {$assignment['title']}",
            'success',
            "modules/assignments/review.php?id={$assignmentId}"
        );
    } catch (Exception $e) {
        error_log("Assignment submission notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify when assignment is graded/reviewed
 */
function notifyAssignmentGraded($assignmentId, $assignment, $grade = null, $feedback = null) {
    try {
        $gradeText = $grade ? "Grade: {$grade}/100" : "";
        $message = "Your assignment '{$assignment['title']}' has been reviewed.";
        if ($gradeText) $message .= " {$gradeText}";
        
        $type = 'info';
        if ($grade !== null) {
            $type = $grade >= 70 ? 'success' : 'warning';
        }
        
        return createNotification(
            $assignment['assigned_to'],
            'Assignment Reviewed',
            $message,
            $type,
            "modules/assignments/view.php?id={$assignmentId}"
        );
    } catch (Exception $e) {
        error_log("Assignment grading notification error: " . $e->getMessage());
        return false;
    }
}

// =============================================================================
// PROJECT NOTIFICATION FUNCTIONS
// =============================================================================

/**
 * Notify when project is created
 */
function notifyProjectCreated($projectId, $projectData, $creator) {
    try {
        // Notify admins and mentors about new project
        $adminsAndMentors = getUsersByRole(['admin', 'mentor']);
        $notified = 0;
        
        foreach ($adminsAndMentors as $user) {
            if ($user['user_id'] !== $creator['user_id']) {
                if (createNotification(
                    $user['user_id'],
                    'New Project Created',
                    "New project created: {$projectData['title']}",
                    'info',
                    "modules/projects/view.php?id={$projectId}"
                )) {
                    $notified++;
                }
            }
        }
        
        return $notified;
    } catch (Exception $e) {
        error_log("Project creation notification error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Notify when someone joins a project
 */
function notifyProjectJoined($projectId, $project, $newParticipant) {
    try {
        $db = Database::getInstance();
        $notified = 0;
        
        // Notify project creator
        if (createNotification(
            $project['created_by'],
            'New Project Member',
            "{$newParticipant['first_name']} {$newParticipant['last_name']} joined your project: {$project['title']}",
            'success',
            "modules/projects/view.php?id={$projectId}"
        )) {
            $notified++;
        }
        
        // Notify other participants
        $participants = $db->fetchAll(
            "SELECT pp.user_id, u.first_name, u.last_name 
             FROM project_participants pp 
             JOIN users u ON u.user_id = pp.user_id 
             WHERE pp.project_id = :project_id AND pp.user_id != :new_user AND pp.user_id != :creator",
            [
                ':project_id' => $projectId, 
                ':new_user' => $newParticipant['user_id'],
                ':creator' => $project['created_by']
            ]
        );
        
        foreach ($participants as $participant) {
            if (createNotification(
                $participant['user_id'],
                'Project Update',
                "{$newParticipant['first_name']} {$newParticipant['last_name']} joined the project: {$project['title']}",
                'info',
                "modules/projects/view.php?id={$projectId}"
            )) {
                $notified++;
            }
        }
        
        return $notified;
        
    } catch (Exception $e) {
        error_log("Project join notification error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Notify when project status changes
 */
function notifyProjectStatusChanged($projectId, $project, $newStatus, $updatedBy) {
    try {
        $db = Database::getInstance();
        
        $statusMessages = [
            'active' => 'Project has been activated and is now in progress',
            'completed' => 'Project has been completed successfully!',
            'on_hold' => 'Project has been put on hold temporarily', 
            'cancelled' => 'Project has been cancelled'
        ];
        
        $message = $statusMessages[$newStatus] ?? "Project status updated to: {$newStatus}";
        $type = $newStatus === 'completed' ? 'success' : ($newStatus === 'cancelled' ? 'error' : 'warning');
        
        // Get all project participants
        $participants = $db->fetchAll(
            "SELECT pp.user_id FROM project_participants pp WHERE pp.project_id = :project_id",
            [':project_id' => $projectId]
        );
        
        $notified = 0;
        foreach ($participants as $participant) {
            if ($participant['user_id'] !== $updatedBy['user_id']) {
                if (createNotification(
                    $participant['user_id'],
                    'Project Status Update',
                    "'{$project['title']}': {$message}",
                    $type,
                    "modules/projects/view.php?id={$projectId}"
                )) {
                    $notified++;
                }
            }
        }
        
        return $notified;
        
    } catch (Exception $e) {
        error_log("Project status notification error: " . $e->getMessage());
        return 0;
    }
}

// =============================================================================
// TRAINING NOTIFICATION FUNCTIONS
// =============================================================================

/**
 * Notify when training session is created
 */
function notifyTrainingSessionCreated($sessionId, $sessionData, $creator) {
    try {
        // Notify all participants and mentors
        $participants = getUsersByRole(['participant', 'mentor']);
        $notified = 0;
        
        foreach ($participants as $user) {
            if ($user['user_id'] !== $creator['user_id']) {
                if (createNotification(
                    $user['user_id'],
                    'New Training Session',
                    "New training session available: {$sessionData['title']}",
                    'info',
                    "modules/training/sessions.php?view=details&id={$sessionId}"
                )) {
                    $notified++;
                }
            }
        }
        
        return $notified;
    } catch (Exception $e) {
        error_log("Training session creation notification error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Notify training registration confirmation
 */
function notifyTrainingRegistration($sessionId, $session, $participant) {
    try {
        return createNotification(
            $participant['user_id'],
            'Training Registration Confirmed',
            "You're registered for: {$session['title']} on " . date('M j, Y \a\t g:i A', strtotime($session['session_date'])),
            'success',
            "modules/training/sessions.php?view=details&id={$sessionId}"
        );
    } catch (Exception $e) {
        error_log("Training registration notification error: " . $e->getMessage());
        return false;
    }
}

// =============================================================================
// USER MANAGEMENT NOTIFICATION FUNCTIONS
// =============================================================================

/**
 * Send welcome notification for new users
 */
function sendWelcomeNotification($userId, $userData) {
    try {
        $welcomeMessages = [
            'participant' => "Welcome to the Girls Leadership Program! We're excited to have you join us on this journey of growth and leadership development.",
            'mentor' => "Welcome to the Girls Leadership Program! Thank you for volunteering to mentor our participants. Your guidance will make a real difference.",
            'admin' => "Welcome to the Girls Leadership Program admin panel. You now have administrative access to manage the program.",
            'volunteer' => "Welcome to the Girls Leadership Program! Thank you for volunteering to support our mission."
        ];
        
        $message = $welcomeMessages[$userData['role']] ?? "Welcome to the Girls Leadership Program!";
        
        return createNotification(
            $userId,
            'Welcome to Girls Leadership Program!',
            $message,
            'success',
            'dashboard.php'
        );
    } catch (Exception $e) {
        error_log("Welcome notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify role change
 */
function notifyRoleChanged($userId, $oldRole, $newRole, $changedBy) {
    try {
        $roleNames = [
            'participant' => 'Participant',
            'mentor' => 'Mentor', 
            'admin' => 'Administrator',
            'volunteer' => 'Volunteer'
        ];
        
        $message = "Your role has been updated from {$roleNames[$oldRole]} to {$roleNames[$newRole]}.";
        
        return createNotification(
            $userId,
            'Role Updated',
            $message,
            'info',
            'modules/users/profile.php'
        );
    } catch (Exception $e) {
        error_log("Role change notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify mentor assignment
 */
function notifyMentorAssignment($mentorId, $menteeId, $mentorData, $menteeData) {
    try {
        $notified = 0;
        
        // Notify mentor
        if (createNotification(
            $mentorId,
            'New Mentee Assigned',
            "You have been assigned as a mentor to {$menteeData['first_name']} {$menteeData['last_name']}",
            'info',
            "modules/users/mentorship.php"
        )) {
            $notified++;
        }
        
        // Notify mentee
        if (createNotification(
            $menteeId,
            'Mentor Assigned',
            "You have been assigned a mentor: {$mentorData['first_name']} {$mentorData['last_name']}",
            'success',
            "modules/users/mentorship.php"
        )) {
            $notified++;
        }
        
        return $notified;
    } catch (Exception $e) {
        error_log("Mentor assignment notification error: " . $e->getMessage());
        return 0;
    }
}

// =============================================================================
// ENHANCED LOGGING FUNCTIONS (Using existing logActivity from config.php)
// =============================================================================

/**
 * Log assignment-related activities with context
 */
function logAssignmentActivity($action, $userId, $assignmentTitle, $assignmentId = null, $details = null) {
    $activity = "Assignment {$action}: {$assignmentTitle}";
    $enhancedDetails = $details;
    
    if ($assignmentId) {
        $enhancedDetails = "Assignment ID: {$assignmentId}" . ($details ? " | {$details}" : "");
    }
    
    // Use the existing logActivity function from config.php
    if (function_exists('logActivity')) {
        return logActivity($activity . ($enhancedDetails ? " - {$enhancedDetails}" : ""), $userId);
    }
    
    return true;
}

/**
 * Log project-related activities with context
 */
function logProjectActivity($action, $userId, $projectTitle, $projectId = null, $details = null) {
    $activity = "Project {$action}: {$projectTitle}";
    $enhancedDetails = $details;
    
    if ($projectId) {
        $enhancedDetails = "Project ID: {$projectId}" . ($details ? " | {$details}" : "");
    }
    
    // Use the existing logActivity function from config.php
    if (function_exists('logActivity')) {
        return logActivity($activity . ($enhancedDetails ? " - {$enhancedDetails}" : ""), $userId);
    }
    
    return true;
}

/**
 * Log notification-related activities
 */
function logNotificationActivity($action, $userId, $notificationType, $details = null) {
    $activity = "Notification {$action}: {$notificationType}";
    
    // Use the existing logActivity function from config.php
    if (function_exists('logActivity')) {
        return logActivity($activity . ($details ? " - {$details}" : ""), $userId);
    }
    
    return true;
}

/**
 * Log user authentication activities
 */
function logAuthActivity($action, $userId, $details = null) {
    $level = in_array($action, ['login_success', 'logout']) ? 'SUCCESS' : 'WARNING';
    
    // Use the existing logActivity function from config.php
    if (function_exists('logActivity')) {
        return logActivity("Auth {$action}" . ($details ? " - {$details}" : ""), $userId, $level);
    }
    
    return true;
}

// =============================================================================
// UTILITY HELPER FUNCTIONS
// =============================================================================

/**
 * Enhanced input sanitization (if not already defined)
 */
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map('sanitizeInput', $input);
        }
        
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        return $input;
    }
}

/**
 * Generate a secure random token
 */
function generateSecureToken($length = 32) {
    try {
        return bin2hex(random_bytes($length));
    } catch (Exception $e) {
        // Fallback for older PHP versions
        return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/62))), 0, $length);
    }
}

/**
 * Format file sizes
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Validate email address
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Time ago helper function
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    
    return floor($time/31536000) . ' years ago';
}

/**
 * Check if user has permission for specific action (compatibility function)
 */
if (!function_exists('hasUserPermission')) {
    function hasUserPermission($permission, $user = null) {
        // Use the existing hasPermission function if available
        if (function_exists('hasPermission')) {
            return hasPermission($permission, $user ? $user['role'] : null);
        }
        
        // Fallback basic permission check
        if (!$user) {
            $user = getCurrentUser();
        }
        
        if (!$user) {
            return false;
        }
        
        $rolePermissions = [
            'admin' => [
                'user_management', 'project_management', 'assignment_management', 
                'training_management', 'mentee_management', 'system_settings'
            ],
            'mentor' => [
                'project_management', 'assignment_management', 'training_management', 
                'mentee_management'
            ],
            'participant' => [
                'assignment_submission', 'project_participation'
            ],
            'volunteer' => [
                'project_participation'
            ]
        ];
        
        $userRole = $user['role'] ?? 'participant';
        $permissions = $rolePermissions[$userRole] ?? [];
        
        return in_array($permission, $permissions);
    }
}

// =============================================================================
// DEBUGGING AND TESTING FUNCTIONS
// =============================================================================

/**
 * Test notification system
 */
function testNotificationSystem($userId = null) {
    if (!$userId) {
        $userId = $_SESSION['user_id'] ?? 1;
    }
    
    $testResult = createNotification(
        $userId,
        'Test Notification',
        'This is a test notification to verify the system is working.',
        'info'
    );
    
    return $testResult ? "Notification system is working! Notification ID: {$testResult}" : "Notification system failed!";
}

// =============================================================================
// INITIALIZATION
// =============================================================================

// Ensure logs directory exists for enhanced logging
$logDir = (defined('APP_ROOT') ? APP_ROOT : dirname(dirname(__FILE__))) . '/logs';
if (!file_exists($logDir)) {
    @mkdir($logDir, 0755, true);
}

/**
 * Log survey-related activities with context
 * Add this function to your functions.php file in the ENHANCED LOGGING FUNCTIONS section
 */
function logSurveyActivity($action, $userId, $surveyTitle, $surveyId = null, $details = null) {
    $activity = "Survey {$action}: {$surveyTitle}";
    $enhancedDetails = $details;
    
    if ($surveyId) {
        $enhancedDetails = "Survey ID: {$surveyId}" . ($details ? " | {$details}" : "");
    }
    
    // Use the existing logActivity function from config.php
    if (function_exists('logActivity')) {
        return logActivity($activity . ($enhancedDetails ? " - {$enhancedDetails}" : ""), $userId);
    }
    
    return true;
}

?>