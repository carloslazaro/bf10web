<?php
/**
 * Driver API — returns stops for a given driver + date.
 * Also handles status updates from the driver app.
 *
 * GET  /api/driver.php?action=conductores        → list of drivers
 * GET  /api/driver.php?action=paradas&conductor=JOSE&fecha=2026-04-10  → stops
 * POST /api/driver.php  {action:"estado", id:123, estado:"recogida"}   → update status
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$pdo = getDB();

// Read action from GET, POST form, or JSON body
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode($rawBody, true);
    if ($jsonBody && isset($jsonBody['action'])) {
        $action = $jsonBody['action'];
    }
    // Store for later use so we don't re-read php://input
    $_REQUEST['_json'] = $jsonBody;
}

// Helper to get JSON body (already parsed or fresh)
function getJsonBody() {
    if (isset($_REQUEST['_json'])) return $_REQUEST['_json'];
    $body = json_decode(file_get_contents('php://input'), true);
    $_REQUEST['_json'] = $body;
    return $body;
}

// ── List drivers ─────────────────────────────────────────────
if ($action === 'conductores') {
    $rows = $pdo->query(
        "SELECT nombre FROM conductores WHERE activo = 1 ORDER BY nombre"
    )->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse(['conductores' => $rows]);
}

// ── List drivers (full info, admin only) ────────────────────
if ($action === 'conductores_full') {
    $rows = $pdo->query("
        SELECT c.id, c.nombre, c.pin, c.activo, c.created_at,
               cam.matricula AS camion_matricula, cam.modelo AS camion_modelo
        FROM conductores c
        LEFT JOIN camiones cam ON cam.conductor_habitual = c.nombre
        ORDER BY c.nombre
    ")->fetchAll();
    jsonResponse(['conductores' => $rows]);
}

// ── Create conductor (admin only) ───────────────────────────
if ($action === 'conductor_create') {
    $input = getJsonBody();
    $nombre = strtoupper(trim($input['nombre'] ?? ''));
    $pin    = trim($input['pin'] ?? '');
    $activo = isset($input['activo']) ? (int)$input['activo'] : 1;
    $camion_habitual = trim($input['camion_habitual'] ?? '');

    if (!$nombre) jsonResponse(['error' => 'Nombre requerido'], 400);
    if ($pin && strlen($pin) !== 4) jsonResponse(['error' => 'PIN debe tener 4 dígitos'], 400);

    // Check duplicate
    $chk = $pdo->prepare("SELECT id FROM conductores WHERE nombre = ?");
    $chk->execute([$nombre]);
    if ($chk->fetch()) jsonResponse(['error' => 'Ya existe un conductor con ese nombre'], 400);

    $stmt = $pdo->prepare("INSERT INTO conductores (nombre, pin, activo) VALUES (?, ?, ?)");
    $stmt->execute([$nombre, $pin ?: null, $activo]);
    $newId = (int)$pdo->lastInsertId();

    // Update camion habitual if specified
    if ($camion_habitual) {
        $pdo->prepare("UPDATE camiones SET conductor_habitual = NULL WHERE conductor_habitual = ?")->execute([$nombre]);
        $pdo->prepare("UPDATE camiones SET conductor_habitual = ? WHERE matricula = ?")->execute([$nombre, $camion_habitual]);
    }

    jsonResponse(['success' => true, 'id' => $newId]);
}

// ── Update conductor (admin only) ───────────────────────────
if ($action === 'conductor_update') {
    $input = getJsonBody();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    // Get old name
    $old = $pdo->prepare("SELECT nombre FROM conductores WHERE id = ?");
    $old->execute([$id]);
    $oldRow = $old->fetch();
    if (!$oldRow) jsonResponse(['error' => 'Conductor no encontrado'], 404);
    $oldName = $oldRow['nombre'];

    $sets = [];
    $params = [];

    if (isset($input['nombre']) && trim($input['nombre'])) {
        $newName = strtoupper(trim($input['nombre']));
        $sets[] = "nombre = ?";
        $params[] = $newName;
    } else {
        $newName = $oldName;
    }
    if (isset($input['pin'])) {
        $sets[] = "pin = ?";
        $params[] = trim($input['pin']) ?: null;
    }
    if (isset($input['activo'])) {
        $sets[] = "activo = ?";
        $params[] = (int)$input['activo'];
    }

    if ($sets) {
        $params[] = $id;
        $pdo->prepare("UPDATE conductores SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    // Update name in camion_conductor and camiones if name changed
    if ($newName !== $oldName) {
        $pdo->prepare("UPDATE camion_conductor SET conductor_nombre = ? WHERE conductor_nombre = ?")->execute([$newName, $oldName]);
        $pdo->prepare("UPDATE camiones SET conductor_habitual = ? WHERE conductor_habitual = ?")->execute([$newName, $oldName]);
    }

    // Update camion habitual if specified
    if (isset($input['camion_habitual'])) {
        $pdo->prepare("UPDATE camiones SET conductor_habitual = NULL WHERE conductor_habitual = ?")->execute([$newName]);
        if (trim($input['camion_habitual'])) {
            $pdo->prepare("UPDATE camiones SET conductor_habitual = ? WHERE matricula = ?")->execute([$newName, trim($input['camion_habitual'])]);
        }
    }

    jsonResponse(['success' => true]);
}

// ── Toggle conductor activo (admin only) ────────────────────
if ($action === 'conductor_toggle') {
    $input = getJsonBody();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
    $pdo->prepare("UPDATE conductores SET activo = NOT activo WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ── Verify PIN ──────────────────────────────────────────────
if ($action === 'login') {
    $input = getJsonBody();
    $nombre = strtoupper(trim($input['conductor'] ?? ''));
    $pin    = trim($input['pin'] ?? '');

    if (!$nombre || !$pin) {
        jsonResponse(['error' => 'Falta conductor o PIN'], 400);
    }

    $stmt = $pdo->prepare("SELECT pin FROM conductores WHERE nombre = :nombre AND activo = 1");
    $stmt->execute([':nombre' => $nombre]);
    $row = $stmt->fetch();

    if (!$row || $row['pin'] !== $pin) {
        jsonResponse(['ok' => false, 'error' => 'PIN incorrecto'], 401);
    }

    jsonResponse(['ok' => true, 'conductor' => $nombre]);
}

// ── Get stops for driver + date ──────────────────────────────
// Uses fecha_aviso as the date field.
// "por_recoger" shows stops from the selected day AND all previous days (accumulated).
// "recogida" and "no_estan" show only the selected day.
// "todas" shows everything for that day + accumulated pending from before.
if ($action === 'paradas') {
    $conductor = strtoupper(trim($_GET['conductor'] ?? ''));
    $fecha     = trim($_GET['fecha'] ?? '');  // YYYY-MM-DD format

    if (!$conductor) {
        jsonResponse(['error' => 'Falta conductor'], 400);
    }

    // Base WHERE for this conductor
    $baseWhere = "UPPER(conductor) = :conductor";

    // ── Pending (por_recoger): fecha_aviso <= selected date ──
    $pendingSql = "SELECT id, direccion, barrio_cp, sacos, conductor, estado,
                          fecha_de_ruta, orden, viaje, marca, observaciones, tlf_aviso,
                          urgen, avisador, hora_aviso, fecha_aviso,
                          lat, lng
                   FROM rutas_data
                   WHERE $baseWhere
                   AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')";
    $pendingParams = [':conductor' => $conductor];

    if ($fecha) {
        $pendingSql .= " AND (fecha_aviso <= :fecha OR fecha_aviso = '' OR fecha_aviso IS NULL)";
        $pendingParams[':fecha'] = $fecha;
    }
    $pendingSql .= " ORDER BY fecha_aviso ASC, CAST(orden AS UNSIGNED) ASC, id ASC";

    $stmt = $pdo->prepare($pendingSql);
    $stmt->execute($pendingParams);
    $pending = $stmt->fetchAll();

    // ── Recogida: same date logic (accumulated) ──
    $doneSql = "SELECT id, direccion, barrio_cp, sacos, conductor, estado,
                       fecha_de_ruta, orden, marca, observaciones, tlf_aviso,
                       urgen, avisador, hora_aviso, fecha_aviso
                FROM rutas_data
                WHERE $baseWhere AND estado = 'recogida'";
    $doneParams = [':conductor' => $conductor];

    if ($fecha) {
        $doneSql .= " AND (fecha_aviso <= :fecha OR fecha_aviso = '' OR fecha_aviso IS NULL)";
        $doneParams[':fecha'] = $fecha;
    }
    $doneSql .= " ORDER BY CAST(orden AS UNSIGNED) ASC, id ASC";

    $stmt = $pdo->prepare($doneSql);
    $stmt->execute($doneParams);
    $done = $stmt->fetchAll();

    // ── No estan: same date logic (accumulated) ──
    $absentSql = "SELECT id, direccion, barrio_cp, sacos, conductor, estado,
                         fecha_de_ruta, orden, marca, observaciones, tlf_aviso,
                         urgen, avisador, hora_aviso, fecha_aviso
                  FROM rutas_data
                  WHERE $baseWhere AND estado = 'no_estan'";
    $absentParams = [':conductor' => $conductor];

    if ($fecha) {
        $absentSql .= " AND (fecha_aviso <= :fecha OR fecha_aviso = '' OR fecha_aviso IS NULL)";
        $absentParams[':fecha'] = $fecha;
    }
    $absentSql .= " ORDER BY CAST(orden AS UNSIGNED) ASC, id ASC";

    $stmt = $pdo->prepare($absentSql);
    $stmt->execute($absentParams);
    $absent = $stmt->fetchAll();

    // Merge all for "todas" view
    $all = array_merge($pending, $done, $absent);

    jsonResponse([
        'todas'       => $all,
        'por_recoger' => $pending,
        'recogida'    => $done,
        'no_estan'    => $absent,
        'counts'      => [
            'total'       => array_sum(array_map(fn($s) => max(1,(int)($s['sacos']??1)), $all)),
            'por_recoger' => array_sum(array_map(fn($s) => max(1,(int)($s['sacos']??1)), $pending)),
            'recogida'    => array_sum(array_map(fn($s) => max(1,(int)($s['sacos']??1)), $done)),
            'no_estan'    => array_sum(array_map(fn($s) => max(1,(int)($s['sacos']??1)), $absent)),
        ]
    ]);
}

// ── Update stop status ───────────────────────────────────────
if ($action === 'estado') {
    $input = getJsonBody();
    $id     = (int)($input['id'] ?? 0);
    $estado = trim($input['estado'] ?? '');

    if (!$id) {
        jsonResponse(['error' => 'Falta id'], 400);
    }

    $valid = ['por_recoger', 'recogida', 'no_estan', ''];
    if (!in_array($estado, $valid)) {
        jsonResponse(['error' => 'Estado no válido'], 400);
    }

    if ($estado === 'recogida') {
        // Set fecha_recogida to today when marking as recogida
        $hoy = date('d/m/Y');
        $stmt = $pdo->prepare("UPDATE rutas_data SET estado = :estado, fecha_recogida = :fecha WHERE id = :id");
        $stmt->execute([':estado' => $estado, ':fecha' => $hoy, ':id' => $id]);
    } else {
        // Clear fecha_recogida when un-marking
        $stmt = $pdo->prepare("UPDATE rutas_data SET estado = :estado, fecha_recogida = '' WHERE id = :id");
        $stmt->execute([':estado' => $estado, ':id' => $id]);
    }

    jsonResponse(['ok' => true, 'id' => $id, 'estado' => $estado]);
}

// ── Swap order (move up/down) ────────────────────────────────
if ($action === 'reordenar') {
    $input = getJsonBody();
    $id        = (int)($input['id'] ?? 0);
    $direction = $input['direction'] ?? ''; // 'up' or 'down'

    if (!$id || !in_array($direction, ['up', 'down'])) {
        jsonResponse(['error' => 'Faltan parámetros'], 400);
    }

    // Get current stop info
    $stmt = $pdo->prepare("SELECT id, conductor, fecha_aviso, estado, orden FROM rutas_data WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $current = $stmt->fetch();

    if (!$current) {
        jsonResponse(['error' => 'Parada no encontrada'], 404);
    }

    // Get all siblings with same conductor + pending status, ordered as displayed
    $sql = "SELECT id FROM rutas_data
            WHERE UPPER(conductor) = UPPER(:conductor)
            AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')
            ORDER BY fecha_aviso ASC, CAST(orden AS UNSIGNED) ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':conductor' => $current['conductor']]);
    $siblings = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // First, normalize: assign sequential orden 1,2,3... to all siblings
    $updateStmt = $pdo->prepare("UPDATE rutas_data SET orden = :orden WHERE id = :id");
    foreach ($siblings as $i => $sid) {
        $updateStmt->execute([':orden' => $i + 1, ':id' => $sid]);
    }

    // Find current index in the list
    $idx = array_search($id, $siblings);

    if ($idx === false) {
        jsonResponse(['error' => 'No encontrada en lista'], 400);
    }

    $swapIdx = $direction === 'up' ? $idx - 1 : $idx + 1;

    if ($swapIdx < 0 || $swapIdx >= count($siblings)) {
        jsonResponse(['ok' => true, 'msg' => 'Ya está al límite']);
    }

    // Swap the two
    $updateStmt->execute([':orden' => $swapIdx + 1, ':id' => $siblings[$idx]]);
    $updateStmt->execute([':orden' => $idx + 1, ':id' => $siblings[$swapIdx]]);

    jsonResponse(['ok' => true, 'swapped' => [$siblings[$idx], $siblings[$swapIdx]]]);
}

// ── Bulk reorder (after drag & drop) ─────────────────────────
if ($action === 'reordenar_bulk') {
    $input = getJsonBody();
    $ids = $input['ids'] ?? [];  // array of IDs in new order

    if (empty($ids) || !is_array($ids)) {
        jsonResponse(['error' => 'Falta lista de IDs'], 400);
    }

    $stmt = $pdo->prepare("UPDATE rutas_data SET orden = :orden WHERE id = :id");
    foreach ($ids as $i => $id) {
        $stmt->execute([':orden' => $i + 1, ':id' => (int)$id]);
    }

    jsonResponse(['ok' => true, 'count' => count($ids)]);
}

// ── Get single stop detail ───────────────────────────────────
if ($action === 'detalle') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { jsonResponse(['error' => 'Falta id'], 400); }

    $stmt = $pdo->prepare("SELECT * FROM rutas_data WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) { jsonResponse(['error' => 'No encontrada'], 404); }
    jsonResponse(['parada' => $row]);
}

// ── Update stop fields (observaciones screen) ────────────────
if ($action === 'actualizar') {
    $input = getJsonBody();
    $id = (int)($input['id'] ?? 0);
    if (!$id) { jsonResponse(['error' => 'Falta id'], 400); }

    $allowed = ['sacos', 'marca', 'observaciones', 'viaje',
                'etiqueta_1', 'etiqueta_2', 'etiqueta_3',
                'etiqueta_4', 'etiqueta_5', 'etiqueta_6',
                'etiqueta_7', 'etiqueta_8', 'etiqueta_9',
                'etiqueta_10', 'etiqueta_11', 'etiqueta_12',
                'etiqueta_13', 'etiqueta_14', 'etiqueta_15'];

    $sets = [];
    $params = [':id' => $id];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $input[$field];
        }
    }

    if (empty($sets)) {
        jsonResponse(['error' => 'Nada que actualizar'], 400);
    }

    $sql = "UPDATE rutas_data SET " . implode(', ', $sets) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);

    jsonResponse(['ok' => true, 'id' => $id]);
}

// ── Delete stop ─────────────────────────────────────────────
if ($action === 'eliminar_parada') {
    $input = getJsonBody();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Falta id'], 400);

    $pdo->prepare("DELETE FROM rutas_data WHERE id = :id")->execute([':id' => $id]);
    jsonResponse(['ok' => true, 'id' => $id]);
}

// ── Quick set viaje ──────────────────────────────────────────
if ($action === 'set_viaje') {
    $input = getJsonBody();
    $id = (int)($input['id'] ?? 0);
    $viaje = trim($input['viaje'] ?? '');

    if (!$id) jsonResponse(['error' => 'Falta id'], 400);

    $valid = ['', 'Viaje 1', 'Viaje 2', 'Viaje 3', 'Viaje 4'];
    if (!in_array($viaje, $valid)) jsonResponse(['error' => 'Viaje no válido'], 400);

    $pdo->prepare("UPDATE rutas_data SET viaje = :viaje WHERE id = :id")
        ->execute([':viaje' => $viaje, ':id' => $id]);

    jsonResponse(['ok' => true, 'id' => $id, 'viaje' => $viaje]);
}

// ── Create new stop ─────────────────────────────────────────
if ($action === 'crear_parada') {
    $d = getJsonBody();
    $conductor = strtoupper(trim($d['conductor'] ?? ''));
    if (!$conductor) jsonResponse(['error' => 'Falta conductor'], 400);

    // Get max row_order
    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(row_order),0) FROM rutas_data")->fetchColumn();

    // Build etiqueta columns
    $etCols = '';
    $etVals = '';
    $etParams = [];
    for ($i = 1; $i <= 15; $i++) {
        $v = trim($d["etiqueta_$i"] ?? '');
        if ($v !== '' || $i <= (int)($d['sacos'] ?? 1)) {
            $etCols .= ", etiqueta_$i";
            $etVals .= ", :etiqueta_$i";
            $etParams[":etiqueta_$i"] = $v;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO rutas_data (row_order, direccion, barrio_cp, sacos, urgen, tlf_aviso,
            conductor, marca, observaciones, fecha_aviso, avisador, viaje $etCols)
        VALUES (:row_order, :direccion, :barrio_cp, :sacos, :urgen, :tlf_aviso,
            :conductor, :marca, :observaciones, :fecha_aviso, :avisador, :viaje $etVals)
    ");
    $stmt->execute(array_merge([
        ':row_order'     => $maxOrder + 1,
        ':direccion'     => trim($d['direccion'] ?? ''),
        ':barrio_cp'     => trim($d['barrio_cp'] ?? ''),
        ':sacos'         => (int)($d['sacos'] ?? 1),
        ':urgen'         => trim($d['urgen'] ?? ''),
        ':tlf_aviso'     => trim($d['tlf_aviso'] ?? ''),
        ':conductor'     => $conductor,
        ':marca'         => trim($d['marca'] ?? 'BF10'),
        ':observaciones' => trim($d['observaciones'] ?? ''),
        ':fecha_aviso'   => trim($d['fecha_aviso'] ?? date('d/m/Y')),
        ':avisador'      => trim($d['avisador'] ?? $conductor),
        ':viaje'         => trim($d['viaje'] ?? ''),
    ], $etParams));

    jsonResponse(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

// ── Auto-assign viajes (capacity from camion or default 13) ─
if ($action === 'crear_ruta') {
    $d = getJsonBody();
    $conductor = strtoupper(trim($d['conductor'] ?? ''));
    $fecha = trim($d['fecha'] ?? '');

    $forzar = !empty($d['forzar']);
    $maxViajes = (int)($d['max_viajes'] ?? 4);
    if ($maxViajes < 1) $maxViajes = 1;
    if ($maxViajes > 4) $maxViajes = 4;

    if (!$conductor) jsonResponse(['error' => 'Falta conductor'], 400);

    // Get camion capacity: from today's selection, or habitual, or default 13
    $MAX_SACAS = 13;
    $fechaCamion = $fecha ?: date('Y-m-d');
    $stmt = $pdo->prepare("SELECT c.capacidad_sacas FROM camion_conductor cc JOIN camiones c ON c.id = cc.camion_id WHERE cc.conductor_nombre = :c AND cc.fecha = :f");
    $stmt->execute([':c' => $conductor, ':f' => $fechaCamion]);
    $cap = $stmt->fetchColumn();
    if (!$cap || (int)$cap <= 0) {
        // Try habitual camion
        $stmt = $pdo->prepare("SELECT capacidad_sacas FROM camiones WHERE UPPER(conductor_habitual) = :c AND activo = 1");
        $stmt->execute([':c' => $conductor]);
        $cap = $stmt->fetchColumn();
    }
    if ($cap && (int)$cap > 0) $MAX_SACAS = (int)$cap;

    // Check if any stops already have viaje assigned
    if (!$forzar) {
        $chkSql = "SELECT COUNT(*) FROM rutas_data
                   WHERE UPPER(conductor) = :conductor
                   AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')
                   AND viaje != '' AND viaje IS NOT NULL";
        $chkParams = [':conductor' => $conductor];
        if ($fecha) {
            $chkSql .= " AND (fecha_aviso <= :fecha OR fecha_aviso = '' OR fecha_aviso IS NULL)";
            $chkParams[':fecha'] = $fecha;
        }
        $stmt = $pdo->prepare($chkSql);
        $stmt->execute($chkParams);
        $conViaje = (int)$stmt->fetchColumn();

        if ($conViaje > 0) {
            jsonResponse(['confirm' => true, 'con_viaje' => $conViaje,
                'msg' => "Hay $conViaje paradas con viaje asignado. Se reasignaran todas."]);
        }
    }

    // Get pending stops for this conductor
    $sql = "SELECT id, sacos, barrio_cp FROM rutas_data
            WHERE UPPER(conductor) = :conductor
            AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')";
    $params = [':conductor' => $conductor];
    if ($fecha) {
        $sql .= " AND (fecha_aviso <= :fecha OR fecha_aviso = '' OR fecha_aviso IS NULL)";
        $params[':fecha'] = $fecha;
    }
    $sql .= " ORDER BY barrio_cp ASC, fecha_aviso ASC, CAST(orden AS UNSIGNED) ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stops = $stmt->fetchAll();

    // ── Group by barrio ──
    $barrios = [];
    foreach ($stops as $s) {
        $b = trim($s['barrio_cp'] ?: 'Sin barrio');
        if (!isset($barrios[$b])) $barrios[$b] = ['sacas' => 0, 'stops' => []];
        $barrios[$b]['sacas'] += max(1, (int)$s['sacos']);
        $barrios[$b]['stops'][] = $s;
    }

    // Sort barrios by sacas descending (fill biggest first)
    uasort($barrios, fn($a, $b) => $b['sacas'] <=> $a['sacas']);

    // ── Distribute barrios into viajes (max 13 sacas, min viajes) ──
    $viajeSlots = [];
    for ($v = 1; $v <= $maxViajes; $v++) $viajeSlots[$v] = 0;
    $viajes = []; // id => viaje number

    foreach ($barrios as $barrio => $data) {
        $barrioSacas = $data['sacas'];

        // Try to fit entire barrio in one viaje
        $assigned = false;
        for ($v = 1; $v <= $maxViajes; $v++) {
            if ($viajeSlots[$v] + $barrioSacas <= $MAX_SACAS) {
                // Fits entirely in this viaje
                foreach ($data['stops'] as $s) {
                    $viajes[$s['id']] = $v;
                }
                $viajeSlots[$v] += $barrioSacas;
                $assigned = true;
                break;
            }
        }

        if (!$assigned) {
            // Barrio doesn't fit whole in any viaje — split it
            foreach ($data['stops'] as $s) {
                $sacas = max(1, (int)$s['sacos']);
                $placed = false;
                for ($v = 1; $v <= $maxViajes; $v++) {
                    if ($viajeSlots[$v] + $sacas <= $MAX_SACAS) {
                        $viajes[$s['id']] = $v;
                        $viajeSlots[$v] += $sacas;
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) {
                    // All viajes full — leave as SN (unassigned)
                    $viajes[$s['id']] = 0; // 0 = sin asignar
                }
            }
        }
    }

    // Separate assigned vs unassigned
    $assigned = array_filter($viajes, fn($v) => $v > 0);
    $unassigned = array_filter($viajes, fn($v) => $v === 0);

    // Compact: renumber assigned viajes to 1,2,3... without gaps
    $usedViajes = array_unique(array_values($assigned));
    sort($usedViajes);
    $remap = [];
    foreach ($usedViajes as $i => $v) {
        $remap[$v] = $i + 1;
    }
    foreach ($assigned as $id => $v) {
        $assigned[$id] = $remap[$v];
    }

    // Build result array
    $result = [];
    foreach ($assigned as $id => $v) {
        $result[] = ['id' => $id, 'viaje' => "Viaje $v"];
    }

    // Update assigned stops
    $upd = $pdo->prepare("UPDATE rutas_data SET viaje = :viaje WHERE id = :id");
    foreach ($result as $v) {
        $upd->execute([':viaje' => $v['viaje'], ':id' => $v['id']]);
    }

    // Clear viaje for unassigned stops
    $sinAsignarSacas = 0;
    foreach ($unassigned as $id => $v) {
        $upd->execute([':viaje' => '', ':id' => $id]);
        // Count sacas
        foreach ($stops as $s) {
            if ((int)$s['id'] === $id) { $sinAsignarSacas += max(1, (int)$s['sacos']); break; }
        }
    }

    // Count per viaje + sacas per viaje
    $summary = [];
    $sacasSummary = [];
    foreach ($result as $v) {
        $summary[$v['viaje']] = ($summary[$v['viaje']] ?? 0) + 1;
    }
    foreach ($remap as $old => $new) {
        if (isset($viajeSlots[$old])) $sacasSummary["Viaje $new"] = $viajeSlots[$old];
    }

    // Calculate km using Google Directions API
    $kmTotal = calcularKmRuta($pdo, $conductor, $fecha);

    // Cache the result
    if ($kmTotal > 0) {
        $fechaCache = $fecha ?: date('Y-m-d');
        $pdo->prepare("REPLACE INTO km_diarios (conductor, fecha, km_total, viajes_detalle) VALUES (:c, :f, :km, :det)")
            ->execute([':c' => $conductor, ':f' => $fechaCache, ':km' => $kmTotal, ':det' => json_encode($summary)]);
    }

    jsonResponse(['ok' => true, 'total' => count($result), 'viajes' => $summary, 'sacas' => $sacasSummary, 'capacidad' => $MAX_SACAS, 'km' => $kmTotal, 'sin_asignar' => $sinAsignarSacas]);
}

// ── Helper: calculate km for a conductor's daily route ───────
function calcularKmRuta($pdo, $conductor, $fecha) {
    $GMAPS_KEY = 'AIzaSyBgmkvCN-ZzZkxtdPQEyl4OaVvq10qu340';

    $SALIDAS = [
        'Viaje 1' => '40.35119,-3.562747',
        'Viaje 2' => '40.335487,-3.5917364',
        'Viaje 3' => '40.335487,-3.5917364',
        'Viaje 4' => '40.335487,-3.5917364',
    ];
    $DESTINO = '40.335487,-3.5917364'; // Vertedero

    // Get all pending stops grouped by viaje
    $sql = "SELECT id, lat, lng, viaje FROM rutas_data
            WHERE UPPER(conductor) = UPPER(:conductor)
            AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')
            AND viaje != '' AND lat IS NOT NULL AND lat != 0";
    $params = [':conductor' => $conductor];
    if ($fecha) {
        $sql .= " AND (fecha_aviso <= :fecha OR fecha_aviso = '' OR fecha_aviso IS NULL)";
        $params[':fecha'] = $fecha;
    }
    $sql .= " ORDER BY viaje ASC, CAST(orden AS UNSIGNED) ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Group by viaje
    $byViaje = [];
    foreach ($rows as $r) {
        $byViaje[$r['viaje']][] = $r;
    }

    $kmTotal = 0;

    foreach ($byViaje as $viaje => $stops) {
        $origin = $SALIDAS[$viaje] ?? $DESTINO;
        $waypoints = [];
        foreach ($stops as $s) {
            $waypoints[] = $s['lat'] . ',' . $s['lng'];
        }

        if (empty($waypoints)) continue;

        // Call Directions API with optimize
        $waypointsStr = 'optimize:true|' . implode('|', $waypoints);
        $url = "https://maps.googleapis.com/maps/api/directions/json?"
             . "origin=" . urlencode($origin)
             . "&destination=" . urlencode($DESTINO)
             . "&waypoints=" . urlencode($waypointsStr)
             . "&key=" . $GMAPS_KEY;

        $resp = @file_get_contents($url);
        if (!$resp) continue;

        $data = json_decode($resp, true);
        if (($data['status'] ?? '') !== 'OK') continue;

        // Sum all legs
        foreach ($data['routes'][0]['legs'] ?? [] as $leg) {
            $kmTotal += ($leg['distance']['value'] ?? 0) / 1000;
        }
    }

    return round($kmTotal, 1);
}

// ── Stats: sacas recogidas + paradas + ranking ──────────────
if ($action === 'stats') {
    $conductor = strtoupper(trim($_GET['conductor'] ?? ''));
    if (!$conductor) jsonResponse(['error' => 'Falta conductor'], 400);

    $today = date('d/m/Y');
    $monthPrefix = date('m/Y');
    $yearPrefix = date('Y');

    // Helper: count sacas and paradas for a conductor with a WHERE condition
    function countSacas($pdo, $conductor, $extraWhere, $extraParams = []) {
        $sql = "SELECT COALESCE(SUM(CAST(sacos AS UNSIGNED)),0) as sacas, COUNT(*) as paradas
                FROM rutas_data
                WHERE UPPER(conductor) = ? AND estado = 'recogida' $extraWhere";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$conductor], $extraParams));
        return $stmt->fetch();
    }

    // Today
    $hoy = countSacas($pdo, $conductor, "AND fecha_recogida = ?", [$today]);

    // This week
    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $dates[] = date('d/m/Y', strtotime("monday this week +$i days"));
    }
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $semana = countSacas($pdo, $conductor, "AND fecha_recogida IN ($ph)", $dates);

    // This month
    $mes = countSacas($pdo, $conductor, "AND fecha_recogida LIKE ?", ["%/$monthPrefix"]);

    // This year
    $anio = countSacas($pdo, $conductor, "AND fecha_recogida LIKE ?", ["%/$yearPrefix"]);

    // Ranking: all conductors this month, sorted by sacas desc
    $rankStmt = $pdo->prepare("
        SELECT UPPER(conductor) as nombre,
               COALESCE(SUM(CAST(sacos AS UNSIGNED)),0) as sacas,
               COUNT(*) as paradas
        FROM rutas_data
        WHERE estado = 'recogida' AND fecha_recogida LIKE ?
        AND conductor IS NOT NULL AND conductor != ''
        GROUP BY UPPER(conductor)
        ORDER BY sacas DESC
    ");
    $rankStmt->execute(["%/$monthPrefix"]);
    $ranking = $rankStmt->fetchAll();

    // Find position
    $posicion = 0;
    foreach ($ranking as $i => $r) {
        if ($r['nombre'] === $conductor) { $posicion = $i + 1; break; }
    }
    $totalConductores = count($ranking);

    // Km today (from cache)
    $todayISO = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT km_total FROM km_diarios WHERE conductor = :c AND fecha = :f");
    $stmt->execute([':c' => $conductor, ':f' => $todayISO]);
    $kmHoy = (float)($stmt->fetchColumn() ?: 0);

    // Km this month
    $monthISO = date('Y-m');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(km_total),0) FROM km_diarios WHERE conductor = :c AND fecha LIKE :m");
    $stmt->execute([':c' => $conductor, ':m' => "$monthISO%"]);
    $kmMes = (float)$stmt->fetchColumn();

    jsonResponse([
        'hoy'     => ['sacas' => (int)$hoy['sacas'], 'paradas' => (int)$hoy['paradas']],
        'semana'  => ['sacas' => (int)$semana['sacas'], 'paradas' => (int)$semana['paradas']],
        'mes'     => ['sacas' => (int)$mes['sacas'], 'paradas' => (int)$mes['paradas']],
        'anio'    => ['sacas' => (int)$anio['sacas'], 'paradas' => (int)$anio['paradas']],
        'ranking' => ['posicion' => $posicion, 'total' => $totalConductores, 'top' => array_slice($ranking, 0, 5)],
        'km_hoy'  => round($kmHoy, 1),
        'km_mes'  => round($kmMes, 1),
    ]);
}

// ── List camiones ───────────────────────────────────────────
if ($action === 'camiones') {
    $rows = $pdo->query("SELECT id, matricula, modelo, conductor_habitual FROM camiones WHERE activo = 1 ORDER BY matricula")->fetchAll();
    jsonResponse(['camiones' => $rows]);
}

// ── Get/set camion for a conductor on a date ────────────────
if ($action === 'camion_dia') {
    $conductor = strtoupper(trim($_GET['conductor'] ?? ''));
    $fecha = trim($_GET['fecha'] ?? date('Y-m-d'));

    if (!$conductor) jsonResponse(['error' => 'Falta conductor'], 400);

    // Check if already selected today
    $stmt = $pdo->prepare("SELECT cc.camion_id, c.matricula, c.modelo
        FROM camion_conductor cc
        JOIN camiones c ON c.id = cc.camion_id
        WHERE cc.conductor_nombre = :c AND cc.fecha = :f");
    $stmt->execute([':c' => $conductor, ':f' => $fecha]);
    $row = $stmt->fetch();

    if ($row) {
        jsonResponse(['selected' => true, 'camion_id' => (int)$row['camion_id'], 'matricula' => $row['matricula'], 'modelo' => $row['modelo']]);
    }

    // No selection yet, find habitual
    $stmt = $pdo->prepare("SELECT id, matricula, modelo FROM camiones WHERE UPPER(conductor_habitual) = :c AND activo = 1");
    $stmt->execute([':c' => $conductor]);
    $habitual = $stmt->fetch();

    jsonResponse(['selected' => false, 'habitual' => $habitual ?: null]);
}

// ── Save camion selection for the day ───────────────────────
if ($action === 'set_camion') {
    $d = getJsonBody();
    $conductor = strtoupper(trim($d['conductor'] ?? ''));
    $camionId = (int)($d['camion_id'] ?? 0);
    $fecha = trim($d['fecha'] ?? date('Y-m-d'));

    if (!$conductor || !$camionId) jsonResponse(['error' => 'Faltan datos'], 400);

    // Upsert
    $pdo->prepare("DELETE FROM camion_conductor WHERE conductor_nombre = :c AND fecha = :f")
        ->execute([':c' => $conductor, ':f' => $fecha]);
    $pdo->prepare("INSERT INTO camion_conductor (camion_id, conductor_nombre, fecha) VALUES (:cid, :c, :f)")
        ->execute([':cid' => $camionId, ':c' => $conductor, ':f' => $fecha]);

    jsonResponse(['ok' => true]);
}

// ── Login by PIN only (no conductor selection) ──────────────
if ($action === 'login_pin') {
    $input = getJsonBody();
    $pin = trim($input['pin'] ?? '');

    if (!$pin || strlen($pin) !== 4) {
        jsonResponse(['error' => 'PIN inválido'], 400);
    }

    $stmt = $pdo->prepare("SELECT nombre, pin FROM conductores WHERE pin = :pin AND activo = 1");
    $stmt->execute([':pin' => $pin]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(['ok' => false, 'error' => 'PIN incorrecto'], 401);
    }

    jsonResponse(['ok' => true, 'conductor' => $row['nombre']]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
