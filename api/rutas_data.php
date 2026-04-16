<?php
/**
 * Rutas data API — CRUD + Google Sheets sync.
 *
 * GET  ?action=list                — all rows ordered by row_order
 * POST ?action=update-cell         — update a single cell { id, field, value }
 * POST ?action=insert-row          — insert empty row { after_id } (or 0 for top)
 * POST ?action=delete-row          — delete row { id }
 * POST ?action=import-from-sheets  — read Excel → replace all DB rows
 * POST ?action=export-to-sheets    — write DB rows → overwrite Excel
 * POST ?action=geocode-batch       — geocode rows with lat=NULL (batch of ~600)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_sheets.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || (!isRutas() && !isManager() && !isFacturacion())) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$SPREADSHEET_READ  = '1vgHxxWdyxj1rjwZjIvOwpVO1Il8uNL0LDR7Z43nq23k'; // Abril 2026 (origen, solo lectura)
$SPREADSHEET_WRITE = '1bAvqZrEpf-vjTQ9sJ7diOulRL3tB2wD0qWA8nl4Zo0s'; // Copia Abril 2026 (destino escritura)
$SHEET_NAME = 'Madrid+Pueblos';

// ---------- Geocoding helper ----------
define('GEOCODE_API_KEY', 'AIzaSyBgmkvCN-ZzZkxtdPQEyl4OaVvq10qu340');

function buildGeocodeQuery($direccion, $barrio) {
    $addr = preg_replace('/\b\d{6,}\b/', '', $direccion); // quitar teléfonos
    $addr = preg_replace('/ubicacion/i', '', $addr);
    $addr = preg_replace('/\s+/', ' ', trim($addr));
    $addr = rtrim($addr, '-/ ');
    if ($addr === '') return null;
    $query = $addr;
    if ($barrio) $query .= ', ' . $barrio;
    $query .= ', Madrid, Spain';
    return $query;
}

function geocodeAddress($direccion, $barrio) {
    $pdo = getDB();
    $query = buildGeocodeQuery($direccion, $barrio);
    if (!$query) return null;

    $hash = md5(mb_strtolower($query));

    // Check cache first
    $stmt = $pdo->prepare("SELECT lat, lng FROM geocode_cache WHERE address_hash = ?");
    $stmt->execute([$hash]);
    $cached = $stmt->fetch();
    if ($cached !== false) {
        // Cache hit (even if lat/lng are NULL — means we already tried and it was imprecise)
        return ($cached['lat'] !== null) ? ['lat' => $cached['lat'], 'lng' => $cached['lng']] : null;
    }

    // Call Google Geocoding API
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
         . urlencode($query) . '&key=' . GEOCODE_API_KEY;

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        // Network error — don't cache, retry later
        return null;
    }
    $data = json_decode($resp, true);

    $lat = null;
    $lng = null;
    $locType = null;

    if (($data['status'] ?? '') === 'OK' && !empty($data['results'])) {
        $r = $data['results'][0];
        $locType = $r['geometry']['location_type'] ?? '';
        if (in_array($locType, ['ROOFTOP', 'RANGE_INTERPOLATED'])) {
            $lat = $r['geometry']['location']['lat'];
            $lng = $r['geometry']['location']['lng'];
        }
    }

    // Save to cache (including NULL results to avoid re-querying imprecise addresses)
    $ins = $pdo->prepare("
        INSERT INTO geocode_cache (address_hash, query_text, lat, lng, location_type)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng), location_type=VALUES(location_type)
    ");
    $ins->execute([$hash, $query, $lat, $lng, $locType]);

    return ($lat !== null) ? ['lat' => $lat, 'lng' => $lng] : null;
}

// Column mapping: DB field → sheet column index (0-based)
// Index 6 = 'etiquetas' in Excel — parsed into etiqueta_1..15 during import
// $COLUMNS maps Excel column positions (A, B, C...) to DB fields.
// 'interior' and 'telefono2' are DB-only fields, NOT in the Excel.
$COLUMNS = [
    'direccion', 'barrio_cp', 'sacos', 'urgen', 'tlf_aviso',
    'conductor', '_etiquetas_raw_', 'marca', 'observaciones', 'fecha_recogida',
    'avisador', 'hora_aviso', 'fecha_aviso', '_skip_', 'fecha_de_ruta',
    'estado', 'orden', 'swap_pending', 'swap_orden',
    'etiqueta_1', 'etiqueta_2', 'etiqueta_3', 'etiqueta_4', 'etiqueta_5', 'etiqueta_6',
    'etiqueta_7', 'etiqueta_8', 'etiqueta_9', 'etiqueta_10', 'etiqueta_11',
    'etiqueta_12', 'etiqueta_13', 'etiqueta_14', 'etiqueta_15',
];

// Parse etiquetas string into array of up to 15 labels
function parseEtiquetas($raw) {
    $raw = trim($raw);
    if ($raw === '') return [];
    $raw = preg_replace('/\.{2,}/', '', $raw);
    $parts = preg_split('/\s+/', $raw);
    $result = [];
    for ($i = 0; $i < count($parts); $i++) {
        $token = $parts[$i];
        if (strtoupper($token) === 'S' && isset($parts[$i + 1]) && preg_match('/^\d/', $parts[$i + 1])) {
            $result[] = 'S ' . $parts[$i + 1];
            $i++;
            continue;
        }
        if (strtoupper($token) === 'S/N') { $result[] = $token; continue; }
        if (preg_match('/\d/', $token)) { $result[] = $token; continue; }
    }
    return array_slice($result, 0, 15);
}

// Normalize marca from Excel abbreviations to CRM brand codes
function normalizeMarca($raw) {
    $m = strtoupper(trim($raw));
    $map = [
        'ATS'       => 'ATUSACO',
        'ATU'       => 'ATUSACO',
        'SERV'      => 'SERVISACO',
        'ECO'       => 'ECOSACO',
        'SB'        => 'SACAS_BLANCAS',
        'UTE'       => 'UTE-EXTRAMAD',
        'EXTRAMAD'  => 'UTE-EXTRAMAD',
        'UTE '      => 'UTE-EXTRAMAD',
    ];
    return $map[$m] ?? $raw;
}

// ---------- GET: List ----------
if ($method === 'GET' && $action === 'list') {
    $filter = $_GET['filter'] ?? '';
    if ($filter === 'pendientes') {
        $stmt = $pdo->query("SELECT * FROM rutas_data WHERE (estado = '' OR estado IS NULL) ORDER BY row_order ASC, id ASC");
    } else if ($filter === 'sin_ruta') {
        $stmt = $pdo->query("
            SELECT * FROM rutas_data
            WHERE (estado = '' OR estado IS NULL OR estado = 'por_recoger')
              AND (conductor = '' OR conductor IS NULL)
              AND NOT (UPPER(TRIM(direccion)) LIKE 'DIA %' AND (sacos IS NULL OR sacos = '' OR sacos = '0'))
            ORDER BY row_order ASC, id ASC
        ");
    } else if ($filter === 'recogidas') {
        $stmt = $pdo->query("SELECT * FROM rutas_data WHERE estado IS NOT NULL AND estado != '' ORDER BY row_order ASC, id ASC");
    } else {
        $stmt = $pdo->query("SELECT * FROM rutas_data ORDER BY row_order ASC, id ASC");
    }
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

// ---------- POST: Update cell ----------
if ($method === 'POST' && $action === 'update-cell') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? '';

    // Allow editing both Excel-mapped columns and DB-only fields
    $editableFields = array_merge($COLUMNS, ['interior', 'telefono2']);
    if (!$id || !in_array($field, $editableFields) || $field === '_skip_' || $field === '_etiquetas_raw_') {
        jsonResponse(['error' => 'ID y campo válido requeridos'], 400);
    }

    $pdo->prepare("UPDATE rutas_data SET `$field` = ? WHERE id = ?")->execute([$value, $id]);
    jsonResponse(['success' => true]);
}

// ---------- POST: Insert row ----------
if ($method === 'POST' && $action === 'insert-row') {
    $data = json_decode(file_get_contents('php://input'), true);
    $afterId = (int)($data['after_id'] ?? 0);

    if ($afterId > 0) {
        // Get row_order of the reference row
        $ref = $pdo->prepare("SELECT row_order FROM rutas_data WHERE id = ?");
        $ref->execute([$afterId]);
        $refRow = $ref->fetch();
        $newOrder = $refRow ? (int)$refRow['row_order'] + 1 : 0;
        // Shift all rows after this
        $pdo->prepare("UPDATE rutas_data SET row_order = row_order + 1 WHERE row_order >= ?")->execute([$newOrder]);
    } else {
        // Insert at top (before first row)
        $pdo->exec("UPDATE rutas_data SET row_order = row_order + 1");
        $newOrder = 0;
    }

    $pdo->prepare("INSERT INTO rutas_data (row_order) VALUES (?)")->execute([$newOrder]);
    $newId = $pdo->lastInsertId();

    jsonResponse(['success' => true, 'id' => $newId, 'row_order' => $newOrder]);
}

// ---------- POST: Reorder row (move row to new position) ----------
if ($method === 'POST' && $action === 'reorder') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    $afterId = (int)($data['after_id'] ?? -1); // -1 = not provided, 0 = move to top

    if (!$id || $afterId < 0) jsonResponse(['error' => 'ID y after_id requeridos'], 400);

    // Remove current row from ordering
    $cur = $pdo->prepare("SELECT row_order FROM rutas_data WHERE id = ?");
    $cur->execute([$id]);
    $curRow = $cur->fetch();
    if (!$curRow) jsonResponse(['error' => 'Fila no encontrada'], 404);

    if ($afterId > 0) {
        $ref = $pdo->prepare("SELECT row_order FROM rutas_data WHERE id = ?");
        $ref->execute([$afterId]);
        $refRow = $ref->fetch();
        if (!$refRow) jsonResponse(['error' => 'Fila destino no encontrada'], 404);
        $newOrder = (int)$refRow['row_order'] + 1;
        // Shift rows to make space
        $pdo->prepare("UPDATE rutas_data SET row_order = row_order + 1 WHERE row_order >= ? AND id != ?")->execute([$newOrder, $id]);
    } else {
        // Move to top
        $pdo->prepare("UPDATE rutas_data SET row_order = row_order + 1 WHERE id != ?")->execute([$id]);
        $newOrder = 0;
    }

    $pdo->prepare("UPDATE rutas_data SET row_order = ? WHERE id = ?")->execute([$newOrder, $id]);
    jsonResponse(['success' => true]);
}

// ---------- POST: Delete row ----------
if ($method === 'POST' && $action === 'delete-row') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo->prepare("DELETE FROM rutas_data WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ---------- POST: Import from Sheets ----------
if ($method === 'POST' && $action === 'import-from-sheets') {
    $range = "'" . $SHEET_NAME . "'!A:Z";
    $rows = sheetsRead($SPREADSHEET_READ, $range);

    if (isset($rows['error'])) {
        jsonResponse(['error' => 'Error leyendo sheets: ' . $rows['error']], 500);
    }

    // First row is header, skip it
    if (empty($rows) || count($rows) < 2) {
        jsonResponse(['error' => 'No hay datos en el sheet'], 400);
    }

    // Truncate table
    $pdo->exec("DELETE FROM rutas_data");
    $pdo->exec("ALTER TABLE rutas_data AUTO_INCREMENT = 1");

    // Build insert (filter out _skip_ and _etiquetas_raw_ columns)
    $dbColumns = [];
    $sheetIndexes = [];
    $etiquetasRawSheetIdx = null;
    foreach ($COLUMNS as $idx => $col) {
        if ($col === '_etiquetas_raw_') {
            $etiquetasRawSheetIdx = $idx; // remember sheet col for etiquetas parsing
            continue;
        }
        if ($col === '_skip_') continue;
        $dbColumns[] = $col;
        $sheetIndexes[] = $idx;
    }
    // Add 'etiquetas' as extra DB column (populated from _etiquetas_raw_ Excel column)
    $dbColumns[] = 'etiquetas';
    $colList = implode(', ', array_map(function($c) { return "`$c`"; }, $dbColumns));
    $placeholders = implode(', ', array_fill(0, count($dbColumns), '?'));
    $ins = $pdo->prepare("INSERT INTO rutas_data (row_order, $colList) VALUES (?, $placeholders)");
    $etiquetasDbIdx = count($dbColumns) - 1; // index of 'etiquetas' in $dbColumns

    // Find etiqueta_1..15 positions in $dbColumns for overwriting parsed values
    $etiquetaDbIndexes = [];
    $marcaDbIndex = null;
    foreach ($dbColumns as $ci => $col) {
        if (preg_match('/^etiqueta_(\d+)$/', $col, $m)) {
            $etiquetaDbIndexes[(int)$m[1]] = $ci;
        }
        if ($col === 'marca') $marcaDbIndex = $ci;
    }

    // Find indexes for fecha_recogida and estado in $dbColumns
    $idxFechaRecogida = array_search('fecha_recogida', $dbColumns);
    $idxEstado = array_search('estado', $dbColumns);

    // First pass: build filtered list of valid rows
    $validRows = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i] ?? [];
        $direccion = isset($row[0]) ? trim($row[0]) : '';
        $barrio    = isset($row[1]) ? trim($row[1]) : '';
        // Skip completely empty rows
        if ($direccion === '' && $barrio === '') continue;
        $validRows[] = $row;
    }

    // Second pass: remove day-rows followed by another day-row (no real addresses after them)
    $finalRows = [];
    for ($i = 0; $i < count($validRows); $i++) {
        $dir = strtolower(trim($validRows[$i][0] ?? ''));
        $bar = trim($validRows[$i][1] ?? '');
        $isDayRow = (strpos($dir, 'dia') === 0) && $bar === '';

        if ($isDayRow) {
            // Check if next valid row is also a day-row
            $nextDir = isset($validRows[$i + 1]) ? strtolower(trim($validRows[$i + 1][0] ?? '')) : '';
            $nextBar = isset($validRows[$i + 1]) ? trim($validRows[$i + 1][1] ?? '') : '';
            $nextIsDayRow = (strpos($nextDir, 'dia') === 0) && $nextBar === '';
            if ($nextIsDayRow || !isset($validRows[$i + 1])) {
                // Skip this day-row (next is also a day or it's the last row)
                continue;
            }
        }
        $finalRows[] = $validRows[$i];
    }

    // Third pass: insert
    $imported = 0;
    $skipped = count($rows) - 1 - count($finalRows); // header excluded
    $order = 0;
    for ($i = 0; $i < count($finalRows); $i++) {
        $row = $finalRows[$i];
        $order++;
        $params = [$order]; // row_order
        foreach ($sheetIndexes as $sheetCol) {
            $params[] = isset($row[$sheetCol]) ? $row[$sheetCol] : '';
        }
        // Add raw etiquetas string to 'etiquetas' DB field
        $rawEtiq = ($etiquetasRawSheetIdx !== null && isset($row[$etiquetasRawSheetIdx])) ? trim($row[$etiquetasRawSheetIdx]) : '';
        $params[] = $rawEtiq;

        // If fecha_recogida is filled, set estado = 'recogida'
        $fechaRecogida = trim($params[$idxFechaRecogida + 1] ?? ''); // +1 for row_order offset
        if ($fechaRecogida !== '') {
            $params[$idxEstado + 1] = 'recogida';
        }

        // Normalize marca from Excel abbreviations
        if ($marcaDbIndex !== null) {
            $paramIdx = $marcaDbIndex + 1; // +1 for row_order offset
            $params[$paramIdx] = normalizeMarca($params[$paramIdx]);
        }

        // Parse etiquetas raw from Excel column G into etiqueta_1..15
        if ($etiquetasRawSheetIdx !== null) {
            $rawEtiquetas = isset($row[$etiquetasRawSheetIdx]) ? $row[$etiquetasRawSheetIdx] : '';
            $parsed = parseEtiquetas($rawEtiquetas);
            // Overwrite etiqueta_1..15 params (only if individual fields from Excel are empty)
            for ($ei = 1; $ei <= 15; $ei++) {
                if (isset($etiquetaDbIndexes[$ei])) {
                    $paramIdx = $etiquetaDbIndexes[$ei] + 1; // +1 for row_order offset
                    $existingVal = trim($params[$paramIdx] ?? '');
                    // If the individual Excel column is empty, fill from parsed etiquetas
                    if ($existingVal === '' && isset($parsed[$ei - 1])) {
                        $params[$paramIdx] = $parsed[$ei - 1];
                    }
                }
            }
        }

        $ins->execute($params);
        $imported++;
    }

    jsonResponse(['success' => true, 'imported' => $imported, 'skipped' => $skipped]);
}

// ---------- POST: Export to Sheets ----------
if ($method === 'POST' && $action === 'export-to-sheets') {
    // 1. Read current content of destination sheet as backup
    $backupRange = "'" . $SHEET_NAME . "'!A:Z";
    $backupData = sheetsRead($SPREADSHEET_WRITE, $backupRange);
    if (!isset($backupData['error'])) {
        // Ensure backup table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rutas_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                row_count INT DEFAULT 0,
                data LONGTEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $backupJson = json_encode($backupData, JSON_UNESCAPED_UNICODE);
        $rowCount = is_array($backupData) ? count($backupData) : 0;
        $pdo->prepare("INSERT INTO rutas_backups (row_count, data) VALUES (?, ?)")
            ->execute([$rowCount, $backupJson]);
    }

    // 2. Read all rows from DB
    $stmt = $pdo->query("SELECT * FROM rutas_data ORDER BY row_order ASC, id ASC");
    $dbRows = $stmt->fetchAll();

    // Build header row
    $header = ['DIRECCION', 'BARRIO / C.P.', 'SACOS', 'URGEN', 'TLF AVISO',
        'CONDUCTOR', 'ETIQUETAS', 'MARCA', 'OBSERVACIONES', 'FECHA RECOG',
        'AVISADOR', 'HORA AVISO', 'FECHA AVISO', 'FECHA RECOGIDA', 'FECHA_DE_RUTA',
        'ESTADO', 'ORDEN', 'SWAP_PENDING', 'SWAP_ORDEN',
        'ETIQUETA_1', 'ETIQUETA_2', 'ETIQUETA_3', 'ETIQUETA_4', 'ETIQUETA_5', 'ETIQUETA_6',
        'ETIQUETA_7', 'ETIQUETA_8', 'ETIQUETA_9', 'ETIQUETA_10', 'ETIQUETA_11',
        'ETIQUETA_12', 'ETIQUETA_13', 'ETIQUETA_14', 'ETIQUETA_15'];

    $values = [$header];
    foreach ($dbRows as $r) {
        $row = [];
        foreach ($COLUMNS as $col) {
            if ($col === '_skip_') {
                $row[] = '';
            } elseif ($col === '_etiquetas_raw_') {
                // Reconstruct etiquetas string from individual fields for Excel column G
                $parts = [];
                for ($ei = 1; $ei <= 15; $ei++) {
                    $v = trim($r["etiqueta_$ei"] ?? '');
                    if ($v !== '') $parts[] = $v;
                }
                $row[] = implode(' ', $parts);
            } else {
                $row[] = $r[$col] ?? '';
            }
        }
        $values[] = $row;
    }

    // 3. Write to destination sheet
    $numCols = count($header);
    $range = "'" . $SHEET_NAME . "'!A1:AH" . (count($values) + 100);
    $clearResult = sheetsUpdate($SPREADSHEET_WRITE, $range, array_merge($values, array_fill(0, 100, array_fill(0, $numCols, ''))));
    if (isset($clearResult['error'])) {
        jsonResponse(['error' => 'Error escribiendo en sheets: ' . $clearResult['error']], 500);
    }

    jsonResponse(['success' => true, 'exported' => count($dbRows)]);
}

// ---------- GET: List backups ----------
if ($method === 'GET' && $action === 'backups') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rutas_backups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        row_count INT DEFAULT 0,
        data LONGTEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $pdo->query("SELECT id, created_at, row_count FROM rutas_backups ORDER BY id DESC LIMIT 20");
    jsonResponse(['backups' => $stmt->fetchAll()]);
}

// ---------- POST: Restore backup to destination sheet ----------
if ($method === 'POST' && $action === 'restore-backup') {
    $data = json_decode(file_get_contents('php://input'), true);
    $backupId = (int)($data['id'] ?? 0);
    if (!$backupId) jsonResponse(['error' => 'ID de backup requerido'], 400);

    $stmt = $pdo->prepare("SELECT * FROM rutas_backups WHERE id = ?");
    $stmt->execute([$backupId]);
    $backup = $stmt->fetch();
    if (!$backup) jsonResponse(['error' => 'Backup no encontrado'], 404);

    $rows = json_decode($backup['data'], true);
    if (!is_array($rows) || !count($rows)) jsonResponse(['error' => 'Backup vacío'], 400);

    // Write backup data back to destination sheet
    $range = "'" . $SHEET_NAME . "'!A1:Z" . (count($rows) + 100);
    $padded = array_merge($rows, array_fill(0, 100, array_fill(0, 26, '')));
    $result = sheetsUpdate($SPREADSHEET_WRITE, $range, $padded);
    if (isset($result['error'])) {
        jsonResponse(['error' => 'Error restaurando: ' . $result['error']], 500);
    }

    jsonResponse(['success' => true, 'restored_rows' => count($rows)]);
}

// ---------- GET: Check duplicate address (last 7 days) ----------
if ($method === 'GET' && $action === 'check-duplicate') {
    $dir = trim($_GET['direccion'] ?? '');
    if (!$dir) jsonResponse(['duplicates' => []]);

    $stmt = $pdo->prepare("
        SELECT id, direccion, barrio_cp, sacos, urgen, interior, tlf_aviso, telefono2, marca, observaciones, fecha_aviso, avisador, estado
        FROM rutas_data
        WHERE LOWER(TRIM(direccion)) = LOWER(TRIM(?))
          AND fecha_aviso >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute([$dir]);
    jsonResponse(['duplicates' => $stmt->fetchAll()]);
}

// ---------- POST: Create aviso de recogida ----------
if ($method === 'POST' && $action === 'create-aviso') {
    $data = json_decode(file_get_contents('php://input'), true);

    $direccion     = trim($data['direccion'] ?? '');
    $barrio_cp     = trim($data['barrio_cp'] ?? '');
    $sacos         = trim($data['sacos'] ?? '');
    $urgen         = trim($data['urgen'] ?? '');
    $interior      = trim($data['interior'] ?? '');
    $tlf_aviso     = trim($data['tlf_aviso'] ?? '');
    $telefono2     = trim($data['telefono2'] ?? '');
    $marca         = trim($data['marca'] ?? '');
    $observaciones = trim($data['observaciones'] ?? '');
    $fecha_aviso   = trim($data['fecha_aviso'] ?? date('Y-m-d'));
    $avisador      = trim($data['avisador'] ?? '');

    if (!$direccion && !$barrio_cp) {
        jsonResponse(['error' => 'Dirección o barrio requeridos'], 400);
    }

    // Insert at the end
    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(row_order),0) FROM rutas_data")->fetchColumn();
    $newOrder = $maxOrder + 1;

    // Build etiqueta fields
    $etiquetaCols = '';
    $etiquetaPlaceholders = '';
    $etiquetaValues = [];
    $etiquetasDisplay = trim($data['etiquetas'] ?? '');
    for ($ei = 1; $ei <= 15; $ei++) {
        $v = trim($data["etiqueta_$ei"] ?? '');
        if ($v !== '') {
            $etiquetaCols .= ", etiqueta_$ei";
            $etiquetaPlaceholders .= ', ?';
            $etiquetaValues[] = $v;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO rutas_data (row_order, direccion, barrio_cp, sacos, urgen, interior, tlf_aviso, telefono2, marca, observaciones, fecha_aviso, avisador, etiquetas$etiquetaCols)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?$etiquetaPlaceholders)
    ");
    $stmt->execute(array_merge([$newOrder, $direccion, $barrio_cp, $sacos, $urgen, $interior, $tlf_aviso, $telefono2, $marca, $observaciones, $fecha_aviso, $avisador, $etiquetasDisplay], $etiquetaValues));
    $newId = (int)$pdo->lastInsertId();

    // Geocode the new aviso
    if ($direccion !== '') {
        $coords = geocodeAddress($direccion, $barrio_cp);
        if ($coords) {
            $pdo->prepare("UPDATE rutas_data SET lat = ?, lng = ? WHERE id = ?")->execute([$coords['lat'], $coords['lng'], $newId]);
        }
    }

    jsonResponse(['success' => true, 'id' => $newId]);
}

// ---------- POST: Update existing aviso ----------
if ($method === 'POST' && $action === 'update-aviso') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $fields = ['sacos', 'urgen', 'interior', 'tlf_aviso', 'telefono2', 'marca', 'observaciones', 'avisador', 'barrio_cp'];
    $sets = [];
    $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "`$f` = ?";
            $params[] = trim($data[$f] ?? '');
        }
    }
    if (!$sets) jsonResponse(['error' => 'Nada que actualizar'], 400);

    // Always update fecha_aviso to today
    $sets[] = "fecha_aviso = ?";
    $params[] = date('Y-m-d');

    $params[] = $id;
    $pdo->prepare("UPDATE rutas_data SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    jsonResponse(['success' => true]);
}

// ---------- GET: Map data (lightweight) ----------
if ($method === 'GET' && $action === 'map-data') {
    $stmt = $pdo->query("
        SELECT id, direccion, barrio_cp, sacos, conductor, estado, marca, lat, lng, viaje
        FROM rutas_data
        WHERE lat IS NOT NULL AND lat != 0 AND lng IS NOT NULL AND lng != 0
        ORDER BY conductor, CAST(orden AS UNSIGNED) ASC
    ");
    $rows = $stmt->fetchAll();

    // Also return list of conductores for the filter
    $conductores = $pdo->query("SELECT DISTINCT conductor FROM rutas_data WHERE conductor != '' AND conductor IS NOT NULL ORDER BY conductor")->fetchAll(PDO::FETCH_COLUMN);

    jsonResponse(['stops' => $rows, 'conductores' => $conductores]);
}

// ---------- POST: Add day structure ----------
if ($method === 'POST' && $action === 'add-day') {
    $data = json_decode(file_get_contents('php://input'), true);
    $fecha = $data['fecha'] ?? date('Y-m-d');
    $mode = $data['mode'] ?? 'madrid';

    // Get max row_order
    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(row_order),0) FROM rutas_data")->fetchColumn();

    $insDir = $pdo->prepare("INSERT INTO rutas_data (row_order, direccion, fecha_de_ruta) VALUES (?, ?, ?)");
    $insBar = $pdo->prepare("INSERT INTO rutas_data (row_order, fecha_de_ruta, barrio_cp) VALUES (?, ?, ?)");
    $insEmpty = $pdo->prepare("INSERT INTO rutas_data (row_order, fecha_de_ruta) VALUES (?, ?)");
    $created = 0;
    $order = $maxOrder + 1;

    // Header row with date (in direccion field so it triggers day-row styling)
    $dayLabel = 'Dia ' . date('d/m/Y', strtotime($fecha));
    $insDir->execute([$order++, $dayLabel, $fecha]);
    $created++;

    if ($mode === 'pueblos') {
        // Pueblos mode: just 10 empty rows
        for ($i = 0; $i < 10; $i++) {
            $insEmpty->execute([$order++, $fecha]);
            $created++;
        }
    } else {
        // Madrid mode: barrios with 5 empty rows between each
        $barrios = [
            'Fuencarral-Mirasierra', 'Tetuan', 'Chamberi', 'Chamartin',
            'Salamanca', 'Retiro', 'Centro', 'Arganzuela', 'Ciudad Lineal',
            'S Blas-Canillejas-Barajas', 'Hortaleza-Sanchinarro', 'Moratalaz',
            'Vallecas', 'Pta Angel-Batan-Aluche-Camp.', 'Carabanchel',
            'Carabanchel Alto-Aguillas-4 Vientos', 'Usera', 'Villaverde',
        ];
        foreach ($barrios as $barrio) {
            $insBar->execute([$order++, $fecha, $barrio]);
            $created++;
            for ($i = 0; $i < 5; $i++) {
                $insEmpty->execute([$order++, $fecha]);
                $created++;
            }
        }
    }

    jsonResponse(['success' => true, 'created' => $created]);
}

// ---------- POST: Geocode batch ----------
// Processes rows with lat=NULL and non-empty direccion, in batches.
// Returns how many were processed and how many remain.
if ($method === 'POST' && $action === 'geocode-batch') {
    $batchSize = 600;

    // Find rows needing geocoding: non-day-rows with direccion but no lat
    $stmt = $pdo->query("
        SELECT id, direccion, barrio_cp
        FROM rutas_data
        WHERE lat IS NULL
          AND direccion IS NOT NULL AND direccion != ''
          AND NOT (LOWER(direccion) LIKE 'dia%' AND (barrio_cp IS NULL OR barrio_cp = ''))
        ORDER BY id ASC
        LIMIT $batchSize
    ");
    $rows = $stmt->fetchAll();

    $geocoded = 0;
    $cached = 0;
    $skipped = 0;
    $errors = 0;
    $update = $pdo->prepare("UPDATE rutas_data SET lat = ?, lng = ? WHERE id = ?");

    foreach ($rows as $row) {
        $coords = geocodeAddress($row['direccion'], $row['barrio_cp']);
        if ($coords) {
            $update->execute([$coords['lat'], $coords['lng'], $row['id']]);
            $geocoded++;
        } else {
            // Mark as processed (set lat=0, lng=0) so we don't retry
            // Actually leave NULL — the cache will prevent re-calling the API
            $skipped++;
        }
        usleep(50000); // 50ms between calls (cache hits are instant, only real API calls matter)
    }

    // Count remaining
    $remaining = (int)$pdo->query("
        SELECT COUNT(*) FROM rutas_data
        WHERE lat IS NULL
          AND direccion IS NOT NULL AND direccion != ''
          AND NOT (LOWER(direccion) LIKE 'dia%' AND (barrio_cp IS NULL OR barrio_cp = ''))
    ")->fetchColumn();

    jsonResponse([
        'success' => true,
        'processed' => count($rows),
        'geocoded' => $geocoded,
        'skipped' => $skipped,
        'remaining' => $remaining,
    ]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
