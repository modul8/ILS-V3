<?php
require __DIR__ . "/_bootstrap.php";

$asset_type = isset($asset_type) ? strtolower(trim((string)$asset_type)) : "";
$allowed = ["drain", "culvert", "bridge", "floodgate"];
if (!in_array($asset_type, $allowed, true)) {
    http_response_code(400);
    echo "Invalid asset type.";
    exit;
}

$title_map = [
    "drain" => "Drains",
    "culvert" => "Culverts",
    "bridge" => "Bridges",
    "floodgate" => "Floodgates",
];
$title = $title_map[$asset_type];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 - <?php echo htmlspecialchars($title, ENT_QUOTES, "UTF-8"); ?></title>
  <link rel="stylesheet" href="assets/style.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body>
  <header class="topbar">
    <div class="topbar-left">ILS V3 Asset Console</div>
    <nav class="topbar-nav">
      <a href="drains.php"<?php echo $asset_type === "drain" ? " class=\"active\"" : ""; ?>>Drains</a>
      <a href="culverts.php"<?php echo $asset_type === "culvert" ? " class=\"active\"" : ""; ?>>Culverts</a>
      <a href="bridges.php"<?php echo $asset_type === "bridge" ? " class=\"active\"" : ""; ?>>Bridges</a>
      <a href="floodgates.php"<?php echo $asset_type === "floodgate" ? " class=\"active\"" : ""; ?>>Floodgates</a>
      <a href="jobs.php">Jobs</a>
      <?php if (($current_user["role"] ?? "") === "admin"): ?>
      <a href="mapping_tools.php">Mapping Tools</a>
      <?php endif; ?>
      <a href="index.php">Home</a>
    </nav>
    <div class="topbar-right">
      <span class="meta">Signed in as <?php echo htmlspecialchars($current_user["username"], ENT_QUOTES, "UTF-8"); ?> (<?php echo htmlspecialchars($current_user["role"], ENT_QUOTES, "UTF-8"); ?>)</span>
      <?php if (($current_user["role"] ?? "") === "admin"): ?>
        <a class="btn btn-secondary" href="admin_users.php">Users</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
  </header>

  <main class="wrap" data-asset-page="1" data-asset-type="<?php echo htmlspecialchars($asset_type, ENT_QUOTES, "UTF-8"); ?>">
    <section class="search-panel">
      <h1><?php echo htmlspecialchars($title, ENT_QUOTES, "UTF-8"); ?></h1>
      <p>Search by asset ID (example: <code>478</code>).</p>
      <div class="search-row">
        <input id="assetSearchInput" placeholder="Enter asset ID" autocomplete="off">
        <button id="assetSearchBtn" class="btn">Search</button>
      </div>
      <div class="hint">No match found will open a pre-filled new asset card.</div>
    </section>

    <section class="card">
      <div class="line">
        <h2><?php echo htmlspecialchars($title, ENT_QUOTES, "UTF-8"); ?> List</h2>
        <button id="refreshListBtn" class="btn btn-secondary" type="button">Refresh List</button>
      </div>
      <div id="assetList" class="list-table-wrap"></div>
    </section>

    <section id="results"></section>
  </main>

  <script>
    window.ILS_V3 = {
      assetType: <?php echo json_encode($asset_type); ?>,
      role: <?php echo json_encode($current_user["role"]); ?>,
      username: <?php echo json_encode($current_user["username"]); ?>
    };
  </script>
  <script src="assets/app.js?v=<?php echo (string)@filemtime(__DIR__ . '/assets/app.js'); ?>"></script>
</body>
</html>
