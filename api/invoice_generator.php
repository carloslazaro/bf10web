<?php
/**
 * Invoice PDF generator using FPDF.
 * Generates Spanish-style fiscal invoice with IVA breakdown.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/fpdf.php';

// Company data
// IMPORTANTE: en una factura fiscal española la razón social legal y el CIF son obligatorios.
// COMPANY_TRADE_NAME es el nombre comercial visible en la cabecera; COMPANY_LEGAL_NAME es la
// razón social fiscal real que debe figurar en el documento por requerimiento legal.
const COMPANY_TRADE_NAME = 'BF10 Sacos de Escombro';
const COMPANY_LEGAL_NAME = 'SERVISACO Recuperación y Logística SL';
const COMPANY_NAME       = COMPANY_TRADE_NAME; // Mostrar nombre comercial en la cabecera
const COMPANY_CIF        = 'B26764688';
const COMPANY_ADDR       = 'Calle Totana, 8 - Puerta Dcha';
const COMPANY_CITY       = '28033 Madrid';
const COMPANY_COUNTRY    = 'España';
const COMPANY_PHONE      = '674 78 34 79';
const COMPANY_EMAIL      = 'pedidos@sacosescombromadridbf10.es';
const COMPANY_WEB        = 'sacosescombromadridbf10.es';

const IVA_RATE = 0.21; // 21% IVA

/**
 * Get or create an invoice record for a given order.
 * Returns the invoice row (array).
 */
function getOrCreateInvoice($orderCode) {
    $pdo = getDB();

    // Fetch order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$orderCode]);
    $order = $stmt->fetch();
    if (!$order) return null;

    // Check if invoice already exists
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $invoice = $stmt->fetch();

    if ($invoice) {
        return ['order' => $order, 'invoice' => $invoice];
    }

    // Generate sequential invoice number for the current year
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM invoices WHERE YEAR(issued_at) = ?");
    $stmt->execute([$year]);
    $nextSeq = ((int)$stmt->fetch()['c']) + 1;
    $invoiceNumber = sprintf('BF10-%s-%04d', $year, $nextSeq);

    // Calculate base + IVA from total (price includes IVA)
    $totalInclIva = (float)$order['package_price'];
    $base = round($totalInclIva / (1 + IVA_RATE), 2);
    $iva  = round($totalInclIva - $base, 2);

    $stmt = $pdo->prepare("
        INSERT INTO invoices (order_id, invoice_number, base_amount, iva_amount, total_amount, issued_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$order['id'], $invoiceNumber, $base, $iva, $totalInclIva]);

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

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);

    // === Header: company data ===
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, utf8d('BF10 — SACOS DE ESCOMBRO'), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d(COMPANY_NAME), 0, 1);
    // Razón social fiscal real (obligatoria por ley en facturas)
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 3.5, utf8d(COMPANY_LEGAL_NAME . ' · CIF: ' . COMPANY_CIF), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d(COMPANY_ADDR), 0, 1);
    $pdf->Cell(0, 4, utf8d(COMPANY_CITY . ', ' . COMPANY_COUNTRY), 0, 1);
    $pdf->Cell(0, 4, utf8d('Tel: ' . COMPANY_PHONE . '  ·  ' . COMPANY_EMAIL), 0, 1);
    $pdf->Cell(0, 4, utf8d(COMPANY_WEB), 0, 1);

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
    $pdf->SetDrawColor(218, 41, 28); // BF10 red
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
    $pdf->Cell(0, 5, utf8d($order['address']), 0, 1);
    $pdf->Cell(0, 5, utf8d($order['postal_code'] . ' ' . $order['city']), 0, 1);
    $pdf->Cell(0, 5, utf8d('Email: ' . $order['email']), 0, 1);
    $pdf->Cell(0, 5, utf8d('Tel: ' . $order['phone']), 0, 1);

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

    // Empty rows for visual balance
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
    $pdf->SetFillColor(218, 41, 28);
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
    $paymentLabel = $order['payment_method'] === 'card'
        ? 'Tarjeta de crédito/débito (Stripe)'
        : 'Transferencia bancaria';
    $pdf->Cell(0, 5, utf8d($paymentLabel), 0, 1);
    if ($order['status'] === 'confirmado' || $order['status'] === 'enviado') {
        $pdf->SetTextColor(0, 166, 81); // green
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
        . ' Para cualquier incidencia, contacte en ' . COMPANY_EMAIL . ' o al ' . COMPANY_PHONE . '.'
    ), 0, 'C');

    if ($destination === 'F') {
        $pdf->Output('F', $filename);
        return ['order' => $order, 'invoice' => $invoice, 'path' => $filename];
    }
    if ($destination === 'S') {
        return ['order' => $order, 'invoice' => $invoice, 'pdf' => $pdf->Output('S', $invoice['invoice_number'] . '.pdf')];
    }
    // 'I' or 'D'
    $pdf->Output($destination, $invoice['invoice_number'] . '.pdf');
    return ['order' => $order, 'invoice' => $invoice];
}

/** FPDF uses Windows-1252; convert UTF-8 strings. */
function utf8d($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $str);
}

function money($amount) {
    return number_format((float)$amount, 2, ',', '.') . ' EUR';
}
