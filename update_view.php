<?php
declare(strict_types=1);

/**
 * update_view.php
 * - View current DB values (streetviewlink, url, latitude, longitude, heading, pitch, zoom)
 * - Paste a Google Maps URL ? parse ? preview as Embed URL
 * - Save parsed values back to DB (with CSRF protection)
 */

session_start();

require_once __DIR__ . '/dbconnect/config_local.php';

/* ---------- DB connect (using your constants) ---------- */
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || (!defined('DB_PASSWORD') && !defined('DB_PASS'))) {
    http_response_code(500);
    echo "Missing DB constants in config_local.php (need DB_HOST, DB_NAME, DB_USER, DB_PASSWORD).";
    exit;
}
$host = DB_HOST;
$name = DB_NAME;
$user = DB_USER;
$pass = defined('DB_PASSWORD') ? DB_PASSWORD : DB_PASS;

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

/** ---------- Adjust to your schema ---------- */
$table       = 'building';
$idcol       = 'id';
$titlecol    = 'Title';
$svcol       = 'streetviewlink';   // will store the generated embed URL
$urlcol      = 'url';              // optional: store the raw URL you pasted
$latcol      = 'latitude';
$lngcol      = 'longitude';
$headingcol  = 'heading';
$pitchcol    = 'pitch';
$zoomcol     = 'zoom';             // store FOV (10..100)
/** ------------------------------------------ */

// Input
$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($id === '') { http_response_code(400); echo "Missing ?id parameter."; exit; }

/* ---------- Helpers ---------- */
function clamp_vals(array $vals): array {
    // heading 0..360 normalized
    if ($vals['heading'] !== null) {
        $h = fmod((float)$vals['heading'], 360.0);
        if ($h < 0) $h += 360.0;
        $vals['heading'] = $h;
    }
    // pitch -90..90
    if ($vals['pitch'] !== null) {
        $p = (float)$vals['pitch'];
        if ($p > 90)  $p = 90;
        if ($p < -90) $p = -90;
        $vals['pitch'] = $p;
    }
    // fov 10..100
    if ($vals['zoom'] !== null) {
        $f = (float)$vals['zoom'];
        if ($f < 10)  $f = 10;
        if ($f > 100) $f = 100;
        $vals['zoom'] = $f;
    }
    return $vals;
}

function build_embed_url_from_vals(array $vals, ?string $apiKey): ?string {
    if ($apiKey === null || $vals['latitude'] === null || $vals['longitude'] === null) return null;
    $vals = clamp_vals($vals);
    $params = [
        'key'      => $apiKey,
        'location' => $vals['latitude'] . ',' . $vals['longitude'],
    ];
    if ($vals['heading'] !== null) $params['heading'] = (string)$vals['heading'];
    if ($vals['pitch']   !== null) $params['pitch']   = (string)$vals['pitch'];
    if ($vals['zoom']    !== null) $params['fov']     = (string)$vals['zoom'];
    return 'https://www.google.com/maps/embed/v1/streetview?' . http_build_query($params);
}

/* Parse many Google URL shapes and return lat/lng/heading/pitch/zoom */
function parse_gmaps_sv_url(string $raw): array {
    $out = ['latitude'=>null,'longitude'=>null,'heading'=>null,'pitch'=>null,'zoom'=>null];
    $raw = trim($raw);
    if ($raw === '') return $out;

    // Query-param style (viewpoint=lat,lng&heading=&pitch=&fov=)
    $u = @parse_url($raw);
    if (is_array($u) && isset($u['query'])) {
        parse_str($u['query'], $q);
        if (!empty($q['viewpoint'])) {
            $vp = explode(',', (string)$q['viewpoint']);
            if (count($vp) >= 2) {
                $lat = filter_var($vp[0], FILTER_VALIDATE_FLOAT);
                $lng = filter_var($vp[1], FILTER_VALIDATE_FLOAT);
                if ($lat !== false) $out['latitude']  = (float)$lat;
                if ($lng !== false) $out['longitude'] = (float)$lng;
            }
        }
        if (isset($q['heading']) && is_numeric((string)$q['heading'])) $out['heading'] = (float)$q['heading'];
        if (isset($q['pitch'])   && is_numeric((string)$q['pitch']))   $out['pitch']   = (float)$q['pitch']; // already pitch
        if (isset($q['fov'])     && is_numeric((string)$q['fov']))     $out['zoom']    = (float)$q['fov'];
    }

    // @lat,lng,3a,75y,268.36h,90t/...  (stop at '?', then cut at first '/')
    if (preg_match('/@([\-0-9.]+),([\-0-9.]+),([^?]+)/', $raw, $m)) {
        $lat = filter_var($m[1], FILTER_VALIDATE_FLOAT);
        $lng = filter_var($m[2], FILTER_VALIDATE_FLOAT);
        if ($lat !== false) $out['latitude']  = (float)$lat;
        if ($lng !== false) $out['longitude'] = (float)$lng;

        $segment = explode('/', $m[3], 2)[0];

        if (preg_match('/([\-0-9.]+)y/i', $segment, $mm)) $out['zoom']    = (float)$mm[1];   // fov-ish
        if (preg_match('/([\-0-9.]+)h/i', $segment, $mm)) $out['heading'] = (float)$mm[1];

        // 't' means tilt: 0=down, 90=horizon, 180=up -> convert to pitch: pitch = tilt - 90
        if (preg_match('/([\-0-9.]+)t/i', $segment, $mm)) {
            $tilt = (float)$mm[1];
            $out['pitch'] = $tilt - 90.0;
        }
        // Some variants use 'p' which already is pitch
        if (preg_match('/([\-0-9.]+)p/i', $segment, $mm)) {
            $out['pitch'] = (float)$mm[1];
        }
    }

    return clamp_vals($out);
}

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

