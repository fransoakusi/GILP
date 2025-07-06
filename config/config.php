<?php
/**
 * Main Configuration for Girls Leadership Program
 * Application settings and constants
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(dirname(__FILE__)));
}

// ====================================================
// PATH CONFIGURATION (DEFINED FIRST)
// ====================================================

define('BASE_PATH', APP_ROOT . '/');
define('CONFIG_PATH', BASE_PATH . 'config/');
define('MODULES_PATH', BASE_PATH . 'modules/');
define('INCLUDES_PATH', BASE_PATH . 'includes/');
define('ASSETS_PATH', BASE_PATH . 'assets/');
define('UPLOADS_PATH', BASE_PATH . 'assets/uploads/');
define('TESTS_PATH', BASE_PATH . 'tests/');

// ====================================================
// SESSION MANAGEMENT (AFTER PATHS ARE DEFINED)
// ====================================================

// Load session manager first
require_once CONFIG_PATH . 'session.php';

// ====================================================
// APPLICATION SETTINGS
// ====================================================

// Application Information
define('APP_NAME', 'Girls Leadership Program Manager');
define('APP_VERSION', '1.0.0');
define('APP_DESCRIPTION', 'Project Management System for Leadership Development');
define('APP_URL', 'http://localhost/girls-leadership-pm');

// Debug Mode (SET TO FALSE IN PRODUCTION)
define('DEBUG_MODE', true);
define('LOG_ERRORS', true);

// URL Paths
define('BASE_URL', APP_URL . '/');
define('ASSETS_URL', BASE_URL . 'assets/');
define('UPLOADS_URL', BASE_URL . 'assets/uploads/');

// ====================================================
// SECURITY SETTINGS
// ====================================================

// Session timeout (already configured in session manager)
define('SESSION_TIMEOUT', 3600); // 1 hour

// Password Requirements
define('MIN_PASSWORD_LENGTH', 8);
define('REQUIRE_SPECIAL_CHARS', true);
define('REQUIRE_NUMBERS', true);
define('REQUIRE_UPPERCASE', true);

// File Upload Settings
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_FILE_TYPES', [
    'pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'gif'
]);

// Security Keys (CHANGE THESE IN PRODUCTION)
define('ENCRYPTION_KEY', 'your-secret-encryption-key-change-this');
define('CSRF_TOKEN_NAME', 'csrf_token');

// ====================================================
// USER ROLES AND PERMISSIONS
// ====================================================

define('USER_ROLES', [
    'admin' => [
        'name' => 'Administrator',
        'permissions' => [
            'user_management', 'project_management', 'assignment_management',
            'training_management', 'survey_management', 'report_access',
            'system_settings', 'file_management'
        ]
    ],
    'mentor' => [
        'name' => 'Mentor',
        'permissions' => [
            'mentee_management', 'assignment_review', 'training_access',
            'communication', 'progress_tracking', 'file_upload'
        ]
    ],
    'participant' => [
        'name' => 'Participant',
        'permissions' => [
            'assignment_submission', 'training_access', 'communication',
            'goal_setting', 'survey_participation', 'file_upload'
        ]
    ],
    'volunteer' => [
        'name' => 'Volunteer',
        'permissions' => [
            'training_access', 'communication', 'survey_participation'
        ]
    ]
]);

// ====================================================
// APPLICATION FEATURES
// ====================================================

define('FEATURES', [
    'user_registration' => true,
    'email_notifications' => true,
    'file_uploads' => true,
    'mobile_responsive' => true,
    'real_time_notifications' => true,
    'progress_tracking' => true,
    'survey_system' => true,
    'messaging_system' => true,
    'calendar_integration' => true,
    'report_generation' => true
]);

// ====================================================
// EMAIL CONFIGURATION
// ====================================================

define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('SMTP_ENCRYPTION', 'tls');

define('FROM_EMAIL', 'noreply@girlsleadership.org');
define('FROM_NAME', 'Girls Leadership Program');
define('ADMIN_EMAIL', 'admin@girlsleadership.org');

// ====================================================
// PAGINATION AND DISPLAY
// ====================================================

define('ITEMS_PER_PAGE', 20);
define('MAX_SEARCH_RESULTS', 100);
define('RECENT_ITEMS_COUNT', 10);

// ====================================================
// DATE AND TIME
// ====================================================

date_default_timezone_set('UTC');
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'M j, Y');
define('DISPLAY_DATETIME_FORMAT', 'M j, Y g:i A');

// ====================================================
// STATUS DEFINITIONS
// ====================================================

define('PROJECT_STATUSES', [
    'planning' => ['label' => 'Planning', 'color' => '#6C5CE7'],
    'active' => ['label' => 'Active', 'color' => '#00B894'],
    'completed' => ['label' => 'Completed', 'color' => '#74B9FF'],
    'on_hold' => ['label' => 'On Hold', 'color' => '#FDCB6E'],
    'cancelled' => ['label' => 'Cancelled', 'color' => '#E84393']
]);

define('ASSIGNMENT_STATUSES', [
    'assigned' => ['label' => 'Assigned', 'color' => '#74B9FF'],
    'in_progress' => ['label' => 'In Progress', 'color' => '#FDCB6E'],
    'submitted' => ['label' => 'Submitted', 'color' => '#A29BFE'],
    'reviewed' => ['label' => 'Reviewed', 'color' => '#FD79A8'],
    'completed' => ['label' => 'Completed', 'color' => '#00B894']
]);

define('PRIORITY_LEVELS', [
    'low' => ['label' => 'Low', 'color' => '#74B9FF'],
    'medium' => ['label' => 'Medium', 'color' => '#FDCB6E'],
    'high' => ['label' => 'High', 'color' => '#FD79A8'],
    'urgent' => ['label' => 'Urgent', 'color' => '#E84393']
]);

// ====================================================
// UTILITY FUNCTIONS
// ====================================================

/**
 * Check if user has permission
 */
