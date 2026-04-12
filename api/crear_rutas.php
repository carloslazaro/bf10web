<?php
/**
 * Crear Rutas v3 — assign conductores to unassigned stops using zonas_habituales,
 * then organize into viajes with urgentes-first + oldest-first priority.
 *
 * GET  ?action=preview    — conductor stats + unassigned stops count
 * POST ?action=generate   — auto-assign + organize viajes
 */
require_once __DIR__ . '/config.php';

if (!isManager()) {
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

// ── Assign barrios to viajes algorithm ──
// Priority: urgentes first, then oldest within same barrio group
function assignViajes($paradas, $capacidad, $maxViajes) {
    // Group by barrio
    $barrios = [];
    foreach ($paradas as $p) {
        $barrio = trim($p['barrio_cp']) ?: '(sin barrio)';
        if (!isset($barrios[$barrio])) {
            $barrios[$barrio] = ['paradas' => [], 'total_sacas' => 0];
        }
        $barrios[$barrio]['paradas'][] = $p;
        $barrios[$barrio]['total_sacas'] += max((int)$p['sacos'], 1);
    }

    // Within each barrio: sort urgentes first, then by id ASC (oldest first)
    foreach ($barrios as &$bd) {
        usort($bd['paradas'], function($a, $b) {
            $aUrg = !empty($a['urgen']) && strtolower($a['urgen']) !== 'no' ? 1 : 0;
            $bUrg = !empty($b['urgen']) && strtolower($b['urgen']) !== 'no' ? 1 : 0;
            if ($aUrg !== $bUrg) return $bUrg - $aUrg; // urgentes first
            return (int)$a['id'] - (int)$b['id']; // oldest first
        });
    }
    unset($bd);

    // Sort barrios: those with urgentes first, then by total sacas DESC
    uasort($barrios, function($a, $b) {
        $aHasUrg = 0; $bHasUrg = 0;
        foreach ($a['paradas'] as $p) if (!empty($p['urgen']) && strtolower($p['urgen']) !== 'no') { $aHasUrg = 1; break; }
        foreach ($b['paradas'] as $p) if (!empty($p['urgen']) && strtolower($p['urgen']) !== 'no') { $bHasUrg = 1; break; }
        if ($aHasUrg !== $bHasUrg) return $bHasUrg - $aHasUrg;
        return $b['total_sacas'] - $a['total_sacas'];
    });

    // Initialize viajes
    $viajes = [];
    for ($v = 1; $v <= $maxViajes; $v++) {
        $viajes[$v] = ['paradas' => [], 'sacas' => 0];
    }
    $overflow = [];

    foreach ($barrios as $barrioData) {
        $assigned = false;
        for ($v = 1; $v <= $maxViajes; $v++) {
            if ($viajes[$v]['sacas'] + $barrioData['total_sacas'] <= $capacidad) {
                foreach ($barrioData['paradas'] as $p) {
                    $viajes[$v]['paradas'][] = $p;
                    $viajes[$v]['sacas'] += max((int)$p['sacos'], 1);
                }
                $assigned = true;
                break;
            }
        }
        if (!$assigned) {
            // Split paradas individually — urgentes first
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

    // Compact
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
        SELECT id, direccion, barrio_cp, sacos, urgen, marca,
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
            SELECT id, direccion, barrio_cp, sacos, urgen, marca
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
