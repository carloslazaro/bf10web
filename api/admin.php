<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_notify.php';
require_once __DIR__ . '/events_helper.php';
require_once __DIR__ . '/certificate_generator.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/stripe_helper.php';

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

    if ($status && in_array($status, ['confirmado', 'pendiente_pago', 'enviado', 'recogida', 'recogida_solicitada'])) {
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
               o.paid_at, o.picked_up_at, o.created_at, o.updated_at,
               o.certificate_requested, o.certificate_requested_at,
               o.certificate_issued_at, o.certificate_number, o.certificate_file_path,
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
        FROM orders WHERE status IN ('confirmado','enviado','recogida')
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

    if (!$orderId || !in_array($newStatus, ['confirmado', 'pendiente_pago', 'enviado', 'recogida', 'recogida_solicitada'])) {
        jsonResponse(['error' => 'Datos no válidos'], 400);
    }

    $pdo = getDB();

    // Capture previous status for the audit log
    $prev = $pdo->prepare("SELECT status, source, phone, order_code, certificate_requested FROM orders WHERE id = ?");
    $prev->execute([$orderId]);
    $row = $prev->fetch();
    if (!$row) jsonResponse(['error' => 'Pedido no encontrado'], 404);
    $prevStatus = $row['status'];

    // When marking as 'recogida', also stamp picked_up_at
    if ($newStatus === 'recogida') {
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, picked_up_at = COALESCE(picked_up_at, NOW()) WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    }
    $stmt->execute([$newStatus, $orderId]);

    logEvent($orderId, 'status_changed',
        "Estado: $prevStatus → $newStatus", 'admin',
        ['from' => $prevStatus, 'to' => $newStatus]);

    // Auto-issue certificate if customer requested it and we just marked as 'recogida'
    $certInfo = null;
    if ($newStatus === 'recogida' && (int)$row['certificate_requested'] === 1) {
        $issued = issueCertificate($row['order_code']);
        if ($issued && empty($issued['error'])) {
            logEvent($orderId, 'certificate_issued',
                'Certificado RCD emitido automáticamente al marcar como recogida',
                'system',
                ['number' => $issued['order']['certificate_number'] ?? null]);
            if (function_exists('sendCertificateEmail')) {
                @sendCertificateEmail($issued['order'], $issued['path']);
            }
            $certInfo = [
                'issued' => true,
                'number' => $issued['order']['certificate_number'] ?? null,
            ];
        }
    }

    // WhatsApp notify for orders that came from the bot
    if ($row['source'] === 'whatsapp' && !empty($row['phone'])) {
        $labels = [
            'confirmado'     => '✅ Tu pedido ' . $row['order_code'] . ' ha sido CONFIRMADO. Te entregaremos los sacos en 24-48h.',
            'enviado'        => '🚚 Tu pedido ' . $row['order_code'] . ' está EN CAMINO. ¡En breve llegan los sacos!',
            'recogida_solicitada' => '📦 Hemos recibido tu solicitud de recogida para el pedido ' . $row['order_code'] . '. Te contactaremos para coordinar la fecha.',
            'recogida'       => '♻️ Tu pedido ' . $row['order_code'] . ' ha sido RECOGIDO. Los residuos van camino a planta autorizada.',
            'pendiente_pago' => '⏳ Tu pedido ' . $row['order_code'] . ' está pendiente de pago. Si necesitas el enlace, escribe "estado" por aquí.',
        ];
        if (isset($labels[$newStatus])) {
            if (@waNotify($row['phone'], $labels[$newStatus])) {
                logEvent($orderId, 'whatsapp_notify', 'Notificación WhatsApp enviada por cambio de estado', 'admin');
            }
        }
    }

    jsonResponse(['success' => true, 'message' => "Estado actualizado a '$newStatus'", 'certificate' => $certInfo]);
}

