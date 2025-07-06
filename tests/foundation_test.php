<?php
/**
 * Foundation Test for Girls Leadership Program
 * Test database, configuration, and authentication systems
 */

// Start output buffering to prevent header issues
ob_start();

// Set error reporting for testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define APP_ROOT for testing
define('APP_ROOT', dirname(dirname(__FILE__)));

// Create logs directory if it doesn't exist
$logsDir = APP_ROOT . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Include configuration files
try {
    // Load session manager first to avoid conflicts
    if (file_exists(APP_ROOT . '/config/session.php')) {
        require_once APP_ROOT . '/config/session.php';
    }
    
    require_once APP_ROOT . '/config/config.php';
    require_once APP_ROOT . '/config/auth.php';
} catch (Exception $e) {
    ob_end_clean();
    die("<h1>Configuration Error</h1><p>Could not load configuration files: " . $e->getMessage() . "</p>");
}

class FoundationTest {
    private $testResults = [];
    private $totalTests = 0;
    private $passedTests = 0;
    
    public function runAllTests() {
        // Clear any previous output
        ob_clean();
        
        echo "<h1>🧪 Girls Leadership Program - Foundation Tests</h1>";
        echo "<div style='font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px;'>";
        
        $this->testConfiguration();
        $this->testDatabase();
        $this->testAuthentication();
        $this->testUtilityFunctions();
        
        $this->displayResults();
        
        echo "</div>";
        
        // Flush output buffer
        ob_end_flush();
    }
    
    /**
     * Test Configuration System
     */
    private function testConfiguration() {
        $this->addTestSection("Configuration System Tests");
        
        // Test constants
        $this->test("APP_NAME defined", defined('APP_NAME'));
        $this->test("APP_VERSION defined", defined('APP_VERSION'));
        $this->test("DEBUG_MODE defined", defined('DEBUG_MODE'));
        $this->test("USER_ROLES defined", defined('USER_ROLES'));
        
        // Test paths
        $this->test("BASE_PATH exists", is_dir(BASE_PATH));
        $this->test("CONFIG_PATH exists", is_dir(CONFIG_PATH));
        $this->test("ASSETS_PATH exists", is_dir(ASSETS_PATH));
        
        // Test session manager
        $this->test("SessionManager class exists", class_exists('SessionManager'));
        if (class_exists('SessionManager')) {
            $this->test("Session is active", SessionManager::isActive());
            
            // Test session operations
            SessionManager::set('test_key', 'test_value');
            $this->test("Session set/get works", SessionManager::get('test_key') === 'test_value');
            SessionManager::remove('test_key');
            $this->test("Session remove works", !SessionManager::has('test_key'));
        }
        
        // Test user roles structure
        $roles = USER_ROLES;
        $this->test("Admin role exists", isset($roles['admin']));
        $this->test("Mentor role exists", isset($roles['mentor']));
        $this->test("Participant role exists", isset($roles['participant']));
        $this->test("Volunteer role exists", isset($roles['volunteer']));
        
        // Test utility functions
        $this->test("sanitizeInput function exists", function_exists('sanitizeInput'));
        $this->test("generateCSRFToken function exists", function_exists('generateCSRFToken'));
        $this->test("hasPermission function exists", function_exists('hasPermission'));
    }
    
    /**
     * Test Database System
     */
    private function testDatabase() {
        $this->addTestSection("Database System Tests");
        
        try {
            // Test database connection
            $this->test("Database class exists", class_exists('Database'));
            
            $db = Database::getInstance();
            $this->test("Database instance created", $db instanceof Database);
            
            // Test connection
            $connectionTest = Database::testConnection();
            $this->test("Database connection successful", $connectionTest);
            
            if ($connectionTest) {
                // Test basic operations
                $testQuery = $db->fetchRow("SELECT 1 as test_value");
                $this->test("Basic query execution", $testQuery['test_value'] == 1);
                
                // Test table existence (from our schema)
                $this->test("Users table exists", $db->tableExists('users'));
                $this->test("Projects table exists", $db->tableExists('projects'));
                $this->test("Assignments table exists", $db->tableExists('assignments'));
                
                // Test database statistics
                $stats = $db->getStats();
                $this->test("Database stats retrieved", is_array($stats));
                $this->test("Table count available", isset($stats['table_count']));
                
                // Test sample data exists
                $userCount = $db->count('users');
                $this->test("Sample users exist", $userCount > 0);
                
                echo "<div style='background: #e8f5e8; padding: 10px; margin: 10px 0; border-left: 4px solid #4caf50;'>";
                echo "<strong>Database Statistics:</strong><br>";
                echo "Tables: " . ($stats['table_count'] ?? 'N/A') . "<br>";
                echo "Users: " . ($stats['total_users'] ?? 'N/A') . "<br>";
                echo "Active Projects: " . ($stats['active_projects'] ?? 'N/A') . "<br>";
                echo "Pending Assignments: " . ($stats['pending_assignments'] ?? 'N/A');
                echo "</div>";
            }
            
        } catch (Exception $e) {
            $this->test("Database connection", false, "Error: " . $e->getMessage());
        }
    }
    
