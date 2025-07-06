<?php
/**
 * Directory Structure Setup for Girls Leadership Program
 * Creates necessary directories and checks permissions
 */

// Define APP_ROOT
define('APP_ROOT', dirname(__FILE__));

// Required directories
$directories = [
    'config',
    'assets',
    'assets/css',
    'assets/js', 
    'assets/images',
    'assets/images/icons',
    'assets/images/avatars',
    'assets/uploads',
    'assets/uploads/profiles',
    'assets/uploads/assignments',
    'assets/uploads/resources',
    'includes',
    'modules',
    'modules/dashboard',
    'modules/users',
    'modules/projects',
    'modules/assignments',
    'modules/training',
    'modules/communication',
    'modules/surveys',
    'modules/reports',
    'api',
    'tests',
    'logs'
];

$created = [];
$errors = [];
$existing = [];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Girls Leadership Program - Directory Setup</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: #00B894; }
        .error { color: #E84393; }
        .warning { color: #FDCB6E; }
        .info { color: #74B9FF; }
        .card { background: #f9f9f9; padding: 15px; margin: 10px 0; border-left: 4px solid #6C5CE7; }
        .btn { display: inline-block; padding: 10px 20px; background: #6C5CE7; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px 0 0; }
    </style>
</head>
<body>";

echo "<h1>📁 Girls Leadership Program - Directory Setup</h1>";

// Create directories
foreach ($directories as $dir) {
    $fullPath = APP_ROOT . '/' . $dir;
    
    if (is_dir($fullPath)) {
        $existing[] = $dir;
    } else {
        if (mkdir($fullPath, 0755, true)) {
            $created[] = $dir;
        } else {
            $errors[] = $dir;
        }
    }
}

// Results
echo "<div class='card'>";
echo "<h2>📊 Setup Results</h2>";

if (!empty($created)) {
    echo "<div class='success'>";
    echo "<h3>✅ Created Directories (" . count($created) . ")</h3>";
    echo "<ul>";
    foreach ($created as $dir) {
        echo "<li>$dir</li>";
    }
    echo "</ul>";
    echo "</div>";
}

if (!empty($existing)) {
    echo "<div class='warning'>";
    echo "<h3>📂 Already Existed (" . count($existing) . ")</h3>";
    echo "<ul>";
    foreach ($existing as $dir) {
        echo "<li>$dir</li>";
    }
    echo "</ul>";
    echo "</div>";
}

if (!empty($errors)) {
    echo "<div class='error'>";
    echo "<h3>❌ Failed to Create (" . count($errors) . ")</h3>";
    echo "<ul>";
    foreach ($errors as $dir) {
        echo "<li>$dir - Check permissions</li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "</div>";

// File checklist
$requiredFiles = [
    'config/database.php' => 'Database configuration',
    'config/config.php' => 'Main application configuration',
    'config/auth.php' => 'Authentication system',
    'config/session.php' => 'Session management',
    'tests/foundation_test.php' => 'Foundation testing'
];

echo "<div class='card'>";
echo "<h2>📄 Required Files Checklist</h2>";

$allFilesExist = true;
foreach ($requiredFiles as $file => $description) {
    $exists = file_exists(APP_ROOT . '/' . $file);
    $class = $exists ? 'success' : 'error';
    $icon = $exists ? '✅' : '❌';
    
    echo "<div class='$class'>$icon $file - $description</div>";
    
    if (!$exists) {
        $allFilesExist = false;
    }
}

echo "</div>";

// Next steps
echo "<div class='card'>";
echo "<h2>🚀 Next Steps</h2>";

if (empty($errors) && $allFilesExist) {
    echo "<div class='success'>";
    echo "<h3>🎉 Directory structure is ready!</h3>";
    echo "<p>All required directories created and files exist.</p>";
    echo "</div>";
    
    echo "<h3>Ready to proceed:</h3>";
    echo "<ol>";
    echo "<li>Set up your MySQL database</li>";
    echo "<li>Update database credentials in config/database.php</li>";
    echo "<li>Run foundation tests</li>";
    echo "<li>Start development</li>";
    echo "</ol>";
    
    echo "<a href='tests/foundation_test.php' class='btn'>🧪 Run Foundation Tests</a>";
    echo "<a href='index.php' class='btn'>🏠 Go to Home Page</a>";
    
} else {
    echo "<div class='warning'>";
    echo "<h3>⚠️ Setup incomplete</h3>";
    
    if (!empty($errors)) {
        echo "<p><strong>Directory creation failed:</strong></p>";
        echo "<ul>";
        foreach ($errors as $dir) {
            echo "<li>Check write permissions for: $dir</li>";
        }
        echo "</ul>";
    }
    
    if (!$allFilesExist) {
        echo "<p><strong>Missing configuration files:</strong></p>";
        echo "<p>Please create the required files using the provided artifacts.</p>";
    }
    
    echo "</div>";
    
    echo "<a href='#' onclick='location.reload()' class='btn'>🔄 Re-run Setup</a>";
}

echo "</div>";

// System info
echo "<div class='card'>";
echo "<h2>🔧 System Information</h2>";
echo "<div class='info'>";
echo "<strong>PHP Version:</strong> " . PHP_VERSION . "<br>";
echo "<strong>Current Directory:</strong> " . APP_ROOT . "<br>";
echo "<strong>Web Server:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "<br>";
echo "<strong>Document Root:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "<br>";
echo "<strong>Script Name:</strong> " . $_SERVER['SCRIPT_NAME'] . "<br>";
echo "</div>";
echo "</div>";

echo "</body></html>";
?>