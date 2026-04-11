<?php
/**
 * Avisador App API — PIN login + create aviso.
 *
 * POST action=login      — verify PIN (only avisador role)
 * POST action=create     — create aviso de recogida
 * GET  action=my-avisos  — list avisos created by this avisador
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';
} else {
    $body = null;
}
function getBody() {
    global $body;
    if ($body) return $body;
    $body = json_decode(file_get_contents('php://input'), true);
    return $body;
}

$pdo = getDB();

// ── Login (verify PIN — only avisador role) ──
if ($action === 'login') {
    $input = getBody();
    $pin = trim($input['pin'] ?? '');

    if (strlen($pin) !== 4) {
        jsonResponse(['ok' => false, 'error' => 'PIN debe tener 4 dígitos'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT cp.user_id, cp.nombre, u.role
        FROM comerciales_pin cp
        JOIN users u ON u.id = cp.user_id
        WHERE cp.pin = ? AND cp.activo = 1 AND u.role IN ('avisador', 'comercial', 'manager', 'ceo')
    ");
    $stmt->execute([$pin]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(['ok' => false, 'error' => 'PIN incorrecto'], 401);
    }

    jsonResponse(['ok' => true, 'nombre' => $row['nombre'], 'user_id' => (int)$row['user_id']]);
}

// ── Create aviso de recogida ──
if ($action === 'create') {
    $data = getBody();

    $direccion     = trim($data['direccion'] ?? '');
    $barrio_cp     = trim($data['barrio_cp'] ?? '');
    $sacos         = trim($data['sacos'] ?? '');
    $urgen         = trim($data['urgen'] ?? '');
    $tlf_aviso     = trim($data['tlf_aviso'] ?? '');
    $marca         = trim($data['marca'] ?? '');
    $observaciones = trim($data['observaciones'] ?? '');
    $fecha_aviso   = trim($data['fecha_aviso'] ?? date('Y-m-d'));
    $avisador      = trim($data['avisador'] ?? '');

    if (!$direccion && !$barrio_cp) {
        jsonResponse(['error' => 'Dirección o barrio requeridos'], 400);
    }

    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(row_order),0) FROM rutas_data")->fetchColumn();
    $newOrder = $maxOrder + 1;

    $stmt = $pdo->prepare("
        INSERT INTO rutas_data (row_order, direccion, barrio_cp, sacos, urgen, tlf_aviso, marca, observaciones, fecha_aviso, avisador)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$newOrder, $direccion, $barrio_cp, $sacos, $urgen, $tlf_aviso, $marca, $observaciones, $fecha_aviso, $avisador]);
    $newId = (int)$pdo->lastInsertId();

    jsonResponse(['success' => true, 'id' => $newId]);
}

// ── My avisos (last 50 by this avisador) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'my-avisos') {
    $nombre = trim($_GET['nombre'] ?? '');
    if (!$nombre) jsonResponse(['error' => 'Nombre requerido'], 400);

    $stmt = $pdo->prepare("
        SELECT id, direccion, barrio_cp, sacos, urgen, tlf_aviso, marca, observaciones, fecha_aviso, created_at
        FROM rutas_data
        WHERE avisador = ?
        ORDER BY id DESC
        LIMIT 50
    ");
    $stmt->execute([$nombre]);
    jsonResponse(['avisos' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
