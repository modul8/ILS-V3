<?php
require __DIR__ . "/_bootstrap.php";
$is_admin = (($current_user["role"] ?? "") === "admin");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 - Jobs</title>
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
      <a class="active" href="jobs.php">Jobs</a>
      <?php if ($is_admin): ?><a href="mapping_tools.php">Mapping Tools</a><?php endif; ?>
      <a href="index.php">Home</a>
    </nav>
    <div class="topbar-right">
      <span class="meta">Signed in as <?php echo htmlspecialchars($current_user["username"], ENT_QUOTES, "UTF-8"); ?> (<?php echo htmlspecialchars($current_user["role"], ENT_QUOTES, "UTF-8"); ?>)</span>
      <?php if ($is_admin): ?>
        <a class="btn btn-secondary" href="admin_users.php">Users</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
  </header>

  <main class="wrap">
    <section class="search-panel">
      <h1>Jobs</h1>
      <p>Import work lists and map jobs to asset coordinates.</p>
      <div class="grid">
        <label>Module
          <select id="jobsModule">
            <option value="">All</option>
            <option value="work">work</option>
            <option value="drain">drain</option>
            <option value="weeds">weeds</option>
            <option value="fire">fire</option>
            <option value="tracks">tracks</option>
          </select>
        </label>
        <label>Status
          <select id="jobsStatus">
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="scheduled">Scheduled</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </label>
        <label>Asset Type
          <select id="jobsAssetType">
            <option value="">All</option>
            <option value="drain">Drain</option>
            <option value="culvert">Culvert</option>
            <option value="bridge">Bridge</option>
            <option value="floodgate">Floodgate</option>
          </select>
        </label>
        <label>Current Work
          <select id="jobsCurrentWork">
            <option value="">All</option>
            <option value="1">In Current Work</option>
            <option value="0">Not In Current Work</option>
          </select>
        </label>
        <label>Invoice Ready
          <select id="jobsInvoiceReady">
            <option value="">All</option>
            <option value="1">Completed Not Invoiced</option>
          </select>
        </label>
        <label>Search
          <input id="jobsSearch" placeholder="WO / PO / Asset / Description">
        </label>
      </div>
      <div class="line">
        <button class="btn" id="jobsRefreshBtn" type="button">Refresh Jobs</button>
      </div>
    </section>

    <?php if ($is_admin): ?>
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
    <?php endif; ?>

    <section class="card">
      <h2>Job List</h2>
      <?php if ($is_admin): ?>
      <div class="line">
        <button class="btn btn-secondary" id="jobsSelectAllBtn" type="button">Select All Visible</button>
        <button class="btn btn-secondary" id="jobsClearSelectionBtn" type="button">Clear Selection</button>
        <button class="btn" id="jobsAddCurrentBtn" type="button">Add to Current Work</button>
        <button class="btn btn-secondary" id="jobsRemoveCurrentBtn" type="button">Remove from Current Work</button>
        <button class="btn" id="jobsMarkCompletedBtn" type="button">Mark Completed</button>
        <button class="btn" id="jobsMarkInvoicedBtn" type="button">Mark Invoiced</button>
        <button class="btn" id="jobsInvoiceAsCompletedBtn" type="button">Invoice as Completed</button>
      </div>
      <div id="jobsActionMsg"></div>
      <?php endif; ?>
      <div id="jobsTableWrap" class="list-table-wrap"></div>
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

