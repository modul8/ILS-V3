<?php
require __DIR__ . "/_bootstrap.php";

$asset_type = strtolower(trim((string)($_GET["asset_type"] ?? "")));
$asset_id = trim((string)($_GET["asset_id"] ?? ""));
if ($asset_type !== "drain" || $asset_id === "") {
    http_response_code(400);
    echo "Invalid drain track request.";
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ILS V3 - Drain Track <?php echo htmlspecialchars($asset_id, ENT_QUOTES, "UTF-8"); ?></title>
  <link rel="stylesheet" href="assets/style.css?v=<?php echo (string)@filemtime(__DIR__ . '/assets/style.css'); ?>">
  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin=""
  >
  <style>
    #drainTrackMap {
      width: 100%;
      height: min(78vh, 760px);
      border: 1px solid #c7d0de;
      border-radius: 12px;
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar-left">ILS V3 Drain Track Viewer</div>
    <nav class="topbar-nav">
      <a href="drains.php" class="active">Drains</a>
      <a href="mapping_tools.php">Mapping Tools</a>
      <a href="index.php">Home</a>
    </nav>
    <div class="topbar-right">
      <span class="meta">Signed in as <?php echo htmlspecialchars($current_user["username"], ENT_QUOTES, "UTF-8"); ?> (<?php echo htmlspecialchars($current_user["role"], ENT_QUOTES, "UTF-8"); ?>)</span>
      <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
  </header>

  <main class="wrap">
    <section class="card">
      <div class="line">
        <h1>Drain <?php echo htmlspecialchars($asset_id, ENT_QUOTES, "UTF-8"); ?> Track</h1>
        <div class="line">
          <a id="openPinLink" class="pin-link" title="Open map pin" target="_blank" rel="noopener" style="display:none;"><img src="assets/gps.png" alt="Map pin"></a>
          <a id="downloadGeoJsonLink" class="btn btn-secondary" target="_blank" rel="noopener">Download GeoJSON</a>
        </div>
      </div>
      <div id="viewerMsg" class="meta">Loading track...</div>
      <div id="drainTrackMap"></div>
    </section>
  </main>

  <script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
  ></script>
  <script>
    (function () {
      const assetType = <?php echo json_encode($asset_type); ?>;
      const assetId = <?php echo json_encode($asset_id); ?>;
      const msg = document.getElementById("viewerMsg");
      const downloadLink = document.getElementById("downloadGeoJsonLink");
      const openPinLink = document.getElementById("openPinLink");
      const trackUrl = `api/index.php?action=download_asset_track&asset_type=${encodeURIComponent(assetType)}&asset_id=${encodeURIComponent(assetId)}`;
      const assetUrl = `api/index.php?action=get_asset&asset_type=${encodeURIComponent(assetType)}&asset_id=${encodeURIComponent(assetId)}`;
      downloadLink.href = trackUrl;

      const map = L.map("drainTrackMap", { zoomControl: true });
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 22,
        attribution: "&copy; OpenStreetMap contributors"
      }).addTo(map);
      map.setView([-33.54, 115.63], 13);

      function setMsg(text, isErr) {
        msg.textContent = text;
        msg.className = isErr ? "error" : "meta";
      }

      async function loadAssetPin() {
        try {
          const r = await fetch(assetUrl);
          const j = await r.json();
          if (!j.ok || !j.asset) return;
          const lat = String(j.asset.lat || "").trim();
          const lon = String(j.asset.lon || "").trim();
          if (!lat || !lon) return;
          const q = encodeURIComponent(`${lat},${lon}`);
          openPinLink.href = `https://www.google.com/maps/search/?api=1&query=${q}`;
          openPinLink.style.display = "";
        } catch (_) {
          // No-op
        }
      }

      async function loadTrack() {
        try {
          const r = await fetch(trackUrl);
          const txt = await r.text();
          const geo = JSON.parse(txt);
          if (!geo || !geo.type) {
            setMsg("Track data invalid.", true);
            return;
          }
          const layer = L.geoJSON(geo, {
            style: { color: "#16a34a", weight: 5, opacity: 0.95 },
          }).addTo(map);
          const b = layer.getBounds();
          if (b.isValid()) map.fitBounds(b.pad(0.2));
          setMsg("Track loaded.", false);
        } catch (e) {
          setMsg("Could not load track (not found or invalid data).", true);
        }
      }

      loadAssetPin();
      loadTrack();
    })();
  </script>
</body>
</html>
