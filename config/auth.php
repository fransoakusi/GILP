<?php
/**
 * Authentication System for Girls Leadership Program
 * Secure user authentication and session management
 */

// Prevent direct access - only if not properly included
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(dirname(__FILE__)));
}

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Authenticate user login
     */
    public function login($username, $password, $remember = false) {
        try {
            // Clean inputs
            $username = sanitizeInput($username);
            
            // Get user from database
            $user = $this->getUserByUsername($username);
            
            if (!$user) {
                logActivity("Login attempt with invalid username: {$username}", null, 'WARNING');
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Check if user is active
            if (!$user['is_active']) {
                logActivity("Login attempt for inactive user: {$username}", $user['user_id'], 'WARNING');
                return ['success' => false, 'message' => 'Account is inactive. Contact administrator.'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                logActivity("Failed login attempt for user: {$username}", $user['user_id'], 'WARNING');
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Update last login
            $this->updateLastLogin($user['user_id']);
            
            // Create session
            $this->createUserSession($user);
            
            // Handle remember me
            if ($remember) {
                $this->setRememberMeCookie($user['user_id']);
            }
            
            logActivity("Successful login", $user['user_id'], 'INFO');
            
            return [
                'success' => true, 
                'message' => 'Login successful',
                'user' => $this->sanitizeUserData($user)
            ];
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login error occurred'];
        }
    }
    
    /**
     * Register new user
     */
    public function register($userData) {
        try {
            // Validate required fields
            $required = ['username', 'email', 'password', 'first_name', 'last_name', 'role'];
            foreach ($required as $field) {
                if (empty($userData[$field])) {
                    return ['success' => false, 'message' => "Field '{$field}' is required"];
                }
            }
            
            // Sanitize inputs
            $userData = sanitizeInput($userData);
            
            // Validate email
            if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid email format'];
            }
            
            // Validate password
            $passwordValidation = $this->validatePassword($userData['password']);
            if (!$passwordValidation['valid']) {
                return ['success' => false, 'message' => $passwordValidation['message']];
            }
            
            // Check if username exists
            if ($this->getUserByUsername($userData['username'])) {
                return ['success' => false, 'message' => 'Username already exists'];
            }
            
            // Check if email exists
            if ($this->getUserByEmail($userData['email'])) {
                return ['success' => false, 'message' => 'Email already registered'];
            }
            
            // Validate role
            if (!array_key_exists($userData['role'], USER_ROLES)) {
                return ['success' => false, 'message' => 'Invalid user role'];
            }
            
            // Hash password
            $userData['password_hash'] = password_hash($userData['password'], PASSWORD_DEFAULT);
            unset($userData['password']);
            
            // Insert user
            $userId = $this->db->insert('users', $userData);
            
            if ($userId) {
                logActivity("New user registered: {$userData['username']}", $userId, 'INFO');
                return [
                    'success' => true, 
                    'message' => 'Registration successful',
                    'user_id' => $userId
                ];
            } else {
                return ['success' => false, 'message' => 'Registration failed'];
            }
            
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Registration error occurred'];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        $userId = $_SESSION['user_id'] ?? null;
        
        // Clear remember me cookie
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
        }
        
        // Destroy session
        session_destroy();
        session_start();
        
        logActivity("User logged out", $userId, 'INFO');
        
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
            // Check session timeout
            if (isset($_SESSION['last_activity'])) {
                if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
                    $this->logout();
                    return false;
                }
            }
            
            $_SESSION['last_activity'] = time();
            return true;
        }
        
        // Check remember me cookie
        if (isset($_COOKIE['remember_token'])) {
            return $this->handleRememberMe();
        }
        
        return false;
    }
    
    /**
     * Get current user data
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $userId = $_SESSION['user_id'];
        $user = $this->getUserById($userId);
        
        return $user ? $this->sanitizeUserData($user) : null;
    }
    
    /**
     * Change user password
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        try {
            $user = $this->getUserById($userId);
            
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }
            
            // Verify current password
            if (!password_verify($currentPassword, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            
            // Validate new password
            $passwordValidation = $this->validatePassword($newPassword);
            if (!$passwordValidation['valid']) {
                return ['success' => false, 'message' => $passwordValidation['message']];
            }
            
            // Update password
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->db->update('users', 
                ['password_hash' => $newPasswordHash], 
                'user_id = :user_id', 
                [':user_id' => $userId]
            );
            
            logActivity("Password changed", $userId, 'INFO');
            
            return ['success' => true, 'message' => 'Password changed successfully'];
            
        } catch (Exception $e) {
            error_log("Password change error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Password change failed'];
        }
    }
    
    /**
     * Reset password (generate new temporary password)
     */
    public function resetPassword($email) {
        try {
            $user = $this->getUserByEmail($email);
            
            if (!$user) {
                // Don't reveal if email exists or not
                return ['success' => true, 'message' => 'If email exists, reset instructions sent'];
            }
            
            // Generate temporary password
            $tempPassword = generateRandomString(12);
            $tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            // Update user password
            $this->db->update('users', 
                ['password_hash' => $tempPasswordHash], 
                'user_id = :user_id', 
                [':user_id' => $user['user_id']]
            );
            
            // TODO: Send email with temporary password
            // For now, we'll log it (REMOVE IN PRODUCTION)
            if (DEBUG_MODE) {
                error_log("Temporary password for {$email}: {$tempPassword}");
            }
            
            logActivity("Password reset requested", $user['user_id'], 'INFO');
            
            return ['success' => true, 'message' => 'Reset instructions sent to your email'];
            
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Password reset failed'];
        }
    }
    
    /**
     * Check user permissions
     */
    public function hasPermission($permission) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        return hasPermission($permission, $_SESSION['user_role']);
    }
    
    /**
     * Require login (redirect if not logged in)
     */
    public function requireLogin($redirectUrl = '/login.php') {
        if (!$this->isLoggedIn()) {
            redirect($redirectUrl, 'Please log in to access this page', 'warning');
        }
    }
    
    /**
     * Require specific permission
     */
    public function requirePermission($permission, $redirectUrl = '/dashboard.php') {
        $this->requireLogin();
        
        if (!$this->hasPermission($permission)) {
            redirect($redirectUrl, 'Access denied', 'error');
        }
    }
    
    // ====================================================
    // PRIVATE HELPER METHODS
    // ====================================================
    
    private function getUserByUsername($username) {
        return $this->db->fetchRow(
            "SELECT * FROM users WHERE username = :username", 
            [':username' => $username]
        );
    }
    
    private function getUserByEmail($email) {
        return $this->db->fetchRow(
            "SELECT * FROM users WHERE email = :email", 
            [':email' => $email]
        );
    }
    
    private function getUserById($userId) {
        return $this->db->fetchRow(
            "SELECT * FROM users WHERE user_id = :user_id", 
            [':user_id' => $userId]
        );
    }
    
    private function updateLastLogin($userId) {
        $this->db->update('users', 
            ['last_login' => date(DATETIME_FORMAT)], 
            'user_id = :user_id', 
            [':user_id' => $userId]
        );
    }
    
    private function createUserSession($user) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['last_activity'] = time();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
    }
    
    private function sanitizeUserData($user) {
        unset($user['password_hash']);
        return $user;
    }
    
    private function validatePassword($password) {
        $errors = [];
        
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = "Password must be at least " . MIN_PASSWORD_LENGTH . " characters long";
        }
        
        if (REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }
        
        if (REQUIRE_NUMBERS && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }
        
        if (REQUIRE_SPECIAL_CHARS && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }
        
        return [
            'valid' => empty($errors),
            'message' => empty($errors) ? 'Password is valid' : implode('. ', $errors)
        ];
    }
    
    private function setRememberMeCookie($userId) {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + (30 * 24 * 60 * 60); // 30 days
        
        // Store token in database (you'd add a remember_tokens table)
        // For now, we'll use a simple approach
        setcookie('remember_token', $token, $expiry, '/', '', false, true);
        
        // TODO: Store token hash in database with user_id and expiry
    }
    
    private function handleRememberMe() {
        // TODO: Implement remember me functionality
        // Check token in database and auto-login if valid
        return false;
    }
}

// ====================================================
// GLOBAL AUTH FUNCTIONS
// ====================================================

function auth() {
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth();
    }
    return $auth;
}

function isLoggedIn() {
    return auth()->isLoggedIn();
}

function getCurrentUser() {
    return auth()->getCurrentUser();
}

function requireLogin($redirectUrl = '/login.php') {
    auth()->requireLogin($redirectUrl);
}

function requirePermission($permission, $redirectUrl = '/dashboard.php') {
    auth()->requirePermission($permission, $redirectUrl);
}

function hasUserPermission($permission) {
    return auth()->hasPermission($permission);
}

?>