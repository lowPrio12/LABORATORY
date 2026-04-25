<?php
require_once '../../model/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

// Helper function to format time
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
$stmt->execute([$user_id, ucfirst($user_role) . " accessed User Management"]);

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $export_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    // Check permission for export
    $canExport = false;
    if ($user_role === 'admin') {
        $canExport = true;
    } else {
        $stmt = $conn->prepare("SELECT user_role FROM users WHERE user_id = ?");
        $stmt->execute([$export_user_id]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($targetUser && ($targetUser['user_role'] === 'user' || $export_user_id == $user_id)) {
            $canExport = true;
        }
    }

    if ($canExport && $export_user_id > 0) {
        // Get user data
        $stmt = $conn->prepare("SELECT username, user_role, created_at FROM users WHERE user_id = ?");
        $stmt->execute([$export_user_id]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($export_type === 'egg_records') {
            // Export egg records
            $stmt = $conn->prepare("
                SELECT batch_number, total_egg, status, date_started_incubation, 
                       balut_count, chick_count, failed_count,
                       DATEDIFF(NOW(), date_started_incubation) as days_in_incubation
                FROM egg 
                WHERE user_id = ? 
                ORDER BY date_started_incubation DESC
            ");
            $stmt->execute([$export_user_id]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $userData['username'] . '_egg_records_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            fputcsv($output, ['Batch Number', 'Total Eggs', 'Balut Count', 'Chick Count', 'Failed Count', 'Status', 'Date Started', 'Days in Incubation']);

            foreach ($records as $record) {
                fputcsv($output, [
                    $record['batch_number'],
                    $record['total_egg'],
                    $record['balut_count'],
                    $record['chick_count'],
                    $record['failed_count'],
                    $record['status'],
                    date('Y-m-d', strtotime($record['date_started_incubation'])),
                    $record['days_in_incubation']
                ]);
            }
            fclose($output);
            exit;
        } elseif ($export_type === 'activity_logs') {
            // Export activity logs
            $stmt = $conn->prepare("
                SELECT action, log_date 
                FROM user_activity_logs 
                WHERE user_id = ? 
                ORDER BY log_date DESC
            ");
            $stmt->execute([$export_user_id]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $userData['username'] . '_activity_logs_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            fputcsv($output, ['Action', 'Date & Time']);

            foreach ($records as $record) {
                fputcsv($output, [
                    $record['action'],
                    date('Y-m-d H:i:s', strtotime($record['log_date']))
                ]);
            }
            fclose($output);
            exit;
        }
    }
}

// Handle AJAX requests for user management
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// CREATE USER (Admin only for admin accounts, Manager can only create regular users)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $response = ['success' => false, 'message' => ''];

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? 'user');

    // Role restrictions based on user role
    if ($user_role !== 'admin') {
        // Manager can only create 'user' role, not admin or manager
        if ($role !== 'user') {
            $response['message'] = 'You can only create regular user accounts.';
            echo json_encode($response);
            exit;
        }
    }

    $errors = [];
    if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    // Check duplicate
    $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $chk->execute([$username]);
    if ($chk->fetch()) $errors[] = 'Username already exists.';

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $conn->prepare("INSERT INTO users (username, password, user_role) VALUES (?, ?, ?)");
        $ins->execute([$username, $hash, $role]);

        $actionLog = "Created user: $username ($role)";
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $actionLog]);

        $response = ['success' => true, 'message' => 'User created successfully.'];
    } else {
        $response = ['success' => false, 'message' => implode(' ', $errors)];
    }

    if ($isAjax) {
        echo json_encode($response);
        exit;
    }
}

