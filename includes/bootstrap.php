<?php
/**
 * Bootstrap File for Girls Leadership Program
 * Loads all essential configuration and authentication files
 * Include this file in all pages to ensure proper functionality
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Bootstrap must be loaded with APP_ROOT defined');
}

// Check if already bootstrapped
if (defined('GLP_BOOTSTRAPPED')) {
    return;
}

// Start output buffering to prevent header issues
if (!ob_get_level()) {
    ob_start();
}

// Load core configuration files in the correct order
try {
    // 1. Load session manager first (if exists)
    if (file_exists(APP_ROOT . '/config/session.php')) {
        require_once APP_ROOT . '/config/session.php';
    }
    
    // 2. Load main configuration
    require_once APP_ROOT . '/config/config.php';
    
    // 3. Load authentication system
    require_once APP_ROOT . '/config/auth.php';
    
    // 4. Load utility functions (if exists)
    if (file_exists(APP_ROOT . '/includes/functions.php')) {
        require_once APP_ROOT . '/includes/functions.php';
    }
    
} catch (Exception $e) {
    // Log error and show user-friendly message
    error_log("Bootstrap Error: " . $e->getMessage());
    
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        die("<h1>Configuration Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>");
    } else {
        die("<h1>System Error</h1><p>The application could not be initialized. Please contact support.</p>");
    }
}

// Mark as bootstrapped
define('GLP_BOOTSTRAPPED', true);

// Log successful bootstrap (only in debug mode)
if (defined('DEBUG_MODE') && DEBUG_MODE && function_exists('logActivity')) {
    logActivity('System bootstrapped successfully');
}

// Function to get the current page name
if (!function_exists('getCurrentPage')) {
    function getCurrentPage() {
        return basename($_SERVER['PHP_SELF'], '.php');
    }
}

// Function to check if we're on a specific page
if (!function_exists('isCurrentPage')) {
    function isCurrentPage($page) {
        return getCurrentPage() === $page;
    }
}

// Function to generate page title
if (!function_exists('getPageTitle')) {
    function getPageTitle($pageTitle = null) {
        $appName = defined('APP_NAME') ? APP_NAME : 'Girls Leadership Program';
        return $pageTitle ? $pageTitle . ' - ' . $appName : $appName;
    }
}

// Function to include CSS/JS assets
if (!function_exists('asset')) {
    function asset($path) {
        $baseUrl = defined('ASSETS_URL') ? ASSETS_URL : '/assets/';
        return $baseUrl . ltrim($path, '/');
    }
}

// Function to generate URLs
if (!function_exists('url')) {
    function url($path = '') {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
        return $baseUrl . ltrim($path, '/');
    }
}

// Function to get user-friendly error messages
if (!function_exists('getUserFriendlyError')) {
    function getUserFriendlyError($error) {
        $errorMessages = [
            'invalid_credentials' => 'Invalid username or password. Please try again.',
            'account_inactive' => 'Your account is inactive. Please contact an administrator.',
            'session_expired' => 'Your session has expired. Please log in again.',
            'access_denied' => 'You do not have permission to access this page.',
            'database_error' => 'A system error occurred. Please try again later.',
            'validation_error' => 'Please check your input and try again.',
            'file_upload_error' => 'File upload failed. Please check file size and format.',
            'csrf_error' => 'Security token mismatch. Please refresh and try again.',
        ];
        
        return $errorMessages[$error] ?? 'An unexpected error occurred. Please try again.';
    }
}

// Function to format user names
if (!function_exists('formatUserName')) {
    function formatUserName($user, $format = 'full') {
        if (!is_array($user)) {
            return 'Unknown User';
        }
        
        $firstName = $user['first_name'] ?? '';
        $lastName = $user['last_name'] ?? '';
        $username = $user['username'] ?? '';
        
        switch ($format) {
            case 'first':
                return $firstName ?: $username;
            case 'last':
                return $lastName ?: $username;
            case 'initials':
                return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
            case 'full':
            default:
                return trim($firstName . ' ' . $lastName) ?: $username;
        }
    }
}

// Function to check if user has specific role
if (!function_exists('hasRole')) {
    function hasRole($role) {
        if (!isLoggedIn()) {
            return false;
        }
        
        $currentUser = getCurrentUser();
        return $currentUser && $currentUser['role'] === $role;
    }
}

// Function to check if user is admin
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return hasRole('admin');
    }
}

// Function to check if user is mentor
if (!function_exists('isMentor')) {
    function isMentor() {
        return hasRole('mentor');
    }
}

// Function to check if user is participant
if (!function_exists('isParticipant')) {
    function isParticipant() {
        return hasRole('participant');
    }
}

// Bootstrap complete
if (defined('DEBUG_MODE') && DEBUG_MODE && function_exists('logActivity')) {
    logActivity('Bootstrap loaded successfully');
}

?>