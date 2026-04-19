<?php
require __DIR__ . "/_bootstrap.php";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <div class="topbar-left">ILS V3 Asset Console</div>
    <div class="topbar-right">
      <span class="meta">Signed in as <?php echo htmlspecialchars($current_user["username"], ENT_QUOTES, "UTF-8"); ?> (<?php echo htmlspecialchars($current_user["role"], ENT_QUOTES, "UTF-8"); ?>)</span>
      <?php if (($current_user["role"] ?? "") === "admin"): ?>
        <a class="btn btn-secondary" href="admin_users.php">Users</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
  </header>
  <main class="wrap home">
    <h1>Asset Types</h1>
    <div class="tiles">
      <a class="tile" href="drains.php">
        <h2>Drains</h2>
        <p>Search and manage drain assets.</p>
      </a>
      <a class="tile" href="culverts.php">
        <h2>Culverts</h2>
        <p>Search and manage culvert assets.</p>
      </a>
      <a class="tile" href="bridges.php">
        <h2>Bridges</h2>
        <p>Search and manage bridge assets.</p>
      </a>
      <a class="tile" href="floodgates.php">
        <h2>Floodgates</h2>
        <p>Search and manage floodgate assets.</p>
      </a>
      <a class="tile" href="jobs.php">
        <h2>Jobs</h2>
        <p>Import work lists and link jobs to asset coordinates.</p>
      </a>
      <?php if (($current_user["role"] ?? "") === "admin"): ?>
      <a class="tile" href="mapping_tools.php">
        <h2>Mapping Tools</h2>
        <p>Run map conversion/georeference scripts from the web app.</p>
      </a>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
