<?php
/**
 * Facturación Export API — CSV general + A3 Contabilidad format
 *
 * GET ?action=csv-general&from=&to=       — export facturas CSV general
 * GET ?action=csv-a3&from=&to=            — export A3 Contabilidad format
 * GET ?action=summary&from=&to=           — facturación summary (totals, pending, etc.)
 * GET ?action=list&from=&to=&status=      — list all invoices with filters
 * POST ?action=update-payment             — update payment status { id, status, method }
 */
require_once __DIR__ . '/config.php';

if (!isLoggedIn() || !isManager()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── List invoices with filters ──
if ($method === 'GET' && $action === 'list') {
    $from = sanitize($_GET['from'] ?? date('Y-01-01'));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));
    $status = sanitize($_GET['status'] ?? '');

    $where = ["i.issued_at BETWEEN ? AND ?"];
    $params = ["$from 00:00:00", "$to 23:59:59"];

    if ($status) {
        $where[] = "i.payment_status = ?";
        $params[] = $status;
    }

    $sent = sanitize($_GET['sent'] ?? '');
    if ($sent === 'yes') {
        $where[] = "i.sent_at IS NOT NULL";
    } elseif ($sent === 'no') {
        $where[] = "i.sent_at IS NULL";
    }

    $stmt = $pdo->prepare("
        SELECT i.*,
            COALESCE(o.name, alb.cliente, '') as cliente_nombre,
            COALESCE(o.nif, '') as cliente_nif,
            COALESCE(o.order_code, '') as order_code,
            COALESCE(alb.albaran_code, '') as albaran_code,
            (SELECT SUM(ab.total_amount) FROM abonos ab WHERE ab.invoice_id = i.id) as total_abonado
        FROM invoices i
        LEFT JOIN orders o ON o.id = i.order_id
        LEFT JOIN albaranes alb ON alb.id = i.albaran_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.issued_at DESC
    ");
    $stmt->execute($params);
    jsonResponse(['invoices' => $stmt->fetchAll()]);
}

// ── Summary ──
if ($method === 'GET' && $action === 'summary') {
    $from = sanitize($_GET['from'] ?? date('Y-01-01'));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_facturas,
            COALESCE(SUM(total_amount), 0) as total_facturado,
            COALESCE(SUM(base_amount), 0) as total_base,
            COALESCE(SUM(iva_amount), 0) as total_iva,
            SUM(CASE WHEN payment_status = 'pagada' THEN total_amount ELSE 0 END) as total_cobrado,
            SUM(CASE WHEN payment_status = 'pendiente' OR payment_status IS NULL OR payment_status = '' THEN total_amount ELSE 0 END) as total_pendiente,
            SUM(CASE WHEN payment_status = 'abonada' THEN total_amount ELSE 0 END) as total_abonado
        FROM invoices
        WHERE issued_at BETWEEN ? AND ?
    ");
    $stmt->execute(["$from 00:00:00", "$to 23:59:59"]);
    $summary = $stmt->fetch();

    // Abonos total
    $stmtAb = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM abonos WHERE issued_at BETWEEN ? AND ?");
    $stmtAb->execute(["$from 00:00:00", "$to 23:59:59"]);
    $summary['total_abonos'] = (float)$stmtAb->fetchColumn();

    // By brand
    $stmtBrand = $pdo->prepare("
        SELECT brand, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total
        FROM invoices WHERE issued_at BETWEEN ? AND ?
        GROUP BY brand ORDER BY total DESC
    ");
    $stmtBrand->execute(["$from 00:00:00", "$to 23:59:59"]);
    $summary['por_marca'] = $stmtBrand->fetchAll();

    // Monthly breakdown
    $stmtMonth = $pdo->prepare("
        SELECT DATE_FORMAT(issued_at, '%Y-%m') as mes,
               COUNT(*) as facturas, COALESCE(SUM(total_amount), 0) as total
        FROM invoices WHERE issued_at BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(issued_at, '%Y-%m') ORDER BY mes
    ");
    $stmtMonth->execute(["$from 00:00:00", "$to 23:59:59"]);
    $summary['mensual'] = $stmtMonth->fetchAll();

    jsonResponse($summary);
}

// ── Update payment status ──
if ($method === 'POST' && $action === 'update-payment') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    $status = sanitize($data['status'] ?? '');
    $paymentMethod = sanitize($data['method'] ?? '');

    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
    $validStatuses = ['pendiente', 'pagada', 'abonada', 'parcial'];
    if (!in_array($status, $validStatuses)) jsonResponse(['error' => 'Estado no válido'], 400);

    $sql = "UPDATE invoices SET payment_status = ?";
    $params = [$status];
    if ($status === 'pagada') {
        $sql .= ", paid_at = NOW()";
    }
    if ($paymentMethod) {
        $sql .= ", payment_method = ?";
        $params[] = $paymentMethod;
    }
    $sql .= " WHERE id = ?";
    $params[] = $id;

    $pdo->prepare($sql)->execute($params);
    jsonResponse(['success' => true]);
}

// ── CSV General ──
if ($method === 'GET' && $action === 'csv-general') {
    $from = sanitize($_GET['from'] ?? date('Y-01-01'));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare("
        SELECT i.*, COALESCE(o.name, alb.cliente, '') as cliente,
               COALESCE(o.nif, '') as nif,
               COALESCE(o.order_code, '') as pedido,
               COALESCE(alb.albaran_code, '') as albaran
        FROM invoices i
        LEFT JOIN orders o ON o.id = i.order_id
        LEFT JOIN albaranes alb ON alb.id = i.albaran_id
        WHERE i.issued_at BETWEEN ? AND ?
        ORDER BY i.issued_at
    ");
    $stmt->execute(["$from 00:00:00", "$to 23:59:59"]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="facturas_' . $from . '_' . $to . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nº Factura', 'Fecha', 'Cliente', 'NIF', 'Pedido', 'Albarán', 'Base', 'IVA%', 'IVA', 'Total', 'Estado Pago', 'Forma Pago', 'Marca'], ';');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['invoice_number'],
            substr($r['issued_at'], 0, 10),
            $r['cliente'],
            $r['nif'],
            $r['pedido'],
            $r['albaran'],
            number_format($r['base_amount'], 2, ',', ''),
            number_format($r['iva_rate'], 0),
            number_format($r['iva_amount'], 2, ',', ''),
            number_format($r['total_amount'], 2, ',', ''),
            $r['payment_status'] ?: 'pendiente',
            $r['payment_method'] ?: '-',
            $r['brand']
        ], ';');
    }
    fclose($out);
    exit;
}

