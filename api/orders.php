<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stripe_helper.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/certificate_generator.php';
require_once __DIR__ . '/events_helper.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Generate unique order code
function generateOrderCode() {
    $pdo = getDB();
    do {
        $code = 'BF10-' . strtoupper(substr(uniqid(), -6)) . rand(10, 99);
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

// Create or find user by email
function findOrCreateUser($email, $name, $phone) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        return $user['id'];
    }

    // Create new user with random password
    $password = bin2hex(random_bytes(4));
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, phone, role) VALUES (?, ?, ?, ?, 'user')");
    $stmt->execute([$email, $hash, $name, $phone]);

    $userId = $pdo->lastInsertId();

    // Store password temporarily to potentially send by email later
    $_SESSION['new_user_password'] = $password;
    $_SESSION['new_user_id'] = $userId;

    return $userId;
}

// POST: Create new order
if ($method === 'POST' && $action === 'create') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['package_qty', 'name', 'email', 'phone', 'address', 'city', 'postal_code', 'payment_method'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse(['error' => "El campo '$field' es obligatorio"], 400);
        }
    }

    // Validate package
    $qty = (int)$data['package_qty'];
    if (!isset(PACKAGES[$qty])) {
        jsonResponse(['error' => 'Paquete no válido'], 400);
    }
    $package = PACKAGES[$qty];

    // Validate email
    $email = sanitize($data['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Email no válido'], 400);
    }

    // Validate phone
    $phone = sanitize($data['phone']);
    if (!preg_match('/^[0-9\s\+]{9,15}$/', preg_replace('/\s/', '', $phone))) {
        jsonResponse(['error' => 'Teléfono no válido'], 400);
    }

    // Validate postal code (Madrid region: 28xxx, 45xxx nearby)
    $postalCode = sanitize($data['postal_code']);
    if (!preg_match('/^(28|45)\d{3}$/', $postalCode)) {
        jsonResponse(['error' => 'Código postal no válido para la zona de servicio'], 400);
    }

    // Validate payment method
    $paymentMethod = sanitize($data['payment_method']);
    if (!in_array($paymentMethod, ['card', 'transfer'])) {
        jsonResponse(['error' => 'Método de pago no válido'], 400);
    }

    // Sanitize inputs
    $name = sanitize($data['name']);
    $address = sanitize($data['address']);
    $city = sanitize($data['city']);
    $observations = sanitize($data['observations'] ?? '');

    // NIF/CIF and invoice request
    $nif = sanitize($data['nif'] ?? '');
    $requestInvoice = !empty($data['request_invoice']) ? 1 : 0;

    // Validate NIF/CIF if provided
    if ($nif && !preg_match('/^[A-Za-z]\d{7}[A-Za-z0-9]$|^\d{8}[A-Za-z]$/', $nif)) {
        jsonResponse(['error' => 'NIF/CIF no válido'], 400);
    }

    // If invoice requested, NIF is mandatory
    if ($requestInvoice && !$nif) {
        jsonResponse(['error' => 'Para solicitar factura debes indicar un NIF/CIF válido'], 400);
    }

    // Find or create user
    $userId = findOrCreateUser($email, $name, $phone);

    // All orders start as pendiente_pago. Stripe webhook will mark card orders as confirmado.
    $status = 'pendiente_pago';

    // Generate order code
    $orderCode = generateOrderCode();

    // Insert order
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_code, user_id, package_qty, package_name, package_price,
            name, nif, email, phone, address, city, postal_code, observations,
            request_invoice, payment_method, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    try {
        $stmt->execute([
            $orderCode, $userId, $qty, $package['name'], $package['price'],
            $name, $nif, $email, $phone, $address, $city, $postalCode, $observations,
            $requestInvoice, $paymentMethod, $status
        ]);
        $orderId = $pdo->lastInsertId();

        $response = [
            'success' => true,
            'order' => [
                'code' => $orderCode,
                'package' => $package['name'],
                'price' => $package['price'],
                'status' => $status,
                'payment_method' => $paymentMethod,
            ]
        ];

        // If card payment: create Stripe Checkout Session
        if ($paymentMethod === 'card') {
            $session = stripeCreateCheckoutSession([
                'id' => $orderId,
                'order_code' => $orderCode,
                'package_qty' => $qty,
                'email' => $email,
            ]);

            if (!empty($session['__error'])) {
                // Mark order as failed-to-create-checkout but keep it for manual follow-up
                jsonResponse(['error' => 'Error al iniciar el pago: ' . $session['__error']], 500);
            }

            // Save Stripe session id
            $upd = $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?");
            $upd->execute([$session['id'], $orderId]);

            $response['checkout_url'] = $session['url'];
        }

        // Add bank details if transfer
        // NOTE: 'beneficiary' (titular de la cuenta) is intentionally NOT exposed
        // in the public API response. It only appears inside the confirmation email
        // (mail_helper.php) where the customer needs it to make the transfer.
        if ($paymentMethod === 'transfer') {
            $response['bank'] = [
                'iban' => BANK_IBAN,
                'concept' => $orderCode,
                'amount' => number_format($package['price'], 2, ',', '.') . ' €',
            ];
        }

        // Send confirmation email for non-card orders immediately.
        // Card orders send email from stripe_webhook.php on checkout.session.completed.
        if ($paymentMethod !== 'card') {
            // Reload the inserted row so we pass a full array
            $sel = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $sel->execute([$orderId]);
            $fullOrder = $sel->fetch();
            if ($fullOrder) {
                @sendOrderConfirmationEmail($fullOrder);
            }
        }

        jsonResponse($response, 201);

    } catch (PDOException $e) {
        jsonResponse(['error' => 'Error al crear el pedido. Inténtalo de nuevo.'], 500);
    }
}

