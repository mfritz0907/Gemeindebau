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
        .catch(() => status && (status.textContent = "Fehler beim Laden der Daten."));
};

/* -------------------- UI wiring -------------------- */

function wireForm() {
    const form = document.getElementById("search-form");
    const status = document.getElementById("status");
    const kunstBtn = document.getElementById("kunst-btn");
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
                .catch((err) => {
                    console.error(err);
                    if (status) status.textContent = "Fehler beim Laden der Daten.";
                });
        });
    }

    if (kunstBtn) {
        kunstBtn.addEventListener("click", () => {
            form?.reset();
            if (status) status.textContent = "Lade Gemeindebauten mit Kunst …";
            // keep existing API param for back-compat
            loadMarkers({ with_art: 1 })
                .then((rows) => (status ? (status.textContent = rows.length ? "Fertig" : "0 Treffer") : null))
                .catch((err) => {
                    console.error(err);
                    if (status) status.textContent = "Fehler beim Laden der Daten.";
                });
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener("click", () => {
            form?.reset();
            if (status) status.textContent = "Filter zurückgesetzt. Lade alle Daten …";
            loadMarkers()
                .then((rows) => (status ? (status.textContent = rows.length ? "Fertig" : "0 Treffer") : null))
                .catch((err) => {
                    console.error(err);
                    if (status) status.textContent = "Fehler beim Laden der Daten.";
                });
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
                console.error(err);
                if (status) status.textContent = `Fehler beim Laden von #${id}.`;
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
                console.error(err);
                if (status) status.textContent = "Fehler beim Laden der Street-View-Daten.";
            }
        });
    }
}

/* -------------------- Backend calls -------------------- */

async function fetchJSON(url) {
    const res = await fetch(url, { headers: { Accept: "application/json" } });
    if (!res.ok) {
        const text = await res.text().catch(() => "");
        throw new Error(`Backend ${res.status}: ${text || "no body"}`);
    }
    // Read as text first to guard against empty/non-JSON responses
    const body = await res.text();
    if (!body) throw new Error("Empty response body");
    try {
        return JSON.parse(body);
    } catch (e) {
        throw new Error("Ungültiges JSON vom Server: " + body.slice(0, 300));
    }
}

/** Existing: triggers action=mapMarkers with optional filters */
async function loadMarkers(query = {}) {
    const params = new URLSearchParams({ action: "mapMarkers", ...query, nocache: Date.now() });
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
function zoomForFullWindow(desiredZoomLevel) {
    const panoEl = document.getElementById("pano");
    const h = Math.max(1, panoEl?.clientHeight || 1);
    const H = Math.max(1, window.innerHeight || h);

    const correction = Math.log2(H / h);
    const adjusted = Number.isFinite(desiredZoomLevel) ? desiredZoomLevel - correction : 0;

    return clamp(adjusted, 0, 5);
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

        panoExpanded = !panoExpanded;
        panoEl.classList.toggle("expanded", panoExpanded);
        panoEl.classList.toggle("expandable", hasActiveSelection);
        google.maps.event.trigger(pano, "resize");
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
    if (!markers.length) return;
    const idx = Math.floor(Math.random() * markers.length);
    const marker = markers[idx];
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

    if (!rows || !rows.length) {
        return;
    }

    const bounds = new google.maps.LatLngBounds();

    rows.forEach((row) => {
        const lat = parseFloat(row.lat);
        const lng = parseFloat(row.lng);
        if (Number.isNaN(lat) || Number.isNaN(lng)) return;

        const pos = { lat, lng };
        const m = new google.maps.Marker({ position: pos, map, title: row.Title || "" });

        m.addListener("click", () => {
            const panoState = derivePanoState(row);
            const { position, heading, pitch, baseZoomLevel } = panoState;

            enablePanoExpansionCue();

            const applyZoom = () => pano.setZoom(zoomForFullWindow(baseZoomLevel));

            // Make pano visible & set base attributes first
            pano.setVisible(true);
            pano.setPosition(position || pos);
            pano.setPov({ heading, pitch });

            // Force layout and apply zoom; re-apply after pano change to avoid late "zoom jump"
            google.maps.event.trigger(pano, "resize");
            applyZoom();
            google.maps.event.addListenerOnce(pano, "pano_changed", () => {
                google.maps.event.trigger(pano, "resize");
                setTimeout(applyZoom, 0);
            });

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
        const first = rows[0];
        const lat = parseFloat(first.lat);
        const lng = parseFloat(first.lng);
        if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
            map.panTo({ lat, lng });
        }
    }
};
