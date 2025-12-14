<?php
// Robust JSON API for the frontend

// ---- Prevent stray output and standardize error handling ----
ob_start();
header_remove(); // in case something set headers before

// Make sure we always return JSON and never PHP notices/warnings
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Convert any PHP warning/notice/error to a JSON 500
set_error_handler(function ($severity, $message, $file, $line) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  // Clean any partial buffer
  if (ob_get_length()) { ob_clean(); }
  echo json_encode([
    'error'  => 'PHP error',
    'detail' => $message,
    'file'   => basename($file),
    'line'   => $line
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
});

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) { ob_clean(); }
    echo json_encode([
      'error'  => 'Fatal error',
      'detail' => $e['message'],
      'file'   => basename($e['file']),
      'line'   => $e['line']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
});

// ---- No-cache headers ----
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// ---- Helpers ----
function out($payload, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  if (ob_get_length()) { ob_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function prepare_and_bind($mysqli, $sql, $types = "", $params = []) {
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) return [null, "Prepare failed: ".$mysqli->error];
  if ($types !== "") {
    // PHP 7+ spread args; for older, build references (not needed if 7+)
    $stmt->bind_param($types, ...$params);
  }
  return [$stmt, null];
}

// ---- DB connect ----
$configPath = __DIR__ . '/dbconnect/config_local.php';
if (!is_file($configPath)) {
  out([
    'error'  => 'Config missing',
    'detail' => 'Create dbconnect/config_local.php from config_local.php.example and add your database credentials.'
  ], 500);
}

require $configPath; // defines DB_HOST, DB_USER, DB_PASSWORD, DB_NAME

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_errno) {
  out(['error' => 'DB connection failed', 'detail' => $mysqli->connect_error], 500);
}
$mysqli->set_charset('utf8mb4');

// ---- Routing ----
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

function resolve_art_visible_column($mysqli) {
  static $col = null;
  if ($col !== null) return $col;

  $candidates = ['art_visible', 'Art_visible', 'artVisible', 'ArtVisible', 'Art visible'];

  foreach ($candidates as $cand) {
    $esc = $mysqli->real_escape_string($cand);
    $res = $mysqli->query("SHOW COLUMNS FROM `building` LIKE '{$esc}'");
    if ($res && $res->num_rows > 0) {
      $col = $cand;
      return $col;
    }
  }

  return null;
}

// 1) Single record by id
if ($action === 'recordById') {
  $id = isset($_GET['id']) ? trim($_GET['id']) : '';
  if ($id === '' || !ctype_digit($id)) out(['error' => 'Missing/invalid id'], 400);

  $sql = "SELECT
            id,
            Title,
            art,
            architecture,
            all_architects,
            zipcode,
            Year_from,
            Year_to,
            latitude  AS lat,
            longitude AS lng,
            heading,
            pitch,
            zoom,
            url,
            streetviewlink
          FROM building
          WHERE id = ?
          LIMIT 1";
  [$stmt, $err] = prepare_and_bind($mysqli, $sql, "i", [(int)$id]);
  if ($err) out(['error' => $err], 500);
  if (!$stmt->execute()) out(['error' => 'Execute failed', 'detail' => $stmt->error], 500);
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  out($row ?: []);
}

// 2) Street View records
if ($action === 'fetchStreetView') {
  $sql = "SELECT id, Title, art, streetviewlink
          FROM building
          WHERE COALESCE(NULLIF(streetviewlink,''), '') <> ''";
  $res = $mysqli->query($sql);
  $rows = [];
  if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
  out($rows);
}

