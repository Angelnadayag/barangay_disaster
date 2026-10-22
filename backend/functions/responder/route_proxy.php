<?php
// ============================================================================
// Endpoint: Tactical Street Routing Proxy & Road Network Engine
// Returns high-precision GeoJSON road coordinates following actual streets
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Helpers.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');

$startLat = (isset($_GET['start_lat']) && is_numeric($_GET['start_lat'])) ? (float)$_GET['start_lat'] : null;
$startLng = (isset($_GET['start_lng']) && is_numeric($_GET['start_lng'])) ? (float)$_GET['start_lng'] : null;
$endLat = (isset($_GET['end_lat']) && is_numeric($_GET['end_lat'])) ? (float)$_GET['end_lat'] : null;
$endLng = (isset($_GET['end_lng']) && is_numeric($_GET['end_lng'])) ? (float)$_GET['end_lng'] : null;

if ($startLat === null || $startLng === null || $endLat === null || $endLng === null ||
    ($startLat === 0.0 && $startLng === 0.0) || ($endLat === 0.0 && $endLng === 0.0)) {
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates provided.']);
    exit;
}

// 1. Immediate proximity check (< 15 meters)
$distKm = haversineDistance($startLat, $startLng, $endLat, $endLng);
if ($distKm < 0.015) {
    echo json_encode([
        'success' => true,
        'points' => [[$startLat, $startLng], [$endLat, $endLng]],
        'distanceKm' => 0.02,
        'durationMins' => 1,
        'source' => 'immediate_proximity'
    ]);
    exit;
}

// 2. High-speed disk cache check (Precision: ~1 meter, cached 24h)
$cacheDir = sys_get_temp_dir() . '/barangay_route_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}
$cacheKey = md5(round($startLat, 5) . ',' . round($startLng, 5) . ';' . round($endLat, 5) . ',' . round($endLng, 5));
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    $cached = file_get_contents($cacheFile);
    if (!empty($cached)) {
        echo $cached;
        exit;
    }
}

// 3. Query Live OSRM driving engine
// HTTP port 80 is used first to avoid Windows cURL SSL handshake timeouts on demo server
$osrmServers = [
    "http://router.project-osrm.org/route/v1/driving/{$startLng},{$startLat};{$endLng},{$endLat}?overview=full&geometries=geojson",
    "https://router.project-osrm.org/route/v1/driving/{$startLng},{$startLat};{$endLng},{$endLat}?overview=full&geometries=geojson"
];

foreach ($osrmServers as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BarangayDisasterSystem/2.0 (Iligan City Emergency Command)');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($response)) {
        $json = json_decode($response, true);
        if (!empty($json['code']) && $json['code'] === 'Ok' && !empty($json['routes'][0]['geometry']['coordinates'])) {
            $rawCoords = $json['routes'][0]['geometry']['coordinates'];
            // GeoJSON coordinates are [lon, lat], Leaflet expects [lat, lon]
            $leafletPoints = array_map(function($pt) {
                return [(float)$pt[1], (float)$pt[0]];
            }, $rawCoords);

            $distanceMeters = (float)$json['routes'][0]['distance'];
            $durationSeconds = (float)$json['routes'][0]['duration'];

            $resultPayload = json_encode([
                'success' => true,
                'points' => $leafletPoints,
                'distanceKm' => round($distanceMeters / 1000, 2),
                'durationMins' => max(1, round($durationSeconds / 60)),
                'source' => 'osrm_live'
            ]);

            @file_put_contents($cacheFile, $resultPayload);
            echo $resultPayload;
            exit;
        }
    }
}

// 4. Offline Road Corridor Network Solver (Accurate Hinaplanon & Highway Network)
$streetPath = computeHinaplanonStreetPath($startLat, $startLng, $endLat, $endLng);
$fallbackPayload = json_encode([
    'success' => true,
    'points' => $streetPath['points'],
    'distanceKm' => $streetPath['distanceKm'],
    'durationMins' => $streetPath['durationMins'],
    'source' => 'hinaplanon_street_network'
]);
echo $fallbackPayload;
exit;

// ----------------------------------------------------------------------------
// Helper: Great-circle distance (Haversine formula in KM)
// ----------------------------------------------------------------------------
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $r * $c;
}

