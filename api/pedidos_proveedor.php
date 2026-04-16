<?php
/**
 * Pedidos a proveedor (purchase orders) API — admin only.
 *
 * GET  ?action=list          — active orders (non-recibido) + received at bottom
 * GET  ?action=detail&id=N   — single order
 * POST ?action=create        — create new order
 * POST ?action=update        — update existing order
 * POST ?action=change-state  — change state (auto stock on recibido)
 * POST ?action=delete        — soft-delete
 * POST ?action=restore       — restore from trash
 * GET  ?action=trash         — list trashed orders
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();

// ---------- Generate next code ----------
function nextPedidoCode() {
    $pdo = getDB();
    $year = date('Y');
    $stmt = $pdo->prepare("
        SELECT codigo FROM pedidos_proveedor
        WHERE codigo LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(["PED-$year-%"]);
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/PED-\d{4}-(\d+)/', $last, $m)) {
        $next = (int)$m[1] + 1;
    } else {
        $next = 1;
    }
    return "PED-$year-" . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ---------- GET: Distinct providers ----------
if ($method === 'GET' && $action === 'proveedores') {
    $stmt = $pdo->query("SELECT DISTINCT proveedor FROM pedidos_proveedor WHERE proveedor IS NOT NULL AND proveedor != '' AND deleted_at IS NULL ORDER BY proveedor ASC");
    $provs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse(['proveedores' => $provs]);
}

// ---------- GET: List ----------
if ($method === 'GET' && $action === 'list') {
    $marca = sanitize($_GET['marca'] ?? '');
    $estado = sanitize($_GET['estado'] ?? '');
    $proveedor = sanitize($_GET['proveedor'] ?? '');

    // Active orders (non-recibido)
    $where = ["p.deleted_at IS NULL", "p.estado != 'recibido'"];
    $params = [];
    if ($marca) { $where[] = 'p.marca = ?'; $params[] = $marca; }
    if ($estado) { $where[] = 'p.estado = ?'; $params[] = $estado; }
    if ($proveedor) { $where[] = 'p.proveedor = ?'; $params[] = $proveedor; }

    $sql = "SELECT p.*, u.name AS user_name FROM pedidos_proveedor p LEFT JOIN users u ON u.id = p.user_id";
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY p.fecha_pedido DESC, p.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $activos = $stmt->fetchAll();

    // Received orders
    $whereR = ["p.deleted_at IS NULL", "p.estado = 'recibido'"];
    $paramsR = [];
    if ($marca) { $whereR[] = 'p.marca = ?'; $paramsR[] = $marca; }
    if ($proveedor) { $whereR[] = 'p.proveedor = ?'; $paramsR[] = $proveedor; }

    $sqlR = "SELECT p.*, u.name AS user_name FROM pedidos_proveedor p LEFT JOIN users u ON u.id = p.user_id";
    $sqlR .= ' WHERE ' . implode(' AND ', $whereR);
    $sqlR .= ' ORDER BY p.fecha_real_entrega DESC, p.id DESC LIMIT 200';
    $stmtR = $pdo->prepare($sqlR);
    $stmtR->execute($paramsR);
    $recibidos = $stmtR->fetchAll();

    jsonResponse(['activos' => $activos, 'recibidos' => $recibidos]);
}

// ---------- GET: Detail ----------
if ($method === 'GET' && $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $stmt = $pdo->prepare("
        SELECT p.*, u.name AS user_name
        FROM pedidos_proveedor p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.id = ? AND p.deleted_at IS NULL
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    jsonResponse(['pedido' => $row]);
}

// ---------- POST: Create ----------
if ($method === 'POST' && $action === 'create') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) jsonResponse(['error' => 'JSON no válido'], 400);

    if (empty($data['marca']) || empty($data['cantidad'])) {
        jsonResponse(['error' => 'Marca y cantidad son obligatorios'], 400);
    }

    $code = nextPedidoCode();

    $stmt = $pdo->prepare("
        INSERT INTO pedidos_proveedor (
            codigo, marca, cantidad, numeracion_inicial, numeracion_final,
            proveedor, fecha_pedido, fecha_prevista_entrega, estado, comentarios, user_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $code,
        sanitize($data['marca']),
        (int)$data['cantidad'],
        $data['numeracion_inicial'] ? (int)$data['numeracion_inicial'] : null,
        $data['numeracion_final'] ? (int)$data['numeracion_final'] : null,
        sanitize($data['proveedor'] ?? ''),
        $data['fecha_pedido'] ?: null,
        $data['fecha_prevista_entrega'] ?: null,
        sanitize($data['estado'] ?? 'borrador'),
        sanitize($data['comentarios'] ?? ''),
        (int)$_SESSION['user_id'],
    ]);

    jsonResponse(['success' => true, 'id' => $pdo->lastInsertId(), 'code' => $code], 201);
}

// ---------- POST: Update ----------
if ($method === 'POST' && $action === 'update') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $chk = $pdo->prepare("SELECT id, estado FROM pedidos_proveedor WHERE id = ? AND deleted_at IS NULL");
    $chk->execute([$id]);
    if (!$chk->fetch()) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    $fields = [];
    $params = [];
    $allowed = [
        'marca' => 'str', 'cantidad' => 'int', 'numeracion_inicial' => 'int',
        'numeracion_final' => 'int', 'proveedor' => 'str', 'fecha_pedido' => 'date',
        'fecha_prevista_entrega' => 'date', 'fecha_real_entrega' => 'date',
        'comentarios' => 'str',
    ];

    foreach ($allowed as $col => $type) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            if ($type === 'int') $params[] = $data[$col] !== null && $data[$col] !== '' ? (int)$data[$col] : null;
            elseif ($type === 'date') $params[] = $data[$col] ?: null;
            else $params[] = sanitize($data[$col] ?? '');
        }
    }

    if (!$fields) jsonResponse(['error' => 'Nada que actualizar'], 400);

    $params[] = $id;
    $pdo->prepare("UPDATE pedidos_proveedor SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    jsonResponse(['success' => true]);
}

// ---------- POST: Change State ----------
if ($method === 'POST' && $action === 'change-state') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    $newState = sanitize($data['estado'] ?? '');

    if (!$id || !$newState) jsonResponse(['error' => 'ID y estado requeridos'], 400);

    $valid = ['borrador', 'pedido_hecho', 'en_almacen_proveedor', 'recibido'];
    if (!in_array($newState, $valid)) jsonResponse(['error' => 'Estado no válido'], 400);

    $chk = $pdo->prepare("SELECT * FROM pedidos_proveedor WHERE id = ? AND deleted_at IS NULL");
    $chk->execute([$id]);
    $pedido = $chk->fetch();
    if (!$pedido) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    // Update state
    $updates = "estado = ?";
    $params = [$newState];

    // If marking as recibido, set fecha_real_entrega to today
    if ($newState === 'recibido' && !$pedido['fecha_real_entrega']) {
        $updates .= ", fecha_real_entrega = CURDATE()";
    }
    // If moving OUT of recibido, clear fecha_real_entrega
    if ($newState !== 'recibido' && $pedido['estado'] === 'recibido') {
        $updates .= ", fecha_real_entrega = NULL";
    }

    $params[] = $id;
    $pdo->prepare("UPDATE pedidos_proveedor SET $updates WHERE id = ?")->execute($params);

    // Auto-create stock entrada when recibido
    if ($newState === 'recibido' && $pedido['estado'] !== 'recibido') {
        $stk = $pdo->prepare("
            INSERT INTO stock_movimientos (marca, tipo, cantidad, motivo, numeracion_inicial, numeracion_final, comentarios, user_id)
            VALUES (?, 'entrada', ?, 'compra', ?, ?, ?, ?)
        ");
        $stk->execute([
            $pedido['marca'],
            (int)$pedido['cantidad'],
            $pedido['numeracion_inicial'],
            $pedido['numeracion_final'],
            "Pedido " . $pedido['codigo'] . " — " . $pedido['proveedor'],
            (int)$_SESSION['user_id'],
        ]);
    }

    // Remove stock entrada when moving OUT of recibido
    if ($newState !== 'recibido' && $pedido['estado'] === 'recibido') {
        $comentarioBuscar = "Pedido " . $pedido['codigo'] . " — " . $pedido['proveedor'];
        $pdo->prepare("
            DELETE FROM stock_movimientos
            WHERE tipo = 'entrada' AND motivo = 'compra' AND comentarios = ? AND marca = ?
        ")->execute([$comentarioBuscar, $pedido['marca']]);
    }

    jsonResponse(['success' => true]);
}

// ---------- POST: Soft Delete ----------
if ($method === 'POST' && $action === 'delete') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo->prepare("UPDATE pedidos_proveedor SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ---------- POST: Restore ----------
if ($method === 'POST' && $action === 'restore') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo->prepare("UPDATE pedidos_proveedor SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ---------- GET: Trash ----------
if ($method === 'GET' && $action === 'trash') {
    $stmt = $pdo->query("
        SELECT p.*, u.name AS user_name
        FROM pedidos_proveedor p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.deleted_at IS NOT NULL
        ORDER BY p.deleted_at DESC LIMIT 200
    ");
    jsonResponse(['pedidos' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
