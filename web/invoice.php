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
        <label>Dolibarr Base URL
          <input id="invBaseUrl" placeholder="https://yourhost/dolibarr">
        </label>
        <label>Dolibarr API Key
          <input id="invApiKey" type="password" placeholder="API key">
        </label>
        <label>Customer SOCID
          <select id="invSocid">
            <option value="">Select customer...</option>
          </select>
        </label>
        <label>Line Rate
          <input id="invLineRate" type="number" step="0.01" min="0" value="0">
        </label>
        <label>GST/TVA %
          <input id="invTvaTx" type="number" step="0.01" min="0" value="0">
        </label>
      </div>
      <div class="line">
        <button class="btn btn-secondary" id="invTestConnBtn" type="button">Test Dolibarr Connection</button>
        <button class="btn" id="invCreateDraftsBtn" type="button">Create Draft Invoices</button>
      </div>
      <h2>Manual Draft Invoice</h2>
      <p>Create one draft invoice with custom lines (for example noxious weed spraying).</p>
      <div class="grid">
        <label>Customer PO / Ref (optional)
          <input id="invManualPo" placeholder="e.g. 261330">
        </label>
        <label>Work Order (optional)
          <input id="invManualWo" placeholder="e.g. 261324">
        </label>
        <label>Service Date (optional)
          <input id="invManualDate" type="date">
        </label>
      </div>
      <div class="grid">
        <label>Service Description
          <input id="invManualDesc" placeholder="e.g. 52W SPRAY NOX WEEDS CATCHMENT VICTORY SU - WATERLOO PARADISE">
        </label>
      </div>
      <div class="grid">
        <label>Hours
          <input id="invManualHoursQty" type="number" step="0.01" min="0" value="0">
        </label>
        <label>Hourly Rate
          <input id="invManualHoursRate" type="number" step="0.01" min="0" value="0">
        </label>
        <label>Chemical Litres
          <input id="invManualChemQty" type="number" step="0.01" min="0" value="0">
        </label>
        <label>Chemical Rate / L
          <input id="invManualChemRate" type="number" step="0.01" min="0" value="0">
        </label>
      </div>
      <div class="line">
        <button class="btn" id="invCreateManualBtn" type="button">Create Manual Draft Invoice</button>
      </div>
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
    window.ILS_V3_INVOICE = {
      role: <?php echo json_encode($current_user["role"]); ?>,
      dolibarr_base_url: <?php echo json_encode((string)($cfg["dolibarr_base_url"] ?? "")); ?>,
      dolibarr_socid: <?php echo json_encode((string)($cfg["dolibarr_socid"] ?? "")); ?>,
      dolibarr_tva_tx: <?php echo json_encode((string)($cfg["dolibarr_tva_tx"] ?? "0")); ?>
    };
  </script>
  <script src="assets/invoice.js?v=<?php echo (string)@filemtime(__DIR__ . '/assets/invoice.js'); ?>"></script>
</body>
</html>
