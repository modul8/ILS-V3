(function () {
  const cfg = window.ILS_V3_INVOICE || {};
  if ((cfg.role || "").toLowerCase() !== "admin") return;

  const moduleInput = document.getElementById("invModule");
  const assetTypeInput = document.getElementById("invAssetType");
  const viewInput = document.getElementById("invView");
  const searchInput = document.getElementById("invSearch");
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
  loadQueue();
})();

