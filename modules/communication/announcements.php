 <?php
/**
 * System Announcements - Girls Leadership Program
 * Beautiful interface for managing system-wide announcements
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

// Check if user can create announcements (admin only)
$canCreateAnnouncements = hasUserPermission('user_management');

// Get parameters
$view = sanitizeInput($_GET['view'] ?? 'list');
$announcementId = intval($_GET['id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Initialize variables
$message = '';
$messageType = 'info';
$announcement = null;

// Handle announcement actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            $db = Database::getInstance();
            
            switch ($action) {
                case 'create_announcement':
                    if (!$canCreateAnnouncements) {
                        throw new Exception('Access denied. Only administrators can create announcements.');
                    }
                    
                    $title = sanitizeInput($_POST['title'] ?? '');
                    $content = sanitizeInput($_POST['content'] ?? '');
                    $priority = sanitizeInput($_POST['priority'] ?? 'medium');
                    
                    // Enhanced validation
                    if (empty($title)) {
                        throw new Exception('Announcement title is required.');
                    }
                    if (strlen($title) < 5) {
                        throw new Exception('Title must be at least 5 characters long.');
                    }
                    if (strlen($title) > 200) {
                        throw new Exception('Title must not exceed 200 characters.');
                    }
                    if (empty($content)) {
                        throw new Exception('Announcement content is required.');
                    }
                    if (strlen($content) < 20) {
                        throw new Exception('Content must be at least 20 characters long.');
                    }
                    if (strlen($content) > 5000) {
                        throw new Exception('Content must not exceed 5000 characters.');
                    }
                    
                    $validPriorities = ['low', 'medium', 'high', 'urgent'];
                    if (!in_array($priority, $validPriorities)) {
                        throw new Exception('Please select a valid priority level.');
                    }
                    
                    // Create announcement using messages table
                    $announcementData = [
                        'sender_id' => $currentUser['user_id'],
                        'receiver_id' => null, // System-wide announcement
                        'subject' => $title,
                        'message_text' => $content,
                        'message_type' => 'announcement',
                        'sent_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $newAnnouncementId = $db->insert('messages', $announcementData);
                    
                    // Create notifications for all active users
                    $activeUsers = $db->fetchAll("SELECT user_id FROM users WHERE is_active = 1 AND user_id != :admin_id", [':admin_id' => $currentUser['user_id']]);
                    
                    $notificationType = ($priority === 'urgent') ? 'warning' : (($priority === 'high') ? 'info' : 'success');
                    
                    foreach ($activeUsers as $user) {
                        $db->insert('notifications', [
                            'user_id' => $user['user_id'],
                            'title' => 'New Announcement',
                            'message' => "📢 {$title}",
                            'type' => $notificationType,
                            'action_url' => "modules/communication/announcements.php?view=details&id={$newAnnouncementId}",
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                    
                    $message = 'Announcement created and sent to all users successfully!';
                    $messageType = 'success';
                    logActivity("Created announcement: {$title}", $currentUser['user_id']);
                    
                    // Redirect to view the announcement
                    redirect("announcements.php?view=details&id={$newAnnouncementId}", $message, $messageType);
                    break;
                    
                case 'edit_announcement':
                    if (!$canCreateAnnouncements) {
                        throw new Exception('Access denied.');
                    }
                    
                    $editId = intval($_POST['announcement_id'] ?? 0);
                    $title = sanitizeInput($_POST['title'] ?? '');
                    $content = sanitizeInput($_POST['content'] ?? '');
                    
                    if ($editId <= 0) {
                        throw new Exception('Invalid announcement ID.');
                    }
                    
                    // Validation (same as create)
                    if (empty($title) || strlen($title) < 5 || strlen($title) > 200) {
                        throw new Exception('Title must be between 5 and 200 characters.');
                    }
                    if (empty($content) || strlen($content) < 20 || strlen($content) > 5000) {
                        throw new Exception('Content must be between 20 and 5000 characters.');
                    }
                    
                    // Update announcement
                    $updated = $db->update('messages', 
                                          ['subject' => $title, 'message_text' => $content], 
                                          'message_id = :id AND sender_id = :user_id AND message_type = :type', 
                                          [':id' => $editId, ':user_id' => $currentUser['user_id'], ':type' => 'announcement']);
                    
                    if ($updated) {
                        $message = 'Announcement updated successfully!';
                        $messageType = 'success';
                        logActivity("Updated announcement ID: {$editId}", $currentUser['user_id']);
                    } else {
                        throw new Exception('Announcement not found or access denied.');
                    }
                    break;
                    
                case 'delete_announcement':
                    if (!$canCreateAnnouncements) {
                        throw new Exception('Access denied.');
                    }
                    
                    $deleteId = intval($_POST['announcement_id'] ?? 0);
                    if ($deleteId > 0) {
                        $deleted = $db->delete('messages', 
                                              'message_id = :id AND sender_id = :user_id AND message_type = :type', 
                                              [':id' => $deleteId, ':user_id' => $currentUser['user_id'], ':type' => 'announcement']);
                        
                        if ($deleted) {
                            $message = 'Announcement deleted successfully.';
                            $messageType = 'success';
                            logActivity("Deleted announcement ID: {$deleteId}", $currentUser['user_id']);
                            
                            // If we're viewing the deleted announcement, redirect to list
                            if ($view === 'details' && $announcementId === $deleteId) {
                                redirect("announcements.php", $message, $messageType);
                            }
                        } else {
                            throw new Exception('Announcement not found or access denied.');
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
            error_log("Announcement action error: " . $e->getMessage());
        }
    }
}

// Load data based on view
try {
    $db = Database::getInstance();
    
    if ($view === 'create' || $view === 'edit') {
        if (!$canCreateAnnouncements) {
            redirect('announcements.php', 'Access denied.', 'error');
        }
        
        if ($view === 'edit' && $announcementId > 0) {
            $announcement = $db->fetchRow(
                "SELECT * FROM messages 
                 WHERE message_id = :id AND message_type = 'announcement' AND sender_id = :user_id",
                [':id' => $announcementId, ':user_id' => $currentUser['user_id']]
            );
            
            if (!$announcement) {
                redirect('announcements.php', 'Announcement not found.', 'error');
            }
        }
        
        $announcements = [];
        $totalAnnouncements = 0;
        $totalPages = 0;
        
    } elseif ($view === 'details' && $announcementId > 0) {
        // Load specific announcement
        $announcement = $db->fetchRow(
            "SELECT m.*, u.first_name, u.last_name 
             FROM messages m 
             LEFT JOIN users u ON u.user_id = m.sender_id 
             WHERE m.message_id = :id AND m.message_type = 'announcement'",
            [':id' => $announcementId]
        );
        
        if (!$announcement) {
            redirect('announcements.php', 'Announcement not found.', 'error');
        }
        
        $announcements = [];
        $totalAnnouncements = 0;
        $totalPages = 0;
        
    } else {
        // Default list view
        $view = 'list';
        
        // Count total announcements
        $totalAnnouncements = $db->count('messages', "message_type = 'announcement'");
        $totalPages = ceil($totalAnnouncements / $perPage);
        
        // Get announcements for current page
        $announcementsQuery = "SELECT m.*, u.first_name, u.last_name 
                              FROM messages m 
                              LEFT JOIN users u ON u.user_id = m.sender_id 
                              WHERE m.message_type = 'announcement' 
                              ORDER BY m.sent_at DESC 
                              LIMIT $perPage OFFSET $offset";
        
        $announcements = $db->fetchAll($announcementsQuery);
        $announcement = null;
    }
    
    // Get announcement statistics
    $stats = [
        'total_announcements' => $db->count('messages', "message_type = 'announcement'"),
        'this_month' => $db->count('messages', "message_type = 'announcement' AND MONTH(sent_at) = MONTH(CURRENT_DATE()) AND YEAR(sent_at) = YEAR(CURRENT_DATE())"),
        'this_week' => $db->count('messages', "message_type = 'announcement' AND YEARWEEK(sent_at, 1) = YEARWEEK(CURRENT_DATE(), 1)"),
        'my_announcements' => $canCreateAnnouncements ? $db->count('messages', "message_type = 'announcement' AND sender_id = :user_id", [':user_id' => $currentUser['user_id']]) : 0
    ];
    
} catch (Exception $e) {
    error_log("Announcements error: " . $e->getMessage());
    $announcements = [];
    $announcement = null;
    $totalAnnouncements = 0;
    $totalPages = 0;
    $stats = ['total_announcements' => 0, 'this_month' => 0, 'this_week' => 0, 'my_announcements' => 0];
}

// Helper function to get priority badge class
function getPriorityClass($priority) {
    $classes = [
        'low' => 'priority-low',
        'medium' => 'priority-medium', 
        'high' => 'priority-high',
        'urgent' => 'priority-urgent'
    ];
    return $classes[$priority] ?? 'priority-medium';
}

// Helper function to format time
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    
    return date('M j, Y', strtotime($datetime));
}

$pageTitle = ucfirst($view) . ' - Announcements';
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
        
        /* Content Sections */
        .content-section {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
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
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
        }
        
        /* Announcement List */
        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .announcement-card {
            background: var(--gray-100);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary-color);
            position: relative;
        }
        
        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            background: var(--white);
        }
        
        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }
        
        .announcement-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        
        .announcement-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--gray-600);
            flex-wrap: wrap;
        }
        
        .announcement-content {
            color: var(--gray-600);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .announcement-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-end;
        }
        
        /* Announcement Details */
        .announcement-details {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .details-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: var(--white);
            padding: 2rem;
        }
        
        .details-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        
        .details-meta {
            display: flex;
            gap: 2rem;
            font-size: 0.9rem;
            opacity: 0.9;
            flex-wrap: wrap;
        }
        
        .details-content {
            padding: 2rem;
            font-size: 1.1rem;
            line-height: 1.7;
            color: var(--dark-color);
        }
        
        /* Forms */
        .form-container {
            background: var(--white);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
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
        
        textarea.form-control {
            resize: vertical;
            min-height: 200px;
        }
        
        .character-counter {
            font-size: 0.8rem;
            color: var(--gray-600);
            text-align: right;
            margin-top: 0.25rem;
        }
        
        .character-counter.warning {
            color: var(--warning-color);
        }
        
        .character-counter.danger {
            color: var(--danger-color);
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }
        
        /* Priority Badges */
        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .priority-low {
            background: rgba(116, 185, 255, 0.1);
            color: #74B9FF;
        }
        
        .priority-medium {
            background: rgba(253, 203, 110, 0.1);
            color: var(--warning-color);
        }
        
        .priority-high {
            background: rgba(253, 121, 168, 0.1);
            color: var(--accent-color);
        }
        
        .priority-urgent {
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
            padding: 1.5rem 0;
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .announcement-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .announcement-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .details-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .announcement-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                justify-content: center;
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
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-bell"></i> Notifications
                </a>
                <a href="announcements.php" class="nav-link active">
                    <i class="fas fa-bullhorn"></i> Announcements
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
            <a href="announcements.php">Announcements</a>
            <?php if ($view !== 'list'): ?>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo ucfirst($view); ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <?php if ($view === 'create'): ?>
                        Create Announcement
                    <?php elseif ($view === 'edit'): ?>
                        Edit Announcement
                    <?php elseif ($view === 'details'): ?>
                        Announcement Details
                    <?php else: ?>
                        System Announcements
                    <?php endif; ?>
                </h1>
                <p class="page-subtitle">
                    <?php if ($view === 'create'): ?>
                        Create important announcements for all users
                    <?php elseif ($view === 'edit'): ?>
                        Update announcement information
                    <?php elseif ($view === 'details'): ?>
                        View announcement details and information
                    <?php else: ?>
                        Important updates and information for the program
                    <?php endif; ?>
                </p>
            </div>
            <div class="header-actions">
                <?php if ($view === 'list'): ?>
                    <?php if ($canCreateAnnouncements): ?>
                        <a href="?view=create" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Announcement
                        </a>
                    <?php endif; ?>
                    <a href="messages.php" class="btn btn-outline">
                        <i class="fas fa-envelope"></i> Messages
                    </a>
                    <a href="notifications.php" class="btn btn-outline">
                        <i class="fas fa-bell"></i> Notifications
                    </a>
                <?php else: ?>
                    <a href="announcements.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($view === 'list'): ?>
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_announcements']; ?></div>
                    <div class="stat-label">Total Announcements</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['this_month']; ?></div>
                    <div class="stat-label">This Month</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['this_week']; ?></div>
                    <div class="stat-label">This Week</div>
                </div>
                
                <?php if ($canCreateAnnouncements): ?>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['my_announcements']; ?></div>
                        <div class="stat-label">My Announcements</div>
                    </div>
                <?php else: ?>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['this_week']; ?></div>
                        <div class="stat-label">Recent</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Announcements List -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        All Announcements (<?php echo number_format($totalAnnouncements); ?>)
                    </h2>
                    <?php if ($totalPages > 1): ?>
                        <span style="font-size: 0.9rem; color: var(--gray-600);">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($announcements)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                        <h3 class="empty-title">No announcements yet</h3>
                        <p>
                            <?php if ($canCreateAnnouncements): ?>
                                Create the first announcement to share important information with all users.
                            <?php else: ?>
                                Announcements will appear here when administrators post them.
                            <?php endif; ?>
                        </p>
                        <?php if ($canCreateAnnouncements): ?>
                            <a href="?view=create" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create First Announcement
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="announcement-list">
                        <?php foreach ($announcements as $ann): ?>
                            <div class="announcement-card">
                                <div class="announcement-header">
                                    <div style="flex: 1;">
                                        <h3 class="announcement-title">
                                            <?php echo htmlspecialchars($ann['subject']); ?>
                                        </h3>
                                        <div class="announcement-meta">
                                            <span>
                                                <i class="fas fa-user"></i>
                                                <?php echo htmlspecialchars($ann['first_name'] . ' ' . $ann['last_name']); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-calendar"></i>
                                                <?php echo timeAgo($ann['sent_at']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="announcement-content">
                                    <?php echo nl2br(htmlspecialchars($ann['message_text'])); ?>
                                </div>
                                
                                <div class="announcement-actions">
                                    <a href="?view=details&id=<?php echo $ann['message_id']; ?>" class="btn btn-outline btn-sm">
                                        <i class="fas fa-eye"></i> View Details
                                    </a>
                                    
                                    <?php if ($canCreateAnnouncements && $ann['sender_id'] === $currentUser['user_id']): ?>
                                        <a href="?view=edit&id=<?php echo $ann['message_id']; ?>" class="btn btn-outline btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="delete_announcement">
                                            <input type="hidden" name="announcement_id" value="<?php echo $ann['message_id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?page=<?php echo $page - 1; ?>">
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
                                    <a href="?page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?page=<?php echo $totalPages; ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
        <?php elseif ($view === 'details' && $announcement): ?>
            <!-- Announcement Details -->
            <div class="announcement-details">
                <div class="details-header">
                    <h1 class="details-title"><?php echo htmlspecialchars($announcement['subject']); ?></h1>
                    <div class="details-meta">
                        <div>
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($announcement['first_name'] . ' ' . $announcement['last_name']); ?>
                        </div>
                        <div>
                            <i class="fas fa-calendar"></i>
                            <?php echo date('F j, Y \a\t g:i A', strtotime($announcement['sent_at'])); ?>
                        </div>
                    </div>
                </div>
                
                <div class="details-content">
                    <?php echo nl2br(htmlspecialchars($announcement['message_text'])); ?>
                </div>
                
                <?php if ($canCreateAnnouncements && $announcement['sender_id'] === $currentUser['user_id']): ?>
                    <div style="padding: 2rem; border-top: 1px solid var(--gray-200); background: var(--gray-100);">
                        <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                            <a href="?view=edit&id=<?php echo $announcement['message_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Edit Announcement
                            </a>
                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="delete_announcement">
                                <input type="hidden" name="announcement_id" value="<?php echo $announcement['message_id']; ?>">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($view === 'create' || ($view === 'edit' && $announcement)): ?>
            <!-- Create/Edit Form -->
            <div class="form-container">
                <form method="POST" id="announcementForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <?php if ($view === 'edit'): ?>
                        <input type="hidden" name="action" value="edit_announcement">
                        <input type="hidden" name="announcement_id" value="<?php echo $announcement['message_id']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="action" value="create_announcement">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="title">Announcement Title *</label>
                        <input type="text" id="title" name="title" class="form-control" 
                               placeholder="Enter a clear, descriptive title" 
                               value="<?php echo $view === 'edit' ? htmlspecialchars($announcement['subject']) : ''; ?>" 
                               maxlength="200" required>
                        <div class="character-counter">
                            <span id="titleCount">0</span> / 200 characters
                        </div>
                    </div>
                    
                    <?php if ($view === 'create'): ?>
                        <div class="form-group">
                            <label for="priority">Priority Level</label>
                            <select id="priority" name="priority" class="form-control">
                                <option value="low">Low - General information</option>
                                <option value="medium" selected>Medium - Standard announcement</option>
                                <option value="high">High - Important information</option>
                                <option value="urgent">Urgent - Immediate attention required</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="content">Announcement Content *</label>
                        <textarea id="content" name="content" class="form-control" 
                                  placeholder="Write your announcement content here..." 
                                  maxlength="5000" required><?php echo $view === 'edit' ? htmlspecialchars($announcement['message_text']) : ''; ?></textarea>
                        <div class="character-counter">
                            <span id="contentCount">0</span> / 5000 characters
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <a href="announcements.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-<?php echo $view === 'edit' ? 'save' : 'bullhorn'; ?>"></i>
                            <?php echo $view === 'edit' ? 'Update' : 'Publish'; ?> Announcement
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
    
    <script>
        // Character counters
        function setupCharacterCounter(inputId, counterId, maxLength) {
            const input = document.getElementById(inputId);
            const counter = document.getElementById(counterId);
            
            if (!input || !counter) return;
            
            function updateCounter() {
                const length = input.value.length;
                counter.textContent = length;
                
                const counterElement = counter.parentElement;
                if (length > maxLength * 0.9) {
                    counterElement.className = 'character-counter danger';
                } else if (length > maxLength * 0.8) {
                    counterElement.className = 'character-counter warning';
                } else {
                    counterElement.className = 'character-counter';
                }
            }
            
            input.addEventListener('input', updateCounter);
            updateCounter(); // Initialize
        }
        
        // Setup character counters
        setupCharacterCounter('title', 'titleCount', 200);
        setupCharacterCounter('content', 'contentCount', 5000);
        
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Form validation
        const announcementForm = document.getElementById('announcementForm');
        if (announcementForm) {
            announcementForm.addEventListener('submit', function(e) {
                const title = document.getElementById('title').value.trim();
                const content = document.getElementById('content').value.trim();
                
                if (!title || title.length < 5) {
                    e.preventDefault();
                    alert('Title must be at least 5 characters long.');
                    document.getElementById('title').focus();
                    return;
                }
                
                if (!content || content.length < 20) {
                    e.preventDefault();
                    alert('Content must be at least 20 characters long.');
                    document.getElementById('content').focus();
                    return;
                }
                
                // Add loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.style.opacity = '0.6';
                submitBtn.disabled = true;
            });
        }
        
        // Auto-resize textarea
        const textarea = document.getElementById('content');
        if (textarea) {
            function autoResize() {
                textarea.style.height = 'auto';
                textarea.style.height = Math.max(200, textarea.scrollHeight) + 'px';
            }
            
            textarea.addEventListener('input', autoResize);
            autoResize(); // Initialize
        }
        
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
        
        // Add hover effects to announcement cards
        document.querySelectorAll('.announcement-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // 'n' to create new announcement (admin only)
            if (e.key === 'n' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                const createBtn = document.querySelector('a[href*="view=create"]');
                if (createBtn) {
                    window.location.href = createBtn.href;
                }
            }
        });
        
        // Confirmation for delete actions
        document.querySelectorAll('form[onsubmit*="confirm"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this announcement? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
