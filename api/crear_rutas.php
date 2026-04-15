<?php
/**
 * Crear Rutas v3 — assign conductores to unassigned stops using zonas_habituales,
 * then organize into viajes with urgentes-first + oldest-first priority.
 *
 * GET  ?action=preview    — conductor stats + unassigned stops count
 * POST ?action=generate   — auto-assign + organize viajes
 */
require_once __DIR__ . '/config.php';

if (!isManager() && !isFacturacion()) {
    jsonResponse(['error' => 'Acceso denegado'], 403);
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Get camion capacity for a conductor ──
function getCamionCapacity($pdo, $conductorNombre) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT cam.capacidad_sacas FROM camion_conductor cc
        JOIN camiones cam ON cam.id = cc.camion_id
        WHERE cc.conductor_nombre = ? AND cc.fecha = ? LIMIT 1
    ");
    $stmt->execute([$conductorNombre, $today]);
    $row = $stmt->fetch();
    if ($row && $row['capacidad_sacas'] > 0) return (int)$row['capacidad_sacas'];

    $stmt = $pdo->prepare("SELECT capacidad_sacas FROM camiones WHERE conductor_habitual = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$conductorNombre]);
    $row = $stmt->fetch();
    if ($row && $row['capacidad_sacas'] > 0) return (int)$row['capacidad_sacas'];

    return 13;
}

// ── Helper: check if a stop is priority (urgente/policia/ayuntamiento) ──
function isPriorityStop($p) {
    $u = strtoupper(trim($p['urgen'] ?? ''));
    return in_array($u, ['URGT', 'POLI', 'AYTO']);
}

// ── Helper: calculate centroid of a group of stops ──
function calcCentroid($paradas) {
    $sumLat = 0; $sumLng = 0; $n = 0;
    foreach ($paradas as $p) {
        if (!empty($p['lat']) && !empty($p['lng'])) {
            $sumLat += (float)$p['lat'];
            $sumLng += (float)$p['lng'];
            $n++;
        }
    }
    if ($n === 0) return null;
    return ['lat' => $sumLat / $n, 'lng' => $sumLng / $n];
}

// ── Helper: haversine distance in km ──
function haversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ── Assign barrios to viajes algorithm v2 ──
// Priority stops (URGT/POLI/AYTO) forced into viajes 1-3
// Geographic clustering using centroids to group nearby barrios
function assignViajes($paradas, $capacidad, $maxViajes) {
    // ── Step 1: Separate priority stops from normal stops ──
    $priorityStops = [];
    $normalStops = [];
    foreach ($paradas as $p) {
        if (isPriorityStop($p)) {
            $priorityStops[] = $p;
        } else {
            $normalStops[] = $p;
        }
    }

    // Initialize viajes
    $viajes = [];
    for ($v = 1; $v <= $maxViajes; $v++) {
        $viajes[$v] = ['paradas' => [], 'sacas' => 0];
    }
    $overflow = [];

    // ── Step 2: Place priority stops first into viajes 1-3 ──
    $maxPriorityViaje = min($maxViajes, 3);
    foreach ($priorityStops as $p) {
        $sacas = max((int)$p['sacos'], 1);
        $placed = false;
        // Try viajes 1-3 (least loaded first)
        for ($v = 1; $v <= $maxPriorityViaje; $v++) {
            if ($viajes[$v]['sacas'] + $sacas <= $capacidad) {
                $viajes[$v]['paradas'][] = $p;
                $viajes[$v]['sacas'] += $sacas;
                $placed = true;
                break;
            }
        }
        // If viajes 1-3 full, try viaje 4 as last resort
        if (!$placed && $maxViajes > $maxPriorityViaje) {
            for ($v = $maxPriorityViaje + 1; $v <= $maxViajes; $v++) {
                if ($viajes[$v]['sacas'] + $sacas <= $capacidad) {
                    $viajes[$v]['paradas'][] = $p;
                    $viajes[$v]['sacas'] += $sacas;
                    $placed = true;
                    break;
                }
            }
        }
        if (!$placed) $overflow[] = $p;
    }

    // ── Step 3: Group normal stops by barrio ──
    $barrios = [];
    foreach ($normalStops as $p) {
        $barrio = trim($p['barrio_cp']) ?: '(sin barrio)';
        if (!isset($barrios[$barrio])) {
            $barrios[$barrio] = ['paradas' => [], 'total_sacas' => 0, 'centroid' => null];
        }
        $barrios[$barrio]['paradas'][] = $p;
        $barrios[$barrio]['total_sacas'] += max((int)$p['sacos'], 1);
    }

    // Sort within each barrio by oldest first
    foreach ($barrios as &$bd) {
        usort($bd['paradas'], function($a, $b) {
            return (int)$a['id'] - (int)$b['id'];
        });
        $bd['centroid'] = calcCentroid($bd['paradas']);
    }
    unset($bd);

    // ── Step 4: Geographic clustering — assign barrios to viajes by proximity ──
    // Calculate centroid of each viaje (from priority stops already placed)
    // Then assign barrios to nearest viaje that has capacity
    $barrioList = array_values($barrios);
    $barrioKeys = array_keys($barrios);

    // Sort barrios by size DESC (largest first for better bin-packing)
    array_multisort(array_column($barrioList, 'total_sacas'), SORT_DESC, $barrioList, $barrioKeys);

    foreach ($barrioList as $idx => $barrioData) {
        // Find best viaje: nearest centroid with capacity
        $bestViaje = null;
        $bestDist = PHP_FLOAT_MAX;

        for ($v = 1; $v <= $maxViajes; $v++) {
            if ($viajes[$v]['sacas'] + $barrioData['total_sacas'] > $capacidad) continue;

            $vjCentroid = calcCentroid($viajes[$v]['paradas']);
            if ($vjCentroid && $barrioData['centroid']) {
                $dist = haversine($vjCentroid['lat'], $vjCentroid['lng'],
                                  $barrioData['centroid']['lat'], $barrioData['centroid']['lng']);
            } else {
                // No centroid available — prefer least loaded
                $dist = $viajes[$v]['sacas'];
            }

            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestViaje = $v;
            }
        }

        if ($bestViaje !== null) {
            foreach ($barrioData['paradas'] as $p) {
                $viajes[$bestViaje]['paradas'][] = $p;
                $viajes[$bestViaje]['sacas'] += max((int)$p['sacos'], 1);
            }
        } else {
            // Barrio doesn't fit as block — split individually
            foreach ($barrioData['paradas'] as $p) {
                $sacas = max((int)$p['sacos'], 1);
                $placed = false;
                for ($v = 1; $v <= $maxViajes; $v++) {
                    if ($viajes[$v]['sacas'] + $sacas <= $capacidad) {
                        $viajes[$v]['paradas'][] = $p;
                        $viajes[$v]['sacas'] += $sacas;
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) $overflow[] = $p;
            }
        }
    }

    // ── Step 5: Compact — remove empty viajes and renumber ──
    $compacted = [];
    $vNum = 1;
    for ($v = 1; $v <= $maxViajes; $v++) {
        if (!empty($viajes[$v]['paradas'])) {
            $compacted[$vNum] = $viajes[$v];
            $vNum++;
        }
    }
    return ['viajes' => $compacted, 'overflow' => $overflow];
}

// ══════════════════════════════════════════
// PREVIEW — show conductor stats + unassigned
// ══════════════════════════════════════════
if ($method === 'GET' && $action === 'preview') {
    $conductores = $pdo->query("
        SELECT c.nombre, cam.matricula AS camion, cam.capacidad_sacas
        FROM conductores c
        LEFT JOIN camiones cam ON cam.conductor_habitual = c.nombre AND cam.activo = 1
        WHERE c.activo = 1 ORDER BY c.nombre
    ")->fetchAll();

    $assigned = $pdo->query("
        SELECT UPPER(conductor) as conductor, COUNT(*) as paradas, COALESCE(SUM(CAST(sacos AS UNSIGNED)),0) as sacas
        FROM rutas_data
        WHERE (estado = '' OR estado IS NULL OR estado = 'por_recoger')
          AND conductor != '' AND conductor IS NOT NULL
        GROUP BY UPPER(conductor)
    ")->fetchAll();
    $assignedMap = [];
    foreach ($assigned as $a) $assignedMap[$a['conductor']] = ['paradas' => (int)$a['paradas'], 'sacas' => (int)$a['sacas']];

    $unassigned = $pdo->query("
        SELECT COUNT(*) as paradas, COALESCE(SUM(CAST(sacos AS UNSIGNED)),0) as sacas
        FROM rutas_data
        WHERE (estado = '' OR estado IS NULL OR estado = 'por_recoger')
          AND (conductor = '' OR conductor IS NULL)
          AND NOT (UPPER(TRIM(direccion)) LIKE 'DIA %' AND (sacos IS NULL OR sacos = '' OR sacos = '0'))
    ")->fetch();

    // Load zonas habituales per conductor
    $zonas = $pdo->query("SELECT conductor, barrio FROM zonas_habituales WHERE activo = 1 ORDER BY conductor, barrio")->fetchAll();
    $zonasMap = [];
    foreach ($zonas as $z) {
        $c = $z['conductor'];
        if (!isset($zonasMap[$c])) $zonasMap[$c] = [];
        $zonasMap[$c][] = $z['barrio'];
    }

    $data = [];
    foreach ($conductores as $c) {
        $n = strtoupper($c['nombre']);
        $a = $assignedMap[$n] ?? ['paradas' => 0, 'sacas' => 0];
        $data[] = [
            'nombre' => $c['nombre'],
            'paradas_asignadas' => $a['paradas'],
            'sacas_asignadas' => $a['sacas'],
            'camion' => $c['camion'] ?: '-',
            'capacidad' => ($c['capacidad_sacas'] && $c['capacidad_sacas'] > 0) ? (int)$c['capacidad_sacas'] : 13,
            'zonas' => $zonasMap[$n] ?? []
        ];
    }

    jsonResponse([
        'conductores' => $data,
        'sin_conductor' => ['paradas' => (int)$unassigned['paradas'], 'sacas' => (int)$unassigned['sacas']]
    ]);
}

// ══════════════════════════════════════════
// GENERATE — assign unassigned stops + organize viajes
// Uses zonas_habituales for conductor-barrio matching
// ══════════════════════════════════════════
if ($method === 'POST' && $action === 'generate') {
    $input = json_decode(file_get_contents('php://input'), true);
    $conductoresInput = $input['conductores'] ?? [];

    if (empty($conductoresInput)) {
        jsonResponse(['error' => 'Selecciona al menos un conductor'], 400);
    }

    // Build conductor list with capacities
    $conductorList = [];
    foreach ($conductoresInput as $ci) {
        $nombre = strtoupper(trim($ci['nombre'] ?? ''));
        $maxViajes = min(max((int)($ci['max_viajes'] ?? 4), 1), 4);
        if (!$nombre) continue;
        $capacidad = getCamionCapacity($pdo, $nombre);
        $conductorList[$nombre] = ['max_viajes' => $maxViajes, 'capacidad' => $capacidad];
    }

    // ── Load zonas habituales for selected conductores ──
    $zonasMap = []; // barrio → [conductor1, conductor2, ...]
    $zonas = $pdo->query("SELECT conductor, barrio FROM zonas_habituales WHERE activo = 1")->fetchAll();
    foreach ($zonas as $z) {
        if (!isset($conductorList[$z['conductor']])) continue;
        $b = $z['barrio'];
        if (!isset($zonasMap[$b])) $zonasMap[$b] = [];
        $zonasMap[$b][] = $z['conductor'];
    }

    // Track load per conductor for balancing within shared zones
    $conductorLoad = [];
    foreach ($conductorList as $n => $cfg) {
        $conductorLoad[$n] = 0;
    }

    // ── Step 1: Get unassigned pending stops, urgentes first then oldest ──
    // Exclude organizer rows (direccion starts with "DIA" and no sacos)
    $unassigned = $pdo->query("
        SELECT id, direccion, barrio_cp, sacos, urgen, marca, lat, lng,
               CASE WHEN urgen != '' AND LOWER(urgen) != 'no' THEN 1 ELSE 0 END as is_urgent
        FROM rutas_data
        WHERE (conductor = '' OR conductor IS NULL)
          AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')
          AND NOT (UPPER(TRIM(direccion)) LIKE 'DIA %' AND (sacos IS NULL OR sacos = '' OR sacos = '0'))
        ORDER BY is_urgent DESC, id ASC
    ")->fetchAll();

    // ── Step 2: Count existing load per conductor ──
    $existingLoad = $pdo->query("
        SELECT UPPER(TRIM(conductor)) as conductor, COALESCE(SUM(CAST(sacos AS UNSIGNED)),0) as sacas
        FROM rutas_data
        WHERE conductor != '' AND conductor IS NOT NULL
          AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')
        GROUP BY UPPER(TRIM(conductor))
    ")->fetchAll();
    foreach ($existingLoad as $el) {
        if (isset($conductorLoad[$el['conductor']])) {
            $conductorLoad[$el['conductor']] = (int)$el['sacas'];
        }
    }

    // ── Step 3: Assign unassigned stops to conductores ──
    $newAssignments = [];
    $conductorNames = array_keys($conductorList);
    $rrIndex = 0;

    foreach ($unassigned as $stop) {
        $barrio = mb_strtolower(trim($stop['barrio_cp'] ?? ''));
        $conductor = null;

        // Try zona habitual match
        if ($barrio && isset($zonasMap[$barrio])) {
            $candidates = $zonasMap[$barrio];
            // Pick the conductor with least current load among candidates
            $minLoad = PHP_INT_MAX;
            foreach ($candidates as $cand) {
                if (isset($conductorLoad[$cand]) && $conductorLoad[$cand] < $minLoad) {
                    $minLoad = $conductorLoad[$cand];
                    $conductor = $cand;
                }
            }
        }

        // Fallback: round-robin among selected conductores (least loaded first)
        if (!$conductor) {
            // Pick least loaded conductor
            $minLoad = PHP_INT_MAX;
            foreach ($conductorNames as $cn) {
                if ($conductorLoad[$cn] < $minLoad) {
                    $minLoad = $conductorLoad[$cn];
                    $conductor = $cn;
                }
            }
        }

        $sacas = max((int)$stop['sacos'], 1);
        $conductorLoad[$conductor] += $sacas;
        $newAssignments[$stop['id']] = $conductor;
    }

    // ── Step 4: For each conductor, get ALL their pending stops ──
    $result = [];
    $stats = ['total_paradas' => 0, 'asignadas_viaje' => 0, 'sin_viaje' => 0, 'nuevas_asignaciones' => count($newAssignments)];

    foreach ($conductorList as $nombre => $cfg) {
        $stmt = $pdo->prepare("
            SELECT id, direccion, barrio_cp, sacos, urgen, marca, lat, lng
            FROM rutas_data
            WHERE UPPER(conductor) = ?
              AND (estado = '' OR estado IS NULL OR estado = 'por_recoger')
            ORDER BY id ASC
        ");
        $stmt->execute([$nombre]);
        $existingStops = $stmt->fetchAll();

        $newStops = [];
        foreach ($unassigned as $stop) {
            if (isset($newAssignments[$stop['id']]) && $newAssignments[$stop['id']] === $nombre) {
                $stop['_new'] = true;
                $newStops[] = $stop;
            }
        }

        $allStops = array_merge($existingStops, $newStops);
        $stats['total_paradas'] += count($allStops);

        if (empty($allStops)) {
            $result[$nombre] = ['viajes' => [], 'overflow' => [], 'capacidad' => $cfg['capacidad'], 'max_viajes' => $cfg['max_viajes'], 'nuevas' => 0];
            continue;
        }

        $vResult = assignViajes($allStops, $cfg['capacidad'], $cfg['max_viajes']);

        $asignadas = 0;
        foreach ($vResult['viajes'] as $vData) {
            $asignadas += count($vData['paradas']);
        }
        $stats['asignadas_viaje'] += $asignadas;
        $stats['sin_viaje'] += count($vResult['overflow']);

        $result[$nombre] = [
            'viajes' => $vResult['viajes'],
            'overflow' => $vResult['overflow'],
            'capacidad' => $cfg['capacidad'],
            'max_viajes' => $cfg['max_viajes'],
            'nuevas' => count($newStops)
        ];
    }

    jsonResponse(['result' => $result, 'stats' => $stats, 'conductor_assignments' => $newAssignments]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
