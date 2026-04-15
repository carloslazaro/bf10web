<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'sacosbf10_bbdd_bf10');
define('DB_USER', 'sacosbf10_bbdd3');
define('DB_PASS', 'N@tur2026');

// Site config
define('SITE_URL', 'https://sacosescombromadridbf10.es');
define('SITE_NAME', 'BF10 - Sacos de Escombro Madrid');
define('CONTACT_EMAIL', 'pedidos@sacosescombromadridbf10.es');
define('CONTACT_PHONE', '685 20 82 52');

// Bank transfer details
define('BANK_IBAN', 'ES00 0000 0000 0000 0000 0000'); // TODO: poner IBAN real
define('BANK_BENEFICIARY', 'SERVISACO Recuperación y Logística SL');
define('BANK_CONCEPT_PREFIX', 'BF10-');

// ========================================
// Brands (multi-marca CRM)
// All four brands invoice under the same legal entity (SERVISACO SL, B26764688)
// — only the trade name / colour / domain shown to the customer changes.
// To change the fiscal entity per brand: edit `legal_name` / `cif` / `address` keys.
// ========================================
define('BRANDS', [
    'BF10' => [
        'code'         => 'BF10',
        'trade_name'   => 'BF10 Sacos de Escombro',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B26764688',
        'address'      => 'Calle Totana, 8 - Puerta Dcha',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => 'sacosescombromadridbf10.es',
        'invoice_prefix' => 'BF10',
        'order_prefix'   => 'BF10',
        'cert_prefix'    => 'CERT',
        'color'        => '#DA291C',
    ],
    'SERVISACO' => [
        'code'         => 'SERVISACO',
        'trade_name'   => 'Servisaco',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B26764688',
        'address'      => 'Calle Totana, 8 - Puerta Dcha',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => 'servisaco.es',
        'invoice_prefix' => 'SVC',
        'order_prefix'   => 'SVC',
        'cert_prefix'    => 'CERT',
        'color'        => '#1B5E20',
    ],
    'ATUSACO' => [
        'code'         => 'ATUSACO',
        'trade_name'   => 'Atusaco',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B26764688',
        'address'      => 'Calle Totana, 8 - Puerta Dcha',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => 'atusaco.es',
        'invoice_prefix' => 'ATU',
        'order_prefix'   => 'ATU',
        'cert_prefix'    => 'CERT',
        'color'        => '#1565C0',
    ],
    'ATUSACO_LUISFER' => [
        'code'         => 'ATUSACO_LUISFER',
        'trade_name'   => 'Atusaco Luisfer',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B26764688',
        'address'      => 'Calle Totana, 8 - Puerta Dcha',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => 'atusaco.es',
        'invoice_prefix' => 'ATU',
        'order_prefix'   => 'ATU',
        'cert_prefix'    => 'CERT',
        'color'        => '#0D47A1',
    ],
    'ATUSACO_HERREROCON' => [
        'code'         => 'ATUSACO_HERREROCON',
        'trade_name'   => 'Atusaco Herrerocon',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B26764688',
        'address'      => 'Calle Totana, 8 - Puerta Dcha',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => 'atusaco.es',
        'invoice_prefix' => 'ATU',
        'order_prefix'   => 'ATU',
        'cert_prefix'    => 'CERT',
        'color'        => '#283593',
    ],
    'ATUSACO_COSASCASA' => [
        'code'         => 'ATUSACO_COSASCASA',
        'trade_name'   => 'Atusaco Cosas Casa',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B26764688',
        'address'      => 'Calle Totana, 8 - Puerta Dcha',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => 'atusaco.es',
        'invoice_prefix' => 'ATU',
        'order_prefix'   => 'ATU',
        'cert_prefix'    => 'CERT',
        'color'        => '#4527A0',
    ],
    'ECOSACO' => [
        'code'         => 'ECOSACO',
        'trade_name'   => 'Eco Saco',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B26764688',
        'address'      => 'Calle Totana, 8 - Puerta Dcha',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => 'ecosaco.es',
        'invoice_prefix' => 'ECO',
        'order_prefix'   => 'ECO',
        'cert_prefix'    => 'CERT',
        'color'        => '#2E7D32',
    ],
    'SACAS_BLANCAS' => [
        'code'         => 'SACAS_BLANCAS',
        'trade_name'   => 'Sacas Blancas',
        'legal_name'   => 'SERVISACO Recuperación y Logística SL',
        'cif'          => 'B56789012',
        'address'      => 'Por definir',
        'city'         => '28033 Madrid',
        'country'      => 'España',
        'phone'        => '685 20 82 52',
        'email'        => 'pedidos@sacosescombromadridbf10.es',
        'web'          => '',
        'invoice_prefix' => 'SB',
        'order_prefix'   => 'SB',
        'cert_prefix'    => 'CERT',
        'color'        => '#795548',
    ],
]);

function getBrand($code) {
    $code = strtoupper($code ?: 'BF10');
    $brands = BRANDS;
    return $brands[$code] ?? $brands['BF10'];
}

// Packages
// Base price: 47€/saca. Volume discounts: 10% off (25), 15% off (50).
// 25: 25 * 47 * 0.90 = 1057.50  (42,30€/saca)
// 50: 50 * 47 * 0.85 = 1997.50  (39,95€/saca)
define('PACKAGES', [
    1  => ['name' => '1 saca (TEST)', 'price' => 1.00, 'unit' => 1.00, 'discount' => 0, 'description' => 'Test — 1 saca a 1€'],
    10 => ['name' => '10 sacas', 'price' => 470.00,  'unit' => 47.00,  'discount' => 0,  'description' => 'Pedido mínimo — 10 sacas a 47€/saca'],
    25 => ['name' => '25 sacas', 'price' => 1057.50, 'unit' => 42.30,  'discount' => 10, 'description' => 'Pack estándar — 25 sacas con 10% de descuento (42,30€/saca)'],
    50 => ['name' => '50 sacas', 'price' => 1997.50, 'unit' => 39.95,  'discount' => 15, 'description' => 'Pack profesional — 50 sacas con 15% de descuento (39,95€/saca)'],
]);

// Load secrets (API keys). File is gitignored.
if (file_exists(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}
// Fallbacks so code never breaks if secrets.php missing
if (!defined('STRIPE_PUBLIC_KEY'))    define('STRIPE_PUBLIC_KEY', '');
if (!defined('STRIPE_SECRET_KEY'))    define('STRIPE_SECRET_KEY', '');
if (!defined('STRIPE_WEBHOOK_SECRET')) define('STRIPE_WEBHOOK_SECRET', '');

// Database connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error de conexión a base de datos']);
            exit;
        }
    }
    return $pdo;
}

// JSON response helper
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF token
function generateCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Input sanitization
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user is manager
function isManager() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['manager', 'ceo']);
}

// Check if user is facturacion
function isFacturacion() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'facturacion';
}

function requireManager() {
    if (!isLoggedIn() || !isManager()) {
        jsonResponse(['error' => 'Acceso denegado'], 403);
    }
}

function requireManagerOrFacturacion() {
    if (!isLoggedIn() || (!isManager() && !isFacturacion())) {
        jsonResponse(['error' => 'Acceso denegado'], 403);
    }
}

function isComercial() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'comercial';
}

function requireComercial() {
    if (!isLoggedIn() || !isComercial()) {
        jsonResponse(['error' => 'Acceso denegado'], 403);
    }
}

function isRutas() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'rutas';
}

function requireRutas() {
    if (!isLoggedIn() || (!isRutas() && !isManager())) {
        jsonResponse(['error' => 'Acceso denegado'], 403);
    }
}
