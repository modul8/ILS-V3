(function () {
  const pdfSelect = document.getElementById("mappingPdf");
  const stemInput = document.getElementById("mappingStem");
  const msg = document.getElementById("mappingMsg");
  const output = document.getElementById("mappingOutput");
  const filesWrap = document.getElementById("mappingFiles");
  const uploadInput = document.getElementById("mappingUploadPdf");

  const refreshBtn = document.getElementById("mappingRefreshPdfsBtn");
  const uploadBtn = document.getElementById("mappingUploadBtn");
  const initBtn = document.getElementById("mappingInitBtn");
  const convertBtn = document.getElementById("mappingConvertBtn");
  const georefBtn = document.getElementById("mappingGeorefBtn");
  const structBtn = document.getElementById("mappingStructBtn");
  const outputsBtn = document.getElementById("mappingOutputsBtn");

  const loadClickToolBtn = document.getElementById("mappingLoadClickToolBtn");
  const clickMeta = document.getElementById("mappingClickMeta");
  const zoomInput = document.getElementById("mappingZoom");
  const zoomLabel = document.getElementById("mappingZoomLabel");
  const clickImage = document.getElementById("mappingClickImage");
  const pointLayer = document.getElementById("mappingPointLayer");
  const pointList = document.getElementById("mappingPointList");

  const loadStructToolBtn = document.getElementById("mappingLoadStructToolBtn");
  const structMeta = document.getElementById("mappingStructMeta");
  const structImage = document.getElementById("mappingStructImage");
  const structLayer = document.getElementById("mappingStructLayer");
  const structList = document.getElementById("mappingStructList");
  const structUpsertAsset = document.getElementById("mappingStructUpsertAsset");

  let lastType = "culvert";
  let lastStructType = "culvert";
  let controlPoints = [];
  let structurePoints = [];

  function esc(v) {
    return String(v || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  async function api(action, method, body, params) {
    const url = new URL("api/index.php", window.location.href);
    url.searchParams.set("action", action);
    if (params) Object.keys(params).forEach((k) => url.searchParams.set(k, params[k]));
    const opts = { method: method || "GET" };
    if (body) {
      opts.headers = { "Content-Type": "application/json" };
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    return res.json();
  }

  function setMessage(text, ok) {
    msg.className = ok ? "success" : "error";
    msg.textContent = text;
  }

  function inferStem(pdfName) {
    if (!pdfName) return "";
    return pdfName.replace(/\.pdf$/i, "");
  }

  function applyZoom() {
    if (!clickImage) return;
    const zoom = Number(zoomInput && zoomInput.value ? zoomInput.value : 100);
    if (zoomLabel) zoomLabel.textContent = `${zoom}%`;
    if (clickImage.naturalWidth) {
      clickImage.style.width = `${Math.round(clickImage.naturalWidth * (zoom / 100))}px`;
    }
    if (structImage && structImage.naturalWidth) {
      structImage.style.width = `${Math.round(structImage.naturalWidth * (zoom / 100))}px`;
    }
    renderControlPoints();
    renderStructurePoints();
  }

  function renderDots(layer, image, rows, typeKey, idKey) {
    if (!layer || !image) return;
    layer.innerHTML = "";
    const displayW = image.clientWidth || 0;
    const displayH = image.clientHeight || 0;
    if (!displayW || !displayH || !image.naturalWidth || !image.naturalHeight) return;
    layer.style.width = `${displayW}px`;
    layer.style.height = `${displayH}px`;
    const sx = displayW / image.naturalWidth;
    const sy = displayH / image.naturalHeight;
    rows.forEach((p) => {
      const x = Number(p.pixel_x || 0) * sx;
      const y = Number(p.pixel_y || 0) * sy;
      const dot = document.createElement("div");
      dot.className = "map-point";
      dot.style.left = `${x}px`;
      dot.style.top = `${y}px`;
      layer.appendChild(dot);

      const tag = document.createElement("div");
      tag.className = "map-point-label";
      tag.style.left = `${x}px`;
      tag.style.top = `${y}px`;
      const t = (p[typeKey] || "").trim();
      const id = (p[idKey] || "").trim();
      tag.textContent = `${t}${id ? ` ${id}` : ""}`;
      layer.appendChild(tag);
    });
  }

  function renderControlPoints() {
    renderDots(pointLayer, clickImage, controlPoints, "asset_type", "asset_id");
    if (!pointList) return;
    if (!controlPoints.length) {
      pointList.innerHTML = `<div class="meta">No control points saved yet.</div>`;
      return;
    }
    pointList.innerHTML = `
      <table>
        <thead>
          <tr><th>#</th><th>Type</th><th>ID</th><th>Pixel</th><th>Lon</th><th>Lat</th><th>Label</th></tr>
        </thead>
        <tbody>
          ${controlPoints.map((p, i) => `
            <tr>
              <td>${i + 1}</td>
              <td>${esc(p.asset_type)}</td>
              <td>${esc(p.asset_id)}</td>
              <td>${esc(`${p.pixel_x}, ${p.pixel_y}`)}</td>
              <td>${esc(p.lon)}</td>
              <td>${esc(p.lat)}</td>
              <td>${esc(p.label)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    `;
  }

  function renderStructurePoints() {
    renderDots(structLayer, structImage, structurePoints, "structure_type", "structure_id");
    if (!structList) return;
    if (!structurePoints.length) {
      structList.innerHTML = `<div class="meta">No structure points saved yet.</div>`;
      return;
    }
    structList.innerHTML = `
      <table>
        <thead>
          <tr><th>#</th><th>Type</th><th>ID</th><th>Pixel</th><th>Lon</th><th>Lat</th></tr>
        </thead>
        <tbody>
          ${structurePoints.map((p, i) => `
            <tr>
              <td>${i + 1}</td>
              <td>${esc(p.structure_type)}</td>
              <td>${esc(p.structure_id)}</td>
              <td>${esc(`${p.pixel_x}, ${p.pixel_y}`)}</td>
              <td>${esc(p.lon)}</td>
              <td>${esc(p.lat)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    `;
  }

  async function refreshControlPoints() {
    const map_stem = stemInput ? stemInput.value.trim() : "";
    if (!map_stem) return;
    const r = await api("mapping_get_control_points", "GET", null, { map_stem });
    if (!r.ok) {
      setMessage(r.error || "Could not load control points.", false);
      return;
    }
    controlPoints = Array.isArray(r.points) ? r.points : [];
    if (clickImage) {
      clickImage.onload = () => {
        clickImage.style.display = "block";
        applyZoom();
        renderControlPoints();
        if (clickMeta) {
          clickMeta.textContent = `Image size: ${r.image_width} x ${r.image_height} | Control points: ${controlPoints.length}`;
        }
      };
      clickImage.src = `${r.image_url}&_ts=${Date.now()}`;
    }
  }

  async function refreshStructurePoints() {
    const map_stem = stemInput ? stemInput.value.trim() : "";
    if (!map_stem) return;
    const r = await api("mapping_get_structure_points", "GET", null, { map_stem });
    if (!r.ok) {
      setMessage(r.error || "Could not load structure points.", false);
      return;
    }
    structurePoints = Array.isArray(r.points) ? r.points : [];
    if (structImage) {
      structImage.onload = () => {
        structImage.style.display = "block";
        applyZoom();
        renderStructurePoints();
        if (structMeta) {
          const world = r.world_file_found ? "yes" : "no";
          structMeta.textContent = `Image size: ${r.image_width} x ${r.image_height} | Georef world file: ${world} | Structure points: ${structurePoints.length}`;
        }
      };
      structImage.src = `${r.image_url}&_ts=${Date.now()}`;
    }
  }

  async function refreshPdfs() {
    setMessage("Loading PDF list...", true);
    const r = await api("mapping_list_pdfs", "GET", null, null);
    if (!r.ok) {
      setMessage(r.error || "Could not load PDF list.", false);
      return;
    }
    const rows = Array.isArray(r.pdfs) ? r.pdfs : [];
    pdfSelect.innerHTML = rows.map((p) => `<option value="${esc(p)}">${esc(p)}</option>`).join("");
    if (rows.length && !stemInput.value.trim()) stemInput.value = inferStem(rows[0]);
    setMessage(`Loaded ${rows.length} PDF file(s).`, true);
    await refreshOutputs();
  }

  async function uploadPdf() {
    if (!uploadInput || !uploadInput.files || !uploadInput.files.length) {
      setMessage("Select a PDF file first.", false);
      return;
    }
    const form = new FormData();
    form.append("pdf", uploadInput.files[0]);
    setMessage("Uploading PDF...", true);
    output.textContent = "";
    const url = new URL("api/index.php", window.location.href);
    url.searchParams.set("action", "mapping_upload_pdf");
    const res = await fetch(url, { method: "POST", body: form });
    const r = await res.json();
    if (!r.ok) {
      setMessage(r.error || "Upload failed.", false);
      return;
    }
    setMessage(`Uploaded ${r.pdf_name}.`, true);
    await refreshPdfs();
    if (pdfSelect) pdfSelect.value = r.pdf_name;
    if (stemInput) stemInput.value = r.map_stem || inferStem(r.pdf_name || "");
    await refreshOutputs();
  }

  async function refreshOutputs() {
    const map_stem = stemInput ? stemInput.value.trim() : "";
    if (!filesWrap) return;
    if (!map_stem) {
      filesWrap.innerHTML = `<div class="meta">Enter map stem to list output files.</div>`;
      return;
    }
    const r = await api("mapping_list_outputs", "GET", null, { map_stem });
    if (!r.ok) {
      filesWrap.innerHTML = `<div class="error">${esc(r.error || "Could not load outputs.")}</div>`;
      return;
    }
    const files = Array.isArray(r.files) ? r.files : [];
    if (!files.length) {
      filesWrap.innerHTML = `<div class="meta">No output files yet for this map.</div>`;
      return;
    }
    filesWrap.innerHTML = files.map((f) => `
      <div class="photo-item">
        <div>${esc(f.name)} <span class="meta">(${Number(f.size || 0).toLocaleString()} bytes)</span></div>
        <a class="link" href="${esc(f.download_url)}">Download</a>
      </div>
    `).join("");
  }

  async function runOperation(operation) {
    const pdf_name = pdfSelect ? pdfSelect.value : "";
    const map_stem = stemInput ? stemInput.value.trim() : "";
    setMessage(`Running ${operation}...`, true);
    output.textContent = "";
    const r = await api("mapping_run", "POST", { operation, pdf_name, map_stem });
    if (!r.ok) {
      setMessage(r.error || `Operation failed: ${operation}`, false);
      output.textContent = r.output || "";
      return;
    }
    setMessage(`${operation} complete (exit ${r.exit_code}).`, true);
    output.textContent = r.output || "";
    await refreshOutputs();
    if (operation === "convert_pdf" || operation === "init_inputs") {
      await refreshControlPoints();
    }
    if (operation === "georef_map" || operation === "build_structure_geojson") {
      await refreshStructurePoints();
    }
  }

  async function saveClickedControlPoint(pixelX, pixelY) {
    const assetTypeInput = window.prompt("Asset type (culvert/bridge/floodgate/drain/landmark):", lastType || "culvert");
    if (assetTypeInput === null) return;
    const asset_type = String(assetTypeInput || "").trim().toLowerCase();
    if (!["culvert", "bridge", "floodgate", "drain", "landmark"].includes(asset_type)) {
      setMessage("Invalid asset type.", false);
      return;
    }
    lastType = asset_type;
    const asset_id = String(window.prompt("Asset number (optional):", "") || "").trim();
    const lon = String(window.prompt("Longitude (leave blank to auto-use asset coords):", "") || "").trim();
    const lat = String(window.prompt("Latitude (leave blank to auto-use asset coords):", "") || "").trim();
    const label = String(window.prompt("Landmark label (optional):", "") || "").trim();

    const map_stem = stemInput ? stemInput.value.trim() : "";
    if (!map_stem) {
      setMessage("Missing map stem.", false);
      return;
    }
    const r = await api("mapping_add_control_point", "POST", {
      map_stem,
      pixel_x: pixelX,
      pixel_y: pixelY,
      asset_type,
      asset_id,
      lon,
      lat,
      label,
    });
    if (!r.ok) {
      setMessage(r.error || "Could not save control point.", false);
      return;
    }
    const sourceMsg = r.coord_source === "asset" ? "coords from asset list" : "manual coords";
    const assetMsg = r.asset_action && r.asset_action !== "none" ? `, asset ${r.asset_action}` : "";
    setMessage(`Control point saved (${sourceMsg}${assetMsg}).`, true);
    await refreshControlPoints();
  }

  async function saveClickedStructurePoint(pixelX, pixelY) {
    const typeInput = window.prompt("Structure type (culvert/bridge/floodgate/drain):", lastStructType || "culvert");
    if (typeInput === null) return;
    const structure_type = String(typeInput || "").trim().toLowerCase();
    if (!["culvert", "bridge", "floodgate", "drain"].includes(structure_type)) {
      setMessage("Invalid structure type.", false);
      return;
    }
    lastStructType = structure_type;
    const structure_id = String(window.prompt("Structure/Asset ID (required):", "") || "").trim();
    if (!structure_id) {
      setMessage("Structure ID is required.", false);
      return;
    }
    const label = String(window.prompt("Optional note/label:", "") || "").trim();

    const map_stem = stemInput ? stemInput.value.trim() : "";
    if (!map_stem) {
      setMessage("Missing map stem.", false);
      return;
    }
    const r = await api("mapping_add_structure_point", "POST", {
      map_stem,
      pixel_x: pixelX,
      pixel_y: pixelY,
      structure_type,
      structure_id,
      label,
      upsert_asset: !!(structUpsertAsset && structUpsertAsset.checked),
    });
    if (!r.ok) {
      setMessage(r.error || "Could not save structure point.", false);
      return;
    }
    const a = r.asset_action && r.asset_action !== "none" ? `, asset ${r.asset_action}` : "";
    setMessage(`Structure point saved (georef coords${a}).`, true);
    await refreshStructurePoints();
  }

  if (clickImage) {
    clickImage.addEventListener("click", async (ev) => {
      if (!clickImage.naturalWidth || !clickImage.clientWidth) return;
      const rect = clickImage.getBoundingClientRect();
      const sx = clickImage.naturalWidth / rect.width;
      const sy = clickImage.naturalHeight / rect.height;
      const pixelX = Math.round((ev.clientX - rect.left) * sx);
      const pixelY = Math.round((ev.clientY - rect.top) * sy);
      await saveClickedControlPoint(pixelX, pixelY);
    });
  }

  if (structImage) {
    structImage.addEventListener("click", async (ev) => {
      if (!structImage.naturalWidth || !structImage.clientWidth) return;
      const rect = structImage.getBoundingClientRect();
      const sx = structImage.naturalWidth / rect.width;
      const sy = structImage.naturalHeight / rect.height;
      const pixelX = Math.round((ev.clientX - rect.left) * sx);
      const pixelY = Math.round((ev.clientY - rect.top) * sy);
      await saveClickedStructurePoint(pixelX, pixelY);
    });
  }

  if (zoomInput) zoomInput.addEventListener("input", applyZoom);
  if (loadClickToolBtn) loadClickToolBtn.addEventListener("click", refreshControlPoints);
  if (loadStructToolBtn) loadStructToolBtn.addEventListener("click", refreshStructurePoints);

  if (pdfSelect) {
    pdfSelect.addEventListener("change", () => {
      const p = pdfSelect.value || "";
      if (!stemInput.value.trim()) stemInput.value = inferStem(p);
    });
  }
  if (refreshBtn) refreshBtn.addEventListener("click", refreshPdfs);
  if (uploadBtn) uploadBtn.addEventListener("click", uploadPdf);
  if (initBtn) initBtn.addEventListener("click", () => runOperation("init_inputs"));
  if (convertBtn) convertBtn.addEventListener("click", () => runOperation("convert_pdf"));
  if (georefBtn) georefBtn.addEventListener("click", () => runOperation("georef_map"));
  if (structBtn) structBtn.addEventListener("click", () => runOperation("build_structure_geojson"));
  if (outputsBtn) outputsBtn.addEventListener("click", refreshOutputs);
  if (stemInput) {
    stemInput.addEventListener("change", async () => {
      await refreshOutputs();
      await refreshControlPoints();
      await refreshStructurePoints();
    });
  }

  refreshPdfs();
})();
