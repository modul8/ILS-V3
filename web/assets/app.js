(function () {
  const cfg = window.ILS_V3 || null;
  if (!cfg || !cfg.assetType) return;

  const assetType = cfg.assetType;
  const currentRole = (cfg.role || "user").toLowerCase();
  const isAdmin = currentRole === "admin";
  const searchInput = document.getElementById("assetSearchInput");
  const searchBtn = document.getElementById("assetSearchBtn");
  const refreshListBtn = document.getElementById("refreshListBtn");
  const results = document.getElementById("results");
  const assetList = document.getElementById("assetList");

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
    const opts = { method: method || "GET", headers: {} };
    if (body) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    return res.json();
  }

  function mapLink(lat, lon) {
    if (lat === null || lat === "" || lon === null || lon === "") return "";
    const q = encodeURIComponent(`${lat},${lon}`);
    return `<a class="link" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${q}">Open map pin</a>`;
  }

  function trackLink(type, id, hasTrack, directUrl) {
    if (type !== "drain" || !hasTrack) return "";
    const href = directUrl || `api/index.php?action=download_asset_track&asset_type=drain&asset_id=${encodeURIComponent(id)}`;
    return `<a class="link" target="_blank" rel="noopener" href="${esc(href)}">Open drain track</a>`;
  }

  function contactRowHtml(c, i, readonly) {
    const dis = readonly ? "disabled" : "";
    const removeBtn = readonly
      ? ""
      : `<button class="btn btn-danger" data-remove-contact="${i}" type="button">Remove</button>`;
    return `
      <div class="contact-row" data-contact-row="${i}">
        <input data-field="name" placeholder="Name" value="${esc(c.name)}" ${dis}>
        <input data-field="phone" placeholder="Phone" value="${esc(c.phone)}" ${dis}>
        <input data-field="email" placeholder="Email (optional)" value="${esc(c.email)}" ${dis}>
        ${removeBtn}
      </div>
    `;
  }

  function notesHtml(notes) {
    const rows = Array.isArray(notes) ? notes : [];
    if (!rows.length) return `<div class="meta">No notes yet.</div>`;
    return rows.map((n) => `
      <div class="photo-item">
        <div>
          <div>${esc(n.note_text)}</div>
          <div class="meta">By ${esc(n.username)} at ${esc(n.created_at)}</div>
        </div>
      </div>
    `).join("");
  }

  function photosHtml(photos) {
    const rows = Array.isArray(photos) ? photos : [];
    if (!rows.length) return `<div class="meta">No photos uploaded.</div>`;
    return rows.map((p) => `
      <div class="photo-item">
        <a class="link" href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.filename)}</a>
        <span class="meta">${esc(p.created_at || "")}</span>
      </div>
    `).join("");
  }

  function renderNewCard(id) {
    const adminFields = isAdmin ? `
      <div class="grid">
        <label>Work Order<input id="workOrder" value=""></label>
        <label>Purchase Order<input id="purchaseOrder" value=""></label>
        <label>Latitude<input id="lat" value=""></label>
        <label>Longitude<input id="lon" value=""></label>
      </div>
    ` : "";
    results.innerHTML = `
      <article class="card">
        <h2>Create ${esc(assetType)} ${esc(id)}</h2>
        <div class="meta">No existing asset found. You can create this new asset.</div>
        <div class="grid">
          <label>Asset Type<input id="assetType" value="${esc(assetType)}" readonly></label>
          <label>Asset ID<input id="assetId" value="${esc(id)}" readonly></label>
        </div>
        ${adminFields}
        <div class="line">
          <button class="btn" id="createAssetBtn" type="button">Create Asset</button>
          ${isAdmin ? '<button class="btn btn-secondary" id="gpsBtn" type="button">Use Phone GPS</button><span id="mapLinkWrap"></span>' : ""}
        </div>
        <div id="createMsg"></div>
      </article>
    `;
    const createBtn = document.getElementById("createAssetBtn");
    createBtn.onclick = createAsset;
    if (isAdmin) {
      const gpsBtn = document.getElementById("gpsBtn");
      gpsBtn.onclick = useGps;
    }
  }

  function renderAssetCard(asset) {
    const readonly = !isAdmin;
    const dis = readonly ? "disabled" : "";
    const contacts = Array.isArray(asset.contacts) ? asset.contacts : [];
    results.innerHTML = `
      <article class="card">
        <div class="line">
          <h2>${esc(asset.asset_type)} ${esc(asset.asset_id)}</h2>
          <span class="meta">Updated: ${esc(asset.updated_at || "-")}</span>
        </div>
        <div class="grid">
          <label>Asset Type<input id="assetType" value="${esc(asset.asset_type)}" readonly></label>
          <label>Asset ID<input id="assetId" value="${esc(asset.asset_id)}" readonly></label>
          <label>Work Order<input id="workOrder" value="${esc(asset.work_order)}" ${dis}></label>
          <label>Purchase Order<input id="purchaseOrder" value="${esc(asset.purchase_order)}" ${dis}></label>
          <label>Latitude<input id="lat" value="${esc(asset.lat)}" ${dis}></label>
          <label>Longitude<input id="lon" value="${esc(asset.lon)}" ${dis}></label>
        </div>
        <div class="line">
          ${isAdmin ? '<button class="btn btn-secondary" id="gpsBtn" type="button">Use Phone GPS</button>' : ""}
          <span id="mapLinkWrap">${mapLink(asset.lat, asset.lon)}</span>
          <span id="trackLinkWrap">${trackLink(asset.asset_type, asset.asset_id, asset.has_track, asset.track_url)}</span>
        </div>

        <h3>Contacts ${isAdmin ? "" : "(read-only)"}</h3>
        <div id="contacts" class="contacts">
          ${contacts.length ? contacts.map((c, i) => contactRowHtml(c, i, readonly)).join("") : '<div class="meta">No contacts.</div>'}
        </div>
        ${isAdmin ? '<div class="line"><button class="btn btn-secondary" id="addContactBtn" type="button">Add Contact</button><button class="btn" id="saveAdminBtn" type="button">Save Admin Changes</button></div>' : ''}
        <div id="saveMsg"></div>

        <h3>Notes</h3>
        <div class="line">
          <textarea id="newNoteText" placeholder="Add a note..."></textarea>
        </div>
        <div class="line">
          <button class="btn" id="addNoteBtn" type="button">Add Note</button>
        </div>
        <div id="noteMsg"></div>
        <div id="notesList" class="photo-list">${notesHtml(asset.notes)}</div>

        <h3>Photos</h3>
        <div class="line">
          <input id="photoInput" type="file" accept="image/*" capture="environment">
          <button class="btn btn-secondary" id="uploadPhotoBtn" type="button">Upload Photo</button>
        </div>
        <div id="photoMsg"></div>
        <div id="photoList" class="photo-list">${photosHtml(asset.photos)}</div>
      </article>
    `;
    wireAssetCardHandlers();
  }

  function gatherContacts() {
    const rows = Array.from(document.querySelectorAll("[data-contact-row]"));
    return rows
      .map((row) => ({
        name: row.querySelector('[data-field="name"]').value.trim(),
        phone: row.querySelector('[data-field="phone"]').value.trim(),
        email: row.querySelector('[data-field="email"]').value.trim(),
      }))
      .filter((c) => c.name || c.phone || c.email);
  }

  function wireContactRemoveButtons() {
    document.querySelectorAll("[data-remove-contact]").forEach((btn) => {
      btn.onclick = function () {
        const row = btn.closest("[data-contact-row]");
        if (row) row.remove();
      };
    });
  }

  function refreshMapLink() {
    const lat = document.getElementById("lat")?.value?.trim() || "";
    const lon = document.getElementById("lon")?.value?.trim() || "";
    const wrap = document.getElementById("mapLinkWrap");
    if (wrap) wrap.innerHTML = mapLink(lat, lon);
  }

  function useGps() {
    if (!navigator.geolocation) {
      alert("Geolocation is not available on this device.");
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = document.getElementById("lat");
        const lon = document.getElementById("lon");
        if (lat) lat.value = pos.coords.latitude.toFixed(6);
        if (lon) lon.value = pos.coords.longitude.toFixed(6);
        refreshMapLink();
      },
      () => alert("Could not get location. Check browser permissions.")
    );
  }

  async function createAsset() {
    const payload = {
      asset_type: document.getElementById("assetType").value.trim(),
      asset_id: document.getElementById("assetId").value.trim(),
    };
    if (isAdmin) {
      payload.work_order = document.getElementById("workOrder").value.trim();
      payload.purchase_order = document.getElementById("purchaseOrder").value.trim();
      payload.lat = document.getElementById("lat").value.trim();
      payload.lon = document.getElementById("lon").value.trim();
    }
    const msg = document.getElementById("createMsg");
    msg.className = "";
    msg.textContent = "Creating...";
    const r = await api("create_asset", "POST", payload);
    if (!r.ok) {
      msg.className = "error";
      msg.textContent = r.error || "Create failed.";
      return;
    }
    msg.className = "success";
    msg.textContent = "Asset created.";
    await loadBySearch();
    await loadList();
  }

  async function saveAdminChanges() {
    if (!isAdmin) return;
    const payload = {
      asset_type: document.getElementById("assetType").value.trim(),
      asset_id: document.getElementById("assetId").value.trim(),
      work_order: document.getElementById("workOrder").value.trim(),
      purchase_order: document.getElementById("purchaseOrder").value.trim(),
      lat: document.getElementById("lat").value.trim(),
      lon: document.getElementById("lon").value.trim(),
      contacts: gatherContacts(),
    };
    const msg = document.getElementById("saveMsg");
    msg.className = "";
    msg.textContent = "Saving...";
    const r = await api("update_asset_admin", "POST", payload);
    if (!r.ok) {
      msg.className = "error";
      msg.textContent = r.error || "Save failed.";
      return;
    }
    msg.className = "success";
    msg.textContent = "Saved.";
    await loadBySearch();
    await loadList();
  }

  async function addNote() {
    const asset_id = document.getElementById("assetId").value.trim();
    const text = document.getElementById("newNoteText").value.trim();
    const msg = document.getElementById("noteMsg");
    msg.className = "";
    if (!text) {
      msg.className = "error";
      msg.textContent = "Enter note text.";
      return;
    }
    msg.textContent = "Adding note...";
    const r = await api("add_note", "POST", {
      asset_type: assetType,
      asset_id,
      note_text: text,
    });
    if (!r.ok) {
      msg.className = "error";
      msg.textContent = r.error || "Could not add note.";
      return;
    }
    msg.className = "success";
    msg.textContent = "Note added.";
    await loadBySearch();
  }

  async function uploadPhoto() {
    const input = document.getElementById("photoInput");
    const msg = document.getElementById("photoMsg");
    if (!input.files || !input.files.length) {
      msg.className = "error";
      msg.textContent = "Choose a photo first.";
      return;
    }
    const form = new FormData();
    form.append("asset_type", document.getElementById("assetType").value.trim());
    form.append("asset_id", document.getElementById("assetId").value.trim());
    form.append("photo", input.files[0]);
    const lat = document.getElementById("lat");
    const lon = document.getElementById("lon");
    form.append("lat", lat ? lat.value.trim() : "");
    form.append("lon", lon ? lon.value.trim() : "");

    const url = new URL("api/index.php", window.location.href);
    url.searchParams.set("action", "upload_photo");
    const res = await fetch(url, { method: "POST", body: form });
    const j = await res.json();
    if (!j.ok) {
      msg.className = "error";
      msg.textContent = j.error || "Upload failed.";
      return;
    }
    msg.className = "success";
    msg.textContent = "Photo uploaded.";
    await loadBySearch();
  }

  function wireAssetCardHandlers() {
    if (isAdmin) {
      const saveBtn = document.getElementById("saveAdminBtn");
      const addContactBtn = document.getElementById("addContactBtn");
      const gpsBtn = document.getElementById("gpsBtn");
      if (saveBtn) saveBtn.onclick = saveAdminChanges;
      if (addContactBtn) {
        addContactBtn.onclick = function () {
          const container = document.getElementById("contacts");
          const i = container.querySelectorAll("[data-contact-row]").length;
          container.insertAdjacentHTML("beforeend", contactRowHtml({ name: "", phone: "", email: "" }, i, false));
          wireContactRemoveButtons();
        };
      }
      if (gpsBtn) gpsBtn.onclick = useGps;
      const lat = document.getElementById("lat");
      const lon = document.getElementById("lon");
      if (lat) lat.addEventListener("input", refreshMapLink);
      if (lon) lon.addEventListener("input", refreshMapLink);
      wireContactRemoveButtons();
    }
    const addNoteBtn = document.getElementById("addNoteBtn");
    const uploadBtn = document.getElementById("uploadPhotoBtn");
    if (addNoteBtn) addNoteBtn.onclick = addNote;
    if (uploadBtn) uploadBtn.onclick = uploadPhoto;
  }

  async function loadBySearch() {
    const id = (searchInput.value || "").trim();
    if (!id) return;
    const r = await api("get_asset", "GET", null, { asset_type: assetType, asset_id: id });
    if (!r.ok) {
      renderNewCard(id);
      return;
    }
    renderAssetCard(r.asset);
  }

  async function loadList() {
    const q = (searchInput.value || "").trim();
    const r = await api("list_assets", "GET", null, { asset_type: assetType, q });
    if (!r.ok) {
      assetList.innerHTML = `<div class="error">Could not load list.</div>`;
      return;
    }
    const rows = Array.isArray(r.assets) ? r.assets : [];
    if (!rows.length) {
      assetList.innerHTML = `<div class="meta">No assets found.</div>`;
      return;
    }
    const showTrack = assetType === "drain";
    assetList.innerHTML = `
      <table>
        <thead>
          <tr><th>Asset ID</th><th>WO</th><th>PO</th><th>Pin</th>${showTrack ? "<th>Drain</th>" : ""}<th>Updated</th></tr>
        </thead>
        <tbody>
          ${rows.map((a) => `
            <tr>
              <td><a href="#" class="link" data-open-asset="${esc(a.asset_id)}">${esc(a.asset_id)}</a></td>
              <td>${esc(a.work_order || "")}</td>
              <td>${esc(a.purchase_order || "")}</td>
              <td>${mapLink(a.lat, a.lon)}</td>
              ${showTrack ? `<td>${trackLink(a.asset_type, a.asset_id, Number(a.has_track || 0) === 1, "")}</td>` : ""}
              <td>${esc(a.updated_at || "")}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    `;
    assetList.querySelectorAll("[data-open-asset]").forEach((el) => {
      el.onclick = function (e) {
        e.preventDefault();
        searchInput.value = el.getAttribute("data-open-asset") || "";
        loadBySearch();
      };
    });
  }

  searchBtn.addEventListener("click", async () => {
    await loadBySearch();
    await loadList();
  });
  searchInput.addEventListener("keydown", async (e) => {
    if (e.key === "Enter") {
      await loadBySearch();
      await loadList();
    }
  });
  if (refreshListBtn) refreshListBtn.addEventListener("click", loadList);
  loadList();
})();
