<?php
/**
 * Email helper using PHP mail() function.
 * Sends HTML emails with optional attachments.
 * On shared hosting (Sered cPanel) mail() uses local Exim.
 */
require_once __DIR__ . '/config.php';

const MAIL_FROM_EMAIL = 'pedidos@sacosescombromadridbf10.es';
const MAIL_FROM_NAME  = 'BF10 Sacos de Escombro';
const MAIL_REPLY_TO   = 'pedidos@sacosescombromadridbf10.es';

/**
 * Low-level mail sender. Supports HTML + optional single attachment.
 *
 * @param string $to
 * @param string $subject
 * @param string $htmlBody
 * @param array|null $attachment ['path' => '...', 'name' => '...'] or null
 * @return bool
 */
function sendMail($to, $subject, $htmlBody, $attachment = null) {
    $boundary = md5(uniqid('', true));
    $fromHeader = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . MAIL_FROM_EMAIL . '>';

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'Reply-To: ' . MAIL_REPLY_TO;
    $headers[] = 'X-Mailer: BF10/1.0';

    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if ($attachment && is_file($attachment['path'])) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        $fileContent = file_get_contents($attachment['path']);
        $fileName = $attachment['name'] ?? basename($attachment['path']);
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: application/pdf; name=\"$fileName\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n\r\n";
        $body .= chunk_split(base64_encode($fileContent)) . "\r\n";
        $body .= "--$boundary--";
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $body = $htmlBody;
    }

    $ok = @mail($to, $subjectEnc, $body, implode("\r\n", $headers));

    // Log
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO email_log (order_id, to_email, subject, type, status, error)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $GLOBALS['__mail_order_id'] ?? null,
            $to,
            $subject,
            $GLOBALS['__mail_type'] ?? 'generic',
            $ok ? 'sent' : 'failed',
            $ok ? null : error_get_last()['message'] ?? null,
        ]);
    } catch (PDOException $e) {
        // don't break mail flow if log table doesn't exist yet
    }

    return $ok;
}

/**
 * Order confirmation email (sent after order creation or payment).
 */
function sendOrderConfirmationEmail($order) {
    $GLOBALS['__mail_order_id'] = $order['id'];
    $GLOBALS['__mail_type'] = 'order_confirmation';

    $priceStr = number_format((float)$order['package_price'], 2, ',', '.') . ' €';
    $isCard = $order['payment_method'] === 'card';
    $paid = in_array($order['status'], ['confirmado', 'enviado']);

    $paymentBlock = $isCard && $paid
        ? '<p style="color:#00A651;font-weight:bold;">✓ Pago recibido. Tu pedido está confirmado.</p>'
        : ($isCard
            ? '<p>Tu pago con tarjeta se procesará a través de Stripe. Recibirás un email de confirmación cuando se complete.</p>'
            : '<div style="background:#f8f8f8;padding:12px 16px;border-left:3px solid #DA291C;margin:16px 0;">'
              . '<strong>Datos para la transferencia:</strong><br>'
              . 'IBAN: ' . BANK_IBAN . '<br>'
              . 'Beneficiario: ' . BANK_BENEFICIARY . '<br>'
              . 'Concepto: <strong>' . $order['order_code'] . '</strong><br>'
              . 'Importe: <strong>' . $priceStr . '</strong>'
              . '</div>');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#111;max-width:600px;margin:0 auto;padding:20px;">
        <div style="background:#111;color:#fff;padding:20px;text-align:center;">
            <h1 style="margin:0;color:#fff;">BF10 — Sacos de Escombro</h1>
        </div>
        <div style="padding:24px;background:#fff;">
            <h2>¡Gracias por tu pedido, ' . htmlspecialchars($order['name']) . '!</h2>
            <p>Hemos recibido tu solicitud correctamente. Aquí tienes el resumen:</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;"><strong>Código de pedido:</strong></td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . $order['order_code'] . '</td></tr>
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;"><strong>Pack:</strong></td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . htmlspecialchars($order['package_name']) . '</td></tr>
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;"><strong>Total (IVA incl.):</strong></td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;font-size:18px;color:#DA291C;"><strong>' . $priceStr . '</strong></td></tr>
                <tr><td style="padding:8px 0;"><strong>Dirección de entrega:</strong></td><td style="padding:8px 0;text-align:right;">' . htmlspecialchars($order['address']) . '<br>' . htmlspecialchars($order['postal_code'] . ' ' . $order['city']) . '</td></tr>
            </table>
            ' . $paymentBlock . '
            <p>Nos pondremos en contacto contigo para coordinar la entrega en 24-48 horas laborables.</p>
            <p>Puedes consultar el estado de tu pedido en cualquier momento desde <a href="' . SITE_URL . '/mi-cuenta/" style="color:#DA291C;">tu área de cliente</a>.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="font-size:13px;color:#666;">Si tienes cualquier duda, escríbenos a ' . MAIL_FROM_EMAIL . ' o llámanos al ' . CONTACT_PHONE . '.</p>
            <p style="font-size:13px;color:#666;">Gracias por confiar en BF10.</p>
        </div>
        <div style="background:#f4f4f4;padding:16px;text-align:center;font-size:11px;color:#999;">
            BF10 Sacos de Escombro · ' . CONTACT_PHONE . ' · ' . SITE_URL . '
        </div>
    </body></html>';

    return sendMail($order['email'], 'Pedido ' . $order['order_code'] . ' recibido — BF10', $html);
}

