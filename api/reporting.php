<?php
/**
 * Reporting API — KPIs and dashboards
 *
 * GET ?action=kpi-today          — today's KPIs
 * GET ?action=kpi-range&from=&to= — KPIs for date range
 * GET ?action=conductor-perf     — conductor performance
 * GET ?action=barrio-demand      — barrio demand heatmap data
 * GET ?action=facturacion        — billing summary
 */
require_once __DIR__ . '/config.php';

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();
$action = $_GET['action'] ?? '';

// ── Today's KPIs ──
if ($action === 'kpi-today') {
    $today = date('Y-m-d');

    // Rutas stats
    $rutas = $pdo->query("
        SELECT
            COUNT(*) as total_paradas,
            COALESCE(SUM(CAST(sacos AS UNSIGNED)), 0) as total_sacas,
            SUM(CASE WHEN estado = 'recogida' THEN 1 ELSE 0 END) as recogidas,
            SUM(CASE WHEN estado = '' OR estado IS NULL OR estado = 'por_recoger' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN estado = 'no_estan' THEN 1 ELSE 0 END) as no_estan,
            COUNT(DISTINCT CASE WHEN conductor != '' AND conductor IS NOT NULL THEN UPPER(conductor) END) as conductores_activos,
            SUM(CASE WHEN (conductor = '' OR conductor IS NULL) AND (estado = '' OR estado IS NULL OR estado = 'por_recoger') THEN 1 ELSE 0 END) as sin_conductor
        FROM rutas_data
    ")->fetch();

    // Paradas > 48h pendientes
    $alertas48h = $pdo->query("
        SELECT COUNT(*) as cnt FROM rutas_data
        WHERE (estado = '' OR estado IS NULL OR estado = 'por_recoger')
          AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ")->fetchColumn();

    // Today's orders
    $pedidosHoy = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(package_price), 0) as total FROM orders WHERE DATE(created_at) = ?");
    $pedidosHoy->execute([$today]);
    $pedidos = $pedidosHoy->fetch();

    // Today's albaranes
    $albaranesHoy = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(importe), 0) as total FROM albaranes WHERE fecha_entrega = ? AND deleted_at IS NULL");
    $albaranesHoy->execute([$today]);
    $albs = $albaranesHoy->fetch();

    // Solicitudes pendientes
    $solPendientes = $pdo->query("SELECT COUNT(*) FROM solicitudes_recogida WHERE estado = 'pendiente'")->fetchColumn();

    jsonResponse([
        'fecha' => $today,
        'rutas' => [
            'total_paradas' => (int)$rutas['total_paradas'],
            'total_sacas' => (int)$rutas['total_sacas'],
            'recogidas' => (int)$rutas['recogidas'],
            'pendientes' => (int)$rutas['pendientes'],
            'no_estan' => (int)$rutas['no_estan'],
            'conductores_activos' => (int)$rutas['conductores_activos'],
            'sin_conductor' => (int)$rutas['sin_conductor'],
            'pct_completado' => $rutas['total_paradas'] > 0 ? round(($rutas['recogidas'] / $rutas['total_paradas']) * 100) : 0
        ],
        'alertas' => [
            'paradas_48h' => (int)$alertas48h,
            'solicitudes_pendientes' => (int)$solPendientes
        ],
        'pedidos_hoy' => ['count' => (int)$pedidos['cnt'], 'total' => (float)$pedidos['total']],
        'albaranes_hoy' => ['count' => (int)$albs['cnt'], 'total' => (float)$albs['total']]
    ]);
}

