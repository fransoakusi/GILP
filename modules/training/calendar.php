 <?php
/**
 * Training Calendar View - Girls Leadership Program
 * Beautiful calendar interface for viewing training sessions
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

// Get calendar parameters
$currentYear = intval($_GET['year'] ?? date('Y'));
$currentMonth = intval($_GET['month'] ?? date('n'));
$view = sanitizeInput($_GET['view'] ?? 'month');

// Validate parameters
$currentYear = max(2020, min(2030, $currentYear));
$currentMonth = max(1, min(12, $currentMonth));
if (!in_array($view, ['month', 'week'])) {
    $view = 'month';
}

// Calculate calendar boundaries
$firstDayOfMonth = new DateTime("$currentYear-$currentMonth-01");
$lastDayOfMonth = new DateTime($firstDayOfMonth->format('Y-m-t'));

// For calendar display, we need to show the full weeks
$calendarStart = clone $firstDayOfMonth;
$calendarStart->modify('last monday'); // Start from Monday of the first week
if ($calendarStart > $firstDayOfMonth) {
    $calendarStart->modify('-7 days');
}

$calendarEnd = clone $lastDayOfMonth;
$calendarEnd->modify('next sunday'); // End at Sunday of the last week
if ($calendarEnd < $lastDayOfMonth) {
    $calendarEnd->modify('+7 days');
}

// Get training sessions for the calendar period
try {
    $db = Database::getInstance();
    
    $sessionsQuery = "SELECT ts.*, 
                             u.first_name as instructor_first_name, 
                             u.last_name as instructor_last_name,
                             (SELECT COUNT(*) FROM session_attendance sa WHERE sa.session_id = ts.session_id AND sa.status IN ('registered', 'attended')) as registered_count
                      FROM training_sessions ts 
                      LEFT JOIN users u ON u.user_id = ts.instructor_id 
                      WHERE DATE(ts.session_date) >= :start_date 
                      AND DATE(ts.session_date) <= :end_date
                      ORDER BY ts.session_date ASC";
    
    $sessions = $db->fetchAll($sessionsQuery, [
        ':start_date' => $calendarStart->format('Y-m-d'),
        ':end_date' => $calendarEnd->format('Y-m-d')
    ]);
    
    // Group sessions by date
    $sessionsByDate = [];
    foreach ($sessions as $session) {
        $date = date('Y-m-d', strtotime($session['session_date']));
        if (!isset($sessionsByDate[$date])) {
            $sessionsByDate[$date] = [];
        }
        $sessionsByDate[$date][] = $session;
    }
    
    // Get user's registered sessions
    $userSessions = [];
    if (!empty($sessions)) {
        $sessionIds = array_column($sessions, 'session_id');
        $userSessionsQuery = "SELECT session_id FROM session_attendance 
                             WHERE user_id = :user_id AND session_id IN (" . implode(',', $sessionIds) . ")";
        $userRegistrations = $db->fetchAll($userSessionsQuery, [':user_id' => $currentUser['user_id']]);
        $userSessions = array_column($userRegistrations, 'session_id');
    }
    
    // Get calendar statistics
    $stats = [
        'total_sessions_month' => count($sessions),
        'my_sessions_month' => 0,
        'upcoming_sessions' => 0,
        'today_sessions' => 0
    ];
    
    $today = date('Y-m-d');
    $now = new DateTime();
    
    foreach ($sessions as $session) {
        if (in_array($session['session_id'], $userSessions)) {
            $stats['my_sessions_month']++;
        }
        
        $sessionDate = new DateTime($session['session_date']);
        if ($sessionDate >= $now) {
            $stats['upcoming_sessions']++;
        }
        
        if (date('Y-m-d', strtotime($session['session_date'])) === $today) {
            $stats['today_sessions']++;
        }
    }
    
} catch (Exception $e) {
    error_log("Calendar error: " . $e->getMessage());
    $sessions = [];
    $sessionsByDate = [];
    $userSessions = [];
    $stats = ['total_sessions_month' => 0, 'my_sessions_month' => 0, 'upcoming_sessions' => 0, 'today_sessions' => 0];
}

// Calendar navigation
$prevMonth = clone $firstDayOfMonth;
$prevMonth->modify('-1 month');

$nextMonth = clone $firstDayOfMonth;
$nextMonth->modify('+1 month');

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$pageTitle = 'Training Calendar - ' . $monthNames[$currentMonth] . ' ' . $currentYear;
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
        
        /* Calendar Header */
        .calendar-header {
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
        
        .calendar-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .nav-btn {
            padding: 0.75rem;
            background: var(--gray-100);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--dark-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .nav-btn:hover {
            background: var(--primary-color);
            color: var(--white);
            transform: translateY(-2px);
        }
        
        .month-year {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            min-width: 200px;
            text-align: center;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
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
        
        /* Calendar Container */
        .calendar-container {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--gray-200);
        }
        
        .calendar-header-cell {
            background: var(--gray-100);
            padding: 1rem;
            text-align: center;
            font-weight: 700;
            color: var(--dark-color);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .calendar-day {
            background: var(--white);
            min-height: 120px;
            padding: 0.75rem;
            position: relative;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .calendar-day:hover {
            background: var(--gray-100);
        }
        
        .calendar-day.other-month {
            background: var(--gray-100);
            opacity: 0.6;
        }
        
        .calendar-day.today {
            background: rgba(108, 92, 231, 0.1);
            border: 2px solid var(--primary-color);
        }
        
        .calendar-day.has-sessions {
            background: rgba(108, 92, 231, 0.05);
        }
        
        .day-number {
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .calendar-day.today .day-number {
            color: var(--primary-color);
        }
        
        .calendar-day.other-month .day-number {
            color: var(--gray-600);
        }
        
        .day-sessions {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .session-item {
            background: var(--primary-color);
            color: var(--white);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .session-item:hover {
            transform: scale(1.05);
            box-shadow: var(--shadow);
        }
        
        .session-item.my-session {
            background: var(--success-color);
        }
        
        .session-item.status-completed {
            background: var(--gray-600);
        }
        
        .session-item.status-cancelled {
            background: var(--danger-color);
        }
        
        .session-item.status-ongoing {
            background: var(--warning-color);
            color: var(--dark-color);
        }
        
        .more-sessions {
            background: var(--gray-600);
            color: var(--white);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            text-align: center;
            margin-top: 0.25rem;
        }
        
        /* Session Modal */
        .session-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            animation: fadeIn 0.3s ease-out;
        }
        
        .session-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--white);
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: var(--shadow-hover);
            animation: slideIn 0.3s ease-out;
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray-600);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            background: var(--gray-100);
            color: var(--dark-color);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .session-details {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .session-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .detail-icon {
            color: var(--primary-color);
            width: 16px;
        }
        
        .session-description {
            background: var(--gray-100);
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            line-height: 1.6;
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
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            background: var(--gray-100);
            border-radius: 8px;
            padding: 0.25rem;
        }
        
        .view-toggle button {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: var(--gray-600);
        }
        
        .view-toggle button.active {
            background: var(--primary-color);
            color: var(--white);
        }
        
        /* Legend */
        .calendar-legend {
            display: flex;
            gap: 2rem;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            background: var(--gray-100);
            font-size: 0.8rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }
        
        .legend-color.regular {
            background: var(--primary-color);
        }
        
        .legend-color.my-session {
            background: var(--success-color);
        }
        
        .legend-color.completed {
            background: var(--gray-600);
        }
        
        .legend-color.cancelled {
            background: var(--danger-color);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .calendar-day {
                min-height: 100px;
            }
            
            .session-item {
                font-size: 0.6rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .calendar-header {
                flex-direction: column;
                text-align: center;
            }
            
            .calendar-nav {
                order: -1;
            }
            
            .calendar-day {
                min-height: 80px;
                padding: 0.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .calendar-legend {
                flex-direction: column;
                gap: 1rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .calendar-day {
                min-height: 60px;
                padding: 0.25rem;
            }
            
            .day-sessions {
                gap: 0.125rem;
            }
            
            .session-item {
                padding: 0.125rem 0.25rem;
                font-size: 0.5rem;
            }
            
            .calendar-header-cell {
                padding: 0.5rem;
                font-size: 0.7rem;
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
        <!-- Calendar Header -->
        <div class="calendar-header">
            <h1 class="calendar-title">Training Calendar</h1>
            
            <div class="calendar-nav">
                <a href="?year=<?php echo $prevMonth->format('Y'); ?>&month=<?php echo $prevMonth->format('n'); ?>" class="nav-btn">
                    <i class="fas fa-chevron-left"></i>
                </a>
                
                <div class="month-year">
                    <?php echo $monthNames[$currentMonth] . ' ' . $currentYear; ?>
                </div>
                
                <a href="?year=<?php echo $nextMonth->format('Y'); ?>&month=<?php echo $nextMonth->format('n'); ?>" class="nav-btn">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            
            <div class="header-actions">
                <a href="?year=<?php echo date('Y'); ?>&month=<?php echo date('n'); ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-calendar-day"></i> Today
                </a>
                <?php if ($canManageSessions): ?>
                    <a href="create.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> New Session
                    </a>
                <?php endif; ?>
                <a href="sessions.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-list"></i> List View
                </a>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_sessions_month']; ?></div>
                <div class="stat-label">Sessions This Month</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo $stats['my_sessions_month']; ?></div>
                <div class="stat-label">My Sessions</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['upcoming_sessions']; ?></div>
                <div class="stat-label">Upcoming</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-value"><?php echo $stats['today_sessions']; ?></div>
                <div class="stat-label">Today</div>
            </div>
        </div>
        
        <!-- Calendar -->
        <div class="calendar-container">
            <div class="calendar-grid">
                <!-- Calendar Headers -->
                <div class="calendar-header-cell">Monday</div>
                <div class="calendar-header-cell">Tuesday</div>
                <div class="calendar-header-cell">Wednesday</div>
                <div class="calendar-header-cell">Thursday</div>
                <div class="calendar-header-cell">Friday</div>
                <div class="calendar-header-cell">Saturday</div>
                <div class="calendar-header-cell">Sunday</div>
                
                <!-- Calendar Days -->
                <?php
                $current = clone $calendarStart;
                $today = date('Y-m-d');
                
                while ($current <= $calendarEnd):
                    $dateStr = $current->format('Y-m-d');
                    $dayNumber = $current->format('j');
                    $isCurrentMonth = $current->format('n') == $currentMonth;
                    $isToday = $dateStr === $today;
                    $daySessions = $sessionsByDate[$dateStr] ?? [];
                    $hasUserSessions = false;
                    
                    // Check if user has sessions on this day
                    foreach ($daySessions as $session) {
                        if (in_array($session['session_id'], $userSessions)) {
                            $hasUserSessions = true;
                            break;
                        }
                    }
                    
                    $dayClasses = ['calendar-day'];
                    if (!$isCurrentMonth) $dayClasses[] = 'other-month';
                    if ($isToday) $dayClasses[] = 'today';
                    if (!empty($daySessions)) $dayClasses[] = 'has-sessions';
                ?>
                    <div class="<?php echo implode(' ', $dayClasses); ?>" 
                         onclick="showDayDetails('<?php echo $dateStr; ?>')">
                        <div class="day-number"><?php echo $dayNumber; ?></div>
                        
                        <?php if (!empty($daySessions)): ?>
                            <div class="day-sessions">
                                <?php 
                                $displayCount = min(3, count($daySessions));
                                for ($i = 0; $i < $displayCount; $i++):
                                    $session = $daySessions[$i];
                                    $isMySession = in_array($session['session_id'], $userSessions);
                                    
                                    $sessionClasses = ['session-item'];
                                    if ($isMySession) $sessionClasses[] = 'my-session';
                                    $sessionClasses[] = 'status-' . $session['status'];
                                ?>
                                    <div class="<?php echo implode(' ', $sessionClasses); ?>" 
                                         onclick="event.stopPropagation(); showSessionModal(<?php echo $session['session_id']; ?>)"
                                         title="<?php echo htmlspecialchars($session['title']); ?>">
                                        <?php echo date('g:i A', strtotime($session['session_date'])); ?> - 
                                        <?php echo htmlspecialchars(substr($session['title'], 0, 15)); ?>
                                        <?php if (strlen($session['title']) > 15) echo '...'; ?>
                                    </div>
                                <?php endfor; ?>
                                
                                <?php if (count($daySessions) > 3): ?>
                                    <div class="more-sessions">
                                        +<?php echo count($daySessions) - 3; ?> more
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php
                    $current->modify('+1 day');
                endwhile;
                ?>
            </div>
            
            <!-- Calendar Legend -->
            <div class="calendar-legend">
                <div class="legend-item">
                    <div class="legend-color regular"></div>
                    <span>Regular Sessions</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color my-session"></div>
                    <span>My Sessions</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color completed"></div>
                    <span>Completed</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color cancelled"></div>
                    <span>Cancelled</span>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Session Modal -->
    <div class="session-modal" id="sessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Session Details</h3>
                <button class="modal-close" onclick="closeSessionModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>
    
    <script>
        // Session data for JavaScript
        const sessions = <?php echo json_encode($sessions); ?>;
        const userSessions = <?php echo json_encode($userSessions); ?>;
        
        // Show session modal
        function showSessionModal(sessionId) {
            const session = sessions.find(s => s.session_id == sessionId);
            if (!session) return;
            
            const isMySession = userSessions.includes(sessionId);
            const sessionDate = new Date(session.session_date);
            
            const modalBody = document.getElementById('modalBody');
            modalBody.innerHTML = `
                <div class="session-details">
                    <h4 style="color: var(--primary-color); margin-bottom: 1rem; font-size: 1.2rem;">
                        ${escapeHtml(session.title)}
                    </h4>
                    
                    <div class="session-detail">
                        <i class="fas fa-calendar-alt detail-icon"></i>
                        <span>${sessionDate.toLocaleDateString()} at ${sessionDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                    </div>
                    
                    ${session.instructor_first_name ? `
                        <div class="session-detail">
                            <i class="fas fa-user-tie detail-icon"></i>
                            <span>Instructor: ${escapeHtml(session.instructor_first_name + ' ' + session.instructor_last_name)}</span>
                        </div>
                    ` : ''}
                    
                    ${session.location ? `
                        <div class="session-detail">
                            <i class="fas fa-map-marker-alt detail-icon"></i>
                            <span>${escapeHtml(session.location)}</span>
                        </div>
                    ` : ''}
                    
                    <div class="session-detail">
                        <i class="fas fa-clock detail-icon"></i>
                        <span>${session.duration_minutes} minutes</span>
                    </div>
                    
                    <div class="session-detail">
                        <i class="fas fa-users detail-icon"></i>
                        <span>${session.registered_count} registered</span>
                    </div>
                    
                    <div class="session-detail">
                        <i class="fas fa-info-circle detail-icon"></i>
                        <span>Status: <span class="status-badge status-${session.status}">${session.status.charAt(0).toUpperCase() + session.status.slice(1)}</span></span>
                    </div>
                    
                    ${isMySession ? `
                        <div style="background: rgba(0, 184, 148, 0.1); padding: 0.75rem; border-radius: 6px; margin: 1rem 0; text-align: center; color: var(--success-color); font-weight: 600;">
                            <i class="fas fa-check-circle"></i> You are registered for this session
                        </div>
                    ` : ''}
                    
                    ${session.description ? `
                        <div class="session-description">
                            ${escapeHtml(session.description).replace(/\\n/g, '<br>')}
                        </div>
                    ` : ''}
                    
                    <div style="margin-top: 1.5rem; text-align: center;">
                        <a href="sessions.php" class="btn btn-outline btn-sm">
                            <i class="fas fa-list"></i> View All Sessions
                        </a>
                        ${<?php echo $canManageSessions ? 'true' : 'false'; ?> ? `
                            <a href="attendance.php?session_id=${session.session_id}" class="btn btn-primary btn-sm">
                                <i class="fas fa-clipboard-list"></i> Manage Attendance
                            </a>
                        ` : ''}
                    </div>
                </div>
            `;
            
            document.getElementById('sessionModal').classList.add('show');
        }
        
        // Close session modal
        function closeSessionModal() {
            document.getElementById('sessionModal').classList.remove('show');
        }
        
        // Show day details (could be expanded to show all sessions for that day)
        function showDayDetails(dateStr) {
            const daySessions = sessions.filter(s => s.session_date.startsWith(dateStr));
            if (daySessions.length === 1) {
                showSessionModal(daySessions[0].session_id);
            } else if (daySessions.length > 1) {
                // Could implement a day view modal here
                console.log('Multiple sessions on this day:', daySessions);
            }
        }
        
        // Escape HTML for security
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Close modal when clicking outside
        document.getElementById('sessionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSessionModal();
            }
        });
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSessionModal();
            }
            
            // Arrow key navigation for calendar
            if (e.key === 'ArrowLeft' && e.ctrlKey) {
                e.preventDefault();
                window.location.href = `?year=<?php echo $prevMonth->format('Y'); ?>&month=<?php echo $prevMonth->format('n'); ?>`;
            } else if (e.key === 'ArrowRight' && e.ctrlKey) {
                e.preventDefault();
                window.location.href = `?year=<?php echo $nextMonth->format('Y'); ?>&month=<?php echo $nextMonth->format('n'); ?>`;
            }
        });
        
        // Add hover effects to calendar days
        document.querySelectorAll('.calendar-day').forEach(day => {
            day.addEventListener('mouseenter', function() {
                if (this.classList.contains('has-sessions')) {
                    this.style.transform = 'scale(1.02)';
                    this.style.zIndex = '10';
                }
            });
            
            day.addEventListener('mouseleave', function() {
                this.style.transform = '';
                this.style.zIndex = '';
            });
        });
        
        // Auto-refresh calendar every 5 minutes to show updated session data
        setInterval(() => {
            // Could implement auto-refresh here
            console.log('Calendar data refresh interval');
        }, 5 * 60 * 1000);
        
        // Add loading states to navigation buttons
        document.querySelectorAll('.nav-btn, .btn').forEach(button => {
            button.addEventListener('click', function() {
                if (this.tagName === 'A') {
                    this.style.opacity = '0.6';
                }
            });
        });
    </script>
</body>
</html>
