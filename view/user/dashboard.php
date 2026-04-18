<?php

require '../../model/config.php';

// Check if user is logged in and has user role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'user') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Check database connection
if (!isset($conn) || $conn === null) {
    die("Database connection not established. Please check config.php");
}

// ── Incubation constants ─────────────────────────────────────────────────────
define('BALUT_UNLOCK_DAY', 14);
define('CHICK_UNLOCK_DAY', 25);
define('EXPECTED_HATCH_DAY', 28);

// ── Real-time Day calculation function ──────────────────────────────────────
function getCurrentDay($dateStarted)
{
    if (empty($dateStarted)) return 1;

    $start = new DateTime($dateStarted);
    $today = new DateTime('now');
    $diff = $start->diff($today)->days;

    // Day 1 is the start day, so add 1 to the difference
    $day = $diff + 1;

    // Cap at 35 days maximum
    return min($day, 35);
}

// ── Add Batch ─────────────────────────────────────────────────────────────
if (isset($_POST['add_batch'])) {
    $total_egg = intval($_POST['total_egg']);
    if ($total_egg <= 0) {
        $_SESSION['error'] = "Please enter a valid number of eggs.";
        header("Location: dashboard.php");
        exit;
    }
    try {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(batch_number), 0) + 1 AS next_batch FROM egg WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $next_batch = $stmt->fetch(PDO::FETCH_ASSOC)['next_batch'];

        $stmt = $conn->prepare("INSERT INTO egg (user_id, total_egg, status, date_started_incubation, batch_number, failed_count, balut_count, chick_count) VALUES (?, ?, 'incubating', NOW(), ?, 0, 0, 0)");
        if ($stmt->execute([$user_id, $total_egg, $next_batch])) {
            $log_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
            $log_stmt->execute([$user_id, "Added batch #{$next_batch} with {$total_egg} eggs"]);
            $_SESSION['success'] = "Batch #{$next_batch} added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "DB error: " . $e->getMessage();
    }
    header("Location: dashboard.php");
    exit;
}


// ── Delete Batch ─────────────────────────────────────────────────────────
if (isset($_POST['delete_batch'])) {
    $egg_id = intval($_POST['egg_id']);
    try {
        $stmt = $conn->prepare("SELECT batch_number FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($batch) {
            $conn->prepare("DELETE FROM egg_daily_logs WHERE egg_id = ?")->execute([$egg_id]);
            $conn->prepare("DELETE FROM egg WHERE egg_id = ? AND user_id = ?")->execute([$egg_id, $user_id]);
            $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)")->execute([$user_id, "Deleted batch #{$batch['batch_number']}"]);
            $_SESSION['success'] = "Batch deleted successfully.";
        } else {
            $_SESSION['error'] = "Batch not found.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "DB error: " . $e->getMessage();
    }
    header("Location: dashboard.php");
    exit;
}

