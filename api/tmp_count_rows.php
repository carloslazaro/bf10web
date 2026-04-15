<?php
// Temporary script to check row counts — delete after use
$token = 'chk_bf10_2026';
if (($_GET['t'] ?? '') !== $token) { http_response_code(403); die('No'); }

require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = getDB();

$total = (int)$pdo->query("SELECT COUNT(*) FROM rutas_data")->fetchColumn();
$pendientes = (int)$pdo->query("SELECT COUNT(*) FROM rutas_data WHERE (fecha_recogida IS NULL OR fecha_recogida = '')")->fetchColumn();
$recogidas = (int)$pdo->query("SELECT COUNT(*) FROM rutas_data WHERE fecha_recogida IS NOT NULL AND fecha_recogida != ''")->fetchColumn();
$dias = (int)$pdo->query("SELECT COUNT(*) FROM rutas_data WHERE LOWER(TRIM(direccion)) LIKE 'dia%' AND (barrio_cp IS NULL OR barrio_cp = '')")->fetchColumn();

// Sample last 20 rows
$sample = $pdo->query("SELECT id, row_order, LEFT(direccion,60) as dir, barrio_cp, fecha_aviso, fecha_recogida, estado FROM rutas_data ORDER BY row_order DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'total' => $total,
    'pendientes' => $pendientes,
    'recogidas' => $recogidas,
    'filas_dia' => $dias,
    'ultimas_20' => $sample
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
