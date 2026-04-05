<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET: List all orders (manager only)
if ($method === 'GET' && $action === 'orders') {
    requireManager();

    $pdo = getDB();
    $status = sanitize($_GET['status'] ?? '');

    $sql = "
        SELECT o.id, o.order_code, o.package_name, o.package_qty, o.package_price,
               o.name, o.email, o.phone, o.address, o.city, o.postal_code, o.observations,
               o.billing_same, o.billing_name, o.billing_company, o.billing_cif, o.billing_address,
               o.payment_method, o.status, o.created_at, o.updated_at
        FROM orders o
    ";

    $params = [];
    if ($status && in_array($status, ['confirmado', 'pendiente_pago', 'enviado'])) {
        $sql .= " WHERE o.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY o.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['orders' => $stmt->fetchAll()]);
}

// GET: Order stats (manager only)
if ($method === 'GET' && $action === 'stats') {
    requireManager();

    $pdo = getDB();

    $stats = [];

    // Total orders
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
    $stats['total'] = $stmt->fetch()['total'];

    // By status
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
    $stats['by_status'] = [];
    while ($row = $stmt->fetch()) {
        $stats['by_status'][$row['status']] = (int)$row['count'];
    }

    // Revenue
    $stmt = $pdo->query("SELECT COALESCE(SUM(package_price), 0) as total FROM orders WHERE status IN ('confirmado', 'enviado')");
    $stats['revenue'] = (float)$stmt->fetch()['total'];

    // Today
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()");
    $stats['today'] = (int)$stmt->fetch()['count'];

    jsonResponse(['stats' => $stats]);
}

// PUT: Update order status (manager only)
if ($method === 'PUT' && $action === 'update-status') {
    requireManager();

    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($data['order_id'] ?? 0);
    $newStatus = sanitize($data['status'] ?? '');

    if (!$orderId || !in_array($newStatus, ['confirmado', 'pendiente_pago', 'enviado'])) {
        jsonResponse(['error' => 'Datos no válidos'], 400);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $orderId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => 'Pedido no encontrado'], 404);
    }

    jsonResponse(['success' => true, 'message' => "Estado actualizado a '$newStatus'"]);
}

// DELETE: Delete order (manager only)
if ($method === 'DELETE' && $action === 'delete') {
    requireManager();

    $orderId = (int)($_GET['id'] ?? 0);
    if (!$orderId) {
        jsonResponse(['error' => 'ID de pedido requerido'], 400);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => 'Pedido no encontrado'], 404);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
