<?php
require_once __DIR__ . '/config.php';

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

    // Billing data
    $billingSame = !empty($data['billing_same']) ? 1 : 0;
    $billingName = sanitize($data['billing_name'] ?? '');
    $billingCompany = sanitize($data['billing_company'] ?? '');
    $billingCif = sanitize($data['billing_cif'] ?? '');
    $billingAddress = sanitize($data['billing_address'] ?? '');

    // If billing different, validate CIF
    if (!$billingSame && $billingCif) {
        // Basic Spanish CIF/NIF validation (letter + 8 digits or 8 digits + letter)
        if (!preg_match('/^[A-Za-z]\d{7}[A-Za-z0-9]$|^\d{8}[A-Za-z]$/', $billingCif)) {
            jsonResponse(['error' => 'CIF/NIF no válido'], 400);
        }
    }

    // Find or create user
    $userId = findOrCreateUser($email, $name, $phone);

    // Determine status
    $status = ($paymentMethod === 'card') ? 'confirmado' : 'pendiente_pago';

    // Generate order code
    $orderCode = generateOrderCode();

    // Insert order
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_code, user_id, package_qty, package_name, package_price,
            name, email, phone, address, city, postal_code, observations,
            billing_same, billing_name, billing_company, billing_cif, billing_address,
            payment_method, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    try {
        $stmt->execute([
            $orderCode, $userId, $qty, $package['name'], $package['price'],
            $name, $email, $phone, $address, $city, $postalCode, $observations,
            $billingSame, $billingName, $billingCompany, $billingCif, $billingAddress,
            $paymentMethod, $status
        ]);

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

        // Add bank details if transfer
        if ($paymentMethod === 'transfer') {
            $response['bank'] = [
                'iban' => BANK_IBAN,
                'beneficiary' => BANK_BENEFICIARY,
                'concept' => $orderCode,
                'amount' => $package['price'] . ' €',
            ];
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
        SELECT order_code, package_name, package_price, status, payment_method,
               address, city, created_at
        FROM orders
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);

    jsonResponse(['orders' => $stmt->fetchAll()]);
}

// GET: Get single order by code
if ($method === 'GET' && $action === 'detail') {
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) {
        jsonResponse(['error' => 'Código de pedido requerido'], 400);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT order_code, package_name, package_qty, package_price,
               name, email, phone, address, city, postal_code, observations,
               billing_same, billing_name, billing_company, billing_cif, billing_address,
               payment_method, status, created_at
        FROM orders
        WHERE order_code = ?
    ");
    $stmt->execute([$code]);
    $order = $stmt->fetch();

    if (!$order) {
        jsonResponse(['error' => 'Pedido no encontrado'], 404);
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

jsonResponse(['error' => 'Acción no válida'], 400);
