<?php
require '../../model/config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

/* LOG ACCESS */
$action = "Admin accessed user management";
$stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
$stmt->execute([$_SESSION['user_id'], $action]);

// Get user statistics
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='admin'")->fetchColumn();
$totalManagers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='manager'")->fetchColumn();
$totalRegularUsers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'")->fetchColumn();

// Get recent users (last 7 days)
$recentUsers = $conn->query("
    SELECT COUNT(*) FROM users 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetchColumn();

// Fetch all users with additional stats
$stmt = $conn->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM egg WHERE user_id = u.user_id) as batch_count,
           (SELECT COUNT(*) FROM user_activity_logs WHERE user_id = u.user_id) as activity_count
    FROM users u 
    ORDER BY u.created_at DESC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available roles for filter
$roles = ['admin', 'manager', 'user'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | EggFlow Analytics</title>

    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/admin/js/css/main_dashboard.css">
    <link rel="stylesheet" href="../../assets/admin/js/css/user_management.css">
</head>

<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>
                <i class="fas fa-egg"></i>
                EggFlow Analytics
            </h2>
            <ul>
                <li>
                    <a href="../admin/dashboard.php">
                        <i class="fas fa-chart-pie"></i>
                        Dashboard
                    </a>
                </li>
                <li class="active">
                    <a href="dashboard.php">
                        <i class="fas fa-users"></i>
                        User Management
                    </a>
                </li>
                <li>
                    <a href="../admin/analytics.php">
                        <i class="fas fa-chart-line"></i>
                        Advanced Analytics
                    </a>
                </li>
                <li>
                    <a href="../admin/reports.php">
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
                    <h1>User Management</h1>
                    <p>
                        <i class="fas fa-users-cog"></i>
                        Manage system users and their permissions
                    </p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar"></i>
                    <?= date('l, F j, Y') ?>
                </div>
            </div>

            <!-- Stats Grid -->
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
                        <i class="fas fa-arrow-up"></i>
                        +<?= $recentUsers ?> in last 7 days
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Administrators</span>
                        <span class="stat-icon">
                            <i class="fas fa-crown"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($totalAdmins) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-shield-alt"></i>
                        Full system access
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Managers</span>
                        <span class="stat-icon">
                            <i class="fas fa-user-tie"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($totalManagers) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-chart-line"></i>
                        Management access
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Regular Users</span>
                        <span class="stat-icon">
                            <i class="fas fa-user"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($totalRegularUsers) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-egg"></i>
                        Basic access
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search users by name, email or role..." onkeyup="filterUsers()">
                </div>
                <select id="roleFilter" class="filter-select" onchange="filterUsers()">
                    <option value="all">All Roles</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $role ?>"><?= ucfirst($role) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline" onclick="openAddUserModal()">
                    <i class="fas fa-plus"></i>
                    Add New User
                </button>
            </div>

            <!-- Users Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">System Users</h3>
                    <div class="table-actions">
                        <span class="table-subtitle">
                            <i class="fas fa-user-check"></i>
                            <?= count($users) ?> total users
                        </span>
                    </div>
                </div>

                <table id="usersTable">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th>Activity</th>
                            <th>Batches</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user):
                            // Get initials for avatar
                            $initials = '';
                            $nameParts = explode(' ', $user['username']);
                            foreach ($nameParts as $part) {
                                if (!empty($part)) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            $initials = substr($initials, 0, 2);
                        ?>
                            <tr data-role="<?= $user['user_role'] ?>" data-name="<?= strtolower($user['username']) ?>">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <div class="user-avatar" style="background: <?= getAvatarColor($user['user_id']) ?>">
                                            <?= $initials ?: 'U' ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 500; color: #0f172a;">
                                                <?= htmlspecialchars($user['username']) ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: #64748b;">
                                                ID: <?= $user['user_id'] ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-badge <?= $user['user_role'] ?>">
                                        <i class="fas <?= getRoleIcon($user['user_role']) ?>"></i>
                                        <?= ucfirst($user['user_role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="tooltip" data-tooltip="<?= date('F j, Y', strtotime($user['created_at'])) ?>">
                                        <i class="far fa-calendar-alt" style="color: #94a3b8; margin-right: 0.5rem;"></i>
                                        <?= timeAgo($user['created_at']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="activity-indicator">
                                        <span class="activity-count"><?= number_format($user['activity_count']) ?></span>
                                        <span class="activity-label">actions</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <i class="fas fa-box"></i>
                                        <?= $user['batch_count'] ?> batches
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <!-- Replace the existing edit button with this -->
                                        <button class="btn-icon edit" onclick="openEditModal('<?= $user['user_id'] ?>', '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>', '<?= $user['user_role'] ?>')" title="Edit user">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon view" onclick="viewUserDetails('<?= $user['user_id'] ?>')" title="View details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" action="../../view/users/user-delete.php" style="display:inline" onsubmit="return confirmDelete()">
                                                <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                                <button type="submit" class="btn-icon delete" title="Delete user">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 3rem; color: #64748b;">
                                    <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No users found</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- User Activity Summary -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">Recent User Activity</h3>
                    <a href="../admin/analytics.php" class="btn btn-outline">
                        <i class="fas fa-chart-line"></i>
                        View Full Analytics
                    </a>
                </div>

                <?php
                // Get recent user activity
                $stmt = $conn->query("
                    SELECT l.*, u.username 
                    FROM user_activity_logs l
                    JOIN users u ON l.user_id = u.user_id
                    ORDER BY l.log_date DESC
                    LIMIT 5
                ");
                $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivities as $activity): ?>
                            <tr>
                                <td>
                                    <span class="tooltip" data-tooltip="<?= date('F j, Y H:i:s', strtotime($activity['log_date'])) ?>">
                                        <?= timeAgo($activity['log_date']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($activity['username']) ?></td>
                                <td>
                                    <span class="badge badge-success">
                                        <?= htmlspecialchars($activity['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-indicator status-active"></span>
                                    Active
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($recentActivities)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #64748b; padding: 2rem;">
                                    <i class="fas fa-info-circle"></i> No recent activity
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-user-edit" style="color: #10b981;"></i>
                    Edit User
                </h2>
                <button type="button" class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editUserForm" onsubmit="updateUser(event)">
                    <input type="hidden" id="edit_user_id" name="user_id">

                    <div class="form-group">
                        <label for="edit_username">
                            <i class="fas fa-user"></i>
                            Username
                        </label>
                        <input
                            type="text"
                            id="edit_username"
                            name="username"
                            required
                            minlength="3"
                            maxlength="50"
                            pattern="[a-zA-Z0-9_]+"
                            title="Username can only contain letters, numbers, and underscores"
                            placeholder="Enter username">
                    </div>

                    <div class="form-group">
                        <label for="edit_password">
                            <i class="fas fa-lock"></i>
                            New Password (leave blank to keep current)
                        </label>
                        <input
                            type="password"
                            id="edit_password"
                            name="password"
                            minlength="6"
                            placeholder="Enter new password">
                        <small style="color: #64748b; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                            Leave blank to keep current password. Minimum 6 characters if changing.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="edit_role">
                            <i class="fas fa-tag"></i>
                            Role
                        </label>
                        <select id="edit_role" name="user_role" required>
                            <option value="user">Regular User</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeEditModal()">
                            <i class="fas fa-times"></i>
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../../assets/users/js/user-update.js"></script>

    <!-- Add User Modal - Only for user creation -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-user-plus" style="color: #10b981;"></i>
                    Add New User
                </h2>
                <button type="button" class="modal-close" onclick="closeAddModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="createUserForm" onsubmit="createUser(event)">
                    <div class="form-group">
                        <label for="add_username">
                            <i class="fas fa-user"></i>
                            Username
                        </label>
                        <input
                            type="text"
                            id="add_username"
                            name="username"
                            required
                            minlength="3"
                            maxlength="50"
                            pattern="[a-zA-Z0-9_]+"
                            title="Username can only contain letters, numbers, and underscores"
                            placeholder="Enter username">
                    </div>

                    <div class="form-group">
                        <label for="add_password">
                            <i class="fas fa-lock"></i>
                            Password
                        </label>
                        <input
                            type="password"
                            id="add_password"
                            name="password"
                            required
                            minlength="6"
                            placeholder="Enter password">
                        <small style="color: #64748b; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                            Minimum 6 characters
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="add_role">
                            <i class="fas fa-tag"></i>
                            Role
                        </label>
                        <select id="add_role" name="user_role" required>
                            <option value="user">Regular User</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeAddModal()">
                            <i class="fas fa-times"></i>
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i>
                            Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Include only the user creation JavaScript -->
    <script src="../../assets/js/user-create.js"></script>


    <!-- View User Details Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-user-circle" style="color: #10b981;"></i>
                    User Details
                </h2>
                <button class="modal-close" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="viewUserDetails">
                <!-- Loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Custom JavaScript -->
    <script src="../../assets/users/js/user_create.js"></script>
    <script>
        // Pass PHP data to JavaScript
        const userData = {
            users: <?= json_encode($users) ?>,
            currentUserId: <?= $_SESSION['user_id'] ?>
        };
    </script>
</body>

</html>

<?php
// Helper functions
function timeAgo($datetime)
{
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

function getAvatarColor($id)
{
    $colors = [
        'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
        'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
        'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'linear-gradient(135deg, #30cfd0 0%, #330867 100%)'
    ];
    return $colors[$id % count($colors)];
}

function getRoleIcon($role)
{
    switch ($role) {
        case 'admin':
            return 'fa-crown';
        case 'manager':
            return 'fa-user-tie';
        default:
            return 'fa-user';
    }
}
?>