// ── Daily Update ─────────────────────────────────────────────────────────
if (isset($_POST['update_daily'])) {
    $egg_id       = intval($_POST['egg_id']);
    $failed_count = intval($_POST['failed_count']);
    $balut_count  = intval($_POST['balut_count']);
    $chick_count  = intval($_POST['chick_count']);

    if ($failed_count < 0 || $balut_count < 0 || $chick_count < 0) {
        $_SESSION['error'] = "Values cannot be negative.";
        header("Location: dashboard.php");
        exit;
    }
    if ($failed_count + $balut_count + $chick_count == 0) {
        $_SESSION['error'] = "Enter at least one value greater than 0.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT * FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $egg = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($egg) {
            $current_day = getCurrentDay($egg['date_started_incubation']);

            if (($balut_count > 0 || $chick_count > 0) && $current_day < BALUT_UNLOCK_DAY) {
                $_SESSION['error'] = "Balut/chick fields unlock at Day " . BALUT_UNLOCK_DAY . ". Today is Day {$current_day}.";
                header("Location: dashboard.php");
                exit;
            }

            if ($chick_count > 0 && $current_day < CHICK_UNLOCK_DAY) {
                $_SESSION['error'] = "Chick harvest is only allowed from Day " . CHICK_UNLOCK_DAY . ". Today is Day {$current_day}.";
                header("Location: dashboard.php");
                exit;
            }

            $total_processed = $egg['failed_count'] + $egg['balut_count'] + $egg['chick_count'];
            $remaining       = $egg['total_egg'] - $total_processed;
            $total_input     = $failed_count + $balut_count + $chick_count;

            if ($total_input > $remaining) {
                $_SESSION['error'] = "Input ({$total_input}) exceeds remaining eggs ({$remaining}).";
                header("Location: dashboard.php");
                exit;
            }

            $chk = $conn->prepare("SELECT log_id FROM egg_daily_logs WHERE egg_id = ? AND day_number = ?");
            $chk->execute([$egg_id, $current_day]);

            if ($chk->fetch()) {
                $_SESSION['error'] = "Batch #{$egg['batch_number']} already updated for Day {$current_day}. Come back tomorrow.";
            } else {
                $insert_log = $conn->prepare("INSERT INTO egg_daily_logs (egg_id, day_number, failed_count, balut_count, chick_count) VALUES (?, ?, ?, ?, ?)");
                $insert_log->execute([$egg_id, $current_day, $failed_count, $balut_count, $chick_count]);

                $update_egg = $conn->prepare("UPDATE egg SET failed_count = failed_count + ?, balut_count = balut_count + ?, chick_count = chick_count + ? WHERE egg_id = ?");
                $update_egg->execute([$failed_count, $balut_count, $chick_count, $egg_id]);

                $new_processed = $total_processed + $total_input;
                $done = '';
                if ($new_processed >= $egg['total_egg']) {
                    $conn->prepare("UPDATE egg SET status = 'complete' WHERE egg_id = ?")->execute([$egg_id]);
                    $done = " Batch complete!";
                }

                $log_action = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
                $log_action->execute([$user_id, "Updated batch #{$egg['batch_number']} Day {$current_day}: F:{$failed_count} B:{$balut_count} C:{$chick_count}"]);

                $_SESSION['success'] = "Batch #{$egg['batch_number']} updated for Day {$current_day}!{$done}";
            }
        } else {
            $_SESSION['error'] = "Invalid batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "DB error: " . $e->getMessage();
    }
    header("Location: dashboard.php");
    exit;
}