// ── CSV A3 Contabilidad ──
// Format: Tipo;Serie;Numero;Fecha;CIF;Razon Social;Base Imponible;% IVA;Cuota IVA;Total;Tipo Operacion
if ($method === 'GET' && $action === 'csv-a3') {
    $from = sanitize($_GET['from'] ?? date('Y-01-01'));
    $to = sanitize($_GET['to'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare("
        SELECT i.*, COALESCE(o.name, alb.cliente, '') as cliente,
               COALESCE(o.nif, '') as nif
        FROM invoices i
        LEFT JOIN orders o ON o.id = i.order_id
        LEFT JOIN albaranes alb ON alb.id = i.albaran_id
        WHERE i.issued_at BETWEEN ? AND ?
        ORDER BY i.issued_at
    ");
    $stmt->execute(["$from 00:00:00", "$to 23:59:59"]);
    $rows = $stmt->fetchAll();

    // Also include abonos
    $stmtAb = $pdo->prepare("SELECT * FROM abonos WHERE issued_at BETWEEN ? AND ? ORDER BY issued_at");
    $stmtAb->execute(["$from 00:00:00", "$to 23:59:59"]);
    $abonos = $stmtAb->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="a3cont_' . $from . '_' . $to . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tipo', 'Serie', 'Numero', 'Fecha', 'CIF', 'Razon Social', 'Base Imponible', '% IVA', 'Cuota IVA', 'Total', 'Tipo Operacion'], ';');

    foreach ($rows as $r) {
        // Parse invoice number to get serie + numero
        $serie = 'A';
        $numero = $r['invoice_number'];
        if (preg_match('/^([A-Z]+)-?\d{4}-(\d+)/', $r['invoice_number'], $m)) {
            $serie = $m[1];
            $numero = $m[2];
        }

        fputcsv($out, [
            'FE', // Factura emitida
            $serie,
            $numero,
            date('d/m/Y', strtotime($r['issued_at'])),
            $r['nif'] ?: '',
            $r['cliente'],
            number_format($r['base_amount'], 2, ',', ''),
            number_format($r['iva_rate'], 0),
            number_format($r['iva_amount'], 2, ',', ''),
            number_format($r['total_amount'], 2, ',', ''),
            'S' // Servicio
        ], ';');
    }

    // Abonos as negative entries
    foreach ($abonos as $a) {
        $serie = 'AB';
        $numero = $a['abono_number'];
        if (preg_match('/AB-\d{4}-(\d+)/', $a['abono_number'], $m)) {
            $numero = $m[1];
        }

        fputcsv($out, [
            'AB', // Abono
            $serie,
            $numero,
            date('d/m/Y', strtotime($a['issued_at'])),
            $a['nif'] ?: '',
            $a['cliente'],
            number_format(-$a['base_amount'], 2, ',', ''),
            number_format($a['iva_rate'], 0),
            number_format(-$a['iva_amount'], 2, ',', ''),
            number_format(-$a['total_amount'], 2, ',', ''),
            'S'
        ], ';');
    }

    fclose($out);
    exit;
}

jsonResponse(['error' => 'Acción no válida'], 400);
