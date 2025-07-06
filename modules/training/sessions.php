 <?php
/**
 * Training Session Management - Girls Leadership Program
 * Beautiful interface for managing training sessions and workshops
 */

// Define APP_ROOT and load configuration
define('APP_ROOT', dirname(dirname(dirname(__FILE__))));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/auth.php';

// Require login
requireLogin();

// Get current user
$currentUser = getCurrentUser();
$userRole = $currentUser['role'];

// Check permissions
$canManageSessions = hasUserPermission('training_management') || hasUserPermission('user_management');
$canCreateSessions = hasUserPermission('training_management');

// Handle session actions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageSessions) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $sessionId = intval($_POST['session_id'] ?? 0);
        
        if ($sessionId > 0) {
            $db = Database::getInstance();
            
            switch ($action) {
                case 'start_session':
                    $db->update('training_sessions', ['status' => 'ongoing'], 'session_id = :id', [':id' => $sessionId]);
                    $message = 'Training session started successfully.';
                    $messageType = 'success';
                    logActivity("Training session ID $sessionId started", $currentUser['user_id']);
                    break;
                    
                case 'complete_session':
                    $db->update('training_sessions', ['status' => 'completed'], 'session_id = :id', [':id' => $sessionId]);
                    $message = 'Training session marked as completed.';
                    $messageType = 'success';
                    logActivity("Training session ID $sessionId completed", $currentUser['user_id']);
                    break;
                    
                case 'cancel_session':
                    $db->update('training_sessions', ['status' => 'cancelled'], 'session_id = :id', [':id' => $sessionId]);
                    $message = 'Training session cancelled.';
                    $messageType = 'success';
                    logActivity("Training session ID $sessionId cancelled", $currentUser['user_id']);
                    break;
                    
                case 'register_attendance':
                    // Check if already registered
                    $existing = $db->fetchRow(
                        "SELECT * FROM session_attendance WHERE session_id = :session_id AND user_id = :user_id",
                        [':session_id' => $sessionId, ':user_id' => $currentUser['user_id']]
                    );
                    
                    if (!$existing) {
                        $db->insert('session_attendance', [
                            'session_id' => $sessionId,
                            'user_id' => $currentUser['user_id'],
                            'status' => 'registered',
                            'registration_date' => date('Y-m-d H:i:s')
                        ]);
                        $message = 'Successfully registered for the training session!';
                        $messageType = 'success';
                        logActivity("Registered for training session ID $sessionId", $currentUser['user_id']);
                    } else {
                        $message = 'You are already registered for this session.';
                        $messageType = 'warning';
                    }
                    break;
                    
                case 'unregister_attendance':
                    $db->delete(
                        'session_attendance',
                        'session_id = :session_id AND user_id = :user_id',
                        [':session_id' => $sessionId, ':user_id' => $currentUser['user_id']]
                    );
                    $message = 'Successfully unregistered from the training session.';
                    $messageType = 'success';
                    logActivity("Unregistered from training session ID $sessionId", $currentUser['user_id']);
                    break;
            }
        }
    }
}

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? '');
$dateFilter = sanitizeInput($_GET['date_filter'] ?? '');
$instructorFilter = intval($_GET['instructor_id'] ?? 0);
$mySessionsOnly = isset($_GET['my_sessions']);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Build query conditions
$whereConditions = ['1 = 1'];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(ts.title LIKE :search OR ts.description LIKE :search OR ts.location LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($statusFilter)) {
    $whereConditions[] = "ts.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($dateFilter)) {
    switch ($dateFilter) {
        case 'today':
            $whereConditions[] = "DATE(ts.session_date) = CURDATE()";
            break;
        case 'this_week':
            $whereConditions[] = "YEARWEEK(ts.session_date) = YEARWEEK(NOW())";
            break;
        case 'this_month':
            $whereConditions[] = "YEAR(ts.session_date) = YEAR(NOW()) AND MONTH(ts.session_date) = MONTH(NOW())";
            break;
        case 'upcoming':
            $whereConditions[] = "ts.session_date >= NOW()";
            break;
        case 'past':
            $whereConditions[] = "ts.session_date < NOW()";
            break;
    }
}

