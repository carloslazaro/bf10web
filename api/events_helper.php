<?php
/**
 * Order events / audit log.
 *
 * Use logEvent($orderId, $type, $description, $actor, $metadata) to record
 * any meaningful change to an order. The admin panel renders these as a
 * timeline inside the order detail modal.
 *
 * Common event types:
 *   created, payment_pending, payment_received, status_changed,
 *   invoice_issued, invoice_sent, email_sent, email_failed,
 *   whatsapp_notify, note_added, deleted
 */
require_once __DIR__ . '/config.php';

function logEvent($orderId, $type, $description = null, $actor = null, $metadata = null) {
    if (!$orderId) return false;
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO order_events (order_id, event_type, description, actor, metadata)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $orderId,
            $type,
            $description,
            $actor,
            is_array($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : $metadata,
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getEvents($orderId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, event_type, description, actor, metadata, created_at
        FROM order_events
        WHERE order_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}
