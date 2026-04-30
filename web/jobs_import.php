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
  <title>ILS V3 - Job Import</title>
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
      <a class="active" href="jobs_import.php">Job Import</a>
      <a href="invoice.php">Invoice</a>
      <a href="mapping_tools.php">Mapping Tools</a>
      <a href="index.php">Home</a>
    </nav>
    <div class="topbar-right">
      <span class="meta">Signed in as <?php echo htmlspecialchars($current_user["username"], ENT_QUOTES, "UTF-8"); ?> (<?php echo htmlspecialchars($current_user["role"], ENT_QUOTES, "UTF-8"); ?>)</span>
      <a class="btn btn-secondary" href="admin_users.php">Users</a>
      <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
  </header>

  <main class="wrap">
    <section class="search-panel">
      <h1>Job Import</h1>
      <p>Import CSV or V2-style XLSX bundles into the jobs table.</p>
      <div class="line">
        <a class="btn btn-secondary" href="jobs.php">Open Jobs List</a>
      </div>
    </section>

    <section class="card">
      <h2>Import Work List CSV</h2>
      <div class="meta">CSV headers: module, asset_type, asset_id, work_order, purchase_order, status, scheduled_date, description, job_key(optional)</div>
      <div class="line">
        <input id="jobsCsvFile" type="file" accept=".csv,text/csv">
        <button class="btn" id="jobsImportBtn" type="button">Import CSV</button>
      </div>
      <div id="jobsImportMsg"></div>
      <hr>
      <h2>Import XLSX Bundle (V2-style)</h2>
      <div class="meta">Upload 1 WO/PO file and 1+ drain job files. `Work Orders` column overrides `MI #` when populated.</div>
      <div class="grid">
        <label>WO/PO File (updated work list)
          <input id="jobsWorkFile" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        </label>
        <label>Drain Job Files (one or more)
          <input id="jobsJobFiles" type="file" multiple accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        </label>
      </div>
      <div class="line">
        <button class="btn btn-secondary" id="jobsPreviewXlsxBtn" type="button">Dry Run Preview</button>
        <button class="btn" id="jobsImportXlsxBtn" type="button">Import XLSX Bundle</button>
      </div>
      <div id="jobsImportXlsxMsg"></div>
    </section>
  </main>

  <script>
    window.ILS_V3_JOBS = {
      role: <?php echo json_encode($current_user["role"]); ?>
    };
  </script>
  <script src="assets/jobs.js?v=<?php echo (string)@filemtime(__DIR__ . '/assets/jobs.js'); ?>"></script>
</body>
</html>
