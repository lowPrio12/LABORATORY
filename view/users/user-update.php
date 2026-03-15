<?php
// Turn on error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set header for JSON response
header('Content-Type: application/json');

// Debug: Log the request
error_log("user-update.php accessed");
error_log("POST data: " . print_r($_POST, true));

try {
    require_once '../../model/config.php';

    // Check if user is logged in and is admin
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }

    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }

    // Check if it's an AJAX request
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    if (!$isAjax) {
        echo json_encode(['success' => false, 'message' => 'Invalid request type']);
        exit;
    }

    // Validate required fields
    $user_id = $_POST['user_id'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $user_role = $_POST['user_role'] ?? '';

    $errors = [];

    // Validate user ID
    if (empty($user_id)) {
        $errors[] = 'User ID is required';
    } elseif (!is_numeric($user_id)) {
        $errors[] = 'Invalid user ID';
    }

    // Validate username
    if (empty($username)) {
        $errors[] = 'Username is required';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters';
    } elseif (strlen($username) > 50) {
        $errors[] = 'Username must be less than 50 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores';
    }

    // Validate password if provided
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }

    // Validate role
    $valid_roles = ['admin', 'manager', 'user'];
    if (!in_array($user_role, $valid_roles)) {
        $errors[] = 'Invalid user role';
    }

    // If there are validation errors, return them
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Check if user exists
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingUser) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Check if username already exists (but not for the current user)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
    $stmt->execute([$username, $user_id]);
    $userExists = $stmt->fetchColumn();

    if ($userExists > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit;
    }

    // Prevent admin from changing their own role
    if ($user_id == $_SESSION['user_id'] && $user_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'You cannot change your own role from admin']);
        exit;
    }

    // Build update query based on whether password is provided
    if (!empty($password)) {
        // Hash the new password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Update with new password
        $stmt = $conn->prepare("
            UPDATE users 
            SET username = ?, 
                password = ?, 
                user_role = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $result = $stmt->execute([$username, $hashed_password, $user_role, $user_id]);
        $password_changed = true;
    } else {
        // Update without changing password
        $stmt = $conn->prepare("
            UPDATE users 
            SET username = ?, 
                user_role = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $result = $stmt->execute([$username, $user_role, $user_id]);
        $password_changed = false;
    }

    if (!$result) {
        throw new Exception('Failed to update user');
    }

    // Log the activity
    $action = "Updated user: $username (ID: $user_id)";
    if ($password_changed) {
        $action .= " - Password changed";
    }
    if ($existingUser['user_role'] !== $user_role) {
        $action .= " - Role changed from {$existingUser['user_role']} to $user_role";
    }

    $logStmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
    $logStmt->execute([$_SESSION['user_id'], $action]);

    // Commit transaction
    $conn->commit();

    // Fetch updated user data for response
    $stmt = $conn->prepare("
        SELECT u.*, 
               (SELECT COUNT(*) FROM egg WHERE user_id = u.user_id) as batch_count,
               (SELECT COUNT(*) FROM user_activity_logs WHERE user_id = u.user_id) as activity_count
        FROM users u 
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

    // Remove password from response
    unset($updatedUser['password']);

    echo json_encode([
        'success' => true,
        'message' => 'User updated successfully',
        'user' => $updatedUser,
        'password_changed' => $password_changed
    ]);
    exit;
} catch (PDOException $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("User update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("User update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
