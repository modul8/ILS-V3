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
    return `<a class="link" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${q}">Map</a>`;
  }

  function assetDisplay(assetType, assetId) {
    const t = String(assetType || "").trim().toLowerCase();
    let id = String(assetId || "").trim();
    if (t === "drain") id = id.replace(/^drain\s+/i, "").trim();
    return id;
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
      <table>
        <thead>
          <tr>
            ${isAdmin ? "<th></th>" : ""}
            <th>Module</th>
            <th>Asset</th>
            <th>WO</th>
            <th>PO</th>
            <th>Status</th>
            <th>Pin</th>
            <th>Current</th>
            <th>Completed</th>
            <th>Invoiced</th>
            <th>Match</th>
            <th>Updated</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map((j) => `
            <tr>
              ${isAdmin ? `<td><input class="job-select" type="checkbox" value="${Number(j.id) || 0}"></td>` : ""}
              <td>${esc(j.module)}</td>
              <td>${esc(assetDisplay(j.asset_type || "", j.asset_id || ""))}</td>
              <td>${esc(j.work_order || "")}</td>
              <td>${esc(j.purchase_order || "")}</td>
              <td>${esc(j.status || "")}</td>
              <td>${mapLink(j.lat, j.lon)}</td>
              <td>${Number(j.in_current_work) ? "Yes" : "No"}</td>
              <td>${j.completed_at ? esc(j.completed_at) : ""}</td>
              <td>${j.invoiced_at ? esc(j.invoiced_at) : ""}</td>
              <td>${j.asset_ref ? "Matched" : "<span class='error'>Unmatched</span>"}</td>
              <td>${esc(j.updated_at || "")}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
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
  loadJobs();
})();