/* ---------- Handle POST (extract or save) ---------- */
$inputUrlRaw = '';
$parsedInput = null;
$generatedEmbedUrl = null;
$notes = [];
$flash = null;

$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'extract';

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo "Invalid CSRF token.";
        exit;
    }

    if ($action === 'extract') {
        $inputUrlRaw = isset($_POST['gmaps_url']) ? trim((string)$_POST['gmaps_url']) : '';
        $parsedInput = parse_gmaps_sv_url($inputUrlRaw);

        if ($apiKey !== null) {
            $generatedEmbedUrl = build_embed_url_from_vals($parsedInput, $apiKey);
        } else {
            $notes[] = "Kein GOOGLE_MAPS_API_KEY in config_local.php definiert – Embed-Link kann nicht erzeugt werden.";
        }

    } elseif ($action === 'save') {
        // Read posted fields (hidden inputs) and sanitize
        $inputUrlRaw = isset($_POST['gmaps_url']) ? trim((string)$_POST['gmaps_url']) : '';

        $vals = [
            'latitude'  => isset($_POST['lat'])     ? (is_numeric($_POST['lat'])     ? (float)$_POST['lat']     : null) : null,
            'longitude' => isset($_POST['lng'])     ? (is_numeric($_POST['lng'])     ? (float)$_POST['lng']     : null) : null,
            'heading'   => isset($_POST['heading']) ? (is_numeric($_POST['heading']) ? (float)$_POST['heading'] : null) : null,
            'pitch'     => isset($_POST['pitch'])   ? (is_numeric($_POST['pitch'])   ? (float)$_POST['pitch']   : null) : null,
            'zoom'      => isset($_POST['zoom'])    ? (is_numeric($_POST['zoom'])    ? (float)$_POST['zoom']    : null) : null,
        ];
        $vals = clamp_vals($vals);

        // Build embed link server-side
        $generatedEmbedUrl = build_embed_url_from_vals($vals, $apiKey);

        // Save to DB
        try {
            $sql = "UPDATE `{$table}` SET
                        `{$latcol}`     = :lat,
                        `{$lngcol}`     = :lng,
                        `{$headingcol}` = :heading,
                        `{$pitchcol}`   = :pitch,
                        `{$zoomcol}`    = :zoom,
                        `{$svcol}`      = :sv" .
                        ($urlcol ? ", `{$urlcol}` = :url" : "") . "
                    WHERE `{$idcol}` = :id
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':lat',     $vals['latitude'],  PDO::PARAM_STR);
            $stmt->bindValue(':lng',     $vals['longitude'], PDO::PARAM_STR);
            $stmt->bindValue(':heading', $vals['heading'],   PDO::PARAM_STR);
            $stmt->bindValue(':pitch',   $vals['pitch'],     PDO::PARAM_STR);
            $stmt->bindValue(':zoom',    $vals['zoom'],      PDO::PARAM_STR);
            $stmt->bindValue(':sv',      $generatedEmbedUrl, PDO::PARAM_STR);
            if ($urlcol) {
                $stmt->bindValue(':url', $inputUrlRaw !== '' ? $inputUrlRaw : null, $inputUrlRaw !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            }
            $stmt->bindValue(':id',      $id);
            $stmt->execute();

            $flash = "Erfolgreich gespeichert.";
            // Reflect saved values in current view:
            $parsedInput = $vals;
        } catch (Throwable $e) {
            http_response_code(500);
            echo "Update error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }
    }
}

