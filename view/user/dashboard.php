<?php
require '../../model/config.php';

// Fix the role check condition
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'user') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize variables
$incubating = 0;
$complete = 0;
$chicks = 0;
$batches_count = 0;
$success_rate = 0;
$batches = [];
$logs = [];

// Check if connection exists
if (!isset($conn) || $conn === null) {
    die("Database connection not established. Please check your config.php file.");
}

// Handle Add Batch
if (isset($_POST['add_batch'])) {
    $total_egg = intval($_POST['total_egg']);

    if ($total_egg <= 0) {
        $_SESSION['error'] = "Please enter a valid number of eggs.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        // Get the next batch number
        $stmt = $conn->prepare("SELECT COALESCE(MAX(batch_number), 0) + 1 as next_batch FROM egg WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_batch = $result['next_batch'];

        // Insert new batch
        $stmt = $conn->prepare("INSERT INTO egg (user_id, total_egg, status, date_started_incubation, batch_number) 
                               VALUES (?, ?, 'incubating', NOW(), ?)");
        if ($stmt->execute([$user_id, $total_egg, $next_batch])) {
            $egg_id = $conn->lastInsertId();

            // Log activity
            $log_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
            $log_stmt->execute([$user_id, "Added new batch #{$next_batch} with {$total_egg} eggs"]);

            $_SESSION['success'] = "Batch #{$next_batch} added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit;
}

// Handle Delete Batch
if (isset($_POST['delete_batch'])) {
    $egg_id = intval($_POST['egg_id']);

    try {
        // Get batch number for logging
        $stmt = $conn->prepare("SELECT batch_number FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($batch) {
            // Delete batch (cascade will delete daily logs)
            $stmt = $conn->prepare("DELETE FROM egg WHERE egg_id = ? AND user_id = ?");
            if ($stmt->execute([$egg_id, $user_id])) {
                // Log activity
                $log_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
                $log_stmt->execute([$user_id, "Deleted batch #{$batch['batch_number']}"]);

                $_SESSION['success'] = "Batch deleted successfully!";
            } else {
                $_SESSION['error'] = "Failed to delete batch.";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit;
}

// Handle Daily Update
if (isset($_POST['update_daily'])) {
    $egg_id = intval($_POST['egg_id']);
    $failed_count = intval($_POST['failed_count']);
    $balut_count = intval($_POST['balut_count']);
    $chick_count = intval($_POST['chick_count']);

    // Validate inputs are not negative
    if ($failed_count < 0 || $balut_count < 0 || $chick_count < 0) {
        $_SESSION['error'] = "Values cannot be negative.";
        header("Location: dashboard.php");
        exit;
    }

    // Check if at least one value is entered
    if ($failed_count + $balut_count + $chick_count == 0) {
        $_SESSION['error'] = "Please enter at least one value greater than 0.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        // Verify the egg belongs to this user and get current totals
        $stmt = $conn->prepare("SELECT * FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $egg = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($egg) {
            // Calculate remaining eggs
            $total_processed = $egg['failed_count'] + $egg['balut_count'] + $egg['chick_count'];
            $remaining_eggs = $egg['total_egg'] - $total_processed;

            // Check if the total input exceeds remaining eggs
            $total_input = $failed_count + $balut_count + $chick_count;

            if ($total_input > $remaining_eggs) {
                $_SESSION['error'] = "Total input ({$total_input}) exceeds remaining eggs ({$remaining_eggs}). Please adjust your entries.";
                header("Location: dashboard.php");
                exit;
            }

            // Get current day number
            $start = new DateTime($egg['date_started_incubation']);
            $today = new DateTime();
            $day_number = $start->diff($today)->days + 1;

            // Check if log for this day already exists
            $check_stmt = $conn->prepare("SELECT log_id FROM egg_daily_logs WHERE egg_id = ? AND day_number = ?");
            $check_stmt->execute([$egg_id, $day_number]);

            if ($check_stmt->fetch()) {
                $_SESSION['error'] = "Daily log for day {$day_number} already exists.";
            } else {
                // Insert daily log
                $log_stmt = $conn->prepare("INSERT INTO egg_daily_logs (egg_id, day_number, failed_count, balut_count, chick_count) 
                                          VALUES (?, ?, ?, ?, ?)");
                if ($log_stmt->execute([$egg_id, $day_number, $failed_count, $balut_count, $chick_count])) {

                    // Update egg totals
                    $update_stmt = $conn->prepare("UPDATE egg SET 
                                                  failed_count = failed_count + ?,
                                                  balut_count = balut_count + ?,
                                                  chick_count = chick_count + ?
                                                  WHERE egg_id = ?");
                    $update_stmt->execute([$failed_count, $balut_count, $chick_count, $egg_id]);

                    // Check if all eggs are processed
                    $new_total_processed = $total_processed + $total_input;

                    if ($new_total_processed >= $egg['total_egg']) {
                        // Update status to complete
                        $status_stmt = $conn->prepare("UPDATE egg SET status = 'complete' WHERE egg_id = ?");
                        $status_stmt->execute([$egg_id]);
                        $status_message = " Batch completed!";
                    } else {
                        $status_message = "";
                    }

                    // Log activity
                    $activity_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
                    $action = "Updated batch #{$egg['batch_number']} - Day {$day_number}: Failed:{$failed_count}, Balut:{$balut_count}, Chicks:{$chick_count}";
                    $activity_stmt->execute([$user_id, $action]);

                    $_SESSION['success'] = "Batch #{$egg['batch_number']} updated successfully!{$status_message}";
                } else {
                    $_SESSION['error'] = "Failed to update batch.";
                }
            }
        } else {
            $_SESSION['error'] = "Invalid batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit;
}

// Fetch user's batches
try {
    $stmt = $conn->prepare("SELECT * FROM egg WHERE user_id = ? ORDER BY date_started_incubation DESC");
    $stmt->execute([$user_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching batches: " . $e->getMessage();
    $batches = [];
}

// Calculate statistics
$incubating = 0;
$complete = 0;
$chicks = 0;
$total_eggs = 0;
$processed_eggs = 0;

foreach ($batches as $batch) {
    if ($batch['status'] == 'incubating') {
        $incubating++;
    } elseif ($batch['status'] == 'complete') {
        $complete++;
    }

    $chicks += $batch['chick_count'];
    $total_eggs += $batch['total_egg'];
    $processed_eggs += ($batch['failed_count'] + $batch['balut_count'] + $batch['chick_count']);
}

$batches_count = count($batches);
$success_rate = $total_eggs > 0 ? ($processed_eggs > 0 ? ($chicks / $processed_eggs) * 100 : 0) : 0;

// Fetch recent activity logs
try {
    $log_stmt = $conn->prepare("SELECT * FROM user_activity_logs WHERE user_id = ? ORDER BY log_date DESC LIMIT 10");
    $log_stmt->execute([$user_id]);
    $logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching logs: " . $e->getMessage();
    $logs = [];
}

// Get remaining eggs for each batch to use in JavaScript
$batch_remaining = [];
foreach ($batches as $batch) {
    $total_processed = $batch['failed_count'] + $batch['balut_count'] + $batch['chick_count'];
    $batch_remaining[$batch['egg_id']] = $batch['total_egg'] - $total_processed;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Egg Incubation System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* CSS Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f7fc;
            color: #2c3e50;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 25px 0;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-header {
            text-align: center;
            padding: 0 20px 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h2 {
            font-size: 24px;
            margin-bottom: 5px;
            color: white;
        }

        .sidebar-header p {
            font-size: 14px;
            opacity: 0.8;
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
        }

        .sidebar-menu li {
            margin: 5px 15px;
        }

        .sidebar-menu li a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            padding: 12px 20px;
            border-radius: 8px;
            transition: all 0.3s;
            font-size: 15px;
        }

        .sidebar-menu li a i {
            width: 25px;
            margin-right: 10px;
            font-size: 18px;
        }

        .sidebar-menu li.active a,
        .sidebar-menu li a:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 20px 30px;
            min-height: 100vh;
            background: #f4f7fc;
        }

        /* Header Bar */
        .top-bar {
            background: white;
            border-radius: 12px;
            padding: 20px 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-text h1 {
            font-size: 24px;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .welcome-text p {
            color: #7f8c8d;
            font-size: 14px;
        }

        .date-display {
            background: #f8f9fa;
            padding: 10px 20px;
            border-radius: 8px;
            color: #2c3e50;
            font-weight: 500;
        }

        .date-display i {
            margin-right: 8px;
            color: #2a5298;
        }

        /* Messages */
        .message-container {
            margin-bottom: 25px;
        }

        .success-message,
        .error-message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            animation: slideDown 0.3s ease;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .success-message i,
        .error-message i {
            font-size: 20px;
            margin-right: 12px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }

        .stat-info h3 {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-info p {
            font-size: 32px;
            font-weight: bold;
            color: #1e3c72;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-icon i {
            font-size: 28px;
            color: white;
        }

        /* Action Button */
        .action-bar {
            margin-bottom: 25px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(30, 60, 114, 0.4);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #1e3c72;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-header h3 {
            color: #1e3c72;
            font-size: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 15px 12px;
            text-align: center;
            border-bottom: 2px solid #e9ecef;
        }

        td {
            padding: 15px 12px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
            color: #2c3e50;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-incubating {
            background: #fff3cd;
            color: #856404;
        }

        .status-complete {
            background: #d4edda;
            color: #155724;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        /* Activity Logs */
        .activity-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .activity-section h3 {
            color: #1e3c72;
            margin-bottom: 20px;
            font-size: 18px;
        }

        .activity-list {
            list-style: none;
        }

        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-time {
            background: #f8f9fa;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            color: #2a5298;
            font-weight: 500;
        }

        .activity-text {
            color: #2c3e50;
            flex: 1;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s ease;
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
        }

        .modal-header h3 {
            color: #1e3c72;
            font-size: 20px;
        }

        .modal-body {
            padding: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2a5298;
            box-shadow: 0 0 0 3px rgba(42, 82, 152, 0.1);
        }

        .remaining-info {
            background: #e8f4fd;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #1e3c72;
            border-left: 4px solid #2a5298;
        }

        .remaining-info i {
            margin-right: 8px;
            color: #2a5298;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #cbd5e0;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }

            .sidebar-header h2,
            .sidebar-header p,
            .sidebar-menu li a span {
                display: none;
            }

            .sidebar-menu li a {
                justify-content: center;
                padding: 12px;
            }

            .sidebar-menu li a i {
                margin-right: 0;
            }

            .main-content {
                margin-left: 70px;
                padding: 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2>🥚 Egg System</h2>
            <p>Incubation Tracker</p>
        </div>
        <ul class="sidebar-menu">
            <li class="active">
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="../../controller/auth/signout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="welcome-text">
                <h1>Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?>!</h1>
                <p>Here's what's happening with your incubation today.</p>
            </div>
            <div class="date-display">
                <i class="far fa-calendar-alt"></i>
                <?= date('l, F j, Y') ?>
            </div>
        </div>

        <!-- Messages -->
        <div class="message-container">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Incubating Batches</h3>
                    <p><?= number_format($incubating) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-egg"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Completed Batches</h3>
                    <p><?= number_format($complete) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Total Chicks</h3>
                    <p><?= number_format($chicks) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-hat-wizard"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Total Batches</h3>
                    <p><?= number_format($batches_count) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Success Rate</h3>
                    <p><?= number_format($success_rate, 1) ?>%</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <button class="btn btn-primary" onclick="openModal('addModal')">
                <i class="fas fa-plus-circle"></i>
                Add New Batch
            </button>
        </div>

        <!-- Batches Table -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list" style="margin-right: 8px;"></i>Your Batches</h3>
            </div>

            <?php if (empty($batches)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No batches found. Click "Add New Batch" to get started!</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Batch #</th>
                            <th>Total Eggs</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>Day</th>
                            <th>Balut</th>
                            <th>Chicks</th>
                            <th>Failed</th>
                            <th>Remaining</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch):
                            $start = new DateTime($batch['date_started_incubation']);
                            $today = new DateTime();
                            $day_since = $start->diff($today)->days + 1;
                            $total_processed = $batch['failed_count'] + $batch['balut_count'] + $batch['chick_count'];
                            $remaining = $batch['total_egg'] - $total_processed;
                        ?>
                            <tr>
                                <td><strong>#<?= $batch['batch_number'] ?></strong></td>
                                <td><?= number_format($batch['total_egg']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($batch['status']) ?>">
                                        <?= ucfirst($batch['status']) ?>
                                    </span>
                                </td>
                                <td><?= date("M d, Y", strtotime($batch['date_started_incubation'])) ?></td>
                                <td>Day <?= $day_since ?></td>
                                <td><?= number_format($batch['balut_count']) ?></td>
                                <td><?= number_format($batch['chick_count']) ?></td>
                                <td><?= number_format($batch['failed_count']) ?></td>
                                <td><strong><?= number_format($remaining) ?></strong></td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($batch['status'] == 'incubating' && $remaining > 0): ?>
                                            <button class="btn btn-success btn-sm" onclick="openUpdateModal(<?= $batch['egg_id'] ?>, <?= $day_since ?>, <?= $remaining ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this batch? This action cannot be undone.');">
                                            <input type="hidden" name="egg_id" value="<?= $batch['egg_id'] ?>">
                                            <button class="btn btn-danger btn-sm" type="submit" name="delete_batch">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <?php if (!empty($logs)): ?>
            <div class="activity-section">
                <h3><i class="fas fa-history" style="margin-right: 8px;"></i>Recent Activity</h3>
                <div class="activity-list">
                    <?php foreach ($logs as $log): ?>
                        <div class="activity-item">
                            <span class="activity-time">
                                <?= date("M d, H:i", strtotime($log['log_date'])) ?>
                            </span>
                            <span class="activity-text">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Add Batch Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle" style="margin-right: 8px;"></i>Add New Batch</h3>
            </div>
            <form method="post" onsubmit="return validateAddForm()">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Total Eggs</label>
                        <input type="number" name="total_egg" id="total_egg" min="1" placeholder="Enter number of eggs" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" name="add_batch" class="btn btn-success">Save Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Modal -->
    <div class="modal" id="updateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="margin-right: 8px;"></i>Update Batch - Day <span id="modalDayNumber">1</span></h3>
            </div>
            <form method="post" onsubmit="return validateUpdateForm()">
                <div class="modal-body">
                    <input type="hidden" name="egg_id" id="updateEggId">
                    <div class="remaining-info" id="remainingInfo">
                        <i class="fas fa-info-circle"></i>
                        <span id="remainingEggs">0</span> eggs remaining in this batch
                    </div>
                    <div class="form-group">
                        <label>Failed Eggs</label>
                        <input type="number" name="failed_count" id="failed_count" min="0" value="0" placeholder="Number of failed eggs" oninput="checkRemaining()">
                    </div>
                    <div class="form-group">
                        <label>Balut</label>
                        <input type="number" name="balut_count" id="balut_count" min="0" value="0" placeholder="Number of balut" oninput="checkRemaining()">
                    </div>
                    <div class="form-group">
                        <label>Chicks</label>
                        <input type="number" name="chick_count" id="chick_count" min="0" value="0" placeholder="Number of hatched chicks" oninput="checkRemaining()">
                    </div>
                    <div id="validationMessage" style="color: #dc3545; font-size: 14px; margin-top: 10px; display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeModal('updateModal')">Cancel</button>
                    <button type="submit" name="update_daily" class="btn btn-success" id="submitBtn">Update Batch</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // JavaScript Code
        // Store remaining eggs data
        const batchRemaining = <?= json_encode($batch_remaining) ?>;
        let currentRemaining = 0;

        // Modal Functions
        function openModal(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = 'auto';

            // Reset form
            const modal = document.getElementById(id);
            const form = modal.querySelector('form');
            if (form) form.reset();

            // Hide validation message if visible
            const validationMsg = document.getElementById('validationMessage');
            if (validationMsg) validationMsg.style.display = 'none';
        }

        function openUpdateModal(id, day, remaining) {
            document.getElementById('updateEggId').value = id;
            document.getElementById('modalDayNumber').innerText = day;
            document.getElementById('remainingEggs').innerText = remaining;
            currentRemaining = remaining;

            document.getElementById('failed_count').value = 0;
            document.getElementById('balut_count').value = 0;
            document.getElementById('chick_count').value = 0;

            // Hide validation message
            document.getElementById('validationMessage').style.display = 'none';

            openModal('updateModal');
        }

        // Check if total input exceeds remaining eggs
        function checkRemaining() {
            const failed = parseInt(document.getElementById('failed_count').value) || 0;
            const balut = parseInt(document.getElementById('balut_count').value) || 0;
            const chick = parseInt(document.getElementById('chick_count').value) || 0;

            const total = failed + balut + chick;
            const validationMsg = document.getElementById('validationMessage');
            const submitBtn = document.getElementById('submitBtn');

            if (total > currentRemaining) {
                validationMsg.innerHTML = `<i class="fas fa-exclamation-circle"></i> Total (${total}) exceeds remaining eggs (${currentRemaining})`;
                validationMsg.style.display = 'block';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
                return false;
            } else {
                validationMsg.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
                return true;
            }
        }

        // Form Validation
        function validateAddForm() {
            const totalEggs = document.getElementById('total_egg').value;
            if (!totalEggs || totalEggs <= 0) {
                alert('Please enter a valid number of eggs (greater than 0).');
                return false;
            }
            return true;
        }

        function validateUpdateForm() {
            const failed = parseInt(document.getElementById('failed_count').value) || 0;
            const balut = parseInt(document.getElementById('balut_count').value) || 0;
            const chick = parseInt(document.getElementById('chick_count').value) || 0;

            if (failed < 0 || balut < 0 || chick < 0) {
                alert('Values cannot be negative.');
                return false;
            }

            if (failed + balut + chick === 0) {
                alert('Please enter at least one value greater than 0.');
                return false;
            }

            // Check if total exceeds remaining
            if (!checkRemaining()) {
                return false;
            }

            return true;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active');
                if (activeModal) {
                    activeModal.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            }
        });

        // Auto-hide messages after 5 seconds
        setTimeout(function() {
            const messages = document.querySelectorAll('.success-message, .error-message');
            messages.forEach(function(message) {
                message.style.opacity = '0';
                setTimeout(function() {
                    message.style.display = 'none';
                }, 300);
            });
        }, 5000);
    </script>
</body>

</html>