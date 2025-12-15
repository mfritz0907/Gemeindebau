/* gemeindebauscript.js
   - Loads Google Map + Street View
   - Triggers backend actions:
       • mapMarkers (existing)
       • recordById
       • fetchStreetView (enriched via recordById to place markers)
*/

let map, pano, markers = [];
let initialRandomShown = false;
let hasActiveSelection = false;
let panoExpanded = false;
let lastRows = [];

// Slideshow state
let slideshowPano = null;
let slideshowTimer = null;
let slideshowRows = [];
let slideshowIndex = 0;
let slideshowIntervalMs = 10000;
let slideshowRunning = false;

function showMapError(message) {
    const status = document.getElementById("status");
    if (status) {
        status.textContent = message;
        status.classList.add("status-error");
    }

    const mapEl = document.getElementById("map");
    if (mapEl) {
        mapEl.classList.add("map-error");
        mapEl.innerHTML = `<div class="map-error-overlay" role="alert">${message}</div>`;
    }

    console.error(message);
}

// Google Maps auth failure callback
window.gm_authFailure = function () {
    showMapError(
        "Google Maps API-Schlüssel ist ungültig oder nicht korrekt freigeschaltet. Bitte Schlüssel in dbconnect/config_local.php prüfen."
    );
};

// Called when no key is provided at all (set by PHP template)
window.notifyMissingMapsKey = function () {
    showMapError("Google Maps API-Schlüssel fehlt. Bitte GOOGLE_MAPS_API_KEY in dbconnect/config_local.php setzen.");
};

/* -------------------- Map bootstrap -------------------- */

// Google Maps callback must be global
window.initMap = function () {
    const center = { lat: 48.2082, lng: 16.3738 };

    map = new google.maps.Map(document.getElementById("map"), {
        center,
        zoom: 12,
        mapTypeControl: false,
    });

    pano = new google.maps.StreetViewPanorama(document.getElementById("pano"), {
        position: center,
        visible: false,
    });
    map.setStreetView(pano);

    setupPanoExpansion();
    setupSlideshow();

    wireForm();

    // initial load
    const status = document.getElementById("status");
    if (status) status.textContent = "Lade Daten …";
    loadMarkers()
        .then((rows) => {
            if (status) status.textContent = rows && rows.length ? "Fertig" : "0 Treffer";
            if (!initialRandomShown && rows && rows.length) {
                showRandomMarker();
                initialRandomShown = true;
            }
        })
        .catch((err) => handleLoadError(status, "Fehler beim Laden der Daten", err));
};

/* -------------------- UI wiring -------------------- */

