<?php
/**
 * Borrador Rutas API v2 — manager/ceo only.
 *
 * POST ?action=save-draft       — save generated draft from crear_rutas
 * GET  ?action=draft            — list current draft grouped by conductor
 * POST ?action=update-cell      — edit a single cell in draft { id, field, value }
 * POST ?action=confirm          — push draft into rutas_data (assign conductor + viaje)
 * POST ?action=clear            — clear draft
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Save draft from crear_rutas generate ──
if ($method === 'POST' && $action === 'save-draft') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!$input) {
        jsonResponse(['error' => 'JSON inválido', 'raw_length' => strlen($raw)], 400);
    }

    $result = $input['result'] ?? [];
    $conductorAssignments = $input['conductor_assignments'] ?? [];

    if (empty($result)) {
        jsonResponse(['error' => 'No hay datos de resultado', 'keys' => array_keys($input)], 400);
    }

    // Clear existing draft
    $pdo->exec("DELETE FROM borrador_rutas");

    $ins = $pdo->prepare("
        INSERT INTO borrador_rutas (parada_id, direccion, barrio_cp, sacos, urgen, tlf_aviso, conductor, marca, observaciones, avisador, hora_aviso, fecha_aviso, orden, viaje, is_new)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $total = 0;
    $orden = 1;

    foreach ($result as $conductor => $cd) {
        $viajes = $cd['viajes'] ?? [];
        foreach ($viajes as $vNum => $vData) {
            $paradas = $vData['paradas'] ?? [];
            foreach ($paradas as $p) {
                $isNew = !empty($p['_new']) ? 1 : 0;
                $ins->execute([
                    $p['id'], $p['direccion'] ?? '', $p['barrio_cp'] ?? '',
                    $p['sacos'] ?? 1, $p['urgen'] ?? '', '',
                    $conductor, $p['marca'] ?? '', '', '', '', '',
                    $orden++, (int)$vNum, $isNew
                ]);
                $total++;
            }
        }
        // Overflow stops
        $overflow = $cd['overflow'] ?? [];
        foreach ($overflow as $p) {
            $isNew = !empty($p['_new']) ? 1 : 0;
            $ins->execute([
                $p['id'], $p['direccion'] ?? '', $p['barrio_cp'] ?? '',
                $p['sacos'] ?? 1, $p['urgen'] ?? '', '',
                $conductor, $p['marca'] ?? '', '', '', '', '',
                $orden++, 0, $isNew
            ]);
            $total++;
        }
    }

    jsonResponse(['success' => true, 'total' => $total]);
}

// ── Get current draft ──
if ($method === 'GET' && $action === 'draft') {
    $rows = $pdo->query("
        SELECT id, parada_id, direccion, barrio_cp, sacos, conductor, marca, viaje, orden, is_new
        FROM borrador_rutas
        ORDER BY conductor, viaje, orden ASC
    ")->fetchAll();

    // Conductores list for dropdown
    $conductores = $pdo->query("SELECT nombre FROM conductores WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);

    jsonResponse(['draft' => $rows, 'conductores' => $conductores]);
}

// ── Update a single cell in draft ──
if ($method === 'POST' && $action === 'update-cell') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $field = $input['field'] ?? '';
    $value = $input['value'] ?? '';

    $allowed = ['conductor', 'viaje', 'sacos', 'marca', 'barrio_cp', 'orden'];
    if (!$id || !in_array($field, $allowed)) {
        jsonResponse(['error' => 'Campo no válido'], 400);
    }

    $pdo->prepare("UPDATE borrador_rutas SET `$field` = ? WHERE id = ?")->execute([$value, $id]);
    jsonResponse(['success' => true]);
}

// ── Confirm: push draft to rutas_data ──
if ($method === 'POST' && $action === 'confirm') {
    $rows = $pdo->query("SELECT * FROM borrador_rutas ORDER BY id")->fetchAll();

    if (empty($rows)) {
        jsonResponse(['error' => 'No hay borrador'], 400);
    }

    $stmtUpdate = $pdo->prepare("UPDATE rutas_data SET conductor = ?, viaje = ? WHERE id = ?");
    $updated = 0;

    foreach ($rows as $r) {
        $paradaId = (int)$r['parada_id'];
        if (!$paradaId) continue;

        $conductor = trim($r['conductor']);
        $viaje = (int)$r['viaje'];

        $stmtUpdate->execute([$conductor, $viaje, $paradaId]);
        $updated++;
    }

    // Clear draft after confirm
    $pdo->exec("DELETE FROM borrador_rutas");

    jsonResponse(['success' => true, 'updated' => $updated]);
}

// ── Clear draft ──
if ($method === 'POST' && $action === 'clear') {
    $pdo->exec("DELETE FROM borrador_rutas");
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
