<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

// Helper function to format time as "X minutes/hours/days ago"
function timeAgo($datetime)
{
    if (!$datetime) return 'Never';

    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) {
        return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    } elseif ($diff->m > 0) {
        return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

// Only log once per hour to prevent spam on refresh
if (!isset($_SESSION['dashboard_logged_today']) || $_SESSION['dashboard_logged_today'] !== date('Y-m-d H')) {
    $action = "Admin opened dashboard";
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
    $stmt->execute([$_SESSION['user_id'], $action]);
    $_SESSION['dashboard_logged_today'] = date('Y-m-d H');
}

/* USER STATS */
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='admin'")->fetchColumn();
$totalManagers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='manager'")->fetchColumn();
$totalRegularUsers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'")->fetchColumn();

/* EGG STATS */
$total_batches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$total_eggs = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$total_chicks = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$total_balut = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;
$total_failed = $conn->query("SELECT SUM(failed_count) FROM egg")->fetchColumn() ?: 0;

// Incubation success rate
$incubation_rate = $total_eggs > 0 ? round(($total_chicks / $total_eggs) * 100, 1) : 0;

/* FETCH BATCHES */
$stmt = $conn->prepare("
SELECT e.*, u.username,
       DATEDIFF(NOW(), e.date_started_incubation) as days_in_incubation
FROM egg e
JOIN users u ON e.user_id = u.user_id
ORDER BY e.date_started_incubation DESC
LIMIT 5
");
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ACTIVITY LOGS - Real-time */
$stmt = $conn->prepare("
SELECT l.*, u.username
FROM user_activity_logs l
LEFT JOIN users u ON l.user_id = u.user_id
WHERE l.log_date IS NOT NULL
ORDER BY l.log_date DESC
LIMIT 10
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ========== ANALYTICS DATA ========== */

// 1. DAILY ACTIVITY (Last 14 days)
$dates = [];
$activityTrend = [];
$uniqueUsers = [];

for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[] = $date;
}

$checkData = $conn->query("SELECT COUNT(*) as count FROM user_activity_logs WHERE log_date IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
$hasLogData = $checkData['count'] > 0;

$stmt = $conn->prepare("
    SELECT DATE(log_date) as date, 
           COUNT(*) as count,
           COUNT(DISTINCT user_id) as unique_users
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      AND log_date IS NOT NULL
    GROUP BY DATE(log_date)
    ORDER BY date
");
$stmt->execute();
$dailyStats = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dailyStats[$row['date']] = [
        'count' => (int)$row['count'],
        'unique_users' => (int)$row['unique_users']
    ];
}

$totalActivity = 0;
$daysWithData = 0;

foreach ($dates as $date) {
    $count = isset($dailyStats[$date]) ? $dailyStats[$date]['count'] : 0;
    $unique = isset($dailyStats[$date]) ? $dailyStats[$date]['unique_users'] : 0;

    $activityTrend[] = $count;
    $uniqueUsers[] = $unique;

    if ($count > 0) {
        $totalActivity += $count;
        $daysWithData++;
    }
}

$avgDailyActivity = $daysWithData > 0 ? round($totalActivity / $daysWithData, 1) : 0;

// 2. HOURLY ACTIVITY PATTERN
$stmt = $conn->prepare("
    SELECT HOUR(log_date) as hour, 
           COUNT(*) as count
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND log_date IS NOT NULL
    GROUP BY HOUR(log_date)
    ORDER BY hour
");
$stmt->execute();
$hourlyActivity = array_fill(0, 24, 0);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hourlyActivity[(int)$row['hour']] = (int)$row['count'];
}

// 3. TOP ACTIONS
$stmt = $conn->prepare("
    SELECT action, 
           COUNT(*) as total_count,
           COUNT(DISTINCT user_id) as unique_users,
           MAX(log_date) as last_performed
    FROM user_activity_logs
    WHERE log_date IS NOT NULL
    GROUP BY action
    ORDER BY total_count DESC
    LIMIT 8
");
$stmt->execute();
$actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. USER ENGAGEMENT METRICS
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT user_id) as active_users_7d,
        COUNT(*) as total_actions_7d,
        COALESCE(AVG(daily_actions), 0) as avg_actions_per_user
    FROM (
        SELECT user_id, 
               COUNT(*) as daily_actions
        FROM user_activity_logs
        WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          AND log_date IS NOT NULL
        GROUP BY user_id, DATE(log_date)
    ) as daily_user_actions
");
$stmt->execute();
$engagementMetrics = $stmt->fetch(PDO::FETCH_ASSOC);

// 5. EGG BATCH ANALYTICS
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_batches,
        SUM(total_egg) as total_eggs,
        SUM(chick_count) as total_chicks,
        SUM(balut_count) as total_balut,
        SUM(failed_count) as total_failed,
        AVG(chick_count / total_egg * 100) as avg_success_rate
    FROM egg
    WHERE total_egg > 0
");
$stmt->execute();
$eggAnalytics = $stmt->fetch(PDO::FETCH_ASSOC);

// 6. BATCH STATUS DISTRIBUTION
$stmt = $conn->prepare("
    SELECT status, COUNT(*) as count 
    FROM egg 
    GROUP BY status
");
$stmt->execute();
$batchStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$incubatingBatches = 0;
$completeBatches = 0;
foreach ($batchStatus as $status) {
    if ($status['status'] == 'incubating') $incubatingBatches = $status['count'];
    if ($status['status'] == 'complete') $completeBatches = $status['count'];
}

// 7. USER ROLE DISTRIBUTION
$stmt = $conn->prepare("
    SELECT user_role, COUNT(*) as count 
    FROM users 
    GROUP BY user_role
");
$stmt->execute();
$userRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$adminCount = 0;
$managerCount = 0;
$userCount = 0;
foreach ($userRoles as $role) {
    if ($role['user_role'] == 'admin') $adminCount = $role['count'];
    if ($role['user_role'] == 'manager') $managerCount = $role['count'];
    if ($role['user_role'] == 'user') $userCount = $role['count'];
}

// 8. TOP PERFORMING USERS (by chicks hatched)
$stmt = $conn->prepare("
    SELECT u.username, 
           COUNT(e.egg_id) as batch_count,
           SUM(e.total_egg) as total_eggs,
           SUM(e.chick_count) as total_chicks,
           ROUND(SUM(e.chick_count) / SUM(e.total_egg) * 100, 1) as success_rate
    FROM users u
    JOIN egg e ON u.user_id = e.user_id
    WHERE e.total_egg > 0
    GROUP BY u.user_id
    ORDER BY total_chicks DESC
    LIMIT 5
");
$stmt->execute();
$topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 9. Performance metrics
$currentHour = (int)date('H');
$peakHour = array_search(max($hourlyActivity), $hourlyActivity);
$peakActivity = max($hourlyActivity);

// Format dates for JavaScript
$formattedDates = array_map(function ($date) {
    return date('M d', strtotime($date));
}, $dates);

// Get active tab from URL or default to 'dashboard'
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard | EggFlow</title>

    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(195deg, #1e293b, #0f172a);
            color: white;
            padding: 2rem 1.5rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 2rem;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar h2 i {
            color: #10b981;
        }

        .sidebar ul {
            list-style: none;
        }

        .sidebar li {
            margin-bottom: 0.5rem;
        }

        .sidebar a {
            color: #cbd5e1;
            text-decoration: none;
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
            cursor: pointer;
        }

        .sidebar a i {
            width: 20px;
            font-size: 1.1rem;
        }

        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(5px);
        }

        .sidebar li.active a {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border-left: 3px solid #10b981;
        }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: #1e293b;
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.75rem;
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title h1 {
            font-size: 1.875rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .date-badge {
            background: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 100px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.2);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #3b82f6);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #10b98120, #3b82f620);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #10b981;
            font-size: 1.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.25rem;
        }

        .stat-trend {
            font-size: 0.875rem;
            color: #10b981;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Tab Content visibility */
        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Chart Grid */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #0f172a;
        }

        .chart-subtitle {
            font-size: 0.875rem;
            color: #64748b;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Analytics Cards */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .analytics-card {
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            padding: 1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .analytics-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #10b981;
        }

        .analytics-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        /* Tables */
        .table-container {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .table-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #0f172a;
        }

        .table-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .btn-primary {
            background: #10b981;
            color: white;
        }

        .btn-primary:hover {
            background: #059669;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }

        th {
            text-align: left;
            padding: 1rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 1rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        tr:hover td {
            background: #f8fafc;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background: #fef9c3;
            color: #854d0e;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }

        .status-active {
            background: #10b981;
            box-shadow: 0 0 0 2px #10b98120;
        }

        .frequency-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 120px;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            position: relative;
        }

        .frequency-fill {
            height: 100%;
            background: #10b981;
            border-radius: 3px;
        }

        .frequency-bar span {
            font-size: 0.75rem;
            color: #64748b;
            margin-left: 0.5rem;
        }

        .activity-time {
            font-size: 0.875rem;
            color: #64748b;
        }

        /* Reports specific styles */
        .report-filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .report-filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .report-filter-group label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #475569;
        }

        .report-filter-group select,
        .report-filter-group input {
            padding: 0.5rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.875rem;
            font-family: inherit;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            padding: 1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .kpi-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #10b981;
        }

        .kpi-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-top: 4rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem;
            }

            .page-title h1 {
                font-size: 1.5rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .chart-container {
                height: 250px;
            }

            .table-container {
                padding: 1rem;
            }

            th,
            td {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .report-filters {
                flex-direction: column;
            }

            .report-filter-group {
                width: 100%;
            }

            .report-filter-group select,
            .report-filter-group input {
                flex: 1;
            }
        }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-top-color: #10b981;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Toast notification */
        .toast-notification {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: #1e293b;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-size: 0.875rem;
            z-index: 2000;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <h2>
                <i class="fas fa-egg"></i>
                EggFlow
            </h2>
            <ul>
                <li class="<?= $activeTab == 'dashboard' ? 'active' : '' ?>">
                    <a onclick="switchTab('dashboard')">
                        <i class="fas fa-tachometer-alt"></i>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="../users/dashboard.php">
                        <i class="fas fa-users"></i>
                        User Management
                    </a>
                </li>
                <li class="<?= $activeTab == 'analytics' ? 'active' : '' ?>">
                    <a onclick="switchTab('analytics')">
                        <i class="fas fa-chart-line"></i>
                        Analytics
                    </a>
                </li>
                <li class="<?= $activeTab == 'reports' ? 'active' : '' ?>">
                    <a onclick="switchTab('reports')">
                        <i class="fas fa-file-alt"></i>
                        Reports
                    </a>
                </li>
                <li>
                    <a href="../../controller/auth/signout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1 id="pageTitle">Dashboard</h1>
                    <p id="pageSubtitle">
                        <i class="fas fa-chart-pie"></i>
                        System overview and key metrics
                    </p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar"></i>
                    <?= date('l, F j, Y') ?>
                </div>
            </div>

            <!-- Quick Stats (visible on all tabs) -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Users</span>
                        <span class="stat-icon">
                            <i class="fas fa-users"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($totalUsers) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-user-tie"></i>
                        <?= $adminCount ?> Admins | <?= $managerCount ?> Managers
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Active Today</span>
                        <span class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= !empty($activityTrend) ? end($activityTrend) : 0 ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-clock"></i>
                        Peak at <?= $peakHour !== false ? str_pad($peakHour, 2, '0', STR_PAD_LEFT) : '00' ?>:00
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Egg Batches</span>
                        <span class="stat-icon">
                            <i class="fas fa-box"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($total_batches) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-egg"></i>
                        <?= number_format($total_eggs) ?> total eggs
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Incubation Rate</span>
                        <span class="stat-icon">
                            <i class="fas fa-hat-wizard"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= $incubation_rate ?>%</div>
                    <div class="stat-trend">
                        <i class="fas fa-chart-line"></i>
                        <?= number_format($total_chicks) ?> chicks hatched
                    </div>
                </div>
            </div>

            <!-- ==================== DASHBOARD TAB ==================== -->
            <div id="dashboardTab" class="tab-pane <?= $activeTab == 'dashboard' ? 'active' : '' ?>">
                <!-- Recent Batches -->
                <div class="table-container">
                    <div class="table-header">
                        <h3 class="table-title">Recent Egg Batches</h3>
                        <a href="batches.php" class="btn btn-outline">
                            <i class="fas fa-external-link-alt"></i>
                            View All
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Batch #</th>
                                    <th>User</th>
                                    <th>Total Eggs</th>
                                    <th>Chicks</th>
                                    <th>Status</th>
                                    <th>Days in Incubation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($batches)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: #64748b; padding: 2rem;">
                                            <i class="fas fa-info-circle"></i> No egg batches available
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($batches as $batch): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($batch['batch_number'] ?? $batch['egg_id']) ?></strong></td>
                                            <td>
                                                <span class="status-indicator status-active"></span>
                                                <?= htmlspecialchars($batch['username']) ?>
                    </div>
                    <td><?= number_format($batch['total_egg']) ?></td>
                    <td><?= number_format($batch['chick_count']) ?></td>
                    <td>
                        <?php
                                        $statusClass = '';
                                        $statusIcon = '';
                                        $status = $batch['status'] ?? 'incubating';
                                        switch ($status) {
                                            case 'incubating':
                                                $statusClass = 'badge-success';
                                                $statusIcon = 'fa-spinner';
                                                break;
                                            case 'complete':
                                                $statusClass = 'badge-info';
                                                $statusIcon = 'fa-check-circle';
                                                break;
                                            default:
                                                $statusClass = 'badge-warning';
                                                $statusIcon = 'fa-clock';
                                        }
                        ?>
                        <span class="badge <?= $statusClass ?>">
                            <i class="fas <?= $statusIcon ?>"></i>
                            <?= ucfirst($status) ?>
                        </span>
                </div>
                <td>
                    <?= $batch['days_in_incubation'] ?? 0 ?> days
            </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- Recent Activity Logs -->
<div class="table-container">
    <div class="table-header">
        <h3 class="table-title">Recent Activity</h3>
        <div class="table-actions">
            <button class="btn btn-outline" onclick="refreshLogs()">
                <i class="fas fa-sync-alt"></i>
                Refresh
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table" id="activityLogsTable">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
    </div>
    </thead>
    <tbody id="activityLogsBody">
        <?php if (empty($logs)): ?>
            <tr>
                <td colspan="4" style="text-align: center; color: #64748b; padding: 2rem;">
                    <i class="fas fa-info-circle"></i> No recent activity
</div>
</div>
<?php else: ?>
    <?php foreach ($logs as $log): ?>
        <tr>
            <td class="activity-time" data-timestamp="<?= htmlspecialchars($log['log_date']) ?>">
                <?= timeAgo($log['log_date']) ?>
                </div>
            <td><?= htmlspecialchars($log['username'] ?? 'System') ?> </div>
            <td>
                <span class="badge badge-success">
                    <?= htmlspecialchars($log['action']) ?>
                </span>
                </div>
            <td>
                <i class="fas fa-info-circle" style="color: #94a3b8;"></i>
                <?= isset($log['details']) ? htmlspecialchars($log['details']) : '—' ?>
                </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        </table>
        </div>
        </div>
        </div>

        <!-- ==================== ANALYTICS TAB ==================== -->
        <div id="analyticsTab" class="tab-pane <?= $activeTab == 'analytics' ? 'active' : '' ?>">
            <!-- Egg Analytics Summary -->
            <div class="analytics-grid">
                <div class="analytics-card">
                    <div class="analytics-value"><?= number_format($eggAnalytics['total_batches'] ?? 0) ?></div>
                    <div class="analytics-label">Total Batches</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-value"><?= number_format($eggAnalytics['total_eggs'] ?? 0) ?></div>
                    <div class="analytics-label">Total Eggs</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-value"><?= number_format($eggAnalytics['total_chicks'] ?? 0) ?></div>
                    <div class="analytics-label">Chicks Hatched</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-value"><?= number_format($eggAnalytics['total_balut'] ?? 0) ?></div>
                    <div class="analytics-label">Balut Count</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-value"><?= number_format($eggAnalytics['total_failed'] ?? 0) ?></div>
                    <div class="analytics-label">Failed Eggs</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-value"><?= round($eggAnalytics['avg_success_rate'] ?? 0, 1) ?>%</div>
                    <div class="analytics-label">Avg Success Rate</div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="chart-grid">
                <!-- Daily Activity Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title">Daily Activity Trend</h3>
                            <p class="chart-subtitle">Last 14 days • Avg <?= $avgDailyActivity ?> actions/day</p>
                        </div>
                        <div class="table-actions">
                            <button class="btn btn-outline" onclick="toggleChartType('daily')">
                                <i class="fas fa-chart-bar"></i>
                            </button>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="dailyActivityChart"></canvas>
                    </div>
                </div>

                <!-- Hourly Activity Pattern -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title">Activity by Hour</h3>
                            <p class="chart-subtitle">24-hour pattern • Peak at <?= $peakHour !== false ? date('g A', mktime($peakHour, 0, 0)) : 'N/A' ?></p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="hourlyActivityChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Batch Status & User Role Distribution -->
            <div class="chart-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Batch Status Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="batchStatusChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">User Role Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="userRoleChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Actions Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">Top User Actions</h3>
                    <div class="table-actions">
                        <button class="btn btn-outline" onclick="exportTopActions()">
                            <i class="fas fa-download"></i>
                            Export
                        </button>
                        <button class="btn btn-outline" onclick="refreshTopActions()">
                            <i class="fas fa-sync-alt"></i>
                            Refresh
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="data-table" id="topActionsTable">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Total</th>
                                <th>Unique Users</th>
                                <th>Last Performed</th>
                                <th>Frequency</th>
                </div>
                </thead>
                <tbody id="topActionsBody">
                    <?php if (empty($actionStats)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #64748b; padding: 2rem;">
                                <i class="fas fa-info-circle"></i> No activity data available
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($actionStats as $action): ?>
        <tr>
            <td>
                <span class="badge badge-info"><?= htmlspecialchars($action['action']) ?></span>
                </div>
            <td><strong><?= number_format($action['total_count']) ?></strong> </div>
            <td><?= $action['unique_users'] ?> </div>
            <td class="activity-time" data-timestamp="<?= htmlspecialchars($action['last_performed']) ?>">
                <?= timeAgo($action['last_performed']) ?>
                </div>
            <td>
                <div class="frequency-bar">
                    <div class="frequency-fill" style="width: <?= min(100, ($action['total_count'] / $actionStats[0]['total_count'] * 100)) ?>%;"></div>
                    <span><?= round(($action['total_count'] / $actionStats[0]['total_count'] * 100), 1) ?>%</span>
                </div>
                </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        </table>
        </div>
        </div>

        <!-- Top Performing Users -->
        <div class="table-container">
            <div class="table-header">
                <h3 class="table-title">Top Performing Users</h3>
                <div class="table-actions">
                    <button class="btn btn-outline" onclick="exportTopUsers()">
                        <i class="fas fa-download"></i>
                        Export
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Batches</th>
                            <th>Total Eggs</th>
                            <th>Chicks Hatched</th>
                            <th>Success Rate</th>
            </div>
            </thead>
            <tbody>
                <?php if (empty($topUsers)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #64748b; padding: 2rem;">
                            <i class="fas fa-info-circle"></i> No user data available
        </div>
        </div>
    <?php else: ?>
        <?php foreach ($topUsers as $user): ?>
        <tr>
            <td>
                <i class="fas fa-user-circle"></i>
                <?= htmlspecialchars($user['username']) ?>
                </div>
            <td><?= $user['batch_count'] ?> </div>
            <td><?= number_format($user['total_eggs']) ?> </div>
            <td><?= number_format($user['total_chicks']) ?> </div>
            <td>
                <span class="badge badge-success"><?= $user['success_rate'] ?>%</span>
                </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        </table>
        </div>
        </div>
        </div>

        <!-- ==================== REPORTS TAB ==================== -->
        <div id="reportsTab" class="tab-pane <?= $activeTab == 'reports' ? 'active' : '' ?>">
            <!-- Report Filters -->
            <div class="report-filters">
                <div class="report-filter-group">
                    <label>Report Type:</label>
                    <select id="reportType">
                        <option value="activity">Activity Report</option>
                        <option value="users">User Report</option>
                        <option value="batches">Batches Report</option>
                        <option value="performance">Performance Report</option>
                    </select>
                </div>
                <div class="report-filter-group">
                    <label>Date Range:</label>
                    <select id="dateRange">
                        <option value="today">Today</option>
                        <option value="week">Last 7 Days</option>
                        <option value="month" selected>Last 30 Days</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>
                <div class="report-filter-group" id="customDateRange" style="display: none;">
                    <label>From:</label>
                    <input type="date" id="startDate">
                    <label>To:</label>
                    <input type="date" id="endDate">
                </div>
                <button class="btn btn-primary" onclick="generateReport()">
                    <i class="fas fa-chart-bar"></i> Generate Report
                </button>
                <button class="btn btn-outline" onclick="exportReport()">
                    <i class="fas fa-download"></i> Export
                </button>
                <button class="btn btn-outline" onclick="printReport()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>

            <!-- KPI Summary Cards -->
            <div class="kpi-grid" id="kpiGrid">
                <div class="kpi-card">
                    <div class="kpi-value" id="totalActions"><?= array_sum($activityTrend) ?></div>
                    <div class="kpi-label">Total Actions</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" id="avgDaily"><?= $avgDailyActivity ?></div>
                    <div class="kpi-label">Avg Daily Actions</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" id="activeUsers"><?= $engagementMetrics['active_users_7d'] ?? 0 ?></div>
                    <div class="kpi-label">Active Users (7d)</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" id="peakHour"><?= $peakHour !== false ? date('g A', mktime($peakHour, 0, 0)) : 'N/A' ?></div>
                    <div class="kpi-label">Peak Activity Hour</div>
                </div>
            </div>

            <!-- Report Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title" id="reportChartTitle">Activity Report</h3>
                    <div class="table-actions">
                        <button class="btn btn-outline" onclick="toggleReportChartType()">
                            <i class="fas fa-chart-line"></i> Toggle View
                        </button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="reportChart"></canvas>
                </div>
            </div>

            <!-- Report Data Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title" id="reportTableTitle">Activity Details</h3>
                </div>
                <div class="table-responsive">
                    <table class="data-table" id="reportTable">
                        <thead id="reportTableHeader">
                            <tr>
                                <th>Date</th>
                                <th>Total Activities</th>
                                <th>Unique Users</th>
                </div>
                </thead>
                <tbody id="reportTableBody">
                    <?php for ($i = 0; $i < count($formattedDates); $i++): ?>
                        <tr>
                            <td><?= $formattedDates[$i] ?>
            </div>
            <td><?= $activityTrend[$i] ?? 0 ?> </div>
            <td><?= $uniqueUsers[$i] ?? 0 ?> </div>
                </div>
            <?php endfor; ?>
            </tbody>
            </table>
            </div>
            </div>
            </div>
            </main>
            </div>

            <script>
                // Chart instances
                let dailyChart = null;
                let hourlyChart = null;
                let batchStatusChart = null;
                let userRoleChart = null;
                let reportChart = null;
                let currentChartType = 'line';

                // Pass PHP data to JavaScript
                const chartData = {
                    dates: <?= json_encode($formattedDates) ?>,
                    activityTrend: <?= json_encode($activityTrend) ?>,
                    uniqueUsers: <?= json_encode($uniqueUsers) ?>,
                    hourlyActivity: <?= json_encode(array_values($hourlyActivity)) ?>,
                    peakHour: <?= $peakHour !== false ? $peakHour : 0 ?>,
                    actionStats: <?= json_encode($actionStats) ?>,
                    batchStatus: {
                        incubating: <?= $incubatingBatches ?>,
                        complete: <?= $completeBatches ?>
                    },
                    userRoles: {
                        admin: <?= $adminCount ?>,
                        manager: <?= $managerCount ?>,
                        user: <?= $userCount ?>
                    }
                };

                // Tab switching function
                function switchTab(tabName) {
                    // Update URL without reload
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', tabName);
                    window.history.pushState({}, '', url);

                    // Update active states in sidebar
                    document.querySelectorAll('.sidebar li').forEach(li => {
                        li.classList.remove('active');
                    });

                    // Find and activate the correct sidebar item
                    const sidebarItems = document.querySelectorAll('.sidebar li');
                    if (tabName === 'dashboard') sidebarItems[0].classList.add('active');
                    if (tabName === 'analytics') sidebarItems[2].classList.add('active');
                    if (tabName === 'reports') sidebarItems[3].classList.add('active');

                    // Update tab panes
                    document.querySelectorAll('.tab-pane').forEach(pane => {
                        pane.classList.remove('active');
                    });
                    document.getElementById(tabName + 'Tab').classList.add('active');

                    // Update page title
                    const titles = {
                        dashboard: {
                            title: 'Dashboard',
                            subtitle: 'System overview and key metrics',
                            icon: 'chart-pie'
                        },
                        analytics: {
                            title: 'Analytics',
                            subtitle: 'Comprehensive system analytics and insights',
                            icon: 'chart-line'
                        },
                        reports: {
                            title: 'Reports',
                            subtitle: 'Generate and export detailed reports',
                            icon: 'file-alt'
                        }
                    };
                    document.getElementById('pageTitle').innerText = titles[tabName].title;
                    document.getElementById('pageSubtitle').innerHTML = `<i class="fas fa-${titles[tabName].icon}"></i> ${titles[tabName].subtitle}`;

                    // Re-render charts if needed
                    if (tabName === 'analytics') {
                        setTimeout(() => {
                            if (dailyChart) dailyChart.resize();
                            if (hourlyChart) hourlyChart.resize();
                            if (batchStatusChart) batchStatusChart.resize();
                            if (userRoleChart) userRoleChart.resize();
                        }, 100);
                    }
                    if (tabName === 'reports') {
                        setTimeout(() => {
                            if (reportChart) reportChart.resize();
                        }, 100);
                    }

                    // Close mobile sidebar if open
                    document.getElementById('sidebar').classList.remove('active');
                }

                // Initialize all charts
                function initializeCharts() {
                    initializeDailyChart();
                    initializeHourlyChart();
                    initializeBatchStatusChart();
                    initializeUserRoleChart();
                    initializeReportChart();
                }

                function initializeDailyChart() {
                    const ctx = document.getElementById('dailyActivityChart').getContext('2d');
                    if (dailyChart) dailyChart.destroy();

                    dailyChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: chartData.dates,
                            datasets: [{
                                label: 'Total Activities',
                                data: chartData.activityTrend,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 3,
                                pointBackgroundColor: '#10b981',
                                pointBorderColor: 'white',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                tension: 0.4,
                                fill: true
                            }, {
                                label: 'Unique Users',
                                data: chartData.uniqueUsers,
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                borderWidth: 2,
                                borderDash: [5, 5],
                                pointRadius: 3,
                                tension: 0.4,
                                yAxisID: 'y1'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        usePointStyle: true,
                                        boxWidth: 6
                                    }
                                },
                                tooltip: {
                                    backgroundColor: '#1e293b',
                                    titleColor: '#f8fafc',
                                    bodyColor: '#cbd5e1',
                                    padding: 12,
                                    cornerRadius: 8
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: '#f1f5f9'
                                    },
                                    title: {
                                        display: true,
                                        text: 'Total Activities'
                                    }
                                },
                                y1: {
                                    beginAtZero: true,
                                    position: 'right',
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    title: {
                                        display: true,
                                        text: 'Unique Users'
                                    }
                                }
                            }
                        }
                    });
                }

                function initializeHourlyChart() {
                    const ctx = document.getElementById('hourlyActivityChart').getContext('2d');
                    if (hourlyChart) hourlyChart.destroy();

                    const hourLabels = Array.from({
                        length: 24
                    }, (_, i) => `${String(i).padStart(2, '0')}:00`);

                    hourlyChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: hourLabels,
                            datasets: [{
                                label: 'Activities',
                                data: chartData.hourlyActivity,
                                backgroundColor: (context) => {
                                    const value = context.dataset.data[context.dataIndex];
                                    const max = Math.max(...context.dataset.data);
                                    const opacity = 0.3 + (value / max) * 0.7;
                                    return `rgba(16, 185, 129, ${opacity})`;
                                },
                                borderRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        title: (context) => `Hour: ${context[0].label}`
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: '#f1f5f9'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                function initializeBatchStatusChart() {
                    const ctx = document.getElementById('batchStatusChart').getContext('2d');
                    if (batchStatusChart) batchStatusChart.destroy();

                    batchStatusChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Incubating', 'Complete'],
                            datasets: [{
                                data: [chartData.batchStatus.incubating, chartData.batchStatus.complete],
                                backgroundColor: ['#10b981', '#3b82f6'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (context) => `${context.label}: ${context.raw} batches`
                                    }
                                }
                            }
                        }
                    });
                }

                function initializeUserRoleChart() {
                    const ctx = document.getElementById('userRoleChart').getContext('2d');
                    if (userRoleChart) userRoleChart.destroy();

                    userRoleChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: ['Admin', 'Manager', 'User'],
                            datasets: [{
                                data: [chartData.userRoles.admin, chartData.userRoles.manager, chartData.userRoles.user],
                                backgroundColor: ['#ef4444', '#f59e0b', '#10b981'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (context) => `${context.label}: ${context.raw} users`
                                    }
                                }
                            }
                        }
                    });
                }

                function initializeReportChart() {
                    const ctx = document.getElementById('reportChart').getContext('2d');
                    if (reportChart) reportChart.destroy();

                    reportChart = new Chart(ctx, {
                        type: currentChartType,
                        data: {
                            labels: chartData.dates,
                            datasets: [{
                                label: 'Activities',
                                data: chartData.activityTrend,
                                borderColor: '#10b981',
                                backgroundColor: currentChartType === 'line' ? 'rgba(16, 185, 129, 0.1)' : '#10b981',
                                borderWidth: 2,
                                tension: 0.4,
                                fill: currentChartType === 'line'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top'
                                }
                            }
                        }
                    });
                }

                function toggleChartType(type) {
                    if (dailyChart) {
                        const newType = dailyChart.config.type === 'line' ? 'bar' : 'line';
                        dailyChart.config.type = newType;
                        dailyChart.update();
                        const btn = event.currentTarget;
                        const icon = btn.querySelector('i');
                        if (icon) icon.className = newType === 'line' ? 'fas fa-chart-bar' : 'fas fa-chart-line';
                    }
                }

                function toggleReportChartType() {
                    currentChartType = currentChartType === 'line' ? 'bar' : 'line';
                    if (reportChart) {
                        reportChart.config.type = currentChartType;
                        reportChart.data.datasets[0].backgroundColor = currentChartType === 'line' ? 'rgba(16, 185, 129, 0.1)' : '#10b981';
                        reportChart.data.datasets[0].fill = currentChartType === 'line';
                        reportChart.update();
                    }
                }

                // Real-time refresh functions
                async function refreshLogs() {
                    const refreshBtn = event.currentTarget;
                    const originalHtml = refreshBtn.innerHTML;
                    refreshBtn.innerHTML = '<div class="spinner"></div> Refreshing...';
                    refreshBtn.disabled = true;

                    setTimeout(() => {
                        location.reload();
                    }, 500);
                }

                async function refreshTopActions() {
                    const refreshBtn = event.currentTarget;
                    const originalHtml = refreshBtn.innerHTML;
                    refreshBtn.innerHTML = '<div class="spinner"></div> Refreshing...';
                    refreshBtn.disabled = true;

                    setTimeout(() => {
                        location.reload();
                    }, 500);
                }

                function formatTimeAgo(datetime) {
                    if (!datetime) return 'Never';
                    const date = new Date(datetime.replace(' ', 'T'));
                    const now = new Date();
                    const diff = Math.floor((now - date) / 1000);

                    if (diff < 60) return 'Just now';
                    if (diff < 3600) return `${Math.floor(diff / 60)} minute${Math.floor(diff / 60) !== 1 ? 's' : ''} ago`;
                    if (diff < 86400) return `${Math.floor(diff / 3600)} hour${Math.floor(diff / 3600) !== 1 ? 's' : ''} ago`;
                    if (diff < 2592000) return `${Math.floor(diff / 86400)} day${Math.floor(diff / 86400) !== 1 ? 's' : ''} ago`;
                    return date.toLocaleDateString();
                }

                function formatNumber(num) {
                    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                }

                function escapeHtml(str) {
                    if (!str) return '';
                    return str.replace(/[&<>]/g, function(m) {
                        if (m === '&') return '&amp;';
                        if (m === '<') return '&lt;';
                        if (m === '>') return '&gt;';
                        return m;
                    });
                }

                function showToast(message, type = 'success') {
                    const toast = document.createElement('div');
                    toast.className = 'toast-notification';
                    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 3000);
                }

                function exportTopActions() {
                    showToast('Exporting top actions...');
                    setTimeout(() => showToast('Export complete'), 1000);
                }

                function exportTopUsers() {
                    showToast('Exporting top users...');
                    setTimeout(() => showToast('Export complete'), 1000);
                }

                // Report functions
                function generateReport() {
                    const reportType = document.getElementById('reportType').value;
                    let title = '';
                    switch (reportType) {
                        case 'activity':
                            title = 'Activity Report';
                            break;
                        case 'users':
                            title = 'User Report';
                            break;
                        case 'batches':
                            title = 'Batches Report';
                            break;
                        case 'performance':
                            title = 'Performance Report';
                            break;
                    }

                    document.getElementById('reportChartTitle').innerHTML = title;
                    document.getElementById('reportTableTitle').innerHTML = title + ' Details';

                    showToast(`Generating ${title}...`);
                    setTimeout(() => showToast('Report generated successfully'), 1000);
                }

                function exportReport() {
                    showToast('Exporting report...');
                    setTimeout(() => showToast('Report exported successfully'), 1000);
                }

                function printReport() {
                    window.print();
                }

                // Date range handling
                const dateRangeSelect = document.getElementById('dateRange');
                if (dateRangeSelect) {
                    dateRangeSelect.addEventListener('change', function() {
                        const customRange = document.getElementById('customDateRange');
                        if (customRange) {
                            customRange.style.display = this.value === 'custom' ? 'flex' : 'none';
                        }
                    });
                }

                // Mobile menu
                document.getElementById('mobileMenuBtn').addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('active');
                });

                // Update all timestamps periodically
                function updateAllTimestamps() {
                    document.querySelectorAll('.activity-time[data-timestamp]').forEach(el => {
                        const timestamp = el.dataset.timestamp;
                        if (timestamp) {
                            el.textContent = formatTimeAgo(timestamp);
                        }
                    });
                }

                // Auto-refresh timestamps every 30 seconds
                setInterval(updateAllTimestamps, 30000);

                // Initialize on load
                document.addEventListener('DOMContentLoaded', () => {
                    initializeCharts();
                    updateAllTimestamps();
                });
            </script>
</body>

</html>