<?php
/**
 * Password Hashing Utility
 * 
 * This script provides functions for securely hashing passwords and verifying them.
 * Uses PHP's password_hash() and password_verify() functions which are currently
 * the recommended way to handle passwords in PHP.
 */

/**
 * Hash a password securely
 * 
 * @param string $password The plain text password to hash
 * @return string The hashed password
 */
function hashPassword($password) {
    // PASSWORD_DEFAULT uses bcrypt algorithm (as of PHP 5.5.0)
    // The cost factor (10) can be increased as hardware improves
    $options = [
        'cost' => 10,
    ];
    
    return password_hash($password, PASSWORD_DEFAULT, $options);
}

/**
 * Verify a password against a hash
 * 
 * @param string $password The plain text password to verify
 * @param string $hash The stored password hash
 * @return bool True if password matches, false otherwise
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Example usage:
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = $_POST['password'];
    
    // Hash the password (you would store this in your database)
    $hashedPassword = hashPassword($password);
    
    // For demonstration, verify the password immediately
    $isValid = verifyPassword($password, $hashedPassword);
    
    echo "<h2>Password Hashing Results</h2>";
    echo "<p><strong>Original Password:</strong> " . htmlspecialchars($password) . "</p>";
    echo "<p><strong>Hashed Password:</strong> " . htmlspecialchars($hashedPassword) . "</p>";
    echo "<p><strong>Verification Result:</strong> " . ($isValid ? "✅ Valid" : "❌ Invalid") . "</p>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Hashing Example</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        form { background: #f4f4f4; padding: 20px; border-radius: 5px; }
        input[type="password"] { width: 100%; padding: 8px; margin: 10px 0; }
        input[type="submit"] { background: #333; color: #fff; border: 0; padding: 10px 20px; cursor: pointer; }
        input[type="submit"]:hover { background: #555; }
    </style>
</head>
<body>
    <h1>Password Hashing Demo</h1>
    <form method="post">
        <label for="password">Enter a password to hash:</label><br>
        <input type="password" id="password" name="password" required>
        <br>
        <input type="submit" value="Hash Password">
    </form>
    
    <h2>About Password Hashing</h2>
    <p>This example uses PHP's <code>password_hash()</code> function with the bcrypt algorithm (default as of PHP 5.5.0).</p>
    <p>The hashed password includes:</p>
    <ul>
        <li>The algorithm used</li>
        <li>The cost factor</li>
        <li>The salt (automatically generated)</li>
        <li>The actual hash</li>
    </ul>
    <p>When verifying, <code>password_verify()</code> extracts all necessary information from the stored hash.</p>
</body>
</html>