/* gemeindebauscript.js */
let map, pano, markers = [];

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

    wireForm();

    // initial load
    const status = document.getElementById("status");
    if (status) status.textContent = "Lade Daten …";
    loadMarkers()
        .then((rows) => {
            if (status) status.textContent = rows && rows.length ? "Fertig" : "0 Treffer";
        })
        .catch(() => status && (status.textContent = "Fehler beim Laden der Daten."));
};

function wireForm() {
    const form = document.getElementById("search-form");
    const status = document.getElementById("status");
    const kunstBtn = document.getElementById("kunst-btn");
    const resetBtn = document.getElementById("reset-btn");

    form.addEventListener("submit", (e) => {
        e.preventDefault();
        const q = document.getElementById("q").value.trim();
        const zipcode = document.getElementById("zipcode").value.trim();

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

    if (kunstBtn) {
        kunstBtn.addEventListener("click", () => {
            form.reset();
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
            form.reset();
            if (status) status.textContent = "Filter zurückgesetzt. Lade alle Daten …";
            loadMarkers()
                .then((rows) => (status ? (status.textContent = rows.length ? "Fertig" : "0 Treffer") : null))
                .catch((err) => {
                    console.error(err);
                    if (status) status.textContent = "Fehler beim Laden der Daten.";
                });
        });
    }
}

async function loadMarkers(query = {}) {
    const params = new URLSearchParams({ action: "mapMarkers", ...query, nocache: Date.now() });
    const url = `get_data.php?${params.toString()}`;

    const res = await fetch(url, { headers: { Accept: "application/json" } });

    // If backend failed, read text and throw a helpful error before attempting JSON
    if (!res.ok) {
        const text = await res.text().catch(() => "");
        throw new Error(`Backend ${res.status}: ${text || "no body"}`);
    }

    // Read as text first to guard against empty/non-JSON responses
    const body = await res.text();
    if (!body) throw new Error("Empty response body");

    let data;
    try {
        data = JSON.parse(body);
    } catch (e) {
        throw new Error("Ungültiges JSON vom Server: " + body.slice(0, 300));
    }

    if (!Array.isArray(data)) {
        // If API returns an error object, surface it
        if (data && typeof data === "object" && data.error) {
            throw new Error("API-Fehler: " + (data.detail || data.error));
        }
        throw new Error("Unerwartetes Antwortformat");
    }

    placeMarkers(data);
    return data;
}

/* ---------- Helpers ---------- */

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

function setText(id, v) {
    const el = document.getElementById(id);
    if (el) el.textContent = v;
}

/* ---------- Markers & interactions ---------- */

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
            const heading = Number(row.heading) || 0;
            const pitch = Number(row.pitch) || 0;

            // Your DB 'zoom' column is FOV degrees (10..100) saved by update_view.php.
            // Convert FOV → SV zoom level, then adjust for pane size to match full-window perception.
            const rawZoom = Number(row.zoom);
            const baseZoomLevel = rawZoom > 5 ? fovToZoomLevel(rawZoom) : clamp(rawZoom, 0, 5);
            const applyZoom = () => pano.setZoom(zoomForFullWindow(baseZoomLevel));

            // Make pano visible & set base attributes first
            pano.setVisible(true);
            pano.setPosition(pos);
            pano.setPov({ heading, pitch });

            // Force layout and apply zoom; re-apply after pano change to avoid late "zoom jump"
            google.maps.event.trigger(pano, "resize");
            applyZoom();
            google.maps.event.addListenerOnce(pano, "pano_changed", () => {
                google.maps.event.trigger(pano, "resize");
                setTimeout(applyZoom, 0);
            });

            setText("record-id-textbox", `#${row.id} — ${row.Title || ""}`);
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
