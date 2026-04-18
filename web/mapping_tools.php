<?php
require __DIR__ . "/_bootstrap.php";
if (($current_user["role"] ?? "") !== "admin") {
    http_response_code(403);
    echo "Admin only.";
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 - Mapping Tools</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <div class="topbar-left">ILS V3 Asset Console</div>
    <nav class="topbar-nav">
      <a href="drains.php">Drains</a>
      <a href="culverts.php">Culverts</a>
      <a href="bridges.php">Bridges</a>
      <a href="jobs.php">Jobs</a>
      <a class="active" href="mapping_tools.php">Mapping Tools</a>
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
      <h1>Mapping Tools</h1>
      <p>Run drain-map-pipeline scripts from the web app (server side).</p>
      <div class="line">
        <button class="btn" id="mappingRefreshPdfsBtn" type="button">Refresh PDF List</button>
      </div>
      <div class="line">
        <input id="mappingUploadPdf" type="file" accept=".pdf,application/pdf">
        <button class="btn btn-secondary" id="mappingUploadBtn" type="button">Upload PDF</button>
      </div>
      <div class="grid">
        <label>Map PDF
          <select id="mappingPdf"></select>
        </label>
        <label>Map Stem
          <input id="mappingStem" placeholder="Map 9 - Shire of Dardanup">
        </label>
      </div>
      <div class="line">
        <button class="btn" id="mappingInitBtn" type="button">1) Init Inputs</button>
        <button class="btn" id="mappingConvertBtn" type="button">2) Convert PDF to PNG</button>
      </div>
      <div class="line">
        <button class="btn" id="mappingGeorefBtn" type="button">3) Georeference Map</button>
        <button class="btn" id="mappingStructBtn" type="button">4) Build Structure GeoJSON</button>
      </div>
      <div class="line">
        <button class="btn btn-secondary" id="mappingOutputsBtn" type="button">Refresh Output Files</button>
      </div>
      <div id="mappingFiles" class="photo-list"></div>
      <div id="mappingMsg"></div>
      <pre id="mappingOutput" class="log-box"></pre>
    </section>
  </main>

  <script src="assets/mapping.js"></script>
</body>
</html>
