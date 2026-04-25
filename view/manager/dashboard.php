<?php
require_once '../../model/config.php';

// Check if user is logged in and is manager
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'manager') {
    header("Location: ../../index.php");
    exit;
}

$manager_id = $_SESSION['user_id'];

// Get active tab from URL parameter
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$validTabs = ['overview', 'analytics', 'reports'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'overview';
}

// Helper: format date/time properly
function formatDateTime($datetime)
{
    if (!$datetime) return 'Never';
    $timestamp = strtotime($datetime);
    return date('M j, Y g:i A', $timestamp);
}

// Helper: time ago with more accurate display
function timeAgo($datetime)
{
    if (!$datetime) return 'Never';
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    elseif ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    elseif ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    elseif ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    elseif ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    else return 'Just now';
}

// Log access
$stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
$stmt->execute([$manager_id, "Manager accessed dashboard"]);

// ── Handle AJAX / POST actions ──────────────────────────────────────────────
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Handle AJAX activity refresh
if ($isAjax && isset($_GET['get_activity_ajax'])) {
    $stmt = $conn->prepare("
        SELECT l.log_date, u.username, l.action
        FROM user_activity_logs l
        JOIN users u ON l.user_id = u.user_id
        WHERE l.log_date IS NOT NULL
        ORDER BY l.log_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $freshLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $logsData = [];
    foreach ($freshLogs as $log) {
        $logsData[] = [
            'formatted_date' => formatDateTime($log['log_date']),
            'time_ago' => timeAgo($log['log_date']),
            'username' => $log['username'],
            'action' => $log['action']
        ];
    }
    echo json_encode(['logs' => $logsData]);
    exit;
}

// Create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = in_array($_POST['role'] ?? '', ['user', 'manager']) ? $_POST['role'] : 'user';
    $errors   = [];
    if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    // Check duplicate
    $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $chk->execute([$username]);
    if ($chk->fetch()) $errors[] = 'Username already exists.';
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins  = $conn->prepare("INSERT INTO users (username, password, user_role) VALUES (?, ?, ?)");
        $ins->execute([$username, $hash, $role]);
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$manager_id, "Created user: $username ($role)"]);
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'User created successfully.']);
            exit;
        }
    } else {
        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }
    }
}

// Edit user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $edit_id  = (int)($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['user', 'manager']) ? $_POST['role'] : 'user';
    $password = $_POST['password'] ?? '';
    if ($edit_id && strlen($username) >= 3) {
        if (!empty($password) && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd  = $conn->prepare("UPDATE users SET username=?, user_role=?, password=? WHERE user_id=?");
            $upd->execute([$username, $role, $hash, $edit_id]);
        } else {
            $upd = $conn->prepare("UPDATE users SET username=?, user_role=? WHERE user_id=?");
            $upd->execute([$username, $role, $edit_id]);
        }
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$manager_id, "Edited user ID: $edit_id"]);
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
            exit;
        }
    } else {
        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }
    }
}

// Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $del_id = (int)($_POST['user_id'] ?? 0);
    if ($del_id && $del_id !== $manager_id) {
        $del = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $del->execute([$del_id]);
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$manager_id, "Deleted user ID: $del_id"]);
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'User deleted.']);
            exit;
        }
    } else {
        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete this user.']);
            exit;
        }
    }
}

// Distribute profits
$distribution_message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['distribute_profits'])) {
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
    $stmt->execute([$manager_id, "Distributed profits based on balut counts"]);
    $distribution_message = "Profits distributed successfully!";
}

