<?php
/**
 * Invoice PDF generator using FPDF.
 * Generates Spanish-style fiscal invoice with IVA breakdown.
 *
 * Brand-aware: company header / colour / invoice prefix come from
 * BRANDS[$order['brand']] (config.php). All four brands invoice under
 * the same fiscal entity (SERVISACO SL).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/fpdf.php';

// Legacy COMPANY_* constants kept for backward compatibility (mail_helper, etc.).
// Real per-brand data comes from BRANDS via getBrand() at render time.
const COMPANY_TRADE_NAME = 'BF10 Sacos de Escombro';
const COMPANY_LEGAL_NAME = 'SERVISACO Recuperación y Logística SL';
const COMPANY_NAME       = COMPANY_TRADE_NAME;
const COMPANY_CIF        = 'B26764688';
const COMPANY_ADDR       = 'Calle Totana, 8 - Puerta Dcha';
const COMPANY_CITY       = '28033 Madrid';
const COMPANY_COUNTRY    = 'España';
const COMPANY_PHONE      = '685 20 82 52';
const COMPANY_EMAIL      = 'pedidos@sacosescombromadridbf10.es';
const COMPANY_WEB        = 'sacosescombromadridbf10.es';

const IVA_RATE = 0.21; // 21% IVA

/**
 * Get or create an invoice record for a given order.
 * Invoice number is per-brand sequential per year:  {prefix}-{YYYY}-{NNNN}
 */
function getOrCreateInvoice($orderCode) {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$orderCode]);
    $order = $stmt->fetch();
    if (!$order) return null;

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $invoice = $stmt->fetch();
    if ($invoice) return ['order' => $order, 'invoice' => $invoice];

    $brandCode = $order['brand'] ?? 'BF10';
    $brand = getBrand($brandCode);
    $prefix = $brand['invoice_prefix'];

    // Sequential per brand+year — use MAX to avoid collisions
    $year = date('Y');
    $pattern = "$prefix-$year-%";
    $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $lastInv = $stmt->fetchColumn();
    $nextSeq = 1;
    if ($lastInv && preg_match('/-(\d+)$/', $lastInv, $m)) {
        $nextSeq = (int)$m[1] + 1;
    }
    $invoiceNumber = sprintf('%s-%s-%04d', $prefix, $year, $nextSeq);

    $totalInclIva = (float)$order['package_price'];
    $base = round($totalInclIva / (1 + IVA_RATE), 2);
    $iva  = round($totalInclIva - $base, 2);

    $stmt = $pdo->prepare("
        INSERT INTO invoices (order_id, invoice_number, brand, base_amount, iva_amount, total_amount, issued_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$order['id'], $invoiceNumber, $brandCode, $base, $iva, $totalInclIva]);

    $invoiceId = $pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();

    return ['order' => $order, 'invoice' => $invoice];
}

/**
 * Render invoice as PDF. $destination can be 'I' (inline), 'D' (download),
 * 'S' (return string), 'F' (save to file path in $filename).
 */
