<?php
/**
 * Session Fix Verification for Girls Leadership Program
 * Quick diagnostic and fix for session-related issues
 */

// Define APP_ROOT
define('APP_ROOT', dirname(__FILE__));

echo "<!DOCTYPE html>
<html>
<head>
    <title>Session Fix - Girls Leadership Program</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f8f9fa; }
        .success { color: #00B894; background: #e8f6f3; padding: 10px; border-left: 4px solid #00B894; margin: 10px 0; }
        .error { color: #E84393; background: #fdf2f8; padding: 10px; border-left: 4px solid #E84393; margin: 10px 0; }
        .warning { color: #FDCB6E; background: #fef7e6; padding: 10px; border-left: 4px solid #FDCB6E; margin: 10px 0; }
        .info { color: #74B9FF; background: #e6f3ff; padding: 10px; border-left: 4px solid #74B9FF; margin: 10px 0; }
        .card { background: white; padding: 20px; margin: 20px 0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #6C5CE7; }
        .btn { display: inline-block; padding: 10px 20px; background: #6C5CE7; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px 0 0; }
        code { background: #f1f3f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>";

echo "<h1>🔧 Session Fix Verification</h1>";

// Check session status before any configuration
$initialSessionStatus = session_status();
echo "<div class='info'><strong>Initial Session Status:</strong> ";
switch($initialSessionStatus) {
    case PHP_SESSION_DISABLED:
        echo "Disabled ❌";
        break;
    case PHP_SESSION_NONE:
        echo "None (Good) ✅";
        break;
    case PHP_SESSION_ACTIVE:
        echo "Already Active ⚠️";
        break;
}
echo "</div>";

// Test 1: Check if files exist
echo "<div class='card'>";
echo "<h2>📁 File Existence Check</h2>";

$files = [
    'config/session.php' => 'Session Manager',
    'config/config.php' => 'Main Configuration',
    'config/database.php' => 'Database Configuration',
    'config/auth.php' => 'Authentication System'
];

$allExist = true;
foreach ($files as $file => $description) {
    $exists = file_exists(APP_ROOT . '/' . $file);
    $status = $exists ? 'success' : 'error';
    $icon = $exists ? '✅' : '❌';
    
    echo "<div class='$status'>$icon $description: <code>$file</code></div>";
    
    if (!$exists) {
        $allExist = false;
    }
}
echo "</div>";

// Test 2: Try to load session manager
if (file_exists(APP_ROOT . '/config/session.php')) {
    echo "<div class='card'>";
    echo "<h2>🔄 Session Manager Test</h2>";
    
    try {
        require_once APP_ROOT . '/config/session.php';
        echo "<div class='success'>✅ Session Manager loaded successfully</div>";
        
        if (class_exists('SessionManager')) {
            echo "<div class='success'>✅ SessionManager class exists</div>";
            
            // Test session operations
            $sessionActive = SessionManager::isActive();
            echo "<div class='" . ($sessionActive ? 'success' : 'warning') . "'>";
            echo ($sessionActive ? '✅' : '⚠️') . " Session Active: " . ($sessionActive ? 'Yes' : 'No');
            echo "</div>";
            
            if ($sessionActive) {
                // Test basic operations
                SessionManager::set('test_fix', 'working');
                $testValue = SessionManager::get('test_fix');
                
                if ($testValue === 'working') {
                    echo "<div class='success'>✅ Session set/get operations working</div>";
                } else {
                    echo "<div class='error'>❌ Session operations failed</div>";
                }
                
                // Get session info
                $sessionInfo = SessionManager::getInfo();
                echo "<div class='info'>";
                echo "<strong>Session Info:</strong><br>";
                echo "Session ID: " . substr($sessionInfo['session_id'], 0, 10) . "...<br>";
                echo "Status: " . $sessionInfo['session_status'] . "<br>";
                echo "Created: " . ($sessionInfo['created_at'] ? date('Y-m-d H:i:s', $sessionInfo['created_at']) : 'N/A');
                echo "</div>";
                
                SessionManager::remove('test_fix');
            }
        } else {
            echo "<div class='error'>❌ SessionManager class not found</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error loading Session Manager: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    echo "</div>";
}

// Test 3: Try to load main configuration
if (file_exists(APP_ROOT . '/config/config.php')) {
    echo "<div class='card'>";
    echo "<h2>⚙️ Main Configuration Test</h2>";
    
    try {
        // Capture any warnings/errors
        ob_start();
        require_once APP_ROOT . '/config/config.php';
        $output = ob_get_contents();
        ob_end_clean();
        
        if (empty($output)) {
            echo "<div class='success'>✅ Configuration loaded without warnings</div>";
        } else {
            echo "<div class='warning'>⚠️ Configuration loaded with warnings:<br>";
            echo "<pre style='background: #fff3cd; padding: 10px; border-radius: 5px;'>" . htmlspecialchars($output) . "</pre>";
            echo "</div>";
        }
        
        // Check if constants are defined
        $constants = ['APP_NAME', 'APP_VERSION', 'DEBUG_MODE', 'SESSION_TIMEOUT'];
        foreach ($constants as $const) {
            $defined = defined($const);
            $status = $defined ? 'success' : 'error';
            $icon = $defined ? '✅' : '❌';
            echo "<div class='$status'>$icon Constant $const: " . ($defined ? 'Defined' : 'Missing') . "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error loading configuration: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    echo "</div>";
}

// Test 4: Overall status and recommendations
echo "<div class='card'>";
echo "<h2>🎯 Overall Status & Recommendations</h2>";

if ($allExist && class_exists('SessionManager') && defined('APP_NAME')) {
    echo "<div class='success'>";
    echo "<h3>🎉 All Systems Working!</h3>";
    echo "<p>Session configuration has been fixed and all core files are working properly.</p>";
    echo "</div>";
    
    echo "<h4>Next Steps:</h4>";
    echo "<ol>";
    echo "<li>Set up your MySQL database</li>";
    echo "<li>Update database credentials in <code>config/database.php</code></li>";
    echo "<li>Run the foundation tests</li>";
    echo "<li>Start using the application</li>";
    echo "</ol>";
    
    echo "<a href='tests/foundation_test.php' class='btn'>🧪 Run Foundation Tests</a>";
    echo "<a href='index.php' class='btn'>🏠 Go to Home Page</a>";
    
} else {
    echo "<div class='error'>";
    echo "<h3>🚨 Issues Found</h3>";
    echo "<p>Some problems still need to be resolved:</p>";
    echo "</div>";
    
    echo "<h4>Required Actions:</h4>";
    echo "<ul>";
    
    if (!$allExist) {
        echo "<li>Create missing configuration files (especially <code>config/session.php</code>)</li>";
    }
    
    if (!class_exists('SessionManager')) {
        echo "<li>Ensure SessionManager class is properly defined in <code>config/session.php</code></li>";
    }
    
    if (!defined('APP_NAME')) {
        echo "<li>Fix configuration loading issues in <code>config/config.php</code></li>";
    }
    
    echo "</ul>";
    
    echo "<a href='#' onclick='location.reload()' class='btn'>🔄 Re-run Check</a>";
}

echo "</div>";

// System information
echo "<div class='card'>";
echo "<h2>🔧 System Information</h2>";
echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>";
echo "<div><strong>PHP Version:</strong> " . PHP_VERSION . "</div>";
echo "<div><strong>Session Support:</strong> " . (extension_loaded('session') ? 'Available' : 'Missing') . "</div>";
echo "<div><strong>Current Directory:</strong> " . APP_ROOT . "</div>";
echo "<div><strong>Server:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</div>";
echo "<div><strong>Initial Session Status:</strong> $initialSessionStatus</div>";
echo "<div><strong>Current Session Status:</strong> " . session_status() . "</div>";
echo "</div>";
echo "</div>";

echo "</body></html>";
?>