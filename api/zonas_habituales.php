<?php
/**
 * Zonas Habituales API — manage conductor zone assignments
 *
 * GET  ?action=list                     — all zones grouped by conductor
 * GET  ?action=barrios                  — all distinct barrios in system
 * POST ?action=toggle   { conductor, barrio, activo }  — toggle zone on/off
 * POST ?action=add      { conductor, barrio }           — add new zone
 * POST ?action=remove   { conductor, barrio }           — remove zone
 */
require_once __DIR__ . '/config.php';

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// List all zones
if ($method === 'GET' && $action === 'list') {
    $rows = $pdo->query("
        SELECT zh.conductor, zh.barrio, zh.activo
        FROM zonas_habituales zh
        ORDER BY zh.conductor, zh.barrio
    ")->fetchAll();

    $grouped = [];
    foreach ($rows as $r) {
        $c = $r['conductor'];
        if (!isset($grouped[$c])) $grouped[$c] = [];
        $grouped[$c][] = ['barrio' => $r['barrio'], 'activo' => (int)$r['activo']];
    }

    jsonResponse(['zonas' => $grouped]);
}

// All distinct barrios
if ($method === 'GET' && $action === 'barrios') {
    $barrios = $pdo->query("
        SELECT DISTINCT LOWER(TRIM(barrio_cp)) as barrio
        FROM rutas_data
        WHERE barrio_cp IS NOT NULL AND barrio_cp != ''
        ORDER BY barrio
    ")->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse(['barrios' => $barrios]);
}

// Toggle zone active/inactive
if ($method === 'POST' && $action === 'toggle') {
    $input = json_decode(file_get_contents('php://input'), true);
    $conductor = strtoupper(trim($input['conductor'] ?? ''));
    $barrio = strtolower(trim($input['barrio'] ?? ''));
    $activo = (int)($input['activo'] ?? 1);

    if (!$conductor || !$barrio) jsonResponse(['error' => 'Datos incompletos'], 400);

    $stmt = $pdo->prepare("UPDATE zonas_habituales SET activo = ? WHERE conductor = ? AND barrio = ?");
    $stmt->execute([$activo, $conductor, $barrio]);
    jsonResponse(['success' => true]);
}

// Add new zone
if ($method === 'POST' && $action === 'add') {
    $input = json_decode(file_get_contents('php://input'), true);
    $conductor = strtoupper(trim($input['conductor'] ?? ''));
    $barrio = strtolower(trim($input['barrio'] ?? ''));

    if (!$conductor || !$barrio) jsonResponse(['error' => 'Datos incompletos'], 400);

    $stmt = $pdo->prepare("INSERT IGNORE INTO zonas_habituales (conductor, barrio, activo) VALUES (?, ?, 1)");
    $stmt->execute([$conductor, $barrio]);
    jsonResponse(['success' => true]);
}

// Remove zone
if ($method === 'POST' && $action === 'remove') {
    $input = json_decode(file_get_contents('php://input'), true);
    $conductor = strtoupper(trim($input['conductor'] ?? ''));
    $barrio = strtolower(trim($input['barrio'] ?? ''));

    if (!$conductor || !$barrio) jsonResponse(['error' => 'Datos incompletos'], 400);

    $stmt = $pdo->prepare("DELETE FROM zonas_habituales WHERE conductor = ? AND barrio = ?");
    $stmt->execute([$conductor, $barrio]);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
