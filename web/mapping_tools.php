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
      <a href="floodgates.php">Floodgates</a>
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
      <hr>
      <h2>5) Web Click Control Points</h2>
      <p class="meta">Click directly on the map image in browser to append control points. Existing points are kept unless you run Init Inputs again.</p>
      <div class="line">
        <button class="btn" id="mappingLoadClickToolBtn" type="button">Load Map For Clicking</button>
        <label class="meta">Zoom
          <input id="mappingZoom" type="range" min="25" max="300" value="100" step="5">
          <span id="mappingZoomLabel">100%</span>
        </label>
      </div>
      <div id="mappingClickMeta" class="meta"></div>
      <div id="mappingClickWrap" class="map-click-wrap">
        <div id="mappingImageStage" class="map-image-stage">
          <img id="mappingClickImage" alt="Map PNG for control point clicking">
          <div id="mappingPointLayer" class="map-point-layer"></div>
        </div>
      </div>
      <div id="mappingPointList" class="list-table-wrap"></div>
      <hr>
      <h2>6) Web Click Asset Points (From Georeferenced Map)</h2>
      <p class="meta">Requires georeference first (`.pgw` present). Click map to add assets with auto-derived lat/lon.</p>
      <div class="line">
        <button class="btn" id="mappingLoadStructToolBtn" type="button">Load Georef Click Tool</button>
        <label class="meta">
          <input id="mappingStructUpsertAsset" type="checkbox" checked>
          Also create/update in Assets list
        </label>
      </div>
      <div id="mappingStructMeta" class="meta"></div>
      <div id="mappingStructWrap" class="map-click-wrap">
        <div id="mappingStructStage" class="map-image-stage">
          <img id="mappingStructImage" alt="Map PNG for structure clicking">
          <div id="mappingStructLayer" class="map-point-layer"></div>
        </div>
      </div>
      <div id="mappingStructList" class="list-table-wrap"></div>
      <hr>
      <h2>7) Build Asset Collection GeoJSON (All Maps)</h2>
      <p class="meta">Creates <code>all_assets.geojson</code> plus one file per asset type from all georeferenced map outputs.</p>
      <div class="line">
        <button class="btn" id="mappingBuildCollectionsBtn" type="button">Build Asset Collections</button>
        <button class="btn btn-secondary" id="mappingCollectionsRefreshBtn" type="button">Refresh Collection Files</button>
      </div>
      <div id="mappingCollectionsFiles" class="photo-list"></div>
      <div id="mappingMsg"></div>
      <pre id="mappingOutput" class="log-box"></pre>
    </section>
  </main>

  <div id="mappingPointModal" class="modal-backdrop hidden">
    <div class="modal-card">
      <h3 id="mappingPointModalTitle">Add Point</h3>
      <div class="grid">
        <label>Type
          <select id="mappingPointType"></select>
        </label>
        <label>ID / Number
          <input id="mappingPointId" placeholder="Optional for control points">
        </label>
      </div>
      <div id="mappingPointCoordsRow" class="grid">
        <label>Latitude
          <input id="mappingPointLat" placeholder="Optional if existing asset has coords">
        </label>
        <label>Longitude
          <input id="mappingPointLon" placeholder="Optional if existing asset has coords">
        </label>
      </div>
      <label>Label (optional)
        <input id="mappingPointLabel">
      </label>
      <div id="mappingPointAssetHint" class="meta"></div>
      <div id="mappingPointModalError" class="error"></div>
      <div class="line">
        <button class="btn btn-secondary" id="mappingPointCancelBtn" type="button">Cancel</button>
        <button class="btn" id="mappingPointSaveBtn" type="button">Save</button>
      </div>
    </div>
  </div>

  <script src="assets/mapping.js"></script>
</body>
</html>
