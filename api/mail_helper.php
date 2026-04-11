<?php
/**
 * Email helper. Uses authenticated SMTP (Sered cPanel mailbox) when SMTP_*
 * constants are present in secrets.php; falls back to PHP mail() otherwise.
 *
 * No external dependencies — raw socket SMTP client (PLAIN/LOGIN auth, SSL/TLS).
 */
require_once __DIR__ . '/config.php';

const MAIL_FROM_EMAIL = 'pedidos@sacosescombromadridbf10.es';
const MAIL_FROM_NAME  = 'BF10 Sacos de Escombro';
const MAIL_REPLY_TO   = 'pedidos@sacosescombromadridbf10.es';

/**
 * High-level send. Picks SMTP if configured, otherwise mail().
 * Always logs to email_log.
 *
 * @param string $to
 * @param string $subject
 * @param string $htmlBody
 * @param array|null $attachment ['path' => '...', 'name' => '...'] or null
 * @param string|null $fromName  Optional From display name (defaults to MAIL_FROM_NAME)
 * @return bool
 */
function sendMail($to, $subject, $htmlBody, $attachment = null, $fromName = null) {
    $fromName = $fromName ?: MAIL_FROM_NAME;
    $useSmtp = defined('SMTP_HOST') && SMTP_HOST && defined('SMTP_USER') && SMTP_USER;

    $error = null;
    $ok = false;
    try {
        if ($useSmtp) {
            $ok = smtpSend($to, $subject, $htmlBody, $attachment, $fromName, $error);
        } else {
            $ok = mailSendLegacy($to, $subject, $htmlBody, $attachment, $fromName);
            if (!$ok) $error = error_get_last()['message'] ?? 'mail() returned false';
        }
    } catch (Exception $e) {
        $ok = false;
        $error = $e->getMessage();
    }

    // Log to DB
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
            $ok ? null : $error,
        ]);
    } catch (PDOException $e) {
        // don't break the send flow if the log table is missing
    }

    return $ok;
}

/**
 * Build the raw RFC 5322 message (headers + MIME parts) used by both transports.
 */
function buildMimeMessage($to, $subject, $htmlBody, $attachment, $fromName) {
    $boundary = md5(uniqid('', true));
    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . MAIL_FROM_EMAIL . '>';
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . $subjectEnc;
    $headers[] = 'Reply-To: ' . MAIL_REPLY_TO;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'X-Mailer: BF10/1.0';
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . parse_url(SITE_URL, PHP_URL_HOST) . '>';

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
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($htmlBody));
    }

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * Raw-socket SMTP client. Returns true on success.
 * Supports SSL (port 465) and STARTTLS (587). LOGIN auth.
 */
function smtpSend($to, $subject, $htmlBody, $attachment, $fromName, &$error = null) {
    $host = SMTP_HOST;
    $port = (int)SMTP_PORT;
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl';
    $user = SMTP_USER;
    $pass = SMTP_PASS;

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) {
        $error = "SMTP connect failed [$errno]: $errstr";
        return false;
    }
    stream_set_timeout($sock, 20);

    $read = function() use ($sock) {
        $data = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };
    $expect = function($expectedCode) use ($read, &$error) {
        $resp = $read();
        if (strpos($resp, (string)$expectedCode) !== 0) {
            $error = trim($resp);
            return false;
        }
        return true;
    };

    if (!$expect(220)) { fclose($sock); return false; }

    $hostname = parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost';
    $write("EHLO $hostname");
    if (!$expect(250)) { fclose($sock); return false; }

    if ($secure === 'tls') {
        $write('STARTTLS');
        if (!$expect(220)) { fclose($sock); return false; }
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $error = 'STARTTLS handshake failed';
            fclose($sock);
            return false;
        }
        $write("EHLO $hostname");
        if (!$expect(250)) { fclose($sock); return false; }
    }

    $write('AUTH LOGIN');
    if (!$expect(334)) { fclose($sock); return false; }
    $write(base64_encode($user));
    if (!$expect(334)) { fclose($sock); return false; }
    $write(base64_encode($pass));
    if (!$expect(235)) { fclose($sock); return false; }

    $write('MAIL FROM:<' . MAIL_FROM_EMAIL . '>');
    if (!$expect(250)) { fclose($sock); return false; }
    $write('RCPT TO:<' . $to . '>');
    if (!$expect(250)) { fclose($sock); return false; }

    $write('DATA');
    if (!$expect(354)) { fclose($sock); return false; }

    $message = buildMimeMessage($to, $subject, $htmlBody, $attachment, $fromName);
    // Dot-stuff lines beginning with '.'
    $message = preg_replace("/(^|\r\n)\\./", "\\1..", $message);
    fwrite($sock, $message . "\r\n.\r\n");
    if (!$expect(250)) { fclose($sock); return false; }

    $write('QUIT');
    fclose($sock);
    return true;
}

