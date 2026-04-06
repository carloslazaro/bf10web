<?php
/**
 * Receipt (RECIBO) PDF generator — non-fiscal proof of purchase.
 * Uses the same FPDF library as invoice_generator and shares the
 * COMPANY_* constants defined there.
 *
 * A "recibo" is NOT a Spanish fiscal invoice. It has no sequential number
 * and no IVA breakdown. It's a simple proof of payment for clients who
 * did not request a factura (e.g. private individuals).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/fpdf.php';
require_once __DIR__ . '/invoice_generator.php'; // for COMPANY_* constants and helpers

/**
 * Render a receipt PDF for the given order.
 * Destinations: 'I' (inline), 'D' (download), 'F' (save), 'S' (string)
 */
function renderReceiptPdf($orderCode, $destination = 'I', $filename = '') {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$orderCode]);
    $order = $stmt->fetch();
    if (!$order) return false;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);

    // === Header ===
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, utf8d('BF10 — SACOS DE ESCOMBRO'), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d(COMPANY_NAME), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 3.5, utf8d(COMPANY_LEGAL_NAME . ' · CIF: ' . COMPANY_CIF), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 4, utf8d(COMPANY_ADDR), 0, 1);
    $pdf->Cell(0, 4, utf8d(COMPANY_CITY . ', ' . COMPANY_COUNTRY), 0, 1);
    $pdf->Cell(0, 4, utf8d('Tel: ' . COMPANY_PHONE . '  ·  ' . COMPANY_EMAIL), 0, 1);
    $pdf->Cell(0, 4, utf8d(COMPANY_WEB), 0, 1);

    // === Receipt block ===
    $pdf->SetXY(130, 15);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(65, 8, utf8d('RECIBO'), 0, 2, 'R');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(65, 5, utf8d('Pedido: ') . $order['order_code'], 0, 2, 'R');
    $pdf->Cell(65, 5, utf8d('Fecha: ') . date('d/m/Y', strtotime($order['created_at'])), 0, 2, 'R');
    if (!empty($order['paid_at'])) {
        $pdf->Cell(65, 5, utf8d('Pagado: ') . date('d/m/Y', strtotime($order['paid_at'])), 0, 2, 'R');
    }

    // Separator
    $pdf->Ln(10);
    $pdf->SetDrawColor(218, 41, 28);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(6);

    // === Client ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8d('CLIENTE:'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, utf8d($order['name']), 0, 1);
    if (!empty($order['nif'])) {
        $pdf->Cell(0, 5, utf8d('NIF/CIF: ' . $order['nif']), 0, 1);
    }
    $pdf->Cell(0, 5, utf8d($order['address']), 0, 1);
    $pdf->Cell(0, 5, utf8d($order['postal_code'] . ' ' . $order['city']), 0, 1);
    if (!empty($order['email'])) $pdf->Cell(0, 5, utf8d('Email: ' . $order['email']), 0, 1);
    if (!empty($order['phone'])) $pdf->Cell(0, 5, utf8d('Tel: ' . $order['phone']), 0, 1);

    $pdf->Ln(8);

    // === Item ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(125, 8, utf8d('Concepto'), 1, 0, 'L', true);
    $pdf->Cell(20, 8, utf8d('Cant.'),   1, 0, 'C', true);
    $pdf->Cell(35, 8, utf8d('Importe'), 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 10);
    $concept = 'Pack ' . $order['package_name'] . ' - Entrega y recogida en Madrid';
    $pdf->Cell(125, 8, utf8d($concept), 1, 0, 'L');
    $pdf->Cell(20, 8, $order['package_qty'], 1, 0, 'C');
    $pdf->Cell(35, 8, money($order['package_price']), 1, 1, 'R');

    // Filler rows
    for ($i = 0; $i < 2; $i++) {
        $pdf->Cell(125, 6, '', 'LR', 0);
        $pdf->Cell(20, 6, '', 'LR', 0);
        $pdf->Cell(35, 6, '', 'LR', 1);
    }
    $pdf->Cell(180, 0, '', 'T', 1);

    $pdf->Ln(4);

    // === Total ===
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(218, 41, 28);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(115, 10, '', 0, 0);
    $pdf->Cell(30, 10, utf8d('TOTAL:'), 1, 0, 'R', true);
    $pdf->Cell(35, 10, money($order['package_price']), 1, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln(8);

    // === Payment + status ===
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, utf8d('FORMA DE PAGO'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, utf8d($order['payment_method'] === 'card'
        ? 'Tarjeta de crédito/débito (Stripe)'
        : 'Transferencia bancaria'), 0, 1);

    if (in_array($order['status'], ['confirmado', 'enviado'])) {
        $pdf->SetTextColor(0, 166, 81);
        $pdf->Cell(0, 5, utf8d('Estado: PAGADO'), 0, 1);
    } else {
        $pdf->SetTextColor(180, 110, 0);
        $pdf->Cell(0, 5, utf8d('Estado: PENDIENTE DE PAGO'), 0, 1);
    }
    $pdf->SetTextColor(0, 0, 0);

    // === Footer ===
    $pdf->SetY(-30);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4, utf8d(
        'Este documento es un recibo / justificante de compra. NO es una factura fiscal. '
      . 'Si necesita factura con NIF/CIF, puede solicitarla desde su área de cliente o escribiendo a '
      . COMPANY_EMAIL . '.'
    ), 0, 'C');

    $fname = 'recibo_' . $orderCode . '.pdf';

    if ($destination === 'F') {
        $pdf->Output('F', $filename);
        return ['order' => $order, 'path' => $filename];
    }
    if ($destination === 'S') {
        return ['order' => $order, 'pdf' => $pdf->Output('S', $fname)];
    }
    $pdf->Output($destination, $fname);
    return ['order' => $order];
}
