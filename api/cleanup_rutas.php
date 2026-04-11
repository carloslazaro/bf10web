<?php
/**
 * One-time: delete all rutas_data rows after row 1025.
 * DELETE this file after running.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = getDB();

$total = $pdo->query("SELECT COUNT(*) FROM rutas_data")->fetchColumn();
echo "Total rows before: $total\n";

// Get the id of row 1025 (row_order-based)
$stmt = $pdo->query("SELECT id, row_order FROM rutas_data ORDER BY row_order ASC, id ASC LIMIT 1 OFFSET 1024");
$row1025 = $stmt->fetch();

if (!$row1025) {
    echo "Table has fewer than 1025 rows, nothing to delete.\n";
    exit;
}

echo "Row 1025: id={$row1025['id']}, row_order={$row1025['row_order']}\n";

// Delete everything after row 1025
$del = $pdo->prepare("DELETE FROM rutas_data WHERE row_order > ? OR (row_order = ? AND id > ?)");
$del->execute([$row1025['row_order'], $row1025['row_order'], $row1025['id']]);
$deleted = $del->rowCount();

$remaining = $pdo->query("SELECT COUNT(*) FROM rutas_data")->fetchColumn();
echo "Deleted: $deleted rows\n";
echo "Remaining: $remaining rows\n";
echo "Done.\n";
