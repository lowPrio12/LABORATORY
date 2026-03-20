<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · modern refresh</title>
    <!-- keep your existing structure, enhance with clean css -->
    <!-- Google Font for a friendlier, modern look -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 (free) for subtle icons, improves visual communication -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/users/css/index.css">

</head>

<body>
    <div class="container">
        <h2>Log in</h2>

        <?php
        // Keep your existing code, just update the redirect part
        require 'model/config.php';   // your config path

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
                $_SESSION['user_name'] = $user['username'];

                // Redirect based on role - FIXED to include manager
                if ($user['user_role'] === 'admin') {
                    header("Location: view/admin/dashboard.php");
                    exit;
                } elseif ($user['user_role'] === 'manager') {
                    header("Location: view/manager/dashboard.php");
                    exit;
                } else {
                    header("Location: view/user/dashboard.php");
                    exit;
                }
            } else {
                $error = "Invalid username or password!";
            }
        }
        ?>

        <!-- show error with icon if any -->
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" placeholder="Username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
            </div>

            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="Password" required>
            </div>

            <!-- preserved exact helper text but made visually softer -->
            <div class="hint-pw">
                <i class="fas fa-key"></i> Demo password: <strong>password123</strong>
            </div>

            <button type="submit" name="login">
                <span>Sign in</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <!-- optional subtle link (keeps design friendly) -->
        <div class="note">
            <i class="fas fa-shield-alt" style="opacity: 0.6;"></i>
            secure login — <a href="#">reset</a> · <a href="#">help</a>
        </div>
    </div>

    <!-- nothing else changed, PHP intact, just improved user interface with modern CSS -->
</body>

</html>