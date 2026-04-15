<?php
/**
 * Histórico de Rutas API
 *
 * GET  ?action=snapshot          — generate today's snapshot
 * GET  ?action=list&from=&to=    — list historic entries (date range)
 * GET  ?action=detail&fecha=     — detail for a specific date
 * GET  ?action=dates             — list available dates
 */
require_once __DIR__ . '/config.php';

if (!isLoggedIn() || (!isManager() && !isFacturacion())) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Generate/update snapshot for a date (defaults to today)
if ($action === 'snapshot') {
    $fecha = sanitize($_GET['fecha'] ?? date('Y-m-d'));

    // Delete existing snapshot for this date
    $pdo->prepare("DELETE FROM rutas_historico WHERE fecha = ?")->execute([$fecha]);

    // Get data grouped by conductor + viaje
    $stmt = $pdo->prepare("
        SELECT
            UPPER(TRIM(conductor)) as conductor,
            COALESCE(viaje, 0) as viaje,
            COUNT(*) as total_paradas,
            COALESCE(SUM(CAST(sacos AS UNSIGNED)), 0) as total_sacas,
            SUM(CASE WHEN estado = 'recogida' THEN 1 ELSE 0 END) as completadas,
            SUM(CASE WHEN estado = '' OR estado IS NULL OR estado = 'por_recoger' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN estado = 'no_estan' THEN 1 ELSE 0 END) as no_estan,
            GROUP_CONCAT(DISTINCT LOWER(TRIM(barrio_cp)) SEPARATOR ', ') as barrios
        FROM rutas_data
        WHERE conductor IS NOT NULL AND conductor != ''
          AND (fecha_aviso = ? OR fecha_aviso LIKE ?)
        GROUP BY UPPER(TRIM(conductor)), COALESCE(viaje, 0)
        ORDER BY conductor, viaje
    ");
    // Match date in different formats
    $stmt->execute([$fecha, $fecha . '%']);
    $rows = $stmt->fetchAll();

    // If no data for specific date, use current state for all active stops
    if (empty($rows)) {
        $rows = $pdo->query("
            SELECT
                UPPER(TRIM(conductor)) as conductor,
                COALESCE(viaje, 0) as viaje,
                COUNT(*) as total_paradas,
                COALESCE(SUM(CAST(sacos AS UNSIGNED)), 0) as total_sacas,
                SUM(CASE WHEN estado = 'recogida' THEN 1 ELSE 0 END) as completadas,
                SUM(CASE WHEN estado = '' OR estado IS NULL OR estado = 'por_recoger' THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN estado = 'no_estan' THEN 1 ELSE 0 END) as no_estan,
                GROUP_CONCAT(DISTINCT LOWER(TRIM(barrio_cp)) SEPARATOR ', ') as barrios
            FROM rutas_data
            WHERE conductor IS NOT NULL AND conductor != ''
            GROUP BY UPPER(TRIM(conductor)), COALESCE(viaje, 0)
            ORDER BY conductor, viaje
        ")->fetchAll();
    }

    $ins = $pdo->prepare("
        INSERT INTO rutas_historico (fecha, conductor, viaje, total_paradas, total_sacas, completadas, pendientes, no_estan, barrios)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $count = 0;
    foreach ($rows as $r) {
        if (!$r['conductor']) continue;
        $ins->execute([
            $fecha, $r['conductor'], (int)$r['viaje'],
            (int)$r['total_paradas'], (int)$r['total_sacas'],
            (int)$r['completadas'], (int)$r['pendientes'], (int)$r['no_estan'],
            $r['barrios'] ?: ''
        ]);
        $count++;
    }

    jsonResponse(['success' => true, 'entries' => $count, 'fecha' => $fecha]);
}

// List entries (with date range filter)
if ($action === 'list') {
    $from = sanitize($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare("
        SELECT fecha, conductor, SUM(total_paradas) as paradas, SUM(total_sacas) as sacas,
               SUM(completadas) as completadas, SUM(pendientes) as pendientes, SUM(no_estan) as no_estan,
               COUNT(DISTINCT viaje) as viajes
        FROM rutas_historico
        WHERE fecha BETWEEN ? AND ?
        GROUP BY fecha, conductor
        ORDER BY fecha DESC, conductor
    ");
    $stmt->execute([$from, $to]);

    // Group by date
    $byDate = [];
    foreach ($stmt->fetchAll() as $r) {
        $f = $r['fecha'];
        if (!isset($byDate[$f])) $byDate[$f] = ['fecha' => $f, 'conductores' => [], 'totals' => ['paradas' => 0, 'sacas' => 0, 'completadas' => 0, 'pendientes' => 0]];
        $byDate[$f]['conductores'][] = $r;
        $byDate[$f]['totals']['paradas'] += (int)$r['paradas'];
        $byDate[$f]['totals']['sacas'] += (int)$r['sacas'];
        $byDate[$f]['totals']['completadas'] += (int)$r['completadas'];
        $byDate[$f]['totals']['pendientes'] += (int)$r['pendientes'];
    }

    jsonResponse(['historico' => array_values($byDate)]);
}

// Detail for specific date
if ($action === 'detail') {
    $fecha = sanitize($_GET['fecha'] ?? '');
    if (!$fecha) jsonResponse(['error' => 'Fecha requerida'], 400);

    $stmt = $pdo->prepare("
        SELECT * FROM rutas_historico WHERE fecha = ? ORDER BY conductor, viaje
    ");
    $stmt->execute([$fecha]);

    jsonResponse(['detail' => $stmt->fetchAll()]);
}

// Available dates
if ($action === 'dates') {
    $dates = $pdo->query("
        SELECT DISTINCT fecha FROM rutas_historico ORDER BY fecha DESC LIMIT 90
    ")->fetchAll(PDO::FETCH_COLUMN);

    jsonResponse(['dates' => $dates]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