function wireForm() {
    const form = document.getElementById("search-form");
    const status = document.getElementById("status");
    const kunstBtn = document.getElementById("kunst-btn");
    const artVisibleBtn = document.getElementById("art-visible-btn");
    const resetBtn = document.getElementById("reset-btn");

    // NEW: recordById trigger (expects a small form with input#record-id-input)
    const recordIdForm = document.getElementById("record-id-form");
    const recordIdInput = document.getElementById("record-id-input");

    // NEW: fetchStreetView trigger (expects a button#streetview-btn)
    const streetViewBtn = document.getElementById("streetview-btn");

    if (form) {
        form.addEventListener("submit", (e) => {
            e.preventDefault();
            const q = (document.getElementById("q")?.value || "").trim();
            const zipcode = (document.getElementById("zipcode")?.value || "").trim();

            // Build decades string like "1910-1919,1950-1959"
            const decades = Array.from(form.querySelectorAll('input[name="decade[]"]:checked'))
                .map((cb) => `${cb.value}-${Number(cb.value) + 9}`)
                .join(",");

            if (status) status.textContent = "Lade Daten …";
            loadMarkers({ q, zipcode, decades })
                .then((rows) => (status ? (status.textContent = rows.length ? "Fertig" : "0 Treffer") : null))
                .catch((err) => handleLoadError(status, "Fehler beim Laden der Daten", err));
        });
    }

    if (kunstBtn) {
        kunstBtn.addEventListener("click", () => {
            form?.reset();
            if (status) status.textContent = "Lade Gemeindebauten mit Kunst …";
            // keep existing API param for back-compat
            loadMarkers({ with_art: 1 })
                .then((rows) => (status ? (status.textContent = rows.length ? "Fertig" : "0 Treffer") : null))
                .catch((err) => handleLoadError(status, "Fehler beim Laden der Daten", err));
        });
    }

    if (artVisibleBtn) {
        artVisibleBtn.addEventListener("click", () => {
            form?.reset();
            if (status) status.textContent = "Lade Bauten mit sichtbarer Kunst …";
            loadMarkers({ artVisible: 1 })
                .then((rows) => (status ? (status.textContent = rows.length ? "Fertig" : "0 Treffer") : null))
                .catch((err) => handleLoadError(status, "Fehler beim Laden der Daten", err));
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener("click", () => {
            form?.reset();
            if (status) status.textContent = "Filter zurückgesetzt. Lade alle Daten …";
            loadMarkers()
                .then((rows) => (status ? (status.textContent = rows.length ? "Fertig" : "0 Treffer") : null))
                .catch((err) => handleLoadError(status, "Fehler beim Laden der Daten", err));
        });
    }

    /* --------- NEW: recordById UI hook --------- */
    if (recordIdForm && recordIdInput) {
        recordIdForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            const id = recordIdInput.value.trim();
            if (!id) return;

            if (status) status.textContent = `Lade Datensatz #${id} …`;
            try {
                const row = await loadRecordById(id); // triggers action=recordById
                if (!row || !row.lat || !row.lng) {
                    throw new Error("Kein Datensatz/Koordinaten gefunden.");
                }
                // Place exactly this one marker and focus it
                placeMarkers([row]);
                if (status) status.textContent = "Fertig";
            } catch (err) {
                handleLoadError(status, `Fehler beim Laden von #${id}`, err);
            }
        });
    }

    /* --------- NEW: fetchStreetView UI hook --------- */
    if (streetViewBtn) {
        streetViewBtn.addEventListener("click", async () => {
            if (status) status.textContent = "Lade Objekte mit Street View …";
            try {
                const rows = await loadStreetViewMarkers(); // triggers action=fetchStreetView (and enriches with recordById)
                if (status) status.textContent = rows.length ? "Fertig" : "0 Treffer";
            } catch (err) {
                handleLoadError(status, "Fehler beim Laden der Street-View-Daten", err);
            }
        });
    }
}

/* -------------------- Backend calls -------------------- */

async function fetchJSON(url) {
    const res = await fetch(url, { headers: { Accept: "application/json" } });
    // Read as text first so we can surface backend error messages
    const body = await res.text();
    if (!res.ok) {
        let detail = body || "no body";
        try {
            const parsed = JSON.parse(body);
            if (parsed && typeof parsed === "object") {
                if (parsed.detail) {
                    detail = parsed.detail;
                } else if (parsed.error) {
                    detail = parsed.error;
                }
            }
        } catch (e) {
            // fall back to raw body
        }

        throw new Error(`Backend ${res.status}: ${detail}`);
    }
    if (!body) throw new Error("Empty response body");
    try {
        return JSON.parse(body);
    } catch (e) {
        throw new Error("Ungültiges JSON vom Server: " + body.slice(0, 300));
    }
}

function normalizeMarkerQuery(query = {}) {
    const artVisibleVal = query.artVisible ?? query.art_visible;
    if (artVisibleVal === undefined) {
        return query;
    }

    // Send both naming variants so the backend can read either one.
    const normalized = artVisibleVal === true ? 1 : artVisibleVal === false ? 0 : artVisibleVal;
    return { ...query, artVisible: normalized, art_visible: normalized };
}

/** Existing: triggers action=mapMarkers with optional filters */
async function loadMarkers(query = {}) {
    const normalizedQuery = normalizeMarkerQuery(query);
    const params = new URLSearchParams({ action: "mapMarkers", ...normalizedQuery, nocache: Date.now() });
    const url = `get_data.php?${params.toString()}`;
    const data = await fetchJSON(url);
    if (!Array.isArray(data)) {
        if (data && typeof data === "object" && data.error) {
            throw new Error("API-Fehler: " + (data.detail || data.error));
        }
        throw new Error("Unerwartetes Antwortformat");
    }
    placeMarkers(data);
    return data;
}

function formatStatusError(prefix, err) {
    const suffix = err?.message ? `: ${err.message}` : "";
    return `${prefix}${suffix}`;
}

