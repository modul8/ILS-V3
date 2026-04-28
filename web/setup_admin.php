<?php
session_start();

$config_path = __DIR__ . "/config.php";
if (!file_exists($config_path)) {
    http_response_code(500);
    echo "Missing config.php. Copy config.sample.php to config.php first.";
    exit;
}
$cfg = require $config_path;
require_once __DIR__ . "/_db.php";

$error = "";
$done = false;

try {
    $pdo = db_connect($cfg);
    $count_stmt = $pdo->query("SELECT COUNT(*) AS c FROM users");
    $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $user_count = (int)($count_row["c"] ?? 0);
    if ($user_count > 0) {
        header("Location: login.php");
        exit;
    }
} catch (Exception $e) {
    $error = "Database connection failed.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {
    $username = trim((string)($_POST["username"] ?? ""));
    $password = (string)($_POST["password"] ?? "");
    $confirm = (string)($_POST["confirm_password"] ?? "");
    if ($username === "" || $password === "") {
        $error = "Username and password are required.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 10) {
        $error = "Use at least 10 characters for admin password.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, active) VALUES (:username, :password_hash, 'admin', 1)");
            $stmt->execute([
                ":username" => $username,
                ":password_hash" => $hash,
            ]);
            $done = true;
        } catch (Exception $e) {
            $error = "Could not create admin user.";
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 Setup Admin</title>
  <link rel="stylesheet" href="assets/style.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body class="login-body">
  <form class="login-card" method="post">
    <h1>ILS V3</h1>
    <p>Create first admin account</p>
    <?php if ($done): ?>
      <div class="success">Admin account created. <a class="link" href="login.php">Go to login</a></div>
    <?php else: ?>
      <label>Admin Username<input type="text" name="username" required></label>
      <label>Password<input type="password" name="password" required></label>
      <label>Confirm Password<input type="password" name="confirm_password" required></label>
      <button class="btn" type="submit">Create Admin</button>
    <?php endif; ?>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
  </form>
</body>
</html>

