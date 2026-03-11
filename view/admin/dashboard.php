<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

// Log admin login
$action = "Admin logged in";
$stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
$stmt->execute([$_SESSION['user_id'], $action]);

// ---------------------------
// Update username
// ---------------------------
if (isset($_POST['update_user'])) {
    $user_id_to_update = $_POST['user_id'];
    $new_username = $_POST['username'];

    $stmt = $conn->prepare("SELECT username FROM users WHERE user_id=?");
    $stmt->execute([$user_id_to_update]);
    $old_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($old_user) {
        $stmt = $conn->prepare("UPDATE users SET username=? WHERE user_id=?");
        $stmt->execute([$new_username, $user_id_to_update]);

        $action = "Admin updated user '{$old_user['username']}' to '{$new_username}' (ID: $user_id_to_update)";
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $action]);
    }
    header("Location: dashboard.php");
    exit;
}

// ---------------------------
// Delete user
// ---------------------------
if (isset($_POST['delete_user'])) {
    $user_id_to_delete = $_POST['user_id'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE user_id=?");
    $stmt->execute([$user_id_to_delete]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("DELETE FROM users WHERE user_id=? AND user_role!='admin'");
    $stmt->execute([$user_id_to_delete]);

    if ($user_info) {
        $action = "Admin deleted user '{$user_info['username']}' (ID: $user_id_to_delete)";
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $action]);
    }
    header("Location: dashboard.php");
    exit;
}

// ---------------------------
// Delete batch
// ---------------------------
if (isset($_POST['delete_batch'])) {
    $egg_id = $_POST['egg_id'];
    $stmt = $conn->prepare("SELECT batch_number, user_id FROM egg WHERE egg_id=?");
    $stmt->execute([$egg_id]);
    $batch_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($batch_info) {
        $stmt = $conn->prepare("DELETE FROM egg WHERE egg_id=?");
        $stmt->execute([$egg_id]);

        $action = "Admin deleted Batch #{$batch_info['batch_number']} (User ID: {$batch_info['user_id']})";
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $action]);
    }
    header("Location: dashboard.php");
    exit;
}

// ---------------------------
// Dashboard summary
// ---------------------------
$stmt = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'");
$total_users = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM egg");
$total_batches = $stmt->fetchColumn();

$stmt = $conn->query("SELECT SUM(total_egg) FROM egg");
$total_eggs = $stmt->fetchColumn();

$stmt = $conn->query("SELECT SUM(chick_count) FROM egg");
$total_chicks = $stmt->fetchColumn();

// Fetch users
$stmt = $conn->query("SELECT * FROM users WHERE user_role='user' ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch egg batches
$stmt = $conn->query("
SELECT e.*, u.username
FROM egg e
JOIN users u ON e.user_id = u.user_id
ORDER BY e.date_started_incubation DESC
");
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent activity logs (last 10)
$stmt = $conn->query("
SELECT l.*, u.username
FROM user_activity_logs l
LEFT JOIN users u ON l.user_id = u.user_id
ORDER BY log_date DESC
LIMIT 10
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../../assets/admin_style.css">
</head>

<body>
    <div class="dashboard-container">

        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>Egg System Admin</h2>
            <ul>
                <li class="active">Dashboard</li>
                <li><a href="../../controller/auth/signout.php">Logout</a></li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <h1>Welcome, Admin!</h1>
                <p>Overview of users, batches, and recent activity</p>
            </header>

            <!-- Summary Cards -->
            <div class="cards">
                <div class="card">
                    <h3>Total Users</h3>
                    <p><?= $total_users ?></p>
                </div>
                <div class="card">
                    <h3>Total Batches</h3>
                    <p><?= $total_batches ?></p>
                </div>
                <div class="card">
                    <h3>Total Eggs</h3>
                    <p><?= $total_eggs ?? 0 ?></p>
                </div>
                <div class="card">
                    <h3>Total Chicks</h3>
                    <p><?= $total_chicks ?? 0 ?></p>
                </div>
            </div>

            <!-- Users Table -->
            <h2>Users</h2>
            <table class="styled-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['user_id'] ?></td>
                            <td><?= $user['username'] ?></td>
                            <td><?= date("M d, Y", strtotime($user['created_at'])) ?></td>
                            <td>
                                <!-- Edit Username -->
                                <button onclick="openEditModal(<?= $user['user_id'] ?>, '<?= $user['username'] ?>')">Edit</button>

                                <!-- Delete -->
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user?');">
                                    <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                    <button class="btn-delete" type="submit" name="delete_user">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Egg Batches Table -->
            <h2>All Egg Batches</h2>
            <table class="styled-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Batch</th>
                        <th>Total Eggs</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Balut</th>
                        <th>Chick</th>
                        <th>Failed</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><?= $batch['username'] ?></td>
                            <td>#<?= $batch['batch_number'] ?></td>
                            <td><?= $batch['total_egg'] ?></td>
                            <td><span class="status-badge"><?= $batch['status'] ?></span></td>
                            <td><?= date("M d, Y", strtotime($batch['date_started_incubation'])) ?></td>
                            <td><?= $batch['balut_count'] ?></td>
                            <td><?= $batch['chick_count'] ?></td>
                            <td><?= $batch['failed_count'] ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this batch?');">
                                    <input type="hidden" name="egg_id" value="<?= $batch['egg_id'] ?>">
                                    <button class="btn-delete" type="submit" name="delete_batch">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Activity Logs -->
            <h2>Recent Activity Logs</h2>
            <table class="styled-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= $log['username'] ?? 'System/Admin' ?></td>
                            <td><?= $log['action'] ?></td>
                            <td><?= date("M d, Y H:i", strtotime($log['log_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </main>
    </div>

    <!-- Edit Username Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <h3>Edit Username</h3>
            <form method="post">
                <input type="hidden" name="user_id" id="edit_user_id">
                <label>Username</label>
                <input type="text" name="username" id="edit_username" required>
                <div class="modal-actions">
                    <button class="btn-primary" type="submit" name="update_user">Update</button>
                    <button class="btn-secondary" type="button" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(id, username) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_username').value = username;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }
    </script>

</body>

</html>