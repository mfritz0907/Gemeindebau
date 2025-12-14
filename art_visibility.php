<?php
$configPath = __DIR__ . '/dbconnect/config_local.php';
$googleMapsApiKey = '';

if (file_exists($configPath)) {
    require $configPath;
    if (defined('GOOGLE_MAPS_API_KEY')) {
        $googleMapsApiKey = GOOGLE_MAPS_API_KEY;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Kunst-Sichtbarkeit nach PLZ</title>
    <link rel="stylesheet" href="gemeindebaustyles.css" />
</head>
<body>
    <header class="topbar">
        <div class="title-row">
            <div>
                <p class="eyebrow">Werkzeuge</p>
                <h1>Kunst-Sichtbarkeit nach PLZ</h1>
                <p class="subtitle">Street-View-Ansichten pro Postleitzahl einsehen und die Sichtbarkeit der Kunst markieren.</p>
            </div>
            <div class="header-actions">
                <a class="ghost" href="Gemeindebaukarte.php">Zurück zur Karte</a>
                <div id="status" class="status-pill" role="status" aria-live="polite">Bereit</div>
            </div>
        </div>
    </header>

    <main class="container container--narrow">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Filter</p>
                    <h2>Nach Postleitzahl auswählen</h2>
                </div>
            </div>
            <form id="zip-form" class="zip-form" autocomplete="off">
                <label for="zipcode">Postleitzahl</label>
                <div class="zip-form__row">
                    <select id="zipcode" name="zipcode">
                        <option value="">Bitte auswählen …</option>
                    </select>
                    <button type="submit" class="primary">Laden</button>
                </div>
                <p class="help">Die Liste zeigt alle Datensätze mit Street-View-Link für die ausgewählte PLZ.</p>
            </form>
        </section>

        <section class="panel" id="results-panel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Ergebnisse</p>
                    <h2>Street-View-Einträge</h2>
                </div>
            </div>
            <div id="results" class="zip-results" aria-live="polite"></div>
        </section>
    </main>

    <noscript>
        <p>Diese Ansicht benötigt JavaScript, um die Einträge zu laden.</p>
    </noscript>

    <script>
    (function() {
        const statusEl = document.getElementById('status');
        const form = document.getElementById('zip-form');
        const select = document.getElementById('zipcode');
        const results = document.getElementById('results');

        function setStatus(msg, variant = 'ready') {
            statusEl.textContent = msg;
            statusEl.className = 'status-pill status-pill--' + variant;
        }

        function renderResults(items) {
            if (!items.length) {
                results.innerHTML = '<p class="muted">Keine Einträge gefunden.</p>';
                return;
            }

            const frag = document.createDocumentFragment();
            items.forEach(item => {
                const card = document.createElement('article');
                card.className = 'zip-entry';

                const header = document.createElement('div');
                header.className = 'zip-entry__header';

                const text = document.createElement('div');
                text.innerHTML = `
                    <p class="eyebrow">PLZ ${item.zipcode}</p>
                    <h3>${item.Title ? escapeHtml(item.Title) : 'Ohne Titel'} <span class="muted">#${item.id}</span></h3>
                    <p class="muted">${item.art ? escapeHtml(item.art) : 'Keine Kunstbeschreibung hinterlegt'}</p>
                `;

                const toggleLabel = document.createElement('label');
                toggleLabel.className = 'checkbox-row';
                toggleLabel.innerHTML = `
                    <input type="checkbox" class="art-visible-toggle" data-id="${item.id}" ${item.art_visible ? 'checked' : ''}>
                    <span>Kunst sichtbar</span>
                `;

                header.appendChild(text);
                header.appendChild(toggleLabel);

                const iframeRow = document.createElement('div');
                iframeRow.className = 'zip-entry__iframe';
                const iframe = document.createElement('iframe');
                iframe.src = item.streetviewlink;
                iframe.allowFullscreen = true;
                iframe.loading = 'lazy';
                iframe.referrerPolicy = 'no-referrer-when-downgrade';
                iframeRow.appendChild(iframe);

                card.appendChild(header);
                card.appendChild(iframeRow);
                frag.appendChild(card);
            });

            results.innerHTML = '';
            results.appendChild(frag);
            wireToggles();
        }

        function wireToggles() {
            const toggles = results.querySelectorAll('.art-visible-toggle');
            toggles.forEach(toggle => {
                toggle.addEventListener('change', async (ev) => {
                    const id = toggle.dataset.id;
                    const visible = toggle.checked ? '1' : '0';
                    try {
                        setStatus('Aktualisiere …', 'info');
                        const res = await fetch('get_data.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({ action: 'updateArtVisible', id, visible })
                        });
                        const data = await res.json();
                        if (!res.ok || data.error) {
                            throw new Error(data.error || 'Unbekannter Fehler');
                        }
                        setStatus('Gespeichert', 'success');
                    } catch (err) {
                        setStatus('Fehler: ' + err.message, 'error');
                        toggle.checked = !toggle.checked;
                    }
                }, { once: false });
            });
        }

        function escapeHtml(str) {
            return str.replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
        }

        async function fetchZipcodes() {
            try {
                setStatus('Lade PLZ …', 'info');
                const res = await fetch('get_data.php?action=streetviewZipcodes');
                const data = await res.json();
                select.innerHTML = '<option value="">Bitte auswählen …</option>';
                data.forEach(zip => {
                    const opt = document.createElement('option');
                    opt.value = zip;
                    opt.textContent = zip;
                    select.appendChild(opt);
                });
                setStatus('Bereit', 'ready');
            } catch (err) {
                setStatus('Fehler beim Laden der PLZ: ' + err.message, 'error');
            }
        }

        async function loadEntries(zip) {
            if (!zip) {
                results.innerHTML = '<p class="muted">Bitte zuerst eine Postleitzahl auswählen.</p>';
                return;
            }
            try {
                setStatus('Lade Einträge …', 'info');
                const res = await fetch(`get_data.php?action=streetviewByZip&zipcode=${encodeURIComponent(zip)}`);
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Unbekannter Fehler');
                }
                renderResults(data);
                setStatus(`Gefundene Einträge: ${data.length}`, 'ready');
            } catch (err) {
                results.innerHTML = `<p class="error">${escapeHtml(err.message)}</p>`;
                setStatus('Fehler: ' + err.message, 'error');
            }
        }

        form.addEventListener('submit', (ev) => {
            ev.preventDefault();
            loadEntries(select.value);
        });

        fetchZipcodes();
    })();
    </script>
</body>
</html>