// ====================================================================
// POST /admin.php?action=issue-certificate
// Body: { order_id }   Manually issue/re-issue. Order must be 'recogida'.
// ====================================================================
if ($method === 'POST' && $action === 'issue-certificate') {
    requireManager();
    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($data['order_id'] ?? 0);
    if (!$orderId) jsonResponse(['error' => 'order_id requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT order_code FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    $result = issueCertificate($row['order_code']);
    if (!$result || isset($result['error'])) {
        jsonResponse(['error' => $result['error'] ?? 'Error al emitir el certificado'], 400);
    }

    logEvent($orderId, 'certificate_issued',
        'Certificado RCD emitido manualmente desde admin',
        'admin',
        ['number' => $result['order']['certificate_number'] ?? null]);

    if (function_exists('sendCertificateEmail')) {
        @sendCertificateEmail($result['order'], $result['path']);
    }

    jsonResponse([
        'success' => true,
        'certificate_number' => $result['order']['certificate_number'] ?? null,
        'newly_issued' => !empty($result['newly_issued']),
    ]);
}

// ====================================================================
// GET /admin.php?action=download-certificate&code=BF10-XXXX
// ====================================================================
if ($method === 'GET' && $action === 'download-certificate') {
    requireManager();
    $code = sanitize($_GET['code'] ?? '');
    if (!$code) jsonResponse(['error' => 'Código requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) jsonResponse(['error' => 'Pedido no encontrado'], 404);

    if (empty($order['certificate_issued_at'])) {
        $issued = issueCertificate($code);
        if (!$issued || isset($issued['error'])) {
            jsonResponse(['error' => $issued['error'] ?? 'Certificado no disponible'], 400);
        }
    }

    renderCertificatePdf($code, 'I');
    exit;
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

    $sql = "
        SELECT i.id, i.invoice_number, i.base_amount, i.iva_amount, i.total_amount,
               i.issued_at, i.sent_at,
               COALESCE(o.id, NULL) AS order_id,
               COALESCE(o.order_code, a.albaran_code) AS order_code,
               COALESCE(o.name, a.cliente) AS name,
               COALESCE(o.email, '') AS email,
               COALESCE(o.nif, '') AS nif,
               COALESCE(o.payment_method, a.forma_pago) AS payment_method,
               COALESCE(o.status, 'emitida') AS status,
               COALESCE(o.source, 'albaran') AS source,
               IF(i.order_id IS NOT NULL, 'pedido_web', 'albaran') AS origin
        FROM invoices i
        LEFT JOIN orders o ON o.id = i.order_id
        LEFT JOIN albaranes a ON a.id = i.albaran_id
        WHERE 1=1
    ";
    $params = [];
    if ($sentFilter === 'yes') $sql .= " AND i.sent_at IS NOT NULL";
    if ($sentFilter === 'no')  $sql .= " AND i.sent_at IS NULL";
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

// ====================================================================
// Helpers used by admin create-order / customer endpoints
// ====================================================================
function adminGenerateOrderCode($brandCode) {
    $brand = getBrand($brandCode);
    $prefix = $brand['order_prefix'];
    $pdo = getDB();
    do {
        $code = $prefix . '-' . strtoupper(substr(uniqid(), -6)) . rand(10, 99);
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

function findOrCreateCustomer($data) {
    $pdo = getDB();
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');

    $found = null;
    if ($phone !== '') {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $found = $stmt->fetch();
    }
    if (!$found && $email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $found = $stmt->fetch();
    }

    if ($found) {
        // Update missing fields with new info
        $upd = $pdo->prepare("
            UPDATE customers
            SET name        = COALESCE(NULLIF(?, ''), name),
                nif         = COALESCE(NULLIF(?, ''), nif),
                email       = COALESCE(NULLIF(?, ''), email),
                address     = COALESCE(NULLIF(?, ''), address),
                city        = COALESCE(NULLIF(?, ''), city),
                postal_code = COALESCE(NULLIF(?, ''), postal_code)
            WHERE id = ?
        ");
        $upd->execute([
            $data['name'] ?? '', $data['nif'] ?? '', $email,
            $data['address'] ?? '', $data['city'] ?? '', $data['postal_code'] ?? '',
            $found['id']
        ]);
        return (int)$found['id'];
    }

    $ins = $pdo->prepare("
        INSERT INTO customers (name, nif, email, phone, address, city, postal_code, first_order_at, last_order_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $ins->execute([
        $data['name'] ?? '', $data['nif'] ?? '', $email, $phone,
        $data['address'] ?? '', $data['city'] ?? '', $data['postal_code'] ?? ''
    ]);
    return (int)$pdo->lastInsertId();
}

function refreshCustomerStats($customerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE customers c
        LEFT JOIN (
            SELECT customer_id,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(package_price),0) AS spent,
                   MIN(created_at) AS first_at,
                   MAX(created_at) AS last_at
            FROM orders
            WHERE customer_id = ?
            GROUP BY customer_id
        ) o ON o.customer_id = c.id
        SET c.orders_count   = COALESCE(o.cnt, 0),
            c.total_spent    = COALESCE(o.spent, 0),
            c.first_order_at = COALESCE(o.first_at, c.first_order_at),
            c.last_order_at  = COALESCE(o.last_at, c.last_order_at)
        WHERE c.id = ?
    ");
    $stmt->execute([$customerId, $customerId]);
}

// ====================================================================
// POST /admin.php?action=create-order
// Body: { brand, package_qty, name, nif, email, phone, address, city,
//         postal_code, observations, payment_method, request_invoice,
//         internal_notes }
// Brand-aware admin order creation. Supports cash payment.
// ====================================================================
if ($method === 'POST' && $action === 'create-order') {
    requireManager();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    // Brand
    $brandCode = strtoupper(trim($data['brand'] ?? 'BF10'));
    if (!isset(BRANDS[$brandCode])) {
        jsonResponse(['error' => 'Marca no válida'], 400);
    }

    // Required
    $required = ['package_qty', 'name', 'phone', 'payment_method'];
    foreach ($required as $f) {
        if (empty($data[$f])) jsonResponse(['error' => "El campo '$f' es obligatorio"], 400);
    }

    $qty = (int)$data['package_qty'];
    if (!isset(PACKAGES[$qty])) jsonResponse(['error' => 'Paquete no válido'], 400);
    $package = PACKAGES[$qty];

    $payment = sanitize($data['payment_method']);
    if (!in_array($payment, ['card', 'transfer', 'cash'])) {
        jsonResponse(['error' => 'Método de pago no válido'], 400);
    }

    $email = sanitize($data['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Email no válido'], 400);
    }

    $name        = sanitize($data['name']);
    $phone       = sanitize($data['phone']);
    $nif         = sanitize($data['nif'] ?? '');
    $address     = sanitize($data['address'] ?? '');
    $city        = sanitize($data['city'] ?? '');
    $postalCode  = sanitize($data['postal_code'] ?? '');
    $observations = sanitize($data['observations'] ?? '');
    $internalNotes = sanitize($data['internal_notes'] ?? '');
    $requestInvoice = !empty($data['request_invoice']) ? 1 : 0;

    if ($nif && !preg_match('/^[A-Za-z]\d{7}[A-Za-z0-9]$|^\d{8}[A-Za-z]$/', $nif)) {
        jsonResponse(['error' => 'NIF/CIF no válido'], 400);
    }
    if ($requestInvoice && !$nif) {
        jsonResponse(['error' => 'Para emitir factura se necesita NIF/CIF'], 400);
    }

    // Customer
    $customerId = findOrCreateCustomer([
        'name' => $name, 'nif' => $nif, 'email' => $email, 'phone' => $phone,
        'address' => $address, 'city' => $city, 'postal_code' => $postalCode,
    ]);

    // Status: cash & transfer → confirmado (admin manualmente cobra),
    //         card → pendiente_pago (espera webhook Stripe)
    $status = ($payment === 'card') ? 'pendiente_pago' : 'confirmado';

    $orderCode = adminGenerateOrderCode($brandCode);

    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_code, user_id, customer_id, package_qty, package_name, package_price,
            name, nif, email, phone, address, city, postal_code, observations, internal_notes,
            request_invoice, payment_method, status, source, brand, paid_at
        ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin', ?, ?)
    ");

    try {
        $stmt->execute([
            $orderCode, $customerId, $qty, $package['name'], $package['price'],
            $name, $nif, $email, $phone, $address, $city, $postalCode, $observations, $internalNotes,
            $requestInvoice, $payment, $status, $brandCode,
            ($payment === 'cash') ? date('Y-m-d H:i:s') : null,
        ]);
        $orderId = (int)$pdo->lastInsertId();

        refreshCustomerStats($customerId);

        logEvent($orderId, 'order_created',
            "Pedido creado desde admin (marca: $brandCode, pago: $payment)",
            'admin',
            ['brand' => $brandCode, 'payment' => $payment]);

        $response = [
            'success' => true,
            'order' => [
                'id' => $orderId,
                'code' => $orderCode,
                'brand' => $brandCode,
                'package' => $package['name'],
                'price' => $package['price'],
                'status' => $status,
                'payment_method' => $payment,
            ],
        ];

        // Card → Stripe checkout link
        if ($payment === 'card') {
            $session = stripeCreateCheckoutSession([
                'id' => $orderId,
                'order_code' => $orderCode,
                'package_qty' => $qty,
                'email' => $email ?: 'pedidos@sacosescombromadridbf10.es',
            ]);
            if (empty($session['__error'])) {
                $upd = $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?");
                $upd->execute([$session['id'], $orderId]);
                $response['checkout_url'] = $session['url'];
            }
        }

        // Send confirmation email if customer has email
        if ($email && $payment !== 'card') {
            $sel = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $sel->execute([$orderId]);
            $fullOrder = $sel->fetch();
            if ($fullOrder) {
                @sendOrderConfirmationEmail($fullOrder);
            }
        }

        jsonResponse($response, 201);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Error al crear el pedido: ' . $e->getMessage()], 500);
    }
}

// ====================================================================
// GET /admin.php?action=customers
// Optional ?q= search by name/phone/email/nif
// ====================================================================
if ($method === 'GET' && $action === 'customers') {
    requireManager();
    $pdo = getDB();
    $q = sanitize($_GET['q'] ?? '');

    $sql = "
        SELECT id, name, nif, email, phone, address, city, postal_code,
               orders_count, total_spent, first_order_at, last_order_at, created_at
        FROM customers
    ";
    $params = [];
    if ($q !== '') {
        $sql .= " WHERE (deleted_at IS NULL) AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR nif LIKE ?) ";
        $like = "%$q%";
        $params = [$like, $like, $like, $like];
    } else {
        $sql .= " WHERE deleted_at IS NULL ";
    }
    $sql .= " ORDER BY last_order_at DESC, created_at DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['customers' => $stmt->fetchAll()]);
}

// ====================================================================
// GET /admin.php?action=customer-detail&id=N
// Returns customer + all their orders
// ====================================================================
if ($method === 'GET' && $action === 'customer-detail') {
    requireManager();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'id requerido'], 400);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) jsonResponse(['error' => 'Cliente no encontrado'], 404);

    $stmt = $pdo->prepare("
        SELECT o.id, o.order_code, o.brand, o.package_name, o.package_qty, o.package_price,
               o.payment_method, o.status, o.source, o.created_at, o.paid_at, o.picked_up_at,
               i.invoice_number, i.sent_at AS invoice_sent_at
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
        WHERE o.customer_id = ? OR o.phone = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$id, $customer['phone']]);
    $orders = $stmt->fetchAll();

    // Also fetch albaranes linked to this customer by name
    $stmtAlb = $pdo->prepare("
        SELECT a.id, a.albaran_code, a.marca, a.num_sacas, a.precio, a.importe,
               a.forma_pago, a.pagado, a.fecha_entrega, a.cliente, a.comentarios,
               u.name AS comercial_name,
               i.invoice_number
        FROM albaranes a
        LEFT JOIN users u ON u.id = a.comercial_id
        LEFT JOIN invoices i ON i.albaran_id = a.id
        WHERE a.cliente = ? AND a.deleted_at IS NULL
        ORDER BY a.fecha_entrega DESC
    ");
    $stmtAlb->execute([$customer['name']]);
    $albaranes = $stmtAlb->fetchAll();

    jsonResponse(['customer' => $customer, 'orders' => $orders, 'albaranes' => $albaranes]);
}

// ====================================================================
// GET /admin.php?action=brands
// Returns the list of brands available for the create-order form
// ====================================================================
if ($method === 'GET' && $action === 'brands') {
    requireManager();
    $out = [];
    foreach (BRANDS as $code => $b) {
        $out[] = [
            'code' => $code,
            'trade_name' => $b['trade_name'],
            'color' => $b['color'],
        ];
    }
    jsonResponse(['brands' => $out]);
}

// ====================================================================
// POST /admin.php?action=customer-create
// Quick-create a customer (name required, others optional)
// ====================================================================
if ($method === 'POST' && $action === 'customer-create') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $name = sanitize($data['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'Nombre requerido'], 400);

    $ins = $pdo->prepare("
        INSERT INTO customers (name, nif, email, phone, address, city, postal_code, first_order_at, last_order_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $ins->execute([
        $name,
        sanitize($data['nif'] ?? ''),
        sanitize($data['email'] ?? ''),
        sanitize($data['phone'] ?? ''),
        sanitize($data['address'] ?? ''),
        sanitize($data['city'] ?? ''),
        sanitize($data['postal_code'] ?? ''),
    ]);
    $id = (int)$pdo->lastInsertId();
    jsonResponse(['success' => true, 'id' => $id, 'name' => $name]);
}

// ====================================================================
// POST /admin.php?action=customer-update
// ====================================================================
if ($method === 'POST' && $action === 'customer-update') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $fields = ['name', 'nif', 'email', 'phone', 'address', 'city', 'postal_code'];
    $sets = [];
    $params = [];
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $sets[] = "$f = ?";
            $params[] = sanitize($data[$f]);
        }
    }
    if (empty($sets)) jsonResponse(['error' => 'Nada que actualizar'], 400);
    $params[] = $id;
    $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    jsonResponse(['success' => true]);
}

// ====================================================================
// POST /admin.php?action=customer-delete  (soft delete)
// ====================================================================
if ($method === 'POST' && $action === 'customer-delete') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
    $pdo->prepare("UPDATE customers SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ====================================================================
// POST /admin.php?action=customer-restore
// ====================================================================
if ($method === 'POST' && $action === 'customer-restore') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);
    $pdo->prepare("UPDATE customers SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true]);
}

// ====================================================================
// POST /admin.php?action=invoice-refresh-client
// Refreshes the client data on the order/albaran linked to an invoice
// ====================================================================
if ($method === 'POST' && $action === 'invoice-refresh-client') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $invoiceId = (int)($data['invoice_id'] ?? 0);
    if (!$invoiceId) jsonResponse(['error' => 'invoice_id requerido'], 400);

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv) jsonResponse(['error' => 'Factura no encontrada'], 404);

    if ($inv['order_id']) {
        // Order-based invoice: refresh from customers table
        $stmt = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
        $stmt->execute([$inv['order_id']]);
        $order = $stmt->fetch();
        if ($order && $order['customer_id']) {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$order['customer_id']]);
            $cust = $stmt->fetch();
            if ($cust) {
                $pdo->prepare("
                    UPDATE orders SET name = ?, nif = ?, email = ?, phone = ?,
                           address = ?, city = ?, postal_code = ?
                    WHERE id = ?
                ")->execute([
                    $cust['name'], $cust['nif'] ?? '', $cust['email'] ?? '',
                    $cust['phone'] ?? '', $cust['address'] ?? '',
                    $cust['city'] ?? '', $cust['postal_code'] ?? '',
                    $inv['order_id']
                ]);
                jsonResponse(['success' => true, 'updated' => 'order', 'customer' => $cust['name']]);
            }
        }
        jsonResponse(['success' => false, 'error' => 'No se encontró cliente vinculado al pedido']);
    } elseif ($inv['albaran_id']) {
        // Albaran-based: look up customer by current albaran cliente name, refresh
        $stmt = $pdo->prepare("SELECT cliente FROM albaranes WHERE id = ?");
        $stmt->execute([$inv['albaran_id']]);
        $alb = $stmt->fetch();
        if ($alb) {
            // Try to find a matching customer record
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE name = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$alb['cliente']]);
            $cust = $stmt->fetch();
            if ($cust) {
                // Update albaran cliente name in case it changed
                $pdo->prepare("UPDATE albaranes SET cliente = ? WHERE id = ?")->execute([$cust['name'], $inv['albaran_id']]);
                jsonResponse(['success' => true, 'updated' => 'albaran', 'customer' => $cust['name']]);
            } else {
                jsonResponse(['success' => true, 'updated' => 'albaran', 'customer' => $alb['cliente'], 'note' => 'No hay ficha de cliente']);
            }
        }
        jsonResponse(['success' => false, 'error' => 'Albarán no encontrado']);
    }

    jsonResponse(['success' => false, 'error' => 'Factura sin pedido ni albarán vinculado']);
}

