<?php
/**
 * Minimal Stripe API client using curl.
 * No composer / SDK needed.
 */
require_once __DIR__ . '/config.php';

function stripeApi($endpoint, $params = [], $method = 'POST') {
    $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Stripe-Version: 2024-06-20',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } elseif ($method === 'GET' && !empty($params)) {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['__error' => 'curl: ' . $err, '__http_code' => 0];
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        $msg = $decoded['error']['message'] ?? 'Stripe error';
        return ['__error' => $msg, '__http_code' => $httpCode, 'raw' => $decoded];
    }

    return $decoded;
}

/**
 * Create a Checkout Session for an order.
 * Returns ['id' => 'cs_...', 'url' => 'https://checkout.stripe.com/...']
 */
function stripeCreateCheckoutSession($order) {
    $pkg = PACKAGES[$order['package_qty']];
    $amountCents = (int) round($pkg['price'] * 100);

    $params = [
        'mode' => 'payment',
        'payment_method_types[0]' => 'card',
        'line_items[0][price_data][currency]' => 'eur',
        'line_items[0][price_data][product_data][name]' => 'BF10 — ' . $pkg['name'],
        'line_items[0][price_data][product_data][description]' => 'Entrega y recogida en Madrid · ' . $pkg['description'],
        'line_items[0][price_data][unit_amount]' => $amountCents,
        'line_items[0][quantity]' => 1,
        'customer_email' => $order['email'],
        'client_reference_id' => $order['order_code'],
        'metadata[order_code]' => $order['order_code'],
        'metadata[order_id]' => $order['id'],
        'metadata[package_qty]' => $order['package_qty'],
        'success_url' => (!empty($order['source']) && $order['source'] === 'whatsapp')
            ? SITE_URL . '/pago-confirmado.html?code=' . $order['order_code'] . '&src=wa'
            : SITE_URL . '/?pedido=' . $order['order_code'] . '&pago=ok',
        'cancel_url' => (!empty($order['source']) && $order['source'] === 'whatsapp')
            ? SITE_URL . '/pago-confirmado.html?code=' . $order['order_code'] . '&src=wa&status=cancel'
            : SITE_URL . '/?pedido=' . $order['order_code'] . '&pago=cancelado#pedido',
        'locale' => 'es',
        'billing_address_collection' => 'auto',
    ];

    return stripeApi('checkout/sessions', $params);
}

/**
 * Verify Stripe webhook signature.
 * https://stripe.com/docs/webhooks/signatures
 */
function stripeVerifyWebhook($payload, $sigHeader, $secret, $tolerance = 300) {
    if (!$sigHeader) return false;

    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[$k][] = $v;
    }
    if (empty($parts['t'][0]) || empty($parts['v1'][0])) return false;

    $timestamp = (int) $parts['t'][0];
    if (abs(time() - $timestamp) > $tolerance) return false;

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($parts['v1'] as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