function renderInvoicePdf($orderCode, $destination = 'I', $filename = '') {
    $data = getOrCreateInvoice($orderCode);
    if (!$data) return false;

    $order = $data['order'];
    $invoice = $data['invoice'];
    $brand = getBrand($order['brand'] ?? 'BF10');
    [$br, $bg, $bb] = hexToRgb($brand['color']);

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);

    // === Header ===
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetTextColor($br, $bg, $bb);
    $pdf->Cell(0, 10, utf8d(strtoupper($brand['trade_name'])), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d($brand['trade_name']), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 3.5, utf8d($brand['legal_name'] . '  CIF: ' . $brand['cif']), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d($brand['address']), 0, 1);
    $pdf->Cell(0, 4, utf8d($brand['city'] . ', ' . $brand['country']), 0, 1);
    $pdf->Cell(0, 4, utf8d('Tel: ' . $brand['phone'] . '   ' . $brand['email']), 0, 1);
    $pdf->Cell(0, 4, utf8d($brand['web']), 0, 1);

    // === Invoice block (top right) ===
    $pdf->SetXY(130, 15);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(65, 8, utf8d('FACTURA'), 0, 2, 'R');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(65, 5, utf8d('Nº: ') . $invoice['invoice_number'], 0, 2, 'R');
    $pdf->Cell(65, 5, utf8d('Fecha: ') . date('d/m/Y', strtotime($invoice['issued_at'])), 0, 2, 'R');
    $pdf->Cell(65, 5, utf8d('Pedido: ') . $order['order_code'], 0, 2, 'R');

    // Separator
    $pdf->Ln(10);
    $pdf->SetDrawColor($br, $bg, $bb);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(6);

    // === Client block ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8d('FACTURAR A:'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, utf8d($order['name']), 0, 1);
    if (!empty($order['nif'])) {
        $pdf->Cell(0, 5, utf8d('NIF/CIF: ' . $order['nif']), 0, 1);
    }
    if (!empty($order['address'])) $pdf->Cell(0, 5, utf8d($order['address']), 0, 1);
    if (!empty($order['city']))    $pdf->Cell(0, 5, utf8d(($order['postal_code'] ?? '') . ' ' . $order['city']), 0, 1);
    if (!empty($order['email']))   $pdf->Cell(0, 5, utf8d('Email: ' . $order['email']), 0, 1);
    if (!empty($order['phone']))   $pdf->Cell(0, 5, utf8d('Tel: ' . $order['phone']), 0, 1);

    $pdf->Ln(8);

    // === Items table ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(95, 8, utf8d('Concepto'), 1, 0, 'L', true);
    $pdf->Cell(20, 8, utf8d('Cant.'),   1, 0, 'C', true);
    $pdf->Cell(30, 8, utf8d('Precio'),  1, 0, 'R', true);
    $pdf->Cell(35, 8, utf8d('Importe'), 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 10);
    $concept = 'Pack ' . $order['package_name'] . ' - Entrega y recogida en Madrid';
    $unitPrice = round($invoice['base_amount'] / max(1, (int)$order['package_qty']), 2);
    $pdf->Cell(95, 8, utf8d($concept), 1, 0, 'L');
    $pdf->Cell(20, 8, $order['package_qty'], 1, 0, 'C');
    $pdf->Cell(30, 8, money($unitPrice), 1, 0, 'R');
    $pdf->Cell(35, 8, money($invoice['base_amount']), 1, 1, 'R');

    for ($i = 0; $i < 2; $i++) {
        $pdf->Cell(95, 6, '', 'LR', 0);
        $pdf->Cell(20, 6, '', 'LR', 0);
        $pdf->Cell(30, 6, '', 'LR', 0);
        $pdf->Cell(35, 6, '', 'LR', 1);
    }
    $pdf->Cell(180, 0, '', 'T', 1);

    $pdf->Ln(4);

    // === Totals ===
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(115, 6, '', 0, 0);
    $pdf->Cell(30, 6, utf8d('Base imponible:'), 0, 0, 'R');
    $pdf->Cell(35, 6, money($invoice['base_amount']), 0, 1, 'R');

    $pdf->Cell(115, 6, '', 0, 0);
    $pdf->Cell(30, 6, utf8d('IVA (21%):'), 0, 0, 'R');
    $pdf->Cell(35, 6, money($invoice['iva_amount']), 0, 1, 'R');

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor($br, $bg, $bb);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(115, 10, '', 0, 0);
    $pdf->Cell(30, 10, utf8d('TOTAL:'), 1, 0, 'R', true);
    $pdf->Cell(35, 10, money($invoice['total_amount']), 1, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln(10);

    // === Payment info ===
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, utf8d('FORMA DE PAGO'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $paymentLabel = paymentLabel($order['payment_method']);
    $pdf->Cell(0, 5, utf8d($paymentLabel), 0, 1);
    if (in_array($order['status'], ['confirmado', 'enviado', 'recogida'])) {
        $pdf->SetTextColor(0, 166, 81);
        $pdf->Cell(0, 5, utf8d('Estado: PAGADA'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->SetTextColor(180, 110, 0);
        $pdf->Cell(0, 5, utf8d('Estado: PENDIENTE DE PAGO'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    }

    // === Footer note ===
    $pdf->SetY(-30);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4, utf8d(
        'Operación sujeta a IVA al 21%. Los precios incluyen entrega, recogida y gestión de residuos en vertedero autorizado.'
        . ' Para cualquier incidencia, contacte en ' . $brand['email'] . ' o al ' . $brand['phone'] . '.'
    ), 0, 'C');

    if ($destination === 'F') {
        $pdf->Output('F', $filename);
        return ['order' => $order, 'invoice' => $invoice, 'path' => $filename];
    }
    if ($destination === 'S') {
        return ['order' => $order, 'invoice' => $invoice, 'pdf' => $pdf->Output('S', $invoice['invoice_number'] . '.pdf')];
    }
    $pdf->Output($destination, $invoice['invoice_number'] . '.pdf');
    return ['order' => $order, 'invoice' => $invoice];
}

/**
 * Render invoice PDF for an albarán (delivery note).
 * Similar layout to order invoices but using albarán data.
 */
function renderAlbaranInvoicePdf($albaranId, $destination = 'I', $filename = '') {
    $pdo = getDB();

    // Get albaran
    $stmt = $pdo->prepare("SELECT * FROM albaranes WHERE id = ?");
    $stmt->execute([$albaranId]);
    $alb = $stmt->fetch();
    if (!$alb) return false;

    // Get invoice
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE albaran_id = ?");
    $stmt->execute([$albaranId]);
    $invoice = $stmt->fetch();
    if (!$invoice) return false;

    $brandCode = $alb['marca'] ?? 'BF10';
    $brand = getBrand($brandCode);
    [$br, $bg, $bb] = hexToRgb($brand['color']);

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);

    // === Header (company) ===
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetTextColor($br, $bg, $bb);
    $pdf->Cell(0, 10, utf8d(strtoupper($brand['trade_name'])), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d($brand['trade_name']), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 3.5, utf8d($brand['legal_name'] . '  CIF: ' . $brand['cif']), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d($brand['address']), 0, 1);
    $pdf->Cell(0, 4, utf8d($brand['city'] . ', ' . $brand['country']), 0, 1);
    $pdf->Cell(0, 4, utf8d('Tel: ' . $brand['phone'] . '   ' . $brand['email']), 0, 1);
    $pdf->Cell(0, 4, utf8d($brand['web']), 0, 1);

    // === Invoice block (top right) ===
    $pdf->SetXY(130, 15);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(65, 8, utf8d('FACTURA'), 0, 2, 'R');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(65, 5, utf8d('N' . chr(186) . ': ') . $invoice['invoice_number'], 0, 2, 'R');
    $pdf->Cell(65, 5, utf8d('Fecha: ') . date('d/m/Y', strtotime($invoice['issued_at'])), 0, 2, 'R');
    $pdf->Cell(65, 5, utf8d('Albar' . chr(225) . 'n: ') . $alb['albaran_code'], 0, 2, 'R');

    // Separator
    $pdf->Ln(10);
    $pdf->SetDrawColor($br, $bg, $bb);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(6);

    // === Client block ===
    // Look up full customer data from customers table by name
    $pdo2 = getDB();
    $custStmt = $pdo2->prepare("SELECT * FROM customers WHERE name = ? AND deleted_at IS NULL LIMIT 1");
    $custStmt->execute([$alb['cliente']]);
    $cust = $custStmt->fetch();

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8d('FACTURAR A:'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, utf8d($alb['cliente']), 0, 1);
    if ($cust) {
        if (!empty($cust['nif']))     $pdf->Cell(0, 5, utf8d('NIF/CIF: ' . $cust['nif']), 0, 1);
        if (!empty($cust['address'])) $pdf->Cell(0, 5, utf8d($cust['address']), 0, 1);
        if (!empty($cust['city']))    $pdf->Cell(0, 5, utf8d(($cust['postal_code'] ?? '') . ' ' . $cust['city']), 0, 1);
        if (!empty($cust['email']))   $pdf->Cell(0, 5, utf8d('Email: ' . $cust['email']), 0, 1);
        if (!empty($cust['phone']))   $pdf->Cell(0, 5, utf8d('Tel: ' . $cust['phone']), 0, 1);
    }

    $pdf->Ln(8);

    // === Items table ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(95, 8, utf8d('Concepto'), 1, 0, 'L', true);
    $pdf->Cell(20, 8, utf8d('Cant.'),   1, 0, 'C', true);
    $pdf->Cell(30, 8, utf8d('Precio'),  1, 0, 'R', true);
    $pdf->Cell(35, 8, utf8d('Importe'), 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 9);
    $concept = 'Sacas escombro ' . $alb['marca'];
    if ($alb['numeracion_inicial']) {
        $concept .= ' (' . $alb['numeracion_inicial'] . '-' . ($alb['numeracion_final'] ?? '') . ')';
    }
    $unitPrice = round($invoice['base_amount'] / max(1, (int)$alb['num_sacas']), 2);
    $pdf->Cell(95, 8, utf8d($concept), 1, 0, 'L');
    $pdf->Cell(20, 8, (string)$alb['num_sacas'], 1, 0, 'C');
    $pdf->Cell(30, 8, money($unitPrice), 1, 0, 'R');
    $pdf->Cell(35, 8, money($invoice['base_amount']), 1, 1, 'R');

    for ($i = 0; $i < 2; $i++) {
        $pdf->Cell(95, 6, '', 'LR', 0);
        $pdf->Cell(20, 6, '', 'LR', 0);
        $pdf->Cell(30, 6, '', 'LR', 0);
        $pdf->Cell(35, 6, '', 'LR', 1);
    }
    $pdf->Cell(180, 0, '', 'T', 1);

    $pdf->Ln(4);

    // === Totals ===
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(115, 6, '', 0, 0);
    $pdf->Cell(30, 6, utf8d('Base imponible:'), 0, 0, 'R');
    $pdf->Cell(35, 6, money($invoice['base_amount']), 0, 1, 'R');

    $pdf->Cell(115, 6, '', 0, 0);
    $pdf->Cell(30, 6, utf8d('IVA (21%):'), 0, 0, 'R');
    $pdf->Cell(35, 6, money($invoice['iva_amount']), 0, 1, 'R');

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor($br, $bg, $bb);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(115, 10, '', 0, 0);
    $pdf->Cell(30, 10, utf8d('TOTAL:'), 1, 0, 'R', true);
    $pdf->Cell(35, 10, money($invoice['total_amount']), 1, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln(10);

    // === Payment info ===
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, utf8d('FORMA DE PAGO'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pagoLabels = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia bancaria', 'pendiente' => 'Pendiente'];
    $pdf->Cell(0, 5, utf8d($pagoLabels[$alb['forma_pago']] ?? $alb['forma_pago']), 0, 1);
    if ($alb['pagado'] == 1) {
        $pdf->SetTextColor(0, 166, 81);
        $pdf->Cell(0, 5, utf8d('Estado: PAGADA'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->SetTextColor(180, 110, 0);
        $pdf->Cell(0, 5, utf8d('Estado: PENDIENTE DE PAGO'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    }

    // === Delivery info ===
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, utf8d('DATOS DE ENTREGA'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, utf8d('Fecha de entrega: ' . date('d/m/Y', strtotime($alb['fecha_entrega']))), 0, 1);

    // === Footer note ===
    $pdf->SetY(-30);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4, utf8d(
        'Operaci' . chr(243) . 'n sujeta a IVA al 21%.'
        . ' Para cualquier incidencia, contacte en ' . $brand['email'] . ' o al ' . $brand['phone'] . '.'
    ), 0, 'C');

    if ($destination === 'F') {
        $pdf->Output('F', $filename);
        return ['invoice' => $invoice, 'pdf' => null, 'path' => $filename];
    }
    if ($destination === 'S') {
        return ['invoice' => $invoice, 'pdf' => $pdf->Output('S', $invoice['invoice_number'] . '.pdf')];
    }
    $pdf->Output($destination, $invoice['invoice_number'] . '.pdf');
    return ['invoice' => $invoice];
}

/**
 * Robust UTF-8 → Windows-1252 converter for FPDF.
 * iconv with //TRANSLIT can return false on some hosting environments
 * (libiconv flavour mismatch). Falls back to mb_convert_encoding and
 * finally a hand-rolled stripping pass so the PDF never crashes.
 */
function utf8d($str) {
    if ($str === null || $str === '') return '';
    $str = (string)$str;

    // 1) Preferred path: iconv with TRANSLIT + IGNORE
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str);
    if ($out !== false && $out !== '') return $out;

    // 2) mb_convert_encoding fallback
    if (function_exists('mb_convert_encoding')) {
        $out = @mb_convert_encoding($str, 'Windows-1252', 'UTF-8');
        if ($out !== false && $out !== '') return $out;
    }

    // 3) Last resort: replace common accented chars and strip the rest
    $map = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
        '·'=>'-','—'=>'-','–'=>'-','€'=>'EUR','¿'=>'?','¡'=>'!',
    ];
    $str = strtr($str, $map);
    return preg_replace('/[^\x20-\x7E]/', '', $str);
}

function money($amount) {
    return number_format((float)$amount, 2, ',', '.') . ' EUR';
}

function paymentLabel($method) {
    switch ($method) {
        case 'card':     return 'Tarjeta de crédito/débito (Stripe)';
        case 'transfer': return 'Transferencia bancaria';
        case 'cash':     return 'Efectivo';
        default:         return $method;
    }
}

function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return [218, 41, 28]; // BF10 red default
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}