// Fetch record (to display current DB values)
try {
    $sql = "SELECT
              `{$titlecol}`   AS ttl,
              `{$svcol}`      AS sv,
              `{$urlcol}`     AS db_url,
              `{$latcol}`     AS db_lat,
              `{$lngcol}`     AS db_lng,
              `{$headingcol}` AS db_heading,
              `{$pitchcol}`   AS db_pitch,
              `{$zoomcol}`    AS db_zoom
            FROM `{$table}`
            WHERE `{$idcol}` = :id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    $title      = $row['ttl']        ?? '';
    $link       = $row['sv']         ?? '';
    $dbUrl      = $row['db_url']     ?? '';
    $dbLat      = $row['db_lat']     ?? null;
    $dbLng      = $row['db_lng']     ?? null;
    $dbHeading  = $row['db_heading'] ?? null;
    $dbPitch    = $row['db_pitch']   ?? null;
    $dbZoom     = $row['db_zoom']    ?? null;
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

// Build embed URL for preview (on extract/save)
if ($generatedEmbedUrl === null && is_array($parsedInput) && $apiKey !== null) {
    $generatedEmbedUrl = build_embed_url_from_vals($parsedInput, $apiKey);
}

// Legacy parse from DB streetviewlink (optional)
$parts = ['viewpoint'=>null,'heading'=>null,'pitch'=>null];
if ($link) {
    $url = @parse_url($link);
    if ($url && isset($url['query'])) {
        parse_str($url['query'], $q);
        $parts['viewpoint'] = $q['viewpoint'] ?? null;
        $parts['heading']   = isset($q['heading']) ? (string)$q['heading'] : null;
        $parts['pitch']     = isset($q['pitch'])   ? (string)$q['pitch']   : null;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Update View – Datensatz <?= htmlspecialchars($id) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; margin:24px; color:#222; }
  .card { border:1px solid #e5e5e5; border-radius:12px; padding:16px; max-width:900px; box-shadow:0 2px 6px rgba(0,0,0,.06); }
  h1 { margin:0 0 6px 0; font-size:20px; }
  .sub { color:#666; margin:0 0 16px 0; }
  .grid { display:grid; grid-template-columns: 220px 1fr; gap:10px 16px; }
  .label { color:#555; }
  input[type="text"], textarea { width:100%; max-width:650px; padding:8px; border:1px solid #ccc; border-radius:8px; }
  textarea { min-height: 80px; }
  .kv { margin-top:8px; color:#444; }
  .kv div { margin:4px 0; }
  .muted { color:#8b8b8b; }
  .actions { margin-top:14px; display:flex; gap:8px; flex-wrap:wrap; }
  a.button, button { display:inline-block; padding:8px 12px; border-radius:8px; border:1px solid #ddd; background:#fafafa; text-decoration:none; color:#222; cursor:pointer; }
  a.button:hover, button:hover { background:#f0f0f0; }
  iframe { width:100%; height:360px; border:0; border-radius:8px; }
  .note { color:#8b6f00; font-size:12px; }
  .flash { background:#e7f7eb; border:1px solid #b9e6c3; color:#205e2f; padding:8px 10px; border-radius:8px; margin-bottom:10px; }
</style>
</head>
<body>
  <div class="card">
    <h1>Street View & Koordinaten</h1>
    <p class="sub">Datensatz <strong><?= htmlspecialchars($id) ?></strong><?php if ($title) : ?> – <?= htmlspecialchars($title) ?><?php endif; ?></p>

    <?php if ($flash): ?>
      <div class="flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- DB values -->
    <div class="grid" style="margin-bottom: 16px;">
      <div class="label">streetviewlink (DB):</div>
      <div><?= $link ? '<input type="text" value="'.htmlspecialchars($link, ENT_QUOTES, 'UTF-8').'" readonly onclick="this.select()">' : '<span class="muted">—</span>' ?></div>

      <div class="label">url (DB):</div>
      <div><?= $dbUrl ? '<input type="text" value="'.htmlspecialchars($dbUrl, ENT_QUOTES, 'UTF-8').'" readonly onclick="this.select()">' : '<span class="muted">—</span>' ?></div>

      <div class="label">latitude (DB):</div>
      <div><?= ($dbLat !== null && $dbLat !== '') ? htmlspecialchars((string)$dbLat) : '<span class="muted">—</span>' ?></div>

      <div class="label">longitude (DB):</div>
      <div><?= ($dbLng !== null && $dbLng !== '') ? htmlspecialchars((string)$dbLng) : '<span class="muted">—</span>' ?></div>

      <div class="label">heading (DB):</div>
      <div><?= ($dbHeading !== null && $dbHeading !== '') ? htmlspecialchars((string)$dbHeading) : '<span class="muted">—</span>' ?></div>

      <div class="label">pitch (DB):</div>
      <div><?= ($dbPitch !== null && $dbPitch !== '') ? htmlspecialchars((string)$dbPitch) : '<span class="muted">—</span>' ?></div>

      <div class="label">zoom / fov (DB):</div>
      <div><?= ($dbZoom !== null && $dbZoom !== '') ? htmlspecialchars((string)$dbZoom) : '<span class="muted">—</span>' ?></div>
    </div>

    <hr style="border:none;border-top:1px solid #eee;margin:16px 0;">

    <!-- Parser + transformer -->
    <h2 style="margin:0 0 8px 0; font-size:18px;">Google Maps-URL analysieren & in Embed-Link umwandeln</h2>

    <form method="post" style="margin-bottom: 8px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
      <textarea name="gmaps_url" placeholder="Fügen Sie hier eine Google Maps (Street View)-URL ein …"><?=
        isset($_POST['gmaps_url']) ? htmlspecialchars((string)$_POST['gmaps_url'], ENT_QUOTES, 'UTF-8') : ''
      ?></textarea>
      <div class="actions">
        <button type="submit" name="action" value="extract">Werte extrahieren & Embed-Link erzeugen</button>
      </div>
    </form>

    <?php if (is_array($parsedInput)): ?>
      <div class="kv">
        <div><strong>latitude</strong>: <?= $parsedInput['latitude']  !== null ? htmlspecialchars((string)$parsedInput['latitude'])  : '—' ?></div>
        <div><strong>longitude</strong>: <?= $parsedInput['longitude'] !== null ? htmlspecialchars((string)$parsedInput['longitude']) : '—' ?></div>
        <div><strong>heading</strong>: <?= $parsedInput['heading']    !== null ? htmlspecialchars((string)$parsedInput['heading'])    : '—' ?></div>
        <div><strong>pitch</strong>: <?= $parsedInput['pitch']        !== null ? htmlspecialchars((string)$parsedInput['pitch'])      : '—' ?></div>
        <div><strong>fov (zoom)</strong>: <?= $parsedInput['zoom']    !== null ? htmlspecialchars((string)$parsedInput['zoom'])       : '—' ?></div>
      </div>

      <?php if ($notes): ?>
        <div class="kv" style="margin-top:6px;">
          <?php foreach ($notes as $n): ?>
            <div class="note">• <?= htmlspecialchars($n) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($generatedEmbedUrl): ?>
        <div class="kv" style="margin-top:10px;">
          <div><strong>Embed-Link (vollständig)</strong>:</div>
          <input type="text" value="<?= htmlspecialchars($generatedEmbedUrl, ENT_QUOTES, 'UTF-8') ?>" readonly onclick="this.select()">
          <div class="actions" style="margin-top:6px;">
            <a class="button" href="<?= htmlspecialchars($generatedEmbedUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Embed in neuem Tab öffnen</a>
          </div>
          <div style="margin-top:10px;"><strong>Vorschau</strong>:</div>
          <iframe src="<?= htmlspecialchars($generatedEmbedUrl, ENT_QUOTES, 'UTF-8') ?>" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
          <div style="margin-top:6px;"><small class="muted">Die Vorschau verwendet den vollständigen Embed-Link oben (v1/streetview).</small></div>
        </div>

        <!-- Save form -->
        <form method="post" class="actions" style="margin-top:12px;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="gmaps_url" value="<?= htmlspecialchars($inputUrlRaw, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="lat"     value="<?= htmlspecialchars((string)$parsedInput['latitude']) ?>">
          <input type="hidden" name="lng"     value="<?= htmlspecialchars((string)$parsedInput['longitude']) ?>">
          <input type="hidden" name="heading" value="<?= htmlspecialchars((string)$parsedInput['heading']) ?>">
          <input type="hidden" name="pitch"   value="<?= htmlspecialchars((string)$parsedInput['pitch']) ?>">
          <input type="hidden" name="zoom"    value="<?= htmlspecialchars((string)$parsedInput['zoom']) ?>">
          <button type="submit" name="action" value="save">Werte speichern</button>
        </form>
      <?php elseif ($parsedInput['latitude'] !== null && $parsedInput['longitude'] !== null): ?>
        <div class="kv" style="margin-top:10px;">
          <div><strong>Vorschau</strong>:</div>
          <div class="muted">Kein <code>GOOGLE_MAPS_API_KEY</code> definiert – Embed-Link/Preview kann nicht erzeugt werden.</div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</body>
</html>