    /**
     * Test Authentication System (Fixed for header issues)
     */
    private function testAuthentication() {
        $this->addTestSection("Authentication System Tests");
        
        try {
            // Test Auth class
            $this->test("Auth class exists", class_exists('Auth'));
            
            $auth = new Auth();
            $this->test("Auth instance created", $auth instanceof Auth);
            
            // Test global auth functions
            $this->test("auth() function exists", function_exists('auth'));
            $this->test("isLoggedIn() function exists", function_exists('isLoggedIn'));
            $this->test("getCurrentUser() function exists", function_exists('getCurrentUser'));
            
            // Clear any existing session first
            if (SessionManager::isActive()) {
                SessionManager::remove('user_id');
                SessionManager::remove('username');
                SessionManager::remove('user_role');
                SessionManager::remove('full_name');
            }
            
            // Test login with sample user (admin/admin123) - but don't regenerate session
            // We'll test login functionality without the session regeneration
            $db = Database::getInstance();
            $user = $db->fetchRow("SELECT * FROM users WHERE username = :username", [':username' => 'admin']);
            
            if ($user && password_verify('admin123', $user['password_hash'])) {
                $this->test("Sample admin credentials valid", true);
                
                // Manually set session data for testing (avoid session_regenerate_id)
                SessionManager::set('user_id', $user['user_id']);
                SessionManager::set('username', $user['username']);
                SessionManager::set('user_role', $user['role']);
                SessionManager::set('full_name', $user['first_name'] . ' ' . $user['last_name']);
                
                $this->test("User session created", SessionManager::has('user_id'));
                
                $currentUser = $auth->getCurrentUser();
                $this->test("Current user retrieved", !empty($currentUser));
                $this->test("User has correct role", $currentUser['role'] === 'admin');
                
                // Test permissions
                $this->test("Admin has user_management permission", $auth->hasPermission('user_management'));
                $this->test("Admin has project_management permission", $auth->hasPermission('project_management'));
                
                // Clear session
                SessionManager::remove('user_id');
                SessionManager::remove('username');
                SessionManager::remove('user_role');
                SessionManager::remove('full_name');
                $this->test("Session cleared", !SessionManager::has('user_id'));
                
            } else {
                $this->test("Sample admin credentials valid", false, "Admin user not found or password incorrect");
            }
            
            // Test password validation
            $reflection = new ReflectionClass($auth);
            if ($reflection->hasMethod('validatePassword')) {
                $method = $reflection->getMethod('validatePassword');
                $method->setAccessible(true);
                
                $weakPassword = $method->invoke($auth, '123');
                $strongPassword = $method->invoke($auth, 'StrongPass123!');
                
                $this->test("Weak password rejected", !$weakPassword['valid']);
                $this->test("Strong password accepted", $strongPassword['valid']);
            } else {
                $this->test("Password validation method exists", false, "validatePassword method not found");
            }
            
        } catch (Exception $e) {
            $this->test("Authentication system", false, "Error: " . $e->getMessage());
        }
    }
    
    /**
     * Test Utility Functions
     */
    private function testUtilityFunctions() {
        $this->addTestSection("Utility Functions Tests");
        
        // Test sanitization
        $testInput = "<script>alert('xss')</script>Test Input";
        $sanitized = sanitizeInput($testInput);
        $this->test("Input sanitization", !strpos($sanitized, '<script>'));
        
        // Test CSRF token generation
        $token1 = generateCSRFToken();
        $token2 = generateCSRFToken();
        $this->test("CSRF token generated", !empty($token1));
        $this->test("CSRF token consistency", $token1 === $token2);
        
        // Test random string generation
        $randomString = generateRandomString(10);
        $this->test("Random string generated", strlen($randomString) === 10);
        
        // Test role permissions
        $adminPermissions = hasPermission('user_management', 'admin');
        $participantPermissions = hasPermission('user_management', 'participant');
        
        $this->test("Admin has management permissions", $adminPermissions);
        $this->test("Participant lacks management permissions", !$participantPermissions);
        
        // Test format bytes
        $formatted = formatBytes(1024);
        $this->test("Byte formatting works", strpos($formatted, 'KB') !== false);
    }
    
