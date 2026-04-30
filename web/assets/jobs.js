(function () {
  const cfg = window.ILS_V3_JOBS || {};
  const isAdmin = (cfg.role || "").toLowerCase() === "admin";

  const moduleInput = document.getElementById("jobsModule");
  const statusInput = document.getElementById("jobsStatus");
  const assetTypeInput = document.getElementById("jobsAssetType");
  const currentWorkInput = document.getElementById("jobsCurrentWork");
  const invoiceReadyInput = document.getElementById("jobsInvoiceReady");
  const searchInput = document.getElementById("jobsSearch");
  const refreshBtn = document.getElementById("jobsRefreshBtn");
  const tableWrap = document.getElementById("jobsTableWrap");
  const actionMsg = document.getElementById("jobsActionMsg");
  const selectAllBtn = document.getElementById("jobsSelectAllBtn");
  const clearSelBtn = document.getElementById("jobsClearSelectionBtn");
  const addCurrentBtn = document.getElementById("jobsAddCurrentBtn");
  const removeCurrentBtn = document.getElementById("jobsRemoveCurrentBtn");
  const markCompletedBtn = document.getElementById("jobsMarkCompletedBtn");
  const markInvoicedBtn = document.getElementById("jobsMarkInvoicedBtn");
  const invoiceAsCompletedBtn = document.getElementById("jobsInvoiceAsCompletedBtn");
  const importBtn = document.getElementById("jobsImportBtn");
  const importFile = document.getElementById("jobsCsvFile");
  const importMsg = document.getElementById("jobsImportMsg");
  const importXlsxBtn = document.getElementById("jobsImportXlsxBtn");
  const previewXlsxBtn = document.getElementById("jobsPreviewXlsxBtn");
  const workFileInput = document.getElementById("jobsWorkFile");
  const jobFilesInput = document.getElementById("jobsJobFiles");
  const importXlsxMsg = document.getElementById("jobsImportXlsxMsg");

  function esc(v) {
    return String(v || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function mapLink(lat, lon) {
    if (!lat || !lon) return "";
    const q = encodeURIComponent(`${lat},${lon}`);
    return `<a class="pin-link" title="Open map pin" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${q}"><img src="assets/gps.png" alt="Map pin"></a>`;
  }

  function assetDisplay(assetType, assetId) {
    let id = String(assetId || "").trim();
    id = id.replace(/^\s*drain\b[\s:_-]*/i, "").trim();
    return id;
  }

  function parseMeta(metaText) {
    if (!metaText) return {};
    try {
      const obj = JSON.parse(metaText);
      return obj && typeof obj === "object" ? obj : {};
    } catch (_) {
      return {};
    }
  }

  function segmentText(job) {
    const meta = parseMeta(job.meta);
    const hasNums = Number.isFinite(Number(meta.start_m)) && Number.isFinite(Number(meta.end_m));
    if (hasNums) {
      return `(${Math.round(Number(meta.start_m))}-${Math.round(Number(meta.end_m))})`;
    }
    const m = String(job.description || "").match(/\((\d+)\s*-\s*(\d+)\)/);
    if (m) return `(${m[1]}-${m[2]})`;
    return "";
  }

  function totalKmText(job) {
    const meta = parseMeta(job.meta);
    if (Number.isFinite(Number(meta.qty_km))) {
      return `${Number(meta.qty_km).toFixed(2)}km`;
    }
    const m = segmentText(job).match(/\((\d+)-(\d+)\)/);
    if (m) {
      const d = Math.max(0, Number(m[2]) - Number(m[1]));
      return `${(d / 1000).toFixed(2)}km`;
    }
    return "";
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

  function selectedJobIds() {
    return Array.from(document.querySelectorAll(".job-select:checked"))
      .map((el) => Number(el.value))
      .filter((n) => Number.isFinite(n) && n > 0);
  }

  async function updateFlags(payload, okMsg) {
    const ids = selectedJobIds();
    if (!ids.length) {
      if (actionMsg) {
        actionMsg.className = "error";
        actionMsg.textContent = "Select one or more jobs first.";
      }
      return;
    }
    const r = await api("update_jobs_flags", "POST", { ids, ...payload });
    if (!r.ok) {
      if (actionMsg) {
        actionMsg.className = "error";
        actionMsg.textContent = r.error || "Update failed.";
      }
      return;
    }
    if (actionMsg) {
      actionMsg.className = "success";
      actionMsg.textContent = `${okMsg} (${r.updated || 0} jobs)`;
    }
    await loadJobs();
  }

  async function loadJobs() {
    if (!tableWrap) return;
    const params = {
      module: moduleInput.value.trim(),
      status: statusInput.value,
      asset_type: assetTypeInput.value,
      current_work: currentWorkInput ? currentWorkInput.value : "",
      invoice_ready: invoiceReadyInput ? invoiceReadyInput.value : "",
      q: searchInput.value.trim(),
      limit: "1000",
    };
    const r = await api("list_jobs", "GET", null, params);
    if (!r.ok) {
      tableWrap.innerHTML = `<div class="error">${esc(r.error || "Could not load jobs.")}</div>`;
      return;
    }
    const rows = Array.isArray(r.jobs) ? r.jobs : [];
    if (!rows.length) {
      tableWrap.innerHTML = `<div class="meta">No jobs found.</div>`;
      return;
    }
    tableWrap.innerHTML = `
      <div class="jobs-cards">
        ${rows.map((j) => {
          const title = `${assetDisplay(j.asset_type || "", j.asset_id || "")} ${segmentText(j)} ${totalKmText(j)}`.trim();
          return `
            <article class="job-card">
              <div class="job-card-head">
                ${isAdmin ? `<input class="job-select" type="checkbox" value="${Number(j.id) || 0}">` : ""}
                <div class="job-title">${esc(title)}</div>
                <div class="job-pin">${mapLink(j.lat, j.lon)}</div>
              </div>
              <div class="job-topline">
                <span><b>WO:</b> ${esc(j.work_order || "")}</span>
                <span><b>PO:</b> ${esc(j.purchase_order || "")}</span>
                <span><b>Status:</b> ${esc(j.status || "")}</span>
              </div>
              <div class="job-meta-grid">
                <div><span class="k">Module</span><span class="v">${esc(j.module || "")}</span></div>
                <div><span class="k">Current</span><span class="v">${Number(j.in_current_work) ? "Yes" : "No"}</span></div>
                <div><span class="k">Match</span><span class="v">${j.asset_ref ? "Matched" : "<span class='error'>Unmatched</span>"}</span></div>
                <div><span class="k">Completed</span><span class="v">${j.completed_at ? esc(j.completed_at) : ""}</span></div>
                <div><span class="k">Invoiced</span><span class="v">${j.invoiced_at ? esc(j.invoiced_at) : ""}</span></div>
                <div><span class="k">Updated</span><span class="v">${esc(j.updated_at || "")}</span></div>
              </div>
            </article>
          `;
        }).join("")}
      </div>
    `;
  }

  async function importCsv() {
    if (!isAdmin) return;
    if (!importFile || !importFile.files || !importFile.files.length) {
      importMsg.className = "error";
      importMsg.textContent = "Choose a CSV file first.";
      return;
    }
    importMsg.className = "";
    importMsg.textContent = "Importing...";

    const form = new FormData();
    form.append("file", importFile.files[0]);
    const url = new URL("api/index.php", window.location.href);
    url.searchParams.set("action", "import_jobs_csv");
    const res = await fetch(url, { method: "POST", body: form });
    const j = await res.json();
    if (!j.ok) {
      importMsg.className = "error";
      importMsg.textContent = j.error || "Import failed.";
      return;
    }
    importMsg.className = "success";
    importMsg.textContent = `Imported ${j.rows} rows. Matched: ${j.matched_assets}, Unmatched: ${j.unmatched_assets}.`;
    await loadJobs();
  }

  async function importXlsxBundle(dryRun) {
    if (!isAdmin) return;
    if (!workFileInput || !workFileInput.files || !workFileInput.files.length) {
      importXlsxMsg.className = "error";
      importXlsxMsg.textContent = "Choose the WO/PO XLSX file first.";
      return;
    }
    if (!jobFilesInput || !jobFilesInput.files || !jobFilesInput.files.length) {
      importXlsxMsg.className = "error";
      importXlsxMsg.textContent = "Choose one or more drain job XLSX files.";
      return;
    }
    importXlsxMsg.className = "";
    importXlsxMsg.textContent = dryRun ? "Running dry-run preview..." : "Importing XLSX bundle...";
    const form = new FormData();
    form.append("work_file", workFileInput.files[0]);
    Array.from(jobFilesInput.files).forEach((f) => form.append("job_files[]", f));
    const url = new URL("api/index.php", window.location.href);
    url.searchParams.set("action", "import_jobs_xlsx_bundle");
    if (dryRun) url.searchParams.set("dry_run", "1");
    const res = await fetch(url, { method: "POST", body: form });
    const j = await res.json();
    if (!j.ok) {
      importXlsxMsg.className = "error";
      importXlsxMsg.textContent = `${j.error || "XLSX import failed."}${j.detail ? ` (${j.detail})` : ""}`;
      return;
    }
    const c = j.counts || {};
    if (dryRun) {
      importXlsxMsg.className = "meta";
      const p = j.preview || [];
      const previewText = p.length ? ` Sample: ${p.map((r) => `${r.module || "work"}:${r.asset_type || ""} ${r.asset_id || ""}`).join(" | ")}` : "";
      importXlsxMsg.textContent = `Dry run only. Rows: ${j.rows}. Matched: ${j.matched_assets}, Unmatched: ${j.unmatched_assets}. Drains: ${c.drain_rows || 0}, Other: ${c.other_rows || 0}.${previewText}`;
      return;
    }
    importXlsxMsg.className = "success";
    importXlsxMsg.textContent = `Imported ${j.rows} rows. Matched: ${j.matched_assets}, Unmatched: ${j.unmatched_assets}. Drains: ${c.drain_rows || 0}, Other: ${c.other_rows || 0}.`;
    await loadJobs();
  }

  if (refreshBtn) refreshBtn.addEventListener("click", loadJobs);
  if (searchInput) searchInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") loadJobs();
  });
  if (moduleInput) moduleInput.addEventListener("change", loadJobs);
  if (statusInput) statusInput.addEventListener("change", loadJobs);
  if (assetTypeInput) assetTypeInput.addEventListener("change", loadJobs);
  if (currentWorkInput) currentWorkInput.addEventListener("change", loadJobs);
  if (invoiceReadyInput) invoiceReadyInput.addEventListener("change", loadJobs);
  if (importBtn) importBtn.addEventListener("click", importCsv);
  if (previewXlsxBtn) previewXlsxBtn.addEventListener("click", () => importXlsxBundle(true));
  if (importXlsxBtn) importXlsxBtn.addEventListener("click", () => importXlsxBundle(false));
  if (selectAllBtn) selectAllBtn.addEventListener("click", () => {
    document.querySelectorAll(".job-select").forEach((el) => { el.checked = true; });
  });
  if (clearSelBtn) clearSelBtn.addEventListener("click", () => {
    document.querySelectorAll(".job-select").forEach((el) => { el.checked = false; });
  });
  if (addCurrentBtn) addCurrentBtn.addEventListener("click", () => updateFlags({ in_current_work: 1 }, "Added to current work"));
  if (removeCurrentBtn) removeCurrentBtn.addEventListener("click", () => updateFlags({ in_current_work: 0 }, "Removed from current work"));
  if (markCompletedBtn) markCompletedBtn.addEventListener("click", () => updateFlags({ mark_completed: 1 }, "Marked completed"));
  if (markInvoicedBtn) markInvoicedBtn.addEventListener("click", () => updateFlags({ mark_invoiced: 1 }, "Marked invoiced"));
  if (invoiceAsCompletedBtn) invoiceAsCompletedBtn.addEventListener("click", () => updateFlags({ mark_completed: 1, mark_invoiced: 1 }, "Marked completed and invoiced"));
  if (tableWrap) loadJobs();
})();
