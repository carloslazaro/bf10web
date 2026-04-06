<?php
/**
 * BF10 - Database installer
 * Run once to create tables and default manager account.
 * DELETE THIS FILE after installation for security.
 */
require_once __DIR__ . '/config.php';

$pdo = getDB();

$sql = "
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    role ENUM('user', 'manager') DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(20) NOT NULL UNIQUE,
    user_id INT NOT NULL,

    -- Package
    package_qty INT NOT NULL,
    package_name VARCHAR(100) NOT NULL,
    package_price DECIMAL(10,2) NOT NULL,

    -- Delivery info
    name VARCHAR(255) NOT NULL,
    nif VARCHAR(20),
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address VARCHAR(500) NOT NULL,
    city VARCHAR(100) NOT NULL,
    postal_code VARCHAR(10) NOT NULL,
    observations TEXT,

    -- Invoice
    request_invoice TINYINT(1) DEFAULT 0,

    -- Payment
    payment_method ENUM('card', 'transfer') NOT NULL,
    stripe_payment_id VARCHAR(255),

    -- Status
    status ENUM('confirmado', 'pendiente_pago', 'enviado') DEFAULT 'pendiente_pago',

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo->exec($sql);
    echo "<h2>✅ Tablas creadas correctamente</h2>";

    // Create default manager account
    $managerEmail = 'admin@bf10.es';
    $managerPass = password_hash('Bf10Admin2026!', PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$managerEmail]);

    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, phone, role) VALUES (?, ?, ?, ?, 'manager')");
        $stmt->execute([$managerEmail, $managerPass, 'Administrador BF10', '674783479']);
        echo "<h3>✅ Cuenta de manager creada</h3>";
        echo "<p><strong>Email:</strong> admin@bf10.es</p>";
        echo "<p><strong>Contraseña:</strong> Bf10Admin2026!</p>";
    } else {
        echo "<h3>ℹ️ La cuenta de manager ya existe</h3>";
    }

    echo "<hr>";
    echo "<p style='color:red;font-weight:bold;'>⚠️ IMPORTANTE: Elimina este archivo (install.php) del servidor por seguridad.</p>";

} catch (PDOException $e) {
    echo "<h2>❌ Error</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