// ── KPIs for date range ──
if ($action === 'kpi-range') {
    $from = sanitize($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    // Daily breakdown from historico
    $stmt = $pdo->prepare("
        SELECT fecha,
            SUM(total_paradas) as paradas, SUM(total_sacas) as sacas,
            SUM(completadas) as completadas, SUM(pendientes) as pendientes
        FROM rutas_historico
        WHERE fecha BETWEEN ? AND ?
        GROUP BY fecha ORDER BY fecha
    ");
    $stmt->execute([$from, $to]);
    $daily = $stmt->fetchAll();

    // Orders in range
    $stmtOrd = $pdo->prepare("
        SELECT DATE(created_at) as dia, COUNT(*) as cnt, COALESCE(SUM(package_price), 0) as total
        FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at) ORDER BY dia
    ");
    $stmtOrd->execute([$from, $to]);
    $ordDaily = $stmtOrd->fetchAll();

    // Albaranes in range
    $stmtAlb = $pdo->prepare("
        SELECT fecha_entrega as dia, COUNT(*) as cnt, COALESCE(SUM(importe), 0) as total
        FROM albaranes WHERE fecha_entrega BETWEEN ? AND ? AND deleted_at IS NULL
        GROUP BY fecha_entrega ORDER BY dia
    ");
    $stmtAlb->execute([$from, $to]);
    $albDaily = $stmtAlb->fetchAll();

    jsonResponse([
        'from' => $from, 'to' => $to,
        'rutas_diario' => $daily,
        'pedidos_diario' => $ordDaily,
        'albaranes_diario' => $albDaily
    ]);
}

// ── Conductor Performance ──
if ($action === 'conductor-perf') {
    $from = sanitize($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    // From historico
    $stmt = $pdo->prepare("
        SELECT conductor,
            SUM(total_paradas) as total_paradas,
            SUM(total_sacas) as total_sacas,
            SUM(completadas) as completadas,
            SUM(no_estan) as no_estan,
            COUNT(DISTINCT fecha) as dias_activo,
            COUNT(DISTINCT CONCAT(fecha, '-', viaje)) as total_viajes
        FROM rutas_historico
        WHERE fecha BETWEEN ? AND ?
        GROUP BY conductor ORDER BY total_sacas DESC
    ");
    $stmt->execute([$from, $to]);
    $perf = $stmt->fetchAll();

    // If no historico data, get from current rutas_data
    if (empty($perf)) {
        $perf = $pdo->query("
            SELECT UPPER(TRIM(conductor)) as conductor,
                COUNT(*) as total_paradas,
                COALESCE(SUM(CAST(sacos AS UNSIGNED)), 0) as total_sacas,
                SUM(CASE WHEN estado = 'recogida' THEN 1 ELSE 0 END) as completadas,
                SUM(CASE WHEN estado = 'no_estan' THEN 1 ELSE 0 END) as no_estan,
                1 as dias_activo,
                COUNT(DISTINCT viaje) as total_viajes
            FROM rutas_data
            WHERE conductor IS NOT NULL AND conductor != ''
            GROUP BY UPPER(TRIM(conductor))
            ORDER BY total_sacas DESC
        ")->fetchAll();
    }

    jsonResponse(['performance' => $perf]);
}

// ── Barrio demand ──
if ($action === 'barrio-demand') {
    $from = sanitize($_GET['from'] ?? date('Y-m-d', strtotime('-90 days')));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    $stmt = $pdo->query("
        SELECT LOWER(TRIM(barrio_cp)) as barrio,
            COUNT(*) as total_paradas,
            COALESCE(SUM(CAST(sacos AS UNSIGNED)), 0) as total_sacas,
            SUM(CASE WHEN estado = 'recogida' THEN 1 ELSE 0 END) as recogidas,
            SUM(CASE WHEN estado = '' OR estado IS NULL OR estado = 'por_recoger' THEN 1 ELSE 0 END) as pendientes
        FROM rutas_data
        WHERE barrio_cp IS NOT NULL AND barrio_cp != ''
        GROUP BY LOWER(TRIM(barrio_cp))
        ORDER BY total_paradas DESC
    ");

    jsonResponse(['barrios' => $stmt->fetchAll()]);
}

// ── Facturación summary ──
if ($action === 'facturacion') {
    $from = sanitize($_GET['from'] ?? date('Y-01-01'));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    // Invoices
    $inv = $pdo->prepare("
        SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total,
               COALESCE(SUM(base_amount), 0) as base, COALESCE(SUM(iva_amount), 0) as iva,
               SUM(CASE WHEN payment_status = 'pagada' THEN total_amount ELSE 0 END) as cobrado,
               SUM(CASE WHEN payment_status = 'pendiente' OR payment_status IS NULL OR payment_status = '' THEN total_amount ELSE 0 END) as pendiente
        FROM invoices WHERE issued_at BETWEEN ? AND ?
    ");
    $inv->execute(["$from 00:00:00", "$to 23:59:59"]);

    // Abonos
    $ab = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total FROM abonos WHERE issued_at BETWEEN ? AND ?");
    $ab->execute(["$from 00:00:00", "$to 23:59:59"]);

    // Albaranes
    $alb = $pdo->prepare("
        SELECT COUNT(*) as cnt, COALESCE(SUM(importe), 0) as total,
               SUM(CASE WHEN pagado = 1 THEN importe ELSE 0 END) as cobrado,
               SUM(CASE WHEN pagado = 0 THEN importe ELSE 0 END) as pendiente
        FROM albaranes WHERE fecha_entrega BETWEEN ? AND ? AND deleted_at IS NULL
    ");
    $alb->execute([$from, $to]);

    jsonResponse([
        'facturas' => $inv->fetch(),
        'abonos' => $ab->fetch(),
        'albaranes' => $alb->fetch()
    ]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
