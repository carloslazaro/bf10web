<?php
require_once __DIR__ . '/config.php';

$pdo = getDB();

$email = 'cliente@bf10.es';
$password = 'Cliente2026!';
$hash = password_hash($password, PASSWORD_BCRYPT);
$name = 'Cliente Prueba';
$phone = '600000000';

// Check if exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo "User already exists. Updating password...\n";
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
    $stmt->execute([$hash, $email]);
    echo "Password updated.\n";
} else {
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, phone, role) VALUES (?, ?, ?, ?, 'user')");
    $stmt->execute([$email, $hash, $name, $phone]);
    echo "User created.\n";
}

// Create a test order for this user
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

$stmt = $pdo->prepare("SELECT id FROM orders WHERE user_id = ?");
$stmt->execute([$user['id']]);
if (!$stmt->fetch()) {
    $code = 'BF10-TEST01';
    $stmt = $pdo->prepare("
        INSERT INTO orders (order_code, user_id, package_qty, package_name, package_price,
            name, email, phone, address, city, postal_code, observations,
            billing_same, payment_method, status)
        VALUES (?, ?, 25, '25 sacos', 25.00, ?, ?, ?, 'Calle de Prueba 1, 2ºB', 'Madrid', '28001', 'Pedido de prueba',
            1, 'transfer', 'confirmado')
    ");
    $stmt->execute([$code, $user['id'], $name, $email, $phone]);
    echo "Test order created: $code\n";
}

echo "\nLogin: $email / $password\n";
echo "\n⚠️ DELETE THIS FILE!\n";
