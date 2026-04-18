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
  }

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
  if (stemInput) stemInput.addEventListener("change", refreshOutputs);

  refreshPdfs();
})();
