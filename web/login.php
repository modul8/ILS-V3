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

if (isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

$error = "";
try {
    $pdo = db_connect($cfg);
    $count_stmt = $pdo->query("SELECT COUNT(*) AS c FROM users");
    $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $user_count = (int)($count_row["c"] ?? 0);
    if ($user_count === 0) {
        header("Location: setup_admin.php");
        exit;
    }
} catch (Exception $e) {
    $error = "Database connection failed.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {
    $username = trim((string)($_POST["username"] ?? ""));
    $password = (string)($_POST["password"] ?? "");

    if ($username === "" || $password === "") {
        $error = "Enter username and password.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash, role, active FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([":username" => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || (int)($user["active"] ?? 0) !== 1) {
                $error = "Invalid credentials.";
            } else {
                $hash = (string)($user["password_hash"] ?? "");
                if (!password_verify($password, $hash)) {
                    $error = "Invalid credentials.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION["user_id"] = (int)$user["id"];
                    $_SESSION["username"] = (string)$user["username"];
                    $_SESSION["role"] = (string)$user["role"];
                    header("Location: index.php");
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = "Login failed.";
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 Login</title>
  <link rel="stylesheet" href="assets/style.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body class="login-body">
  <form class="login-card" method="post">
    <h1>ILS V3</h1>
    <p>Sign in</p>
    <label>Username<input type="text" name="username" required autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit">Sign In</button>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
  </form>
</body>
</html>

