 <?php
/**
 * Notification Center - Girls Leadership Program
 * Beautiful interface for managing user notifications
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

// Get parameters
$filter = sanitizeInput($_GET['filter'] ?? 'all');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Initialize variables
$message = '';
$messageType = 'info';

// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            $db = Database::getInstance();
            
            switch ($action) {
                case 'mark_read':
                    $notificationId = intval($_POST['notification_id'] ?? 0);
                    if ($notificationId > 0) {
                        $updated = $db->update('notifications', 
                                   ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 
                                   'notification_id = :id AND user_id = :user_id', 
                                   [':id' => $notificationId, ':user_id' => $currentUser['user_id']]);
                        
                        if ($updated) {
                            $message = 'Notification marked as read.';
                            $messageType = 'success';
                        }
                    }
                    break;
                    
                case 'mark_unread':
                    $notificationId = intval($_POST['notification_id'] ?? 0);
                    if ($notificationId > 0) {
                        $updated = $db->update('notifications', 
                                   ['is_read' => 0, 'read_at' => null], 
                                   'notification_id = :id AND user_id = :user_id', 
                                   [':id' => $notificationId, ':user_id' => $currentUser['user_id']]);
                        
                        if ($updated) {
                            $message = 'Notification marked as unread.';
                            $messageType = 'success';
                        }
                    }
                    break;
                    
                case 'mark_all_read':
                    $updated = $db->update('notifications', 
                               ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 
                               'user_id = :user_id AND is_read = 0', 
                               [':user_id' => $currentUser['user_id']]);
                    
                    if ($updated) {
                        $message = 'All notifications marked as read.';
                        $messageType = 'success';
                        logActivity("Marked all notifications as read", $currentUser['user_id']);
                    }
                    break;
                    
                case 'delete_notification':
                    $notificationId = intval($_POST['notification_id'] ?? 0);
                    if ($notificationId > 0) {
                        $deleted = $db->delete('notifications', 
                                      'notification_id = :id AND user_id = :user_id', 
                                      [':id' => $notificationId, ':user_id' => $currentUser['user_id']]);
                        
                        if ($deleted) {
                            $message = 'Notification deleted.';
                            $messageType = 'success';
                        }
                    }
                    break;
                    
                case 'delete_read':
                    $deleted = $db->delete('notifications', 
                              'user_id = :user_id AND is_read = 1', 
                              [':user_id' => $currentUser['user_id']]);
                    
                    if ($deleted) {
                        $message = 'All read notifications deleted.';
                        $messageType = 'success';
                        logActivity("Deleted all read notifications", $currentUser['user_id']);
                    }
                    break;
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
            error_log("Notification action error: " . $e->getMessage());
        }
    }
}

// Load notifications based on filter
try {
    $db = Database::getInstance();
    
    // Get notification counts for filters
    $notificationCounts = [
        'all' => $db->count('notifications', 'user_id = :user_id', [':user_id' => $currentUser['user_id']]),
        'unread' => $db->count('notifications', 'user_id = :user_id AND is_read = 0', [':user_id' => $currentUser['user_id']]),
        'info' => $db->count('notifications', 'user_id = :user_id AND type = :type', [':user_id' => $currentUser['user_id'], ':type' => 'info']),
        'success' => $db->count('notifications', 'user_id = :user_id AND type = :type', [':user_id' => $currentUser['user_id'], ':type' => 'success']),
        'warning' => $db->count('notifications', 'user_id = :user_id AND type = :type', [':user_id' => $currentUser['user_id'], ':type' => 'warning']),
        'error' => $db->count('notifications', 'user_id = :user_id AND type = :type', [':user_id' => $currentUser['user_id'], ':type' => 'error'])
    ];
    
    // Build filter conditions
    $whereConditions = ['user_id = :user_id'];
    $params = [':user_id' => $currentUser['user_id']];
    
    switch ($filter) {
        case 'unread':
            $whereConditions[] = 'is_read = 0';
            break;
        case 'info':
        case 'success':
        case 'warning':
        case 'error':
            $whereConditions[] = 'type = :type';
            $params[':type'] = $filter;
            break;
        // 'all' - no additional conditions
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Count total notifications for pagination
    $totalNotifications = $db->count('notifications', $whereClause, $params);
    $totalPages = ceil($totalNotifications / $perPage);
    
    // Get notifications for current page
    $notificationsQuery = "SELECT * FROM notifications 
                          WHERE $whereClause 
                          ORDER BY created_at DESC 
                          LIMIT $perPage OFFSET $offset";
    
    $notifications = $db->fetchAll($notificationsQuery, $params);
    
    // Get recent activity summary
    $recentStats = [
        'today_count' => $db->count('notifications', 'user_id = :user_id AND DATE(created_at) = CURDATE()', [':user_id' => $currentUser['user_id']]),
        'week_count' => $db->count('notifications', 'user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)', [':user_id' => $currentUser['user_id']]),
        'unread_count' => $notificationCounts['unread'],
        'last_read' => $db->fetchRow("SELECT read_at FROM notifications WHERE user_id = :user_id AND read_at IS NOT NULL ORDER BY read_at DESC LIMIT 1", [':user_id' => $currentUser['user_id']])
    ];
    
} catch (Exception $e) {
    error_log("Notifications error: " . $e->getMessage());
    $notifications = [];
    $totalNotifications = 0;
    $totalPages = 0;
    $notificationCounts = ['all' => 0, 'unread' => 0, 'info' => 0, 'success' => 0, 'warning' => 0, 'error' => 0];
    $recentStats = ['today_count' => 0, 'week_count' => 0, 'unread_count' => 0, 'last_read' => null];
}

// Helper function to get notification icon
function getNotificationIcon($type) {
    $icons = [
        'info' => 'fas fa-info-circle',
        'success' => 'fas fa-check-circle',
        'warning' => 'fas fa-exclamation-triangle',
        'error' => 'fas fa-times-circle'
    ];
    return $icons[$type] ?? 'fas fa-bell';
}

// Helper function to format relative time
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    
    return date('M j, Y', strtotime($datetime));
}

$pageTitle = 'Notifications';
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
        
        /* Page Header */
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
            flex-wrap: wrap;
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
        
        /* Notification Layout */
        .notifications-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
        }
        
        /* Sidebar Filters */
        .notifications-sidebar {
            background: var(--white);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            height: fit-content;
        }
        
        .sidebar-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }
        
        .filter-nav {
            list-style: none;
        }
        
        .filter-nav li {
            margin-bottom: 0.5rem;
        }
        
        .filter-nav a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            color: var(--dark-color);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .filter-nav a:hover {
            background: var(--gray-100);
        }
        
        .filter-nav a.active {
            background: var(--primary-color);
            color: var(--white);
        }
        
        .filter-icon {
            margin-right: 0.5rem;
        }
        
        .filter-badge {
            background: var(--gray-300);
            color: var(--dark-color);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .filter-nav a.active .filter-badge {
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
        }
        
        .sidebar-actions {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }
        
        /* Main Notifications Area */
        .notifications-main {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .main-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .main-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
        }
        
        .bulk-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        /* Notifications List */
        .notifications-list {
            display: flex;
            flex-direction: column;
        }
        
        .notification-item {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .notification-item:hover {
            background: var(--gray-100);
        }
        
        .notification-item.unread {
            background: rgba(108, 92, 231, 0.05);
            border-left: 4px solid var(--primary-color);
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-content {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        
        .notification-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-top: 0.2rem;
        }
        
        .notification-icon.info {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary-color);
        }
        
        .notification-icon.success {
            background: rgba(0, 184, 148, 0.1);
            color: var(--success-color);
        }
        
        .notification-icon.warning {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .notification-icon.error {
            background: rgba(232, 67, 147, 0.1);
            color: var(--danger-color);
        }
        
        .notification-body {
            flex: 1;
            min-width: 0;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
            gap: 1rem;
        }
        
        .notification-title {
            font-weight: 700;
            color: var(--dark-color);
            font-size: 0.95rem;
            line-height: 1.3;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: var(--gray-600);
            flex-shrink: 0;
        }
        
        .notification-message {
            color: var(--gray-600);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 0.75rem;
        }
        
        .notification-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .notification-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .notification-link:hover {
            background: var(--primary-color);
            color: var(--white);
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
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .notifications-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .notifications-sidebar {
                order: 2;
                background: var(--gray-100);
                padding: 1rem;
            }
            
            .filter-nav {
                display: flex;
                gap: 0.5rem;
                flex-wrap: wrap;
            }
            
            .filter-nav li {
                margin-bottom: 0;
            }
            
            .sidebar-actions {
                margin-top: 1rem;
                padding-top: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .notification-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .notification-actions {
                margin-top: 0.5rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .bulk-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .notification-content {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .notification-icon {
                align-self: flex-start;
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
                <a href="../training/sessions.php" class="nav-link">
                    <i class="fas fa-chalkboard-teacher"></i> Training
                </a>
                <a href="messages.php" class="nav-link">
                    <i class="fas fa-envelope"></i> Messages
                </a>
                <a href="notifications.php" class="nav-link active">
                    <i class="fas fa-bell"></i> Notifications
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
            <a href="notifications.php">Notifications</a>
            <?php if ($filter !== 'all'): ?>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo ucfirst($filter); ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Notification Center</h1>
                <p class="page-subtitle">Stay updated with important information and activities</p>
            </div>
            <div class="header-actions">
                <a href="messages.php" class="btn btn-outline">
                    <i class="fas fa-envelope"></i> Messages
                </a>
                <a href="../../dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stat-value"><?php echo $recentStats['today_count']; ?></div>
                <div class="stat-label">Today</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="stat-value"><?php echo $recentStats['week_count']; ?></div>
                <div class="stat-label">This Week</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="stat-value"><?php echo $recentStats['unread_count']; ?></div>
                <div class="stat-label">Unread</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $notificationCounts['all']; ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        
        <!-- Notifications Layout -->
        <div class="notifications-layout">
            <!-- Sidebar Filters -->
            <div class="notifications-sidebar">
                <h2 class="sidebar-title">Filter Notifications</h2>
                
                <ul class="filter-nav">
                    <li>
                        <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-list filter-icon"></i>
                                All Notifications
                            </span>
                            <span class="filter-badge"><?php echo $notificationCounts['all']; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="?filter=unread" class="<?php echo $filter === 'unread' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-exclamation-circle filter-icon"></i>
                                Unread
                            </span>
                            <span class="filter-badge"><?php echo $notificationCounts['unread']; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="?filter=info" class="<?php echo $filter === 'info' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-info-circle filter-icon"></i>
                                Information
                            </span>
                            <span class="filter-badge"><?php echo $notificationCounts['info']; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="?filter=success" class="<?php echo $filter === 'success' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-check-circle filter-icon"></i>
                                Success
                            </span>
                            <span class="filter-badge"><?php echo $notificationCounts['success']; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="?filter=warning" class="<?php echo $filter === 'warning' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-exclamation-triangle filter-icon"></i>
                                Warnings
                            </span>
                            <span class="filter-badge"><?php echo $notificationCounts['warning']; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="?filter=error" class="<?php echo $filter === 'error' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-times-circle filter-icon"></i>
                                Errors
                            </span>
                            <span class="filter-badge"><?php echo $notificationCounts['error']; ?></span>
                        </a>
                    </li>
                </ul>
                
                <div class="sidebar-actions">
                    <?php if ($notificationCounts['unread'] > 0): ?>
                        <form method="POST" style="margin-bottom: 1rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-primary btn-sm" style="width: 100%;">
                                <i class="fas fa-check-double"></i> Mark All Read
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <form method="POST" onsubmit="return confirm('Delete all read notifications? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="delete_read">
                        <button type="submit" class="btn btn-outline btn-sm" style="width: 100%;">
                            <i class="fas fa-trash"></i> Clear Read
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Main Notifications -->
            <div class="notifications-main">
                <div class="main-header">
                    <h2 class="main-title">
                        <?php echo ucfirst($filter); ?> Notifications
                        <span style="font-size: 0.9rem; color: var(--gray-600); font-weight: 400;">
                            (<?php echo number_format($totalNotifications); ?>)
                        </span>
                    </h2>
                    <div class="bulk-actions">
                        <?php if ($totalPages > 1): ?>
                            <span style="font-size: 0.9rem; color: var(--gray-600);">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-bell-slash"></i>
                        </div>
                        <h3 class="empty-title">No notifications found</h3>
                        <p>
                            <?php if ($filter === 'unread'): ?>
                                All caught up! You have no unread notifications.
                            <?php elseif ($filter === 'all'): ?>
                                You don't have any notifications yet. They'll appear here when you receive them.
                            <?php else: ?>
                                No <?php echo $filter; ?> notifications found.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="notifications-list">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item <?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
                                <div class="notification-content">
                                    <div class="notification-icon <?php echo $notification['type']; ?>">
                                        <i class="<?php echo getNotificationIcon($notification['type']); ?>"></i>
                                    </div>
                                    
                                    <div class="notification-body">
                                        <div class="notification-header">
                                            <h4 class="notification-title">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </h4>
                                            <span class="notification-time">
                                                <?php echo timeAgo($notification['created_at']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="notification-message">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </div>
                                        
                                        <div class="notification-actions">
                                            <?php if (!empty($notification['action_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" class="notification-link">
                                                    <i class="fas fa-external-link-alt"></i> View Details
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if (!$notification['is_read']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                                    <button type="submit" class="btn-icon btn-success" title="Mark as Read">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="mark_unread">
                                                    <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                                    <button type="submit" class="btn-icon btn-warning" title="Mark as Unread">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Delete this notification?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="action" value="delete_notification">
                                                <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                                <button type="submit" class="btn-icon btn-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?filter=<?php echo $filter; ?>&page=1">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>">
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
                                    <a href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?filter=<?php echo $filter; ?>&page=<?php echo $totalPages; ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <script>
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Add loading states to form buttons
        document.querySelectorAll('form button[type="submit"]').forEach(button => {
            button.addEventListener('click', function() {
                this.style.opacity = '0.6';
                this.disabled = true;
                
                // Re-enable after 5 seconds in case something goes wrong
                setTimeout(() => {
                    this.style.opacity = '1';
                    this.disabled = false;
                }, 5000);
            });
        });
        
        // Add hover effects to notification items
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(5px)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // 'a' to mark all as read (when not in input field)
            if (e.key === 'a' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                const markAllForm = document.querySelector('input[value="mark_all_read"]');
                if (markAllForm) {
                    markAllForm.closest('form').submit();
                }
            }
            
            // 'u' to show unread
            if (e.key === 'u' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                window.location.href = '?filter=unread';
            }
        });
        
        // Auto-refresh notification counts every 30 seconds
        setInterval(function() {
            // Only refresh if user is still on the page
            if (document.hasFocus()) {
                fetch('notifications.php?ajax=counts')
                    .then(response => response.json())
                    .then(data => {
                        // Update notification badges in navigation if they exist
                        const navBadge = document.querySelector('.nav-link .badge');
                        if (navBadge && data.unread > 0) {
                            navBadge.textContent = data.unread;
                            navBadge.style.display = 'inline';
                        } else if (navBadge) {
                            navBadge.style.display = 'none';
                        }
                    })
                    .catch(error => console.log('Notification count update failed:', error));
            }
        }, 30000);
        
        // Mark notification as read when action link is clicked
        document.querySelectorAll('.notification-link').forEach(link => {
            link.addEventListener('click', function() {
                const notificationItem = this.closest('.notification-item');
                if (notificationItem && notificationItem.classList.contains('unread')) {
                    const markReadForm = notificationItem.querySelector('input[value="mark_read"]');
                    if (markReadForm) {
                        // Submit form in background
                        fetch(window.location.href, {
                            method: 'POST',
                            body: new FormData(markReadForm.closest('form'))
                        });
                        
                        // Update UI immediately
                        notificationItem.classList.remove('unread');
                    }
                }
            });
        });
    </script>
</body>
</html>