// GET: Get user orders (authenticated)
if ($method === 'GET' && $action === 'my-orders') {
    if (!isLoggedIn()) {
        jsonResponse(['error' => 'Debes iniciar sesión'], 401);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_code, o.package_name, o.package_qty, o.package_price,
               o.status, o.payment_method, o.address, o.city, o.postal_code,
               o.nif, o.request_invoice, o.observations, o.created_at, o.paid_at,
               o.picked_up_at,
               o.certificate_requested, o.certificate_requested_at,
               o.certificate_issued_at, o.certificate_number,
               i.id AS invoice_id, i.invoice_number, i.issued_at AS invoice_issued_at
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);

    jsonResponse(['orders' => $stmt->fetchAll()]);
}

// GET: Stats for current logged-in user (mi-cuenta dashboard cards)
if ($method === 'GET' && $action === 'my-stats') {
    if (!isLoggedIn()) {
        jsonResponse(['error' => 'Debes iniciar sesión'], 401);
    }

    $pdo = getDB();
    $uid = (int)$_SESSION['user_id'];

    $stats = [
        'total'      => 0,
        'pagados'    => 0,
        'pendientes' => 0,
        'enviados'   => 0,
        'gasto_total'  => 0.0,
        'gasto_pagado' => 0.0,
    ];

    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) c, COALESCE(SUM(package_price),0) total
        FROM orders WHERE user_id = ? GROUP BY status
    ");
    $stmt->execute([$uid]);
    while ($r = $stmt->fetch()) {
        $stats['total']       += (int)$r['c'];
        $stats['gasto_total'] += (float)$r['total'];
        if ($r['status'] === 'pendiente_pago') {
            $stats['pendientes'] += (int)$r['c'];
        } elseif ($r['status'] === 'confirmado') {
            $stats['pagados']      += (int)$r['c'];
            $stats['gasto_pagado'] += (float)$r['total'];
        } elseif ($r['status'] === 'enviado') {
            $stats['enviados']     += (int)$r['c'];
            $stats['pagados']      += (int)$r['c'];
            $stats['gasto_pagado'] += (float)$r['total'];
        } elseif ($r['status'] === 'recogida') {
            $stats['enviados']     += (int)$r['c'];
            $stats['pagados']      += (int)$r['c'];
            $stats['gasto_pagado'] += (float)$r['total'];
        }
    }

    jsonResponse(['stats' => $stats]);
}

