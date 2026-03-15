<?php
require 'model/config.php';

$error = '';

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_role'] = $user['user_role'];

        // Redirect based on role
        if ($user['user_role'] === 'admin') {
            header("Location: view/admin/dashboard.php");
        } else {
            header("Location: view/user/dashboard.php");
        }
        exit;
    } else {
        $error = "Invalid username or password!";
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div class="container">
        <h2>Login</h2>
        <?php if ($error) echo "<p class='error'>$error</p>"; ?>
        <form method="post">
            <input type="text" name="username" placeholder="Username" required><br>
            <input type="password" name="password" placeholder="Password" required><br>
            <button type="submit" name="login">Login</button>
            <br>
            <p>Password: password123</p>
        </form>
    </div>
</body>

</html>