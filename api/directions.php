<?php
/**
 * Directions API proxy — optimizes route and splits into chunks of max 10 waypoints.
 *
 * POST /api/directions.php
 * Body: { origin: {lat,lng}, destination: {lat,lng}, waypoints: [{lat,lng,id},...], optimize: true }
 *
 * Returns: { chunks: [ { origin, destination, waypoints, url }, ... ], totalStops, optimizedOrder: [ids] }
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$GMAPS_KEY = 'AIzaSyBgmkvCN-ZzZkxtdPQEyl4OaVvq10qu340';
$MAX_WAYPOINTS_PER_CHUNK = 10;

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['waypoints'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing waypoints']);
    exit;
}

$origin = $body['origin'];       // {lat, lng}
$destination = $body['destination']; // {lat, lng}
$waypoints = $body['waypoints'];   // [{lat, lng, id}, ...]
$optimize = $body['optimize'] ?? true;

$totalStops = count($waypoints);

// If 10 or fewer, no need for Directions API — just return a single chunk URL
if ($totalStops <= $MAX_WAYPOINTS_PER_CHUNK) {
    $wpStr = implode('|', array_map(fn($w) => "{$w['lat']},{$w['lng']}", $waypoints));
    $url = "https://www.google.com/maps/dir/?api=1"
         . "&origin={$origin['lat']},{$origin['lng']}"
         . "&destination={$destination['lat']},{$destination['lng']}"
         . "&waypoints=" . urlencode($wpStr)
         . "&travelmode=driving";
    echo json_encode([
        'totalStops' => $totalStops,
        'chunks' => [['url' => $url, 'stops' => $totalStops]],
        'optimizedOrder' => array_column($waypoints, 'id')
    ]);
    exit;
}

// Call Directions API to optimize the order
$wpStr = implode('|', array_map(fn($w) => "{$w['lat']},{$w['lng']}", $waypoints));
$apiUrl = "https://maps.googleapis.com/maps/api/directions/json?"
    . "origin={$origin['lat']},{$origin['lng']}"
    . "&destination={$destination['lat']},{$destination['lng']}"
    . "&waypoints=" . ($optimize ? "optimize:true|" : "") . urlencode($wpStr)
    . "&mode=driving"
    . "&key={$GMAPS_KEY}";

$response = file_get_contents($apiUrl);
$data = json_decode($response, true);

if ($data['status'] !== 'OK') {
    http_response_code(500);
    echo json_encode(['error' => 'Directions API error', 'status' => $data['status']]);
    exit;
}

// Get optimized order
$order = $data['routes'][0]['waypoint_order'] ?? range(0, $totalStops - 1);
$ordered = array_map(fn($i) => $waypoints[$i], $order);
$optimizedIds = array_map(fn($w) => $w['id'], $ordered);

// Split into chunks of MAX_WAYPOINTS_PER_CHUNK
$chunks = [];
$chunkList = array_chunk($ordered, $MAX_WAYPOINTS_PER_CHUNK);

foreach ($chunkList as $ci => $chunk) {
    // Origin: for first chunk use the real origin, for subsequent use previous chunk's last waypoint
    if ($ci === 0) {
        $chunkOrigin = $origin;
    } else {
        $prev = end($chunkList[$ci - 1]);
        $chunkOrigin = ['lat' => $prev['lat'], 'lng' => $prev['lng']];
    }

    // Destination: for last chunk use the real destination, for others use the last waypoint of this chunk
    if ($ci === count($chunkList) - 1) {
        $chunkDest = $destination;
        $chunkWaypoints = $chunk;
    } else {
        // Last waypoint becomes the destination of this chunk
        $chunkDest = ['lat' => end($chunk)['lat'], 'lng' => end($chunk)['lng']];
        $chunkWaypoints = array_slice($chunk, 0, -1);
    }

    $wpStr = implode('|', array_map(fn($w) => "{$w['lat']},{$w['lng']}", $chunkWaypoints));
    $url = "https://www.google.com/maps/dir/?api=1"
         . "&origin={$chunkOrigin['lat']},{$chunkOrigin['lng']}"
         . "&destination={$chunkDest['lat']},{$chunkDest['lng']}"
         . ($wpStr ? "&waypoints=" . urlencode($wpStr) : "")
         . "&travelmode=driving";

    $chunks[] = [
        'url' => $url,
        'stops' => count($chunk),
        'label' => 'Tramo ' . ($ci + 1)
    ];
}

echo json_encode([
    'totalStops' => $totalStops,
    'chunks' => $chunks,
    'optimizedOrder' => $optimizedIds
]);