function hasPermission($permission, $userRole = null) {
    if (!$userRole && isset($_SESSION['user_role'])) {
        $userRole = $_SESSION['user_role'];
    }
    
    if (!$userRole || !isset(USER_ROLES[$userRole])) {
        return false;
    }
    
    return in_array($permission, USER_ROLES[$userRole]['permissions']);
}

/**
 * Get user role display name
 */
function getRoleDisplayName($role) {
    return USER_ROLES[$role]['name'] ?? ucfirst($role);
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && 
           hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Format bytes to human readable
 */
function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}

/**
 * Generate random string
 */
function generateRandomString($length = 10) {
    return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
}

/**
 * Log activity (simple logging function)
 */
function logActivity($message, $userId = null, $level = 'INFO') {
    if (!LOG_ERRORS) return;
    
    $userId = $userId ?? ($_SESSION['user_id'] ?? 'guest');
    $timestamp = date(DATETIME_FORMAT);
    $logEntry = "[{$timestamp}] [{$level}] User: {$userId} | {$message}" . PHP_EOL;
    
    $logFile = BASE_PATH . 'logs/activity.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Redirect with message
 */
function redirect($url, $message = null, $type = 'info') {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    } else {
        echo "<script>window.location.href='{$url}';</script>";
        exit;
    }
}

/**
 * Display flash message
 */
function displayFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        
        echo "<div class='alert alert-{$type} flash-message'>{$message}</div>";
    }
}

/**
 * Check if feature is enabled
 */
function isFeatureEnabled($feature) {
    return FEATURES[$feature] ?? false;
}

// ====================================================
// ERROR HANDLING
// ====================================================

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Custom error handler
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $message = "Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}";
    error_log($message);
    
    if (DEBUG_MODE) {
        echo "<div style='background: #ffebee; color: #c62828; padding: 10px; margin: 10px; border-left: 4px solid #c62828;'>";
        echo "<strong>Debug Error:</strong> " . htmlspecialchars($errstr) . "<br>";
        echo "<small>File: " . htmlspecialchars($errfile) . " | Line: {$errline}</small>";
        echo "</div>";
    }
}

set_error_handler('customErrorHandler');

// ====================================================
// INCLUDE DEPENDENCIES
// ====================================================

// Load database configuration
require_once CONFIG_PATH . 'database.php';

// Initialize application
logActivity("Application initialized");

?>