/**
 * Invoice email with PDF attachment.
 */
function sendInvoiceEmail($order, $invoice, $pdfPath) {
    $GLOBALS['__mail_order_id'] = $order['id'];
    $GLOBALS['__mail_type'] = 'invoice';

    $totalStr = number_format((float)$invoice['total_amount'], 2, ',', '.') . ' €';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#111;max-width:600px;margin:0 auto;padding:20px;">
        <div style="background:#111;color:#fff;padding:20px;text-align:center;">
            <h1 style="margin:0;color:#fff;">BF10 — Factura</h1>
        </div>
        <div style="padding:24px;background:#fff;">
            <h2>Factura ' . htmlspecialchars($invoice['invoice_number']) . '</h2>
            <p>Hola ' . htmlspecialchars($order['name']) . ',</p>
            <p>Adjuntamos la factura correspondiente a tu pedido <strong>' . $order['order_code'] . '</strong>.</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;">Número de factura:</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;"><strong>' . htmlspecialchars($invoice['invoice_number']) . '</strong></td></tr>
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;">Fecha de emisión:</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . date('d/m/Y', strtotime($invoice['issued_at'])) . '</td></tr>
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;">Base imponible:</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . number_format((float)$invoice['base_amount'], 2, ',', '.') . ' €</td></tr>
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;">IVA (21%):</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . number_format((float)$invoice['iva_amount'], 2, ',', '.') . ' €</td></tr>
                <tr><td style="padding:8px 0;"><strong>Total:</strong></td><td style="padding:8px 0;text-align:right;font-size:18px;color:#DA291C;"><strong>' . $totalStr . '</strong></td></tr>
            </table>
            <p>Puedes descargar la factura desde el archivo adjunto o desde <a href="' . SITE_URL . '/mi-cuenta/" style="color:#DA291C;">tu área de cliente</a>.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="font-size:13px;color:#666;">Si tienes cualquier duda fiscal o administrativa, escríbenos a ' . MAIL_FROM_EMAIL . '.</p>
        </div>
        <div style="background:#f4f4f4;padding:16px;text-align:center;font-size:11px;color:#999;">
            BF10 Sacos de Escombro · ' . CONTACT_PHONE . '<br>
            <span style="font-size:10px;">(Razón social fiscal en la factura adjunta)</span>
        </div>
    </body></html>';

    return sendMail(
        $order['email'],
        'Factura ' . $invoice['invoice_number'] . ' — BF10',
        $html,
        ['path' => $pdfPath, 'name' => $invoice['invoice_number'] . '.pdf']
    );
}
