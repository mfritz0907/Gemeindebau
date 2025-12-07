
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
    <title>Gemeindebaukarte</title>
    <link rel="stylesheet" href="gemeindebaustyles.css" />
</head>
<body>
    <header class="topbar">
        <div class="title-row">
            <div>
                <p class="eyebrow">Wiener Gemeindebauten</p>
                <h1>Gemeindebaukarte</h1>
                <p class="subtitle">Entdecke Architektur, Kunst am Bau und Street View Aufnahmen in einem klaren Überblick.</p>
            </div>
            <div id="status" class="status-pill" role="status" aria-live="polite">Bereit</div>
        </div>

        <form id="search-form" class="search-form" autocomplete="off" role="search" aria-label="Gebäude filtern">
            <label class="visually-hidden" for="q">Suchbegriff</label>
            <input id="q" name="q" type="text" placeholder="Suche (Titel, Stichwort) …" aria-describedby="q-hint" />
            <span id="q-hint" class="visually-hidden">Gib Titel, Architektur- oder Stichwörter ein.</span>

            <label class="visually-hidden" for="zipcode">Postleitzahl</label>
            <input id="zipcode" name="zipcode" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="5" autocomplete="postal-code" placeholder="PLZ" />

            <fieldset class="decades">
                <legend class="visually-hidden">Baujahrzehnte</legend>
                <label><input type="checkbox" name="decade[]" value="1910">1910er</label>
                <label><input type="checkbox" name="decade[]" value="1920">1920er</label>
                <label><input type="checkbox" name="decade[]" value="1930">1930er</label>
                <label><input type="checkbox" name="decade[]" value="1940">1940er</label>
                <label><input type="checkbox" name="decade[]" value="1950">1950er</label>
                <label><input type="checkbox" name="decade[]" value="1960">1960er</label>
                <label><input type="checkbox" name="decade[]" value="1970">1970er</label>
            </fieldset>

            <button class="primary" type="submit" aria-label="Suche starten">Suchen</button>
            <button id="reset-btn" type="button" aria-label="Filter zurücksetzen">Zurücksetzen</button>
            <button id="kunst-btn" type="button" aria-label="Nur mit Kunst anzeigen">Gemeindebauten mit Kunst</button>
            <button id="streetview-btn" type="button" aria-label="Nur mit Streetview">Gemeindebauten mit Streetview</button>
        </form>
    </header>

    <div class="container">
        <div class="left" aria-label="Karte">
            <div class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Karte</p>
                        <h2>Übersicht</h2>
                    </div>
                    <button type="button" class="ghost" onclick="showRandomMarker()" aria-label="Zufälligen Ort anzeigen">Zufälligen Ort</button>
                </div>
                <div id="map" class="map"></div>
            </div>
        </div>

        <div class="right" aria-label="Street View und Informationen">
            <div class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Street View</p>
                        <h2>Rundgang</h2>
                    </div>
                </div>
                <div id="pano" class="pano"></div>
            </div>
            <div class="panel" id="info-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Details</p>
                        <h2>Gebäude</h2>
                    </div>
                </div>
                <div class="info-grid">
                    <div class="info-item" data-label="ID &amp; Titel">
                        <div id="record-id-textbox" class="info-value muted">Klicke auf einen Marker, um Details zu sehen.</div>
                    </div>
                    <div class="info-item" data-label="Kunst am Bau">
                        <div id="art-textbox" class="info-value"></div>
                    </div>
                    <div class="info-item" data-label="Architektur">
                        <div id="architecture-textbox" class="info-value"></div>
                    </div>
                    <div class="info-item" data-label="Architekt:innen">
                        <div id="architect-textbox" class="info-value"></div>
                    </div>
                    <div class="info-item" data-label="Baujahr von">
                        <div id="Year_from" class="info-value"></div>
                    </div>
                    <div class="info-item" data-label="Baujahr bis">
                        <div id="Year_to" class="info-value"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <noscript>
        <p>Diese Anwendung benötigt JavaScript, um Karte und Street View anzuzeigen.</p>
    </noscript>

    <script src="gemeindebauscript.js"></script>
    <!-- Google Maps key is read from dbconnect/config_local.php -->
    
<?php if ($googleMapsApiKey !== ''): ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($googleMapsApiKey, ENT_QUOTES, 'UTF-8'); ?>&callback=initMap&loading=async" async defer></script>
<?php else: ?>
    <script>
        console.warn('Google Maps API key is missing. Please set GOOGLE_MAPS_API_KEY in dbconnect/config_local.php.');
    </script>
<?php endif; ?>
</body>
</html>