/**
 * Legacy fallback using PHP mail() — used when SMTP is not configured.
 */
function mailSendLegacy($to, $subject, $htmlBody, $attachment, $fromName) {
    $boundary = md5(uniqid('', true));
    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . MAIL_FROM_EMAIL . '>';
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'Reply-To: ' . MAIL_REPLY_TO;
    $headers[] = 'X-Mailer: BF10/1.0';

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

    return @mail($to, $subjectEnc, $body, implode("\r\n", $headers));
}

/**
 * Order confirmation email (sent after order creation or payment).
 */
function sendOrderConfirmationEmail($order) {
    $GLOBALS['__mail_order_id'] = $order['id'];
    $GLOBALS['__mail_type'] = 'order_confirmation';

    $priceStr = number_format((float)$order['package_price'], 2, ',', '.') . ' €';
    $isCard = $order['payment_method'] === 'card';
    $paid = in_array($order['status'], ['confirmado', 'enviado', 'recogida']);

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

/**
 * Certificate of waste management email — sent automatically when an
 * order is marked as 'recogida' AND the customer requested a certificate.
 */
function sendCertificateEmail($order, $pdfPath) {
    $GLOBALS['__mail_order_id'] = $order['id'];
    $GLOBALS['__mail_type'] = 'certificate';

    $certNumber = $order['certificate_number'] ?? '';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#111;max-width:600px;margin:0 auto;padding:20px;">
        <div style="background:#111;color:#fff;padding:20px;text-align:center;">
            <h1 style="margin:0;color:#fff;">BF10 — Certificado RCD</h1>
        </div>
        <div style="padding:24px;background:#fff;">
            <h2>Tu certificado de gestión de residuos</h2>
            <p>Hola ' . htmlspecialchars($order['name']) . ',</p>
            <p>Hemos completado la recogida de las sacas de tu pedido <strong>' . htmlspecialchars($order['order_code']) . '</strong> y los residuos han sido trasladados a una planta de tratamiento autorizada por la Comunidad de Madrid.</p>
            <p>Adjuntamos el <strong>Certificado de Gestión de Residuos de Construcción y Demolición (RCD)</strong> que solicitaste, sin coste adicional.</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;">Nº de certificado:</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;"><strong>' . htmlspecialchars($certNumber) . '</strong></td></tr>
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;">Pedido:</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . htmlspecialchars($order['order_code']) . '</td></tr>
                <tr><td style="padding:8px 0;border-bottom:1px solid #eee;">Cantidad:</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . (int)$order['package_qty'] . ' sacas</td></tr>
                <tr><td style="padding:8px 0;">Código LER:</td><td style="padding:8px 0;text-align:right;">17 09 04</td></tr>
            </table>
            <p>Conserva este documento como prueba de la correcta gestión de los residuos de tu obra. Es válido frente al ayuntamiento, la administración autonómica y cualquier inspección.</p>
            <p>También puedes descargarlo desde <a href="' . SITE_URL . '/mi-cuenta/" style="color:#DA291C;">tu área de cliente</a>.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="font-size:13px;color:#666;">Gracias por confiar en BF10. Si necesitas otro pedido, llámanos al ' . CONTACT_PHONE . ' o entra en ' . SITE_URL . '.</p>
        </div>
        <div style="background:#f4f4f4;padding:16px;text-align:center;font-size:11px;color:#999;">
            BF10 Sacos de Escombro · ' . CONTACT_PHONE . ' · ' . SITE_URL . '
        </div>
    </body></html>';

    return sendMail(
        $order['email'],
        'Certificado de gestión de residuos ' . $certNumber . ' — BF10',
        $html,
        ['path' => $pdfPath, 'name' => 'certificado_' . $order['order_code'] . '.pdf']
    );
}
