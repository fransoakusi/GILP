<?php
/**
 * Session Manager for Girls Leadership Program
 * Centralized session configuration and management
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(dirname(__FILE__)));
}

class SessionManager {
    private static $initialized = false;
    
    /**
     * Initialize session with secure configuration
     */
    public static function init() {
        if (self::$initialized) {
            return true;
        }
        
        // Only configure if session is not active
        if (session_status() === PHP_SESSION_NONE) {
            // Set session configuration before starting
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
            ini_set('session.gc_maxlifetime', 3600);
            ini_set('session.cookie_lifetime', 3600);
            ini_set('session.sid_length', 48);
            ini_set('session.sid_bits_per_character', 6);
            
            // Set session name
            session_name('GLP_SESSION');
            
            // Start session
            if (session_start()) {
                self::$initialized = true;
                
                // Initialize session security
                self::initSecurity();
                
                return true;
            } else {
                error_log("Failed to start session");
                return false;
            }
        } else {
            // Session already active
            self::$initialized = true;
            return true;
        }
    }
    
    /**
     * Initialize session security measures
     */
    private static function initSecurity() {
        // Regenerate session ID on first visit
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
            $_SESSION['created_at'] = time();
        }
        
        // Check session timeout
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > 3600) { // 1 hour timeout
                self::destroy();
                return false;
            }
        }
        
        $_SESSION['last_activity'] = time();
        
        // Regenerate session ID periodically (every 30 minutes)
        if (isset($_SESSION['created_at']) && time() - $_SESSION['created_at'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = time();
        }
        
        return true;
    }
    
    /**
     * Set session variable
     */
    public static function set($key, $value) {
        self::init();
        $_SESSION[$key] = $value;
    }
    
    /**
     * Get session variable
     */
    public static function get($key, $default = null) {
        self::init();
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Check if session variable exists
     */
    public static function has($key) {
        self::init();
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove session variable
     */
    public static function remove($key) {
        self::init();
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * Destroy session
     */
    public static function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            // Delete session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
        }
        self::$initialized = false;
    }
    
    /**
     * Get session ID
     */
    public static function getId() {
        self::init();
        return session_id();
    }
    
    /**
     * Check if session is active and valid
     */
    public static function isActive() {
        return session_status() === PHP_SESSION_ACTIVE && self::$initialized;
    }
    
    /**
     * Get session info for debugging
     */
    public static function getInfo() {
        self::init();
        return [
            'session_id' => session_id(),
            'session_status' => session_status(),
            'created_at' => self::get('created_at'),
            'last_activity' => self::get('last_activity'),
            'user_id' => self::get('user_id'),
            'username' => self::get('username'),
            'user_role' => self::get('user_role')
        ];
    }
}

// Auto-initialize session when this file is included
SessionManager::init();

?>