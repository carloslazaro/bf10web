<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_notify.php';
require_once __DIR__ . '/events_helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// CSV export sets its own header below; everything else is JSON.
if (!($method === 'GET' && $action === 'export-csv')) {
    header('Content-Type: application/json; charset=utf-8');
}

// ====================================================================
// GET /admin.php?action=orders
// Optional filters: status, source, q (search), from, to
// Returns: orders[] with invoice info joined
// ====================================================================
if ($method === 'GET' && $action === 'orders') {
    requireManager();
    $pdo = getDB();

    $status = sanitize($_GET['status'] ?? '');
    $source = sanitize($_GET['source'] ?? '');
    $q      = sanitize($_GET['q'] ?? '');
    $from   = sanitize($_GET['from'] ?? '');
    $to     = sanitize($_GET['to'] ?? '');

    $where = [];
    $params = [];

    if ($status && in_array($status, ['confirmado', 'pendiente_pago', 'enviado'])) {
        $where[] = "o.status = ?";
        $params[] = $status;
    }
    if ($source && in_array($source, ['web', 'whatsapp', 'admin'])) {
        $where[] = "o.source = ?";
        $params[] = $source;
    }
    if ($q !== '') {
        $where[] = "(o.order_code LIKE ? OR o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($from !== '') { $where[] = "DATE(o.created_at) >= ?"; $params[] = $from; }
    if ($to   !== '') { $where[] = "DATE(o.created_at) <= ?"; $params[] = $to; }

    $sql = "
        SELECT o.id, o.order_code, o.package_name, o.package_qty, o.package_price,
               o.name, o.nif, o.email, o.phone, o.address, o.city, o.postal_code,
               o.observations, o.internal_notes, o.request_invoice, o.payment_method,
               o.status, o.source, o.stripe_session_id, o.stripe_payment_intent,
               o.paid_at, o.created_at, o.updated_at,
               i.id AS invoice_id, i.invoice_number, i.issued_at AS invoice_issued_at,
               i.sent_at AS invoice_sent_at, i.total_amount AS invoice_total
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
    ";
    if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY o.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['orders' => $stmt->fetchAll()]);
}