// 3) Map markers with filters
if ($action === 'mapMarkers') {
  $q        = isset($_GET['q']) ? trim($_GET['q']) : '';
  $zipcode  = isset($_GET['zipcode']) ? trim($_GET['zipcode']) : '';
  $decades  = isset($_GET['decades']) ? trim($_GET['decades']) : '';
  $withArt  = isset($_GET['with_art']) ? (int)$_GET['with_art'] : 0;

  $useGrouping = false; // set to true to group by (art, zipcode)

  $where  = ["COALESCE(NULLIF(b.streetviewlink,''), '') <> ''"];
  $types  = "";
  $params = [];

  if ($withArt === 1) {
    $where[] = "COALESCE(NULLIF(b.art,''), '') <> ''";
  }

  if ($q !== '') {
    $where[] = "(b.art LIKE ? OR b.Title LIKE ? OR b.architecture LIKE ? OR b.all_architects LIKE ?)";
    $types  .= "ssss";
    $like = "%".$q."%";
    array_push($params, $like, $like, $like, $like);
  }

  if ($zipcode !== '') {
    if (!ctype_digit($zipcode) || strlen($zipcode) < 3 || strlen($zipcode) > 5) {
      out(['error' => 'Invalid zipcode'], 400);
    }
    $where[] = "b.zipcode = ?";
    $types  .= "s";
    $params[] = $zipcode;
  }

  // decades format: "1910-1919,1950-1959"
  $decadeClauses = [];
  if ($decades !== '') {
    foreach (explode(',', $decades) as $r) {
      if (preg_match('/^(\d{4})-(\d{4})$/', $r, $m)) {
        $start = (int)$m[1];
        $end   = (int)$m[2];
        // overlap: Year_from <= end AND Year_to >= start
        $decadeClauses[] = "(b.Year_from <= ? AND b.Year_to >= ?)";
        $types  .= "ii";
        $params[] = $end;
        $params[] = $start;
      }
    }
  }
  if ($decadeClauses) $where[] = "(".implode(" OR ", $decadeClauses).")";

  $whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

  $selectCols = "
    b.id,
    b.Title,
    b.art,
    b.architecture,
    b.all_architects,
    b.zipcode,
    b.Year_from   AS Year_from,
    b.Year_to     AS Year_to,
    b.latitude    AS lat,
    b.longitude   AS lng,
    b.heading     AS heading,
    b.pitch       AS pitch,
    b.zoom        AS zoom,
    b.url         AS url,
    b.streetviewlink AS streetviewlink
  ";

  if ($useGrouping) {
    $sql = "SELECT $selectCols
            FROM building b
            JOIN (
              SELECT MIN(id) AS id
              FROM building
              $whereSql
              GROUP BY art, zipcode
            ) m ON m.id = b.id
            ORDER BY b.id ASC";
    [$stmt, $err] = prepare_and_bind($mysqli, $sql, $types, $params);
  } else {
    $sql = "SELECT $selectCols
            FROM building b
            $whereSql
            ORDER BY b.id ASC";
    [$stmt, $err] = prepare_and_bind($mysqli, $sql, $types, $params);
  }

  if ($err) out(['error'=>'Prepare failed', 'detail'=>$err], 500);
  if (!$stmt->execute()) out(['error'=>'Execute failed', 'detail'=>$stmt->error], 500);

  $res = $stmt->get_result();
  $rows = [];
  while ($row = $res->fetch_assoc()) { $rows[] = $row; }
  out($rows);
}

// 4) Distinct ZIP codes that have Street View links
if ($action === 'streetviewZipcodes') {
  $sql = "SELECT DISTINCT zipcode FROM building WHERE zipcode IS NOT NULL AND zipcode <> '' ORDER BY zipcode ASC";
  $res = $mysqli->query($sql);
  $zips = [];
  if ($res) { while ($row = $res->fetch_assoc()) { $zips[] = $row['zipcode']; } }
  out($zips);
}

// 5) Street View records by ZIP with art visibility flag
if ($action === 'streetviewByZip') {
  $zipcode  = isset($_GET['zipcode']) ? trim($_GET['zipcode']) : '';
  if ($zipcode === '' || !ctype_digit($zipcode)) {
    out(['error' => 'Missing/invalid zipcode'], 400);
  }

  $col = resolve_art_visible_column($mysqli);
  if ($col === null) {
    out(['error' => 'art_visible column not found'], 500);
  }
  $colSql = '`' . str_replace('`', '``', $col) . '`';

  $sql = "SELECT id, Title, art, architecture, zipcode, streetviewlink, {$colSql} AS art_visible
          FROM building
          WHERE zipcode = ? AND COALESCE(NULLIF(streetviewlink,''), '') <> ''
          ORDER BY id ASC";

  [$stmt, $err] = prepare_and_bind($mysqli, $sql, "s", [$zipcode]);
  if ($err) out(['error' => $err], 500);
  if (!$stmt->execute()) out(['error'=>'Execute failed', 'detail'=>$stmt->error], 500);
  $res = $stmt->get_result();
  $rows = [];
  while ($row = $res->fetch_assoc()) { $rows[] = $row; }
  out($rows);
}

// 6) Update art_visible flag
if ($action === 'updateArtVisible') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['error' => 'Method not allowed'], 405);
  }

  $id = isset($_POST['id']) ? trim($_POST['id']) : '';
  $visible = isset($_POST['visible']) ? trim($_POST['visible']) : '1';

  if ($id === '' || !ctype_digit($id)) {
    out(['error' => 'Missing/invalid id'], 400);
  }

  $visibleVal = ($visible === '1' || strtolower($visible) === 'true') ? 1 : 0;

  $col = resolve_art_visible_column($mysqli);
  if ($col === null) {
    out(['error' => 'art_visible column not found'], 500);
  }
  $colSql = '`' . str_replace('`', '``', $col) . '`';

  $sql = "UPDATE building SET {$colSql} = ? WHERE id = ? LIMIT 1";
  [$stmt, $err] = prepare_and_bind($mysqli, $sql, "ii", [$visibleVal, (int)$id]);
  if ($err) out(['error' => $err], 500);
  if (!$stmt->execute()) out(['error'=>'Execute failed', 'detail'=>$stmt->error], 500);

  out(['ok' => true, 'id' => (int)$id, 'visible' => $visibleVal]);
}

// Unknown route
out(['error' => 'Unknown action'], 400);
