<?php
/**
 * Training Session Attendance Tracking - Girls Leadership Program
 * Beautiful interface for managing session attendance
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Require training management permission
requirePermission('training_management');

// Get current user
$currentUser = getCurrentUser();

// Get session ID
$sessionId = intval($_GET['session_id'] ?? 0);
if (!$sessionId) {
    redirect('sessions.php', 'Session not found.', 'error');
}

// Initialize variables
$message = '';
$messageType = 'info';

// Load session data
try {
    $db = Database::getInstance();
    
    // Get session details
    $session = $db->fetchRow(
        "SELECT ts.*, u.first_name as instructor_first_name, u.last_name as instructor_last_name
         FROM training_sessions ts 
         LEFT JOIN users u ON u.user_id = ts.instructor_id 
         WHERE ts.session_id = :id",
        [':id' => $sessionId]
    );
    
    if (!$session) {
        redirect('sessions.php', 'Session not found.', 'error');
    }
    
} catch (Exception $e) {
    error_log("Attendance load error: " . $e->getMessage());
    redirect('sessions.php', 'Error loading session.', 'error');
}

// Handle attendance actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'mark_attendance':
                    $attendanceData = $_POST['attendance'] ?? [];
                    $notes = $_POST['notes'] ?? [];
                    
                    foreach ($attendanceData as $userId => $status) {
                        $userId = intval($userId);
                        $status = sanitizeInput($status);
                        $userNotes = sanitizeInput($notes[$userId] ?? '');
                        
                        if ($userId > 0 && in_array($status, ['registered', 'attended', 'missed', 'cancelled'])) {
                            // Check if attendance record exists
                            $existing = $db->fetchRow(
                                "SELECT attendance_id FROM session_attendance WHERE session_id = :session_id AND user_id = :user_id",
                                [':session_id' => $sessionId, ':user_id' => $userId]
                            );
                            
                            $attendanceRecord = [
                                'session_id' => $sessionId,
                                'user_id' => $userId,
                                'status' => $status,
                                'notes' => $userNotes
                            ];
                            
                            if ($existing) {
                                // Update existing record
                                if ($status === 'attended') {
                                    $attendanceRecord['attendance_date'] = date('Y-m-d H:i:s');
                                }
                                $db->update('session_attendance', $attendanceRecord,
                                           'attendance_id = :id',
                                           [':id' => $existing['attendance_id']]);
                            } else {
                                // Create new record
                                $attendanceRecord['registration_date'] = date('Y-m-d H:i:s');
                                if ($status === 'attended') {
                                    $attendanceRecord['attendance_date'] = date('Y-m-d H:i:s');
                                }
                                $db->insert('session_attendance', $attendanceRecord);
                            }
                        }
                    }
                    
                    $message = 'Attendance updated successfully.';
                    $messageType = 'success';
                    logActivity("Updated attendance for session: {$session['title']}", $currentUser['user_id']);
                    break;
                    
                case 'bulk_register':
                    $selectedUsers = $_POST['selected_users'] ?? [];
                    $registeredCount = 0;
                    
                    foreach ($selectedUsers as $userId) {
                        $userId = intval($userId);
                        if ($userId > 0) {
                            // Check if already registered
                            $existing = $db->fetchRow(
                                "SELECT attendance_id FROM session_attendance WHERE session_id = :session_id AND user_id = :user_id",
                                [':session_id' => $sessionId, ':user_id' => $userId]
                            );
                            
                            if (!$existing) {
                                $db->insert('session_attendance', [
                                    'session_id' => $sessionId,
                                    'user_id' => $userId,
                                    'status' => 'registered',
                                    'registration_date' => date('Y-m-d H:i:s')
                                ]);
                                $registeredCount++;
                            }
                        }
                    }
                    
                    $message = "Successfully registered $registeredCount participants.";
                    $messageType = 'success';
                    logActivity("Bulk registered $registeredCount users for session: {$session['title']}", $currentUser['user_id']);
                    break;
                    
                case 'add_participant':
                    $userId = intval($_POST['user_id'] ?? 0);
                    
                    if ($userId > 0) {
                        // Check if already registered
                        $existing = $db->fetchRow(
                            "SELECT attendance_id FROM session_attendance WHERE session_id = :session_id AND user_id = :user_id",
                            [':session_id' => $sessionId, ':user_id' => $userId]
                        );
                        
                        if (!$existing) {
                            $db->insert('session_attendance', [
                                'session_id' => $sessionId,
                                'user_id' => $userId,
                                'status' => 'registered',
                                'registration_date' => date('Y-m-d H:i:s')
                            ]);
                            
                            $message = 'Participant added successfully.';
                            $messageType = 'success';
                        } else {
                            $message = 'Participant is already registered for this session.';
                            $messageType = 'warning';
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            error_log("Attendance action error: " . $e->getMessage());
            $message = 'Error updating attendance. Please try again.';
            $messageType = 'error';
        }
    }
}

// Get attendance data
try {
    // Get all participants registered for this session
    $attendees = $db->fetchAll(
        "SELECT sa.*, u.first_name, u.last_name, u.email, u.role 
         FROM session_attendance sa 
         JOIN users u ON u.user_id = sa.user_id 
         WHERE sa.session_id = :session_id 
         ORDER BY u.first_name, u.last_name",
        [':session_id' => $sessionId]
    );
    
    // Get attendance statistics
    $stats = [
        'total_registered' => $db->count('session_attendance', 'session_id = :id', [':id' => $sessionId]),
        'attended' => $db->count('session_attendance', 'session_id = :id AND status = :status', [':id' => $sessionId, ':status' => 'attended']),
        'missed' => $db->count('session_attendance', 'session_id = :id AND status = :status', [':id' => $sessionId, ':status' => 'missed']),
        'cancelled' => $db->count('session_attendance', 'session_id = :id AND status = :status', [':id' => $sessionId, ':status' => 'cancelled'])
    ];
    
    $stats['attendance_rate'] = $stats['total_registered'] > 0 
        ? round(($stats['attended'] / $stats['total_registered']) * 100, 1) 
        : 0;
    
    // Get users not yet registered (for adding new participants)
    $registeredUserIds = array_column($attendees, 'user_id');
    $notRegisteredQuery = "SELECT user_id, first_name, last_name, role 
                          FROM users 
                          WHERE is_active = 1 
                          AND role IN ('participant', 'mentor', 'volunteer')";
    
    if (!empty($registeredUserIds)) {
        $notRegisteredQuery .= " AND user_id NOT IN (" . implode(',', $registeredUserIds) . ")";
    }
    
    $notRegisteredQuery .= " ORDER BY role, first_name, last_name";
    $availableUsers = $db->fetchAll($notRegisteredQuery);
    
} catch (Exception $e) {
    error_log("Attendance data error: " . $e->getMessage());
    $attendees = [];
    $stats = ['total_registered' => 0, 'attended' => 0, 'missed' => 0, 'cancelled' => 0, 'attendance_rate' => 0];
    $availableUsers = [];
}

$pageTitle = 'Attendance - ' . $session['title'];
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray-600);
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* Session Header */
        .session-header {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .session-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .session-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        
        .session-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .meta-icon {
            color: var(--primary-color);
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .stat-card.total::before {
            background: var(--primary-color);
        }
        
        .stat-card.attended::before {
            background: var(--success-color);
        }
        
        .stat-card.missed::before {
            background: var(--danger-color);
        }
        
        .stat-card.cancelled::before {
            background: var(--warning-color);
        }
        
        .stat-card.rate::before {
            background: linear-gradient(90deg, var(--accent-color), var(--secondary-color));
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .stat-icon.total {
            color: var(--primary-color);
        }
        
        .stat-icon.attended {
            color: var(--success-color);
        }
        
        .stat-icon.missed {
            color: var(--danger-color);
        }
        
        .stat-icon.cancelled {
            color: var(--warning-color);
        }
        
        .stat-icon.rate {
            color: var(--accent-color);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
        
        /* Content Sections */
        .content-section {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Attendance Table */
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .attendance-table th,
        .attendance-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .attendance-table th {
            background: var(--gray-100);
            font-weight: 600;
            color: var(--dark-color);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .attendance-table tbody tr:hover {
            background: var(--gray-100);
        }
        
        .participant-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .participant-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .participant-details h4 {
            margin: 0;
            color: var(--dark-color);
            font-size: 0.95rem;
        }
        
        .participant-details p {
            margin: 0;
            color: var(--gray-600);
            font-size: 0.8rem;
        }
        
        .status-select {
            padding: 0.5rem;
            border: 2px solid var(--gray-200);
            border-radius: 6px;
            font-size: 0.9rem;
            min-width: 120px;
            transition: all 0.3s ease;
        }
        
        .status-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .status-select.attended {
            background: rgba(0, 184, 148, 0.1);
            border-color: var(--success-color);
        }
        
        .status-select.missed {
            background: rgba(232, 67, 147, 0.1);
            border-color: var(--danger-color);
        }
        
        .status-select.cancelled {
            background: rgba(253, 203, 110, 0.1);
            border-color: var(--warning-color);
        }
        
        .notes-input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--gray-300);
            border-radius: 4px;
            font-size: 0.8rem;
            resize: vertical;
            min-height: 40px;
        }
        
        .notes-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-registered {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary-color);
        }
        
        .status-attended {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
        }
        
        .status-missed {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
        }
        
        .status-cancelled {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        /* Add Participant Form */
        .add-participant-form {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
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
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
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
        
        .alert-warning {
            background: rgba(253, 203, 110, 0.1);
            border-color: var(--warning-color);
            color: var(--warning-color);
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
        @media (max-width: 1024px) {
            .attendance-table {
                font-size: 0.9rem;
            }
            
            .attendance-table th,
            .attendance-table td {
                padding: 0.75rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .session-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .attendance-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .session-header {
                padding: 1.5rem;
            }
            
            .session-title {
                font-size: 1.5rem;
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
                <?php if (hasUserPermission('user_management')): ?>
                    <a href="../users/list.php" class="nav-link">
                        <i class="fas fa-users"></i> Users
                    </a>
                <?php endif; ?>
                <a href="../projects/list.php" class="nav-link">
                    <i class="fas fa-project-diagram"></i> Projects
                </a>
                <a href="../assignments/list.php" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="sessions.php" class="nav-link active">
                    <i class="fas fa-chalkboard-teacher"></i> Training
                </a>
                <a href="../../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="../../dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <a href="sessions.php">Training Sessions</a>
            <i class="fas fa-chevron-right"></i>
            <span>Attendance</span>
        </div>
        
        <!-- Session Header -->
        <div class="session-header">
            <h1 class="session-title"><?php echo htmlspecialchars($session['title']); ?></h1>
            
            <div class="session-meta">
                <div class="meta-item">
                    <i class="fas fa-calendar-alt meta-icon"></i>
                    <?php echo date('M j, Y \a\t g:i A', strtotime($session['session_date'])); ?>
                </div>
                <?php if ($session['instructor_first_name']): ?>
                    <div class="meta-item">
                        <i class="fas fa-user-tie meta-icon"></i>
                        <?php echo htmlspecialchars($session['instructor_first_name'] . ' ' . $session['instructor_last_name']); ?>
                    </div>
                <?php endif; ?>
                <?php if ($session['location']): ?>
                    <div class="meta-item">
                        <i class="fas fa-map-marker-alt meta-icon"></i>
                        <?php echo htmlspecialchars($session['location']); ?>
                    </div>
                <?php endif; ?>
                <div class="meta-item">
                    <i class="fas fa-clock meta-icon"></i>
                    <?php echo $session['duration_minutes']; ?> minutes
                </div>
                <div class="meta-item">
                    <i class="fas fa-info-circle meta-icon"></i>
                    Status: <span class="status-badge status-<?php echo $session['status']; ?>">
                        <?php echo ucfirst($session['status']); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_registered']; ?></div>
                <div class="stat-label">Registered</div>
            </div>
            
            <div class="stat-card attended">
                <div class="stat-icon attended">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo $stats['attended']; ?></div>
                <div class="stat-label">Attended</div>
            </div>
            
            <div class="stat-card missed">
                <div class="stat-icon missed">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-value"><?php echo $stats['missed']; ?></div>
                <div class="stat-label">Missed</div>
            </div>
            
            <div class="stat-card cancelled">
                <div class="stat-icon cancelled">
                    <i class="fas fa-user-minus"></i>
                </div>
                <div class="stat-value"><?php echo $stats['cancelled']; ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
            
            <div class="stat-card rate">
                <div class="stat-icon rate">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-value"><?php echo $stats['attendance_rate']; ?>%</div>
                <div class="stat-label">Attendance Rate</div>
            </div>
        </div>
        
        <!-- Add New Participant -->
        <?php if (!empty($availableUsers)): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-user-plus" style="color: var(--primary-color);"></i>
                        Add Participant
                    </h2>
                </div>
                
                <form method="POST" class="add-participant-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="add_participant">
                    
                    <div class="form-actions">
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <select name="user_id" class="form-control" required>
                                <option value="">Select participant to add...</option>
                                <?php 
                                $currentRole = '';
                                foreach ($availableUsers as $user): 
                                    if ($currentRole !== $user['role']) {
                                        if ($currentRole !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . ucfirst($user['role']) . 's">';
                                        $currentRole = $user['role'];
                                    }
                                ?>
                                    <option value="<?php echo $user['user_id']; ?>">
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($currentRole !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Participant
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- Attendance Management -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-clipboard-list" style="color: var(--primary-color);"></i>
                    Attendance Management
                </h2>
                <div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="markAllAttended()">
                        <i class="fas fa-check-double"></i> Mark All Attended
                    </button>
                </div>
            </div>
            
            <?php if (empty($attendees)): ?>
                <div style="text-align: center; padding: 3rem; color: var(--gray-600);">
                    <div style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <h3>No Participants Registered</h3>
                    <p>No one has registered for this training session yet.</p>
                </div>
            <?php else: ?>
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="mark_attendance">
                    
                    <div style="overflow-x: auto;">
                        <table class="attendance-table">
                            <thead>
                                <tr>
                                    <th>Participant</th>
                                    <th>Role</th>
                                    <th>Registration Date</th>
                                    <th>Attendance Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendees as $attendee): ?>
                                    <tr>
                                        <td>
                                            <div class="participant-info">
                                                <div class="participant-avatar">
                                                    <?php echo strtoupper(substr($attendee['first_name'], 0, 1)); ?>
                                                </div>
                                                <div class="participant-details">
                                                    <h4><?php echo htmlspecialchars($attendee['first_name'] . ' ' . $attendee['last_name']); ?></h4>
                                                    <p><?php echo htmlspecialchars($attendee['email']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-registered">
                                                <?php echo ucfirst($attendee['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($attendee['registration_date'])); ?>
                                        </td>
                                        <td>
                                            <select name="attendance[<?php echo $attendee['user_id']; ?>]" 
                                                    class="status-select <?php echo $attendee['status']; ?>"
                                                    onchange="updateStatusStyle(this)">
                                                <option value="registered" <?php echo $attendee['status'] === 'registered' ? 'selected' : ''; ?>>Registered</option>
                                                <option value="attended" <?php echo $attendee['status'] === 'attended' ? 'selected' : ''; ?>>Attended</option>
                                                <option value="missed" <?php echo $attendee['status'] === 'missed' ? 'selected' : ''; ?>>Missed</option>
                                                <option value="cancelled" <?php echo $attendee['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            </select>
                                        </td>
                                        <td>
                                            <textarea name="notes[<?php echo $attendee['user_id']; ?>]" 
                                                      class="notes-input" 
                                                      placeholder="Add notes..."><?php echo htmlspecialchars($attendee['notes'] ?? ''); ?></textarea>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 2rem; text-align: center;">
                        <button type="submit" class="btn btn-success" id="saveAttendanceBtn">
                            <i class="fas fa-save"></i> Save Attendance
                        </button>
                        <a href="sessions.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Sessions
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
        // Update status select styling based on selection
        function updateStatusStyle(select) {
            const value = select.value;
            select.className = 'status-select ' + value;
        }
        
        // Mark all participants as attended
        function markAllAttended() {
            if (confirm('Mark all registered participants as attended?')) {
                const selects = document.querySelectorAll('.status-select');
                selects.forEach(select => {
                    if (select.value === 'registered') {
                        select.value = 'attended';
                        updateStatusStyle(select);
                    }
                });
            }
        }
        
        // Form submission handling
        document.getElementById('attendanceForm').addEventListener('submit', function(e) {
            const saveBtn = document.getElementById('saveAttendanceBtn');
            
            // Add loading state
            saveBtn.classList.add('loading');
            saveBtn.disabled = true;
            
            // Re-enable after a delay if something goes wrong
            setTimeout(() => {
                saveBtn.classList.remove('loading');
                saveBtn.disabled = false;
            }, 10000);
        });
        
        // Auto-save attendance changes
        let autoSaveTimeout;
        document.querySelectorAll('.status-select, .notes-input').forEach(input => {
            input.addEventListener('change', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    // Could implement auto-save here
                    console.log('Auto-save triggered');
                }, 2000);
            });
        });
        
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Initialize status styling for all selects
        document.querySelectorAll('.status-select').forEach(select => {
            updateStatusStyle(select);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.getElementById('attendanceForm').submit();
            }
        });
        
        // Real-time statistics update
        function updateStatistics() {
            const selects = document.querySelectorAll('.status-select');
            let attended = 0, missed = 0, cancelled = 0, registered = 0;
            
            selects.forEach(select => {
                switch (select.value) {
                    case 'attended': attended++; break;
                    case 'missed': missed++; break;
                    case 'cancelled': cancelled++; break;
                    case 'registered': registered++; break;
                }
            });
            
            const total = selects.length;
            const attendanceRate = total > 0 ? Math.round((attended / total) * 100) : 0;
            
            // Update statistics in the UI (optional enhancement)
            const statValues = document.querySelectorAll('.stat-value');
            if (statValues.length >= 5) {
                statValues[1].textContent = attended;
                statValues[2].textContent = missed;
                statValues[3].textContent = cancelled;
                statValues[4].textContent = attendanceRate + '%';
            }
        }
        
        // Update statistics when attendance changes
        document.querySelectorAll('.status-select').forEach(select => {
            select.addEventListener('change', updateStatistics);
        });
        
        // Confirm before leaving if there are unsaved changes
        let hasUnsavedChanges = false;
        
        document.querySelectorAll('.status-select, .notes-input').forEach(input => {
            input.addEventListener('change', () => {
                hasUnsavedChanges = true;
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (hasUnsavedChanges && !document.getElementById('saveAttendanceBtn').classList.contains('loading')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Don't warn when submitting form
        document.getElementById('attendanceForm').addEventListener('submit', () => {
            hasUnsavedChanges = false;
        });
    </script>
</body>
</html> 
