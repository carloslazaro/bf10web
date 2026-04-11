<?php
require_once __DIR__ . '/config.php';
$pdo = getDB();
header('Content-Type: text/plain; charset=utf-8');

// Get all completed pickups grouped by conductor + barrio
$rows = $pdo->query("
    SELECT UPPER(TRIM(conductor)) as conductor, LOWER(TRIM(barrio_cp)) as barrio, COUNT(*) as cnt
    FROM rutas_data
    WHERE conductor IS NOT NULL AND conductor != ''
      AND barrio_cp IS NOT NULL AND barrio_cp != ''
      AND estado = 'recogida'
    GROUP BY UPPER(TRIM(conductor)), LOWER(TRIM(barrio_cp))
    ORDER BY UPPER(TRIM(conductor)), cnt DESC
")->fetchAll();

// Also get pending/active assignments
$pending = $pdo->query("
    SELECT UPPER(TRIM(conductor)) as conductor, LOWER(TRIM(barrio_cp)) as barrio, COUNT(*) as cnt
    FROM rutas_data
    WHERE conductor IS NOT NULL AND conductor != ''
      AND barrio_cp IS NOT NULL AND barrio_cp != ''
      AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')
    GROUP BY UPPER(TRIM(conductor)), LOWER(TRIM(barrio_cp))
    ORDER BY UPPER(TRIM(conductor)), cnt DESC
")->fetchAll();

// Merge both datasets
$data = [];
foreach ($rows as $r) {
    $c = $r['conductor'];
    if (!isset($data[$c])) $data[$c] = [];
    $data[$c][$r['barrio']] = ['recogidas' => (int)$r['cnt'], 'pendientes' => 0];
}
foreach ($pending as $r) {
    $c = $r['conductor'];
    if (!isset($data[$c])) $data[$c] = [];
    if (!isset($data[$c][$r['barrio']])) $data[$c][$r['barrio']] = ['recogidas' => 0, 'pendientes' => 0];
    $data[$c][$r['barrio']]['pendientes'] = (int)$r['cnt'];
}

// Get active conductores
$activos = $pdo->query("SELECT UPPER(TRIM(nombre)) as nombre FROM conductores WHERE activo = 1")->fetchAll(PDO::FETCH_COLUMN);

// Get total unique barrios
$allBarrios = $pdo->query("
    SELECT DISTINCT LOWER(TRIM(barrio_cp)) as barrio
    FROM rutas_data
    WHERE barrio_cp IS NOT NULL AND barrio_cp != ''
    ORDER BY barrio
")->fetchAll(PDO::FETCH_COLUMN);

echo "=== TOTAL BARRIOS UNICOS: " . count($allBarrios) . " ===\n\n";

// Show per conductor
foreach ($activos as $nombre) {
    $zones = $data[$nombre] ?? [];
    if (empty($zones)) {
        echo "--- $nombre: SIN DATOS ---\n\n";
        continue;
    }
    // Sort by total (recogidas + pendientes) DESC
    uasort($zones, function($a, $b) {
        return ($b['recogidas'] + $b['pendientes']) - ($a['recogidas'] + $a['pendientes']);
    });

    $total = 0;
    foreach ($zones as $z) $total += $z['recogidas'] + $z['pendientes'];

    echo "--- $nombre ($total paradas totales, " . count($zones) . " barrios) ---\n";
    foreach ($zones as $barrio => $counts) {
        $sum = $counts['recogidas'] + $counts['pendientes'];
        $pct = round($sum / $total * 100);
        echo "  $barrio: {$counts['recogidas']} recogidas + {$counts['pendientes']} pendientes = $sum ($pct%)\n";
    }
    echo "\n";
}

// Show all unique barrios
echo "\n=== TODOS LOS BARRIOS ===\n";
foreach ($allBarrios as $b) echo "  - $b\n";
