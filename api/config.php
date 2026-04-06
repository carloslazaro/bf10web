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
define('CONTACT_PHONE', '674 78 34 79');

// Bank transfer details
define('BANK_IBAN', 'ES00 0000 0000 0000 0000 0000'); // TODO: poner IBAN real
define('BANK_BENEFICIARY', 'SERVISACO Recuperación y Logística SL');
define('BANK_CONCEPT_PREFIX', 'BF10-');

// Packages
define('PACKAGES', [
    10 => ['name' => '10 sacos', 'price' => 450.00,  'description' => 'Pedido mínimo — 10 sacos de 1m³ a 45€/saco'],
    25 => ['name' => '25 sacos', 'price' => 1050.00, 'description' => 'Pack estándar — 25 sacos de 1m³ a 42€/saco'],
    50 => ['name' => '50 sacos', 'price' => 2000.00, 'description' => 'Pack profesional — 50 sacos de 1m³ a 40€/saco'],
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
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'manager';
}

function requireManager() {
    if (!isLoggedIn() || !isManager()) {
        jsonResponse(['error' => 'Acceso denegado'], 403);
    }
}
