<?php
/**
 * WhatsApp notify helper.
 * Sends a WhatsApp message to a phone number through the BF10 bot service
 * (Node.js on Railway). The bot exposes POST /notify protected by the
 * shared BF10_BOT_TOKEN.
 *
 * Returns true on success, false on failure (errors logged to whatsapp_notify.log).
 *
 * Usage:
 *     require_once __DIR__ . '/whatsapp_notify.php';
 *     waNotify('+34606967970', '✅ Tu pedido BF10-XXXXXX ha sido confirmado.');
 */
require_once __DIR__ . '/config.php';

if (!defined('BF10_BOT_URL')) {
    define('BF10_BOT_URL', 'https://whatsapp-formulario-sheets-production.up.railway.app');
}

function waNotify($phone, $message) {
    if (!defined('BF10_BOT_TOKEN') || BF10_BOT_TOKEN === '') {
        @file_put_contents(__DIR__ . '/whatsapp_notify.log',
            '[' . date('c') . "] BF10_BOT_TOKEN not defined\n", FILE_APPEND);
        return false;
    }
    if (!$phone || !$message) return false;

    // Normalize phone: strip non-digits, drop leading +.
    $phone = preg_replace('/[^\d]/', '', $phone);
    if ($phone === '') return false;

    $payload = json_encode(['phone' => $phone, 'message' => $message]);
    $ch = curl_init(BF10_BOT_URL . '/notify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . BF10_BOT_TOKEN,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) return true;

    @file_put_contents(__DIR__ . '/whatsapp_notify.log',
        '[' . date('c') . "] notify failed http=$code err=$err resp=$resp\n", FILE_APPEND);
    return false;
}
