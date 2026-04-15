<?php
$token = 'chk_bf10_2026';
if (($_GET['t'] ?? '') !== $token) { http_response_code(403); die('No'); }
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();

// Check Araiz row in DB
$stmt = $pdo->query("SELECT * FROM rutas_data WHERE direccion LIKE '%Araiz%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== Araiz en DB ===\n";
foreach ($rows as $r) {
    foreach ($r as $k => $v) {
        if ($v !== '' && $v !== null && $v !== '0') echo "  $k = $v\n";
    }
    echo "\n";
}

// Check the 8 extra Jose Diaz rows
$extras = ['Chopera', 'Lorca', 'comunidad de canarias', 'Araiz', 'azucenas', 'Micenas', 'Vereda de los Zapateros', 'Golondrina'];
echo "=== 8 paradas extra Jose Diaz ===\n";
foreach ($extras as $search) {
    $stmt = $pdo->prepare("SELECT id, row_order, direccion, barrio_cp, conductor, fecha_aviso, fecha_recogida, avisador, estado, created_at FROM rutas_data WHERE direccion LIKE ?");
    $stmt->execute(["%$search%"]);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($found as $r) {
        echo "id={$r['id']} orden={$r['row_order']} dir=\"{$r['direccion']}\" cond={$r['conductor']} f_aviso={$r['fecha_aviso']} f_recog={$r['fecha_recogida']} avisador={$r['avisador']} estado={$r['estado']} created={$r['created_at']}\n";
    }
}

// Also: total rows by conductor JOSE DIAZ
echo "\n=== Total JOSE DIAZ en DB ===\n";
$stmt = $pdo->query("SELECT COUNT(*) as c, fecha_recogida FROM rutas_data WHERE conductor LIKE '%JOSE DIAZ%' GROUP BY fecha_recogida ORDER BY fecha_recogida");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  fecha_recog='" . $r['fecha_recogida'] . "' count=" . $r['c'] . "\n";
}
