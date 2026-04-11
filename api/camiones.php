<?php
/**
 * Camiones API — manager only.
 *
 * GET  ?action=list              — all active camiones
 * GET  ?action=detail&id=N       — single camion
 * POST ?action=create            — new camion
 * POST ?action=update            — edit camion
 * POST ?action=toggle&id=N       — toggle activo
 *
 * GET  ?action=assignments&fecha=YYYY-MM-DD  — daily assignments
 * POST ?action=assign            — assign conductor to camion for a date
 * POST ?action=unassign          — remove assignment
 * GET  ?action=history&camion_id=N           — assignment history for a camion
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── List camiones ──
if ($method === 'GET' && $action === 'list') {
    $all = ($_GET['all'] ?? '') === '1';
    $sql = "SELECT * FROM camiones" . ($all ? '' : ' WHERE activo = 1') . " ORDER BY matricula";
    jsonResponse(['camiones' => $pdo->query($sql)->fetchAll()]);
}

// ── Detail ──
if ($method === 'GET' && $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
    $stmt = $pdo->prepare("SELECT * FROM camiones WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Camion no encontrado'], 404);
    jsonResponse(['camion' => $row]);
}

// ── Create ──
if ($method === 'POST' && $action === 'create') {
    $d = json_decode(file_get_contents('php://input'), true);
    $matricula = strtoupper(trim($d['matricula'] ?? ''));
    if (!$matricula) jsonResponse(['error' => 'Matricula requerida'], 400);

    $stmt = $pdo->prepare("INSERT INTO camiones (matricula, modelo, capacidad_m3, capacidad_sacas, conductor_habitual) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $matricula,
        trim($d['modelo'] ?? ''),
        !empty($d['capacidad_m3']) ? (float)$d['capacidad_m3'] : null,
        !empty($d['capacidad_sacas']) ? (int)$d['capacidad_sacas'] : null,
        trim($d['conductor_habitual'] ?? ''),
    ]);
    jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
}

// ── Update ──
if ($method === 'POST' && $action === 'update') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $fields = [];
    $params = [];
    $allowed = ['matricula' => 'str', 'modelo' => 'str', 'capacidad_m3' => 'float', 'capacidad_sacas' => 'int', 'conductor_habitual' => 'str'];
    foreach ($allowed as $col => $type) {
        if (array_key_exists($col, $d)) {
            $fields[] = "$col = ?";
            $v = $d[$col];
            if ($type === 'int') $params[] = ($v !== '' && $v !== null) ? (int)$v : null;
            elseif ($type === 'float') $params[] = ($v !== '' && $v !== null) ? (float)$v : null;
            else $params[] = trim($v ?? '');
        }
    }
    if (!$fields) jsonResponse(['error' => 'Nada que actualizar'], 400);
    $params[] = $id;
    $pdo->prepare("UPDATE camiones SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    jsonResponse(['success' => true]);
}

// ── Toggle activo ──
if ($method === 'POST' && $action === 'toggle') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
    $pdo->prepare("UPDATE camiones SET activo = NOT activo WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ── Assignments for a date ──
if ($method === 'GET' && $action === 'assignments') {
    $fecha = trim($_GET['fecha'] ?? date('Y-m-d'));
    $stmt = $pdo->prepare("
        SELECT cc.*, c.matricula, c.modelo
        FROM camion_conductor cc
        JOIN camiones c ON c.id = cc.camion_id
        WHERE cc.fecha = ?
        ORDER BY c.matricula
    ");
    $stmt->execute([$fecha]);
    jsonResponse(['assignments' => $stmt->fetchAll()]);
}

// ── Assign conductor to camion for a date ──
if ($method === 'POST' && $action === 'assign') {
    $d = json_decode(file_get_contents('php://input'), true);
    $camion_id = (int)($d['camion_id'] ?? 0);
    $conductor = trim($d['conductor_nombre'] ?? '');
    $fecha     = trim($d['fecha'] ?? date('Y-m-d'));

    if (!$camion_id || !$conductor) jsonResponse(['error' => 'Camion y conductor requeridos'], 400);

    // Upsert (replace if same camion+fecha or same conductor+fecha)
    $pdo->prepare("DELETE FROM camion_conductor WHERE (camion_id = ? AND fecha = ?) OR (conductor_nombre = ? AND fecha = ?)")
        ->execute([$camion_id, $fecha, $conductor, $fecha]);

    $stmt = $pdo->prepare("INSERT INTO camion_conductor (camion_id, conductor_nombre, fecha) VALUES (?, ?, ?)");
    $stmt->execute([$camion_id, $conductor, $fecha]);
    jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

// ── Unassign ──
if ($method === 'POST' && $action === 'unassign') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
    $pdo->prepare("DELETE FROM camion_conductor WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ── History for a camion ──
if ($method === 'GET' && $action === 'history') {
    $camion_id = (int)($_GET['camion_id'] ?? 0);
    if (!$camion_id) jsonResponse(['error' => 'camion_id requerido'], 400);

    $stmt = $pdo->prepare("
        SELECT * FROM camion_conductor
        WHERE camion_id = ?
        ORDER BY fecha DESC
        LIMIT 100
    ");
    $stmt->execute([$camion_id]);
    jsonResponse(['history' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Accion no valida'], 400);
