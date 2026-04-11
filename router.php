<?php
/**
 * Local dev router — run with: php -S localhost:8080 router.php
 *
 * - API calls (/api/*) are proxied to production
 * - Static files served directly from local filesystem
 * - Allows testing frontend changes without deploying
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$localFile = __DIR__ . $uri;

// Serve static files directly (html, css, js, images) — but proxy .php to production
if ($uri !== '/' && is_file($localFile)) {
    $ext = pathinfo($localFile, PATHINFO_EXTENSION);

    // PHP files must be proxied to production, never executed locally
    if ($ext === 'php') {
        // Fall through to proxy section below
    } else {
        $mimeTypes = [
            'html' => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'ttf'   => 'font/ttf',
            'pdf'   => 'application/pdf',
        ];
        if (isset($mimeTypes[$ext])) {
            header('Content-Type: ' . $mimeTypes[$ext]);
        }
        return false; // let PHP built-in server handle the file
    }
}

// Directory requests: serve index.html if it exists
if (is_dir($localFile)) {
    $indexFile = rtrim($localFile, '/') . '/index.html';
    if (is_file($indexFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($indexFile);
        return true;
    }
}

// Proxy all PHP requests to production (API, auth, etc.)
if (str_ends_with($uri, '.php') || strpos($uri, '/api/') === 0) {
    $prodUrl = 'https://sacosescombromadridbf10.es' . $_SERVER['REQUEST_URI'];

    $headers = [];
    foreach (getallheaders() as $name => $value) {
        if (strtolower($name) === 'host') continue;
        $headers[] = "$name: $value";
    }
    // Forward cookies for session
    if (isset($_SERVER['HTTP_COOKIE'])) {
        $headers[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $body = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $body = file_get_contents('php://input');
    }

    $ch = curl_init($prodUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    if ($response === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Proxy error: ' . curl_error($ch)]);
        curl_close($ch);
        return true;
    }
    curl_close($ch);

    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);

    // Forward relevant response headers
    http_response_code($httpCode);
    foreach (explode("\r\n", $responseHeaders) as $line) {
        if (empty($line) || strpos($line, 'HTTP/') === 0) continue;
        $lower = strtolower($line);
        if (strpos($lower, 'content-type:') === 0 ||
            strpos($lower, 'content-disposition:') === 0) {
            header($line);
        }
        // Rewrite Set-Cookie: strip domain, secure, samesite so it works on localhost
        if (strpos($lower, 'set-cookie:') === 0) {
            $cookie = preg_replace('/;\s*domain=[^;]*/i', '', $line);
            $cookie = preg_replace('/;\s*secure/i', '', $cookie);
            $cookie = preg_replace('/;\s*samesite=[^;]*/i', '', $cookie);
            header($cookie, false); // false = don't replace previous Set-Cookie
        }
    }

    echo $responseBody;
    return true;
}

// Fallback: 404
http_response_code(404);
echo '404 Not Found';
return true;
