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
  const clickWrap = document.getElementById("mappingClickWrap");
  const pointLayer = document.getElementById("mappingPointLayer");
  const pointList = document.getElementById("mappingPointList");

  const loadStructToolBtn = document.getElementById("mappingLoadStructToolBtn");
  const structMeta = document.getElementById("mappingStructMeta");
  const structImage = document.getElementById("mappingStructImage");
  const structWrap = document.getElementById("mappingStructWrap");
  const structLayer = document.getElementById("mappingStructLayer");
  const structList = document.getElementById("mappingStructList");
  const structUpsertAsset = document.getElementById("mappingStructUpsertAsset");
  const pointModal = document.getElementById("mappingPointModal");
  const pointModalTitle = document.getElementById("mappingPointModalTitle");
  const pointType = document.getElementById("mappingPointType");
  const pointId = document.getElementById("mappingPointId");
  const pointLon = document.getElementById("mappingPointLon");
  const pointLat = document.getElementById("mappingPointLat");
  const pointLabel = document.getElementById("mappingPointLabel");
  const pointAssetHint = document.getElementById("mappingPointAssetHint");
  const pointCoordsRow = document.getElementById("mappingPointCoordsRow");
  const pointModalError = document.getElementById("mappingPointModalError");
  const pointCancelBtn = document.getElementById("mappingPointCancelBtn");
  const pointSaveBtn = document.getElementById("mappingPointSaveBtn");

  let lastType = "culvert";
  let lastStructType = "culvert";
  let controlPoints = [];
  let structurePoints = [];
  let modalResolve = null;
  let modalMode = "control";
  let modalLookupTimer = null;
  let modalLookupSeq = 0;

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

  function setTypeOptions(options, selectedValue) {
    if (!pointType) return;
    pointType.innerHTML = options.map((v) => `<option value="${esc(v)}">${esc(v)}</option>`).join("");
    pointType.value = options.includes(selectedValue) ? selectedValue : options[0];
  }

  function openPointModal(mode, defaults) {
    return new Promise((resolve) => {
      if (!pointModal) {
        resolve(null);
        return;
      }
      modalResolve = resolve;
      modalMode = mode;
      const d = defaults || {};
      if (pointModalError) pointModalError.textContent = "";
      if (pointAssetHint) pointAssetHint.textContent = "";
      if (pointModalTitle) {
        pointModalTitle.textContent = mode === "control" ? "Add Control Point" : "Add Structure Point";
      }
      if (mode === "control") {
        setTypeOptions(["culvert", "bridge", "floodgate", "drain", "landmark"], d.type || "culvert");
        if (pointCoordsRow) pointCoordsRow.style.display = "";
      } else {
        setTypeOptions(["culvert", "bridge", "floodgate", "drain"], d.type || "culvert");
        if (pointCoordsRow) pointCoordsRow.style.display = "none";
      }
      if (pointId) pointId.value = d.id || "";
      if (pointLon) pointLon.value = d.lon || "";
      if (pointLat) pointLat.value = d.lat || "";
      if (pointLabel) pointLabel.value = d.label || "";
      pointModal.classList.remove("hidden");
      if (pointType) pointType.focus();
      scheduleModalAssetLookup();
    });
  }

  function closePointModal(result) {
    if (!pointModal) return;
    pointModal.classList.add("hidden");
    const resolver = modalResolve;
    modalResolve = null;
    if (resolver) resolver(result || null);
  }

  async function lookupModalAsset() {
    const type = String(pointType && pointType.value ? pointType.value : "").trim().toLowerCase();
    const id = String(pointId && pointId.value ? pointId.value : "").trim();
    const lookupSeq = ++modalLookupSeq;
    if (pointAssetHint) pointAssetHint.textContent = "";
    if (!type || !id || type === "landmark") return;
    const r = await api("get_asset", "GET", null, { asset_type: type, asset_id: id });
    if (lookupSeq !== modalLookupSeq) return;
    if (!r || !r.ok || !r.asset) {
      if (pointAssetHint) pointAssetHint.textContent = "No existing asset match found.";
      return;
    }
    const a = r.asset;
    const hasLonLat = a.lon !== "" && a.lat !== "";
    if (modalMode === "control" && hasLonLat) {
      if (pointLon) pointLon.value = String(a.lon);
      if (pointLat) pointLat.value = String(a.lat);
    }
    const wo = String(a.work_order || "").trim();
    const po = String(a.purchase_order || "").trim();
    const bits = [];
    if (wo) bits.push(`WO: ${wo}`);
    if (po) bits.push(`PO: ${po}`);
    if (hasLonLat) bits.push(`lon/lat: ${a.lon}, ${a.lat}`);
    if (pointAssetHint) {
      pointAssetHint.textContent = bits.length ? `Found existing asset. ${bits.join(" | ")}` : "Found existing asset.";
    }
  }

  function scheduleModalAssetLookup() {
    if (modalLookupTimer) clearTimeout(modalLookupTimer);
    modalLookupTimer = setTimeout(() => {
      lookupModalAsset().catch(() => {
        if (pointAssetHint) pointAssetHint.textContent = "";
      });
    }, 180);
  }

  function centerMapInWrap(wrapEl, imageEl) {
    if (!wrapEl || !imageEl) return;
    const imgW = imageEl.clientWidth || 0;
    const imgH = imageEl.clientHeight || 0;
    const viewW = wrapEl.clientWidth || 0;
    const viewH = wrapEl.clientHeight || 0;
    if (!imgW || !imgH || !viewW || !viewH) return;
    const targetLeft = Math.max(0, (imgW - viewW) / 2);
    const targetTop = Math.max(0, (imgH - viewH) / 2);
    wrapEl.scrollLeft = targetLeft;
    wrapEl.scrollTop = targetTop;
  }

  function recenterBothMaps() {
    centerMapInWrap(clickWrap, clickImage);
    centerMapInWrap(structWrap, structImage);
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
    window.requestAnimationFrame(recenterBothMaps);
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
        window.requestAnimationFrame(() => centerMapInWrap(clickWrap, clickImage));
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
        window.requestAnimationFrame(() => centerMapInWrap(structWrap, structImage));
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
    const payload = await openPointModal("control", { type: lastType || "culvert" });
    if (!payload) return;
    const asset_type = payload.type;
    lastType = asset_type;
    const asset_id = payload.id;
    const lon = payload.lon;
    const lat = payload.lat;
    const label = payload.label;

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
    const payload = await openPointModal("structure", { type: lastStructType || "culvert" });
    if (!payload) return;
    const structure_type = payload.type;
    lastStructType = structure_type;
    const structure_id = payload.id;
    if (!structure_id) {
      setMessage("Structure ID is required.", false);
      return;
    }
    const label = payload.label;

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
  if (pointCancelBtn) pointCancelBtn.addEventListener("click", () => closePointModal(null));
  if (pointSaveBtn) {
    pointSaveBtn.addEventListener("click", () => {
      const type = String(pointType && pointType.value ? pointType.value : "").trim().toLowerCase();
      const id = String(pointId && pointId.value ? pointId.value : "").trim();
      const lon = String(pointLon && pointLon.value ? pointLon.value : "").trim();
      const lat = String(pointLat && pointLat.value ? pointLat.value : "").trim();
      const label = String(pointLabel && pointLabel.value ? pointLabel.value : "").trim();
      if (!type) {
        if (pointModalError) pointModalError.textContent = "Type is required.";
        return;
      }
      closePointModal({ type, id, lon, lat, label });
    });
  }
  if (pointModal) {
    pointModal.addEventListener("click", (ev) => {
      if (ev.target === pointModal) closePointModal(null);
    });
  }
  if (pointType) pointType.addEventListener("change", scheduleModalAssetLookup);
  if (pointId) pointId.addEventListener("input", scheduleModalAssetLookup);

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
