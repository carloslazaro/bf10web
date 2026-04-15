<?php
$token = 'chk_bf10_2026';
if (($_GET['t'] ?? '') !== $token) { http_response_code(403); die('No'); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_sheets.php';
header('Content-Type: text/plain; charset=utf-8');

$spreadsheetId = '1bAvqZrEpf-vjTQ9sJ7diOulRL3tB2wD0qWA8nl4Zo0s';
$range = "'Madrid+Pueblos'!A:Z";
$rows = sheetsRead($spreadsheetId, $range);

if (isset($rows['error'])) {
    echo "ERROR: " . $rows['error'];
    exit;
}

echo "Total filas en Excel (incluido header): " . count($rows) . "\n\n";

// Search for Araiz
echo "=== Buscando 'Araiz' en todo el Excel ===\n";
for ($i = 0; $i < count($rows); $i++) {
    $row = $rows[$i];
    $fullRow = implode(' | ', $row);
    if (stripos($fullRow, 'araiz') !== false) {
        echo "Fila " . ($i+1) . ": " . $fullRow . "\n";
    }
}

echo "\n=== Buscando 'JOSE DIAZ' como conductor (col F, idx 5) ===\n";
$jdCount = 0;
for ($i = 1; $i < count($rows); $i++) {
    $conductor = isset($rows[$i][5]) ? trim($rows[$i][5]) : '';
    if (stripos($conductor, 'JOSE DIAZ') !== false) {
        $dir = isset($rows[$i][0]) ? $rows[$i][0] : '';
        $barrio = isset($rows[$i][1]) ? $rows[$i][1] : '';
        $fechaRecog = isset($rows[$i][9]) ? $rows[$i][9] : '';
        $fechaAviso = isset($rows[$i][12]) ? $rows[$i][12] : '';
        $jdCount++;
        echo "Fila " . ($i+1) . ": dir=\"$dir\" barrio=\"$barrio\" f_recog=\"$fechaRecog\" f_aviso=\"$fechaAviso\"\n";
    }
}
echo "\nTotal JOSE DIAZ: $jdCount\n";