    /**
     * Add test section header
     */
    private function addTestSection($title) {
        echo "<h2 style='color: #6C5CE7; border-bottom: 2px solid #6C5CE7; padding-bottom: 10px;'>$title</h2>";
    }
    
    /**
     * Run individual test
     */
    private function test($description, $condition, $errorMessage = '') {
        $this->totalTests++;
        $status = $condition ? 'PASS' : 'FAIL';
        $color = $condition ? '#4caf50' : '#f44336';
        $icon = $condition ? '✅' : '❌';
        
        if ($condition) {
            $this->passedTests++;
        }
        
        echo "<div style='padding: 8px; margin: 4px 0; background: " . ($condition ? '#e8f5e8' : '#ffebee') . "; border-left: 4px solid $color;'>";
        echo "<span style='color: $color; font-weight: bold;'>$icon $status</span> - $description";
        
        if (!$condition && !empty($errorMessage)) {
            echo "<br><small style='color: #666;'>$errorMessage</small>";
        }
        
        echo "</div>";
        
        $this->testResults[] = [
            'description' => $description,
            'status' => $status,
            'passed' => $condition,
            'error' => $errorMessage
        ];
    }
    
    /**
     * Display final test results
     */
    private function displayResults() {
        $successRate = ($this->passedTests / $this->totalTests) * 100;
        $color = $successRate >= 90 ? '#4caf50' : ($successRate >= 70 ? '#ff9800' : '#f44336');
        
        echo "<div style='background: linear-gradient(135deg, #6C5CE7, #A29BFE); color: white; padding: 20px; margin: 20px 0; border-radius: 10px; text-align: center;'>";
        echo "<h2>🎯 Test Results Summary</h2>";
        echo "<div style='font-size: 24px; margin: 10px 0;'>";
        echo "<strong>{$this->passedTests}/{$this->totalTests}</strong> tests passed";
        echo "</div>";
        echo "<div style='font-size: 18px; color: $color;'>";
        echo "<strong>" . number_format($successRate, 1) . "%</strong> success rate";
        echo "</div>";
        
        if ($successRate >= 90) {
            echo "<div style='margin-top: 15px; font-size: 16px;'>";
            echo "🎉 <strong>Excellent!</strong> Foundation is ready for development.";
            echo "</div>";
        } elseif ($successRate >= 70) {
            echo "<div style='margin-top: 15px; font-size: 16px;'>";
            echo "⚠️ <strong>Good, but needs attention.</strong> Some issues to resolve.";
            echo "</div>";
        } else {
            echo "<div style='margin-top: 15px; font-size: 16px;'>";
            echo "🚨 <strong>Critical issues found.</strong> Foundation needs fixes before proceeding.";
            echo "</div>";
        }
        
        echo "</div>";
        
        // Show next steps
        if ($successRate >= 90) {
            echo "<div style='background: #e8f5e8; padding: 15px; border-left: 4px solid #4caf50; margin: 20px 0;'>";
            echo "<h3>✅ Next Development Steps:</h3>";
            echo "<ol>";
            echo "<li>Create basic HTML layout and CSS styling</li>";
            echo "<li>Develop login and registration pages</li>";
            echo "<li>Build dashboard with role-based navigation</li>";
            echo "<li>Implement user management module</li>";
            echo "<li>Add project management features</li>";
            echo "</ol>";
            echo "</div>";
        }
        
        // System information
        echo "<div style='background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h3>🔧 System Information</h3>";
        echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-family: monospace; font-size: 14px;'>";
        echo "<div><strong>PHP Version:</strong> " . PHP_VERSION . "</div>";
        echo "<div><strong>App Version:</strong> " . APP_VERSION . "</div>";
        echo "<div><strong>Debug Mode:</strong> " . (DEBUG_MODE ? 'Enabled' : 'Disabled') . "</div>";
        echo "<div><strong>Session Status:</strong> " . (SessionManager::isActive() ? 'Active' : 'Inactive') . "</div>";
        echo "<div><strong>Memory Usage:</strong> " . formatBytes(memory_get_usage(true)) . "</div>";
        echo "<div><strong>Test Duration:</strong> " . number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) . "s</div>";
        echo "</div>";
        echo "</div>";
    }
}

// Run tests
$test = new FoundationTest();
$test->runAllTests();

?>