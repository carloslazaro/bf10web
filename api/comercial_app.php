<?php
/**
 * Comercial App API — PIN-based auth for PWA.
 * Wraps albaranes functionality without PHP sessions.
 *
 * GET  ?action=comerciales                     → list comerciales
 * POST {action:"login", nombre:.., pin:..}     → verify PIN, returns user_id
 * GET  ?action=list&user_id=N[&desde&hasta&pagado] → albaranes
 * GET  ?action=detail&id=N                     → single albarán
 * GET  ?action=next-code                       → next albarán code
 * POST {action:"create", user_id:N, ...}       → create albarán
 * POST {action:"update", id:N, ...}            → update albarán
 * POST {action:"delete", id:N}                 → soft-delete
 * POST {action:"restore", id:N}                → restore from trash
 * GET  ?action=trash                           → trashed albaranes
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$pdo = getDB();

// Read action from GET or JSON body
$action = $_GET['action'] ?? '';
$jsonBody = null;
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonBody = json_decode(file_get_contents('php://input'), true);
    $action = $jsonBody['action'] ?? '';
}
function getBody() {
    global $jsonBody;
    if ($jsonBody) return $jsonBody;
    $jsonBody = json_decode(file_get_contents('php://input'), true);
    return $jsonBody;
}

// ── List comerciales ─────────────────────────────────────────
if ($action === 'comerciales') {
    $rows = $pdo->query(
        "SELECT nombre FROM comerciales_pin WHERE activo = 1 ORDER BY nombre"
    )->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse(['comerciales' => $rows]);
}

// ── Login (verify PIN) ──────────────────────────────────────
if ($action === 'login') {
    $input = getBody();
    $pin    = trim($input['pin'] ?? '');

    if (!$pin) {
        jsonResponse(['error' => 'Falta PIN'], 400);
    }

    $stmt = $pdo->prepare("SELECT user_id, nombre, pin FROM comerciales_pin WHERE pin = :pin AND activo = 1");
    $stmt->execute([':pin' => $pin]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(['ok' => false, 'error' => 'PIN incorrecto'], 401);
    }

    jsonResponse(['ok' => true, 'nombre' => $row['nombre'], 'user_id' => (int)$row['user_id']]);
}

// ── Search customers ────────────────────────────────────────
if ($action === 'customers') {
    $q = sanitize($_GET['q'] ?? '');
    if (strlen($q) < 2) jsonResponse(['customers' => []]);
    $stmt = $pdo->prepare("SELECT id, name, phone, email, nif FROM customers WHERE name LIKE ? OR phone LIKE ? OR nif LIKE ? ORDER BY name LIMIT 8");
    $like = "%$q%";
    $stmt->execute([$like, $like, $like]);
    jsonResponse(['customers' => $stmt->fetchAll()]);
}

// ── Create customer (inline) ────────────────────────────────
if ($action === 'customer-create') {
    $d = getBody();
    $name = trim($d['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'Nombre requerido'], 400);
    $stmt = $pdo->prepare("INSERT INTO customers (name) VALUES (?)");
    $stmt->execute([$name]);
    jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name]);
}

// ── Next albarán code ───────────────────────────────────────
if ($action === 'next-code') {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT albaran_code FROM albaranes WHERE albaran_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["ALB-$year-%"]);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/ALB-\d{4}-(\d+)/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    jsonResponse(['code' => "ALB-$year-" . str_pad($next, 4, '0', STR_PAD_LEFT)]);
}

// ── List albaranes ──────────────────────────────────────────
if ($action === 'list') {
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) jsonResponse(['error' => 'user_id requerido'], 400);
    $desde = sanitize($_GET['desde'] ?? '');
    $hasta = sanitize($_GET['hasta'] ?? '');
    $pagado = $_GET['pagado'] ?? '';

    $where = ['a.deleted_at IS NULL', 'a.comercial_id = ?'];
    $params = [$userId];

    if ($desde) { $where[] = 'a.fecha_entrega >= ?'; $params[] = $desde; }
    if ($hasta) { $where[] = 'a.fecha_entrega <= ?'; $params[] = $hasta; }
    if ($pagado !== '') { $where[] = 'a.pagado = ?'; $params[] = (int)$pagado; }

    $sql = "SELECT a.*, u.name AS comercial_name
            FROM albaranes a
            LEFT JOIN users u ON u.id = a.comercial_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.fecha_entrega DESC, a.id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $albaranes = $stmt->fetchAll();

    // Stats
    $totals = ['count' => 0, 'sacas' => 0, 'importe' => 0, 'pagados' => 0];
    foreach ($albaranes as $a) {
        $totals['count']++;
        $totals['sacas'] += (int)$a['num_sacas'];
        $totals['importe'] += (float)$a['importe'];
        if ($a['pagado']) $totals['pagados']++;
    }

    jsonResponse(['albaranes' => $albaranes, 'stats' => $totals]);
}

// ── Detail ──────────────────────────────────────────────────
if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $stmt = $pdo->prepare("SELECT a.*, u.name AS comercial_name FROM albaranes a LEFT JOIN users u ON u.id = a.comercial_id WHERE a.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'No encontrado'], 404);

    jsonResponse(['albaran' => $row]);
}

// ── Create ──────────────────────────────────────────────────
if ($action === 'create') {
    $d = getBody();
    $userId = (int)($d['user_id'] ?? 0);
    if (!$userId) jsonResponse(['error' => 'Falta user_id'], 400);

    $required = ['num_sacas', 'cliente', 'fecha_entrega'];
    foreach ($required as $f) {
        if (empty($d[$f])) jsonResponse(['error' => "Campo obligatorio: $f"], 400);
    }

    // Generate code
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT albaran_code FROM albaranes WHERE albaran_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["ALB-$year-%"]);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/ALB-\d{4}-(\d+)/', $last, $m)) $next = (int)$m[1] + 1;
    $code = "ALB-$year-" . str_pad($next, 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO albaranes (albaran_code, comercial_id, num_sacas, marca,
            numeracion_inicial, numeracion_final, cliente, direccion_envio, fecha_entrega,
            forma_pago, pagado, precio, importe, comentarios)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $code, $userId, (int)$d['num_sacas'], sanitize($d['marca'] ?? 'BF10'),
        ($d['numeracion_inicial'] ?? '') !== '' ? (int)$d['numeracion_inicial'] : null,
        ($d['numeracion_final'] ?? '') !== '' ? (int)$d['numeracion_final'] : null,
        sanitize($d['cliente']), sanitize($d['direccion_envio'] ?? ''), sanitize($d['fecha_entrega']),
        sanitize($d['forma_pago'] ?? 'pendiente'), !empty($d['pagado']) ? 1 : 0,
        (float)($d['precio'] ?? 0), (float)($d['importe'] ?? 0), sanitize($d['comentarios'] ?? ''),
    ]);

    $albaranId = $pdo->lastInsertId();

    // Stock movement
    $pdo->prepare("INSERT INTO stock_movimientos (marca, tipo, cantidad, motivo, albaran_id, numeracion_inicial, numeracion_final, comentarios, user_id) VALUES (?, 'salida', ?, 'venta_albaran', ?, ?, ?, ?, ?)")
        ->execute([
            sanitize($d['marca'] ?? 'BF10'), (int)$d['num_sacas'], $albaranId,
            ($d['numeracion_inicial'] ?? '') !== '' ? (int)$d['numeracion_inicial'] : null,
            ($d['numeracion_final'] ?? '') !== '' ? (int)$d['numeracion_final'] : null,
            "Albarán $code — " . sanitize($d['cliente']), $userId,
        ]);

    jsonResponse(['ok' => true, 'id' => $albaranId, 'code' => $code], 201);
}

// ── Update ──────────────────────────────────────────────────
if ($action === 'update') {
    $d = getBody();
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $allowed = [
        'num_sacas' => 'int', 'marca' => 'str', 'numeracion_inicial' => 'int',
        'numeracion_final' => 'int', 'cliente' => 'str', 'direccion_envio' => 'str', 'fecha_entrega' => 'str',
        'forma_pago' => 'str', 'pagado' => 'bool', 'precio' => 'float', 'importe' => 'float', 'comentarios' => 'str',
    ];

    $fields = []; $params = [];
    foreach ($allowed as $col => $type) {
        if (array_key_exists($col, $d)) {
            $fields[] = "$col = ?";
            if ($type === 'int') $params[] = ($d[$col] !== null && $d[$col] !== '') ? (int)$d[$col] : null;
            elseif ($type === 'float') $params[] = (float)$d[$col];
            elseif ($type === 'bool') $params[] = !empty($d[$col]) ? 1 : 0;
            else $params[] = sanitize($d[$col] ?? '');
        }
    }
    if (!$fields) jsonResponse(['error' => 'Nada que actualizar'], 400);

    $params[] = $id;
    $pdo->prepare("UPDATE albaranes SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    // Sync stock movement
    $stmt = $pdo->prepare("SELECT * FROM albaranes WHERE id = ?");
    $stmt->execute([$id]);
    $alb = $stmt->fetch();
    if ($alb) {
        $pdo->prepare("
            UPDATE stock_movimientos
            SET cantidad = ?, marca = ?, numeracion_inicial = ?, numeracion_final = ?,
                comentarios = ?
            WHERE albaran_id = ?
        ")->execute([
            (int)$alb['num_sacas'], $alb['marca'], $alb['numeracion_inicial'],
            $alb['numeracion_final'],
            "Albarán " . $alb['albaran_code'] . " — " . $alb['cliente'], $id
        ]);
    }

    jsonResponse(['ok' => true]);
}

// ── Delete (soft) ───────────────────────────────────────────
if ($action === 'delete') {
    $d = getBody();
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo->prepare("UPDATE albaranes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
    $pdo->prepare("UPDATE stock_movimientos SET deleted_at = NOW() WHERE albaran_id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

// ── Restore ─────────────────────────────────────────────────
if ($action === 'restore') {
    $d = getBody();
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo->prepare("UPDATE albaranes SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

// ── Trash list ──────────────────────────────────────────────
if ($action === 'trash') {
    $stmt = $pdo->query("
        SELECT a.*, u.name AS comercial_name FROM albaranes a
        LEFT JOIN users u ON u.id = a.comercial_id
        WHERE a.deleted_at IS NOT NULL ORDER BY a.deleted_at DESC LIMIT 200
    ");
    jsonResponse(['albaranes' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