// GET: Get single order by code
if ($method === 'GET' && $action === 'detail') {
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) {
        jsonResponse(['error' => 'Código de pedido requerido'], 400);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, user_id, order_code, package_name, package_qty, package_price,
               name, nif, email, phone, address, city, postal_code, observations,
               internal_notes, request_invoice, payment_method, status, brand,
               paid_at, picked_up_at, created_at,
               certificate_requested, certificate_requested_at,
               certificate_issued_at, certificate_number
        FROM orders
        WHERE order_code = ?
    ");
    $stmt->execute([$code]);
    $order = $stmt->fetch();

    if (!$order) {
        jsonResponse(['error' => 'Pedido no encontrado'], 404);
    }

    // Access control: manager OR owner of the order
    $isOwner = isLoggedIn() && (int)$order['user_id'] === (int)$_SESSION['user_id'];
    if (!isManager() && !$isOwner) {
        jsonResponse(['error' => 'Acceso denegado'], 403);
    }

    jsonResponse(['order' => $order]);
}

// GET: Packages list
if ($method === 'GET' && $action === 'packages') {
    $packages = [];
    foreach (PACKAGES as $qty => $pkg) {
        $packages[] = [
            'qty' => $qty,
            'name' => $pkg['name'],
            'price' => $pkg['price'],
            'description' => $pkg['description'],
        ];
    }
    jsonResponse(['packages' => $packages]);
}

// ====================================================================
// POST /api/orders.php?action=request-certificate
// Body: { code }
// Owner of the order requests the waste-management certificate.
// If the order is already 'recogida', the certificate is issued
// immediately and the email is sent. Otherwise it is just flagged
// for auto-issue when the admin marks the order as 'recogida'.
// ====================================================================
if ($method === 'POST' && $action === 'request-certificate') {
    if (!isLoggedIn()) jsonResponse(['error' => 'Debes iniciar sesión'], 401);

    $data = json_decode(file_get_contents('php://input'), true);
    $code = sanitize($data['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código de pedido requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    // Access control: owner or manager
    $isOwner = (int)$order['user_id'] === (int)$_SESSION['user_id'];
    if (!$isOwner && !isManager()) {
        jsonResponse(['error' => 'Acceso denegado'], 403);
    }

    // Mark request (idempotent)
    if (empty($order['certificate_requested'])) {
        $upd = $pdo->prepare("
            UPDATE orders
            SET certificate_requested = 1,
                certificate_requested_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$order['id']]);
        logEvent($order['id'], 'certificate_requested',
            'Cliente ha solicitado el certificado de gestión de residuos',
            'user');
    }

    // If order already picked up, issue certificate immediately
    $issuedNow = false;
    $certNumber = $order['certificate_number'] ?? null;
    if ($order['status'] === 'recogida') {
        $result = issueCertificate($code);
        if ($result && empty($result['error'])) {
            $issuedNow = !empty($result['newly_issued']);
            $certNumber = $result['order']['certificate_number'] ?? $certNumber;
            if ($issuedNow) {
                logEvent($order['id'], 'certificate_issued',
                    'Certificado RCD emitido automáticamente al solicitarlo (saca ya recogida)',
                    'system',
                    ['number' => $certNumber]);
                if (function_exists('sendCertificateEmail')) {
                    @sendCertificateEmail($result['order'], $result['path']);
                }
            }
        }
    }

    jsonResponse([
        'success' => true,
        'message' => $order['status'] === 'recogida'
            ? '✓ Certificado emitido. Lo recibirás por email en breve.'
            : '✓ Solicitud registrada. Emitiremos el certificado cuando recojamos las sacas.',
        'issued_now' => $issuedNow,
        'certificate_number' => $certNumber,
        'status' => $order['status'],
    ]);
}

// ====================================================================
// GET /api/orders.php?action=download-certificate&code=...
// Customer-facing download of their own certificate.
// ====================================================================
if ($method === 'GET' && $action === 'download-certificate') {
    if (!isLoggedIn()) jsonResponse(['error' => 'Debes iniciar sesión'], 401);
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    $isOwner = (int)$order['user_id'] === (int)$_SESSION['user_id'];
    if (!$isOwner && !isManager()) jsonResponse(['error' => 'Acceso denegado'], 403);

    if ($order['status'] !== 'recogida') {
        jsonResponse(['error' => 'El certificado no está disponible aún. Te avisaremos cuando recojamos las sacas.'], 400);
    }

    if (empty($order['certificate_issued_at'])) {
        $issued = issueCertificate($code);
        if (!$issued || isset($issued['error'])) {
            jsonResponse(['error' => $issued['error'] ?? 'Certificado no disponible'], 400);
        }
    }

    renderCertificatePdf($code, 'I');
    exit;
}

jsonResponse(['error' => 'Acción no válida'], 400);