// EDIT USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $response = ['success' => false, 'message' => ''];
    $edit_id = (int)($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = $_POST['password'] ?? '';

    // Get current user data
    $stmt = $conn->prepare("SELECT user_role FROM users WHERE user_id = ?");
    $stmt->execute([$edit_id]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        $response['message'] = 'User not found.';
        echo json_encode($response);
        exit;
    }

    // Permission checks
    if ($user_role !== 'admin') {
        // Manager cannot edit admin accounts
        if ($targetUser['user_role'] === 'admin') {
            $response['message'] = 'You cannot edit admin accounts.';
            echo json_encode($response);
            exit;
        }
        // Manager cannot change role to admin or manager
        if ($role !== 'user') {
            $response['message'] = 'You can only assign regular user role.';
            echo json_encode($response);
            exit;
        }
        // Manager cannot edit other managers
        if ($targetUser['user_role'] === 'manager' && $edit_id != $user_id) {
            $response['message'] = 'You cannot edit other manager accounts.';
            echo json_encode($response);
            exit;
        }
    }

    if ($edit_id && strlen($username) >= 3) {
        if (!empty($password) && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET username=?, user_role=?, password=? WHERE user_id=?");
            $upd->execute([$username, $role, $hash, $edit_id]);
        } else {
            $upd = $conn->prepare("UPDATE users SET username=?, user_role=? WHERE user_id=?");
            $upd->execute([$username, $role, $edit_id]);
        }

        $actionLog = "Edited user: $username (ID: $edit_id)";
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $actionLog]);

        $response = ['success' => true, 'message' => 'User updated successfully.'];
    } else {
        $response = ['success' => false, 'message' => 'Invalid data. Username must be at least 3 characters.'];
    }

    if ($isAjax) {
        echo json_encode($response);
        exit;
    }
}

// DELETE USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $response = ['success' => false, 'message' => ''];
    $del_id = (int)($_POST['user_id'] ?? 0);

    // Get user data before deletion
    $stmt = $conn->prepare("SELECT username, user_role FROM users WHERE user_id = ?");
    $stmt->execute([$del_id]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        $response['message'] = 'User not found.';
        echo json_encode($response);
        exit;
    }

    // Permission checks
    if ($user_role !== 'admin') {
        // Manager cannot delete admin accounts
        if ($targetUser['user_role'] === 'admin') {
            $response['message'] = 'You cannot delete admin accounts.';
            echo json_encode($response);
            exit;
        }
        // Manager cannot delete other managers
        if ($targetUser['user_role'] === 'manager' && $del_id != $user_id) {
            $response['message'] = 'You cannot delete other manager accounts.';
            echo json_encode($response);
            exit;
        }
    }

    // Prevent self-deletion
    if ($del_id == $user_id) {
        $response['message'] = 'You cannot delete your own account.';
        echo json_encode($response);
        exit;
    }

    if ($del_id) {
        // Delete user's activity logs
        $stmt = $conn->prepare("DELETE FROM user_activity_logs WHERE user_id = ?");
        $stmt->execute([$del_id]);

        // Delete user's egg batches
        $stmt = $conn->prepare("DELETE FROM egg WHERE user_id = ?");
        $stmt->execute([$del_id]);

        // Delete user
        $del = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $del->execute([$del_id]);

        $actionLog = "Deleted user: {$targetUser['username']} (ID: $del_id, Role: {$targetUser['user_role']})";
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $actionLog]);

        $response = ['success' => true, 'message' => 'User deleted successfully.'];
    } else {
        $response = ['success' => false, 'message' => 'Invalid user ID.'];
    }

    if ($isAjax) {
        echo json_encode($response);
        exit;
    }
}

