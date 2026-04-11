<?php
/**
 * RCD Certificate Request endpoint
 * POST: receives form data from /formulario-rcd.html
 * Stores in rcd_requests table and notifies admin.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/events_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    jsonResponse(['error' => 'JSON no válido'], 400);
}

// Validate required fields
$required = ['fecha_desde', 'fecha_hasta', 'obra_denominacion', 'obra_calle',
    'obra_cp_ciudad', 'productor_nombre', 'productor_cif', 'constructor_nombre',
    'constructor_cif', 'firmante_nombre', 'firmante_fecha', 'firmante_email'];
foreach ($required as $f) {
    if (empty($data[$f])) {
        jsonResponse(['error' => "Campo obligatorio: $f"], 400);
    }
}

// Validate email
$email = sanitize($data['firmante_email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Email no válido'], 400);
}

$pdo = getDB();

// Create rcd_requests table if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS rcd_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(20) DEFAULT NULL,
        fecha_desde DATE NOT NULL,
        fecha_hasta DATE NOT NULL,
        obra_denominacion VARCHAR(255) NOT NULL,
        obra_calle VARCHAR(255) NOT NULL,
        obra_cp_ciudad VARCHAR(100) NOT NULL,
        obra_licencia VARCHAR(100) DEFAULT NULL,
        productor_nombre VARCHAR(255) NOT NULL,
        productor_cif VARCHAR(20) NOT NULL,
        productor_calle VARCHAR(255) DEFAULT NULL,
        productor_cp VARCHAR(10) DEFAULT NULL,
        productor_ciudad VARCHAR(100) DEFAULT NULL,
        constructor_nombre VARCHAR(255) NOT NULL,
        constructor_cif VARCHAR(20) NOT NULL,
        constructor_calle VARCHAR(255) DEFAULT NULL,
        constructor_cp VARCHAR(10) DEFAULT NULL,
        constructor_ciudad VARCHAR(100) DEFAULT NULL,
        materiales JSON DEFAULT NULL,
        firmante_nombre VARCHAR(255) NOT NULL,
        firmante_calidad VARCHAR(100) DEFAULT NULL,
        firmante_fecha DATE NOT NULL,
        firmante_email VARCHAR(255) NOT NULL,
        firmante_telefono VARCHAR(20) DEFAULT NULL,
        status ENUM('pendiente','procesado','emitido') DEFAULT 'pendiente',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Insert
$stmt = $pdo->prepare("
    INSERT INTO rcd_requests (
        order_code, fecha_desde, fecha_hasta,
        obra_denominacion, obra_calle, obra_cp_ciudad, obra_licencia,
        productor_nombre, productor_cif, productor_calle, productor_cp, productor_ciudad,
        constructor_nombre, constructor_cif, constructor_calle, constructor_cp, constructor_ciudad,
        materiales,
        firmante_nombre, firmante_calidad, firmante_fecha, firmante_email, firmante_telefono
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$materiales = isset($data['materiales']) && is_array($data['materiales']) ? json_encode($data['materiales']) : null;

try {
    $stmt->execute([
        sanitize($data['order_code'] ?? ''),
        sanitize($data['fecha_desde']),
        sanitize($data['fecha_hasta']),
        sanitize($data['obra_denominacion']),
        sanitize($data['obra_calle']),
        sanitize($data['obra_cp_ciudad']),
        sanitize($data['obra_licencia'] ?? ''),
        sanitize($data['productor_nombre']),
        sanitize($data['productor_cif']),
        sanitize($data['productor_calle'] ?? ''),
        sanitize($data['productor_cp'] ?? ''),
        sanitize($data['productor_ciudad'] ?? ''),
        sanitize($data['constructor_nombre']),
        sanitize($data['constructor_cif']),
        sanitize($data['constructor_calle'] ?? ''),
        sanitize($data['constructor_cp'] ?? ''),
        sanitize($data['constructor_ciudad'] ?? ''),
        $materiales,
        sanitize($data['firmante_nombre']),
        sanitize($data['firmante_calidad'] ?? ''),
        sanitize($data['firmante_fecha']),
        $email,
        sanitize($data['firmante_telefono'] ?? ''),
    ]);
    $requestId = $pdo->lastInsertId();
} catch (PDOException $e) {
    jsonResponse(['error' => 'Error al guardar la solicitud'], 500);
}

// If order_code provided, log event on that order
$orderCode = sanitize($data['order_code'] ?? '');
if ($orderCode) {
    $sel = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
    $sel->execute([$orderCode]);
    $order = $sel->fetch();
    if ($order) {
        logEvent($order['id'], 'rcd_form_submitted',
            'Formulario RCD recibido (solicitud #' . $requestId . ')', 'user');
    }
}

// Notify admin by email
$adminHtml = '<h3>Nueva solicitud de certificado RCD (#' . $requestId . ')</h3>'
    . ($orderCode ? '<p><strong>Pedido:</strong> ' . htmlspecialchars($orderCode) . '</p>' : '')
    . '<p><strong>Obra:</strong> ' . htmlspecialchars($data['obra_denominacion']) . ' — ' . htmlspecialchars($data['obra_calle']) . ', ' . htmlspecialchars($data['obra_cp_ciudad']) . '</p>'
    . '<p><strong>Productor:</strong> ' . htmlspecialchars($data['productor_nombre']) . ' (' . htmlspecialchars($data['productor_cif']) . ')</p>'
    . '<p><strong>Constructor:</strong> ' . htmlspecialchars($data['constructor_nombre']) . ' (' . htmlspecialchars($data['constructor_cif']) . ')</p>'
    . '<p><strong>Firmante:</strong> ' . htmlspecialchars($data['firmante_nombre']) . '</p>'
    . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>'
    . '<p><strong>Tel:</strong> ' . htmlspecialchars($data['firmante_telefono'] ?? '-') . '</p>'
    . '<p><strong>Periodo:</strong> ' . htmlspecialchars($data['fecha_desde']) . ' a ' . htmlspecialchars($data['fecha_hasta']) . '</p>';

@sendMail(CONTACT_EMAIL, 'Nueva solicitud RCD #' . $requestId . ($orderCode ? ' — ' . $orderCode : ''), $adminHtml);

jsonResponse(['success' => true, 'request_id' => $requestId]);