function handleLoadError(statusEl, prefix, err, { showOverlay = true } = {}) {
    console.error(err);
    const message = formatStatusError(prefix, err);
    if (statusEl) statusEl.textContent = message;
    if (showOverlay) showMapError(message);
}

/** NEW: triggers action=recordById&id=... and returns a single row (or null) */
async function loadRecordById(id) {
    const params = new URLSearchParams({ action: "recordById", id: String(id), nocache: Date.now() });
    const url = `get_data.php?${params.toString()}`;
    const data = await fetchJSON(url);
    // API returns {} if not found; normalize to null
    if (!data || typeof data !== "object" || Array.isArray(data) || Object.keys(data).length === 0) return null;
    return data;
}

/** NEW: triggers action=fetchStreetView, then enriches via recordById to get lat/lng so we can place markers */
async function loadStreetViewMarkers() {
    const params = new URLSearchParams({ action: "fetchStreetView", nocache: Date.now() });
    const url = `get_data.php?${params.toString()}`;
    const list = await fetchJSON(url);
    if (!Array.isArray(list)) {
        if (list && typeof list === "object" && list.error) {
            throw new Error("API-Fehler: " + (list.detail || list.error));
        }
        throw new Error("Unerwartetes Antwortformat (fetchStreetView)");
    }

    // Parallel fetch details for each id (recordById) to obtain coordinates.
    // Limit concurrency to avoid hammering the server.
    const ids = list.map(r => r.id).filter(Boolean);
    const detailedRows = await mapWithConcurrency(ids, 8, async (id) => {
        const row = await loadRecordById(id);
        return row && row.lat && row.lng ? row : null;
    });

    const rows = detailedRows.filter(Boolean);
    placeMarkers(rows);
    return rows;
}

/* -------------------- Small concurrency helper -------------------- */
async function mapWithConcurrency(items, limit, worker) {
    const results = new Array(items.length);
    let i = 0;
    const runners = new Array(Math.min(limit, items.length)).fill(0).map(async function run() {
        while (i < items.length) {
            const idx = i++;
            try {
                results[idx] = await worker(items[idx], idx);
            } catch (e) {
                console.error("Worker error:", e);
                results[idx] = null;
            }
        }
    });
    await Promise.all(runners);
    return results;
}

/* -------------------- Helpers -------------------- */

// Clamp helper
function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
}

function getRowPosition(row) {
    const parsed = parseStreetViewLink(row?.streetviewlink);
    const pos = parsed?.position;
    if (pos && Number.isFinite(pos.lat) && Number.isFinite(pos.lng)) {
        return { lat: pos.lat, lng: pos.lng };
    }

    const lat = Number(row?.lat);
    const lng = Number(row?.lng);
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
        return { lat, lng };
    }

    return null;
}

function isEligibleForRandomView(row) {
    if (!row) return false;

    const hasStreetView = Boolean(String(row.streetviewlink || "").trim());
    const hasArt = Boolean(String(row.art || "").trim());
    const heading = Number(row.heading);
    const hasHeading = Number.isFinite(heading);

    return hasStreetView && hasArt && hasHeading;
}

/**
 * Convert FOV degrees (what your DB stores via update_view.php) to Street View zoom level.
 * Approx: zoomLevel = log2(180 / fovDeg)
 * Typical Street View zoom levels ~ 0..5 (fractional allowed).
 */
function fovToZoomLevel(fovDeg) {
    const f = Number(fovDeg);
    if (!Number.isFinite(f) || f <= 0) return 0;
    return clamp(Math.log2(180 / f), 0, 5);
}

/**
 * Adjust a desired zoom level so it *looks like* it was taken on a full-window pano.
 * The smaller the pane vs. the full window, the more we reduce zoom.
 * Uses height ratio: correction ≈ log2(H / h)
 */
function zoomForFullWindow(desiredZoomLevel, elementId = "pano") {
    const panoEl = document.getElementById(elementId);
    const h = Math.max(1, panoEl?.clientHeight || 1);
    const H = Math.max(1, window.innerHeight || h);

    const correction = Math.log2(H / h);
    const adjusted = Number.isFinite(desiredZoomLevel) ? desiredZoomLevel - correction : 0;

    return clamp(adjusted, 0, 5);
}