if ($instructorFilter > 0) {
    $whereConditions[] = "ts.instructor_id = :instructor_id";
    $params[':instructor_id'] = $instructorFilter;
}

// Filter for user's sessions (if participant)
if ($mySessionsOnly || (!$canManageSessions && $userRole === 'participant')) {
    $whereConditions[] = "sa.user_id = :user_id";
    $params[':user_id'] = $currentUser['user_id'];
}

$whereClause = implode(' AND ', $whereConditions);

// Get training sessions with pagination
try {
    $db = Database::getInstance();
    
    $joinClause = ($mySessionsOnly || (!$canManageSessions && $userRole === 'participant')) 
        ? "INNER JOIN session_attendance sa ON sa.session_id = ts.session_id"
        : "LEFT JOIN session_attendance sa ON sa.session_id = ts.session_id";
    
    // Count total sessions
    $countQuery = "SELECT COUNT(DISTINCT ts.session_id) as count 
                   FROM training_sessions ts 
                   $joinClause
                   LEFT JOIN users u ON u.user_id = ts.instructor_id 
                   WHERE $whereClause";
    
    $totalSessions = $db->fetchRow($countQuery, $params)['count'];
    
    // Get sessions for current page
    $sessionsQuery = "SELECT DISTINCT ts.*, 
                             u.first_name as instructor_first_name, 
                             u.last_name as instructor_last_name,
                             (SELECT COUNT(*) FROM session_attendance sa2 WHERE sa2.session_id = ts.session_id AND sa2.status IN ('registered', 'attended')) as registered_count,
                             (SELECT COUNT(*) FROM session_attendance sa3 WHERE sa3.session_id = ts.session_id AND sa3.status = 'attended') as attended_count
                      FROM training_sessions ts 
                      $joinClause
                      LEFT JOIN users u ON u.user_id = ts.instructor_id 
                      WHERE $whereClause 
                      ORDER BY ts.session_date ASC 
                      LIMIT $perPage OFFSET $offset";
    
    $sessions = $db->fetchAll($sessionsQuery, $params);
    
    $totalPages = ceil($totalSessions / $perPage);
    
    // Get session statistics
    $stats = [
        'total_sessions' => $db->count('training_sessions'),
        'scheduled_sessions' => $db->count('training_sessions', 'status = :status', [':status' => 'scheduled']),
        'ongoing_sessions' => $db->count('training_sessions', 'status = :status', [':status' => 'ongoing']),
        'completed_sessions' => $db->count('training_sessions', 'status = :status', [':status' => 'completed']),
        'my_registrations' => $db->count('session_attendance', 'user_id = :user_id', [':user_id' => $currentUser['user_id']])
    ];
    
    // Get instructors for filter dropdown
    $instructors = $db->fetchAll(
        "SELECT DISTINCT u.user_id, u.first_name, u.last_name 
         FROM users u 
         INNER JOIN training_sessions ts ON ts.instructor_id = u.user_id 
         ORDER BY u.first_name, u.last_name"
    );
    
    // Get user registration status for each session
    $userRegistrations = [];
    if (!empty($sessions)) {
        $sessionIds = array_column($sessions, 'session_id');
        $registrationQuery = "SELECT session_id FROM session_attendance 
                             WHERE user_id = :user_id AND session_id IN (" . implode(',', $sessionIds) . ")";
        $registrations = $db->fetchAll($registrationQuery, [':user_id' => $currentUser['user_id']]);
        $userRegistrations = array_column($registrations, 'session_id');
    }
    
} catch (Exception $e) {
    error_log("Training sessions error: " . $e->getMessage());
    $sessions = [];
    $totalSessions = 0;
    $totalPages = 0;
    $stats = ['total_sessions' => 0, 'scheduled_sessions' => 0, 'ongoing_sessions' => 0, 'completed_sessions' => 0, 'my_registrations' => 0];
    $instructors = [];
    $userRegistrations = [];
}

