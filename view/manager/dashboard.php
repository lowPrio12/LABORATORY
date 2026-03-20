<?php
require_once '../../model/config.php';

// Check if user is logged in and is manager
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'manager') {
    header("Location: ../../index.php");
    exit;
}

$manager_id = $_SESSION['user_id'];

// Log access
$action = "Manager accessed dashboard";
$stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
$stmt->execute([$manager_id, $action]);

// Fetch statistics
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;

// Fetch all users with their balut totals
$stmt = $conn->prepare("
    SELECT u.user_id, u.username, u.user_role, u.created_at,
           COALESCE(SUM(e.balut_count), 0) as total_balut,
           COALESCE(SUM(e.chick_count), 0) as total_chicks,
           COALESCE(COUNT(e.egg_id), 0) as batch_count
    FROM users u
    LEFT JOIN egg e ON u.user_id = e.user_id
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent activity logs (all users)
$stmt = $conn->prepare("
    SELECT l.*, u.username 
    FROM user_activity_logs l
    JOIN users u ON l.user_id = u.user_id
    ORDER BY l.log_date DESC
    LIMIT 20
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle profit distribution (simulate distribution)
$distribution_message = null;
if (isset($_POST['distribute_profits'])) {
    // In a real system, you would calculate profits based on balut count
    // and distribute to users (e.g., update a user_balance table).
    // For demonstration, we'll just log the distribution.
    $action = "Distributed profits based on balut counts";
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
    $stmt->execute([$manager_id, $action]);
    $distribution_message = "Profits distributed successfully!";
}
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
                <li class="active"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="../users/dashboard.php class="fas fa-users"></i> User Management</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li><a href="../../controller/auth/signout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="welcome-text">
                    <h1>Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Manager') ?></h1>
                    <p>Monitor balut production and user activity.</p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?>
                </div>
            </div>

            <!-- Distribution Success Message -->
            <?php if ($distribution_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($distribution_message) ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
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
            </div>

            <!-- Actions -->
            <div class="action-bar">
                <button class="btn btn-primary" onclick="openAddUserModal()">
                    <i class="fas fa-user-plus"></i> Add New User
                </button>
                <form method="post" style="display: inline;">
                    <button type="submit" name="distribute_profits" class="btn btn-success" onclick="return confirm('Distribute profits based on balut counts?')">
                        <i class="fas fa-hand-holding-usd"></i> Distribute Profits
                    </button>
                </form>
            </div>

            <!-- Users Table with Balut Summary -->
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
                                    <div><?= htmlspecialchars($user['username']) ?></div>
                                </div>
                            </td>
                            <td><span class="role-badge <?= $user['user_role'] ?>"><?= ucfirst($user['user_role']) ?></span></td>
                            <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                            <td><?= number_format($user['batch_count']) ?></td>
                            <td><strong><?= number_format($user['total_balut']) ?></strong></td>
                            <td><?= number_format($user['total_chicks']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Activity Logs -->
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="activity-time"><?= date('M d, H:i', strtotime($log['log_date'])) ?></td>
                            <td><?= htmlspecialchars($log['username']) ?></td>
                            <td><?= htmlspecialchars($log['action']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New User (Regular)</h3>
                <button class="close" onclick="closeAddUserModal()">&times;</button>
            </div>
            <form id="addUserForm" onsubmit="createUser(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="username" name="username" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_]+">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="password" name="password" required minlength="6">
                    </div>
                    <!-- Role is fixed to 'user' for manager -->
                    <input type="hidden" name="user_role" value="user">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../../assets/manager/js/manager_dashboard.js"></script>
    <script>
        // Pass PHP data to JS if needed
        const managerData = {
            currentUserId: <?= $_SESSION['user_id'] ?>
        };
    </script>
</body>
</html>