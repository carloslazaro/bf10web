<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'sacosbf10_pedidos');
define('DB_USER', 'sacosbf10_admin');
define('DB_PASS', 'Bf10_2026!');

// Site config
define('SITE_URL', 'https://sacosescombromadridbf10.es');
define('SITE_NAME', 'BF10 - Sacos de Escombro Madrid');
define('CONTACT_EMAIL', 'servisaco2026@gmail.com');
define('CONTACT_PHONE', '674 78 34 79');

// Bank transfer details
define('BANK_IBAN', 'ES00 0000 0000 0000 0000 0000'); // TODO: poner IBAN real
define('BANK_BENEFICIARY', 'SERVISACO Recuperación y Logística SL');
define('BANK_CONCEPT_PREFIX', 'BF10-');

// Packages
define('PACKAGES', [
    5  => ['name' => '5 sacos',  'price' => 5.00,  'description' => 'Pack básico de 5 sacos de 1m³'],
    25 => ['name' => '25 sacos', 'price' => 25.00, 'description' => 'Pack estándar de 25 sacos de 1m³'],
    50 => ['name' => '50 sacos', 'price' => 50.00, 'description' => 'Pack profesional de 50 sacos de 1m³'],
]);

// Stripe (placeholder - user will provide keys later)
define('STRIPE_PUBLIC_KEY', '');
define('STRIPE_SECRET_KEY', '');

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