function applyPanoView(targetPano, row, fallbackPosition, elementId = "pano") {
    if (!targetPano || !row) return;

    const panoState = derivePanoState(row);
    const { position, heading, pitch, baseZoomLevel } = panoState;
    const pos = position || fallbackPosition;
    if (!pos) return;

    const applyZoom = () => targetPano.setZoom(zoomForFullWindow(baseZoomLevel, elementId));

    targetPano.setVisible(true);
    targetPano.setPosition(pos);
    targetPano.setPov({ heading, pitch });

    google.maps.event.trigger(targetPano, "resize");
    applyZoom();
    google.maps.event.addListenerOnce(targetPano, "pano_changed", () => {
        google.maps.event.trigger(targetPano, "resize");
        setTimeout(applyZoom, 0);
    });
}

function capturePanoView() {
    if (!pano) return null;

    const position = pano.getPosition();
    const pov = pano.getPov();
    const zoom = pano.getZoom();

    return {
        position: position ? { lat: position.lat(), lng: position.lng() } : null,
        heading: pov?.heading,
        pitch: pov?.pitch,
        zoom: Number.isFinite(zoom) ? zoom : null,
    };
}

function restorePanoView(state) {
    if (!state || !pano) return;

    if (state.position) pano.setPosition(state.position);
    if (state.heading !== undefined && state.pitch !== undefined) {
        pano.setPov({ heading: state.heading, pitch: state.pitch });
    }
    if (Number.isFinite(state.zoom)) pano.setZoom(state.zoom);
}

function parseStreetViewLink(link) {
    if (!link || typeof link !== "string") return null;

    try {
        const url = new URL(link.trim(), window.location.href);
        const params = url.searchParams;

        const result = {};

        const viewpoint = params.get("viewpoint") || params.get("cbll");
        if (viewpoint && viewpoint.includes(",")) {
            const [latStr, lngStr] = viewpoint.split(",").map((v) => v.trim());
            const lat = Number(latStr);
            const lng = Number(lngStr);
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                result.position = { lat, lng };
            }
        }

        const pathCoordsMatch = url.pathname.match(/@([-\d.]+),([-\d.]+)/);
        if (!result.position && pathCoordsMatch) {
            const lat = Number(pathCoordsMatch[1]);
            const lng = Number(pathCoordsMatch[2]);
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                result.position = { lat, lng };
            }
        }

        const headingParams = ["heading", "yaw", "y"]; // prefer explicit heading params
        for (const key of headingParams) {
            const v = params.get(key);
            if (v !== null) {
                const num = Number(v);
                if (Number.isFinite(num)) {
                    result.heading = num;
                    break;
                }
            }
        }

        const pitchParams = ["pitch", "p"]; // Google shares pitch explicitly
        for (const key of pitchParams) {
            const v = params.get(key);
            if (v !== null) {
                const num = Number(v);
                if (Number.isFinite(num)) {
                    result.pitch = num;
                    break;
                }
            }
        }

        const fov = params.get("fov") || params.get("f");
        if (fov !== null) {
            const num = Number(fov);
            if (Number.isFinite(num)) {
                result.fov = num;
            }
        }

        // Path style e.g. /@48.2,16.3,3a,75y,20h,90t -> grab heading/pitch from "y" and "h" segments
        const pathViewMatch = url.pathname.match(/@[^/]*?,([\d.]+)y,([-\d.]+)h/);
        if (!result.heading && pathViewMatch) {
            const num = Number(pathViewMatch[1]);
            if (Number.isFinite(num)) result.heading = num;
        }
        if (!result.pitch && pathViewMatch) {
            const num = Number(pathViewMatch[2]);
            if (Number.isFinite(num)) result.pitch = num;
        }

        return Object.keys(result).length ? result : null;
    } catch (e) {
        return null;
    }
}

function derivePanoState(row) {
    const parsed = parseStreetViewLink(row?.streetviewlink);
    const lat = Number(row?.lat);
    const lng = Number(row?.lng);
    const fallbackPosition = Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
    const position = parsed?.position || fallbackPosition;

    const heading = Number.isFinite(parsed?.heading) ? parsed.heading : Number(row?.heading) || 0;
    const pitch = Number.isFinite(parsed?.pitch) ? parsed.pitch : Number(row?.pitch) || 0;

    let baseZoomLevel;
    if (Number.isFinite(parsed?.fov)) {
        baseZoomLevel = fovToZoomLevel(parsed.fov);
    } else {
        // Your DB 'zoom' column is FOV degrees (10..100) saved by update_view.php.
        // Convert FOV → SV zoom level, then adjust for pane size to match full-window perception.
        const rawZoom = Number(row?.zoom);
        baseZoomLevel = rawZoom > 5 ? fovToZoomLevel(rawZoom) : clamp(rawZoom, 0, 5);
    }

    return { position, heading, pitch, baseZoomLevel };
}

