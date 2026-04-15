<?php
/**
 * Abonos (Credit Notes) API
 *
 * GET  ?action=list              — list all abonos
 * POST ?action=create            — create abono from invoice/albaran
 * GET  ?action=download&id=N     — download abono PDF
 * GET  ?action=export-csv        — export all abonos as CSV
 */
require_once __DIR__ . '/config.php';

if (!isLoggedIn() || (!isManager() && !isComercial() && !isFacturacion())) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function nextAbonoNumber() {
    $pdo = getDB();
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT abono_number FROM abonos WHERE abono_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["AB-$year-%"]);
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/AB-\d{4}-(\d+)/', $last, $m)) {
        $next = (int)$m[1] + 1;
    } else {
        $next = 1;
    }
    return "AB-$year-" . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// List abonos
if ($method === 'GET' && $action === 'list') {
    $from = sanitize($_GET['from'] ?? '');
    $to = sanitize($_GET['to'] ?? '');

    $where = [];
    $params = [];
    if ($from) { $where[] = 'a.issued_at >= ?'; $params[] = "$from 00:00:00"; }
    if ($to) { $where[] = 'a.issued_at <= ?'; $params[] = "$to 23:59:59"; }

    $sql = "SELECT a.*, inv.invoice_number FROM abonos a LEFT JOIN invoices inv ON inv.id = a.invoice_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.issued_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['abonos' => $stmt->fetchAll()]);
}

// Create abono
if ($method === 'POST' && $action === 'create') {
    $data = json_decode(file_get_contents('php://input'), true);

    $invoiceId = (int)($data['invoice_id'] ?? 0);
    $albaranId = (int)($data['albaran_id'] ?? 0);
    $orderId = (int)($data['order_id'] ?? 0);
    $motivo = sanitize($data['motivo'] ?? '');
    $baseAmount = (float)($data['base_amount'] ?? 0);
    $ivaRate = (float)($data['iva_rate'] ?? 21);
    $cliente = sanitize($data['cliente'] ?? '');
    $nif = sanitize($data['nif'] ?? '');
    $brand = sanitize($data['brand'] ?? 'BF10');

    if (!$motivo) jsonResponse(['error' => 'Motivo requerido'], 400);
    if ($baseAmount <= 0) jsonResponse(['error' => 'Importe base debe ser > 0'], 400);

    // If from invoice, auto-fill from invoice data
    if ($invoiceId) {
        $inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $inv->execute([$invoiceId]);
        $invData = $inv->fetch();
        if ($invData) {
            if (!$baseAmount) $baseAmount = (float)$invData['base_amount'];
            $brand = $invData['brand'] ?: $brand;

            // Get client info from order or albaran
            if ($invData['order_id']) {
                $ord = $pdo->prepare("SELECT name, nif FROM orders WHERE id = ?");
                $ord->execute([$invData['order_id']]);
                $ordData = $ord->fetch();
                if ($ordData) { $cliente = $cliente ?: $ordData['name']; $nif = $nif ?: $ordData['nif']; }
            }
            if ($invData['albaran_id']) {
                $alb = $pdo->prepare("SELECT cliente FROM albaranes WHERE id = ?");
                $alb->execute([$invData['albaran_id']]);
                $albData = $alb->fetch();
                if ($albData) { $cliente = $cliente ?: $albData['cliente']; }
                $albaranId = $invData['albaran_id'];
            }
        }
    }

    $ivaAmount = round($baseAmount * $ivaRate / 100, 2);
    $totalAmount = $baseAmount + $ivaAmount;
    $abonoNumber = nextAbonoNumber();

    $stmt = $pdo->prepare("
        INSERT INTO abonos (abono_number, invoice_id, albaran_id, order_id, motivo, base_amount, iva_rate, iva_amount, total_amount, cliente, nif, brand)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $abonoNumber, $invoiceId ?: null, $albaranId ?: null, $orderId ?: null,
        $motivo, $baseAmount, $ivaRate, $ivaAmount, $totalAmount,
        $cliente, $nif, $brand
    ]);

    // Update invoice payment status if fully refunded
    if ($invoiceId) {
        $pdo->prepare("UPDATE invoices SET payment_status = 'abonada' WHERE id = ?")->execute([$invoiceId]);
    }

    jsonResponse(['success' => true, 'id' => $pdo->lastInsertId(), 'abono_number' => $abonoNumber]);
}

// Export CSV
if ($method === 'GET' && $action === 'export-csv') {
    $from = sanitize($_GET['from'] ?? date('Y-01-01'));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare("
        SELECT a.*, inv.invoice_number
        FROM abonos a LEFT JOIN invoices inv ON inv.id = a.invoice_id
        WHERE a.issued_at BETWEEN ? AND ?
        ORDER BY a.issued_at
    ");
    $stmt->execute(["$from 00:00:00", "$to 23:59:59"]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="abonos_' . $from . '_' . $to . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($out, ['Nº Abono', 'Fecha', 'Factura', 'Cliente', 'NIF', 'Motivo', 'Base', 'IVA%', 'IVA', 'Total', 'Marca'], ';');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['abono_number'],
            substr($r['issued_at'], 0, 10),
            $r['invoice_number'] ?: '-',
            $r['cliente'],
            $r['nif'],
            $r['motivo'],
            number_format($r['base_amount'], 2, ',', ''),
            number_format($r['iva_rate'], 0),
            number_format($r['iva_amount'], 2, ',', ''),
            number_format($r['total_amount'], 2, ',', ''),
            $r['brand']
        ], ';');
    }
    fclose($out);
    exit;
}

jsonResponse(['error' => 'Acción no válida'], 400);
