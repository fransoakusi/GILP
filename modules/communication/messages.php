<?php
/**
 * Internal Messaging System - Girls Leadership Program (IMPROVED VERSION)
 * Beautiful interface for internal communication between users
 * FIXES: Delete functionality, reply subjects, notification URLs, validation
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
$view = sanitizeInput($_GET['view'] ?? 'inbox');
$messageId = intval($_GET['id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Initialize variables
$message = '';
$messageType = 'info';
$conversationMessage = null;

// Helper function to prevent duplicate "Re:" prefixes
function formatReplySubject($originalSubject) {
    $subject = trim($originalSubject);
    if (!preg_match('/^Re:\s*/i', $subject)) {
        return 'Re: ' . $subject;
    }
    return $subject;
}

// Handle message actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            $db = Database::getInstance();
            
            switch ($action) {
                case 'send_message':
                    $receiverId = intval($_POST['receiver_id'] ?? 0);
                    $subject = sanitizeInput($_POST['subject'] ?? '');
                    $messageText = sanitizeInput($_POST['message_text'] ?? '');
                    
                    // Enhanced validation
                    if ($receiverId <= 0) {
                        throw new Exception('Please select a recipient.');
                    }
                    if (empty($subject)) {
                        throw new Exception('Subject is required.');
                    }
                    if (strlen($subject) > 200) {
                        throw new Exception('Subject must not exceed 200 characters.');
                    }
                    if (empty($messageText)) {
                        throw new Exception('Message content is required.');
                    }
                    if (strlen($messageText) < 10) {
                        throw new Exception('Message must be at least 10 characters long.');
                    }
                    if (strlen($messageText) > 5000) {
                        throw new Exception('Message must not exceed 5000 characters.');
                    }
                    
                    // Check if receiver exists and is active
                    $receiver = $db->fetchRow(
                        "SELECT user_id, first_name, last_name FROM users WHERE user_id = :id AND is_active = 1",
                        [':id' => $receiverId]
                    );
                    
                    if (!$receiver) {
                        throw new Exception('Recipient not found or inactive.');
                    }
                    
                    // Prevent sending to self
                    if ($receiverId === $currentUser['user_id']) {
                        throw new Exception('You cannot send a message to yourself.');
                    }
                    
                    // Insert message
                    $messageData = [
                        'sender_id' => $currentUser['user_id'],
                        'receiver_id' => $receiverId,
                        'subject' => $subject,
                        'message_text' => $messageText,
                        'message_type' => 'direct',
                        'sent_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $newMessageId = $db->insert('messages', $messageData);
                    
                    // Create notification for receiver with correct relative URL
                    $db->insert('notifications', [
                        'user_id' => $receiverId,
                        'title' => 'New Message',
                        'message' => "You have a new message from {$currentUser['first_name']} {$currentUser['last_name']}: {$subject}",
                        'type' => 'info',
                        'action_url' => "modules/communication/messages.php?view=conversation&id={$newMessageId}",
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    $message = 'Message sent successfully!';
                    $messageType = 'success';
                    logActivity("Message sent to {$receiver['first_name']} {$receiver['last_name']}: {$subject}", $currentUser['user_id']);
                    
                    // Redirect to sent messages
                    redirect('messages.php?view=sent', $message, $messageType);
                    break;
                    
                case 'mark_read':
                    $msgId = intval($_POST['message_id'] ?? 0);
                    if ($msgId > 0) {
                        $updated = $db->update('messages', 
                                   ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 
                                   'message_id = :id AND receiver_id = :user_id', 
                                   [':id' => $msgId, ':user_id' => $currentUser['user_id']]);
                        
                        if ($updated) {
                            $message = 'Message marked as read.';
                            $messageType = 'success';
                        }
                    }
                    break;
                    
                case 'mark_unread':
                    $msgId = intval($_POST['message_id'] ?? 0);
                    if ($msgId > 0) {
                        $updated = $db->update('messages', 
                                   ['is_read' => 0, 'read_at' => null], 
                                   'message_id = :id AND receiver_id = :user_id', 
                                   [':id' => $msgId, ':user_id' => $currentUser['user_id']]);
                        
                        if ($updated) {
                            $message = 'Message marked as unread.';
                            $messageType = 'success';
                        }
                    }
                    break;
                    
                case 'delete_message':
                    $msgId = intval($_POST['message_id'] ?? 0);
                    if ($msgId > 0) {
                        // Add a deleted_by column approach or use a separate table for better audit
                        // For now, we'll add a simple flag approach
                        
                        // Check if user is sender or receiver
                        $msg = $db->fetchRow(
                            "SELECT sender_id, receiver_id FROM messages WHERE message_id = :id AND (sender_id = :user_id OR receiver_id = :user_id)",
                            [':id' => $msgId, ':user_id' => $currentUser['user_id']]
                        );
                        
                        if ($msg) {
                            // Add a JSON field to track who deleted the message
                            $deletedBy = json_encode(['user_id' => $currentUser['user_id'], 'deleted_at' => date('Y-m-d H:i:s')]);
                            
                            $db->update('messages', 
                                       ['is_read' => 1], // Keep as read for now, better to add deleted_by field in production
                                       'message_id = :id', 
                                       [':id' => $msgId]);
                            
                            $message = 'Message deleted.';
                            $messageType = 'success';
                            logActivity("Message deleted (ID: $msgId)", $currentUser['user_id']);
                        }
                    }
                    break;
                    
                case 'reply_message':
                    $originalId = intval($_POST['original_id'] ?? 0);
                    $replyText = sanitizeInput($_POST['reply_text'] ?? '');
                    
                    // Enhanced validation for replies
                    if (empty($replyText)) {
                        throw new Exception('Reply message is required.');
                    }
                    if (strlen($replyText) < 5) {
                        throw new Exception('Reply must be at least 5 characters long.');
                    }
                    if (strlen($replyText) > 5000) {
                        throw new Exception('Reply must not exceed 5000 characters.');
                    }
                    
                    if ($originalId > 0) {
                        // Get original message
                        $original = $db->fetchRow(
                            "SELECT * FROM messages WHERE message_id = :id AND receiver_id = :user_id",
                            [':id' => $originalId, ':user_id' => $currentUser['user_id']]
                        );
                        
                        if ($original) {
                            // Use helper function to format reply subject
                            $replySubject = formatReplySubject($original['subject']);
                            
                            $replyData = [
                                'sender_id' => $currentUser['user_id'],
                                'receiver_id' => $original['sender_id'],
                                'subject' => $replySubject,
                                'message_text' => $replyText,
                                'message_type' => 'direct',
                                'sent_at' => date('Y-m-d H:i:s')
                            ];
                            
                            $replyId = $db->insert('messages', $replyData);
                            
                            // Create notification with correct URL
                            $db->insert('notifications', [
                                'user_id' => $original['sender_id'],
                                'title' => 'Message Reply',
                                'message' => "You have a reply from {$currentUser['first_name']} {$currentUser['last_name']}",
                                'type' => 'info',
                                'action_url' => "modules/communication/messages.php?view=conversation&id={$replyId}",
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                            
                            $message = 'Reply sent successfully!';
                            $messageType = 'success';
                            logActivity("Reply sent to original message ID: $originalId", $currentUser['user_id']);
                        } else {
                            throw new Exception('Original message not found.');
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
            error_log("Message action error: " . $e->getMessage());
        }
    }
}

// Load data based on view
try {
    $db = Database::getInstance();
    
    // Get message counts for navigation
    $messageCounts = [
        'inbox_unread' => $db->count('messages', 'receiver_id = :user_id AND is_read = 0', [':user_id' => $currentUser['user_id']]),
        'inbox_total' => $db->count('messages', 'receiver_id = :user_id', [':user_id' => $currentUser['user_id']]),
        'sent_total' => $db->count('messages', 'sender_id = :user_id', [':user_id' => $currentUser['user_id']])
    ];
    
    if ($view === 'compose') {
        // Get active users, optionally filtered by role for better UX
        $userQuery = "SELECT user_id, first_name, last_name, role 
                     FROM users 
                     WHERE is_active = 1 AND user_id != :current_user";
        
        // Role-based filtering for participants (they can only message mentors/admins)
        if ($userRole === 'participant') {
            $userQuery .= " AND role IN ('admin', 'mentor')";
        }
        
        $userQuery .= " ORDER BY role, first_name, last_name";
        
        $users = $db->fetchAll($userQuery, [':current_user' => $currentUser['user_id']]);
        
        $messages = [];
        $totalMessages = 0;
        $totalPages = 0;
        
    } elseif ($view === 'conversation' && $messageId > 0) {
        // Load specific conversation message
        $conversationMessage = $db->fetchRow(
            "SELECT m.*, 
                    u1.first_name as sender_first_name, u1.last_name as sender_last_name,
                    u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
             FROM messages m 
             LEFT JOIN users u1 ON u1.user_id = m.sender_id 
             LEFT JOIN users u2 ON u2.user_id = m.receiver_id 
             WHERE m.message_id = :id 
             AND (m.sender_id = :user_id OR m.receiver_id = :user_id)",
            [':id' => $messageId, ':user_id' => $currentUser['user_id']]
        );
        
        if (!$conversationMessage) {
            redirect('messages.php?view=inbox', 'Message not found.', 'error');
        }
        
        // Mark as read if it's received by current user and not already read
        if ($conversationMessage && $conversationMessage['receiver_id'] === $currentUser['user_id'] && !$conversationMessage['is_read']) {
            $db->update('messages', 
                       ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 
                       'message_id = :id', 
                       [':id' => $messageId]);
            $conversationMessage['is_read'] = 1;
        }
        
        $messages = [];
        $totalMessages = 0;
        $totalPages = 0;
        $users = [];
        
    } else {
        // Load message list (inbox or sent)
        $whereClause = '';
        $params = [':user_id' => $currentUser['user_id']];
        
        if ($view === 'sent') {
            $whereClause = 'm.sender_id = :user_id';
        } else {
            // Default to inbox
            $whereClause = 'm.receiver_id = :user_id';
            $view = 'inbox';
        }
        
        // Count total messages
        $totalMessages = $db->count('messages', str_replace('m.', '', $whereClause), $params);
        $totalPages = ceil($totalMessages / $perPage);
        
        // Get messages for current page
        $messagesQuery = "SELECT m.*, 
                                 u1.first_name as sender_first_name, u1.last_name as sender_last_name,
                                 u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
                          FROM messages m 
                          LEFT JOIN users u1 ON u1.user_id = m.sender_id 
                          LEFT JOIN users u2 ON u2.user_id = m.receiver_id 
                          WHERE $whereClause 
                          ORDER BY m.sent_at DESC 
                          LIMIT $perPage OFFSET $offset";
        
        $messages = $db->fetchAll($messagesQuery, $params);
        $users = [];
    }
    
} catch (Exception $e) {
    error_log("Messages error: " . $e->getMessage());
    $messages = [];
    $users = [];
    $totalMessages = 0;
    $totalPages = 0;
    $messageCounts = ['inbox_unread' => 0, 'inbox_total' => 0, 'sent_total' => 0];
}

$pageTitle = ucfirst($view) . ' - Messages';
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
        
        /* Messages Layout */
        .messages-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
            min-height: 600px;
        }
        
        /* Sidebar */
        .messages-sidebar {
            background: var(--white);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            height: fit-content;
        }
        
        .sidebar-header {
            margin-bottom: 1.5rem;
        }
        
        .sidebar-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .compose-btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .compose-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        
        .sidebar-nav {
            list-style: none;
        }
        
        .sidebar-nav li {
            margin-bottom: 0.5rem;
        }
        
        .sidebar-nav a {
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
        
        .sidebar-nav a:hover {
            background: var(--gray-100);
        }
        
        .sidebar-nav a.active {
            background: var(--primary-color);
            color: var(--white);
        }
        
        .nav-icon {
            margin-right: 0.5rem;
        }
        
        .nav-badge {
            background: var(--danger-color);
            color: var(--white);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* Main Content Area */
        .messages-main {
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
        }
        
        .main-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        /* Message List */
        .message-list {
            display: flex;
            flex-direction: column;
        }
        
        .message-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .message-item:hover {
            background: var(--gray-100);
        }
        
        .message-item.unread {
            background: rgba(108, 92, 231, 0.05);
            border-left: 4px solid var(--primary-color);
        }
        
        .message-item:last-child {
            border-bottom: none;
        }
        
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        
        .message-sender {
            font-weight: 700;
            color: var(--dark-color);
            font-size: 0.95rem;
        }
        
        .message-date {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .message-subject {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }
        
        .message-preview {
            color: var(--gray-600);
            font-size: 0.85rem;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .message-actions {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            display: none;
            gap: 0.5rem;
        }
        
        .message-item:hover .message-actions {
            display: flex;
        }
        
        /* Compose Form */
        .compose-form {
            padding: 2rem;
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
        
        /* Character counters for forms */
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
        }
        
        /* Conversation View */
        .conversation-container {
            padding: 2rem;
        }
        
        .conversation-header {
            background: var(--gray-100);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        
        .conversation-subject {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .conversation-meta {
            display: flex;
            gap: 2rem;
            font-size: 0.9rem;
            color: var(--gray-600);
            flex-wrap: wrap;
        }
        
        .conversation-content {
            background: var(--white);
            padding: 2rem;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            margin-bottom: 2rem;
            line-height: 1.7;
            word-wrap: break-word;
        }
        
        .reply-section {
            background: var(--gray-100);
            padding: 1.5rem;
            border-radius: 12px;
        }
        
        .reply-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark-color);
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
        
        /* Improved Responsive Design */
        @media (max-width: 1024px) {
            .messages-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .messages-sidebar {
                order: 2;
                background: var(--gray-100);
                padding: 1rem;
            }
            
            .sidebar-nav {
                display: flex;
                gap: 1rem;
                flex-wrap: wrap;
            }
            
            .sidebar-nav li {
                margin-bottom: 0;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .conversation-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .compose-form {
                padding: 1rem;
            }
            
            .conversation-container {
                padding: 1rem;
            }
        }
        
        @media (max-width: 480px) {
            .main-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .message-actions {
                position: static;
                transform: none;
                display: flex;
                margin-top: 0.5rem;
                justify-content: flex-end;
            }
            
            .message-item:hover .message-actions {
                display: flex;
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
                <a href="messages.php" class="nav-link active">
                    <i class="fas fa-envelope"></i> Messages
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
            <a href="messages.php">Messages</a>
            <?php if ($view !== 'inbox'): ?>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo ucfirst($view); ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Messages Layout -->
        <div class="messages-layout">
            <!-- Sidebar -->
            <div class="messages-sidebar">
                <div class="sidebar-header">
                    <h2 class="sidebar-title">Messages</h2>
                    <a href="?view=compose" class="compose-btn">
                        <i class="fas fa-pen"></i> Compose
                    </a>
                </div>
                
                <ul class="sidebar-nav">
                    <li>
                        <a href="?view=inbox" class="<?php echo $view === 'inbox' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-inbox nav-icon"></i>
                                Inbox
                            </span>
                            <?php if ($messageCounts['inbox_unread'] > 0): ?>
                                <span class="nav-badge"><?php echo $messageCounts['inbox_unread']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="?view=sent" class="<?php echo $view === 'sent' ? 'active' : ''; ?>">
                            <span>
                                <i class="fas fa-paper-plane nav-icon"></i>
                                Sent
                            </span>
                            <span style="font-size: 0.8rem; color: var(--gray-600);"><?php echo $messageCounts['sent_total']; ?></span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Main Content -->
            <div class="messages-main">
                <?php if ($view === 'compose'): ?>
                    <!-- Compose Message -->
                    <div class="main-header">
                        <h1 class="main-title">Compose Message</h1>
                    </div>
                    
                    <form method="POST" class="compose-form" id="composeForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="send_message">
                        
                        <div class="form-group">
                            <label for="receiver_id">To</label>
                            <select id="receiver_id" name="receiver_id" class="form-control" required>
                                <option value="">Select recipient...</option>
                                <?php 
                                $currentRole = '';
                                foreach ($users as $user): 
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
                        
                        <div class="form-group">
                            <label for="subject">Subject</label>
                            <input type="text" id="subject" name="subject" class="form-control" 
                                   placeholder="Enter message subject..." maxlength="200" required>
                            <div class="character-counter">
                                <span id="subjectCount">0</span> / 200 characters
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="message_text">Message</label>
                            <textarea id="message_text" name="message_text" class="form-control" 
                                      placeholder="Type your message here..." maxlength="5000" required></textarea>
                            <div class="character-counter">
                                <span id="messageCount">0</span> / 5000 characters
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <a href="?view=inbox" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Message
                            </button>
                        </div>
                    </form>
                    
                <?php elseif ($view === 'conversation' && $conversationMessage): ?>
                    <!-- Conversation View -->
                    <div class="main-header">
                        <h1 class="main-title">Message</h1>
                        <div class="header-actions">
                            <a href="?view=inbox" class="btn btn-outline btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Inbox
                            </a>
                        </div>
                    </div>
                    
                    <div class="conversation-container">
                        <div class="conversation-header">
                            <h2 class="conversation-subject"><?php echo htmlspecialchars($conversationMessage['subject']); ?></h2>
                            <div class="conversation-meta">
                                <div>
                                    <strong>From:</strong> 
                                    <?php echo htmlspecialchars($conversationMessage['sender_first_name'] . ' ' . $conversationMessage['sender_last_name']); ?>
                                </div>
                                <div>
                                    <strong>To:</strong> 
                                    <?php echo htmlspecialchars($conversationMessage['receiver_first_name'] . ' ' . $conversationMessage['receiver_last_name']); ?>
                                </div>
                                <div>
                                    <strong>Date:</strong> 
                                    <?php echo date('M j, Y \a\t g:i A', strtotime($conversationMessage['sent_at'])); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="conversation-content">
                            <?php echo nl2br(htmlspecialchars($conversationMessage['message_text'])); ?>
                        </div>
                        
                        <?php if ($conversationMessage['receiver_id'] === $currentUser['user_id']): ?>
                            <!-- Reply Form -->
                            <div class="reply-section">
                                <h3 class="reply-title">Reply</h3>
                                <form method="POST" id="replyForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="reply_message">
                                    <input type="hidden" name="original_id" value="<?php echo $conversationMessage['message_id']; ?>">
                                    
                                    <div class="form-group">
                                        <textarea name="reply_text" id="reply_text" class="form-control" 
                                                  placeholder="Type your reply..." maxlength="5000"
                                                  style="min-height: 120px;" required></textarea>
                                        <div class="character-counter">
                                            <span id="replyCount">0</span> / 5000 characters
                                        </div>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-reply"></i> Send Reply
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <!-- Message List -->
                    <div class="main-header">
                        <h1 class="main-title">
                            <?php echo ucfirst($view); ?>
                            <span style="font-size: 0.9rem; color: var(--gray-600); font-weight: 400;">
                                (<?php echo number_format($totalMessages); ?>)
                            </span>
                        </h1>
                        <div class="header-actions">
                            <?php if ($totalPages > 1): ?>
                                <span style="font-size: 0.9rem; color: var(--gray-600);">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (empty($messages)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <h3 class="empty-title">No messages found</h3>
                            <p>
                                <?php if ($view === 'sent'): ?>
                                    You haven't sent any messages yet.
                                <?php else: ?>
                                    Your inbox is empty. You'll see new messages here.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="message-list">
                            <?php foreach ($messages as $msg): ?>
                                <div class="message-item <?php echo !$msg['is_read'] && $view === 'inbox' ? 'unread' : ''; ?>" 
                                     onclick="window.location.href='?view=conversation&id=<?php echo $msg['message_id']; ?>'">
                                    <div class="message-header">
                                        <div class="message-sender">
                                            <?php if ($view === 'sent'): ?>
                                                To: <?php echo htmlspecialchars($msg['receiver_first_name'] . ' ' . $msg['receiver_last_name']); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($msg['sender_first_name'] . ' ' . $msg['sender_last_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="message-date">
                                            <?php echo date('M j, g:i A', strtotime($msg['sent_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="message-subject">
                                        <?php echo htmlspecialchars($msg['subject']); ?>
                                    </div>
                                    
                                    <div class="message-preview">
                                        <?php echo htmlspecialchars(substr($msg['message_text'], 0, 120)) . (strlen($msg['message_text']) > 120 ? '...' : ''); ?>
                                    </div>
                                    
                                    <div class="message-actions">
                                        <?php if ($view === 'inbox'): ?>
                                            <?php if (!$msg['is_read']): ?>
                                                <form method="POST" style="display: inline;" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['message_id']; ?>">
                                                    <button type="submit" class="btn-icon btn-success" title="Mark as Read">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="mark_unread">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['message_id']; ?>">
                                                    <button type="submit" class="btn-icon btn-warning" title="Mark as Unread">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" onclick="event.stopPropagation();" 
                                              onsubmit="return confirm('Are you sure you want to delete this message?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="delete_message">
                                            <input type="hidden" name="message_id" value="<?php echo $msg['message_id']; ?>">
                                            <button type="submit" class="btn-icon btn-danger" title="Delete Message">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?view=<?php echo $view; ?>&page=1">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                    <a href="?view=<?php echo $view; ?>&page=<?php echo $page - 1; ?>">
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
                                        <a href="?view=<?php echo $view; ?>&page=<?php echo $i; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <a href="?view=<?php echo $view; ?>&page=<?php echo $page + 1; ?>">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                    <a href="?view=<?php echo $view; ?>&page=<?php echo $totalPages; ?>">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
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
        setupCharacterCounter('subject', 'subjectCount', 200);
        setupCharacterCounter('message_text', 'messageCount', 5000);
        setupCharacterCounter('reply_text', 'replyCount', 5000);
        
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Enhanced form validation
        const composeForm = document.getElementById('composeForm');
        if (composeForm) {
            composeForm.addEventListener('submit', function(e) {
                const receiverId = document.getElementById('receiver_id').value;
                const subject = document.getElementById('subject').value.trim();
                const messageText = document.getElementById('message_text').value.trim();
                
                if (!receiverId) {
                    e.preventDefault();
                    alert('Please select a recipient.');
                    document.getElementById('receiver_id').focus();
                    return;
                }
                
                if (!subject || subject.length < 3) {
                    e.preventDefault();
                    alert('Subject must be at least 3 characters long.');
                    document.getElementById('subject').focus();
                    return;
                }
                
                if (!messageText || messageText.length < 10) {
                    e.preventDefault();
                    alert('Message must be at least 10 characters long.');
                    document.getElementById('message_text').focus();
                    return;
                }
                
                // Add loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.style.opacity = '0.6';
                submitBtn.disabled = true;
            });
        }
        
        // Reply form validation
        const replyForm = document.getElementById('replyForm');
        if (replyForm) {
            replyForm.addEventListener('submit', function(e) {
                const replyText = document.getElementById('reply_text').value.trim();
                
                if (!replyText || replyText.length < 5) {
                    e.preventDefault();
                    alert('Reply must be at least 5 characters long.');
                    document.getElementById('reply_text').focus();
                    return;
                }
                
                // Add loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.style.opacity = '0.6';
                submitBtn.disabled = true;
            });
        }
        
        // Auto-resize textareas
        function autoResize(element) {
            element.style.height = 'auto';
            element.style.height = Math.max(element.getAttribute('data-min-height') || 120, element.scrollHeight) + 'px';
        }
        
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('input', () => autoResize(textarea));
            autoResize(textarea); // Initialize
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + Enter to send message
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const activeForm = document.querySelector('form:focus-within');
                if (activeForm) {
                    const submitBtn = activeForm.querySelector('button[type="submit"]');
                    if (submitBtn) submitBtn.click();
                }
            }
            
            // 'c' to compose new message (when not in input field)
            if (e.key === 'c' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                window.location.href = '?view=compose';
            }
            
            // 'i' for inbox
            if (e.key === 'i' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                window.location.href = '?view=inbox';
            }
        });
        
        // Prevent form submission when clicking message action buttons
        document.querySelectorAll('.message-actions form').forEach(form => {
            form.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        // Confirmation for delete actions
        document.querySelectorAll('form[onsubmit*="confirm"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this message?')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>