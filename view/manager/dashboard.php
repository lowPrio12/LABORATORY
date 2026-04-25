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

// Helper: time ago
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

// ── Fetch statistics ─────────────────────────────────────────────────────────
$totalUsers   = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs    = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks  = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut   = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;
$totalFailed  = $conn->query("SELECT SUM(failed_count) FROM egg")->fetchColumn() ?: 0;

// ── Users with summary ────────────────────────────────────────────────────────
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

// ── Activity logs ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT l.*, u.username
    FROM user_activity_logs l
    JOIN users u ON l.user_id = u.user_id
    WHERE l.log_date IS NOT NULL
    ORDER BY l.log_date DESC
    LIMIT 20
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics: Balut per user (top 8) ────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT u.username, COALESCE(SUM(e.balut_count),0) AS total_balut
    FROM users u LEFT JOIN egg e ON u.user_id = e.user_id
    GROUP BY u.user_id ORDER BY total_balut DESC LIMIT 8
");
$stmt->execute();
$balutPerUser = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
} elseif ($reportType === 'profitDistribution') {
    $stmt = $conn->prepare("
        SELECT u.username,
               COALESCE(SUM(e.balut_count),0) AS total_balut,
               COALESCE(SUM(e.chick_count),0) AS total_chicks
        FROM users u LEFT JOIN egg e ON u.user_id=e.user_id
            AND e.date_started_incubation BETWEEN ? AND ?
        GROUP BY u.user_id
        HAVING total_balut > 0
        ORDER BY total_balut DESC
    ");
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get incubating and complete batch counts for analytics
$incubating = $conn->query("SELECT COUNT(*) FROM egg WHERE status='incubating'")->fetchColumn();
$complete   = $conn->query("SELECT COUNT(*) FROM egg WHERE status='complete'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard | EggFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/manager/css/manager_style.css">
    <style>
        /* ── Tab System ─────────────────────────────── */
        .tab-section {
            display: none;
        }

        .tab-section.active {
            display: block;
        }

        .sidebar-menu li.active a,
        .sidebar-menu a:hover {
            background: rgba(16, 185, 129, .2);
            color: #10b981;
        }

        /* ── Chart Row ──────────────────────────────── */
        .chart-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media(max-width:900px) {
            .chart-row {
                grid-template-columns: 1fr;
            }
        }

        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .1);
        }

        .chart-card h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #1e293b;
        }

        /* ── Reports ─────────────────────────────────── */
        .report-controls {
            background: white;
            border-radius: 20px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .1);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .report-controls .form-group {
            margin-bottom: 0;
        }

        .report-controls label {
            font-size: .8rem;
            font-weight: 600;
            color: #64748b;
            display: block;
            margin-bottom: .35rem;
        }

        .report-controls select,
        .report-controls input[type="date"] {
            padding: .6rem .85rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: .875rem;
            color: #334155;
        }

        .btn-outline {
            background: white;
            border: 1px solid #e2e8f0;
            color: #334155;
            padding: .65rem 1.2rem;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            transition: all .2s;
        }

        .btn-outline:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        /* ── Search ──────────────────────────────────── */
        .search-box input {
            padding: .55rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: .875rem;
            width: 220px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #10b981;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        /* ── Action buttons in table ─────────────────── */
        .action-btns {
            display: flex;
            gap: .5rem;
        }

        .btn-sm {
            padding: .35rem .75rem;
            font-size: .75rem;
            border-radius: 8px;
        }

        /* ── Toast notification ──────────────────────── */
        #toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 9999;
            padding: 1rem 1.5rem;
            border-radius: 14px;
            color: white;
            font-size: .9rem;
            font-weight: 500;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .15);
            display: none;
            align-items: center;
            gap: .75rem;
            animation: slideUp .3s ease;
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
                transform: translateY(20px);
                opacity: 0
            }

            to {
                transform: translateY(0);
                opacity: 1
            }
        }

        /* ── Stat card accent colors ─────────────────── */
        .stat-card:nth-child(1) .stat-icon {
            background: rgba(16, 185, 129, .12);
            color: #10b981;
        }

        .stat-card:nth-child(2) .stat-icon {
            background: rgba(59, 130, 246, .12);
            color: #3b82f6;
        }

        .stat-card:nth-child(3) .stat-icon {
            background: rgba(245, 158, 11, .12);
            color: #f59e0b;
        }

        .stat-card:nth-child(4) .stat-icon {
            background: rgba(139, 92, 246, .12);
            color: #8b5cf6;
        }

        .stat-card:nth-child(5) .stat-icon {
            background: rgba(236, 72, 153, .12);
            color: #ec4899;
        }

        .stat-card:nth-child(6) .stat-icon {
            background: rgba(239, 68, 68, .12);
            color: #ef4444;
        }

        /* ── Spinner ─────────────────────────────────── */
        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, .3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        .badge-incubating {
            background: #dbeafe;
            color: #1d4ed8;
            padding: .2rem .6rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
        }

        .badge-complete {
            background: #dcfce7;
            color: #15803d;
            padding: .2rem .6rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
        }

        /* Alert styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #10b981;
        }
    </style>
</head>

<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chart-line"></i> EggFlow Manager</h2>
                <p>Balut Management & Oversight</p>
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
                    <p id="page-subtitle"><?= $activeTab == 'overview' ? 'Overview & key metrics' : ($activeTab == 'analytics' ? 'Production analytics' : 'Generate & export reports') ?></p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?>
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

                <div class="action-bar">
                    <button class="btn btn-primary" onclick="location.href='../users/user-management.php'">
                        <i class="fas fa-user-plus"></i> Add New User
                    </button>
                    <form method="post" style="display:inline;">
                        <button type="submit" name="distribute_profits" class="btn btn-success"
                            onclick="return confirm('Distribute profits based on balut counts?')">
                            <i class="fas fa-hand-holding-usd"></i> Distribute Profits
                        </button>
                    </form>
                </div>

                <!-- Users Summary -->
                <div class="table-container">
                    <div class="table-header">
                        <h3>Users & Balut Summary</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Joined</th>
                                <th>Batches</th>
                                <th>Total Balut</th>
                                <th>Total Chicks</th>
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
                <td><span class="role-badge <?= $u['user_role'] ?>"><?= ucfirst($u['user_role']) ?></span></td>
                <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                <td><?= number_format($u['batch_count']) ?>
            </div>
            <td><strong><?= number_format($u['total_balut']) ?></strong></td>
            <td><?= number_format($u['total_chicks']) ?>
    </div>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Activity Logs -->
<div class="table-container">
    <div class="table-header">
        <h3>Recent User Activity</h3>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
        </thead>
        <tbody>
            <?php if ($logs): ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="activity-time"><?= timeAgo($log['log_date']) ?></td>
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

<!-- ═══════════════ ANALYTICS TAB ═══════════════ -->
<div id="analytics-section" class="tab-section <?= $activeTab == 'analytics' ? 'active' : '' ?>">
    <div class="stats-grid" style="margin-bottom:2rem;">
        <div class="stat-card">
            <div class="stat-info">
                <h3>Avg Balut / Batch</h3>
                <p><?= $totalBatches > 0 ? number_format($totalBalut / $totalBatches, 1) : '0' ?></p>
            </div>
            <div class="stat-icon"><i class="fas fa-calculator"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Avg Chicks / Batch</h3>
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
            <h3><i class="fas fa-chart-bar" style="color:#10b981;margin-right:.5rem;"></i>Balut per User</h3>
            <canvas id="balutChart"></canvas>
        </div>
        <div class="chart-card">
            <h3><i class="fas fa-chart-line" style="color:#3b82f6;margin-right:.5rem;"></i>Weekly Production Trend (Last 7 Days)</h3>
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <div class="chart-row">
        <div class="chart-card">
            <h3><i class="fas fa-chart-pie" style="color:#f59e0b;margin-right:.5rem;"></i>Outcome Distribution</h3>
            <canvas id="pieChart"></canvas>
        </div>
        <div class="chart-card">
            <h3><i class="fas fa-chart-area" style="color:#8b5cf6;margin-right:.5rem;"></i>Batch Status Overview</h3>
            <canvas id="statusChart"></canvas>
            <p style="text-align:center;color:#64748b;font-size:.85rem;margin-top:.75rem;">
                Incubating: <strong><?= $incubating ?></strong> &nbsp;|&nbsp; Complete: <strong><?= $complete ?></strong>
            </p>
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
                <option value="profitDistribution" <?= $reportType == 'profitDistribution' ? 'selected' : '' ?>>Profit Distribution</option>
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
        <button class="btn btn-primary" id="generateReportBtn" onclick="generateReport()">
            <i class="fas fa-chart-bar"></i> Generate
        </button>
        <button class="btn btn-outline" id="exportCsvBtn" onclick="exportCSV()">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
    </div>

    <div class="table-container" id="reportPreview">
        <div class="table-header">
            <h3 id="reportTitle">Report Preview</h3>
        </div>
        <div id="reportContent" style="padding:.5rem;color:#64748b;">
            <?php if ($reportData): ?>
                <?php
                $titles = [
                    'userSummary' => 'User Summary Report',
                    'batchLog' => 'Batch Log Report',
                    'profitDistribution' => 'Profit Distribution Report',
                ];
                $title = $titles[$reportType] ?? 'Report';
                echo "<script>document.getElementById('reportTitle').innerHTML = '" . $title . "';</script>";
                ?>
                <div style="overflow-x:auto">
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
                </div>
            <?php else: ?>
                Select a report type and click Generate.
            <?php endif; ?>
        </div>
    </div>
</div>
</main>
</div>

<!-- ── Add / Edit User Modal ─────────────────────────────────────── -->
<div id="userModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add New User</h3>
            <button class="close" onclick="closeModal()">&times;</button>
        </div>
        <form id="userForm" onsubmit="saveUser(event)">
            <input type="hidden" id="editUserId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="modalUsername" name="username" required minlength="3" maxlength="50">
                </div>
                <div class="form-group">
                    <label id="passwordLabel">Password</label>
                    <input type="password" id="modalPassword" name="password" minlength="6">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select id="modalRole" name="role">
                        <option value="user">Regular User</option>
                        <option value="manager">Manager</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
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
    // ── PHP data bridged to JS ──────────────────────────────────────────────────
    const PHP = {
        currentUserId: <?= (int)$_SESSION['user_id'] ?>,
        balutPerUser: <?= json_encode($balutPerUser) ?>,
        weeklyTrend: <?= json_encode($weeklyTrend) ?>,
        totalBalut: <?= (int)$totalBalut ?>,
        totalChicks: <?= (int)$totalChicks ?>,
        totalFailed: <?= (int)$totalFailed ?>,
        incubating: <?= (int)$incubating ?>,
        complete: <?= (int)$complete ?>,
    };

    // ── Tab Navigation ──────────────────────────────────────────────────────────
    const tabTitles = {
        overview: 'Overview & key metrics',
        analytics: 'Production analytics',
        reports: 'Generate & export reports',
    };

    // Handle tab clicks for analytics and reports tabs
    document.querySelectorAll('.nav-item[data-tab]').forEach(li => {
        li.querySelector('a').addEventListener('click', (e) => {
            e.preventDefault();
            const tab = li.dataset.tab;

            // Update URL without page reload
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);

            // Update active states
            document.querySelectorAll('.nav-item').forEach(x => x.classList.remove('active'));
            li.classList.add('active');
            document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
            document.getElementById(tab + '-section').classList.add('active');
            document.getElementById('page-subtitle').textContent = tabTitles[tab] ?? '';

            if (tab === 'analytics') initCharts();
        });
    });

    // Handle browser back/forward buttons
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

    // ── Modal ────────────────────────────────────────────────────────────────────
    function openModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add New User';
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
        document.getElementById('passwordLabel').textContent = 'Password (leave blank to keep unchanged)';
        document.getElementById('modalPassword').required = false;
        document.getElementById('userModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('userModal').classList.remove('active');
    }

    // ── Save User (create/edit) ──────────────────────────────────────────────────
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
        btn.innerHTML = '<span class="spinner"></span> Saving…';

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
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast(res.message, 'error');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save';
                showToast('Something went wrong.', 'error');
            });
    }

    // ── Delete User ───────────────────────────────────────────────────────────────
    function deleteUser(id, username) {
        if (!confirm(`Delete user "${username}"? This cannot be undone.`)) return;
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

    // ── Search / Filter ───────────────────────────────────────────────────────────
    function filterUsers() {
        const q = document.getElementById('searchUser')?.value.toLowerCase();
        if (q) {
            document.querySelectorAll('#userTableBody tr').forEach(row => {
                row.style.display = row.dataset.username?.includes(q) ? '' : 'none';
            });
        }
    }

    // ── Toast ─────────────────────────────────────────────────────────────────────
    function showToast(msg, type = 'success') {
        const toast = document.getElementById('toast');
        document.getElementById('toastMsg').textContent = msg;
        toast.className = 'show ' + type;
        setTimeout(() => toast.className = '', 3000);
    }

    // ── Reports ───────────────────────────────────────────────────────────────────
    let reportRows = [];

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
        // Get headers
        const headers = [];
        table.querySelectorAll('thead th').forEach(th => {
            headers.push(th.innerText);
        });
        csv.push(headers.join(','));

        // Get rows
        table.querySelectorAll('tbody tr').forEach(tr => {
            const row = [];
            tr.querySelectorAll('td').forEach(td => {
                row.push('"' + td.innerText.replace(/"/g, '""') + '"');
            });
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
        showToast('Report exported successfully!', 'success');
    }

    // ── Charts ────────────────────────────────────────────────────────────────────
    let chartsInitialized = false;

    function initCharts() {
        if (chartsInitialized) return;
        chartsInitialized = true;

        // Balut per User bar chart
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
                    borderRadius: 8,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f1f5f9'
                        }
                    }
                }
            }
        });

        // Weekly trend line chart
        const labels = PHP.weeklyTrend.map(r => r.day);
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                        label: 'Balut',
                        data: PHP.weeklyTrend.map(r => +r.balut),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16,185,129,.1)',
                        fill: true,
                        tension: .4
                    },
                    {
                        label: 'Chicks',
                        data: PHP.weeklyTrend.map(r => +r.chicks),
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59,130,246,.1)',
                        fill: true,
                        tension: .4
                    },
                    {
                        label: 'Failed',
                        data: PHP.weeklyTrend.map(r => +r.failed),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239,68,68,.1)',
                        fill: true,
                        tension: .4
                    },
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f1f5f9'
                        }
                    }
                }
            }
        });

        // Pie chart
        new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: ['Balut', 'Chicks', 'Failed'],
                datasets: [{
                    data: [PHP.totalBalut, PHP.totalChicks, PHP.totalFailed],
                    backgroundColor: ['#10b981', '#3b82f6', '#ef4444'],
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Status doughnut
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Incubating', 'Complete'],
                datasets: [{
                    data: [PHP.incubating, PHP.complete],
                    backgroundColor: ['#f59e0b', '#10b981'],
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // Initialize charts if analytics tab is active
    if ('<?= $activeTab ?>' === 'analytics') {
        initCharts();
    }
</script>
</body>

</html>