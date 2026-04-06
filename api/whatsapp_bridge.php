<?php
/**
 * WhatsApp Bot Bridge
 * ====================
 * Token-protected JSON endpoints called by the BF10 WhatsApp bot
 * (Node.js service hosted on Railway).
 *
 * Auth: HTTP header `Authorization: Bearer <BF10_BOT_TOKEN>`
 *       The token is shared between this server and the bot service
 *       and lives in api/secrets.php (gitignored).
 *
 * Endpoints:
 *   POST ?action=create-order
 *        Body JSON: { package_qty, name, nif?, email?, phone, address,
 *                     city, postal_code, observations?, payment_method,
 *                     request_invoice? }
 *        Returns:   { success, order:{code,...}, bank?{}, checkout_url? }
 *
 *   GET  ?action=order-status&code=BF10-XXXXXX
 *        Returns:   { success, order:{code,status,payment_method,...} }
 *
 *   POST ?action=create-stripe-link
 *        Body JSON: { code }
 *        Returns:   { success, checkout_url }
 *
 *   GET  ?action=ping
 *        Returns:   { success:true, ts }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stripe_helper.php';
require_once __DIR__ . '/mail_helper.php';

header('Content-Type: application/json; charset=utf-8');

// ---------- Auth ----------
function bridgeAuth() {
    if (!defined('BF10_BOT_TOKEN') || BF10_BOT_TOKEN === '') {
        jsonResponse(['error' => 'Bridge not configured'], 500);
    }
    $hdr = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $h = getallheaders();
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    if (!preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
        jsonResponse(['error' => 'Missing bearer token'], 401);
    }
    if (!hash_equals(BF10_BOT_TOKEN, trim($m[1]))) {
        jsonResponse(['error' => 'Invalid token'], 401);
    }
}

bridgeAuth();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ---------- Helpers ----------
function generateOrderCodeBridge() {
    $pdo = getDB();
    do {
        $code = 'BF10-' . strtoupper(substr(uniqid(), -6)) . rand(10, 99);
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

function findOrCreateUserBridge($email, $name, $phone) {
    $pdo = getDB();
    if (!$email) {
        // Bot users may not have email. Use phone-based pseudo email.
        $email = 'wa_' . preg_replace('/\D/', '', $phone) . '@whatsapp.bf10.local';
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row) return [$row['id'], $email];

    $hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, phone, role) VALUES (?, ?, ?, ?, 'user')");
    $stmt->execute([$email, $hash, $name, $phone]);
    return [$pdo->lastInsertId(), $email];
}

// ---------- Ping ----------
if ($method === 'GET' && $action === 'ping') {
    jsonResponse(['success' => true, 'ts' => date('c')]);
}

// ---------- Create order ----------
if ($method === 'POST' && $action === 'create-order') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'Invalid JSON'], 400);
    }

    $required = ['package_qty', 'name', 'phone', 'address', 'city', 'postal_code', 'payment_method'];
    foreach ($required as $f) {
        if (empty($data[$f])) jsonResponse(['error' => "Missing field: $f"], 400);
    }

    $qty = (int)$data['package_qty'];
    if (!isset(PACKAGES[$qty])) jsonResponse(['error' => 'Paquete no válido'], 400);
    $package = PACKAGES[$qty];

    $name         = sanitize($data['name']);
    $phone        = sanitize($data['phone']);
    $address      = sanitize($data['address']);
    $city         = sanitize($data['city']);
    $postalCode   = sanitize($data['postal_code']);
    $observations = sanitize($data['observations'] ?? '');
    $nif          = sanitize($data['nif'] ?? '');
    $email        = sanitize($data['email'] ?? '');
    $paymentMethod = sanitize($data['payment_method']);
    $requestInvoice = !empty($data['request_invoice']) ? 1 : 0;

    if (!preg_match('/^(28|45)\d{3}$/', $postalCode)) {
        jsonResponse(['error' => 'Código postal fuera de zona Madrid'], 400);
    }
    if (!in_array($paymentMethod, ['card', 'transfer'])) {
        jsonResponse(['error' => 'Método de pago no válido'], 400);
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Email no válido'], 400);
    }
    if ($nif && !preg_match('/^[A-Za-z]\d{7}[A-Za-z0-9]$|^\d{8}[A-Za-z]$/', $nif)) {
        jsonResponse(['error' => 'NIF/CIF no válido'], 400);
    }
    if ($requestInvoice && !$nif) {
        jsonResponse(['error' => 'Para factura se necesita NIF/CIF'], 400);
    }

    list($userId, $email) = findOrCreateUserBridge($email, $name, $phone);
    $orderCode = generateOrderCodeBridge();
    $status    = 'pendiente_pago';

    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_code, user_id, package_qty, package_name, package_price,
            name, nif, email, phone, address, city, postal_code, observations,
            request_invoice, payment_method, status, source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'whatsapp')
    ");

    try {
        $stmt->execute([
            $orderCode, $userId, $qty, $package['name'], $package['price'],
            $name, $nif, $email, $phone, $address, $city, $postalCode, $observations,
            $requestInvoice, $paymentMethod, $status
        ]);
        $orderId = $pdo->lastInsertId();
    } catch (PDOException $e) {
        jsonResponse(['error' => 'DB error: ' . $e->getMessage()], 500);
    }

    $response = [
        'success' => true,
        'order' => [
            'id'             => (int)$orderId,
            'code'           => $orderCode,
            'package'        => $package['name'],
            'price'          => $package['price'],
            'status'         => $status,
            'payment_method' => $paymentMethod,
        ],
    ];

    if ($paymentMethod === 'card') {
        $session = stripeCreateCheckoutSession([
            'id'           => $orderId,
            'order_code'   => $orderCode,
            'package_qty'  => $qty,
            'email'        => $email,
        ]);
        if (!empty($session['__error'])) {
            jsonResponse(['error' => 'Stripe error: ' . $session['__error']], 500);
        }
        $upd = $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?");
        $upd->execute([$session['id'], $orderId]);
        $response['checkout_url'] = $session['url'];
    }

    if ($paymentMethod === 'transfer') {
        $response['bank'] = [
            'iban'        => BANK_IBAN,
            'beneficiary' => BANK_BENEFICIARY, // bot will forward to customer privately
            'concept'     => $orderCode,
            'amount'      => number_format($package['price'], 2, ',', '.') . ' €',
        ];
    }

    // Send confirmation email if customer gave a real email and not card
    // (card emails go via stripe webhook)
    if ($paymentMethod !== 'card' && strpos($email, '@whatsapp.bf10.local') === false) {
        $sel = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $sel->execute([$orderId]);
        $full = $sel->fetch();
        if ($full) @sendOrderConfirmationEmail($full);
    }

    jsonResponse($response, 201);
}

// ---------- Order status ----------
if ($method === 'GET' && $action === 'order-status') {
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'code required'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT order_code, package_name, package_price, status, payment_method,
               name, address, city, postal_code, paid_at, created_at
        FROM orders WHERE order_code = ?
    ");
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    jsonResponse(['success' => true, 'order' => $order]);
}

// ---------- Re-create stripe link ----------
if ($method === 'POST' && $action === 'create-stripe-link') {
    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'code required'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    if ($order['status'] === 'confirmado' || $order['status'] === 'enviado') {
        jsonResponse(['error' => 'Pedido ya pagado'], 400);
    }

    $session = stripeCreateCheckoutSession([
        'id'          => $order['id'],
        'order_code'  => $order['order_code'],
        'package_qty' => $order['package_qty'],
        'email'       => $order['email'],
    ]);
    if (!empty($session['__error'])) {
        jsonResponse(['error' => 'Stripe error: ' . $session['__error']], 500);
    }

    $upd = $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?");
    $upd->execute([$session['id'], $order['id']]);

    jsonResponse(['success' => true, 'checkout_url' => $session['url']]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
