<?php
/**
 * Stripe webhook receiver.
 * Configure in Stripe dashboard → Developers → Webhooks.
 * URL: https://sacosescombromadridbf10.es/api/stripe_webhook.php
 * Events: checkout.session.completed, payment_intent.payment_failed, checkout.session.expired
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stripe_helper.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/invoice_generator.php';
require_once __DIR__ . '/whatsapp_notify.php';

// Read raw body and signature header
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Log raw event for debugging (optional, small file)
@file_put_contents(
    __DIR__ . '/stripe_webhook.log',
    '[' . date('c') . "] " . substr($payload, 0, 500) . "\n",
    FILE_APPEND
);

// Verify signature (only if secret configured)
if (STRIPE_WEBHOOK_SECRET !== '') {
    if (!stripeVerifyWebhook($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
        http_response_code(400);
        echo 'Invalid signature';
        exit;
    }
}

$event = json_decode($payload, true);
if (!$event || empty($event['type'])) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$pdo = getDB();

switch ($event['type']) {
    case 'checkout.session.completed':
        $session = $event['data']['object'];
        $orderCode = $session['client_reference_id'] ?? ($session['metadata']['order_code'] ?? null);
        $paymentIntent = $session['payment_intent'] ?? null;
        $paid = ($session['payment_status'] ?? '') === 'paid';

        if ($orderCode && $paid) {
            $stmt = $pdo->prepare("
                UPDATE orders
                SET status = 'confirmado',
                    stripe_payment_intent = ?,
                    paid_at = NOW()
                WHERE order_code = ?
            ");
            $stmt->execute([$paymentIntent, $orderCode]);

            // Fetch full order and send emails
            $sel = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
            $sel->execute([$orderCode]);
            $order = $sel->fetch();
            if ($order) {
                @sendOrderConfirmationEmail($order);

                // WhatsApp notification: only for orders created from WhatsApp
                // (the `phone` field then matches the WA wa_id format).
                if (!empty($order['source']) && $order['source'] === 'whatsapp' && !empty($order['phone'])) {
                    @waNotify($order['phone'],
                        "✅ Pago recibido - Pedido " . $order['order_code'] . "\n\n" .
                        "Hemos confirmado el pago de tu pack " . $order['package_name'] . ".\n" .
                        "Te entregaremos los sacos en 24-48 horas en " . $order['address'] . ", " . $order['city'] . ".\n\n" .
                        "Cuando necesites recogida, escríbenos por aquí con la palabra 'recogida'."
                    );
                }

                // Mark any existing invoice for this order as paid
                $pdo->prepare("
                    UPDATE invoices SET payment_status = 'pagada', paid_at = NOW(), payment_method = 'tarjeta'
                    WHERE order_id = ? AND (payment_status IS NULL OR payment_status = 'pendiente' OR payment_status = '')
                ")->execute([$order['id']]);

                // If invoice requested, generate PDF + send it
                if (!empty($order['request_invoice'])) {
                    try {
                        $tmp = sys_get_temp_dir() . '/bf10_' . $orderCode . '.pdf';
                        $result = renderInvoicePdf($orderCode, 'F', $tmp);
                        if ($result && file_exists($tmp)) {
                            // Mark as paid + sent
                            $pdo->prepare("UPDATE invoices SET sent_at = NOW(), payment_status = 'pagada', paid_at = NOW(), payment_method = 'tarjeta' WHERE id = ?")
                                ->execute([$result['invoice']['id']]);
                            @sendInvoiceEmail($order, $result['invoice'], $tmp);
                            @unlink($tmp);
                        }
                    } catch (Exception $e) {
                        @file_put_contents(__DIR__ . '/stripe_webhook.log',
                            '[' . date('c') . "] invoice generation failed: " . $e->getMessage() . "\n",
                            FILE_APPEND);
                    }
                }
            }
        }
        break;

    case 'checkout.session.expired':
    case 'checkout.session.async_payment_failed':
    case 'payment_intent.payment_failed':
        $obj = $event['data']['object'];
        $orderCode = $obj['client_reference_id'] ?? ($obj['metadata']['order_code'] ?? null);
        if ($orderCode) {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'pendiente_pago' WHERE order_code = ? AND status != 'confirmado'");
            $stmt->execute([$orderCode]);
        }
        break;
}

http_response_code(200);
echo 'ok';
