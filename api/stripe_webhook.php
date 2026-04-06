<?php
/**
 * Stripe webhook receiver.
 * Configure in Stripe dashboard → Developers → Webhooks.
 * URL: https://sacosescombromadridbf10.es/api/stripe_webhook.php
 * Events: checkout.session.completed, payment_intent.payment_failed, checkout.session.expired
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stripe_helper.php';

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
