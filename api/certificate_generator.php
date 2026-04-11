<?php
/**
 * Certificate of Waste Management (Certificado de Gestión de Residuos)
 * PDF generator. Issued for free when the order has been picked up
 * (status = 'recogida') and the customer has requested it.
 *
 * Spanish RCD certificate — proof that the waste from a construction
 * site has been managed at an authorized treatment plant. Includes:
 *  - Productor del residuo (the customer)
 *  - Gestor (BF10 / SERVISACO)
 *  - Code LER (17 09 04 — Mezcla de residuos de construcción y demolición)
 *  - Quantity, dates, certificate number
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/fpdf.php';
require_once __DIR__ . '/invoice_generator.php'; // for COMPANY_* constants and helpers

// Default LER code for mixed construction & demolition waste
const LER_CODE = '17 09 04';
const LER_DESC = 'Residuos mezclados de construcción y demolición distintos de los especificados en los códigos 17 09 01, 17 09 02 y 17 09 03';

// Certified treatment plant where the waste is delivered
const CERT_GESTOR_NAME    = 'SERVISACO Recuperación y Logística SL';
const CERT_GESTOR_NIMA    = 'B26764688'; // CIF (NIMA real pendiente — sustituir si procede)
const CERT_PLANT_NAME     = 'Planta de tratamiento autorizada — Comunidad de Madrid';
const CERT_AUTH_NUMBER    = 'GE/RCD/CM (autorización en vigor)';

/**
 * Ensure a certificate number is assigned to the order. Returns the order row.
 * Creates the number lazily so a request without issuance does not consume one.
 */
function assignCertificateNumber($orderId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return null;
    if (!empty($order['certificate_number'])) return $order;

    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE certificate_number LIKE ?");
    $stmt->execute(["CERT-$year-%"]);
    $next = ((int)$stmt->fetch()['c']) + 1;
    $number = sprintf('CERT-%s-%04d', $year, $next);

    $upd = $pdo->prepare("UPDATE orders SET certificate_number = ? WHERE id = ?");
    $upd->execute([$number, $orderId]);

    $order['certificate_number'] = $number;
    return $order;
}

/**
 * Render certificate as PDF.
 * $destination: 'I' (inline), 'D' (download), 'F' (save to $filename), 'S' (return string)
 */
