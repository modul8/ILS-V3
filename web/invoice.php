<?php
require __DIR__ . "/_bootstrap.php";
$is_admin = (($current_user["role"] ?? "") === "admin");
if (!$is_admin) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 - Invoice Queue</title>
  <link rel="stylesheet" href="assets/style.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body>
  <header class="topbar">
    <div class="topbar-left">ILS V3 Asset Console</div>
    <nav class="topbar-nav">
      <a href="drains.php">Drains</a>
      <a href="culverts.php">Culverts</a>
      <a href="bridges.php">Bridges</a>
      <a href="floodgates.php">Floodgates</a>
      <a href="jobs.php">Jobs</a>
      <a href="jobs_import.php">Job Import</a>
      <a class="active" href="invoice.php">Invoice</a>
      <a href="mapping_tools.php">Mapping Tools</a>
      <a href="index.php">Home</a>
    </nav>
    <div class="topbar-right">
      <span class="meta">Signed in as <?php echo htmlspecialchars($current_user["username"], ENT_QUOTES, "UTF-8"); ?> (admin)</span>
      <a class="btn btn-secondary" href="admin_users.php">Users</a>
      <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
  </header>

  <main class="wrap">
    <section class="search-panel">
      <h1>Invoice Queue</h1>
      <p>Completed jobs ready for invoicing.</p>
      <div class="grid">
        <label>Module
          <select id="invModule">
            <option value="">All</option>
            <option value="work">work</option>
            <option value="drain">drain</option>
            <option value="weeds">weeds</option>
            <option value="fire">fire</option>
            <option value="tracks">tracks</option>
          </select>
        </label>
        <label>Asset Type
          <select id="invAssetType">
            <option value="">All</option>
            <option value="drain">Drain</option>
            <option value="culvert">Culvert</option>
            <option value="bridge">Bridge</option>
            <option value="floodgate">Floodgate</option>
          </select>
        </label>
        <label>View
          <select id="invView">
            <option value="pending">Completed Not Invoiced</option>
            <option value="invoiced">Already Invoiced</option>
            <option value="all">All Completed</option>
          </select>
        </label>
        <label>Search
          <input id="invSearch" placeholder="WO / PO / Asset / Description">
        </label>
      </div>
      <div class="line">
        <button class="btn" id="invRefreshBtn" type="button">Refresh Queue</button>
      </div>
    </section>

    <section class="card">
      <h2>Invoice Jobs</h2>
      <div class="line">
        <button class="btn btn-secondary" id="invSelectAllBtn" type="button">Select All Visible</button>
        <button class="btn btn-secondary" id="invClearBtn" type="button">Clear Selection</button>
        <button class="btn" id="invMarkBtn" type="button">Mark Invoiced</button>
        <button class="btn btn-secondary" id="invUndoBtn" type="button">Undo Invoiced</button>
        <button class="btn btn-secondary" id="invExportBtn" type="button">Export CSV</button>
      </div>
      <div id="invMsg"></div>
      <div id="invSummary" class="meta"></div>
      <div id="invList" class="list-table-wrap"></div>
    </section>
  </main>

  <script>
    window.ILS_V3_INVOICE = { role: <?php echo json_encode($current_user["role"]); ?> };
  </script>
  <script src="assets/invoice.js?v=<?php echo (string)@filemtime(__DIR__ . '/assets/invoice.js'); ?>"></script>
</body>
</html>

