
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
    <style>
        /* optional helper for visually hidden labels */
        .visually-hidden {
            position: absolute !important;
            height: 1px;
            width: 1px;
            overflow: hidden;
            clip: rect(1px, 1px, 1px, 1px);
            white-space: nowrap;
            border: 0;
            padding: 0;
            margin: -1px;
        }
        /* minimal layout safety */
        html, body {
            height: 100%;
            margin: 0;
        }

        .container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            height: calc(100vh - 80px);
            gap: 8px;
            padding: 8px;
        }

        #map, #pano {
            width: 100%;
            height: 100%;
            background: #eee;
        }

        header.topbar {
            display: grid;
            gap: 8px;
            padding: 8px;
            background: #f7f7f7;
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        fieldset.decades {
            border: 0;
            padding: 0;
            margin: 0 8px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        #status {
            min-height: 1.2em;
            color: #333;
        }

        @media (max-width: 900px) {
            .container {
                grid-template-columns: 1fr;
                grid-template-rows: 50vh 50vh;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
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

            <button type="submit" aria-label="Suche starten">Suchen</button>
            <button id="reset-btn" type="button" aria-label="Filter zurücksetzen">Zurücksetzen</button>
            <button id="kunst-btn" type="button" aria-label="Nur mit Kunst anzeigen">Gemeindebauten mit Kunst</button>
            <button id="streetview-btn" type="button" aria-label="Nur mit Streetview">Gemeindebauten mit Streetview</button>
        </form>

        
        <div id="status" role="status" aria-live="polite"></div>
    </header>

    <div class="container">
        <div class="left" aria-label="Karte">
            <div id="map"></div>
        </div>

        <div class="right" aria-label="Street View und Informationen">
            <div id="pano"></div>
            <div id="info-panel">
                <div id="record-id-textbox"></div>
                <div id="art-textbox"></div>
                <div id="architecture-textbox"></div>
                <div id="architect-textbox"></div>
                <div id="Year_from"></div>
                <div id="Year_to"></div>
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

