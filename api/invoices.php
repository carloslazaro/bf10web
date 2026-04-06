<?php
/**
 * Invoice endpoints.
 *
 * GET  ?action=download&code=BF10-XXXX   — stream PDF to browser (admin or owner)
 * POST ?action=issue    {code}           — force-create invoice (admin only)
 * POST ?action=send     {code}           — send invoice email (admin only)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/invoice_generator.php';
require_once __DIR__ . '/mail_helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function canAccessOrder($order) {
    if (isManager()) return true;
    if (isLoggedIn() && (int)$order['user_id'] === (int)$_SESSION['user_id']) return true;
    return false;
}

// GET: Download invoice PDF
if ($method === 'GET' && $action === 'download') {
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) {
        http_response_code(400);
        exit('Código requerido');
    }

    // Verify order + access
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        exit('Pedido no encontrado');
    }
    if (!canAccessOrder($order)) {
        http_response_code(403);
        exit('Acceso denegado');
    }

    // Stream PDF inline
    renderInvoicePdf($code, 'I');
    exit;
}

// POST: Force-issue invoice (admin only)
if ($method === 'POST' && $action === 'issue') {
    requireManager();
    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $result = getOrCreateInvoice($code);
    if (!$result) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    jsonResponse(['success' => true, 'invoice' => $result['invoice']]);
}

// POST: Send invoice by email (admin only)
if ($method === 'POST' && $action === 'send') {
    requireManager();
    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $result = getOrCreateInvoice($code);
    if (!$result) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    // Generate PDF to temp file
    $tmp = sys_get_temp_dir() . '/' . $result['invoice']['invoice_number'] . '.pdf';
    renderInvoicePdf($code, 'F', $tmp);

    $ok = sendInvoiceEmail($result['order'], $result['invoice'], $tmp);

    if (file_exists($tmp)) @unlink($tmp);

    if ($ok) {
        // Mark sent_at
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE invoices SET sent_at = NOW() WHERE id = ?");
        $stmt->execute([$result['invoice']['id']]);
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['error' => 'No se pudo enviar el email. Revisa el log.'], 500);
    }
}

// POST: Resend order confirmation email (admin only)
if ($method === 'POST' && $action === 'resend-confirmation') {
    requireManager();
    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    $ok = sendOrderConfirmationEmail($order);
    jsonResponse($ok ? ['success' => true] : ['error' => 'Fallo al enviar'], $ok ? 200 : 500);
}

jsonResponse(['error' => 'Acción no válida'], 400);
