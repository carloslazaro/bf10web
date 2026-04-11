<?php
/**
 * Ranking Conductores API — manager/ceo only.
 *
 * GET ?action=ranking&periodo=day|week|month|year&fecha=YYYY-MM-DD
 *   Returns conductor stats: stops completed, total sacos, etc.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo    = getDB();
$action = $_GET['action'] ?? '';

if ($action === 'ranking') {
    $periodo = $_GET['periodo'] ?? 'all';
    $hoy = date('Y-m-d');

    // Calculate "hasta" date based on period (always from beginning of DB)
    switch ($periodo) {
        case 'day':
            $hasta = $hoy;
            break;
        case 'week':
            $ts = strtotime($hoy);
            $dow = (int)date('N', $ts);
            $hasta = date('Y-m-d', strtotime('+' . (7 - $dow) . ' days', $ts));
            break;
        case 'month':
            $hasta = date('Y-m-t');
            break;
        case 'year':
            $hasta = date('Y-12-31');
            break;
        default: // 'all'
            $hasta = '2099-12-31';
    }

    // All conductors from conductores table
    $allDrivers = $pdo->query("SELECT nombre FROM conductores WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);

    // Cumulative stats from beginning up to $hasta
    $stmt = $pdo->prepare("
        SELECT
            conductor,
            COUNT(*) AS paradas,
            SUM(CASE WHEN sacos != '' AND sacos IS NOT NULL THEN CAST(sacos AS UNSIGNED) ELSE 0 END) AS total_sacos,
            COUNT(DISTINCT fecha_de_ruta) AS dias_trabajados
        FROM rutas_data
        WHERE conductor != '' AND conductor IS NOT NULL
          AND fecha_recogida != '' AND fecha_recogida IS NOT NULL
          AND (fecha_de_ruta <= ? OR fecha_recogida <= ?)
        GROUP BY conductor
        ORDER BY total_sacos DESC, paradas DESC
    ");
    $stmt->execute([$hasta, $hasta]);
    $rows = $stmt->fetchAll();

    // Build map conductor → stats
    $statsMap = [];
    foreach ($rows as $r) {
        $statsMap[mb_strtoupper(trim($r['conductor']))] = $r;
    }

    // Merge: ensure all active conductors appear even with 0
    $result = [];
    $seen = [];
    foreach ($rows as $r) {
        $key = mb_strtoupper(trim($r['conductor']));
        $seen[$key] = true;
        $result[] = [
            'conductor' => $r['conductor'],
            'paradas' => (int)$r['paradas'],
            'total_sacos' => (int)$r['total_sacos'],
            'dias_trabajados' => (int)$r['dias_trabajados'],
        ];
    }
    // Add active conductors not in results
    foreach ($allDrivers as $name) {
        $key = mb_strtoupper(trim($name));
        if (!isset($seen[$key])) {
            $result[] = [
                'conductor' => $name,
                'paradas' => 0,
                'total_sacos' => 0,
                'dias_trabajados' => 0,
            ];
        }
    }

    // Sort by total_sacos desc
    usort($result, function ($a, $b) {
        return $b['total_sacos'] - $a['total_sacos'] ?: $b['paradas'] - $a['paradas'];
    });

    // Add rank
    $rank = 1;
    foreach ($result as &$r) {
        $r['rank'] = $rank++;
    }

    jsonResponse([
        'ranking' => $result,
        'periodo' => $periodo,
        'hasta' => $hasta
    ]);
}

jsonResponse(['error' => 'Accion no valida'], 400);