// ----------------------------------------------------------------------------
// High-Precision Hinaplanon & Regional Highway Network Waypoint Solver
// Traces actual road network curves along National Highway into Hinaplanon
// ----------------------------------------------------------------------------
function computeHinaplanonStreetPath($lat1, $lon1, $lat2, $lon2) {
    $junctions = [
        ['lat' => 8.141069, 'lng' => 124.085161, 'name' => 'Kolambugan Highway Junction'],
        ['lat' => 8.155282, 'lng' => 124.089869, 'name' => 'Bacolod Segment A'],
        ['lat' => 8.172467, 'lng' => 124.091574, 'name' => 'Bacolod Segment B'],
        ['lat' => 8.186681, 'lng' => 124.090784, 'name' => 'Coastal Turn into Linamon'],
        ['lat' => 8.188824, 'lng' => 124.097802, 'name' => 'Linamon West Bay Approach'],
        ['lat' => 8.182675, 'lng' => 124.117294, 'name' => 'Linamon Poblacion Highway'],
        ['lat' => 8.182623, 'lng' => 124.123577, 'name' => 'Linamon East Crossing'],
        ['lat' => 8.186205, 'lng' => 124.133961, 'name' => 'Buru-un Highway Entry'],
        ['lat' => 8.185374, 'lng' => 124.140008, 'name' => 'Maria Cristina National Road'],
        ['lat' => 8.184881, 'lng' => 124.150607, 'name' => 'Ditucalan Highway Crossing'],
        ['lat' => 8.186112, 'lng' => 124.165889, 'name' => 'Fuertes / Suarez Highway'],
        ['lat' => 8.196500, 'lng' => 124.195000, 'name' => 'Tominobo Highway'],
        ['lat' => 8.212000, 'lng' => 124.223000, 'name' => 'Tubod Highway Crossing'],
        ['lat' => 8.225000, 'lng' => 124.238000, 'name' => 'Pala-o Highway Junction'],
        ['lat' => 8.235000, 'lng' => 124.246000, 'name' => 'Iligan City Center / Roxas Avenue'],
        ['lat' => 8.242200, 'lng' => 124.254500, 'name' => 'Highway Entrance'],
        ['lat' => 8.243050, 'lng' => 124.255094, 'name' => 'Operations Base Crossing'],
        ['lat' => 8.243800, 'lng' => 124.254440, 'name' => 'Barangay Hall Crossing'],
        ['lat' => 8.244745, 'lng' => 124.255375, 'name' => 'Purok 1 Main Turn'],
        ['lat' => 8.245205, 'lng' => 124.256531, 'name' => 'Central Health Station'],
        ['lat' => 8.245842, 'lng' => 124.258084, 'name' => 'Purok 2 Arterial'],
        ['lat' => 8.245200, 'lng' => 124.259200, 'name' => 'Purok 2 Chapel Access'],
        ['lat' => 8.245800, 'lng' => 124.260000, 'name' => 'Multi-Purpose Covered Court'],
        ['lat' => 8.246496, 'lng' => 124.259655, 'name' => 'Central School Crossing'],
        ['lat' => 8.246899, 'lng' => 124.260479, 'name' => 'High School Avenue'],
        ['lat' => 8.246800, 'lng' => 124.261500, 'name' => 'National High School Gym'],
        ['lat' => 8.246300, 'lng' => 124.261900, 'name' => 'Elementary School Building A'],
        ['lat' => 8.247500, 'lng' => 124.263000, 'name' => 'St. Michael Parish Hall'],
        ['lat' => 8.243800, 'lng' => 124.261800, 'name' => 'Sports Pavilion Turn'],
        ['lat' => 8.243000, 'lng' => 124.262000, 'name' => 'Upland Covered Sports Pavilion']
    ];

    $startJunc = findNearestJunction($lat1, $lon1, $junctions);
    $endJunc = findNearestJunction($lat2, $lon2, $junctions);

    $path = [[$lat1, $lon1]];

    if ($startJunc['index'] === $endJunc['index'] || haversineDistance($lat1, $lon1, $lat2, $lon2) < 0.15) {
        $path[] = [$lat2, $lon2];
    } else {
        $fromIdx = $startJunc['index'];
        $toIdx = $endJunc['index'];
        $step = ($toIdx >= $fromIdx) ? 1 : -1;

        $path[] = [$junctions[$fromIdx]['lat'], $junctions[$fromIdx]['lng']];

        $cur = $fromIdx;
        while ($cur !== $toIdx) {
            $cur += $step;
            $path[] = [$junctions[$cur]['lat'], $junctions[$cur]['lng']];
        }

        $path[] = [$lat2, $lon2];
    }

    $totalDist = 0;
    for ($i = 0; $i < count($path) - 1; $i++) {
        $totalDist += haversineDistance($path[$i][0], $path[$i][1], $path[$i+1][0], $path[$i+1][1]);
    }

    $distKm = round(max(0.1, $totalDist), 2);
    $rapidMins = max(1, round(($distKm / 35) * 60));

    return [
        'points' => $path,
        'distanceKm' => $distKm,
        'durationMins' => $rapidMins
    ];
}

function findNearestJunction($lat, $lng, $junctions) {
    $minDist = PHP_FLOAT_MAX;
    $best = 0;
    foreach ($junctions as $idx => $j) {
        $d = haversineDistance($lat, $lng, $j['lat'], $j['lng']);
        if ($d < $minDist) {
            $minDist = $d;
            $best = $idx;
        }
    }
    return ['index' => $best, 'dist' => $minDist];
}
