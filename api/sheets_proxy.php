<?php
/**
 * Google Sheets proxy API — for rutas users.
 *
 * GET  ?action=read[&range=...]       — read sheet data
 * POST ?action=update                 — update a cell/range
 * POST ?action=append                 — append rows
 * POST ?action=insert-row             — insert empty row at position
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_sheets.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || (!isRutas() && !isManager())) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$spreadsheetId = '1bAvqZrEpf-vjTQ9sJ7diOulRL3tB2wD0qWA8nl4Zo0s';
$defaultSheet = 'Madrid+Pueblos';

// ---------- GET: Read ----------
if ($method === 'GET' && $action === 'read') {
    $range = $_GET['range'] ?? "$defaultSheet!A:Z";
    $data = sheetsRead($spreadsheetId, $range);
    if (isset($data['error'])) {
        jsonResponse(['error' => $data['error']], 500);
    }
    jsonResponse(['rows' => $data]);
}

// ---------- POST: Update cell(s) ----------
if ($method === 'POST' && $action === 'update') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || empty($data['range']) || !isset($data['values'])) {
        jsonResponse(['error' => 'Se requiere range y values'], 400);
    }
    $result = sheetsUpdate($spreadsheetId, $data['range'], $data['values']);
    if (isset($result['error'])) {
        jsonResponse(['error' => $result['error']], 500);
    }
    jsonResponse($result);
}

// ---------- POST: Append rows ----------
if ($method === 'POST' && $action === 'append') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['values'])) {
        jsonResponse(['error' => 'Se requiere values'], 400);
    }
    $range = $data['range'] ?? "$defaultSheet!A:Z";
    $result = sheetsAppend($spreadsheetId, $range, $data['values']);
    if (isset($result['error'])) {
        jsonResponse(['error' => $result['error']], 500);
    }
    jsonResponse($result);
}

// ---------- POST: Insert row at position ----------
if ($method === 'POST' && $action === 'insert-row') {
    $data = json_decode(file_get_contents('php://input'), true);
    $rowIndex = isset($data['rowIndex']) ? (int)$data['rowIndex'] : -1;
    if ($rowIndex < 0) {
        jsonResponse(['error' => 'Se requiere rowIndex (0-based)'], 400);
    }

    // Get the sheetId for Madrid+Pueblos
    $meta = sheetsMetadata($spreadsheetId);
    if (isset($meta['error'])) {
        jsonResponse(['error' => $meta['error']], 500);
    }
    $sheetId = null;
    foreach ($meta['sheets'] as $s) {
        if ($s['properties']['title'] === $defaultSheet) {
            $sheetId = $s['properties']['sheetId'];
            break;
        }
    }
    if ($sheetId === null) {
        jsonResponse(['error' => 'Hoja no encontrada'], 404);
    }

    $result = sheetsInsertRows($spreadsheetId, $sheetId, $rowIndex, 1);
    if (isset($result['error'])) {
        jsonResponse(['error' => $result['error']], 500);
    }
    jsonResponse($result);
}

jsonResponse(['error' => 'Acción no válida'], 400);