function renderCertificatePdf($orderCode, $destination = 'I', $filename = '') {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$orderCode]);
    $order = $stmt->fetch();
    if (!$order) return false;

    // Only orders that have been picked up can have a certificate
    if ($order['status'] !== 'recogida') {
        return ['error' => 'Solo se puede emitir el certificado tras la recogida del residuo.'];
    }

    // Assign certificate number if not yet present
    $order = assignCertificateNumber($order['id']);

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 18);

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

    // Certificate block (top right)
    $pdf->SetXY(125, 15);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(70, 8, utf8d('CERTIFICADO RCD'), 0, 2, 'R');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(70, 5, utf8d('Nº: ') . $order['certificate_number'], 0, 2, 'R');
    $pdf->Cell(70, 5, utf8d('Fecha emisión: ') . date('d/m/Y'), 0, 2, 'R');
    $pdf->Cell(70, 5, utf8d('Pedido: ') . $order['order_code'], 0, 2, 'R');

    // Separator
    $pdf->Ln(8);
    $pdf->SetDrawColor(218, 41, 28);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(8);

    // === Title ===
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 8, utf8d('CERTIFICADO DE GESTIÓN DE RESIDUOS'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 5, utf8d('Residuos de Construcción y Demolición (RCD)'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(6);

    // === Productor ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8d('PRODUCTOR / POSEEDOR DEL RESIDUO'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, utf8d($order['name']), 0, 1);
    if (!empty($order['nif'])) $pdf->Cell(0, 5, utf8d('NIF/CIF: ' . $order['nif']), 0, 1);
    $pdf->Cell(0, 5, utf8d('Dirección de la obra: ' . $order['address']), 0, 1);
    $pdf->Cell(0, 5, utf8d($order['postal_code'] . ' ' . $order['city']), 0, 1);
    if (!empty($order['email'])) $pdf->Cell(0, 5, utf8d('Email: ' . $order['email']), 0, 1);
    if (!empty($order['phone'])) $pdf->Cell(0, 5, utf8d('Tel: ' . $order['phone']), 0, 1);
    $pdf->Ln(4);

    // === Gestor ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8d('GESTOR AUTORIZADO'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, utf8d(CERT_GESTOR_NAME), 0, 1);
    $pdf->Cell(0, 5, utf8d('CIF: ' . CERT_GESTOR_NIMA), 0, 1);
    $pdf->Cell(0, 5, utf8d(COMPANY_ADDR . ' · ' . COMPANY_CITY), 0, 1);
    $pdf->Cell(0, 5, utf8d('Autorización: ' . CERT_AUTH_NUMBER), 0, 1);
    $pdf->Cell(0, 5, utf8d('Destino del residuo: ' . CERT_PLANT_NAME), 0, 1);
    $pdf->Ln(4);

    // === Residuo ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8d('RESIDUO GESTIONADO'), 0, 1);

    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(35, 7, utf8d('Código LER'), 1, 0, 'C', true);
    $pdf->Cell(95, 7, utf8d('Descripción'), 1, 0, 'C', true);
    $pdf->Cell(20, 7, utf8d('Cant.'),       1, 0, 'C', true);
    $pdf->Cell(30, 7, utf8d('Unidad'),      1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 9);
    $y0 = $pdf->GetY();
    $pdf->Cell(35, 14, LER_CODE, 1, 0, 'C');
    $x = $pdf->GetX();
    $pdf->MultiCell(95, 4.5, utf8d(LER_DESC), 1, 'L');
    $pdf->SetXY($x + 95, $y0);
    $pdf->Cell(20, 14, $order['package_qty'], 1, 0, 'C');
    $pdf->Cell(30, 14, utf8d('sacas'), 1, 1, 'C');
    $pdf->Ln(4);

    // Dates
    $deliveredAt = !empty($order['paid_at']) ? date('d/m/Y', strtotime($order['paid_at'])) : date('d/m/Y', strtotime($order['created_at']));
    $pickedUpAt  = !empty($order['picked_up_at']) ? date('d/m/Y', strtotime($order['picked_up_at'])) : date('d/m/Y');

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, utf8d('Fecha de entrega de las sacas: ' . $deliveredAt), 0, 1);
    $pdf->Cell(0, 5, utf8d('Fecha de recogida y traslado a planta: ' . $pickedUpAt), 0, 1);
    $pdf->Ln(4);

    // === Declaración ===
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8d('DECLARACIÓN'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4.5, utf8d(
        'Por la presente, ' . COMPANY_LEGAL_NAME . ', con CIF ' . COMPANY_CIF . ', en su condición de '
      . 'gestor autorizado de residuos no peligrosos de construcción y demolición (RCD), CERTIFICA que '
      . 'ha recogido del productor arriba identificado los residuos correspondientes al pedido '
      . $order['order_code'] . ', y los ha trasladado a planta de tratamiento autorizada por la '
      . 'Comunidad de Madrid para su valorización y/o eliminación conforme a la normativa vigente '
      . '(Real Decreto 105/2008, de 1 de febrero, y Ley 7/2022 de residuos y suelos contaminados).'
    ), 0, 'J');

    $pdf->Ln(4);
    $pdf->MultiCell(0, 4.5, utf8d(
        'Y para que así conste a los efectos oportunos ante el productor del residuo, las administraciones '
      . 'competentes y cualquier tercero interesado, se expide el presente certificado en Madrid, en la fecha '
      . 'arriba indicada.'
    ), 0, 'J');

    // === Signature ===
    $pdf->Ln(12);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, utf8d('Fdo. ' . COMPANY_LEGAL_NAME), 0, 1, 'R');
    $pdf->Cell(0, 5, utf8d('CIF: ' . COMPANY_CIF), 0, 1, 'R');

    // === Footer ===
    $pdf->SetY(-22);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell(0, 3.5, utf8d(
        'Documento emitido electrónicamente. Verificación: ' . COMPANY_EMAIL . ' indicando '
      . 'el número de certificado. Conserve este documento como prueba de la correcta gestión '
      . 'de los residuos de su obra.'
    ), 0, 'C');

    $fname = 'certificado_' . $orderCode . '.pdf';

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

/**
 * Issue certificate: assigns number, generates PDF, saves to disk,
 * stores file path + issued_at on the order. Idempotent.
 * Returns ['order' => ..., 'path' => ..., 'newly_issued' => bool] or false.
 */
function issueCertificate($orderCode) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$orderCode]);
    $order = $stmt->fetch();
    if (!$order) return false;
    if ($order['status'] !== 'recogida') {
        return ['error' => 'No se puede emitir el certificado: la saca aún no ha sido recogida.'];
    }

    // Build storage dir
    $dir = __DIR__ . '/../storage/certificates';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $path = $dir . '/certificado_' . $order['order_code'] . '.pdf';

    $alreadyIssued = !empty($order['certificate_issued_at']) && file_exists($path);
    if ($alreadyIssued) {
        return ['order' => $order, 'path' => $path, 'newly_issued' => false];
    }

    $result = renderCertificatePdf($order['order_code'], 'F', $path);
    if (!$result || isset($result['error'])) return $result ?: false;

    $rel = 'storage/certificates/certificado_' . $order['order_code'] . '.pdf';
    $upd = $pdo->prepare("
        UPDATE orders
        SET certificate_issued_at = NOW(),
            certificate_file_path = ?
        WHERE id = ?
    ");
    $upd->execute([$rel, $order['id']]);

    // Refresh order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$order['id']]);
    $order = $stmt->fetch();

    return ['order' => $order, 'path' => $path, 'newly_issued' => true];
}
