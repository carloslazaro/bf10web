<?php
/**
 * Solicitud de Recogida API — public (no auth required for create/check)
 *
 * POST ?action=crear              — client submits pickup request
 * GET  ?action=mis-sacas&tel=XXX  — client checks their sacks by phone
 * GET  ?action=list               — admin: list all requests (requires manager)
 * POST ?action=aprobar            — admin: approve request → create rutas_data entry
 * POST ?action=rechazar           — admin: reject request
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'line' => $e->getLine()]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Public: Create pickup request ──
if ($method === 'POST' && $action === 'crear') {
    $data = json_decode(file_get_contents('php://input'), true);

    $telefono = preg_replace('/[^0-9+]/', '', $data['telefono'] ?? '');
    $nombre = sanitize($data['nombre'] ?? '');
    $direccion = sanitize($data['direccion'] ?? '');
    $barrio = sanitize($data['barrio_cp'] ?? '');
    $sacos = max(1, (int)($data['sacos'] ?? 1));
    $obs = sanitize($data['observaciones'] ?? '');

    if (strlen($telefono) < 9) jsonResponse(['error' => 'Teléfono no válido'], 400);
    if (!$direccion) jsonResponse(['error' => 'Dirección requerida'], 400);

    $stmt = $pdo->prepare("
        INSERT INTO solicitudes_recogida (telefono, nombre_cliente, direccion, barrio_cp, sacos, observaciones, estado)
        VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
    ");
    $stmt->execute([$telefono, $nombre, $direccion, $barrio, $sacos, $obs]);

    jsonResponse(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Solicitud registrada correctamente']);
}

// ── Public: Client checks their sacks by phone number ──
if ($method === 'GET' && $action === 'mis-sacas') {
    $tel = preg_replace('/[^0-9+]/', '', $_GET['tel'] ?? '');
    if (strlen($tel) < 9) jsonResponse(['error' => 'Teléfono no válido'], 400);

    // Get stops linked to this phone from rutas_data
    $stmt = $pdo->prepare("
        SELECT id, direccion, barrio_cp, sacos, conductor, estado, marca, viaje, fecha_aviso,
               CASE
                   WHEN estado = 'recogida' THEN 'Recogida'
                   WHEN conductor IS NOT NULL AND conductor != '' THEN 'Estamos en camino!'
                   ELSE 'Pendiente de recoger'
               END as estado_cliente,
               CASE WHEN estado = 'recogida' THEN 1 ELSE 0 END as is_recogida
        FROM rutas_data
        WHERE telefono = ? OR tlf_aviso = ?
        ORDER BY
            CASE WHEN estado = 'recogida' THEN 1 ELSE 0 END ASC,
            id DESC
    ");
    $stmt->execute([$tel, $tel]);
    $allStops = $stmt->fetchAll();

    // Filter: pending + last 30 days recogidas
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $filtered = [];
    foreach ($allStops as $s) {
        if ($s['is_recogida']) {
            $fechaAviso = substr($s['fecha_aviso'] ?? '', 0, 10);
            if ($fechaAviso >= $thirtyDaysAgo || !$fechaAviso) {
                $filtered[] = $s;
            }
        } else {
            $filtered[] = $s;
        }
    }

    // Also check solicitudes
    $stmtSol = $pdo->prepare("
        SELECT id, direccion, barrio_cp, sacos, 'pendiente' as estado,
               'Pendiente de recoger' as estado_cliente,
               0 as is_recogida, created_at
        FROM solicitudes_recogida
        WHERE telefono = ? AND estado = 'pendiente'
        ORDER BY id DESC
    ");
    $stmtSol->execute([$tel]);
    $solicitudes = $stmtSol->fetchAll();

    // Get geocode data for map (from geocode_cache if available)
    $stopsForMap = [];
    foreach ($filtered as $s) {
        $entry = [
            'id' => $s['id'],
            'direccion' => $s['direccion'],
            'barrio_cp' => $s['barrio_cp'] ?? '',
            'sacos' => $s['sacos'],
            'estado_cliente' => $s['estado_cliente'],
            'is_recogida' => (int)$s['is_recogida']
        ];

        // Try to get coordinates from geocode_cache
        $geocStmt = $pdo->prepare("SELECT lat, lng FROM geocode_cache WHERE direccion = ? LIMIT 1");
        $geocStmt->execute([$s['direccion']]);
        $geo = $geocStmt->fetch();
        if ($geo) {
            $entry['lat'] = (float)$geo['lat'];
            $entry['lng'] = (float)$geo['lng'];
        }
        $stopsForMap[] = $entry;
    }

    jsonResponse([
        'paradas' => $stopsForMap,
        'solicitudes_pendientes' => $solicitudes,
        'total_pendientes' => count(array_filter($filtered, function($s) { return !$s['is_recogida']; })) + count($solicitudes),
        'total_recogidas' => count(array_filter($filtered, function($s) { return $s['is_recogida']; }))
    ]);
}

// ── Admin: List all requests ──
if ($method === 'GET' && $action === 'list') {
    if (!isLoggedIn() || !isManager()) jsonResponse(['error' => 'Acceso denegado'], 403);

    $estado = sanitize($_GET['estado'] ?? '');
    $where = [];
    $params = [];
    if ($estado) { $where[] = 'estado = ?'; $params[] = $estado; }

    $sql = "SELECT * FROM solicitudes_recogida";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['solicitudes' => $stmt->fetchAll()]);
}

// ── Admin: Approve request → create rutas_data entry ──
if ($method === 'POST' && $action === 'aprobar') {
    if (!isLoggedIn() || !isManager()) jsonResponse(['error' => 'Acceso denegado'], 403);

    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $sol = $pdo->prepare("SELECT * FROM solicitudes_recogida WHERE id = ? AND estado = 'pendiente'");
    $sol->execute([$id]);
    $req = $sol->fetch();
    if (!$req) jsonResponse(['error' => 'Solicitud no encontrada o ya procesada'], 404);

    // Create rutas_data entry
    $ins = $pdo->prepare("
        INSERT INTO rutas_data (direccion, barrio_cp, sacos, tlf_aviso, telefono, estado, marca, fecha_aviso)
        VALUES (?, ?, ?, ?, ?, 'por_recoger', 'BF10', ?)
    ");
    $ins->execute([$req['direccion'], $req['barrio_cp'], $req['sacos'], $req['telefono'], $req['telefono'], date('Y-m-d')]);
    $rutaId = $pdo->lastInsertId();

    // Update solicitud
    $pdo->prepare("UPDATE solicitudes_recogida SET estado = 'aprobada', rutas_data_id = ? WHERE id = ?")->execute([$rutaId, $id]);

    jsonResponse(['success' => true, 'rutas_data_id' => $rutaId]);
}

// ── Admin: Reject request ──
if ($method === 'POST' && $action === 'rechazar') {
    if (!isLoggedIn() || !isManager()) jsonResponse(['error' => 'Acceso denegado'], 403);

    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo->prepare("UPDATE solicitudes_recogida SET estado = 'rechazada' WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
