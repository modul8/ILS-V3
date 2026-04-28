<?php
require __DIR__ . "/_bootstrap.php";
if (($current_user["role"] ?? "") !== "admin") {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

require_once __DIR__ . "/_db.php";
$pdo = db_connect($cfg);
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));
    if ($action === "create_user") {
        $username = trim((string)($_POST["username"] ?? ""));
        $password = (string)($_POST["password"] ?? "");
        $role = trim((string)($_POST["role"] ?? "user"));
        if (!in_array($role, ["admin", "user"], true)) $role = "user";

        if ($username === "" || $password === "") {
            $error = "Username and password are required.";
        } elseif (strlen($password) < 10) {
            $error = "Use at least 10 characters for password.";
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, active) VALUES (:username, :hash, :role, 1)");
                $stmt->execute([":username" => $username, ":hash" => $hash, ":role" => $role]);
                $success = "User created.";
            } catch (Exception $e) {
                $error = "Could not create user.";
            }
        }
    } elseif ($action === "set_active") {
        $id = (int)($_POST["id"] ?? 0);
        $active = (int)($_POST["active"] ?? 0) ? 1 : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET active = :active WHERE id = :id");
            $stmt->execute([":active" => $active, ":id" => $id]);
            $success = "User updated.";
        }
    } elseif ($action === "set_password") {
        $id = (int)($_POST["id"] ?? 0);
        $password = (string)($_POST["password"] ?? "");
        if ($id <= 0 || $password === "") {
            $error = "User and password are required.";
        } elseif (strlen($password) < 10) {
            $error = "Use at least 10 characters for password.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $stmt->execute([":hash" => $hash, ":id" => $id]);
            $success = "Password updated.";
        }
    }
}

$users = $pdo->query("SELECT id, username, role, active, created_at, updated_at FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 - User Admin</title>
  <link rel="stylesheet" href="assets/style.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body>
  <header class="topbar">
    <div class="topbar-left">ILS V3 User Admin</div>
    <div class="topbar-right">
      <a class="btn btn-secondary" href="index.php">Home</a>
      <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
  </header>

  <main class="wrap">
    <section class="card">
      <h2>Create User</h2>
      <form method="post" class="grid">
        <input type="hidden" name="action" value="create_user">
        <label>Username<input name="username" required></label>
        <label>Password<input type="password" name="password" required></label>
        <label>Role
          <select name="role">
            <option value="user">user</option>
            <option value="admin">admin</option>
          </select>
        </label>
        <div><button class="btn" type="submit">Create</button></div>
      </form>
      <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
      <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
    </section>

    <section class="card">
      <h2>Existing Users</h2>
      <div class="list-table-wrap">
        <table>
          <thead>
            <tr><th>ID</th><th>Username</th><th>Role</th><th>Active</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><?php echo (int)$u["id"]; ?></td>
                <td><?php echo htmlspecialchars((string)$u["username"], ENT_QUOTES, "UTF-8"); ?></td>
                <td><?php echo htmlspecialchars((string)$u["role"], ENT_QUOTES, "UTF-8"); ?></td>
                <td><?php echo ((int)$u["active"] === 1) ? "Yes" : "No"; ?></td>
                <td>
                  <form method="post" style="display:inline-flex; gap:8px; align-items:center;">
                    <input type="hidden" name="action" value="set_active">
                    <input type="hidden" name="id" value="<?php echo (int)$u["id"]; ?>">
                    <input type="hidden" name="active" value="<?php echo ((int)$u["active"] === 1) ? 0 : 1; ?>">
                    <button class="btn btn-secondary" type="submit"><?php echo ((int)$u["active"] === 1) ? "Disable" : "Enable"; ?></button>
                  </form>
                  <form method="post" style="display:inline-flex; gap:8px; align-items:center;">
                    <input type="hidden" name="action" value="set_password">
                    <input type="hidden" name="id" value="<?php echo (int)$u["id"]; ?>">
                    <input type="password" name="password" placeholder="New password" required>
                    <button class="btn" type="submit">Set Password</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>