// ── Fetch users based on role ─────────────────────────────────────────
if ($user_role === 'admin') {
    // Admin sees all users
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, u.user_role, u.created_at,
               COALESCE(SUM(e.balut_count), 0) AS total_balut,
               COALESCE(SUM(e.chick_count), 0) AS total_chicks,
               COALESCE(SUM(e.failed_count), 0) AS total_failed,
               COALESCE(COUNT(e.egg_id), 0) AS batch_count
        FROM users u
        LEFT JOIN egg e ON u.user_id = e.user_id
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Manager sees only regular users and themselves
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, u.user_role, u.created_at,
               COALESCE(SUM(e.balut_count), 0) AS total_balut,
               COALESCE(SUM(e.chick_count), 0) AS total_chicks,
               COALESCE(SUM(e.failed_count), 0) AS total_failed,
               COALESCE(COUNT(e.egg_id), 0) AS batch_count
        FROM users u
        LEFT JOIN egg e ON u.user_id = e.user_id
        WHERE u.user_role = 'user' OR u.user_id = ?
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Fetch user egg records for selected user (for modal view) ──────────
$selected_user_id = isset($_GET['view_user']) ? (int)$_GET['view_user'] : 0;
$userEggRecords = [];
$selectedUsername = '';
$canView = false;

if ($selected_user_id > 0) {
    // Check permission to view this user's records
    if ($user_role === 'admin') {
        $canView = true;
    } else {
        // Manager can only view regular users or themselves
        $stmt = $conn->prepare("SELECT user_role FROM users WHERE user_id = ?");
        $stmt->execute([$selected_user_id]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($targetUser && ($targetUser['user_role'] === 'user' || $selected_user_id == $user_id)) {
            $canView = true;
        }
    }

    if ($canView) {
        $stmt = $conn->prepare("SELECT username, user_role, created_at FROM users WHERE user_id = ?");
        $stmt->execute([$selected_user_id]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        $selectedUsername = $userData['username'] ?? '';

        $stmt = $conn->prepare("
            SELECT e.*, 
                   DATEDIFF(NOW(), e.date_started_incubation) as days_in_incubation
            FROM egg e
            WHERE e.user_id = ?
            ORDER BY e.date_started_incubation DESC
        ");
        $stmt->execute([$selected_user_id]);
        $userEggRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Fetch user activity logs for selected user ─────────────────────────
$userActivities = [];
if ($selected_user_id > 0 && $canView) {
    $stmt = $conn->prepare("
        SELECT l.*, u.username
        FROM user_activity_logs l
        LEFT JOIN users u ON l.user_id = u.user_id
        WHERE l.user_id = ?
        ORDER BY l.log_date DESC
        LIMIT 50
    ");
    $stmt->execute([$selected_user_id]);
    $userActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Statistics for dashboard cards ────────────────────────────────────
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;

// Get active tab
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'users';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= ucfirst($user_role) ?> - User Management | EggFlow</title>

    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- jsPDF for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .stat-card p {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-card i {
            font-size: 2rem;
            color: #10b981;
            margin-bottom: 0.5rem;
            display: block;
        }

        /* Tab Content */
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

        /* Action Bar */
        .action-bar {
            background: white;
            border-radius: 20px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: #10b981;
            color: white;
        }

        .btn-primary:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
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

        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Search Box */
        .search-box input {
            padding: 0.55rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.875rem;
            width: 250px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #10b981;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .table-header h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #0f172a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
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

        /* Badges */
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .role-badge.admin {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-badge.manager {
            background: #fef3c7;
            color: #92400e;
        }

        .role-badge.user {
            background: #dcfce7;
            color: #166534;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
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

        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Export Dropdown */
        .export-dropdown {
            position: relative;
            display: inline-block;
        }

        .export-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: white;
            min-width: 180px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            z-index: 1;
            overflow: hidden;
        }

        .export-dropdown-content a {
            color: #1e293b;
            padding: 0.75rem 1rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background 0.2s;
        }

        .export-dropdown-content a:hover {
            background-color: #f1f5f9;
        }

        .export-dropdown:hover .export-dropdown-content {
            display: block;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #94a3b8;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #475569;
            margin-bottom: 0.5rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.875rem;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #10b981;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 9999;
            padding: 1rem 1.5rem;
            border-radius: 14px;
            color: white;
            font-size: 0.875rem;
            font-weight: 500;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            display: none;
            align-items: center;
            gap: 0.75rem;
            animation: slideInRight 0.3s ease;
        }

        .toast.show {
            display: flex;
        }

        .toast.success {
            background: #10b981;
        }

        .toast.error {
            background: #ef4444;
        }

        .toast.info {
            background: #3b82f6;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Loading overlay for PDF generation */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 1rem;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loading-text {
            color: white;
            font-size: 1rem;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Responsive */
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

            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box input {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .spinner {
            width: 20px;
            height: 20px;
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

        /* Print styles for PDF export */
        @media print {

            .sidebar,
            .top-bar,
            .action-bar,
            .mobile-menu-btn,
            .btn,
            .export-dropdown,
            .table-header .btn-group {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }

            .table-container {
                box-shadow: none !important;
                page-break-inside: avoid;
            }

            body {
                background: white;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard">
        <!-- Sidebar - Dynamic based on user role -->
        <aside class="sidebar" id="sidebar">
            <h2>
                <i class="fas fa-egg"></i>
                EggFlow
            </h2>
            <ul>
                <?php if ($user_role === 'admin'): ?>
                    <!-- Admin Sidebar Links -->
                    <li>
                        <a href="../admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i>
                            Dashboard
                        </a>
                    </li>
                    <li class="active">
                        <a href="user-management.php">
                            <i class="fas fa-users"></i>
                            User Management
                        </a>
                    </li>
                    <li>
                        <a href="../admin/dashboard.php?tab=analytics">
                            <i class="fas fa-chart-line"></i>
                            Analytics
                        </a>
                    </li>
                    <li>
                        <a href="../admin/dashboard.php?tab=reports">
                            <i class="fas fa-file-alt"></i>
                            Reports
                        </a>
                    </li>
                <?php else: ?>
                    <!-- Manager Sidebar Links -->
                    <li>
                        <a href="../manager/dashboard.php?tab=overview">
                            <i class="fas fa-tachometer-alt"></i>
                            Dashboard
                        </a>
                    </li>
                    <li class="active">
                        <a href="user-management.php">
                            <i class="fas fa-users"></i>
                            User Management
                        </a>
                    </li>
                    <li>
                        <a href="../manager/dashboard.php?tab=analytics">
                            <i class="fas fa-chart-line"></i>
                            Analytics
                        </a>
                    </li>
                    <li>
                        <a href="../manager/dashboard.php?tab=reports">
                            <i class="fas fa-file-alt"></i>
                            Reports
                        </a>
                    </li>
                <?php endif; ?>
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
                        <?= $user_role === 'admin' ? 'Full access to manage all users and system records' : 'Manage regular users and view operational records' ?>
                    </p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar"></i>
                    <?= date('l, F j, Y') ?>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <h3>Total Users</h3>
                    <p><?= number_format($totalUsers) ?></p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-egg"></i>
                    <h3>Total Batches</h3>
                    <p><?= number_format($totalBatches) ?></p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-hat-wizard"></i>
                    <h3>Total Chicks</h3>
                    <p><?= number_format($totalChicks) ?></p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-drumstick-bite"></i>
                    <h3>Total Balut</h3>
                    <p><?= number_format($totalBalut) ?></p>
                </div>
            </div>

            <!-- Users Tab -->
            <div id="usersTab" class="tab-pane <?= $activeTab == 'users' && !$selected_user_id ? 'active' : '' ?>">
                <div class="action-bar">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-user-plus"></i> Add New User
                    </button>
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Search by username..." onkeyup="filterUsers()">
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3>All Users</h3>
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <?php foreach ($users as $u): ?>
                                <tr data-username="<?= strtolower(htmlspecialchars($u['username'])) ?>">
                                    <td>
                                        <div class="user-info">
                                            <div class="avatar"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
                                            <div><?= htmlspecialchars($u['username']) ?></div>
                                        </div>
                                    </td>
                                    <td><span class="role-badge <?= $u['user_role'] ?>"><?= ucfirst($u['user_role']) ?></span></td>
                                    <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                    <td><?= number_format($u['batch_count']) ?></td>
                                    <td><strong><?= number_format($u['total_balut']) ?></strong></td>
                                    <td><?= number_format($u['total_chicks']) ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <a href="?view_user=<?= $u['user_id'] ?>" class="btn btn-outline btn-sm">
                                                <i class="fas fa-eye"></i> View Records
                                            </a>
                                            <?php if ($user_role === 'admin' || ($user_role === 'manager' && $u['user_role'] === 'user')): ?>
                                                <button class="btn btn-warning btn-sm" onclick="openEditModal(<?= $u['user_id'] ?>, '<?= addslashes($u['username']) ?>', '<?= $u['user_role'] ?>')">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($user_role === 'admin' && $u['user_id'] != $user_id): ?>
                                                <button class="btn btn-danger btn-sm" onclick="deleteUser(<?= $u['user_id'] ?>, '<?= addslashes($u['username']) ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- User Details Modal View (Egg Records & Activity) -->
            <?php if ($selected_user_id > 0 && $canView): ?>
                <div id="userDetailsView" class="tab-pane active">
                    <div class="action-bar" style="margin-bottom: 1.5rem;">
                        <a href="user-management.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Users
                        </a>
                        <h3 style="margin: 0;">User Records: <?= htmlspecialchars($selectedUsername) ?></h3>
                        <div class="btn-group">
                            <div class="export-dropdown">
                                <button class="btn btn-primary">
                                    <i class="fas fa-download"></i> Export <i class="fas fa-chevron-down"></i>
                                </button>
                                <div class="export-dropdown-content">
                                    <a href="#" onclick="exportEggRecordsCSV(<?= $selected_user_id ?>)">
                                        <i class="fas fa-file-csv"></i> Export Egg Records (CSV)
                                    </a>
                                    <a href="#" onclick="exportActivityLogsCSV(<?= $selected_user_id ?>)">
                                        <i class="fas fa-file-csv"></i> Export Activity Logs (CSV)
                                    </a>
                                    <a href="#" onclick="exportEggRecordsPDF(<?= $selected_user_id ?>)">
                                        <i class="fas fa-file-pdf"></i> Export Egg Records (PDF)
                                    </a>
                                    <a href="#" onclick="exportActivityLogsPDF(<?= $selected_user_id ?>)">
                                        <i class="fas fa-file-pdf"></i> Export Activity Logs (PDF)
                                    </a>
                                    <a href="#" onclick="printEggRecords()">
                                        <i class="fas fa-print"></i> Print Egg Records
                                    </a>
                                    <a href="#" onclick="printActivityLogs()">
                                        <i class="fas fa-print"></i> Print Activity Logs
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Egg Batches Section -->
                    <div id="eggRecordsSection" class="table-container" style="margin-bottom: 1.5rem;">
                        <div class="table-header">
                            <h3><i class="fas fa-egg"></i> Egg Batches</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table" id="eggRecordsTable">
                                <thead>
                                    <tr>
                                        <th>Batch #</th>
                                        <th>Total Eggs</th>
                                        <th>Balut</th>
                                        <th>Chicks</th>
                                        <th>Failed</th>
                                        <th>Status</th>
                                        <th>Started</th>
                                        <th>Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($userEggRecords)): ?>
                                        <tr>
                                            <td colspan="8" style="text-align: center; color: #64748b; padding: 2rem;">
                                                <i class="fas fa-info-circle"></i> No egg batches found for this user
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($userEggRecords as $batch): ?>
                                            <tr>
                                                <td><strong>#<?= htmlspecialchars($batch['batch_number'] ?? $batch['egg_id']) ?></strong></td>
                                                <td><?= number_format($batch['total_egg']) ?></td>
                                                <td><?= number_format($batch['balut_count']) ?></td>
                                                <td><?= number_format($batch['chick_count']) ?></td>
                                                <td><?= number_format($batch['failed_count']) ?></td>
                                                <td>
                                                    <span class="badge <?= $batch['status'] == 'incubating' ? 'badge-warning' : 'badge-success' ?>">
                                                        <?= ucfirst($batch['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('M d, Y', strtotime($batch['date_started_incubation'])) ?></td>
                                                <td><?= $batch['days_in_incubation'] ?? 0 ?> days</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Activity Logs Section -->
                    <div id="activityLogsSection" class="table-container">
                        <div class="table-header">
                            <h3><i class="fas fa-history"></i> Activity History</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table" id="activityLogsTable">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($userActivities)): ?>
                                        <tr>
                                            <td colspan="2" style="text-align: center; color: #64748b; padding: 2rem;">
                                                <i class="fas fa-info-circle"></i> No activity logs found for this user
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($userActivities as $activity): ?>
                                            <tr>
                                                <td class="activity-time"><?= timeAgo($activity['log_date']) ?> <br><small><?= date('Y-m-d H:i:s', strtotime($activity['log_date'])) ?></small></td>
                                                <td><?= htmlspecialchars($activity['action']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Generating PDF...</div>
    </div>

    <!-- Add/Edit User Modal -->
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
                        <input type="password" id="modalPassword" name="password">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select id="modalRole" name="role">
                            <?php if ($user_role === 'admin'): ?>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="user">Regular User</option>
                            <?php else: ?>
                                <option value="user">Regular User</option>
                            <?php endif; ?>
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

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMsg"></span>
    </div>

    <script>
        const userRole = '<?= $user_role ?>';
        const currentUserId = <?= (int)$user_id ?>;

        // Search/Filter function
        function filterUsers() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#userTableBody tr');

            rows.forEach(row => {
                const username = row.getAttribute('data-username') || '';
                if (username.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add New User';
            document.getElementById('editUserId').value = '';
            document.getElementById('modalUsername').value = '';
            document.getElementById('modalPassword').value = '';
            document.getElementById('modalPassword').required = true;
            document.getElementById('passwordLabel').textContent = 'Password';
            document.getElementById('userModal').classList.add('active');
        }

        function openEditModal(id, username, role) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit User';
            document.getElementById('editUserId').value = id;
            document.getElementById('modalUsername').value = username;
            document.getElementById('modalPassword').value = '';
            document.getElementById('modalPassword').required = false;
            document.getElementById('passwordLabel').textContent = 'Password (leave blank to keep unchanged)';
            document.getElementById('modalRole').value = role;
            document.getElementById('userModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
        }

        // Save user (Create/Edit)
        async function saveUser(event) {
            event.preventDefault();

            const id = document.getElementById('editUserId').value;
            const username = document.getElementById('modalUsername').value.trim();
            const password = document.getElementById('modalPassword').value;
            const role = document.getElementById('modalRole').value;
            const action = id ? 'edit_user' : 'create_user';

            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner"></span> Saving...';

            const formData = new FormData();
            formData.append('action', action);
            formData.append('username', username);
            formData.append('password', password);
            formData.append('role', role);
            if (id) formData.append('user_id', id);

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast(result.message, 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                }
            } catch (error) {
                showToast('An error occurred. Please try again.', 'error');
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
            }
        }

        // Delete user
        async function deleteUser(id, username) {
            if (!confirm(`Are you sure you want to delete user "${username}"? This action cannot be undone.`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', id);

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('An error occurred. Please try again.', 'error');
            }
        }

        // Toast notification
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMsg = document.getElementById('toastMsg');

            toastMsg.textContent = message;
            toast.className = `toast show ${type}`;

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // ========== EXPORT FUNCTIONS ==========

        // Export Egg Records as CSV
        function exportEggRecordsCSV(userId) {
            window.location.href = `?export=egg_records&user_id=${userId}`;
            showToast('Exporting egg records...', 'info');
        }

        // Export Activity Logs as CSV
        function exportActivityLogsCSV(userId) {
            window.location.href = `?export=activity_logs&user_id=${userId}`;
            showToast('Exporting activity logs...', 'info');
        }

        // Export Egg Records as PDF
        async function exportEggRecordsPDF(userId) {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.classList.add('active');

            try {
                const element = document.getElementById('eggRecordsSection');
                const originalTitle = document.querySelector('.page-title h1').innerText;
                const username = document.querySelector('.action-bar h3').innerText.replace('User Records: ', '');

                // Create a clone for PDF
                const clone = element.cloneNode(true);
                clone.style.width = '800px';
                clone.style.padding = '20px';
                clone.style.backgroundColor = 'white';
                clone.style.borderRadius = '0';
                clone.style.boxShadow = 'none';

                // Add title to clone
                const titleDiv = document.createElement('div');
                titleDiv.innerHTML = `
                    <div style="text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #10b981;">
                        <h1 style="color: #1e293b; margin: 0;">EggFlow - Egg Records</h1>
                        <p style="color: #64748b; margin: 5px 0 0;">User: ${username} | Generated: ${new Date().toLocaleString()}</p>
                    </div>
                `;
                clone.insertBefore(titleDiv, clone.firstChild);

                document.body.appendChild(clone);

                const canvas = await html2canvas(clone, {
                    scale: 2,
                    backgroundColor: '#ffffff',
                    logging: false
                });

                document.body.removeChild(clone);

                const imgData = canvas.toDataURL('image/png');
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgWidth = 210;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;

                pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
                pdf.save(`egg_records_${username}_${new Date().toISOString().split('T')[0]}.pdf`);

                showToast('PDF exported successfully!', 'success');
            } catch (error) {
                console.error('PDF generation error:', error);
                showToast('Error generating PDF. Please try again.', 'error');
            } finally {
                loadingOverlay.classList.remove('active');
            }
        }

        // Export Activity Logs as PDF
        async function exportActivityLogsPDF(userId) {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.classList.add('active');

            try {
                const element = document.getElementById('activityLogsSection');
                const username = document.querySelector('.action-bar h3').innerText.replace('User Records: ', '');

                const clone = element.cloneNode(true);
                clone.style.width = '800px';
                clone.style.padding = '20px';
                clone.style.backgroundColor = 'white';
                clone.style.borderRadius = '0';
                clone.style.boxShadow = 'none';

                const titleDiv = document.createElement('div');
                titleDiv.innerHTML = `
                    <div style="text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #10b981;">
                        <h1 style="color: #1e293b; margin: 0;">EggFlow - Activity Logs</h1>
                        <p style="color: #64748b; margin: 5px 0 0;">User: ${username} | Generated: ${new Date().toLocaleString()}</p>
                    </div>
                `;
                clone.insertBefore(titleDiv, clone.firstChild);

                document.body.appendChild(clone);

                const canvas = await html2canvas(clone, {
                    scale: 2,
                    backgroundColor: '#ffffff',
                    logging: false
                });

                document.body.removeChild(clone);

                const imgData = canvas.toDataURL('image/png');
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgWidth = 210;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;

                pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
                pdf.save(`activity_logs_${username}_${new Date().toISOString().split('T')[0]}.pdf`);

                showToast('PDF exported successfully!', 'success');
            } catch (error) {
                console.error('PDF generation error:', error);
                showToast('Error generating PDF. Please try again.', 'error');
            } finally {
                loadingOverlay.classList.remove('active');
            }
        }

        // Print Egg Records
        function printEggRecords() {
            const originalTitle = document.title;
            const content = document.getElementById('eggRecordsSection').cloneNode(true);
            const username = document.querySelector('.action-bar h3').innerText.replace('User Records: ', '');

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>EggFlow - Egg Records - ${username}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #1e293b; text-align: center; }
                        .header { text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #10b981; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .badge { padding: 2px 6px; border-radius: 4px; font-size: 12px; }
                        .badge-warning { background: #fef3c7; color: #92400e; }
                        .badge-success { background: #dcfce7; color: #166534; }
                        @media print {
                            body { margin: 0; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>EggFlow - Egg Records</h1>
                        <p>User: ${username} | Generated: ${new Date().toLocaleString()}</p>
                    </div>
                    ${content.innerHTML}
                    <p style="margin-top: 20px; text-align: center; font-size: 12px; color: #666;">
                        Generated from EggFlow System
                    </p>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
            showToast('Print dialog opened', 'info');
        }

        // Print Activity Logs
        function printActivityLogs() {
            const content = document.getElementById('activityLogsSection').cloneNode(true);
            const username = document.querySelector('.action-bar h3').innerText.replace('User Records: ', '');

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>EggFlow - Activity Logs - ${username}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #1e293b; text-align: center; }
                        .header { text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #10b981; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        @media print {
                            body { margin: 0; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>EggFlow - Activity Logs</h1>
                        <p>User: ${username} | Generated: ${new Date().toLocaleString()}</p>
                    </div>
                    ${content.innerHTML}
                    <p style="margin-top: 20px; text-align: center; font-size: 12px; color: #666;">
                        Generated from EggFlow System
                    </p>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
            showToast('Print dialog opened', 'info');
        }

        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Update timestamp displaya
        function updateTimestamps() {
            // Timestamps are already formatted
        }

        // Auto-refresh timestamps every 30 seconds
        setInterval(updateTimestamps, 30000);
    </script>
</body>

</html>