// ====================================================================
// GET /admin.php?action=users — list all users with PINs
// ====================================================================
if ($method === 'GET' && $action === 'users') {
    requireManager();
    $pdo = getDB();

    $users = $pdo->query("
        SELECT u.id, u.name, u.email, u.role, u.created_at, u.plain_password,
               cp.pin AS comercial_pin,
               c.pin AS conductor_pin
        FROM users u
        LEFT JOIN comerciales_pin cp ON cp.user_id = u.id
        LEFT JOIN conductores c ON c.nombre = u.name
        WHERE u.role != 'user'
        ORDER BY FIELD(u.role, 'manager', 'comercial', 'rutas'), u.name
    ")->fetchAll();

    jsonResponse(['users' => $users]);
}

// ====================================================================
// POST /admin.php?action=user-create
// ====================================================================
if ($method === 'POST' && $action === 'user-create') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);

    $name = sanitize($data['name'] ?? '');
    $email = sanitize($data['email'] ?? '');
    $role = sanitize($data['role'] ?? 'user');
    $password = $data['password'] ?? '';
    $pin = sanitize($data['pin'] ?? '');

    if (!$name) jsonResponse(['error' => 'Nombre requerido'], 400);
    if (!$email) jsonResponse(['error' => 'Email requerido'], 400);
    if (!$password) jsonResponse(['error' => 'Contraseña requerida'], 400);
    if (!in_array($role, ['manager', 'comercial', 'rutas', 'user', 'avisador', 'ceo'])) jsonResponse(['error' => 'Rol inválido'], 400);

    // Check duplicate email
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $chk->execute([$email]);
    if ($chk->fetch()) jsonResponse(['error' => 'Ya existe un usuario con ese email'], 400);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, plain_password, role) VALUES (?, ?, ?, ?, ?)");
    $ins->execute([$name, $email, $hash, $password, $role]);
    $userId = (int)$pdo->lastInsertId();

    // Create PIN entry for comercial/manager/avisador/ceo
    if (in_array($role, ['manager', 'comercial', 'avisador', 'ceo']) && $pin) {
        // Delete any existing entries to avoid unique key conflicts
        $pdo->prepare("DELETE FROM comerciales_pin WHERE user_id = ? OR nombre = ?")->execute([$userId, $name]);
        $pdo->prepare("INSERT INTO comerciales_pin (nombre, pin, user_id, activo) VALUES (?, ?, ?, 1)")
            ->execute([$name, $pin, $userId]);
    }

    // Create PIN entry for conductor
    if ($role === 'rutas' && $pin) {
        $pdo->prepare("INSERT INTO conductores (nombre, pin) VALUES (?, ?) ON DUPLICATE KEY UPDATE pin=VALUES(pin)")
            ->execute([$name, $pin]);
    }

    jsonResponse(['success' => true, 'id' => $userId]);
}

