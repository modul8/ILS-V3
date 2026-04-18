(function () {
  const pdfSelect = document.getElementById("mappingPdf");
  const stemInput = document.getElementById("mappingStem");
  const msg = document.getElementById("mappingMsg");
  const output = document.getElementById("mappingOutput");

  const refreshBtn = document.getElementById("mappingRefreshPdfsBtn");
  const initBtn = document.getElementById("mappingInitBtn");
  const convertBtn = document.getElementById("mappingConvertBtn");
  const georefBtn = document.getElementById("mappingGeorefBtn");
  const structBtn = document.getElementById("mappingStructBtn");

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
  }

  if (pdfSelect) {
    pdfSelect.addEventListener("change", () => {
      const p = pdfSelect.value || "";
      if (!stemInput.value.trim()) stemInput.value = inferStem(p);
    });
  }
  if (refreshBtn) refreshBtn.addEventListener("click", refreshPdfs);
  if (initBtn) initBtn.addEventListener("click", () => runOperation("init_inputs"));
  if (convertBtn) convertBtn.addEventListener("click", () => runOperation("convert_pdf"));
  if (georefBtn) georefBtn.addEventListener("click", () => runOperation("georef_map"));
  if (structBtn) structBtn.addEventListener("click", () => runOperation("build_structure_geojson"));

  refreshPdfs();
})();
