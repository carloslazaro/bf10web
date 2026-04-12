<?php
/**
 * Google Sheets helper — uses service account JWT auth (no SDK needed).
 */
require_once __DIR__ . '/config.php';

function logGoogleApiCall($endpoint, $method, $spreadsheetId, $range, $statusCode, $responseTimeMs) {
    try {
        $pdo = getDB();
        $pdo->prepare("INSERT INTO google_api_log (endpoint, method, spreadsheet_id, sheet_range, status_code, response_time_ms) VALUES (?,?,?,?,?,?)")
            ->execute([$endpoint, $method, $spreadsheetId, $range, $statusCode, $responseTimeMs]);
    } catch (Exception $e) { /* silent */ }
}

function getGoogleAccessToken() {
    $credsFile = __DIR__ . '/google_service_account.json';
    if (!file_exists($credsFile)) {
        return null;
    }
    $creds = json_decode(file_get_contents($credsFile), true);

    // Check cached token
    if (!empty($_SESSION['gtoken_expires']) && $_SESSION['gtoken_expires'] > time() + 60) {
        return $_SESSION['gtoken'];
    }

    $now = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = base64url_encode(json_encode([
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $sigInput = "$header.$claim";
    openssl_sign($sigInput, $sig, $creds['private_key'], 'sha256WithRSAEncryption');
    $jwt = "$sigInput." . base64url_encode($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($resp['access_token'])) {
        $_SESSION['gtoken'] = $resp['access_token'];
        $_SESSION['gtoken_expires'] = $now + ($resp['expires_in'] ?? 3600);
        return $resp['access_token'];
    }
    return null;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Read a sheet range. Returns array of rows (each row = array of values).
 */
function sheetsRead($spreadsheetId, $range) {
    $token = getGoogleAccessToken();
    if (!$token) return ['error' => 'No se pudo obtener token de Google'];

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($spreadsheetId)
         . '/values/' . urlencode($range);

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logGoogleApiCall('sheets.values.get', 'GET', $spreadsheetId, $range, $code, (int)((microtime(true) - $t0) * 1000));

    if ($code !== 200) {
        return ['error' => $resp['error']['message'] ?? "HTTP $code"];
    }
    return $resp['values'] ?? [];
}

/**
 * Update a single cell or range.
 */
function sheetsUpdate($spreadsheetId, $range, $values) {
    $token = getGoogleAccessToken();
    if (!$token) return ['error' => 'No se pudo obtener token de Google'];

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($spreadsheetId)
         . '/values/' . urlencode($range)
         . '?valueInputOption=USER_ENTERED';

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['values' => $values]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logGoogleApiCall('sheets.values.update', 'PUT', $spreadsheetId, $range, $code, (int)((microtime(true) - $t0) * 1000));

    if ($code !== 200) {
        return ['error' => $resp['error']['message'] ?? "HTTP $code"];
    }
    return ['success' => true, 'updatedCells' => $resp['updatedCells'] ?? 0];
}

/**
 * Append rows at the end of a range.
 */
function sheetsAppend($spreadsheetId, $range, $values) {
    $token = getGoogleAccessToken();
    if (!$token) return ['error' => 'No se pudo obtener token de Google'];

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($spreadsheetId)
         . '/values/' . urlencode($range) . ':append'
         . '?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['values' => $values]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logGoogleApiCall('sheets.values.append', 'POST', $spreadsheetId, $range, $code, (int)((microtime(true) - $t0) * 1000));

    if ($code !== 200) {
        return ['error' => $resp['error']['message'] ?? "HTTP $code"];
    }
    return ['success' => true];
}

/**
 * Insert empty rows at a given position using batchUpdate.
 * $sheetId = numeric sheet id (from metadata), $rowIndex = 0-based insert position, $count = rows to insert.
 */
function sheetsInsertRows($spreadsheetId, $sheetId, $rowIndex, $count = 1) {
    $token = getGoogleAccessToken();
    if (!$token) return ['error' => 'No se pudo obtener token de Google'];

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($spreadsheetId) . ':batchUpdate';

    $body = [
        'requests' => [[
            'insertDimension' => [
                'range' => [
                    'sheetId'    => $sheetId,
                    'dimension'  => 'ROWS',
                    'startIndex' => $rowIndex,
                    'endIndex'   => $rowIndex + $count,
                ],
                'inheritFromBefore' => true,
            ],
        ]],
    ];

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logGoogleApiCall('sheets.batchUpdate', 'POST', $spreadsheetId, 'insertRows', $code, (int)((microtime(true) - $t0) * 1000));

    if ($code !== 200) {
        return ['error' => $resp['error']['message'] ?? "HTTP $code"];
    }
    return ['success' => true];
}

/**
 * Get spreadsheet metadata (sheet names, etc).
 */
function sheetsMetadata($spreadsheetId) {
    $token = getGoogleAccessToken();
    if (!$token) return ['error' => 'No se pudo obtener token de Google'];

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($spreadsheetId)
         . '?fields=sheets.properties';

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logGoogleApiCall('sheets.metadata', 'GET', $spreadsheetId, null, $code, (int)((microtime(true) - $t0) * 1000));

    if ($code !== 200) {
        return ['error' => $resp['error']['message'] ?? "HTTP $code"];
    }
    return $resp;
}
