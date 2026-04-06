<?php
/**
 * Invoice + Receipt endpoints.
 *
 * GET  ?action=download&code=BF10-XXXX           - PDF inline (admin or owner)
 * GET  ?action=download-receipt&code=BF10-XXXX   - non-fiscal receipt PDF (admin or owner)
 * POST ?action=issue            {code,send?}     - create invoice; admin or owner
 *                                                 if send=true also emails it
 * POST ?action=send             {code}           - send existing invoice email (admin only)
 * POST ?action=resend-confirmation {code}        - resend order confirmation (admin only)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/invoice_generator.php';
require_once __DIR__ . '/receipt_generator.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/events_helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function canAccessOrder($order) {
    if (isManager()) return true;
    if (isLoggedIn() && (int)$order['user_id'] === (int)$_SESSION['user_id']) return true;
    return false;
}

function findOrderByCode($code) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    return $stmt->fetch();
}

// ---------------- GET: Download invoice PDF ----------------
if ($method === 'GET' && $action === 'download') {
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) { http_response_code(400); exit('Código requerido'); }

    $order = findOrderByCode($code);
    if (!$order) { http_response_code(404); exit('Pedido no encontrado'); }
    if (!canAccessOrder($order)) { http_response_code(403); exit('Acceso denegado'); }

    renderInvoicePdf($code, 'I');
    exit;
}

// ---------------- GET: Download receipt PDF (non-fiscal) ----------------
if ($method === 'GET' && $action === 'download-receipt') {
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) { http_response_code(400); exit('Código requerido'); }

    $order = findOrderByCode($code);
    if (!$order) { http_response_code(404); exit('Pedido no encontrado'); }
    if (!canAccessOrder($order)) { http_response_code(403); exit('Acceso denegado'); }

    renderReceiptPdf($code, 'I');
    exit;
}

// ---------------- POST: Issue invoice (admin or owner) ----------------
// Body: { code, send: bool }
// If `send` is true the invoice is also emailed to the customer.
if ($method === 'POST' && $action === 'issue') {
    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    $sendEmail = !empty($data['send']);

    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $order = findOrderByCode($code);
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);
    if (!canAccessOrder($order)) jsonResponse(['error' => 'Acceso denegado'], 403);

    $result = getOrCreateInvoice($code);
    if (!$result) jsonResponse(['error' => 'No se pudo emitir la factura'], 500);

    // Detect whether this call was the one that actually created it
    // (by comparing issued_at against now). Cheap heuristic.
    $isNew = (time() - strtotime($result['invoice']['issued_at'])) < 5;
    if ($isNew) {
        logEvent($order['id'], 'invoice_issued',
            'Factura ' . $result['invoice']['invoice_number'] . ' emitida',
            isManager() ? 'admin' : 'user');
    }

    $sent = false;
    $sendError = null;
    if ($sendEmail) {
        try {
            $tmp = sys_get_temp_dir() . '/' . $result['invoice']['invoice_number'] . '.pdf';
            renderInvoicePdf($code, 'F', $tmp);
            $sent = sendInvoiceEmail($result['order'], $result['invoice'], $tmp);
            if (file_exists($tmp)) @unlink($tmp);

            if ($sent) {
                $pdo = getDB();
                $pdo->prepare("UPDATE invoices SET sent_at = NOW() WHERE id = ?")
                    ->execute([$result['invoice']['id']]);
                logEvent($order['id'], 'invoice_sent',
                    'Factura enviada por email a ' . $order['email'],
                    isManager() ? 'admin' : 'user');
            } else {
                $sendError = 'mail() returned false';
            }
        } catch (Exception $e) {
            $sendError = $e->getMessage();
        }
    }

    jsonResponse([
        'success'  => true,
        'invoice'  => $result['invoice'],
        'is_new'   => $isNew,
        'sent'     => $sent,
        'send_error' => $sendError,
    ]);
}

// ---------------- POST: Send existing invoice (admin only) ----------------
if ($method === 'POST' && $action === 'send') {
    requireManager();
    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $result = getOrCreateInvoice($code);
    if (!$result) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    $tmp = sys_get_temp_dir() . '/' . $result['invoice']['invoice_number'] . '.pdf';
    renderInvoicePdf($code, 'F', $tmp);

    $ok = sendInvoiceEmail($result['order'], $result['invoice'], $tmp);
    if (file_exists($tmp)) @unlink($tmp);

    if ($ok) {
        $pdo = getDB();
        $pdo->prepare("UPDATE invoices SET sent_at = NOW() WHERE id = ?")
            ->execute([$result['invoice']['id']]);
        logEvent($result['order']['id'], 'invoice_sent',
            'Factura enviada por email a ' . $result['order']['email'], 'admin');
        jsonResponse(['success' => true]);
    }
    jsonResponse(['error' => 'No se pudo enviar el email. Revisa el log.'], 500);
}

// ---------------- POST: Resend order confirmation email ----------------
if ($method === 'POST' && $action === 'resend-confirmation') {
    requireManager();
    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $order = findOrderByCode($code);
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    $ok = sendOrderConfirmationEmail($order);
    if ($ok) logEvent($order['id'], 'email_sent', 'Reenvío de confirmación de pedido', 'admin');

    jsonResponse($ok ? ['success' => true] : ['error' => 'Fallo al enviar'], $ok ? 200 : 500);
}

jsonResponse(['error' => 'Acción no válida'], 400);
