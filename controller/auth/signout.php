<?php
session_start();
require '../../model/config.php'; // Adjust path if necessary

// Only log if user is logged in
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['user_role'] ?? 'user';
    $action = ($role === 'admin') ? "Admin logged out" : "User logged out";

    // Log logout activity
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
    $stmt->execute([$user_id, $action]);
}

// Destroy session
$_SESSION = [];
session_destroy();

// Redirect to login page
header("Location: ../../index.php");
exit;
