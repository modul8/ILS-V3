(function () {
  const pdfSelect = document.getElementById("mappingPdf");
  const stemInput = document.getElementById("mappingStem");
  const msg = document.getElementById("mappingMsg");
  const output = document.getElementById("mappingOutput");
  const filesWrap = document.getElementById("mappingFiles");
  const collectionsFilesWrap = document.getElementById("mappingCollectionsFiles");
  const uploadInput = document.getElementById("mappingUploadPdf");

  const refreshBtn = document.getElementById("mappingRefreshPdfsBtn");
  const uploadBtn = document.getElementById("mappingUploadBtn");
  const initBtn = document.getElementById("mappingInitBtn");
  const convertBtn = document.getElementById("mappingConvertBtn");
  const rotateCcwBtn = document.getElementById("mappingRotateCcwBtn");
  const rotateCwBtn = document.getElementById("mappingRotateCwBtn");
  const georefBtn = document.getElementById("mappingGeorefBtn");
  const structBtn = document.getElementById("mappingStructBtn");
  const buildCollectionsBtn = document.getElementById("mappingBuildCollectionsBtn");
  const collectionsRefreshBtn = document.getElementById("mappingCollectionsRefreshBtn");
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
  const loadDrainTraceBtn = document.getElementById("mappingLoadDrainTraceBtn");
  const drainTraceClearBtn = document.getElementById("mappingDrainTraceClearBtn");
  const drainTraceSaveBtn = document.getElementById("mappingDrainTraceSaveBtn");
  const drainTraceMeta = document.getElementById("mappingDrainTraceMeta");
  const drainIdInput = document.getElementById("mappingDrainId");
  const drainImage = document.getElementById("mappingDrainImage");
  const drainWrap = document.getElementById("mappingDrainWrap");
  const drainOverlay = document.getElementById("mappingDrainOverlay");
  const drainPointLayer = document.getElementById("mappingDrainPointLayer");
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
  let controlCenteredOnce = false;
  let structureCenteredOnce = false;
  let drainCenteredOnce = false;
  let drainTraceStart = null;
  let drainTraceEnd = null;
  let drainTracePixels = [];
  let drainTraceCoords = [];

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

  function clearMapClickViews() {
    controlPoints = [];
    structurePoints = [];
    controlCenteredOnce = false;
    structureCenteredOnce = false;
    drainCenteredOnce = false;
    if (clickImage) {
      clickImage.removeAttribute("src");
      clickImage.style.display = "none";
    }
    if (structImage) {
      structImage.removeAttribute("src");
      structImage.style.display = "none";
    }
    if (drainImage) {
      drainImage.removeAttribute("src");
      drainImage.style.display = "none";
    }
    if (pointLayer) pointLayer.innerHTML = "";
    if (structLayer) structLayer.innerHTML = "";
    if (drainPointLayer) drainPointLayer.innerHTML = "";
    if (drainOverlay) drainOverlay.innerHTML = "";
    if (clickMeta) clickMeta.textContent = "";
    if (structMeta) structMeta.textContent = "";
    if (drainTraceMeta) drainTraceMeta.textContent = "";
    if (pointList) pointList.innerHTML = `<div class="meta">No control points loaded for current map.</div>`;
    if (structList) structList.innerHTML = `<div class="meta">No structure points loaded for current map.</div>`;
    drainTraceStart = null;
    drainTraceEnd = null;
    drainTracePixels = [];
    drainTraceCoords = [];
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
    if (hasLonLat) bits.push(`lat/lon: ${a.lat}, ${a.lon}`);
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

  function applyZoom() {
    if (!clickImage) return;
    const prevClickW = clickImage.clientWidth || 0;
    const prevStructW = structImage && structImage.clientWidth ? structImage.clientWidth : 0;
    const prevDrainW = drainImage && drainImage.clientWidth ? drainImage.clientWidth : 0;
    const clickCenterRatioX = prevClickW > 0 ? (clickWrap.scrollLeft + (clickWrap.clientWidth / 2)) / prevClickW : 0.5;
    const clickCenterRatioY = clickImage.clientHeight > 0 ? (clickWrap.scrollTop + (clickWrap.clientHeight / 2)) / clickImage.clientHeight : 0.5;
    const structCenterRatioX = prevStructW > 0 ? (structWrap.scrollLeft + (structWrap.clientWidth / 2)) / prevStructW : 0.5;
    const structCenterRatioY = structImage && structImage.clientHeight > 0 ? (structWrap.scrollTop + (structWrap.clientHeight / 2)) / structImage.clientHeight : 0.5;
    const drainCenterRatioX = prevDrainW > 0 ? (drainWrap.scrollLeft + (drainWrap.clientWidth / 2)) / prevDrainW : 0.5;
    const drainCenterRatioY = drainImage && drainImage.clientHeight > 0 ? (drainWrap.scrollTop + (drainWrap.clientHeight / 2)) / drainImage.clientHeight : 0.5;
    const zoom = Number(zoomInput && zoomInput.value ? zoomInput.value : 100);
    if (zoomLabel) zoomLabel.textContent = `${zoom}%`;
    if (clickImage.naturalWidth) {
      clickImage.style.width = `${Math.round(clickImage.naturalWidth * (zoom / 100))}px`;
    }
    if (structImage && structImage.naturalWidth) {
      structImage.style.width = `${Math.round(structImage.naturalWidth * (zoom / 100))}px`;
    }
    if (drainImage && drainImage.naturalWidth) {
      drainImage.style.width = `${Math.round(drainImage.naturalWidth * (zoom / 100))}px`;
    }
    renderControlPoints();
    renderStructurePoints();
    renderDrainTraceOverlay();
    window.requestAnimationFrame(() => {
      const newClickW = clickImage.clientWidth || 0;
      const newClickH = clickImage.clientHeight || 0;
      if (newClickW > 0 && newClickH > 0) {
        clickWrap.scrollLeft = Math.max(0, (newClickW * clickCenterRatioX) - (clickWrap.clientWidth / 2));
        clickWrap.scrollTop = Math.max(0, (newClickH * clickCenterRatioY) - (clickWrap.clientHeight / 2));
      }
      const newStructW = structImage ? (structImage.clientWidth || 0) : 0;
      const newStructH = structImage ? (structImage.clientHeight || 0) : 0;
      if (newStructW > 0 && newStructH > 0) {
        structWrap.scrollLeft = Math.max(0, (newStructW * structCenterRatioX) - (structWrap.clientWidth / 2));
        structWrap.scrollTop = Math.max(0, (newStructH * structCenterRatioY) - (structWrap.clientHeight / 2));
      }
      const newDrainW = drainImage ? (drainImage.clientWidth || 0) : 0;
      const newDrainH = drainImage ? (drainImage.clientHeight || 0) : 0;
      if (newDrainW > 0 && newDrainH > 0) {
        drainWrap.scrollLeft = Math.max(0, (newDrainW * drainCenterRatioX) - (drainWrap.clientWidth / 2));
        drainWrap.scrollTop = Math.max(0, (newDrainH * drainCenterRatioY) - (drainWrap.clientHeight / 2));
      }
    });
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

  function renderDrainTraceOverlay() {
    if (!drainImage || !drainOverlay || !drainPointLayer) return;
    drainOverlay.innerHTML = "";
    drainPointLayer.innerHTML = "";
    const displayW = drainImage.clientWidth || 0;
    const displayH = drainImage.clientHeight || 0;
    if (!displayW || !displayH || !drainImage.naturalWidth || !drainImage.naturalHeight) return;
    const sx = displayW / drainImage.naturalWidth;
    const sy = displayH / drainImage.naturalHeight;

    const addMarker = (pt, color) => {
      if (!pt) return;
      const x = Number(pt[0]) * sx;
      const y = Number(pt[1]) * sy;
      const dot = document.createElement("div");
      dot.className = "map-point";
      dot.style.left = `${x}px`;
      dot.style.top = `${y}px`;
      dot.style.background = color;
      drainPointLayer.appendChild(dot);
    };
    addMarker(drainTraceStart, "#16a34a");
    addMarker(drainTraceEnd, "#dc2626");

    if (drainTracePixels.length >= 2) {
      const pts = drainTracePixels.map((p) => `${(Number(p[0]) * sx).toFixed(1)},${(Number(p[1]) * sy).toFixed(1)}`).join(" ");
      const poly = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
      poly.setAttribute("points", pts);
      poly.setAttribute("fill", "none");
      poly.setAttribute("stroke", "#16a34a");
      poly.setAttribute("stroke-width", "3");
      poly.setAttribute("stroke-linejoin", "round");
      poly.setAttribute("stroke-linecap", "round");
      poly.setAttribute("opacity", "0.95");
      drainOverlay.appendChild(poly);
    }
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
          <tr><th>#</th><th>Type</th><th>ID</th><th>Pixel</th><th>Lat</th><th>Lon</th><th>Label</th><th></th></tr>
        </thead>
        <tbody>
          ${controlPoints.map((p, i) => `
            <tr>
              <td>${i + 1}</td>
              <td>${esc(p.asset_type)}</td>
              <td>${esc(p.asset_id)}</td>
              <td>${esc(`${p.pixel_x}, ${p.pixel_y}`)}</td>
              <td>${esc(p.lat)}</td>
              <td>${esc(p.lon)}</td>
              <td>${esc(p.label)}</td>
              <td><button class="btn btn-secondary mapping-delete-control" data-index="${i}" type="button">Delete</button></td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    `;
    pointList.querySelectorAll(".mapping-delete-control").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const idx = Number(btn.getAttribute("data-index"));
        if (!Number.isInteger(idx) || idx < 0) return;
        const yes = window.confirm(`Delete control point #${idx + 1}?`);
        if (!yes) return;
        const map_stem = stemInput ? stemInput.value.trim() : "";
        const r = await api("mapping_delete_control_point", "POST", { map_stem, index: idx });
        if (!r.ok) {
          setMessage(r.error || "Could not delete control point.", false);
          return;
        }
        setMessage(`Control point deleted. Remaining: ${r.remaining}`, true);
        await refreshControlPoints();
      });
    });
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
          <tr><th>#</th><th>Type</th><th>ID</th><th>Pixel</th><th>Lat</th><th>Lon</th></tr>
        </thead>
        <tbody>
          ${structurePoints.map((p, i) => `
            <tr>
              <td>${i + 1}</td>
              <td>${esc(p.structure_type)}</td>
              <td>${esc(p.structure_id)}</td>
              <td>${esc(`${p.pixel_x}, ${p.pixel_y}`)}</td>
              <td>${esc(p.lat)}</td>
              <td>${esc(p.lon)}</td>
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
        if (!controlCenteredOnce) {
          window.requestAnimationFrame(() => centerMapInWrap(clickWrap, clickImage));
          controlCenteredOnce = true;
        }
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
        if (!structureCenteredOnce) {
          window.requestAnimationFrame(() => centerMapInWrap(structWrap, structImage));
          structureCenteredOnce = true;
        }
        if (structMeta) {
          const world = r.world_file_found ? "yes" : "no";
          structMeta.textContent = `Image size: ${r.image_width} x ${r.image_height} | Georef world file: ${world} | Structure points: ${structurePoints.length}`;
        }
      };
      structImage.src = `${r.image_url}&_ts=${Date.now()}`;
    }
  }

  async function refreshDrainTraceMap() {
    const map_stem = stemInput ? stemInput.value.trim() : "";
    if (!map_stem) return;
    const r = await api("mapping_get_trace_map", "GET", null, { map_stem });
    if (!r.ok) {
      setMessage(r.error || "Could not load drain trace map.", false);
      return;
    }
    if (drainImage) {
      drainImage.onload = () => {
        drainImage.style.display = "block";
        applyZoom();
        renderDrainTraceOverlay();
        if (!drainCenteredOnce) {
          window.requestAnimationFrame(() => centerMapInWrap(drainWrap, drainImage));
          drainCenteredOnce = true;
        }
        if (drainTraceMeta) {
          const world = r.world_file_found ? "yes" : "no";
          drainTraceMeta.textContent = `Image size: ${r.image_width} x ${r.image_height} | Georef world file: ${world}`;
        }
      };
      drainImage.src = `${r.image_url}&_ts=${Date.now()}`;
    }
  }

  function clearDrainTrace() {
    drainTraceStart = null;
    drainTraceEnd = null;
    drainTracePixels = [];
    drainTraceCoords = [];
    renderDrainTraceOverlay();
    if (drainTraceMeta) drainTraceMeta.textContent = "Trace cleared.";
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

  async function refreshCollections() {
    if (!collectionsFilesWrap) return;
    const r = await api("mapping_list_asset_collections", "GET", null, null);
    if (!r.ok) {
      collectionsFilesWrap.innerHTML = `<div class="meta">No collection files yet.</div>`;
      return;
    }
    const files = Array.isArray(r.files) ? r.files : [];
    if (!files.length) {
      collectionsFilesWrap.innerHTML = `<div class="meta">No collection files yet.</div>`;
      return;
    }
    collectionsFilesWrap.innerHTML = files.map((f) => `
      <div class="photo-item">
        <div>${esc(f.name)} <span class="meta">(${Number(f.size || 0).toLocaleString()} bytes)</span></div>
        <a class="link" href="${esc(f.download_url)}">Download</a>
      </div>
    `).join("");
  }

  async function runOperation(operation, extraBody) {
    const pdf_name = pdfSelect ? pdfSelect.value : "";
    const map_stem = stemInput ? stemInput.value.trim() : "";
    setMessage(`Running ${operation}...`, true);
    output.textContent = "";
    const r = await api("mapping_run", "POST", { operation, pdf_name, map_stem, ...(extraBody || {}) });
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
    if (operation === "rotate_pdf") {
      await refreshPdfs();
      clearMapClickViews();
    }
    if (operation === "georef_map" || operation === "build_structure_geojson") {
      await refreshStructurePoints();
    }
    if (operation === "build_asset_collections") {
      await refreshCollections();
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

  if (drainImage) {
    drainImage.addEventListener("click", async (ev) => {
      if (!drainImage.naturalWidth || !drainImage.clientWidth) return;
      const rect = drainImage.getBoundingClientRect();
      const sx = drainImage.naturalWidth / rect.width;
      const sy = drainImage.naturalHeight / rect.height;
      const px = Math.round((ev.clientX - rect.left) * sx);
      const py = Math.round((ev.clientY - rect.top) * sy);
      if (!drainTraceStart) {
        drainTraceStart = [px, py];
        drainTraceEnd = null;
        drainTracePixels = [];
        drainTraceCoords = [];
        renderDrainTraceOverlay();
        if (drainTraceMeta) drainTraceMeta.textContent = `Start set: ${px}, ${py}. Click end point.`;
        return;
      }
      drainTraceEnd = [px, py];
      renderDrainTraceOverlay();
      const map_stem = stemInput ? stemInput.value.trim() : "";
      const r = await api("mapping_trace_drain", "POST", {
        map_stem,
        start_x: drainTraceStart[0],
        start_y: drainTraceStart[1],
        end_x: drainTraceEnd[0],
        end_y: drainTraceEnd[1],
      });
      if (!r.ok) {
        setMessage(r.error || "Drain trace failed.", false);
        drainTracePixels = [];
        drainTraceCoords = [];
        renderDrainTraceOverlay();
        return;
      }
      drainTracePixels = Array.isArray(r.pixel_points) ? r.pixel_points : [];
      drainTraceCoords = Array.isArray(r.coord_points) ? r.coord_points : [];
      if (Array.isArray(r.start_snap)) drainTraceStart = r.start_snap;
      if (Array.isArray(r.end_snap)) drainTraceEnd = r.end_snap;
      renderDrainTraceOverlay();
      if (drainTraceMeta) drainTraceMeta.textContent = `Trace preview ready (${r.point_count || drainTracePixels.length} points).`;
    });
  }

  if (zoomInput) zoomInput.addEventListener("input", applyZoom);
  if (loadClickToolBtn) loadClickToolBtn.addEventListener("click", refreshControlPoints);
  if (loadStructToolBtn) loadStructToolBtn.addEventListener("click", refreshStructurePoints);
  if (loadDrainTraceBtn) loadDrainTraceBtn.addEventListener("click", refreshDrainTraceMap);
  if (drainTraceClearBtn) drainTraceClearBtn.addEventListener("click", clearDrainTrace);
  if (drainTraceSaveBtn) {
    drainTraceSaveBtn.addEventListener("click", async () => {
      const map_stem = stemInput ? stemInput.value.trim() : "";
      const drain_id = String(drainIdInput && drainIdInput.value ? drainIdInput.value : "").trim();
      if (!map_stem) {
        setMessage("Missing map stem.", false);
        return;
      }
      if (!drain_id) {
        setMessage("Drain ID is required.", false);
        return;
      }
      if (!Array.isArray(drainTraceCoords) || drainTraceCoords.length < 2) {
        setMessage("No drain trace to save. Click start and end first.", false);
        return;
      }
      const yes = window.confirm(`Save traced line to drain ${drain_id}?`);
      if (!yes) return;
      const r = await api("mapping_save_drain_track", "POST", { map_stem, drain_id, coord_points: drainTraceCoords });
      if (!r.ok) {
        setMessage(r.error || "Could not save drain track.", false);
        return;
      }
      setMessage(`Drain track saved for ${drain_id}${r.asset_action === "created" ? " (asset created)" : ""}.`, true);
      await refreshOutputs();
      clearDrainTrace();
    });
  }
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
      if (stemInput) stemInput.value = inferStem(p);
      clearMapClickViews();
    });
  }
  if (refreshBtn) refreshBtn.addEventListener("click", refreshPdfs);
  if (uploadBtn) uploadBtn.addEventListener("click", uploadPdf);
  if (initBtn) initBtn.addEventListener("click", () => runOperation("init_inputs"));
  if (convertBtn) convertBtn.addEventListener("click", () => runOperation("convert_pdf"));
  if (rotateCcwBtn) rotateCcwBtn.addEventListener("click", () => runOperation("rotate_pdf", { degrees: -90 }));
  if (rotateCwBtn) rotateCwBtn.addEventListener("click", () => runOperation("rotate_pdf", { degrees: 90 }));
  if (georefBtn) georefBtn.addEventListener("click", () => runOperation("georef_map"));
  if (structBtn) structBtn.addEventListener("click", () => runOperation("build_structure_geojson"));
  if (buildCollectionsBtn) buildCollectionsBtn.addEventListener("click", () => runOperation("build_asset_collections"));
  if (collectionsRefreshBtn) collectionsRefreshBtn.addEventListener("click", refreshCollections);
  if (outputsBtn) outputsBtn.addEventListener("click", refreshOutputs);
  if (stemInput) {
    stemInput.addEventListener("change", async () => {
      clearMapClickViews();
      await refreshOutputs();
      await refreshControlPoints();
      await refreshStructurePoints();
    });
  }

  refreshPdfs();
  refreshCollections();
})();
