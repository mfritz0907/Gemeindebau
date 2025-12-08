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
    <title>Street View Zufallsshow</title>
    <link rel="stylesheet" href="gemeindebaustyles.css" />
</head>
<body>
    <header class="topbar">
        <div class="title-row">
            <div>
                <p class="eyebrow">Alternative Ansicht</p>
                <h1>Street View Slideshow</h1>
                <p class="subtitle">Zufällige Kunstansichten im Vollbild mit frei wählbarem Zeitintervall.</p>
            </div>
            <div class="header-actions">
                <a class="ghost" href="Gemeindebaukarte.php">Zurück zur Karte</a>
                <div id="status" class="status-pill" role="status" aria-live="polite">Bereit</div>
            </div>
        </div>
    </header>

    <div class="visually-hidden" aria-hidden="true">
        <div id="map"></div>
        <div id="pano"></div>
    </div>

    <div id="slideshow" class="slideshow slideshow--standalone" data-standalone="true">
        <div class="slideshow__header">
            <div>
                <p class="eyebrow">Street View</p>
                <h2>Zufallsansicht</h2>
            </div>
            <div class="slideshow__controls" aria-live="polite">
                <label for="slideshow-interval">Intervall:</label>
                <select id="slideshow-interval" name="slideshow-interval">
                    <option value="5000">5 Sekunden</option>
                    <option value="10000" selected>10 Sekunden</option>
                    <option value="20000">20 Sekunden</option>
                    <option value="30000">30 Sekunden</option>
                </select>
                <button type="button" id="slideshow-toggle" class="primary">Start</button>
                <button type="button" id="slideshow-close" aria-label="Slideshow stoppen">Stoppen</button>
            </div>
        </div>
        <div class="slideshow__body">
            <div id="slideshow-pano" class="slideshow__pano" role="presentation"></div>
            <div class="slideshow__caption">
                <p class="eyebrow">Kunst am Bau</p>
                <div id="slideshow-art" class="slideshow__text">Starte die Slideshow, um zufällige Kunstansichten zu sehen.</div>
            </div>
        </div>
    </div>

    <noscript>
        <p>Diese Ansicht benötigt JavaScript, um Street View Panoramen anzuzeigen.</p>
    </noscript>

    <script src="gemeindebauscript.js"></script>
    <!-- Google Maps key is read from dbconnect/config_local.php -->

<?php if ($googleMapsApiKey !== ''): ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($googleMapsApiKey, ENT_QUOTES, 'UTF-8'); ?>&callback=initMap&loading=async" async defer></script>
<?php else: ?>
    <script>
        console.warn('Google Maps API key is missing. Please set GOOGLE_MAPS_API_KEY in dbconnect/config_local.php.');
        if (window.notifyMissingMapsKey) {
            window.notifyMissingMapsKey();
        }
    </script>
<?php endif; ?>
</body>
</html>