function setupPanoExpansion() {
    const panoEl = document.getElementById("pano");
    if (!panoEl) return;

    panoEl.addEventListener("click", () => {
        if (!hasActiveSelection) return;

        const preservedView = capturePanoView();
        panoExpanded = !panoExpanded;
        panoEl.classList.toggle("expanded", panoExpanded);
        panoEl.classList.toggle("expandable", hasActiveSelection);
        google.maps.event.trigger(pano, "resize");

        restorePanoView(preservedView);
        google.maps.event.addListenerOnce(pano, "pano_changed", () => restorePanoView(preservedView));
    });
}

function enablePanoExpansionCue() {
    const panoEl = document.getElementById("pano");
    if (!panoEl) return;

    hasActiveSelection = true;
    panoExpanded = false;
    panoEl.classList.add("expandable");
    panoEl.classList.remove("expanded");
}

function setupSlideshow() {
    const overlay = document.getElementById("slideshow");
    const openBtn = document.getElementById("slideshow-open");
    const closeBtn = document.getElementById("slideshow-close");
    const toggleBtn = document.getElementById("slideshow-toggle");
    const intervalSelect = document.getElementById("slideshow-interval");
    const isStandalone = overlay?.dataset?.standalone === "true";

    if (!overlay) return;

    const updateToggleLabel = () => {
        if (toggleBtn) toggleBtn.textContent = slideshowRunning ? "Stop" : "Start";
    };

    const stopAndHide = () => {
        stopSlideshow();
        if (!isStandalone) overlay.hidden = true;
        updateToggleLabel();
    };

    openBtn?.addEventListener("click", () => {
        overlay.hidden = false;
        ensureSlideshowPano();
        refreshSlideshowData();
        showSlideshowSlide(slideshowIndex);
        updateToggleLabel();
    });

    closeBtn?.addEventListener("click", stopAndHide);

    toggleBtn?.addEventListener("click", async () => {
        if (slideshowRunning) {
            stopSlideshow();
        } else {
            await startSlideshow();
        }
        updateToggleLabel();
    });

    intervalSelect?.addEventListener("change", async (e) => {
        const val = Number(e.target.value);
        if (Number.isFinite(val) && val > 0) {
            slideshowIntervalMs = val;
            if (slideshowRunning) {
                await startSlideshow();
                updateToggleLabel();
            }
        }
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !overlay.hidden) {
            stopAndHide();
        }
    });

    if (!openBtn && !overlay.hidden) {
        ensureSlideshowPano();
        refreshSlideshowData();
        showSlideshowSlide(slideshowIndex);
        updateToggleLabel();
    }
}

function ensureSlideshowPano() {
    if (slideshowPano) return slideshowPano;

    slideshowPano = new google.maps.StreetViewPanorama(document.getElementById("slideshow-pano"), {
        visible: true,
        motionTracking: false,
        fullscreenControl: true,
        zoomControl: true,
    });
    return slideshowPano;
}

function refreshSlideshowData() {
    slideshowRows = Array.isArray(lastRows)
        ? lastRows
              .map((row) => {
                  if (!isEligibleForRandomView(row)) return null;
                  const pos = getRowPosition(row);
                  return pos ? { ...row, _position: pos } : null;
              })
              .filter(Boolean)
        : [];
    slideshowIndex = 0;
}

function setSlideshowArtText(text) {
    const artBox = document.getElementById("slideshow-art");
    if (!artBox) return;
    artBox.textContent = text;
}

function showSlideshowSlide(index) {
    ensureSlideshowPano();

    if (!slideshowRows.length) {
        setSlideshowArtText("Keine Street-View-Daten geladen. Lade Marker und versuche es erneut.");
        return;
    }

    slideshowIndex = (index + slideshowRows.length) % slideshowRows.length;
    const row = slideshowRows[slideshowIndex];
    if (!row) return;

    const fallback = row._position || getRowPosition(row);
    applyPanoView(slideshowPano, row, fallback, "slideshow-pano");

    const artText = row.art?.trim();
    const title = row.Title ? `${row.Title}: ` : "";
    setSlideshowArtText((artText ? title + artText : `${title}Keine Kunstangabe vorhanden.`).trim());
}

