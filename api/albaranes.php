<?php
/**
 * Albaranes (delivery notes) API — for comercial users.
 *
 * GET  ?action=list          — all albaranes (all comerciales can see all)
 * GET  ?action=detail&id=N   — single albarán
 * POST ?action=create        — create new albarán
 * POST ?action=update        — update existing albarán
 * POST ?action=delete        — soft-delete (move to trash)
 * POST ?action=restore       — restore from trash
 * GET  ?action=trash         — list trashed albaranes
 * GET  ?action=next-code     — get next albarán code
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// All endpoints require logged-in comercial (or manager)
if (!isLoggedIn() || (!isComercial() && !isManager())) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();

// ---------- Generate next albarán code ----------
function nextAlbaranCode() {
    $pdo = getDB();
    $year = date('Y');
    $stmt = $pdo->prepare("
        SELECT albaran_code FROM albaranes
        WHERE albaran_code LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(["ALB-$year-%"]);
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/ALB-\d{4}-(\d+)/', $last, $m)) {
        $next = (int)$m[1] + 1;
    } else {
        $next = 1;
    }
    return "ALB-$year-" . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ---------- GET: Next code ----------
if ($method === 'GET' && $action === 'next-code') {
    jsonResponse(['code' => nextAlbaranCode()]);
}

// ---------- GET: List ----------
if ($method === 'GET' && $action === 'list') {
    $comercialId = $_GET['comercial_id'] ?? '';
    $desde = sanitize($_GET['desde'] ?? '');
    $hasta = sanitize($_GET['hasta'] ?? '');
    $pagado = $_GET['pagado'] ?? '';

    $where = ['a.deleted_at IS NULL'];
    $params = [];

    if ($comercialId) {
        $where[] = 'a.comercial_id = ?';
        $params[] = (int)$comercialId;
    }
    if ($desde) {
        $where[] = 'a.fecha_entrega >= ?';
        $params[] = $desde;
    }
    if ($hasta) {
        $where[] = 'a.fecha_entrega <= ?';
        $params[] = $hasta;
    }
    if ($pagado !== '') {
        $where[] = 'a.pagado = ?';
        $params[] = (int)$pagado;
    }

    $sql = "
        SELECT a.*, u.name AS comercial_name,
               inv.invoice_number AS invoice_number
        FROM albaranes a
        LEFT JOIN users u ON u.id = a.comercial_id
        LEFT JOIN invoices inv ON inv.albaran_id = a.id
    ";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.fecha_entrega DESC, a.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['albaranes' => $stmt->fetchAll()]);
}

// ---------- GET: Detail ----------
if ($method === 'GET' && $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $stmt = $pdo->prepare("
        SELECT a.*, u.name AS comercial_name
        FROM albaranes a
        LEFT JOIN users u ON u.id = a.comercial_id
        WHERE a.id = ? AND a.deleted_at IS NULL
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Albarán no encontrado'], 404);

    jsonResponse(['albaran' => $row]);
}

// ---------- POST: Create ----------
if ($method === 'POST' && $action === 'create') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) jsonResponse(['error' => 'JSON no válido'], 400);

    $required = ['num_sacas', 'cliente', 'fecha_entrega'];
    foreach ($required as $f) {
        if (empty($data[$f])) jsonResponse(['error' => "Campo obligatorio: $f"], 400);
    }

    $code = nextAlbaranCode();

    $stmt = $pdo->prepare("
        INSERT INTO albaranes (
            albaran_code, comercial_id, num_sacas, marca,
            numeracion_inicial, numeracion_final,
            cliente, direccion_envio, fecha_entrega, forma_pago, pagado, precio, importe, comentarios
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $code,
        (int)$_SESSION['user_id'],
        (int)$data['num_sacas'],
        sanitize($data['marca'] ?? 'BF10'),
        $data['numeracion_inicial'] ? (int)$data['numeracion_inicial'] : null,
        $data['numeracion_final'] ? (int)$data['numeracion_final'] : null,
        sanitize($data['cliente']),
        sanitize($data['direccion_envio'] ?? ''),
        sanitize($data['fecha_entrega']),
        sanitize($data['forma_pago'] ?? 'pendiente'),
        !empty($data['pagado']) ? 1 : 0,
        (float)($data['precio'] ?? 0),
        (float)($data['importe'] ?? 0),
        sanitize($data['comentarios'] ?? ''),
    ]);

    $albaranId = $pdo->lastInsertId();

    // Auto-create stock salida
    $stk = $pdo->prepare("
        INSERT INTO stock_movimientos (marca, tipo, cantidad, motivo, albaran_id, numeracion_inicial, numeracion_final, comentarios, user_id)
        VALUES (?, 'salida', ?, 'venta_albaran', ?, ?, ?, ?, ?)
    ");
    $stk->execute([
        sanitize($data['marca'] ?? 'BF10'),
        (int)$data['num_sacas'],
        $albaranId,
        $data['numeracion_inicial'] ? (int)$data['numeracion_inicial'] : null,
        $data['numeracion_final'] ? (int)$data['numeracion_final'] : null,
        "Albarán $code — " . sanitize($data['cliente']),
        (int)$_SESSION['user_id'],
    ]);

    jsonResponse(['success' => true, 'id' => $albaranId, 'code' => $code], 201);
}

// ---------- POST: Update ----------
if ($method === 'POST' && $action === 'update') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    // Check exists
    $chk = $pdo->prepare("SELECT id FROM albaranes WHERE id = ?");
    $chk->execute([$id]);
    if (!$chk->fetch()) jsonResponse(['error' => 'Albarán no encontrado'], 404);

    $fields = [];
    $params = [];
    $allowed = [
        'num_sacas' => 'int', 'marca' => 'str', 'numeracion_inicial' => 'int',
        'numeracion_final' => 'int', 'cliente' => 'str', 'direccion_envio' => 'str', 'fecha_entrega' => 'str',
        'forma_pago' => 'str', 'pagado' => 'bool', 'precio' => 'float', 'importe' => 'float', 'comentarios' => 'str',
    ];

    foreach ($allowed as $col => $type) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            if ($type === 'int') $params[] = $data[$col] !== null && $data[$col] !== '' ? (int)$data[$col] : null;
            elseif ($type === 'float') $params[] = (float)$data[$col];
            elseif ($type === 'bool') $params[] = !empty($data[$col]) ? 1 : 0;
            else $params[] = sanitize($data[$col] ?? '');
        }
    }

    if (!$fields) jsonResponse(['error' => 'Nada que actualizar'], 400);

    $params[] = $id;
    $pdo->prepare("UPDATE albaranes SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    // Sync stock movement if num_sacas, marca, or numeracion changed
    if (array_key_exists('num_sacas', $data) || array_key_exists('marca', $data) ||
        array_key_exists('numeracion_inicial', $data) || array_key_exists('numeracion_final', $data) ||
        array_key_exists('cliente', $data)) {
        // Re-read updated albaran
        $stmt = $pdo->prepare("SELECT * FROM albaranes WHERE id = ?");
        $stmt->execute([$id]);
        $alb = $stmt->fetch();
        if ($alb) {
            // Update linked stock movement
            $pdo->prepare("
                UPDATE stock_movimientos
                SET cantidad = ?, marca = ?, numeracion_inicial = ?, numeracion_final = ?,
                    comentarios = ?
                WHERE albaran_id = ?
            ")->execute([
                (int)$alb['num_sacas'],
                $alb['marca'],
                $alb['numeracion_inicial'],
                $alb['numeracion_final'],
                "Albarán " . $alb['albaran_code'] . " — " . $alb['cliente'],
                $id
            ]);
        }
    }

    jsonResponse(['success' => true]);
}

// ---------- POST: Soft Delete ----------
if ($method === 'POST' && $action === 'delete') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $chk = $pdo->prepare("SELECT id FROM albaranes WHERE id = ? AND deleted_at IS NULL");
    $chk->execute([$id]);
    if (!$chk->fetch()) jsonResponse(['error' => 'Albarán no encontrado'], 404);

    $pdo->prepare("UPDATE albaranes SET deleted_at = NOW() WHERE id = ?")->execute([$id]);

    // Also delete the linked stock movement
    $pdo->prepare("DELETE FROM stock_movimientos WHERE albaran_id = ?")->execute([$id]);

    jsonResponse(['success' => true]);
}

// ---------- POST: Restore from trash ----------
if ($method === 'POST' && $action === 'restore') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $chk = $pdo->prepare("SELECT id, num_sacas, marca, numeracion_inicial, numeracion_final, albaran_code, cliente FROM albaranes WHERE id = ? AND deleted_at IS NOT NULL");
    $chk->execute([$id]);
    $row = $chk->fetch();
    if (!$row) jsonResponse(['error' => 'Albarán no encontrado en papelera'], 404);

    $pdo->prepare("UPDATE albaranes SET deleted_at = NULL WHERE id = ?")->execute([$id]);

    // Re-create stock movement
    $stk = $pdo->prepare("
        INSERT INTO stock_movimientos (marca, tipo, cantidad, motivo, albaran_id, numeracion_inicial, numeracion_final, comentarios, user_id)
        VALUES (?, 'salida', ?, 'venta_albaran', ?, ?, ?, ?, ?)
    ");
    $stk->execute([
        $row['marca'],
        (int)$row['num_sacas'],
        $id,
        $row['numeracion_inicial'],
        $row['numeracion_final'],
        "Albarán " . $row['albaran_code'] . " — " . $row['cliente'],
        (int)$_SESSION['user_id'],
    ]);

    jsonResponse(['success' => true]);
}

// ---------- GET: Trash ----------
if ($method === 'GET' && $action === 'trash') {
    $stmt = $pdo->query("
        SELECT a.*, u.name AS comercial_name
        FROM albaranes a
        LEFT JOIN users u ON u.id = a.comercial_id
        WHERE a.deleted_at IS NOT NULL
        ORDER BY a.deleted_at DESC LIMIT 200
    ");
    jsonResponse(['albaranes' => $stmt->fetchAll()]);
}

// ====================================================================
// POST ?action=invoice  — Generate invoice from albarán
// ====================================================================
if ($method === 'POST' && $action === 'invoice') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $stmt = $pdo->prepare("SELECT * FROM albaranes WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $alb = $stmt->fetch();
    if (!$alb) jsonResponse(['error' => 'Albarán no encontrado'], 404);

    // Check if invoice already exists for this albaran
    $chk = $pdo->prepare("SELECT * FROM invoices WHERE albaran_id = ?");
    $chk->execute([$id]);
    $existing = $chk->fetch();
    if ($existing) {
        jsonResponse(['success' => true, 'invoice' => $existing, 'is_new' => false]);
    }

    // Generate invoice number (same system as orders: prefix-YYYY-NNNN)
    require_once __DIR__ . '/config.php';
    $brandCode = $alb['marca'] ?? 'BF10';
    $brand = getBrand($brandCode);
    $prefix = $brand['invoice_prefix'];
    $year = date('Y');

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM invoices WHERE YEAR(issued_at) = ? AND brand = ?");
    $stmt->execute([$year, $brandCode]);
    $nextSeq = ((int)$stmt->fetch()['c']) + 1;
    $invoiceNumber = sprintf('%s-%s-%04d', $prefix, $year, $nextSeq);

    // Calculate amounts (IVA 21%)
    $totalInclIva = (float)$alb['importe'];
    $base = round($totalInclIva / 1.21, 2);
    $iva  = round($totalInclIva - $base, 2);

    $ins = $pdo->prepare("
        INSERT INTO invoices (albaran_id, invoice_number, brand, base_amount, iva_amount, total_amount, issued_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([$id, $invoiceNumber, $brandCode, $base, $iva, $totalInclIva]);

    $invoiceId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();

    jsonResponse(['success' => true, 'invoice' => $invoice, 'is_new' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