// ── Data Fetch ───────────────────────────────────────────────────────────
try {
    $stmt = $conn->prepare("SELECT * FROM egg WHERE user_id = ? ORDER BY date_started_incubation DESC");
    $stmt->execute([$user_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $batches = [];
}

// Calculate current day for each batch and statistics
$stat_incubating = $stat_complete = $stat_chicks = $stat_balut = $stat_failed = 0;
$total_eggs = $processed_eggs = 0;
$batch_remaining = [];

foreach ($batches as &$b) {
    $b['current_day'] = getCurrentDay($b['date_started_incubation']);

    if ($b['status'] === 'incubating') $stat_incubating++;
    else $stat_complete++;
    $stat_chicks += $b['chick_count'];
    $stat_balut  += $b['balut_count'];
    $stat_failed += $b['failed_count'];
    $total_eggs  += $b['total_egg'];
    $proc = $b['failed_count'] + $b['balut_count'] + $b['chick_count'];
    $processed_eggs += $proc;
    $batch_remaining[$b['egg_id']] = $b['total_egg'] - $proc;
}

$success_rate = $processed_eggs > 0 ? round(($stat_chicks / $processed_eggs) * 100, 1) : 0;
$balut_rate   = $processed_eggs > 0 ? round(($stat_balut / $processed_eggs) * 100, 1) : 0;

// Fetch daily analytics
try {
    $stmt = $conn->prepare("SELECT edl.day_number, SUM(edl.balut_count) AS balut, SUM(edl.chick_count) AS chicks, SUM(edl.failed_count) AS failed FROM egg_daily_logs edl JOIN egg e ON edl.egg_id = e.egg_id WHERE e.user_id = ? GROUP BY edl.day_number ORDER BY edl.day_number ASC LIMIT 28");
    $stmt->execute([$user_id]);
    $daily_analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $daily_analytics = [];
}

// Fetch activity logs
try {
    $stmt = $conn->prepare("SELECT * FROM user_activity_logs WHERE user_id = ? ORDER BY log_date DESC LIMIT 15");
    $stmt->execute([$user_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
}

// Pre-build today_logged map
$today_logged_map = [];
foreach ($batches as $b) {
    $day = $b['current_day'];
    $chk = $conn->prepare("SELECT log_id FROM egg_daily_logs WHERE egg_id = ? AND day_number = ?");
    $chk->execute([$b['egg_id'], $day]);
    $today_logged_map[$b['egg_id']] = (bool)$chk->fetch();
}

// Pass PHP variables to JavaScript
$js_vars = [
    'totalBalut' => (int)$stat_balut,
    'totalChicks' => (int)$stat_chicks,
    'totalFailed' => (int)$stat_failed,
    'incubating' => (int)$stat_incubating,
    'complete' => (int)$stat_complete,
    'dailyAnalytics' => $daily_analytics,
    'batchRemaining' => $batch_remaining,
    'BALUT_UNLOCK' => BALUT_UNLOCK_DAY,
    'CHICK_UNLOCK' => CHICK_UNLOCK_DAY,
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Dashboard | EggFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/user/js/css/user_style.css">
    <link rel="stylesheet" href="../../assets/user/js/css/dashboard_responsive.css">
</head>

<body>
    <div class="dashboard">

        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-egg"></i> EggFlow</h2>
                <p>Incubation Tracker</p>
            </div>
            <ul class="sidebar-menu">
                <li class="nav-item active" data-tab="overview">
                    <a href="#"><i class="fas fa-tachometer-alt"></i> Overview</a>
                </li>
                <li class="nav-item" data-tab="batches">
                    <a href="#"><i class="fas fa-layer-group"></i> My Batches</a>
                </li>
                <li class="nav-item" data-tab="analytics">
                    <a href="#"><i class="fas fa-chart-line"></i> Analytics</a>
                </li>
                <li class="nav-item" data-tab="guide">
                    <a href="#"><i class="fas fa-book-open"></i> Incubation Guide</a>
                </li>
                <li>
                    <a href="../../controller/auth/signout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">

            <!-- Top Bar -->
            <div class="top-bar">
                <div class="welcome-text">
                    <h1>Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?>!</h1>
                    <p id="page-subtitle">Overview of your incubation batches</p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
            <?php unset($_SESSION['success']);
            endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
            <?php unset($_SESSION['error']);
            endif; ?>

            <!-- =================== OVERVIEW TAB =================== -->
            <div id="overview-section" class="tab-section active">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Incubating</h3>
                            <p><?= $stat_incubating ?></p>
                        </div>
                        <div class="stat-icon ic-yellow"><i class="fas fa-egg"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Completed</h3>
                            <p><?= $stat_complete ?></p>
                        </div>
                        <div class="stat-icon ic-green"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Balut</h3>
                            <p><?= number_format($stat_balut) ?></p>
                        </div>
                        <div class="stat-icon ic-blue"><i class="fas fa-drumstick-bite"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Chicks</h3>
                            <p><?= number_format($stat_chicks) ?></p>
                        </div>
                        <div class="stat-icon ic-purple"><i class="fas fa-dove"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Chick Rate</h3>
                            <p><?= $success_rate ?>%</p>
                        </div>
                        <div class="stat-icon ic-pink"><i class="fas fa-chart-line"></i></div>
                    </div>
                </div>

                <div class="timeline-card">
                    <div class="timeline-header">
                        <i class="fas fa-clock"></i>
                        <h3>Duck Egg Incubation Timeline</h3>
                        <span class="timeline-total">28 Days Total</span>
                    </div>
                    <div class="timeline-bar">
                        <div class="tl-segment seg-safe" style="width:46.4%"><span>Days 1–13</span><small>Embryo Development</small></div>
                        <div class="tl-segment seg-balut" style="width:17.9%"><span>Days 14–18</span><small>🥚 Balut Ready</small></div>
                        <div class="tl-segment seg-watch" style="width:25%"> <span>Days 19–25</span><small>Late Development</small></div>
                        <div class="tl-segment seg-hatch" style="width:10.7%"><span>Days 26–28</span><small>🐣 Hatching</small></div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3>Active Batches</h3>
                        <button class="btn btn-primary btn-sm" onclick="switchTab('batches')">
                            <i class="fas fa-layer-group"></i> View All
                        </button>
                    </div>
                    <?php
                    $active = array_filter($batches, function ($b) {
                        return $b['status'] === 'incubating';
                    });
                    ?>
                    <?php if (empty($active)): ?>
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <p>No active batches. <a href="#" onclick="switchTab('batches')">Add your first batch →</a></p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Batch #</th>
                                        <th>Total Eggs</th>
                                        <th>Day</th>
                                        <th>Progress</th>
                                        <th>Remaining</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active as $b):
                                        $day  = $b['current_day'];
                                        $proc = $b['failed_count'] + $b['balut_count'] + $b['chick_count'];
                                        $rem  = $b['total_egg'] - $proc;
                                        $pct  = $b['total_egg'] > 0 ? round(($proc / $b['total_egg']) * 100) : 0;
                                        $locked = $day < BALUT_UNLOCK_DAY;
                                        $logged = isset($today_logged_map[$b['egg_id']]) ? $today_logged_map[$b['egg_id']] : false;
                                    ?>
                                        <tr>
                                            <td data-label="Batch #"><strong>#<?= $b['batch_number'] ?></strong></td>
                                            <td data-label="Total Eggs"><?= number_format($b['total_egg']) ?></td>
                                            <td data-label="Day"><span class="day-badge <?= $locked ? 'day-early' : ($day >= CHICK_UNLOCK_DAY ? 'day-late' : 'day-mid') ?>">Day <?= $day ?></span></td>
                                            <td data-label="Progress">
                                                <div class="mini-progress">
                                                    <div class="mini-bar" style="width:<?= $pct ?>%"></div>
                                                </div>
                                                <small><?= $pct ?>%</small>
                                            </td>
                                            <td data-label="Remaining"><?= number_format($rem) ?></td>
                                            <td data-label="Action">
                                                <?php if ($logged): ?>
                                                    <span class="badge-done"><i class="fas fa-check"></i> Updated</span>
                                                <?php elseif ($rem > 0): ?>
                                                    <button class="btn btn-success btn-sm" onclick="openUpdateModal(<?= $b['egg_id'] ?>, <?= $day ?>, <?= $rem ?>, <?= $locked ? 'true' : 'false' ?>)">
                                                        <i class="fas fa-edit"></i> Update
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge-done">Complete</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($logs)): ?>
                    <div class="table-container">
                        <div class="table-header">
                            <h3>Recent Activity</h3>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($logs, 0, 8) as $log): ?>
                                        <tr>
                                            <td data-label="Time" class="activity-time"><?= date('M d, H:i', strtotime($log['log_date'])) ?></td>
                                            <td data-label="Action"><?= htmlspecialchars($log['action']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- =================== BATCHES TAB =================== -->
            <div id="batches-section" class="tab-section">
                <div class="action-bar">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus-circle"></i> Add New Batch
                    </button>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3>All Batches</h3>
                    </div>
                    <?php if (empty($batches)): ?>
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <p>No batches yet. Click "Add New Batch" to get started!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Batch #</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Started</th>
                                        <th>Day</th>
                                        <th>Balut</th>
                                        <th>Chicks</th>
                                        <th>Failed</th>
                                        <th>Remaining</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batches as $b):
                                        $day    = $b['current_day'];
                                        $proc   = $b['failed_count'] + $b['balut_count'] + $b['chick_count'];
                                        $rem    = $b['total_egg'] - $proc;
                                        $locked = $day < BALUT_UNLOCK_DAY;
                                        $logged = isset($today_logged_map[$b['egg_id']]) ? $today_logged_map[$b['egg_id']] : false;
                                    ?>
                                        <tr>
                                            <td data-label="Batch #"><strong>#<?= $b['batch_number'] ?></strong></td>
                                            <td data-label="Total"><?= number_format($b['total_egg']) ?></td>
                                            <td data-label="Status"><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
                                            <td data-label="Started"><?= date('M d, Y', strtotime($b['date_started_incubation'])) ?></td>
                                            <td data-label="Day"><span class="day-badge <?= $locked ? 'day-early' : ($day >= CHICK_UNLOCK_DAY ? 'day-late' : 'day-mid') ?>">Day <?= $day ?></span></td>
                                            <td data-label="Balut"><?= number_format($b['balut_count']) ?></td>
                                            <td data-label="Chicks"><?= number_format($b['chick_count']) ?></td>
                                            <td data-label="Failed"><?= number_format($b['failed_count']) ?></td>
                                            <td data-label="Remaining"><strong><?= number_format($rem) ?></strong></td>
                                            <td data-label="Actions">
                                                <div class="action-btns">
                                                    <?php if ($b['status'] === 'incubating' && $rem > 0 && !$logged): ?>
                                                        <button class="btn btn-warning btn-sm" onclick="openUpdateModal(<?= $b['egg_id'] ?>, <?= $day ?>, <?= $rem ?>, <?= $locked ? 'true' : 'false' ?>)">
                                                            <i class="fas fa-edit"></i> Update
                                                        </button>
                                                    <?php elseif ($logged && $b['status'] === 'incubating'): ?>
                                                        <span class="badge-done"><i class="fas fa-check"></i> Done today</span>
                                                    <?php endif; ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this batch? Cannot be undone.')">
                                                        <input type="hidden" name="egg_id" value="<?= $b['egg_id'] ?>">
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
                        </div>
                    <?php endif; ?>
                </div>

                <?php
                // Display daily logs for each batch with improved layout
                foreach ($batches as $b):
                    try {
                        $ls = $conn->prepare("SELECT * FROM egg_daily_logs WHERE egg_id = ? ORDER BY day_number ASC");
                        $ls->execute([$b['egg_id']]);
                        $dlogs = $ls->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $dlogs = [];
                    }
                    if (empty($dlogs)) continue;

                    // Calculate totals for this batch
                    $total_balut = array_sum(array_column($dlogs, 'balut_count'));
                    $total_chicks = array_sum(array_column($dlogs, 'chick_count'));
                    $total_failed = array_sum(array_column($dlogs, 'failed_count'));
                ?>
                    <div class="batch-logs-section">
                        <div class="batch-logs-header">
                            <h4>
                                <i class="fas fa-history"></i>
                                Batch #<?= $b['batch_number'] ?> — Daily Log
                                <span class="batch-badge"><?= count($dlogs) ?> days recorded</span>
                            </h4>
                        </div>

                        <!-- Summary stats for the batch -->
                        <div class="logs-summary">
                            <span class="logs-summary-item"><i class="fas fa-drumstick-bite" style="color:#f59e0b"></i> Balut: <?= number_format($total_balut) ?></span>
                            <span class="logs-summary-item"><i class="fas fa-dove" style="color:#10b981"></i> Chicks: <?= number_format($total_chicks) ?></span>
                            <span class="logs-summary-item"><i class="fas fa-times-circle" style="color:#ef4444"></i> Failed: <?= number_format($total_failed) ?></span>
                        </div>

                        <!-- Desktop view: Standard table -->
                        <div class="table-wrapper daily-logs-desktop">
                            <table class="data-table keep-standard">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Balut</th>
                                        <th>Chicks</th>
                                        <th>Failed</th>
                                        <th>Date Logged</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dlogs as $dl): ?>
                                        <tr>
                                            <td data-label="Day">Day <?= $dl['day_number'] ?></td>
                                            <td data-label="Balut"><?= number_format($dl['balut_count']) ?></td>
                                            <td data-label="Chicks"><?= number_format($dl['chick_count']) ?></td>
                                            <td data-label="Failed"><?= number_format($dl['failed_count']) ?></td>
                                            <td data-label="Date Logged"><?= date('M d, Y', strtotime($dl['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile view: Card-based layout -->
                        <div class="daily-logs-mobile">
                            <?php foreach ($dlogs as $dl): ?>
                                <div class="log-card">
                                    <div class="log-card-header">
                                        <span class="log-day">Day <?= $dl['day_number'] ?></span>
                                        <span class="log-date"><i class="far fa-calendar-alt"></i> <?= date('M d, Y', strtotime($dl['created_at'])) ?></span>
                                    </div>
                                    <div class="log-stats">
                                        <span><i class="fas fa-drumstick-bite" style="color:#f59e0b"></i> Balut: <?= number_format($dl['balut_count']) ?></span>
                                        <span><i class="fas fa-dove" style="color:#10b981"></i> Chicks: <?= number_format($dl['chick_count']) ?></span>
                                        <span><i class="fas fa-times-circle" style="color:#ef4444"></i> Failed: <?= number_format($dl['failed_count']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- =================== ANALYTICS TAB =================== -->
            <div id="analytics-section" class="tab-section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Eggs</h3>
                            <p><?= number_format($total_eggs) ?></p>
                        </div>
                        <div class="stat-icon ic-green"><i class="fas fa-egg"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Balut Rate</h3>
                            <p><?= $balut_rate ?>%</p>
                        </div>
                        <div class="stat-icon ic-yellow"><i class="fas fa-percentage"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Chick Rate</h3>
                            <p><?= $success_rate ?>%</p>
                        </div>
                        <div class="stat-icon ic-blue"><i class="fas fa-dove"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Batches</h3>
                            <p><?= count($batches) ?></p>
                        </div>
                        <div class="stat-icon ic-purple"><i class="fas fa-layer-group"></i></div>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#10b981;margin-right:.5rem"></i>Outcome Distribution</h3>
                        <canvas id="pieChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-bar" style="color:#3b82f6;margin-right:.5rem"></i>Daily Production Log</h3>
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line" style="color:#f59e0b;margin-right:.5rem"></i>Batch Status</h3>
                        <canvas id="statusChart"></canvas>
                        <p style="text-align:center;color:#64748b;font-size:.85rem;margin-top:.75rem">
                            Incubating: <strong><?= $stat_incubating ?></strong> &nbsp;|&nbsp; Complete: <strong><?= $stat_complete ?></strong>
                        </p>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-info-circle" style="color:#8b5cf6;margin-right:.5rem"></i>Batch Summary</h3>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Eggs</th>
                                        <th>Balut</th>
                                        <th>Chicks</th>
                                        <th>Failed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batches as $b): ?>
                                        <tr>
                                            <td data-label="Batch">#<?= $b['batch_number'] ?></td>
                                            <td data-label="Eggs"><?= number_format($b['total_egg']) ?></td>
                                            <td data-label="Balut"><?= number_format($b['balut_count']) ?></td>
                                            <td data-label="Chicks"><?= number_format($b['chick_count']) ?></td>
                                            <td data-label="Failed"><?= number_format($b['failed_count']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =================== GUIDE TAB =================== -->
            <div id="guide-section" class="tab-section">
                <div class="guide-hero">
                    <i class="fas fa-egg guide-hero-icon"></i>
                    <div>
                        <h2>Duck Egg Incubation Guide</h2>
                        <p>Everything you need to know about balut production — from egg to table.</p>
                    </div>
                </div>

                <div class="guide-grid">
                    <div class="guide-card phase-early">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 1</span>
                            <h3>Early Development</h3>
                            <span class="phase-days">Days 1 – 13</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-thermometer-half"></i> Maintain 37.5–38°C (99.5–100.4°F)</li>
                            <li><i class="fas fa-tint"></i> Humidity: 55–65%</li>
                            <li><i class="fas fa-sync-alt"></i> Turn eggs 3–5× daily</li>
                            <li><i class="fas fa-search"></i> Candle at Day 7 to check fertility</li>
                            <li><i class="fas fa-ban"></i> <strong>Balut &amp; chick harvesting locked</strong></li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> Remove infertile or dead eggs during candling to prevent contamination.</div>
                    </div>

                    <div class="guide-card phase-balut">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 2</span>
                            <h3>Balut Harvest Window</h3>
                            <span class="phase-days">Days 14 – 18</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-egg"></i> Embryo well-developed — ideal for balut</li>
                            <li><i class="fas fa-fire"></i> Boil harvested eggs 20–30 minutes</li>
                            <li><i class="fas fa-star"></i> Day 17–18 is peak balut quality</li>
                            <li><i class="fas fa-temperature-low"></i> Refrigerate unboiled eggs if not eaten immediately</li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> The broth is rich in protein — crack a small hole and drink it first!</div>
                    </div>

                    <div class="guide-card phase-watch">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 3</span>
                            <h3>Late Incubation</h3>
                            <span class="phase-days">Days 19 – 25</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-eye"></i> Stop turning eggs at Day 25</li>
                            <li><i class="fas fa-tint"></i> Increase humidity to 70–75%</li>
                            <li><i class="fas fa-volume-up"></i> You may hear peeping inside the eggs</li>
                            <li><i class="fas fa-exclamation-triangle"></i> Avoid opening the incubator unnecessarily</li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> Mist eggs lightly with warm water daily during this phase.</div>
                    </div>

                    <div class="guide-card phase-hatch">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 4</span>
                            <h3>Hatching</h3>
                            <span class="phase-days">Days 26 – 28</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-egg"></i> Pipping begins around Day 26</li>
                            <li><i class="fas fa-clock"></i> Full hatch takes 12–24 hrs — do not help</li>
                            <li><i class="fas fa-child"></i> Remove dried chicks 12–24h after hatch</li>
                            <li><i class="fas fa-trash"></i> Discard unhatched eggs after Day 30</li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> Ducklings don't need food/water for 24h after hatch — yolk sac sustains them.</div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3>Quick Reference: What Can Be Logged Per Day</h3>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Days</th>
                                    <th>Failed Eggs</th>
                                    <th>Balut</th>
                                    <th>Chicks</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td data-label="Days">1 – 13</td>
                                    <td data-label="Failed Eggs"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Balut"><span class="badge-no">✗ Locked</span></td>
                                    <td data-label="Chicks"><span class="badge-no">✗ Locked</span></td>
                                    <td data-label="Notes">Only failed/infertile removal allowed</td>
                                </tr>
                                <tr>
                                    <td data-label="Days">14 – 18</td>
                                    <td data-label="Failed Eggs"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Balut"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Chicks"><span class="badge-no">✗ Locked</span></td>
                                    <td data-label="Notes">Peak balut harvest window</td>
                                </tr>
                                <tr>
                                    <td data-label="Days">19 – 24</td>
                                    <td data-label="Failed Eggs"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Balut"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Chicks"><span class="badge-no">✗ Locked</span></td>
                                    <td data-label="Notes">Late balut; monitor closely</td>
                                </tr>
                                <tr>
                                    <td data-label="Days">25 – 28</td>
                                    <td data-label="Failed Eggs"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Balut"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Chicks"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Notes">Hatching window</td>
                                </tr>
                                <tr>
                                    <td data-label="Days">29+</td>
                                    <td data-label="Failed Eggs"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Balut"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Chicks"><span class="badge-yes">✓ Yes</span></td>
                                    <td data-label="Notes">Discard unhatched after Day 30</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3>Troubleshooting</h3>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Problem</th>
                                    <th>Possible Cause</th>
                                    <th>Solution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td data-label="Problem">High failure rate early</td>
                                    <td data-label="Possible Cause">Infertile eggs / temp spike</td>
                                    <td data-label="Solution">Candle at Day 7; check thermostat</td>
                                </tr>
                                <tr>
                                    <td data-label="Problem">No hatching after Day 28</td>
                                    <td data-label="Possible Cause">Low humidity / temp too low</td>
                                    <td data-label="Solution">Increase humidity; verify calibration</td>
                                </tr>
                                <tr>
                                    <td data-label="Problem">Chick dies in shell</td>
                                    <td data-label="Possible Cause">Humidity too low at hatch</td>
                                    <td data-label="Solution">Mist eggs during lockdown phase</td>
                                </tr>
                                <tr>
                                    <td data-label="Problem">Bad smell from incubator</td>
                                    <td data-label="Possible Cause">Rotten/exploded egg</td>
                                    <td data-label="Solution">Remove immediately; clean incubator</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- Add Batch Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Batch</h3>
                <button class="close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Total Eggs</label>
                        <input type="number" name="total_egg" min="1" placeholder="e.g. 100" required>
                    </div>
                    <div class="guide-tip" style="margin-top:1rem">
                        <i class="fas fa-info-circle"></i>
                        Today is Day 1. The day will automatically increment each day.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" name="add_batch" class="btn btn-primary">Start Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Update Batch — Day <span id="modalDay">1</span></h3>
                <button class="close" onclick="closeModal('updateModal')">&times;</button>
            </div>
            <form method="post" onsubmit="return validateUpdate()">
                <input type="hidden" name="egg_id" id="updateEggId">
                <div class="modal-body">
                    <div class="remaining-info">
                        <i class="fas fa-info-circle"></i>
                        <span id="remainingText">0 eggs remaining</span>
                    </div>
                    <div id="lockNotice" class="lock-notice" style="display:none">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Balut &amp; Chick updates locked</strong>
                            <p>Unlocks at Day <?= BALUT_UNLOCK_DAY ?>. Only failed egg removal is allowed now.</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-times-circle" style="color:#ef4444"></i> Failed Eggs</label>
                        <input type="number" name="failed_count" id="failedInput" min="0" value="0" oninput="checkTotal()">
                    </div>
                    <div class="form-group" id="balutGroup">
                        <label><i class="fas fa-drumstick-bite" style="color:#f59e0b"></i> Balut Harvested</label>
                        <input type="number" name="balut_count" id="balutInput" min="0" value="0" oninput="checkTotal()">
                    </div>
                    <div class="form-group" id="chickGroup">
                        <label><i class="fas fa-dove" style="color:#10b981"></i> Chicks Hatched</label>
                        <input type="number" name="chick_count" id="chickInput" min="0" value="0" oninput="checkTotal()">
                    </div>
                    <div id="validationMsg" class="alert alert-error" style="display:none;margin-top:.5rem"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updateModal')">Cancel</button>
                    <button type="submit" name="update_daily" class="btn btn-primary" id="submitUpdateBtn">Save Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Pass PHP variables to JavaScript
        window.EggFlowConfig = <?= json_encode($js_vars) ?>;
    </script>
    <script src="../../assets/user/js/user_dashboard.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</body>

</html>