async function startSlideshow() {
    const previousIndex = slideshowIndex;

    if (!slideshowRows.length) {
        const status = document.getElementById("status");
        if (status) status.textContent = "Lade Street-View-Daten …";

        try {
            await loadMarkers();
        } catch (err) {
            handleLoadError(status, "Fehler beim Laden der Daten", err, { showOverlay: false });
            setSlideshowArtText("Fehler beim Laden der Street-View-Daten.");
            slideshowRunning = false;
            return;
        }
    }

    refreshSlideshowData();
    if (!slideshowRows.length) {
        setSlideshowArtText("Keine Street-View-Daten geladen. Lade Marker und versuche es erneut.");
        slideshowRunning = false;
        return;
    }

    if (previousIndex < slideshowRows.length) {
        slideshowIndex = previousIndex;
    }

    if (slideshowTimer) clearInterval(slideshowTimer);

    slideshowRunning = true;
    showSlideshowSlide(slideshowIndex);
    slideshowTimer = setInterval(() => showSlideshowSlide(slideshowIndex + 1), slideshowIntervalMs);
}

function stopSlideshow() {
    if (slideshowTimer) clearInterval(slideshowTimer);
    slideshowTimer = null;
    slideshowRunning = false;
}

function setText(id, v) {
    const el = document.getElementById(id);
    if (el) el.textContent = v;
}

function setRecordTitle(id, recordId, title) {
    const container = document.getElementById(id);
    if (!container) return;

    const hasId = recordId !== undefined && recordId !== null && recordId !== "";
    const hasTitle = Boolean(title);

    container.innerHTML = "";

    if (!hasId && !hasTitle) return;

    if (hasId) {
        const idSpan = document.createElement("span");
        idSpan.textContent = `#${recordId}${hasTitle ? " — " : ""}`;
        container.append(idSpan);
    }

    if (hasTitle) {
        const titleEl = document.createElement("strong");
        titleEl.textContent = title;
        container.append(titleEl);
    }
}

function showRandomMarker() {
    const eligibleMarkers = markers.filter((m) => isEligibleForRandomView(m?.__row));
    if (!eligibleMarkers.length) return;

    const idx = Math.floor(Math.random() * eligibleMarkers.length);
    const marker = eligibleMarkers[idx];
    if (!marker) return;

    // Center map on the selected marker and reuse the existing click handler to populate details.
    map.panTo(marker.getPosition());
    google.maps.event.trigger(marker, "click");
}

/* -------------------- Markers & interactions -------------------- */

// Expose to window for debugging
window.placeMarkers = function (rows) {
    // Clear old markers
    markers.forEach((m) => m.setMap(null));
    markers = [];
    lastRows = Array.isArray(rows) ? [...rows] : [];

    if (!rows || !rows.length) {
        return;
    }

    const bounds = new google.maps.LatLngBounds();

    const markerIcon = {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 6,
        fillColor: "#d32f2f",
        fillOpacity: 0.9,
        strokeColor: "#ffffff",
        strokeWeight: 1.5,
    };

    rows.forEach((row) => {
        const pos = getRowPosition(row);
        if (!pos) return;

        const m = new google.maps.Marker({ position: pos, map, title: row.Title || "", icon: markerIcon });
        m.__row = row;

        m.addListener("click", () => {
            enablePanoExpansionCue();

            applyPanoView(pano, row, pos, "pano");

            setRecordTitle("record-id-textbox", row.id, row.Title || "");
            setText("art-textbox", row.art || "");
            setText("architecture-textbox", row.architecture || "");
            setText("architect-textbox", row.all_architects || "");
            setText("Year_from", row.Year_from || "");
            setText("Year_to", row.Year_to || "");
        });

        markers.push(m);
        bounds.extend(pos);
    });

    // Viewport update: fit all markers if >1, else pan to the single one
    if (markers.length > 1) {
        map.fitBounds(bounds);
    } else {
        const pos = getRowPosition(rows[0]);
        if (pos) {
            map.panTo(pos);
        }
    }
};
