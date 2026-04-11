<?php
/**
 * Simulación de geocoding — NO llama a Google API.
 * Lee el Excel actual y cuenta cuántas peticiones haría.
 * DELETE after running.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_sheets.php';

header('Content-Type: text/plain; charset=utf-8');

$SPREADSHEET_READ = '1vgHxxWdyxj1rjwZjIvOwpVO1Il8uNL0LDR7Z43nq23k';
$SHEET_NAME = 'Madrid+Pueblos';

echo "=== SIMULACIÓN GEOCODING ===\n\n";

// 1. Read from Excel
$range = "'" . $SHEET_NAME . "'!A:Z";
$rows = sheetsRead($SPREADSHEET_READ, $range);

if (isset($rows['error'])) {
    echo "ERROR leyendo sheets: " . $rows['error'] . "\n";
    exit;
}

$totalRows = count($rows) - 1; // minus header
echo "Total filas en Excel (sin cabecera): $totalRows\n\n";

// Apply same filtering as import
$validRows = [];
for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i] ?? [];
    $direccion = isset($row[0]) ? trim($row[0]) : '';
    $barrio    = isset($row[1]) ? trim($row[1]) : '';
    if ($direccion === '' && $barrio === '') continue;
    $validRows[] = $row;
}
echo "Filas válidas (no vacías): " . count($validRows) . "\n";

// Remove consecutive day-rows
$finalRows = [];
for ($i = 0; $i < count($validRows); $i++) {
    $dir = strtolower(trim($validRows[$i][0] ?? ''));
    $bar = trim($validRows[$i][1] ?? '');
    $isDayRow = (strpos($dir, 'dia') === 0) && $bar === '';

    if ($isDayRow) {
        $nextDir = isset($validRows[$i + 1]) ? strtolower(trim($validRows[$i + 1][0] ?? '')) : '';
        $nextBar = isset($validRows[$i + 1]) ? trim($validRows[$i + 1][1] ?? '') : '';
        $nextIsDayRow = (strpos($nextDir, 'dia') === 0) && $nextBar === '';
        if ($nextIsDayRow || !isset($validRows[$i + 1])) {
            continue;
        }
    }
    $finalRows[] = $validRows[$i];
}
echo "Filas después de filtrar day-rows consecutivas: " . count($finalRows) . "\n\n";

// Now simulate geocoding
$dayRows = 0;
$emptyAddress = 0;
$wouldGeocode = 0;
$samples = [];

for ($i = 0; $i < count($finalRows); $i++) {
    $row = $finalRows[$i];
    $direccion = trim($row[0] ?? '');
    $barrio = trim($row[1] ?? '');

    $dirLower = strtolower($direccion);
    $isDayRow = (strpos($dirLower, 'dia') === 0) && $barrio === '';

    if ($isDayRow) {
        $dayRows++;
        continue;
    }

    if ($direccion === '') {
        $emptyAddress++;
        continue;
    }

    // This row WOULD be geocoded
    $wouldGeocode++;

    // Build the query (same logic as the plan)
    $addr = preg_replace('/\b\d{6,}\b/', '', $direccion);
    $addr = preg_replace('/ubicacion/i', '', $addr);
    $addr = preg_replace('/\s+/', ' ', trim($addr));
    $addr = rtrim($addr, '-/ ');
    $query = $addr;
    if ($barrio) $query .= ', ' . $barrio;
    $query .= ', Madrid, Spain';

    // Collect samples
    if (count($samples) < 15) {
        $samples[] = [
            'original' => $direccion,
            'barrio' => $barrio,
            'query' => $query,
        ];
    }
}

echo "--- RESUMEN ---\n";
echo "Filas día (cabeceras):        $dayRows\n";
echo "Filas sin dirección:          $emptyAddress\n";
echo "Filas que se geocodificarían: $wouldGeocode\n";
echo "\n";
echo "Total peticiones a Google API: $wouldGeocode\n";
echo "Coste estimado: ~$" . number_format($wouldGeocode * 0.005, 2) . " (a $5/1000 requests)\n";
echo "Tiempo estimado (50ms entre llamadas): ~" . round($wouldGeocode * 0.05) . " segundos\n";

echo "\n--- PRIMERAS 15 MUESTRAS DE QUERIES ---\n\n";
foreach ($samples as $idx => $s) {
    echo ($idx + 1) . ". Original: " . $s['original'] . "\n";
    echo "   Barrio:   " . $s['barrio'] . "\n";
    echo "   Query:    " . $s['query'] . "\n\n";
}

echo "\nDone.\n";