// ====================================================================
// POST /admin.php?action=user-update
// ====================================================================
if ($method === 'POST' && $action === 'user-update') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    // Read old user data BEFORE updating
    $oldUser = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
    $oldUser->execute([$id]);
    $oldData = $oldUser->fetch();
    if (!$oldData) jsonResponse(['error' => 'Usuario no encontrado'], 404);
    $oldName = $oldData['name'];
    $oldRole = $oldData['role'];

    $sets = [];
    $params = [];

    if (isset($data['name'])) { $sets[] = "name = ?"; $params[] = sanitize($data['name']); }
    if (isset($data['email'])) { $sets[] = "email = ?"; $params[] = sanitize($data['email']); }
    if (isset($data['role'])) { $sets[] = "role = ?"; $params[] = sanitize($data['role']); }
    if (!empty($data['password'])) {
        $sets[] = "password_hash = ?";
        $params[] = password_hash($data['password'], PASSWORD_BCRYPT);
        $sets[] = "plain_password = ?";
        $params[] = $data['password'];
    }

    if ($sets) {
        $params[] = $id;
        $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    // Update PIN
    $pin = sanitize($data['pin'] ?? '');
    $role = sanitize($data['role'] ?? '');
    $name = sanitize($data['name'] ?? '');

    // Clean up old PIN entries if role changed
    $pinRoles = ['manager', 'comercial', 'avisador', 'ceo'];
    if (in_array($oldRole, $pinRoles) && !in_array($role, $pinRoles)) {
        $pdo->prepare("DELETE FROM comerciales_pin WHERE user_id = ?")->execute([$id]);
    }
    if ($oldRole === 'rutas' && $role !== 'rutas') {
        $pdo->prepare("DELETE FROM conductores WHERE nombre = ?")->execute([$oldName]);
    }

    if (in_array($role, ['manager', 'comercial', 'avisador', 'ceo']) && $pin) {
        // Delete by user_id AND by old/new name to avoid unique key conflicts
        $pdo->prepare("DELETE FROM comerciales_pin WHERE user_id = ? OR nombre = ? OR nombre = ?")->execute([$id, $oldName, $name]);
        $pdo->prepare("INSERT INTO comerciales_pin (nombre, pin, user_id, activo) VALUES (?, ?, ?, 1)")
            ->execute([$name, $pin, $id]);
    }
    if ($role === 'rutas' && $pin) {
        // Delete by old name AND new name to be safe
        $pdo->prepare("DELETE FROM conductores WHERE nombre = ? OR nombre = ?")->execute([$oldName, $name]);
        $pdo->prepare("INSERT INTO conductores (nombre, pin, activo) VALUES (?, ?, 1)")
            ->execute([$name, $pin]);
    }

    jsonResponse(['success' => true]);
}

// ====================================================================
// POST /admin.php?action=user-delete
// ====================================================================
if ($method === 'POST' && $action === 'user-delete') {
    requireManager();
    $pdo = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM comerciales_pin WHERE user_id = ?")->execute([$id]);

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