$pageTitle = 'Training Sessions';
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
            max-width: 1400px;
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
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-value {
            font-size: 2.5rem;
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
        
        /* Filters and Search */
        .filters-section {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            gap: 1rem;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark-color);
        }
        
        .form-control {
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
        
        .checkbox-filter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-filter input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }
        
        /* Sessions Grid */
        .sessions-section {
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .section-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .sessions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }
        
        .session-card {
            background: var(--white);
            border-radius: 12px;
            border: 2px solid var(--gray-200);
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .session-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--primary-color);
        }
        
        .session-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary-color);
        }
        
        .session-card.status-ongoing::before {
            background: var(--warning-color);
        }
        
        .session-card.status-completed::before {
            background: var(--success-color);
        }
        
        .session-card.status-cancelled::before {
            background: var(--danger-color);
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .session-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        
        .session-datetime {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .session-datetime.past {
            color: var(--gray-600);
        }
        
        .session-datetime.today {
            color: var(--warning-color);
        }
        
        .session-description {
            color: var(--gray-600);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .session-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .session-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
            margin-bottom: 1rem;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        .stat-text {
            font-size: 0.7rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .session-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
        
        .status-scheduled {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary-color);
        }
        
        .status-ongoing {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .status-completed {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
        }
        
        .status-cancelled {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
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
        
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-success {
            background: var(--success-color);
            color: var(--white);
        }
        
        .btn-warning {
            background: var(--warning-color);
            color: var(--dark-color);
        }
        
        .btn-danger {
            background: var(--danger-color);
            color: var(--white);
        }
        
        /* Registration Status */
        .registration-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.5rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        
        .registration-status.registered {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
        }
        
        .registration-status.not-registered {
            background: var(--gray-100);
            color: var(--gray-600);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            background: var(--gray-100);
        }
        
        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination a {
            color: var(--primary-color);
        }
        
        .pagination a:hover {
            background: var(--primary-color);
            color: var(--white);
        }
        
        .pagination .current {
            background: var(--primary-color);
            color: var(--white);
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
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-600);
        }
        
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }
        
        .empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark-color);
        }
        
        .empty-description {
            margin-bottom: 2rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
            }
            
            .sessions-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                text-align: center;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .sessions-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .session-actions {
                flex-direction: column;
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
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Training Sessions</h1>
                <p class="page-subtitle">Leadership workshops and skill development sessions</p>
            </div>
            <div class="header-actions">
                <?php if ($canCreateSessions): ?>
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Session
                    </a>
                <?php endif; ?>
                <a href="calendar.php" class="btn btn-outline">
                    <i class="fas fa-calendar"></i> Calendar
                </a>
                <a href="../../dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
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
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_sessions']; ?></div>
                <div class="stat-label">Total Sessions</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-value"><?php echo $stats['scheduled_sessions']; ?></div>
                <div class="stat-label">Scheduled</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-play-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['ongoing_sessions']; ?></div>
                <div class="stat-label">Ongoing</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['completed_sessions']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo $stats['my_registrations']; ?></div>
                <div class="stat-label">My Registrations</div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="search">Search Sessions</label>
                        <input type="text" id="search" name="search" class="form-control" 
                               placeholder="Search by title, description, or location..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="scheduled" <?php echo $statusFilter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="ongoing" <?php echo $statusFilter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date_filter">Time Period</label>
                        <select id="date_filter" name="date_filter" class="form-control">
                            <option value="">All Time</option>
                            <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="this_week" <?php echo $dateFilter === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="this_month" <?php echo $dateFilter === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="upcoming" <?php echo $dateFilter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                            <option value="past" <?php echo $dateFilter === 'past' ? 'selected' : ''; ?>>Past</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="instructor_id">Instructor</label>
                        <select id="instructor_id" name="instructor_id" class="form-control">
                            <option value="">All Instructors</option>
                            <?php foreach ($instructors as $instructor): ?>
                                <option value="<?php echo $instructor['user_id']; ?>" 
                                        <?php echo $instructorFilter === $instructor['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="checkbox-filter">
                            <input type="checkbox" id="my_sessions" name="my_sessions" 
                                   <?php echo $mySessionsOnly ? 'checked' : ''; ?>>
                            <label for="my_sessions">My Sessions</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Sessions Section -->
        <div class="sessions-section">
            <div class="section-header">
                <h2 class="section-title">
                    Training Sessions (<?php echo number_format($totalSessions); ?>)
                </h2>
                <div>
                    <?php if ($totalPages > 1): ?>
                        <span style="font-size: 0.9rem; color: var(--gray-600);">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($sessions)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <h3 class="empty-title">No training sessions found</h3>
                    <p class="empty-description">
                        <?php if ($mySessionsOnly): ?>
                            You haven't registered for any training sessions yet. Browse available sessions to get started.
                        <?php else: ?>
                            No training sessions match your current filters. Try adjusting your search criteria or create the first session.
                        <?php endif; ?>
                    </p>
                    <?php if ($canCreateSessions): ?>
                        <a href="create.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create First Session
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="sessions-grid">
                    <?php foreach ($sessions as $session): 
                        $sessionDate = new DateTime($session['session_date']);
                        $now = new DateTime();
                        $isToday = $sessionDate->format('Y-m-d') === $now->format('Y-m-d');
                        $isPast = $sessionDate < $now;
                        $isRegistered = in_array($session['session_id'], $userRegistrations);
                    ?>
                        <div class="session-card status-<?php echo $session['status']; ?>">
                            <div class="session-header">
                                <div>
                                    <span class="status-badge status-<?php echo $session['status']; ?>">
                                        <?php echo ucfirst($session['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <h3 class="session-title">
                                <?php echo htmlspecialchars($session['title']); ?>
                            </h3>
                            
                            <div class="session-datetime <?php echo $isPast ? 'past' : ($isToday ? 'today' : ''); ?>">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo $sessionDate->format('M j, Y \a\t g:i A'); ?>
                            </div>
                            
                            <?php if ($isRegistered): ?>
                                <div class="registration-status registered">
                                    <i class="fas fa-check-circle"></i>
                                    You are registered for this session
                                </div>
                            <?php endif; ?>
                            
                            <p class="session-description">
                                <?php echo htmlspecialchars($session['description'] ?? 'No description provided.'); ?>
                            </p>
                            
                            <div class="session-meta">
                                <?php if ($session['instructor_first_name']): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-user-tie"></i>
                                        <?php echo htmlspecialchars($session['instructor_first_name'] . ' ' . $session['instructor_last_name']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($session['location']): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?php echo htmlspecialchars($session['location']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="meta-item">
                                    <i class="fas fa-clock"></i>
                                    <?php echo $session['duration_minutes']; ?> minutes
                                </div>
                                <?php if ($session['max_participants']): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-users"></i>
                                        Max: <?php echo $session['max_participants']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="session-stats">
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $session['registered_count']; ?></div>
                                    <div class="stat-text">Registered</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $session['attended_count']; ?></div>
                                    <div class="stat-text">Attended</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number">
                                        <?php 
                                        $availability = 'Open';
                                        if ($session['max_participants']) {
                                            $remaining = $session['max_participants'] - $session['registered_count'];
                                            $availability = max(0, $remaining);
                                        }
                                        echo $availability;
                                        ?>
                                    </div>
                                    <div class="stat-text">Available</div>
                                </div>
                            </div>
                            
                            <div class="session-actions">
                                <!-- View/Edit Session -->
                                <?php if ($canManageSessions): ?>
                                    <a href="create.php?id=<?php echo $session['session_id']; ?>" class="btn btn-outline btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    
                                    <a href="attendance.php?session_id=<?php echo $session['session_id']; ?>" class="btn btn-outline btn-sm">
                                        <i class="fas fa-clipboard-list"></i> Attendance
                                    </a>
                                    
                                    <!-- Session Management Actions -->
                                    <?php if ($session['status'] === 'scheduled' && !$isPast): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="start_session">
                                            <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                            <button type="submit" class="btn-icon btn-warning" title="Start Session">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($session['status'] === 'ongoing'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="complete_session">
                                            <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                            <button type="submit" class="btn-icon btn-success" title="Complete Session">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($session['status'], ['scheduled', 'ongoing']) && !$isPast): ?>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to cancel this session?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="cancel_session">
                                            <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                            <button type="submit" class="btn-icon btn-danger" title="Cancel Session">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Registration Actions for Participants -->
                                <?php if (!$canManageSessions && $session['status'] === 'scheduled' && !$isPast): ?>
                                    <?php if (!$isRegistered): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="register_attendance">
                                            <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-user-plus"></i> Register
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to unregister from this session?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="unregister_attendance">
                                            <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                            <button type="submit" class="btn btn-outline btn-sm">
                                                <i class="fas fa-user-minus"></i> Unregister
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($dateFilter) ? '&date_filter=' . urlencode($dateFilter) : ''; ?><?php echo $instructorFilter ? '&instructor_id=' . $instructorFilter : ''; ?><?php echo $mySessionsOnly ? '&my_sessions=1' : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($dateFilter) ? '&date_filter=' . urlencode($dateFilter) : ''; ?><?php echo $instructorFilter ? '&instructor_id=' . $instructorFilter : ''; ?><?php echo $mySessionsOnly ? '&my_sessions=1' : ''; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <?php if ($i === $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($dateFilter) ? '&date_filter=' . urlencode($dateFilter) : ''; ?><?php echo $instructorFilter ? '&instructor_id=' . $instructorFilter : ''; ?><?php echo $mySessionsOnly ? '&my_sessions=1' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($dateFilter) ? '&date_filter=' . urlencode($dateFilter) : ''; ?><?php echo $instructorFilter ? '&instructor_id=' . $instructorFilter : ''; ?><?php echo $mySessionsOnly ? '&my_sessions=1' : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($dateFilter) ? '&date_filter=' . urlencode($dateFilter) : ''; ?><?php echo $instructorFilter ? '&instructor_id=' . $instructorFilter : ''; ?><?php echo $mySessionsOnly ? '&my_sessions=1' : ''; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
        // Auto-submit search form on input change with debounce
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
        
        // Auto-submit form on filter change
        ['status', 'date_filter', 'instructor_id', 'my_sessions'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', function() {
                    this.form.submit();
                });
            }
        });
        
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Add loading states to action buttons
        document.querySelectorAll('form button').forEach(button => {
            button.addEventListener('click', function() {
                this.style.opacity = '0.6';
                this.disabled = true;
            });
        });
        
        // Add hover effects to session cards
        document.querySelectorAll('.session-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Real-time session status updates (optional enhancement)
        function updateSessionTimes() {
            const sessionDateElements = document.querySelectorAll('.session-datetime');
            const now = new Date();
            
            sessionDateElements.forEach(element => {
                const sessionDate = new Date(element.textContent.replace(/.*(\d{1,2}\/\d{1,2}\/\d{4} at \d{1,2}:\d{2} [AP]M).*/, '$1'));
                
                if (sessionDate.toDateString() === now.toDateString()) {
                    element.classList.add('today');
                    element.classList.remove('past');
                } else if (sessionDate < now) {
                    element.classList.add('past');
                    element.classList.remove('today');
                } else {
                    element.classList.remove('past', 'today');
                }
            });
        }
        
        // Update session times every minute
        updateSessionTimes();
        setInterval(updateSessionTimes, 60000);
    </script>
</body>
</html>
