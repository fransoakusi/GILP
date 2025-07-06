 <?php
/**
 * User Management Form - Girls Leadership Program
 * Create and edit users with beautiful interface
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Require admin permission
requirePermission('user_management');

// Get current user
$currentUser = getCurrentUser();

// Determine if we're editing or creating
$userId = intval($_GET['id'] ?? 0);
$isEdit = $userId > 0;
$pageTitle = $isEdit ? 'Edit User' : 'Add New User';

// Initialize form data
$formData = [
    'username' => '',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'role' => 'participant',
    'bio' => '',
    'is_active' => true
];

$errors = [];
$message = '';
$messageType = 'info';

// If editing, load existing user data
if ($isEdit) {
    try {
        $db = Database::getInstance();
        $user = $db->fetchRow(
            "SELECT * FROM users WHERE user_id = :id",
            [':id' => $userId]
        );
        
        if (!$user) {
            redirect('list.php', 'User not found.', 'error');
        }
        
        // Populate form data (excluding password)
        $formData = [
            'username' => $user['username'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'phone' => $user['phone'] ?? '',
            'role' => $user['role'],
            'bio' => $user['bio'] ?? '',
            'is_active' => (bool)$user['is_active']
        ];
        
    } catch (Exception $e) {
        error_log("Error loading user: " . $e->getMessage());
        redirect('list.php', 'Error loading user data.', 'error');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        // Sanitize and validate input
        $formData = [
            'username' => sanitizeInput($_POST['username'] ?? ''),
            'email' => sanitizeInput($_POST['email'] ?? ''),
            'first_name' => sanitizeInput($_POST['first_name'] ?? ''),
            'last_name' => sanitizeInput($_POST['last_name'] ?? ''),
            'phone' => sanitizeInput($_POST['phone'] ?? ''),
            'role' => sanitizeInput($_POST['role'] ?? ''),
            'bio' => sanitizeInput($_POST['bio'] ?? ''),
            'is_active' => isset($_POST['is_active'])
        ];
        
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($formData['username'])) {
            $errors[] = 'Username is required.';
        } elseif (strlen($formData['username']) < 3) {
            $errors[] = 'Username must be at least 3 characters long.';
        }
        
        if (empty($formData['email'])) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (empty($formData['first_name'])) {
            $errors[] = 'First name is required.';
        }
        
        if (empty($formData['last_name'])) {
            $errors[] = 'Last name is required.';
        }
        
        if (!array_key_exists($formData['role'], USER_ROLES)) {
            $errors[] = 'Please select a valid role.';
        }
        
        // Password validation (required for new users, optional for editing)
        if (!$isEdit) {
            if (empty($password)) {
                $errors[] = 'Password is required for new users.';
            } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
                $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.';
            } elseif ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }
        } elseif (!empty($password)) {
            if (strlen($password) < MIN_PASSWORD_LENGTH) {
                $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.';
            } elseif ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }
        }
        
        // Check for duplicate username and email
        if (empty($errors)) {
            try {
                $db = Database::getInstance();
                
                // Check username uniqueness
                $existingUser = $db->fetchRow(
                    "SELECT user_id FROM users WHERE username = :username" . ($isEdit ? " AND user_id != :current_id" : ""),
                    $isEdit ? [':username' => $formData['username'], ':current_id' => $userId] : [':username' => $formData['username']]
                );
                
                if ($existingUser) {
                    $errors[] = 'Username already exists. Please choose a different one.';
                }
                
                // Check email uniqueness
                $existingEmail = $db->fetchRow(
                    "SELECT user_id FROM users WHERE email = :email" . ($isEdit ? " AND user_id != :current_id" : ""),
                    $isEdit ? [':email' => $formData['email'], ':current_id' => $userId] : [':email' => $formData['email']]
                );
                
                if ($existingEmail) {
                    $errors[] = 'Email already exists. Please choose a different one.';
                }
                
            } catch (Exception $e) {
                error_log("Error checking duplicates: " . $e->getMessage());
                $errors[] = 'Error validating user data.';
            }
        }
        
        // If no errors, save the user
        if (empty($errors)) {
            try {
                $db = Database::getInstance();
                
                $userData = $formData;
                $userData['updated_at'] = date('Y-m-d H:i:s');
                
                if (!empty($password)) {
                    $userData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                
                if ($isEdit) {
                    // Update existing user
                    unset($userData['username']); // Don't allow username changes
                    $db->update('users', $userData, 'user_id = :id', [':id' => $userId]);
                    
                    $message = 'User updated successfully.';
                    $messageType = 'success';
                    
                    logActivity("User updated: {$formData['username']}", $currentUser['user_id']);
                    
                } else {
                    // Create new user
                    $userData['date_joined'] = date('Y-m-d H:i:s');
                    $newUserId = $db->insert('users', $userData);
                    
                    $message = 'User created successfully.';
                    $messageType = 'success';
                    
                    logActivity("User created: {$formData['username']}", $currentUser['user_id']);
                    
                    // Redirect to edit the new user
                    redirect("manage.php?id={$newUserId}", $message, $messageType);
                }
                
            } catch (Exception $e) {
                error_log("Error saving user: " . $e->getMessage());
                $errors[] = 'Error saving user data. Please try again.';
            }
        }
    }
}

// Get role options for dropdown
$roleOptions = USER_ROLES;
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
            --accent-color: #FD79A8;
            --success-color: #00B894;
            --warning-color: #FDCB6E;
            --danger-color: #E84393;
            --dark-color: #2D3436;
            --light-color: #F8F9FA;
            --white: #FFFFFF;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --shadow: 0 4px 20px rgba(108, 92, 231, 0.1);
            --shadow-hover: 0 8px 30px rgba(108, 92, 231, 0.15);
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
        
        /* Navigation */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 1rem 2rem;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
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
        
        /* Main Content */
        .main-content {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-header {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: var(--gray-600);
            font-size: 1.1rem;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray-600);
            font-size: 0.9rem;
        }
        
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* Form Styles */
        .form-container {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.9rem;
        }
        
        .form-group label .required {
            color: var(--danger-color);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
        }
        
        .form-control.error {
            border-color: var(--danger-color);
        }
        
        .form-control.error:focus {
            box-shadow: 0 0 0 3px rgba(232, 67, 147, 0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-help {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-top: 0.25rem;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }
        
        .checkbox-wrapper label {
            margin: 0;
            font-weight: 500;
        }
        
        /* Password Strength Indicator */
        .password-group {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
        }
        
        .password-strength {
            margin-top: 0.5rem;
        }
        
        .strength-meter {
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.25rem;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak { background: var(--danger-color); }
        .strength-fair { background: var(--warning-color); }
        .strength-good { background: var(--success-color); }
        .strength-strong { background: var(--primary-color); }
        
        .strength-text {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        /* Buttons */
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
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        
        .btn-success {
            background: var(--success-color);
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
        
        .btn-danger {
            background: var(--danger-color);
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
        
        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            animation: slideIn 0.3s ease-out;
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
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Role Information */
        .role-info {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        
        .role-info h4 {
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .role-info ul {
            margin: 0;
            padding-left: 1.5rem;
        }
        
        .role-info li {
            margin-bottom: 0.25rem;
            color: var(--gray-600);
        }
        
        /* Loading States */
        .btn.loading {
            opacity: 0.8;
            pointer-events: none;
            position: relative;
        }
        
        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid var(--white);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 0.5rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                text-align: center;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .page-header {
                text-align: center;
            }
            
            .form-container {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
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
                    <i class="fas fa-users"></i> Users
                </a>
                <a href="../projects/list.php" class="nav-link">
                    <i class="fas fa-project-diagram"></i> Projects
                </a>
                <a href="../../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <div class="breadcrumb">
                    <a href="../../dashboard.php">Dashboard</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="list.php">Users</a>
                    <i class="fas fa-chevron-right"></i>
                    <span><?php echo $isEdit ? 'Edit User' : 'New User'; ?></span>
                </div>
                <h1 class="page-title"><?php echo $pageTitle; ?></h1>
                <p class="page-subtitle">
                    <?php echo $isEdit ? 'Update user information and permissions' : 'Add a new user to the program'; ?>
                </p>
            </div>
        </div>
        
        <!-- Alert Messages -->
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
        
        <!-- User Form -->
        <div class="form-container">
            <form method="POST" action="" id="userForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-grid">
                    <!-- Basic Information -->
                    <div class="form-group">
                        <label for="first_name">First Name <span class="required">*</span></label>
                        <input type="text" id="first_name" name="first_name" class="form-control" 
                               placeholder="Enter first name" 
                               value="<?php echo htmlspecialchars($formData['first_name']); ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name <span class="required">*</span></label>
                        <input type="text" id="last_name" name="last_name" class="form-control" 
                               placeholder="Enter last name" 
                               value="<?php echo htmlspecialchars($formData['last_name']); ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username <span class="required">*</span></label>
                        <input type="text" id="username" name="username" class="form-control" 
                               placeholder="Enter username" 
                               value="<?php echo htmlspecialchars($formData['username']); ?>"
                               <?php echo $isEdit ? 'readonly title="Username cannot be changed"' : ''; ?>
                               required>
                        <div class="form-help">Must be at least 3 characters long and unique</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="Enter email address" 
                               value="<?php echo htmlspecialchars($formData['email']); ?>" 
                               required>
                        <div class="form-help">Must be a valid email address</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" 
                               placeholder="Enter phone number" 
                               value="<?php echo htmlspecialchars($formData['phone']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role <span class="required">*</span></label>
                        <select id="role" name="role" class="form-control" required>
                            <?php foreach ($roleOptions as $roleKey => $roleData): ?>
                                <option value="<?php echo $roleKey; ?>" 
                                        <?php echo $formData['role'] === $roleKey ? 'selected' : ''; ?>>
                                    <?php echo $roleData['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="roleInfo" class="role-info" style="display: none;">
                            <!-- Role information will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Password Section -->
                    <div class="form-group">
                        <label for="password">
                            Password <?php echo $isEdit ? '(leave blank to keep current)' : '<span class="required">*</span>'; ?>
                        </label>
                        <div class="password-group">
                            <input type="password" id="password" name="password" class="form-control" 
                                   placeholder="Enter password" 
                                   <?php echo $isEdit ? '' : 'required'; ?>>
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('password')"></i>
                        </div>
                        <div class="password-strength">
                            <div class="strength-text">Password strength: <span id="strengthText">Enter password</span></div>
                            <div class="strength-meter">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">
                            Confirm Password <?php echo $isEdit ? '' : '<span class="required">*</span>'; ?>
                        </label>
                        <div class="password-group">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                                   placeholder="Confirm password" 
                                   <?php echo $isEdit ? '' : 'required'; ?>>
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password')"></i>
                        </div>
                        <div id="passwordMatch" class="form-help"></div>
                    </div>
                    
                    <!-- Bio -->
                    <div class="form-group full-width">
                        <label for="bio">Bio</label>
                        <textarea id="bio" name="bio" class="form-control" 
                                  placeholder="Tell us about this user's background and interests..."><?php echo htmlspecialchars($formData['bio']); ?></textarea>
                        <div class="form-help">Optional: Personal bio or description</div>
                    </div>
                    
                    <!-- Status -->
                    <div class="form-group full-width">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="is_active" name="is_active" 
                                   <?php echo $formData['is_active'] ? 'checked' : ''; ?>>
                            <label for="is_active">Active User</label>
                        </div>
                        <div class="form-help">Inactive users cannot log in to the system</div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="list.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    
                    <?php if ($isEdit): ?>
                        <button type="submit" class="btn btn-success" id="submitBtn">
                            <i class="fas fa-save"></i> Update User
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-user-plus"></i> Create User
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        // Role information data
        const rolePermissions = <?php echo json_encode(USER_ROLES); ?>;
        
        // Show role information when role is selected
        document.getElementById('role').addEventListener('change', function() {
            const roleInfo = document.getElementById('roleInfo');
            const selectedRole = this.value;
            
            if (selectedRole && rolePermissions[selectedRole]) {
                const permissions = rolePermissions[selectedRole].permissions;
                roleInfo.innerHTML = `
                    <h4>${rolePermissions[selectedRole].name} Permissions:</h4>
                    <ul>
                        ${permissions.map(permission => `<li>${permission.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</li>`).join('')}
                    </ul>
                `;
                roleInfo.style.display = 'block';
            } else {
                roleInfo.style.display = 'none';
            }
        });
        
        // Trigger role info display on page load
        document.getElementById('role').dispatchEvent(new Event('change'));
        
        // Password visibility toggle
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.parentElement.querySelector('.password-toggle');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            
            strengthFill.className = 'strength-fill strength-' + strength.level;
            strengthFill.style.width = strength.percentage + '%';
            strengthText.textContent = strength.text;
        });
        
        function calculatePasswordStrength(password) {
            let score = 0;
            let text = 'Weak';
            let level = 'weak';
            let percentage = 0;
            
            if (password.length >= 8) score += 1;
            if (password.length >= 12) score += 1;
            if (/[a-z]/.test(password)) score += 1;
            if (/[A-Z]/.test(password)) score += 1;
            if (/[0-9]/.test(password)) score += 1;
            if (/[^A-Za-z0-9]/.test(password)) score += 1;
            
            if (score < 3) {
                text = 'Weak';
                level = 'weak';
                percentage = 25;
            } else if (score < 4) {
                text = 'Fair';
                level = 'fair';
                percentage = 50;
            } else if (score < 5) {
                text = 'Good';
                level = 'good';
                percentage = 75;
            } else {
                text = 'Strong';
                level = 'strong';
                percentage = 100;
            }
            
            return { text, level, percentage };
        }
        
        // Password confirmation checker
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordMatchDiv = document.getElementById('passwordMatch');
        
        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (confirmPassword === '') {
                passwordMatchDiv.textContent = '';
                passwordMatchDiv.style.color = '';
                return;
            }
            
            if (password === confirmPassword) {
                passwordMatchDiv.innerHTML = '<i class="fas fa-check"></i> Passwords match';
                passwordMatchDiv.style.color = 'var(--success-color)';
            } else {
                passwordMatchDiv.innerHTML = '<i class="fas fa-times"></i> Passwords do not match';
                passwordMatchDiv.style.color = 'var(--danger-color)';
            }
        }
        
        passwordInput.addEventListener('input', checkPasswordMatch);
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        
        // Form submission handling
        document.getElementById('userForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            // Check password match for new users or when password is being changed
            if (password && password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return;
            }
            
            // Add loading state
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });
        
        // Username validation
        document.getElementById('username').addEventListener('input', function() {
            const username = this.value;
            if (username.length > 0 && username.length < 3) {
                this.classList.add('error');
            } else {
                this.classList.remove('error');
            }
        });
        
        // Email validation
        document.getElementById('email').addEventListener('input', function() {
            const email = this.value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email.length > 0 && !emailRegex.test(email)) {
                this.classList.add('error');
            } else {
                this.classList.remove('error');
            }
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