// ====================================================================
// GET /admin.php?action=stats
// ====================================================================
if ($method === 'GET' && $action === 'stats') {
    requireManager();
    $pdo = getDB();

    $stats = [];

    $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

    $stats['by_status'] = [];
    $rs = $pdo->query("SELECT status, COUNT(*) c FROM orders GROUP BY status");
    while ($row = $rs->fetch()) $stats['by_status'][$row['status']] = (int)$row['c'];

    $stats['by_source'] = [];
    $rs = $pdo->query("SELECT COALESCE(source,'web') src, COUNT(*) c FROM orders GROUP BY src");
    while ($row = $rs->fetch()) $stats['by_source'][$row['src']] = (int)$row['c'];

    $stats['revenue'] = (float)$pdo->query("
        SELECT COALESCE(SUM(package_price),0)
        FROM orders WHERE status IN ('confirmado','enviado')
    ")->fetchColumn();

    $stats['today'] = (int)$pdo->query("
        SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()
    ")->fetchColumn();

    $stats['month'] = (int)$pdo->query("
        SELECT COUNT(*) FROM orders
        WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
    ")->fetchColumn();

    $stats['invoices_total'] = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
    $stats['invoices_sent']  = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE sent_at IS NOT NULL")->fetchColumn();

    jsonResponse(['stats' => $stats]);
}

// ====================================================================
// PUT /admin.php?action=update-status
// ====================================================================
if ($method === 'PUT' && $action === 'update-status') {
    requireManager();

    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($data['order_id'] ?? 0);
    $newStatus = sanitize($data['status'] ?? '');

    if (!$orderId || !in_array($newStatus, ['confirmado', 'pendiente_pago', 'enviado'])) {
        jsonResponse(['error' => 'Datos no válidos'], 400);
    }

    $pdo = getDB();

    // Capture previous status for the audit log
    $prev = $pdo->prepare("SELECT status, source, phone, order_code FROM orders WHERE id = ?");
    $prev->execute([$orderId]);
    $row = $prev->fetch();
    if (!$row) jsonResponse(['error' => 'Pedido no encontrado'], 404);
    $prevStatus = $row['status'];

    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $orderId]);

    logEvent($orderId, 'status_changed',
        "Estado: $prevStatus → $newStatus", 'admin',
        ['from' => $prevStatus, 'to' => $newStatus]);

    // WhatsApp notify for orders that came from the bot
    if ($row['source'] === 'whatsapp' && !empty($row['phone'])) {
        $labels = [
            'confirmado'     => '✅ Tu pedido ' . $row['order_code'] . ' ha sido CONFIRMADO. Te entregaremos los sacos en 24-48h.',
            'enviado'        => '🚚 Tu pedido ' . $row['order_code'] . ' está EN CAMINO. ¡En breve llegan los sacos!',
            'pendiente_pago' => '⏳ Tu pedido ' . $row['order_code'] . ' está pendiente de pago. Si necesitas el enlace, escribe "estado" por aquí.',
        ];
        if (isset($labels[$newStatus])) {
            if (@waNotify($row['phone'], $labels[$newStatus])) {
                logEvent($orderId, 'whatsapp_notify', 'Notificación WhatsApp enviada por cambio de estado', 'admin');
            }
        }
    }

    jsonResponse(['success' => true, 'message' => "Estado actualizado a '$newStatus'"]);
}

// ====================================================================
// PUT /admin.php?action=update-notes
// Body: { order_id, internal_notes }
// ====================================================================
if ($method === 'PUT' && $action === 'update-notes') {
    requireManager();

    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($data['order_id'] ?? 0);
    $notes   = (string)($data['internal_notes'] ?? '');

    if (!$orderId) jsonResponse(['error' => 'order_id requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE orders SET internal_notes = ? WHERE id = ?");
    $stmt->execute([$notes, $orderId]);

    if ($stmt->rowCount() > 0 || $stmt->errorCode() === '00000') {
        logEvent($orderId, 'note_added', 'Nota interna actualizada', 'admin');
    }

    jsonResponse(['success' => true]);
}

// ====================================================================
// GET /admin.php?action=events&order_id=N
// ====================================================================
if ($method === 'GET' && $action === 'events') {
    requireManager();
    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) jsonResponse(['error' => 'order_id requerido'], 400);

    jsonResponse(['events' => getEvents($orderId)]);
}

// ====================================================================
// GET /admin.php?action=invoices
// Returns all issued invoices joined with their orders.
// ====================================================================
if ($method === 'GET' && $action === 'invoices') {
    requireManager();
    $pdo = getDB();

    $sentFilter = sanitize($_GET['sent'] ?? ''); // 'yes' / 'no' / ''
    $where = [];
    $params = [];
    if ($sentFilter === 'yes') $where[] = "i.sent_at IS NOT NULL";
    if ($sentFilter === 'no')  $where[] = "i.sent_at IS NULL";

    $sql = "
        SELECT i.id, i.invoice_number, i.base_amount, i.iva_amount, i.total_amount,
               i.issued_at, i.sent_at,
               o.id AS order_id, o.order_code, o.name, o.email, o.nif,
               o.payment_method, o.status, o.source
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
    ";
    if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY i.issued_at DESC, i.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['invoices' => $stmt->fetchAll()]);
}

// ====================================================================
// GET /admin.php?action=email-log&order_id=N (optional)
// ====================================================================
if ($method === 'GET' && $action === 'email-log') {
    requireManager();
    $pdo = getDB();
    $orderId = (int)($_GET['order_id'] ?? 0);

    if ($orderId) {
        $stmt = $pdo->prepare("SELECT * FROM email_log WHERE order_id = ? ORDER BY created_at DESC");
        $stmt->execute([$orderId]);
    } else {
        $stmt = $pdo->query("SELECT * FROM email_log ORDER BY created_at DESC LIMIT 100");
    }
    jsonResponse(['log' => $stmt->fetchAll()]);
}

// ====================================================================
// GET /admin.php?action=export-csv
// Streams CSV of (filtered) orders. Same filters as 'orders'.
// ====================================================================
if ($method === 'GET' && $action === 'export-csv') {
    requireManager();
    $pdo = getDB();

    $status = sanitize($_GET['status'] ?? '');
    $source = sanitize($_GET['source'] ?? '');
    $q      = sanitize($_GET['q'] ?? '');
    $from   = sanitize($_GET['from'] ?? '');
    $to     = sanitize($_GET['to'] ?? '');

    $where = [];
    $params = [];
    if ($status) { $where[] = "o.status = ?"; $params[] = $status; }
    if ($source) { $where[] = "o.source = ?"; $params[] = $source; }
    if ($q !== '') {
        $where[] = "(o.order_code LIKE ? OR o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ?)";
        $like = "%$q%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($from !== '') { $where[] = "DATE(o.created_at) >= ?"; $params[] = $from; }
    if ($to   !== '') { $where[] = "DATE(o.created_at) <= ?"; $params[] = $to; }

    $sql = "
        SELECT o.order_code, o.created_at, o.status, o.source, o.payment_method,
               o.package_name, o.package_qty, o.package_price,
               o.name, o.nif, o.email, o.phone,
               o.address, o.postal_code, o.city, o.observations,
               i.invoice_number, i.sent_at AS invoice_sent_at
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
    ";
    if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY o.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $filename = 'bf10_pedidos_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Codigo','Fecha','Estado','Origen','Pago','Pack','Cantidad','Importe',
        'Nombre','NIF','Email','Telefono','Direccion','CP','Ciudad','Observaciones',
        'Factura','Factura enviada'
    ], ';');

    while ($r = $stmt->fetch()) {
        fputcsv($out, [
            $r['order_code'], $r['created_at'], $r['status'], $r['source'] ?? 'web',
            $r['payment_method'], $r['package_name'], $r['package_qty'], $r['package_price'],
            $r['name'], $r['nif'], $r['email'], $r['phone'],
            $r['address'], $r['postal_code'], $r['city'], $r['observations'],
            $r['invoice_number'] ?? '', $r['invoice_sent_at'] ?? ''
        ], ';');
    }
    fclose($out);
    exit;
}

// ====================================================================
// DELETE /admin.php?action=delete&id=N
// ====================================================================
if ($method === 'DELETE' && $action === 'delete') {
    requireManager();
    $orderId = (int)($_GET['id'] ?? 0);
    if (!$orderId) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);

    if ($stmt->rowCount() === 0) jsonResponse(['error' => 'Pedido no encontrado'], 404);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
