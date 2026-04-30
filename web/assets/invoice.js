(function () {
  const cfg = window.ILS_V3_INVOICE || {};
  if ((cfg.role || "").toLowerCase() !== "admin") return;

  const moduleInput = document.getElementById("invModule");
  const assetTypeInput = document.getElementById("invAssetType");
  const viewInput = document.getElementById("invView");
  const searchInput = document.getElementById("invSearch");
  const baseUrlInput = document.getElementById("invBaseUrl");
  const apiKeyInput = document.getElementById("invApiKey");
  const socidInput = document.getElementById("invSocid");
  const lineRateInput = document.getElementById("invLineRate");
  const tvaTxInput = document.getElementById("invTvaTx");
  const testConnBtn = document.getElementById("invTestConnBtn");
  const createDraftsBtn = document.getElementById("invCreateDraftsBtn");
  const refreshBtn = document.getElementById("invRefreshBtn");
  const selectAllBtn = document.getElementById("invSelectAllBtn");
  const clearBtn = document.getElementById("invClearBtn");
  const markBtn = document.getElementById("invMarkBtn");
  const undoBtn = document.getElementById("invUndoBtn");
  const exportBtn = document.getElementById("invExportBtn");
  const msg = document.getElementById("invMsg");
  const summary = document.getElementById("invSummary");
  const list = document.getElementById("invList");

  let currentRows = [];
  if (baseUrlInput) baseUrlInput.value = cfg.dolibarr_base_url || "";
  const defaultSocid = String(cfg.dolibarr_socid || "").trim();
  if (tvaTxInput) tvaTxInput.value = cfg.dolibarr_tva_tx || "0";

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

  function assetDisplay(assetId) {
    return String(assetId || "").replace(/^\s*drain\b[\s:_-]*/i, "").trim();
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

  function selectedIds() {
    return Array.from(document.querySelectorAll(".inv-select:checked"))
      .map((el) => Number(el.value))
      .filter((n) => Number.isFinite(n) && n > 0);
  }

  function renderSummary(rows) {
    const po = new Map();
    rows.forEach((r) => {
      const key = String(r.purchase_order || "(no PO)");
      po.set(key, (po.get(key) || 0) + 1);
    });
    const parts = Array.from(po.entries()).map(([k, v]) => `${k}: ${v}`).join(" | ");
    summary.textContent = `Rows: ${rows.length}${parts ? " | By PO: " + parts : ""}`;
  }

  function toCsv(rows) {
    const cols = ["module", "asset_type", "asset_id", "work_order", "purchase_order", "status", "completed_at", "invoiced_at", "lat", "lon"];
    const out = [cols.join(",")];
    rows.forEach((r) => {
      const line = cols.map((c) => {
        const v = String(r[c] ?? "");
        return `"${v.replace(/"/g, '""')}"`;
      }).join(",");
      out.push(line);
    });
    return out.join("\n");
  }

  async function loadQueue() {
    const view = viewInput.value;
    const params = {
      module: moduleInput.value.trim(),
      asset_type: assetTypeInput.value,
      q: searchInput.value.trim(),
      completed: "1",
      limit: "2000",
    };
    if (view === "pending") params.invoiced = "0";
    else if (view === "invoiced") params.invoiced = "1";
    const r = await api("list_jobs", "GET", null, params);
    if (!r.ok) {
      list.innerHTML = `<div class="error">${esc(r.error || "Could not load invoice queue.")}</div>`;
      return;
    }
    const rows = Array.isArray(r.jobs) ? r.jobs : [];
    currentRows = rows;
    renderSummary(rows);
    if (!rows.length) {
      list.innerHTML = `<div class="meta">No completed jobs found for this view.</div>`;
      return;
    }
    list.innerHTML = `
      <div class="jobs-cards">
        ${rows.map((j) => `
          <article class="job-card">
            <div class="job-card-head">
              <input class="inv-select" type="checkbox" value="${Number(j.id) || 0}">
              <div class="job-title">${esc(assetDisplay(j.asset_id || ""))}</div>
              <div class="job-pin">${mapLink(j.lat, j.lon)}</div>
            </div>
            <div class="job-topline">
              <span><b>WO:</b> ${esc(j.work_order || "")}</span>
              <span><b>PO:</b> ${esc(j.purchase_order || "")}</span>
              <span><b>Status:</b> ${esc(j.status || "")}</span>
            </div>
            <div class="job-meta-grid">
              <div><span class="k">Module</span><span class="v">${esc(j.module || "")}</span></div>
              <div><span class="k">Asset Type</span><span class="v">${esc(j.asset_type || "")}</span></div>
              <div><span class="k">Completed</span><span class="v">${esc(j.completed_at || "")}</span></div>
              <div><span class="k">Invoiced</span><span class="v">${esc(j.invoiced_at || "")}</span></div>
              <div><span class="k">Updated</span><span class="v">${esc(j.updated_at || "")}</span></div>
            </div>
          </article>
        `).join("")}
      </div>
    `;
  }

  async function markInvoiced(value) {
    const ids = selectedIds();
    if (!ids.length) {
      msg.className = "error";
      msg.textContent = "Select one or more jobs first.";
      return;
    }
    const payload = value ? { ids, mark_invoiced: 1 } : { ids, clear_invoiced: 1 };
    const r = await api("update_jobs_flags", "POST", payload);
    if (!r.ok) {
      msg.className = "error";
      msg.textContent = r.error || "Update failed.";
      return;
    }
    msg.className = "success";
    msg.textContent = value ? `Marked invoiced (${r.updated || 0} jobs)` : `Invoiced flag removed (${r.updated || 0} jobs)`;
    await loadQueue();
  }

  function dolibarrPayload() {
    return {
      base_url: String(baseUrlInput?.value || "").trim(),
      api_key: String(apiKeyInput?.value || "").trim(),
      socid: String(socidInput?.value || "").trim(),
      line_rate: Number(lineRateInput?.value || 0),
      tva_tx: Number(tvaTxInput?.value || 0),
    };
  }

  function setSocidOptions(customers) {
    if (!socidInput) return;
    const rows = Array.isArray(customers) ? customers : [];
    const opts = ['<option value="">Select customer...</option>']
      .concat(rows.map((c) => `<option value="${Number(c.id) || 0}">${esc(c.label || ("Customer " + c.id))}</option>`));
    socidInput.innerHTML = opts.join("");
    if (defaultSocid && rows.some((c) => String(c.id) === defaultSocid)) {
      socidInput.value = defaultSocid;
    } else if (rows.length === 1) {
      socidInput.value = String(rows[0].id);
    }
  }

  async function loadCustomers() {
    const payload = dolibarrPayload();
    const r = await api("invoice_list_customers", "POST", payload);
    if (!r.ok) {
      return r;
    }
    setSocidOptions(r.customers || []);
    return r;
  }

  async function testConnection() {
    const payload = dolibarrPayload();
    if (!payload.base_url || !payload.api_key) {
      msg.className = "error";
      msg.textContent = "Enter Dolibarr base URL and API key first.";
      return;
    }
    msg.className = "";
    msg.textContent = "Testing connection...";
    const r = await api("invoice_test_connection", "POST", payload);
    if (!r.ok) {
      msg.className = "error";
      msg.textContent = `${r.error || "Connection failed"}${r.detail ? `: ${r.detail}` : ""}`;
      return;
    }
    const customers = await loadCustomers();
    if (!customers.ok) {
      msg.className = "error";
      msg.textContent = `Connection OK but customer list failed: ${customers.error || ""}${customers.detail ? `: ${customers.detail}` : ""}`;
      return;
    }
    msg.className = "success";
    msg.textContent = `Dolibarr connection OK. Loaded ${Array.isArray(customers.customers) ? customers.customers.length : 0} customer(s).`;
  }

  async function createDrafts() {
    const ids = selectedIds();
    if (!ids.length) {
      msg.className = "error";
      msg.textContent = "Select one or more jobs first.";
      return;
    }
    const payload = { ids, ...dolibarrPayload() };
    if (!payload.base_url || !payload.api_key || !payload.socid) {
      msg.className = "error";
      msg.textContent = "Enter Dolibarr base URL, API key, and SOCID first.";
      return;
    }
    msg.className = "";
    msg.textContent = "Creating draft invoices in Dolibarr...";
    const r = await api("invoice_create_drafts", "POST", payload);
    if (!r.ok) {
      msg.className = "error";
      msg.textContent = `${r.error || "Draft invoice creation failed"}${r.detail ? `: ${r.detail}` : ""}`;
      return;
    }
    const created = Array.isArray(r.created) ? r.created : [];
    const info = created.map((x) => `#${x.invoice_id}${x.purchase_order ? " (PO " + x.purchase_order + ")" : ""}`).join(", ");
    msg.className = "success";
    msg.textContent = `Created ${created.length} draft invoice(s), marked ${r.updated_jobs || 0} jobs invoiced.${info ? " " + info : ""}`;
    await loadQueue();
  }

  function exportCsv() {
    if (!currentRows.length) {
      msg.className = "error";
      msg.textContent = "No rows to export.";
      return;
    }
    const csv = toCsv(currentRows);
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const a = document.createElement("a");
    const stamp = new Date().toISOString().replace(/[:.]/g, "-");
    a.href = URL.createObjectURL(blob);
    a.download = `invoice_queue_${stamp}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(a.href);
    msg.className = "success";
    msg.textContent = "CSV exported.";
  }

  refreshBtn.addEventListener("click", loadQueue);
  [moduleInput, assetTypeInput, viewInput].forEach((el) => el.addEventListener("change", loadQueue));
  searchInput.addEventListener("keydown", (e) => { if (e.key === "Enter") loadQueue(); });
  selectAllBtn.addEventListener("click", () => document.querySelectorAll(".inv-select").forEach((el) => { el.checked = true; }));
  clearBtn.addEventListener("click", () => document.querySelectorAll(".inv-select").forEach((el) => { el.checked = false; }));
  markBtn.addEventListener("click", () => markInvoiced(true));
  undoBtn.addEventListener("click", () => markInvoiced(false));
  exportBtn.addEventListener("click", exportCsv);
  if (testConnBtn) testConnBtn.addEventListener("click", testConnection);
  if (createDraftsBtn) createDraftsBtn.addEventListener("click", createDrafts);
  loadQueue();
})();