// Handle activity log export
if (isset($_GET['export_activity']) && $_GET['export_activity'] === 'csv') {
    // Fetch all activity logs for export (no limit)
    $stmt = $conn->prepare("
        SELECT l.log_date, u.username, l.action
        FROM user_activity_logs l
        JOIN users u ON l.user_id = u.user_id
        WHERE l.log_date IS NOT NULL
        ORDER BY l.log_date DESC
    ");
    $stmt->execute();
    $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    // Headers
    fputcsv($output, ['Date & Time', 'User', 'Action']);
    // Data rows
    foreach ($allLogs as $log) {
        fputcsv($output, [
            formatDateTime($log['log_date']),
            $log['username'],
            $log['action']
        ]);
    }
    fclose($output);
    exit;
}

// ── Fetch statistics ─────────────────────────────────────────────────────────
$totalUsers   = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs    = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks  = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut   = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;
$totalFailed  = $conn->query("SELECT SUM(failed_count) FROM egg")->fetchColumn() ?: 0;

// ── Users with summary - Keep all users for management table ─────────────────
$stmt = $conn->prepare("
    SELECT u.user_id, u.username, u.user_role, u.created_at,
           COALESCE(SUM(e.balut_count), 0)  AS total_balut,
           COALESCE(SUM(e.chick_count), 0)  AS total_chicks,
           COALESCE(SUM(e.failed_count), 0) AS total_failed,
           COALESCE(COUNT(e.egg_id), 0)     AS batch_count
    FROM users u
    LEFT JOIN egg e ON u.user_id = e.user_id
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Activity logs with proper datetime - LIMIT to 10 rows for real-time display ──
$stmt = $conn->prepare("
    SELECT l.*, u.username
    FROM user_activity_logs l
    JOIN users u ON l.user_id = u.user_id
    WHERE l.log_date IS NOT NULL
    ORDER BY l.log_date DESC
    LIMIT 10
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics: Balut per user (top 8) - FIXED: Only regular users ────────────
$stmt = $conn->prepare("
    SELECT u.username, COALESCE(SUM(e.balut_count),0) AS total_balut
    FROM users u 
    LEFT JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id 
    ORDER BY total_balut DESC 
    LIMIT 8
");
$stmt->execute();
$balutPerUser = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics: User Contribution Comparison - FIXED: Only regular users ──────
$stmt = $conn->prepare("
    SELECT u.username, 
           COALESCE(SUM(e.balut_count),0) AS total_balut,
           COALESCE(SUM(e.chick_count),0) AS total_chicks,
           COALESCE(SUM(e.failed_count),0) AS total_failed
    FROM users u 
    LEFT JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id 
    ORDER BY total_balut DESC
");
$stmt->execute();
$userContributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics: Weekly production trend (last 7 days) ─────────────────────────
$stmt = $conn->prepare("
    SELECT DATE(date_started_incubation) AS day,
           SUM(balut_count) AS balut, SUM(chick_count) AS chicks, SUM(failed_count) AS failed
    FROM egg
    WHERE date_started_incubation >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(date_started_incubation)
    ORDER BY day ASC
");
$stmt->execute();
$weeklyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Report data ───────────────────────────────────────────────────────────────
$reportData = [];
$reportType = $_GET['report'] ?? '';
$startDate  = $_GET['start'] ?? date('Y-m-01');
$endDate    = $_GET['end']   ?? date('Y-m-d');

if ($reportType === 'userSummary') {
    $stmt = $conn->prepare("
        SELECT u.username, u.user_role,
               COALESCE(SUM(e.balut_count),0)  AS total_balut,
               COALESCE(SUM(e.chick_count),0)  AS total_chicks,
               COALESCE(SUM(e.failed_count),0) AS total_failed,
               COALESCE(COUNT(e.egg_id),0)      AS batches
        FROM users u LEFT JOIN egg e ON u.user_id=e.user_id
            AND e.date_started_incubation BETWEEN ? AND ?
        WHERE u.user_role = 'user'
        GROUP BY u.user_id ORDER BY total_balut DESC
    ");
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($reportType === 'batchLog') {
    $stmt = $conn->prepare("
        SELECT e.batch_number, u.username, e.total_egg, e.status,
               e.balut_count, e.chick_count, e.failed_count,
               DATE(e.date_started_incubation) AS started
        FROM egg e JOIN users u ON e.user_id=u.user_id
        WHERE e.date_started_incubation BETWEEN ? AND ?
        ORDER BY e.batch_number DESC
    ");
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get incubating and complete batch counts for analytics
$incubating = $conn->query("SELECT COUNT(*) FROM egg WHERE status='incubating'")->fetchColumn();
$complete   = $conn->query("SELECT COUNT(*) FROM egg WHERE status='complete'")->fetchColumn();

// Get top performing users (regular users only)
$stmt = $conn->prepare("
    SELECT u.username, 
           COALESCE(SUM(e.balut_count),0) AS total_balut,
           COALESCE(COUNT(e.egg_id),0) AS batch_count
    FROM users u 
    LEFT JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id 
    ORDER BY total_balut DESC 
    LIMIT 5
");
$stmt->execute();
$topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Manager Dashboard | EggFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
            overflow-x: hidden;
        }

        /* ── Dashboard Layout ── */
        .dashboard {
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── Sidebar - Compact & Responsive ── */
        .sidebar {
            width: 240px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            transition: all 0.3s ease;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .sidebar-header p {
            font-size: 0.65rem;
            opacity: 0.7;
            margin-top: 0.25rem;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0.75rem 0;
        }

        .sidebar-menu li {
            margin: 0.15rem 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.6rem 1.2rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .sidebar-menu li.active a,
        .sidebar-menu a:hover {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 0.75rem;
            left: 0.75rem;
            z-index: 1001;
            background: #10b981;
            color: white;
            border: none;
            padding: 0.5rem 0.7rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        /* Main Content - Compact */
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 1rem;
            transition: margin-left 0.3s ease;
            width: calc(100% - 240px);
            overflow-x: auto;
        }

        /* Top Bar - Compact */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .welcome-text h1 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #0f172a;
        }

        .welcome-text p {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.2rem;
        }

        .date-badge {
            background: white;
            padding: 0.4rem 0.9rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 500;
            color: #1e293b;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        /* Tab System */
        .tab-section {
            display: none;
        }

        .tab-section.active {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Stats Grid - Compact */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 0.75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .stat-info h3 {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 0.35rem;
        }

        .stat-info p {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        /* Chart Row - Compact */
        .chart-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 0.85rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .chart-card h3 {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.65rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .chart-card h3 i {
            font-size: 0.9rem;
        }

        .chart-card canvas {
            max-height: 220px;
            width: 100% !important;
        }

        /* Action Bar - Compact */
        .action-bar {
            background: white;
            border-radius: 12px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        /* Table Container - With Scrollbar */
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 0.85rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            width: 100%;
            overflow-x: auto;
        }

        /* Scrollable table wrapper - HORIZONTAL SCROLL FIX */
        .table-scroll-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }

        .table-scroll-wrapper::-webkit-scrollbar {
            height: 6px;
            width: 6px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Fixed height scrollable wrapper for activity log - VERTICAL SCROLL */
        .activity-scroll-wrapper {
            max-height: 400px;
            overflow-y: auto;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }

        .activity-scroll-wrapper::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .activity-scroll-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .activity-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .activity-scroll-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.85rem;
            flex-wrap: wrap;
            gap: 0.6rem;
        }

        .table-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .table-header h3 i {
            font-size: 0.85rem;
        }

        .data-table {
            width: 100%;
            font-size: 0.75rem;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th {
            text-align: left;
            padding: 0.6rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            background: white;
            white-space: nowrap;
        }

        .data-table td {
            padding: 0.5rem 0.5rem;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap;
        }

        /* For smaller screens, allow text wrapping on some cells */
        @media (max-width: 768px) {
            .data-table td {
                white-space: normal;
                word-break: break-word;
            }
        }

        /* Report Controls - Compact */
        .report-controls {
            background: white;
            border-radius: 12px;
            padding: 0.85rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: flex-end;
        }

        .report-controls .form-group {
            flex: 1;
            min-width: 110px;
        }

        .report-controls label {
            font-size: 0.65rem;
            font-weight: 600;
            color: #64748b;
            display: block;
            margin-bottom: 0.2rem;
        }

        .report-controls select,
        .report-controls input[type="date"] {
            width: 100%;
            padding: 0.45rem 0.65rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.75rem;
            color: #334155;
        }

        /* Buttons - Compact */
        .btn {
            padding: 0.45rem 0.9rem;
            font-size: 0.75rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .btn-primary {
            background: #10b981;
            color: white;
        }

        .btn-primary:hover {
            background: #059669;
        }

        .btn-success {
            background: #059669;
            color: white;
        }

        .btn-success:hover {
            background: #047857;
        }

        .btn-outline {
            background: white;
            border: 1px solid #e2e8f0;
            color: #334155;
        }

        .btn-outline:hover {
            background: #f1f5f9;
        }

        /* Role Badges */
        .role-badge {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .role-badge.user {
            background: #dbeafe;
            color: #1e40af;
        }

        .role-badge.manager {
            background: #fed7aa;
            color: #92400e;
        }

        .role-badge.admin {
            background: #e9d5ff;
            color: #6b21a5;
        }

        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }

        .avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #10b981;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* Activity time styling */
        .activity-time {
            font-size: 0.7rem;
            color: #64748b;
            white-space: nowrap;
        }

        .activity-time i {
            margin-right: 0.2rem;
            font-size: 0.65rem;
        }

        .activity-time small {
            font-size: 0.65rem;
            color: #94a3b8;
        }

        /* Toast Notification */
        #toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            padding: 0.6rem 1rem;
            border-radius: 10px;
            color: white;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: none;
            align-items: center;
            gap: 0.5rem;
            animation: slideUp 0.2s ease;
        }

        #toast.show {
            display: flex;
        }

        #toast.success {
            background: #10b981;
        }

        #toast.error {
            background: #ef4444;
        }

        @keyframes slideUp {
            from {
                transform: translateY(15px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Modal - Compact */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.2s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 0.85rem 1.2rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1rem;
            margin: 0;
        }

        .close {
            font-size: 1.3rem;
            cursor: pointer;
            color: #94a3b8;
            background: none;
            border: none;
        }

        .modal-body {
            padding: 1.2rem;
        }

        .modal-footer {
            padding: 0.85rem 1.2rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.6rem;
        }

        .form-group {
            margin-bottom: 0.85rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: #334155;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.8rem;
        }

        /* Alert */
        .alert {
            padding: 0.6rem 0.9rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.8rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 3px solid #10b981;
        }

        /* Spinner */
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Badges */
        .badge-incubating {
            background: #dbeafe;
            color: #1d4ed8;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-complete {
            background: #dcfce7;
            color: #15803d;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        /* Stat card accent colors */
        .stat-card:nth-child(1) .stat-icon {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
        }

        .stat-card:nth-child(2) .stat-icon {
            background: rgba(59, 130, 246, 0.12);
            color: #3b82f6;
        }

        .stat-card:nth-child(3) .stat-icon {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
        }

        .stat-card:nth-child(4) .stat-icon {
            background: rgba(139, 92, 246, 0.12);
            color: #8b5cf6;
        }

        .stat-card:nth-child(5) .stat-icon {
            background: rgba(236, 72, 153, 0.12);
            color: #ec4899;
        }

        .stat-card:nth-child(6) .stat-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
        }

        /* Search box */
        .search-box input {
            padding: 0.4rem 0.7rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.75rem;
            width: 200px;
        }

        /* Mobile Responsive Styles - Compact */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 0.85rem;
                padding-top: 3.5rem;
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
                gap: 0.6rem;
            }

            .stat-info p {
                font-size: 1rem;
            }

            .stat-icon {
                width: 28px;
                height: 28px;
                font-size: 0.9rem;
            }

            .chart-row {
                grid-template-columns: 1fr;
                gap: 0.6rem;
            }

            .chart-card canvas {
                max-height: 200px;
            }

            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }

            .welcome-text h1 {
                font-size: 1.1rem;
            }

            .report-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .report-controls .form-group {
                min-width: auto;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .action-bar {
                flex-direction: column;
            }

            .action-bar .btn {
                width: 100%;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .search-box input {
                width: 100%;
            }

            #toast {
                bottom: 1rem;
                right: 1rem;
                left: 1rem;
                text-align: center;
                justify-content: center;
            }

            .data-table {
                min-width: 500px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem;
            }

            .chart-card {
                padding: 0.65rem;
            }

            .table-container {
                padding: 0.65rem;
            }

            .data-table th,
            .data-table td {
                padding: 0.4rem 0.35rem;
            }

            .avatar {
                width: 22px;
                height: 22px;
                font-size: 0.6rem;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chart-line"></i> EggFlow</h2>
                <p>Manager Panel</p>
            </div>
            <ul class="sidebar-menu">
                <li class="nav-item <?= $activeTab == 'overview' ? 'active' : '' ?>" data-tab="overview">
                    <a href="?tab=overview"><i class="fas fa-tachometer-alt"></i> Overview</a>
                </li>
                <li class="nav-item">
                    <a href="../users/user-management.php"><i class="fas fa-users"></i> User Management</a>
                </li>
                <li class="nav-item <?= $activeTab == 'analytics' ? 'active' : '' ?>" data-tab="analytics">
                    <a href="?tab=analytics"><i class="fas fa-chart-line"></i> Analytics</a>
                </li>
                <li class="nav-item <?= $activeTab == 'reports' ? 'active' : '' ?>" data-tab="reports">
                    <a href="?tab=reports"><i class="fas fa-file-alt"></i> Reports</a>
                </li>
                <li>
                    <a href="../../controller/auth/signout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="welcome-text">
                    <h1>Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Manager') ?></h1>
                    <p id="page-subtitle"><?= $activeTab == 'overview' ? 'Overview & metrics' : ($activeTab == 'analytics' ? 'Production analytics (Users only)' : 'Generate reports') ?></p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('M d, Y') ?>
                </div>
            </div>

            <?php if ($distribution_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($distribution_message) ?>
                </div>
            <?php endif; ?>

            <!-- ═══════════════ OVERVIEW TAB ═══════════════ -->
            <div id="overview-section" class="tab-section <?= $activeTab == 'overview' ? 'active' : '' ?>">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Users</h3>
                            <p><?= number_format($totalUsers) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Batches</h3>
                            <p><?= number_format($totalBatches) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-egg"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Eggs</h3>
                            <p><?= number_format($totalEggs) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Chicks</h3>
                            <p><?= number_format($totalChicks) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-hat-wizard"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Balut</h3>
                            <p><?= number_format($totalBalut) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-drumstick-bite"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Failed</h3>
                            <p><?= number_format($totalFailed) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    </div>
                </div>

                <!-- Users Summary Table - Scrollable horizontally -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-users"></i> Users & Balut Summary</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Batches</th>
                                    <th>Balut</th>
                                    <th>Chicks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <div class="avatar"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
                                                <div><?= htmlspecialchars($u['username']) ?></div>
                                            </div>
                    </div>
                    <td><span class="role-badge <?= $u['user_role'] ?>"><?= ucfirst($u['user_role']) ?></span>
                    <td><?= date('M d, Y', strtotime($u['created_at'])) ?>
                    <td><?= number_format($u['batch_count']) ?>
                    <td><strong><?= number_format($u['total_balut']) ?></strong>
                    <td><?= number_format($u['total_chicks']) ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            </div>

            <!-- Activity Logs Table - Scrollable with Real Time (LIMIT 10 rows, scrollable, with export button) -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-history"></i> Recent Activity - Last 10 logs</h3>
                    <button class="btn btn-outline" onclick="exportActivityCSV()">
                        <i class="fas fa-download"></i> Export All Logs
                    </button>
                </div>
                <div class="activity-scroll-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>User</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="activityLogsBody">
                            <?php if ($logs): ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="activity-time">
                                            <i class="far fa-clock"></i> <?= formatDateTime($log['log_date']) ?>
                                            <small>(<?= timeAgo($log['log_date']) ?>)</small>
                                        </td>
                                        <td><?= htmlspecialchars($log['username']) ?></td>
                                        <td><?= htmlspecialchars($log['action']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align:center">No activity logs found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    </div>

    <!-- ═══════════════ ANALYTICS TAB ═══════════════ -->
    <div id="analytics-section" class="tab-section <?= $activeTab == 'analytics' ? 'active' : '' ?>">
        <div class="stats-grid" style="margin-bottom:1rem;">
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Avg Balut/Batch</h3>
                    <p><?= $totalBatches > 0 ? number_format($totalBalut / $totalBatches, 1) : '0' ?></p>
                </div>
                <div class="stat-icon"><i class="fas fa-calculator"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Avg Chicks/Batch</h3>
                    <p><?= $totalBatches > 0 ? number_format($totalChicks / $totalBatches, 1) : '0' ?></p>
                </div>
                <div class="stat-icon"><i class="fas fa-dove"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Success Rate</h3>
                    <p><?= $totalEggs > 0 ? number_format((($totalBalut + $totalChicks) / $totalEggs) * 100, 1) : '0' ?>%</p>
                </div>
                <div class="stat-icon"><i class="fas fa-percentage"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Failure Rate</h3>
                    <p><?= $totalEggs > 0 ? number_format(($totalFailed / $totalEggs) * 100, 1) : '0' ?>%</p>
                </div>
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>

        <div class="chart-row">
            <div class="chart-card">
                <h3><i class="fas fa-chart-bar" style="color:#10b981;"></i> Balut per User (Users Only)</h3>
                <canvas id="balutChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-line" style="color:#3b82f6;"></i> Weekly Trend (Last 7 Days)</h3>
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <div class="chart-row">
            <div class="chart-card">
                <h3><i class="fas fa-chart-pie" style="color:#f59e0b;"></i> Outcome Distribution</h3>
                <canvas id="pieChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-area" style="color:#8b5cf6;"></i> Batch Status</h3>
                <canvas id="statusChart"></canvas>
                <p style="text-align:center;color:#64748b;font-size:0.7rem;margin-top:0.5rem;">
                    Incubating: <strong><?= $incubating ?></strong> &nbsp;|&nbsp; Complete: <strong><?= $complete ?></strong>
                </p>
            </div>
        </div>

        <!-- Top Performing Users Table - Scrollable horizontally -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-trophy" style="color:#f59e0b;"></i> Top Performing Users (Users Only)</h3>
            </div>
            <div class="table-scroll-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Username</th>
                            <th>Total Balut</th>
                            <th>Batches</th>
                            <th>Avg/Batch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 1;
                        foreach ($topUsers as $user):
                            $avgBalut = $user['batch_count'] > 0 ? number_format($user['total_balut'] / $user['batch_count'], 1) : 0;
                        ?>
                            <tr>
                                <td style="font-weight: bold;">#<?= $rank++ ?>
                                <td>
                                    <div class="user-info">
                                        <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div><?= htmlspecialchars($user['username']) ?>
                                    </div>
            </div>
            <td><strong><?= number_format($user['total_balut']) ?></strong>
            <td><?= number_format($user['batch_count']) ?>
            <td><?= $avgBalut ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($topUsers)): ?>
                <tr>
                    <td colspan="5" style="text-align:center">No production data available for regular users</td>
                </tr>
            <?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>

    <!-- User Contribution Comparison Table - Scrollable horizontally -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-chart-simple"></i> User Contribution (Users Only)</h3>
        </div>
        <div class="table-scroll-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Balut</th>
                        <th>Chicks</th>
                        <th>Failed</th>
                        <th>Success Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userContributions as $user):
                        $total = $user['total_balut'] + $user['total_chicks'] + $user['total_failed'];
                        $successRate = $total > 0 ? number_format((($user['total_balut'] + $user['total_chicks']) / $total) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div><?= htmlspecialchars($user['username']) ?>
                                </div>
        </div>
        <td><strong><?= number_format($user['total_balut']) ?></strong>
        <td><?= number_format($user['total_chicks']) ?>
        <td><?= number_format($user['total_failed']) ?>
        <td><?= $successRate ?>%
            </tr>
        <?php endforeach; ?>
        <?php if (empty($userContributions)): ?>
            <tr>
                <td colspan="5" style="text-align:center">No contribution data available</td>
            </tr>
        <?php endif; ?>
        </tbody>
        </table>
    </div>
    </div>
    </div>

    <!-- ═══════════════ REPORTS TAB ═══════════════ -->
    <div id="reports-section" class="tab-section <?= $activeTab == 'reports' ? 'active' : '' ?>">
        <div class="report-controls">
            <div class="form-group">
                <label>Report Type</label>
                <select id="reportType">
                    <option value="userSummary" <?= $reportType == 'userSummary' ? 'selected' : '' ?>>User Summary</option>
                    <option value="batchLog" <?= $reportType == 'batchLog' ? 'selected' : '' ?>>Batch Log</option>
                </select>
            </div>
            <div class="form-group">
                <label>Start Date</label>
                <input type="date" id="startDate" value="<?= $startDate ?>">
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="date" id="endDate" value="<?= $endDate ?>">
            </div>
            <button class="btn btn-primary" onclick="generateReport()">
                <i class="fas fa-chart-bar"></i> Generate
            </button>
            <button class="btn btn-outline" onclick="exportCSV()">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
        </div>

        <div class="table-container" id="reportPreview">
            <div class="table-header">
                <h3 id="reportTitle">Report Preview</h3>
            </div>
            <div class="table-scroll-wrapper" id="reportContent">
                <?php if ($reportData): ?>
                    <?php
                    $titles = [
                        'userSummary' => 'User Summary Report',
                        'batchLog' => 'Batch Log Report',
                    ];
                    $title = $titles[$reportType] ?? 'Report';
                    echo "<script>document.getElementById('reportTitle').innerHTML = '" . $title . "';</script>";
                    ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php foreach (array_keys($reportData[0]) as $col): ?>
                                    <th><?= ucwords(str_replace('_', ' ', $col)) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?= htmlspecialchars($value ?? '') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align:center; padding: 1.5rem; color:#94a3b8;">Select a report type and click Generate.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </main>
    </div>

    <!-- Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form id="userForm" onsubmit="saveUser(event)">
                <input type="hidden" id="editUserId" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="modalUsername" required minlength="3" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label id="passwordLabel">Password</label>
                        <input type="password" id="modalPassword" minlength="6">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select id="modalRole">
                            <option value="user">Regular User</option>
                            <option value="manager">Manager</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast"><i class="fas fa-check-circle"></i> <span id="toastMsg"></span></div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        // PHP data
        const PHP = {
            balutPerUser: <?= json_encode($balutPerUser) ?>,
            weeklyTrend: <?= json_encode($weeklyTrend) ?>,
            totalBalut: <?= (int)$totalBalut ?>,
            totalChicks: <?= (int)$totalChicks ?>,
            totalFailed: <?= (int)$totalFailed ?>,
            incubating: <?= (int)$incubating ?>,
            complete: <?= (int)$complete ?>,
        };

        // Export Activity Logs function
        function exportActivityCSV() {
            window.location.href = '?export_activity=csv';
            showToast('Exporting activity logs...', 'success');
        }

        // Mobile Menu
        function toggleMobileMenu() {
            document.getElementById('sidebar').classList.toggle('open');
            const overlay = document.getElementById('sidebarOverlay');
            overlay.style.display = document.getElementById('sidebar').classList.contains('open') ? 'block' : 'none';
        }

        function closeMobileMenu() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').style.display = 'none';
        }

        // Close mobile menu on link click
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) closeMobileMenu();
            });
        });

        // Tab navigation
        const tabTitles = {
            overview: 'Overview & metrics',
            analytics: 'Production analytics (Users only)',
            reports: 'Generate reports',
        };

        document.querySelectorAll('.nav-item[data-tab]').forEach(li => {
            li.querySelector('a').addEventListener('click', (e) => {
                e.preventDefault();
                const tab = li.dataset.tab;
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.pushState({}, '', url);

                document.querySelectorAll('.nav-item').forEach(x => x.classList.remove('active'));
                li.classList.add('active');
                document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
                document.getElementById(tab + '-section').classList.add('active');
                document.getElementById('page-subtitle').textContent = tabTitles[tab] ?? '';

                if (tab === 'analytics') initCharts();
                if (window.innerWidth <= 768) closeMobileMenu();
            });
        });

        window.addEventListener('popstate', () => {
            const params = new URLSearchParams(window.location.search);
            const tab = params.get('tab') || 'overview';
            document.querySelectorAll('.nav-item').forEach(x => x.classList.remove('active'));
            document.querySelector(`.nav-item[data-tab="${tab}"]`).classList.add('active');
            document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
            document.getElementById(tab + '-section').classList.add('active');
            document.getElementById('page-subtitle').textContent = tabTitles[tab] ?? '';
            if (tab === 'analytics') initCharts();
        });

        // Modal functions
        function openModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add User';
            document.getElementById('editUserId').value = '';
            document.getElementById('modalUsername').value = '';
            document.getElementById('modalPassword').value = '';
            document.getElementById('modalRole').value = 'user';
            document.getElementById('passwordLabel').textContent = 'Password';
            document.getElementById('modalPassword').required = true;
            document.getElementById('userModal').classList.add('active');
        }

        function openEditModal(id, username, role) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit User';
            document.getElementById('editUserId').value = id;
            document.getElementById('modalUsername').value = username;
            document.getElementById('modalPassword').value = '';
            document.getElementById('modalRole').value = role;
            document.getElementById('passwordLabel').textContent = 'Password (leave blank to keep)';
            document.getElementById('modalPassword').required = false;
            document.getElementById('userModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
        }

        function saveUser(e) {
            e.preventDefault();
            const id = document.getElementById('editUserId').value;
            const username = document.getElementById('modalUsername').value.trim();
            const password = document.getElementById('modalPassword').value;
            const role = document.getElementById('modalRole').value;
            const action = id ? 'edit_user' : 'create_user';
            const btn = document.getElementById('saveBtn');

            const body = new FormData();
            body.append('action', action);
            body.append('username', username);
            body.append('password', password);
            body.append('role', role);
            if (id) body.append('user_id', id);

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span>';

            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body
                })
                .then(r => r.json())
                .then(res => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Save';
                    if (res.success) {
                        closeModal();
                        showToast(res.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(res.message, 'error');
                    }
                });
        }

        function deleteUser(id, username) {
            if (!confirm(`Delete "${username}"?`)) return;
            const body = new FormData();
            body.append('action', 'delete_user');
            body.append('user_id', id);
            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast(res.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(res.message, 'error');
                    }
                });
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            toast.className = 'show ' + type;
            setTimeout(() => toast.className = '', 3000);
        }

        function generateReport() {
            const type = document.getElementById('reportType').value;
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            window.location.href = `?tab=reports&report=${type}&start=${start}&end=${end}`;
        }

        function exportCSV() {
            const table = document.querySelector('#reportContent table');
            if (!table) {
                showToast('Generate a report first.', 'error');
                return;
            }
            let csv = [];
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => headers.push(th.innerText));
            csv.push(headers.join(','));
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach(td => row.push('"' + td.innerText.replace(/"/g, '""') + '"'));
                csv.push(row.join(','));
            });
            const blob = new Blob([csv.join('\n')], {
                type: 'text/csv'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `report_${Date.now()}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            showToast('Report exported!', 'success');
        }

        // Charts
        let chartsInitialized = false;

        function initCharts() {
            if (chartsInitialized) return;
            chartsInitialized = true;

            new Chart(document.getElementById('balutChart'), {
                type: 'bar',
                data: {
                    labels: PHP.balutPerUser.map(r => r.username),
                    datasets: [{
                        label: 'Total Balut',
                        data: PHP.balutPerUser.map(r => parseInt(r.total_balut)),
                        backgroundColor: 'rgba(16,185,129,.7)',
                        borderColor: '#10b981',
                        borderWidth: 2,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            const labels = PHP.weeklyTrend.map(r => r.day);
            new Chart(document.getElementById('trendChart'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                            label: 'Balut',
                            data: PHP.weeklyTrend.map(r => +r.balut),
                            borderColor: '#10b981',
                            fill: true,
                            tension: .4
                        },
                        {
                            label: 'Chicks',
                            data: PHP.weeklyTrend.map(r => +r.chicks),
                            borderColor: '#3b82f6',
                            fill: true,
                            tension: .4
                        },
                        {
                            label: 'Failed',
                            data: PHP.weeklyTrend.map(r => +r.failed),
                            borderColor: '#ef4444',
                            fill: true,
                            tension: .4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            new Chart(document.getElementById('pieChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Balut', 'Chicks', 'Failed'],
                    datasets: [{
                        data: [PHP.totalBalut, PHP.totalChicks, PHP.totalFailed],
                        backgroundColor: ['#10b981', '#3b82f6', '#ef4444']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 10
                                }
                            }
                        }
                    }
                }
            });

            new Chart(document.getElementById('statusChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Incubating', 'Complete'],
                    datasets: [{
                        data: [PHP.incubating, PHP.complete],
                        backgroundColor: ['#f59e0b', '#10b981']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 10
                                }
                            }
                        }
                    }
                }
            });
        }

        if ('<?= $activeTab ?>' === 'analytics') initCharts();

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('userModal');
            if (event.target === modal) closeModal();
        };

        // Auto-refresh activity logs every 30 seconds (real-time feel)
        setInterval(function() {
            if ('<?= $activeTab ?>' === 'overview') {
                fetch(window.location.href + '?get_activity_ajax=1&nocache=' + Date.now(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.logs && data.logs.length > 0) {
                            const tbody = document.getElementById('activityLogsBody');
                            if (tbody) {
                                let newHtml = '';
                                data.logs.forEach(log => {
                                    newHtml += `
                                        <tr>
                                            <td class="activity-time">
                                                <i class="far fa-clock"></i> ${escapeHtml(log.formatted_date)}
                                                <small>(${escapeHtml(log.time_ago)})</small>
                                            </td>
                                            <td>${escapeHtml(log.username)}</td>
                                            <td>${escapeHtml(log.action)}</td>
                                        </tr>
                                    `;
                                });
                                tbody.innerHTML = newHtml;
                            }
                        }
                    })
                    .catch(err => console.log('Auto-refresh failed:', err));
            }
        }, 30000);

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>

</html>