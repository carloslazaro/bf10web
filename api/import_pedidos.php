<?php
/**
 * One-time import of purchase orders from spreadsheet data.
 * DELETE after running.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = getDB();

// Ensure table exists (run migrate_v12 logic inline)
$check = $pdo->query("SHOW TABLES LIKE 'pedidos_proveedor'");
if ($check->rowCount() === 0) {
    $pdo->exec("
        CREATE TABLE pedidos_proveedor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(30) NOT NULL,
            marca VARCHAR(40) NOT NULL DEFAULT 'BF10',
            cantidad INT NOT NULL DEFAULT 0,
            numeracion_inicial INT DEFAULT NULL,
            numeracion_final INT DEFAULT NULL,
            proveedor VARCHAR(200) NOT NULL DEFAULT '',
            fecha_pedido DATE DEFAULT NULL,
            fecha_prevista_entrega DATE DEFAULT NULL,
            fecha_real_entrega DATE DEFAULT NULL,
            estado ENUM('borrador','pedido_hecho','en_almacen_proveedor','recibido') NOT NULL DEFAULT 'borrador',
            comentarios TEXT,
            user_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_estado (estado),
            INDEX idx_marca (marca),
            INDEX idx_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "Table pedidos_proveedor created.\n";
} else {
    echo "Table pedidos_proveedor already exists.\n";
}

// Check if already imported
$count = $pdo->query("SELECT COUNT(*) FROM pedidos_proveedor")->fetchColumn();
if ($count > 0) {
    echo "SKIP: Table already has $count rows. Delete them first if you want to re-import.\n";
    exit;
}

// Map marca names from spreadsheet to DB values
function mapMarca($raw) {
    $map = [
        'ECOSACO' => 'ECOSACO',
        'SERVISACO' => 'SERVISACO',
        'ATUSACO' => 'ATUSACO',
        'BF10' => 'BF10',
        'ATUSACO HERRERO' => 'ATUSACO_HERREROCON',
        'ATUSACO LUIS FER' => 'ATUSACO_LUISFER',
        'ATUSACO+ROSA' => 'ATUSACO+ROSA',
    ];
    return $map[trim($raw)] ?? trim($raw);
}

// Parse date from various formats
function parseDate($raw) {
    $raw = trim($raw);
    if (!$raw || $raw === 'FALTA FECHA') return null;

    // DD/MM/YYYY format
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    // "1-month" format (Spanish month names) — assume 2026
    $months = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];
    if (preg_match('/^(\d{1,2})-(\w+)$/i', $raw, $m)) {
        $monthName = strtolower($m[2]);
        if (isset($months[$monthName])) {
            return sprintf('2026-%02d-%02d', $months[$monthName], (int)$m[1]);
        }
    }

    return null;
}

// Determine estado based on data
function determineEstado($fechaReal, $notasCarlos) {
    if ($fechaReal) return 'recibido';
    $n = strtolower($notasCarlos);
    if (strpos($n, 'estan en') !== false || strpos($n, 'esta en') !== false) {
        return 'en_almacen_proveedor';
    }
    return 'pedido_hecho';
}

// Generate sequential codes
function nextCode($pdo, &$counter) {
    $counter++;
    return 'PED-2026-' . str_pad($counter, 4, '0', STR_PAD_LEFT);
}

// The data rows
$rows = [
    ['ECOSACO', 'TECNOPAKING', 3000, 'FALTA FECHA', '1-enero', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000 KGS VERDE 850X850X900 MM TP/FP "ECOSACO-2023"'],
    ['SERVISACO', 'TECNOPAKING', 3000, 'FALTA FECHA', '1-enero', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', ''],
    ['ATUSACO', 'TECNOPAKING', 1000, '12/02/2026', '1-febrero', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000 KGS 850X850X900 MM TP/FP "A TU SACO-4S"'],
    ['ATUSACO', 'TECNOPAKING', 1500, '24/02/2026', '1-marzo', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000 KGS 850X850X900 MM TP/FP "A TU SACO-4S"'],
    ['ATUSACO', 'TECNOPAKING', 2500, '26/02/2026', '1-marzo', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000 KGS 850X850X900 MM TP/FP "A TU SACO-4S"'],
    ['BF10', 'TECNOPAKING', 500, '12/02/2026', '1-febrero', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000 KGS DE 850X850X900 MM. TP/FP + ETI "BF10"'],
    ['BF10', 'TECNOPAKING', 1500, '15/02/2026', '1-febrero', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', ''],
    ['SERVISACO', 'TECNOPAKING', 1500, '24/02/2026', '1-marzo', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000KGS NARANJA 850X850X900 M TP/FP +ETI "SERV-4S"'],
    ['SERVISACO', 'TECNOPAKING', 2500, '26/02/2026', '1-marzo', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000KGS NARANJA 850X850X900 M TP/FP +ETI "SERV-4S"'],
    ['SERVISACO', 'TECNOPAKING', 1000, '09/03/2026', '1-marzo', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT. 1000KGS NARANJA 850X850X900 M TP/FP +ETI "SERV-4S"'],
    ['ATUSACO HERRERO', 'TECNOPAKING', 500, 'FALTA FECHA', '1-marzo', '01/04/2024', '', '', 'ESTAN EN TECKOPAKING', 'BB REUT 1000 KGS 850X850X900 MM TP/FP "A TU SACO+HERREROCON"'],
    ['SERVISACO', 'DAVID GIL', 3000, '01/01/2026', '1-junio', '', '', '', 'ESTIMADO 20 SEMANAS DESDE ENERO', '85x85x90'],
    ['ATUSACO', 'DAVID GIL', 3000, 'FALTA FECHA', '1-junio', '', '', '', 'ESTIMADO 20 SEMANAS DESDE ENERO', '85x85x90'],
    ['ATUSACO LUIS FER', 'DAVID GIL', 1500, 'FALTA FECHA', '1-junio', '', '', '', 'ESTIMADO 20 SEMANAS DESDE ENERO', '85x85x90'],
    ['ATUSACO', 'DAVID GIL', 5940, '01/01/2026', '1-julio', '', '', '', 'ESTIMADO 20 SEMANAS DESDE ENERO', ''],
    ['ATUSACO+ROSA', 'DAVID GIL', 3240, '01/01/2026', '1-julio', '', '', '', 'ESTIMADO 20 SEMANAS DESDE ENERO', ''],
    ['ECOSACO', 'TECNOPAKING', 3000, '01/03/2026', '1-septiembre', '', '', '', 'PEDIDO EL 27/03/2026', 'BB REUT. 1000 KGS VERDE 850X850X900 MM TP/FP "ECOSACO-2026"'],
    ['ATUSACO', 'TECNOPAKING', 5000, '01/03/2026', '1-septiembre', '', '', '', 'PEDIDO EL 27/03/2026', 'BB REUT. 1000 KGS 850X850X900 MM TP/FP "A TU SACO-4S"'],
    ['SERVISACO', 'DISAKA', 10000, 'FALTA FECHA', '01/04/2026', '01/04/2024', '', '', '', 'ESTA EN DISAKA'],
];

$stmt = $pdo->prepare("
    INSERT INTO pedidos_proveedor (
        codigo, marca, cantidad, numeracion_inicial, numeracion_final,
        proveedor, fecha_pedido, fecha_prevista_entrega, fecha_real_entrega,
        estado, comentarios, user_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$counter = 0;
$imported = 0;

foreach ($rows as $r) {
    $marca = mapMarca($r[0]);
    $proveedor = $r[1];
    $cantidad = (int)$r[2];
    $fechaPedido = parseDate($r[3]);
    $fechaPrevista = parseDate($r[4]);
    $fechaReal = parseDate($r[5]);
    $numIni = $r[6] ? (int)$r[6] : null;
    $numFin = $r[7] ? (int)$r[7] : null;
    $notasCarlos = trim($r[8]);
    $notasPedido = trim($r[9]);

    // Combine notes into comentarios
    $comentarios = '';
    if ($notasCarlos && $notasPedido) {
        $comentarios = $notasCarlos . ' | ' . $notasPedido;
    } elseif ($notasCarlos) {
        $comentarios = $notasCarlos;
    } elseif ($notasPedido) {
        $comentarios = $notasPedido;
    }

    $estado = determineEstado($fechaReal, $notasCarlos . ' ' . $notasPedido);
    $code = nextCode($pdo, $counter);

    $stmt->execute([
        $code, $marca, $cantidad, $numIni, $numFin,
        $proveedor, $fechaPedido, $fechaPrevista, $fechaReal,
        $estado, $comentarios, 1, // user_id=1 (admin)
    ]);
    $imported++;

    echo "  $code: $marca x$cantidad ($proveedor) → $estado\n";
}

echo "\nDone. Imported $imported purchase orders.\n";
