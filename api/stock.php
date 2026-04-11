<?php
/**
 * Stock movements API — manager only.
 *
 * GET  ?action=summary       — stock per brand (entradas - salidas)
 * GET  ?action=list           — all movements (with filters)
 * GET  ?action=detail&id=N    — single movement
 * POST ?action=create         — manual entry/exit
 * POST ?action=update         — edit a manual movement
 * POST ?action=delete         — soft-delete (trash)
 * POST ?action=restore        — restore from trash
 * GET  ?action=trash          — list trashed movements
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();

// ---------- GET: Summary (stock per brand) — excludes deleted ----------
if ($method === 'GET' && $action === 'summary') {
    $stmt = $pdo->query("
        SELECT marca,
            SUM(CASE WHEN tipo = 'entrada' THEN cantidad ELSE 0 END) AS entradas,
            SUM(CASE WHEN tipo = 'salida'  THEN cantidad ELSE 0 END) AS salidas
        FROM stock_movimientos
        WHERE deleted_at IS NULL
        GROUP BY marca
        ORDER BY marca
    ");
    $rows = $stmt->fetchAll();
    $summary = [];
    $brands = ['BF10', 'SERVISACO', 'ATUSACO', 'ATUSACO_LUISFER', 'ATUSACO_HERREROCON', 'ATUSACO_COSASCASA', 'ECOSACO', 'SACAS_BLANCAS'];
    foreach ($brands as $b) {
        $summary[$b] = ['marca' => $b, 'entradas' => 0, 'salidas' => 0, 'stock' => 0];
    }
    foreach ($rows as $r) {
        $m = $r['marca'];
        $summary[$m] = [
            'marca'    => $m,
            'entradas' => (int)$r['entradas'],
            'salidas'  => (int)$r['salidas'],
            'stock'    => (int)$r['entradas'] - (int)$r['salidas'],
        ];
    }
    jsonResponse(['summary' => array_values($summary)]);
}

// ---------- GET: List — excludes deleted ----------
if ($method === 'GET' && $action === 'list') {
    $marca  = sanitize($_GET['marca'] ?? '');
    $tipo   = sanitize($_GET['tipo'] ?? '');
    $motivo = sanitize($_GET['motivo'] ?? '');
    $desde  = sanitize($_GET['desde'] ?? '');
    $hasta  = sanitize($_GET['hasta'] ?? '');

    $where = ['s.deleted_at IS NULL'];
    $params = [];

    if ($marca) {
        $where[] = 's.marca = ?';
        $params[] = $marca;
    }
    if ($tipo) {
        $where[] = 's.tipo = ?';
        $params[] = $tipo;
    }
    if ($motivo) {
        $where[] = 's.motivo = ?';
        $params[] = $motivo;
    }
    if ($desde) {
        $where[] = 's.created_at >= ?';
        $params[] = "$desde 00:00:00";
    }
    if ($hasta) {
        $where[] = 's.created_at <= ?';
        $params[] = "$hasta 23:59:59";
    }

    $sql = "
        SELECT s.*, u.name AS user_name, al.albaran_code
        FROM stock_movimientos s
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN albaranes al ON al.id = s.albaran_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.created_at DESC LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['movimientos' => $stmt->fetchAll()]);
}

// ---------- GET: Trash ----------
if ($method === 'GET' && $action === 'trash') {
    $stmt = $pdo->query("
        SELECT s.*, u.name AS user_name, al.albaran_code
        FROM stock_movimientos s
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN albaranes al ON al.id = s.albaran_id
        WHERE s.deleted_at IS NOT NULL
        ORDER BY s.deleted_at DESC LIMIT 200
    ");
    jsonResponse(['movimientos' => $stmt->fetchAll()]);
}

// ---------- GET: Detail ----------
if ($method === 'GET' && $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $stmt = $pdo->prepare("
        SELECT s.*, u.name AS user_name, al.albaran_code
        FROM stock_movimientos s
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN albaranes al ON al.id = s.albaran_id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Movimiento no encontrado'], 404);

    jsonResponse(['movimiento' => $row]);
}

// ---------- POST: Create ----------
if ($method === 'POST' && $action === 'create') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) jsonResponse(['error' => 'JSON no válido'], 400);

    $required = ['marca', 'tipo', 'cantidad', 'motivo'];
    foreach ($required as $f) {
        if (empty($data[$f])) jsonResponse(['error' => "Campo obligatorio: $f"], 400);
    }

    if (!in_array($data['tipo'], ['entrada', 'salida'])) {
        jsonResponse(['error' => 'Tipo debe ser entrada o salida'], 400);
    }
    if ((int)$data['cantidad'] <= 0) {
        jsonResponse(['error' => 'Cantidad debe ser mayor que 0'], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO stock_movimientos (marca, tipo, cantidad, motivo, numeracion_inicial, numeracion_final, comentarios, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        sanitize($data['marca']),
        $data['tipo'],
        (int)$data['cantidad'],
        $data['motivo'],
        !empty($data['numeracion_inicial']) ? (int)$data['numeracion_inicial'] : null,
        !empty($data['numeracion_final']) ? (int)$data['numeracion_final'] : null,
        sanitize($data['comentarios'] ?? ''),
        (int)$_SESSION['user_id'],
    ]);

    jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()], 201);
}

// ---------- POST: Update (only manual, not albaran-linked) ----------
if ($method === 'POST' && $action === 'update') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $chk = $pdo->prepare("SELECT id, albaran_id FROM stock_movimientos WHERE id = ? AND deleted_at IS NULL");
    $chk->execute([$id]);
    $row = $chk->fetch();
    if (!$row) jsonResponse(['error' => 'Movimiento no encontrado'], 404);
    if ($row['albaran_id']) {
        jsonResponse(['error' => 'No se puede editar un movimiento vinculado a un albarán'], 400);
    }

    $fields = [];
    $params = [];
    $allowed = [
        'marca' => 'str', 'tipo' => 'str', 'cantidad' => 'int', 'motivo' => 'str',
        'numeracion_inicial' => 'int', 'numeracion_final' => 'int', 'comentarios' => 'str',
    ];

    foreach ($allowed as $col => $type) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            if ($type === 'int') $params[] = $data[$col] !== null && $data[$col] !== '' ? (int)$data[$col] : null;
            else $params[] = sanitize($data[$col] ?? '');
        }
    }

    if (!$fields) jsonResponse(['error' => 'Nada que actualizar'], 400);

    $params[] = $id;
    $pdo->prepare("UPDATE stock_movimientos SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    jsonResponse(['success' => true]);
}

// ---------- POST: Soft Delete ----------
if ($method === 'POST' && $action === 'delete') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $stmt = $pdo->prepare("SELECT id, albaran_id FROM stock_movimientos WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Movimiento no encontrado'], 404);
    if ($row['albaran_id']) {
        jsonResponse(['error' => 'No se puede eliminar un movimiento vinculado a un albarán'], 400);
    }

    $pdo->prepare("UPDATE stock_movimientos SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ---------- POST: Restore from trash ----------
if ($method === 'POST' && $action === 'restore') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $chk = $pdo->prepare("SELECT id FROM stock_movimientos WHERE id = ? AND deleted_at IS NOT NULL");
    $chk->execute([$id]);
    if (!$chk->fetch()) jsonResponse(['error' => 'Movimiento no encontrado en papelera'], 404);

    $pdo->prepare("UPDATE stock_movimientos SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
