<?php
/**
 * Barangay Disaster System - Master Comprehensive Data Seeder
 * Implements strict user specifications:
 * - 1 ICDRRMO Admin, 1 Barangay Head, 50 Responders, 50 Residents (All passwords: '12345678')
 * - Exactly 10 Puroks (all belonging to Hinaplanon only)
 * - 50 Residents and 50 Responders divided across the 10 Puroks (exactly 5 residents & 5 responders per purok)
 * - Every purok has at least 5 Incident Reports, Resource Transactions, Financial Aid (Ayuda), and Events
 * - ICDRRMO and Barangay Head share the exact same resources in agency_resources
 * - Fully dynamic inventory management preserved
 * - Clean anti-slop formatting
 *
 * Usage:
 *   php database/seed_all.php
 */

require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/database/Connection.php';

echo "========================================================\n";
echo "  Barangay Disaster System - Master Data Seeder\n";
echo "========================================================\n";

$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

// User password: '12345678'
$passwordHash = password_hash('12345678', PASSWORD_DEFAULT);

try {
    echo "Starting master database seeding...\n\n";

    // -------------------------------------------------------------------------
    // 1. EXACTLY 10 PUROKS (All belonging to Hinaplanon, Barangay ID: 12)
    // -------------------------------------------------------------------------
    echo "[1/10] Seeding Exactly 10 Hinaplanon Puroks...\n";
    $pdo->exec("TRUNCATE TABLE `puroks`");

    $puroks = [
        1  => ['name' => 'Purok Riverside 1', 'hazard' => 'Flash Flood, Mandulog River Overflow', 'risk' => 'Critical', 'hh' => 450, 'pop' => 2050, 'lat' => 8.246800, 'lng' => 124.258800],
        2  => ['name' => 'Purok Riverside 2', 'hazard' => 'Flash Flood, River Inundation', 'risk' => 'Critical', 'hh' => 520, 'pop' => 2350, 'lat' => 8.248200, 'lng' => 124.260500],
        3  => ['name' => 'Purok Sambag',       'hazard' => 'Flash Flood, Riverbank Erosion', 'risk' => 'Critical', 'hh' => 430, 'pop' => 1940, 'lat' => 8.249800, 'lng' => 124.262000],
        4  => ['name' => 'Purok Baybay',       'hazard' => 'Coastal Storm Surge, Tidal Overflow', 'risk' => 'Critical', 'hh' => 480, 'pop' => 2150, 'lat' => 8.251500, 'lng' => 124.254200],
        5  => ['name' => 'Purok San Miguel',   'hazard' => 'Urban Flood, Creek Drainage Overflow', 'risk' => 'High', 'hh' => 680, 'pop' => 3010, 'lat' => 8.245500, 'lng' => 124.259800],
        6  => ['name' => 'Purok Katipunan',    'hazard' => 'Flash Flood, Drainage Congestion', 'risk' => 'High', 'hh' => 390, 'pop' => 1720, 'lat' => 8.249000, 'lng' => 124.258000],
        7  => ['name' => 'Purok Orchids',      'hazard' => 'High Inundation Zone, Saturated Silt', 'risk' => 'High', 'hh' => 360, 'pop' => 1610, 'lat' => 8.242000, 'lng' => 124.260500],
        8  => ['name' => 'Purok Mahayahay',    'hazard' => 'Localized Ponding, Heavy Siltation', 'risk' => 'Moderate', 'hh' => 410, 'pop' => 1850, 'lat' => 8.244800, 'lng' => 124.261000],
        9  => ['name' => 'Purok Sto. Niño',    'hazard' => 'Monsoon Flooding, Heavy Rainfall', 'risk' => 'Moderate', 'hh' => 510, 'pop' => 2280, 'lat' => 8.243500, 'lng' => 124.257500],
        10 => ['name' => 'Purok Centro',       'hazard' => 'High Density Urban Fire, Strong Winds', 'risk' => 'Moderate', 'hh' => 620, 'pop' => 2780, 'lat' => 8.246200, 'lng' => 124.261800],
    ];

    $purokStmt = $pdo->prepare("INSERT INTO `puroks` (`id`, `barangay_id`, `name`, `hazard_types`, `risk_level`, `households`, `population`, `coordinates_lat`, `coordinates_lng`, `status`) VALUES (?, 12, ?, ?, ?, ?, ?, ?, ?, 'active')");
    foreach ($puroks as $id => $p) {
        $purokStmt->execute([$id, $p['name'], $p['hazard'], $p['risk'], $p['hh'], $p['pop'], $p['lat'], $p['lng']]);
    }

    // Sync purok cluster options
    $pdo->exec("TRUNCATE TABLE `purok_cluster_options`");
    $clusterStmt = $pdo->prepare("INSERT INTO `purok_cluster_options` (`barangay_id`, `name`) VALUES (12, ?)");
    foreach ($puroks as $p) {
        $clusterStmt->execute([$p['name']]);
    }

    // Sync hazard type options
    $pdo->exec("TRUNCATE TABLE `hazard_type_options`");
    $hazStmt = $pdo->prepare("INSERT INTO `hazard_type_options` (`purok_id`, `barangay_id`, `name`) VALUES (?, 12, ?)");
    foreach ($puroks as $pId => $p) {
        $hazards = explode(',', $p['hazard']);
        foreach ($hazards as $h) {
            $hazStmt->execute([$pId, trim($h)]);
        }
    }

    // -------------------------------------------------------------------------
    // 2. USERS (1 ICDRRMO, 1 Barangay Head, 50 Responders, 50 Residents)
    // -------------------------------------------------------------------------
    echo "[2/10] Seeding 102 Users (All passwords: '12345678')...\n";
    $pdo->exec("TRUNCATE TABLE `users`");
    $pdo->exec("TRUNCATE TABLE `residents`");

    $uStmt = $pdo->prepare("INSERT INTO `users` (`id`, `username`, `password`, `first_name`, `last_name`, `full_name`, `email`, `phone`, `gender`, `age`, `role`, `agency_id`, `position`, `availability`, `barangay_id`, `purok`, `status`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");

    $resStmt = $pdo->prepare("INSERT INTO `residents` (`id`, `username`, `password`, `first_name`, `last_name`, `full_name`, `email`, `phone`, `gender`, `age`, `barangay_id`, `purok`, `status`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 12, ?, 'active', NOW())");

    // 1. ICDRRMO Admin (User ID: 1)
    $uStmt->execute([
        1, 'icdrrmo_admin', $passwordHash, 'Engr. Armando', 'Castillo', 'Engr. Armando Castillo',
        'operations@icdrrmo.iligan.gov.ph', '+63 917 100 2000', 'Male', 48, 'icdrrmo', 1,
        'Head of Operations / Disaster Management Officer', 'Available', NULL, NULL
    ]);

    // 2. Barangay Head (Hinaplanon, User ID: 2)
    $uStmt->execute([
        2, 'brgy_hinaplanon', $passwordHash, 'Hon. Rodrigo', 'Almonte', 'Hon. Rodrigo Almonte',
        'captain.almonte@hinaplanon.iligan.gov.ph', '+63 917 234 5678', 'Male', 52, 'barangay_head', 13,
        'Punong Barangay / BDRRMC Chairman', 'Available', 12, 'Purok Centro'
    ]);

    // 50 Responders divided across 10 puroks (5 responders per purok)
    // User IDs: 3 to 52
    $responderSpecializations = [
        'Team Alpha Search & Rescue Lead', 'Emergency Medical Technician (EMT)', 'Water Rescue & Boat Operator',
        'Disaster Triage & Trauma Medic', 'Logistics & Evacuation Coordinator'
    ];
    $firstNamesM = ['Kurt', 'Mark', 'Danilo', 'Carlo', 'Rogelio', 'Eduardo', 'Alejandro', 'Felipe', 'Jaime', 'Ernesto', 'Salvador', 'Roberto', 'Arsenio', 'Vicente', 'Rolando', 'Christian', 'Ferdinand', 'Hernan', 'Karlo', 'Melchor'];
    $firstNamesF = ['Sarah', 'Elena', 'Maria', 'Lorna', 'Corazon', 'Teresa', 'Remedios', 'Grace', 'Carmen', 'Filomena', 'Rosario', 'Fatima', 'Beatrice', 'Divina', 'Esperanza', 'Gemma', 'Imelda', 'Jocelyn', 'Leila', 'Nenita'];
    $lastNames = ['Morales', 'Reyes', 'Villanueva', 'Mendoza', 'Soriano', 'Ramos', 'Gomez', 'Tan', 'Fernandez', 'Sotto', 'Balgos', 'Badelles', 'Cañete', 'Delos Reyes', 'Castillo', 'Dela Torre', 'Magno', 'Panganiban', 'Corpuz', 'Valderrama', 'Alcantara', 'Celdran', 'Pimentel', 'Santos', 'Padilla'];

    $responderId = 3;
    for ($pId = 1; $pId <= 10; $pId++) {
        $pName = $puroks[$pId]['name'];
        for ($rIdx = 0; $rIdx < 5; $rIdx++) {
            $isFemale = ($responderId % 2 == 0);
            $fn = $isFemale ? $firstNamesF[($responderId * 3) % count($firstNamesF)] : $firstNamesM[($responderId * 2) % count($firstNamesM)];
            $ln = $lastNames[($responderId * 5) % count($lastNames)];
            $fnFull = $fn . ' ' . $ln;
            $username = 'responder_' . ($responderId - 2);
            $email = "responder{$responderId}@bdrrmc.hinaplanon.gov.ph";
            $phone = '+63 929 ' . str_pad($responderId * 17, 3, '0', STR_PAD_LEFT) . ' ' . str_pad($responderId * 29, 4, '0', STR_PAD_LEFT);
            $position = $responderSpecializations[$rIdx];
            $gender = $isFemale ? 'Female' : 'Male';
            $age = 24 + (($responderId * 3) % 25);

            $uStmt->execute([
                $responderId, $username, $passwordHash, $fn, $ln, $fnFull,
                $email, $phone, $gender, $age, 'responder', 13,
                $position, 'Available', 12, $pName
            ]);
            $responderId++;
        }
    }

    // 50 Residents divided across 10 puroks (5 residents per purok)
    // User IDs: 53 to 102 (and resident IDs: 1 to 50)
    $residentUserId = 53;
    $residentTableId = 1;
    for ($pId = 1; $pId <= 10; $pId++) {
        $pName = $puroks[$pId]['name'];
        for ($resIdx = 0; $resIdx < 5; $resIdx++) {
            $isFemale = ($residentTableId % 2 != 0);
            $fn = $isFemale ? $firstNamesF[($residentTableId * 7) % count($firstNamesF)] : $firstNamesM[($residentTableId * 4) % count($firstNamesM)];
            $ln = $lastNames[($residentTableId * 3) % count($lastNames)];
            $fnFull = $fn . ' ' . $ln;
            $username = 'resident_' . $residentTableId;
            $email = "resident{$residentTableId}@gmail.com";
            $phone = '+63 970 ' . str_pad($residentTableId * 31, 3, '0', STR_PAD_LEFT) . ' ' . str_pad($residentTableId * 43, 4, '0', STR_PAD_LEFT);
            $gender = $isFemale ? 'Female' : 'Male';
            $age = 20 + (($residentTableId * 5) % 50);

            // Insert into residents table
            $resStmt->execute([
                $residentTableId, $username, $passwordHash, $fn, $ln, $fnFull,
                $email, $phone, $gender, $age, $pName
            ]);

            // Mirror into users table for seamless authentication and FK relations
            $uStmt->execute([
                $residentUserId, $username, $passwordHash, $fn, $ln, $fnFull,
                $email, $phone, $gender, $age, 'resident', NULL,
                'Barangay Resident', 'Available', 12, $pName
            ]);

            $residentUserId++;
            $residentTableId++;
        }
    }

    // -------------------------------------------------------------------------
    // 3. AGENCIES & IDENTICAL RESOURCES (ICDRRMO & Barangay Head share all resources)
    // -------------------------------------------------------------------------
    echo "[3/10] Synchronizing Identical Resource Inventories for ICDRRMO & Hinaplanon...\n";
    $pdo->exec("TRUNCATE TABLE `agency_resources`");

    // Fetch all catalog resources
    $allCatalogResources = $pdo->query("SELECT * FROM `resources` WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

    $arStmt = $pdo->prepare("INSERT INTO `agency_resources` (
        `agency_id`, `resource_id`, `total_quantity`, `available_quantity`, `in_use_quantity`, 
        `damaged_quantity`, `min_threshold`, `batch_number`, `supplier_id`, `date_acquired`, 
        `expiry_date`, `cost_per_unit`, `item_condition`, `status`
    ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 'Good', 'active')");

    foreach ($allCatalogResources as $r) {
        $resId = (int)$r['id'];
        $baseTotal = max(50, (int)$r['total_quantity']);
        $baseAvail = max(30, (int)$r['available_quantity']);
        $baseInUse = (int)$r['in_use_quantity'];
        $threshold = max(20, (int)$r['min_threshold']);
        $batch = !empty($r['batch_number']) ? $r['batch_number'] : ('BATCH-2026-' . str_pad($resId, 3, '0', STR_PAD_LEFT));
        $supplierId = !empty($r['supplier_id']) ? $r['supplier_id'] : 1;
        $dateAcq = !empty($r['date_acquired']) ? $r['date_acquired'] : '2026-09-01';
        $expiry = !empty($r['expiry_date']) ? $r['expiry_date'] : '2028-12-31';
        $cost = (float)$r['cost_per_unit'];

        // Seed for Agency 1: ICDRRMO
        $arStmt->execute([1, $resId, $baseTotal, $baseAvail, $baseInUse, $threshold, $batch, $supplierId, $dateAcq, $expiry, $cost]);

        // Seed for Agency 13: BDRRMC - Hinaplanon (EXACT SAME RESOURCES)
        $arStmt->execute([13, $resId, $baseTotal, $baseAvail, $baseInUse, $threshold, $batch, $supplierId, $dateAcq, $expiry, $cost]);
    }

    // -------------------------------------------------------------------------
    // 4. EVACUATION CENTERS (6 Hinaplanon Centers)
    // -------------------------------------------------------------------------
    echo "[4/10] Seeding Hinaplanon Evacuation Shelters...\n";
    $pdo->exec("TRUNCATE TABLE `evacuation_areas`");
    $evacAreas = [
        [1, 'Hinaplanon National High School Gymnasium', 12, 'National Highway, Brgy. Hinaplanon, Iligan City', 'School Gym', 1200, 280, 250, 'Open / Active', 'Accessible (All Vehicles)', 1, 1, 1, 'Mr. Alberto Tan', '+63 917 444 5566', 8.246800, 124.261500],
        [2, 'Hinaplanon Barangay Multi-Purpose Covered Court', 12, 'Purok San Miguel, Hinaplanon, Iligan City', 'Barangay Multi-Purpose Hall', 800, 180, 110, 'Open / Active', 'Accessible (All Vehicles)', 1, 1, 1, 'Hon. Rodrigo Almonte', '+63 917 555 6677', 8.245800, 124.260000],
        [3, 'Hinaplanon Central Elementary School Building A', 12, 'Purok Centro, Hinaplanon, Iligan City', 'School Gym', 950, 210, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Principal Marissa Cruz', '+63 917 666 7788', 8.246300, 124.261900],
        [4, 'Purok San Miguel Community Chapel & Hall', 12, 'Purok San Miguel Interior, Hinaplanon, Iligan City', 'Church / Chapel', 400, 90, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Pastor Danilo Cruz', '+63 917 777 8899', 8.245200, 124.259200],
        [5, 'St. Michael Parish Pastoral Evacuation Hall', 12, 'Purok Sambag Boundary, Hinaplanon, Iligan City', 'Civic Center', 650, 150, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Sr. Maria Lopez', '+63 917 888 9900', 8.247500, 124.263000],
        [6, 'Hinaplanon Upland Covered Sports Pavilion', 12, 'Purok Orchids Perimeter, Hinaplanon, Iligan City', 'Designated Open Ground', 1100, 250, 0, 'Standby', 'High-Clearance / 4x4 Only', 1, 1, 0, 'Officer Kurt Morales', '+63 917 234 5678', 8.243000, 124.262000]
    ];
    $evacStmt = $pdo->prepare("INSERT INTO `evacuation_areas` (`id`, `name`, `barangay_id`, `location_address`, `center_type`, `capacity_individuals`, `capacity_families`, `current_evacuees_count`, `status`, `accessibility`, `has_potable_water`, `has_electricity`, `has_medical_station`, `contact_officer`, `contact_number`, `coordinates_lat`, `coordinates_lng`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($evacAreas as $ea) {
        $evacStmt->execute($ea);
    }

    // -------------------------------------------------------------------------
    // 5. INCIDENT REPORTS (>= 5 Incidents per Purok = 50+ total)
    // -------------------------------------------------------------------------
    echo "[5/10] Seeding 50+ Incident Reports (>= 5 per Purok)...\n";
    $pdo->exec("TRUNCATE TABLE `disaster_reports`");

    $repStmt = $pdo->prepare("INSERT INTO `disaster_reports` (
        `id`, `tracking_code`, `barangay_id`, `purok_name`, `coordinates_lat`, `coordinates_lng`, 
        `incident_location`, `disaster_type`, `severity`, `urgency`, `aid_support`, 
        `affected_families`, `affected_individuals`, `displaced_families`, `casualties_count`, 
        `injuries_count`, `missing_count`, `requested_assistance`, `situation_overview`, 
        `resolution_notes`, `status`, `submitted_by`, `created_at`, `completed_at`
    ) VALUES (?, ?, 12, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $disasterTypes = ['Flash Flood', 'Flood', 'Storm Surge', 'Fire', 'Landslide', 'Typhoon'];
    $severities = ['Critical', 'High', 'Moderate'];
    $urgencies = ['Immediate', 'High', 'Medium'];

    $reportId = 1;
    for ($pId = 1; $pId <= 10; $pId++) {
        $p = $puroks[$pId];
        $pName = $p['name'];

        for ($incIdx = 1; $incIdx <= 5; $incIdx++) {
            $trackCode = 'REQ-2026-HIN-' . str_pad($reportId, 3, '0', STR_PAD_LEFT);
            $lat = $p['lat'] + (rand(-40, 40) / 100000);
            $lng = $p['lng'] + (rand(-40, 40) / 100000);
            $dtype = $disasterTypes[($reportId + $incIdx) % count($disasterTypes)];
            $sev = $severities[($reportId + $incIdx) % count($severities)];
            $urg = $urgencies[($reportId + $incIdx) % count($urgencies)];
            $affFam = 30 + ($reportId * 5) % 150;
            $affInd = $affFam * 4;
            $dispFam = ($sev === 'Critical') ? (int)($affFam * 0.7) : (int)($affFam * 0.3);
            $injuries = ($sev === 'Critical') ? rand(1, 4) : 0;
            
            $status = ($incIdx <= 3) ? 'Ongoing' : 'Completed';
            $resolved = ($status === 'Completed') ? "Flood waters receded and debris cleared by BDRRMC team. Relief food packs successfully distributed." : NULL;
            $completedAt = ($status === 'Completed') ? '2026-10-14 17:00:00' : NULL;
            
            // Assign submitter from resident/responder in that purok
            $submitterId = 2; // Hon. Rodrigo Almonte
            $daysAgo = (10 - $incIdx);
            $createdAt = date('Y-m-d H:i:s', strtotime("2026-10-18 10:00:00 - {$daysAgo} days"));

            $overview = "Incident alert in {$pName}: Reported {$dtype} condition affecting {$affFam} households. Flood / hazard telemetry actively monitored by BDRRMC responders.";
            $assistance = "Requesting dispatch of {$affFam} Family Food Packs, hygiene kits, first aid replenishment, and evacuation transport support.";

            $repStmt->execute([
                $reportId, $trackCode, $pName, $lat, $lng,
                "{$pName} Residential Sector {$incIdx}", $dtype, $sev, $urg, 'both',
                $affFam, $affInd, $dispFam, 0, $injuries, 0,
                $assistance, $overview, $resolved, $status, $submitterId, $createdAt, $completedAt
            ]);
            $reportId++;
        }
    }

    // -------------------------------------------------------------------------
    // 6. RESOURCE TRANSACTIONS (>= 5 Transactions per Purok = 50+ total)
    // -------------------------------------------------------------------------
    echo "[6/10] Seeding 50+ Resource Transactions (>= 5 per Purok)...\n";
    $pdo->exec("TRUNCATE TABLE `resource_transactions`");

    $trxStmt = $pdo->prepare("INSERT INTO `resource_transactions` (
        `resource_id`, `transaction_type`, `quantity`, `reference_type`, `reference_id`, 
        `added_type`, `remarks`, `performed_by`, `created_at`
    ) VALUES (?, ?, ?, 'distribution', ?, ?, ?, ?, ?)");

    $trxTypes = ['Dispatched', 'Allocation', 'Restock', 'Allocation', 'Dispatched'];

    for ($pId = 1; $pId <= 10; $pId++) {
        $pName = $puroks[$pId]['name'];

        for ($tIdx = 1; $tIdx <= 5; $tIdx++) {
            $resId = (($pId * 5 + $tIdx) % 20) + 1; // Pick core items (Food, Water, Tents, Medical, etc.)
            $trxType = $trxTypes[($tIdx - 1) % count($trxTypes)];
            $qty = 20 + ($tIdx * 15);
            $actorId = ($tIdx % 2 == 0) ? 1 : 2; // ICDRRMO or Barangay Head
            $addedType = ($tIdx % 2 == 0) ? 'ai_recommended' : 'added_by_admin';
            $daysAgo = (12 - $tIdx);
            $createdAt = date('Y-m-d H:i:s', strtotime("2026-10-18 14:00:00 - {$daysAgo} days"));

            $remarks = "{$trxType} {$qty} units of emergency supplies specifically allocated for {$pName}, Hinaplanon disaster response.";

            $trxStmt->execute([
                $resId, $trxType, $qty, $pId, $addedType, $remarks, $actorId, $createdAt
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // 7. FINANCIAL AID (AYUDA) - Programs, Distributions (>= 5 per Purok) & Audits
    // -------------------------------------------------------------------------
    echo "[7/10] Seeding Financial Aid Allocations & 50+ Beneficiaries (>= 5 per Purok)...\n";
    $pdo->exec("TRUNCATE TABLE `financial_aid_allocations`");
    $pdo->exec("TRUNCATE TABLE `financial_aid_distributions`");
    $pdo->exec("TRUNCATE TABLE `financial_aid_audits`");

    $allocations = [
        [1, 'FAA-2026-HIN-001', 'Emergency Flood Relief Cash Assistance', 'Flash Flood', 12, 'All Puroks', 1500000.00, 5000.00, 1050000.00, 450000.00, 'LGU Calamity Fund (QRF)', 300, 'Active', 'ai_recommended', 'Emergency cash assistance approved under Executive Resolution for Mandulog River flash flood affected households.', 1, '2026-10-10 08:00:00'],
        [2, 'FAA-2026-HIN-002', 'Hinaplanon Coastal & Riverbank Recovery Grant', 'Storm Surge', 12, 'All Puroks', 1000000.00, 4000.00, 640000.00, 360000.00, 'DSWD Assistance to Individuals in Crisis (AICS)', 250, 'Active', 'ai_recommended', 'Special livelihood and repair grant for riverine and coastal households with damaged dwellings.', 1, '2026-10-11 10:30:00'],
        [3, 'FAA-2026-HIN-003', 'Hinaplanon Vulnerable Household, PWD & Senior Assistance', 'General Preparedness', 12, 'All Puroks', 800000.00, 4000.00, 520000.00, 280000.00, 'City Mayor Special Relief Assistance', 200, 'Active', 'added_by_admin', 'Targeted financial assistance program focusing on PWDs, single parents, and senior citizen headed households across all 10 Hinaplanon puroks.', 2, '2026-10-12 11:00:00'],
        [4, 'FAA-2026-HIN-004', 'Monsoon Drainage & Flash Flood Standby Recovery Fund', 'Flood', 12, 'All Puroks', 600000.00, 3000.00, 360000.00, 240000.00, 'Barangay Disaster Quick Response Fund', 200, 'Active', 'ai_recommended', 'Quick response recovery aid for localized ponding and drainage overflow victims.', 2, '2026-10-13 09:00:00'],
        [5, 'FAA-2026-HIN-005', 'Hinaplanon Small Livelihood Calamity Recovery Assistance', 'General Preparedness', 12, 'All Puroks', 500000.00, 5000.00, 300000.00, 200000.00, 'LGU Calamity Fund (QRF)', 100, 'Active', 'ai_recommended', 'Emergency assistance to restore micro-enterprises and small vendors impacted by weather disturbances.', 1, '2026-10-14 11:30:00']
    ];

    $allocStmt = $pdo->prepare("INSERT INTO `financial_aid_allocations` (
        `id`, `allocation_code`, `program_name`, `disaster_type`, `barangay_id`, `target_purok`, 
        `total_budget`, `amount_per_beneficiary`, `disbursed_budget`, `remaining_budget`, 
        `funding_source`, `target_beneficiaries`, `status`, `added_type`, `notes`, `allocated_by`, `created_at`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($allocations as $a) {
        $allocStmt->execute($a);
    }

    $distStmt = $pdo->prepare("INSERT INTO `financial_aid_distributions` (
        `allocation_id`, `barangay_id`, `resident_id`, `beneficiary_name`, `purok`, `phone`, 
        `amount`, `claim_status`, `claim_date`, `reference_no`, `sms_status`, `disbursed_by`, `remarks`, `created_at`
    ) VALUES (?, 12, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?, ?)");

    $auditStmt = $pdo->prepare("INSERT INTO `financial_aid_audits` (
        `allocation_id`, `distribution_id`, `barangay_id`, `action`, `details`, `amount`, `actor_id`, `actor_name`, `actor_role`, `created_at`
    ) VALUES (?, ?, 12, ?, ?, ?, ?, ?, ?, ?)");

    // Log allocations in audit
    foreach ($allocations as $a) {
        $auditStmt->execute([
            $a[0], NULL, 'Allocated', "Created financial aid program '{$a[2]}' with total budget of PHP " . number_format($a[6], 2),
            $a[6], $a[15], ($a[15] == 1 ? 'Engr. Armando Castillo' : 'Hon. Rodrigo Almonte'), ($a[15] == 1 ? 'icdrrmo' : 'barangay_head'), $a[16]
        ]);
    }

    // Seed 50 distributions: exactly 5 beneficiaries per purok for all 10 puroks
    $distId = 1;
    $resCounter = 1;
    for ($pId = 1; $pId <= 10; $pId++) {
        $pName = $puroks[$pId]['name'];

        for ($bIdx = 1; $bIdx <= 5; $bIdx++) {
            $resRow = $pdo->query("SELECT * FROM residents WHERE id = {$resCounter}")->fetch(PDO::FETCH_ASSOC);
            $allocId = (($distId - 1) % 5) + 1;
            $amt = $allocations[$allocId - 1][7];
            $isClaimed = ($bIdx <= 4); // 4 Claimed, 1 Pending per purok (80% claimed rate)
            $claimStatus = $isClaimed ? 'Claimed' : 'Pending';
            $claimDate = $isClaimed ? date('Y-m-d H:i:s', strtotime("2026-10-15 09:00:00 + " . ($distId * 25) . " minutes")) : NULL;
            $refNo = $isClaimed ? ('REF-2026-HIN-' . str_pad($distId, 4, '0', STR_PAD_LEFT)) : NULL;
            $remarks = $isClaimed ? "Disbursed to resident of {$pName}. Verified via PhilSys National ID." : "Scheduled for upcoming barangay payout batch.";
            $disbursedBy = $isClaimed ? 2 : NULL;
            $createdAt = date('Y-m-d H:i:s', strtotime("2026-10-14 08:30:00 + " . ($distId * 10) . " minutes"));

            $distStmt->execute([
                $allocId, $resCounter, $resRow['full_name'], $pName, $resRow['phone'],
                $amt, $claimStatus, $claimDate, $refNo, $disbursedBy, $remarks, $createdAt
            ]);

            if ($isClaimed) {
                $auditStmt->execute([
                    $allocId, $distId, 'Disbursed', "Released PHP " . number_format($amt, 2) . " aid to {$resRow['full_name']} ({$pName}) Ref #{$refNo}",
                    $amt, 2, 'Hon. Rodrigo Almonte', 'barangay_head', $claimDate
                ]);
            }

            $distId++;
            $resCounter++;
        }
    }

    // -------------------------------------------------------------------------
    // 8. EVENTS (>= 5 Events per Purok = 50 total) & ATTENDEES
    // -------------------------------------------------------------------------
    echo "[8/10] Seeding 50 Events (>= 5 per Purok) & Resident Attendees...\n";
    $pdo->exec("TRUNCATE TABLE `events`");
    $pdo->exec("TRUNCATE TABLE `event_attendees`");

    $evStmt = $pdo->prepare("INSERT INTO `events` (
        `id`, `title`, `activity_type`, `barangay_id`, `venue`, `start_datetime`, `end_datetime`, 
        `assigned_personnel`, `target_participants`, `actual_participants`, `description`, 
        `status`, `is_archived`, `evaluation_summary`, `content_execution`, `expected_activities`, 
        `cancellation_reason`, `created_by`, `created_at`, `latitude`, `longitude`
    ) VALUES (?, ?, ?, 12, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NULL, 2, ?, ?, ?)");

    $attStmt = $pdo->prepare("INSERT INTO `event_attendees` (
        `activity_id`, `user_id`, `resident_name`, `attended`, `phone`, `registered_at`, `sms_status`, `sms_message`
    ) VALUES (?, ?, ?, 1, ?, ?, 'sent', ?)");

    $eventThemes = [
        [
            'suffix' => 'Flood Evacuation & Early Warning Simulation Drill',
            'type' => 'Disaster Preparedness and Safety',
            'activities' => ['Emergency Evacuation Drill', 'Disaster Preparedness Seminar', 'Flood Preparedness Seminar', 'Basic Life Support Training']
        ],
        [
            'suffix' => 'Community Medical Mission & Free Health Consultation',
            'type' => 'Health and Medical',
            'activities' => ['Medical Mission', 'Free Medical Checkup', 'Free Medicine Distribution', 'Free Blood Pressure Check']
        ],
        [
            'suffix' => 'Community Clean-Up Drive & Drainage Desilting',
            'type' => 'Cleanliness and Environment',
            'activities' => ['Community Clean-Up Drive', 'Canal Cleaning', 'Clean and Green Program', 'Anti-Dengue Clean-Up Drive']
        ],
        [
            'suffix' => 'Supplemental Feeding & Child Nutrition Program',
            'type' => 'Nutrition and Feeding',
            'activities' => ['Supplemental Feeding Program', 'Feeding Program', 'Food Pack Distribution', 'Operation Timbang']
        ],
        [
            'suffix' => 'Emergency Relief Goods & Family Care Distribution',
            'type' => 'Social Welfare and Assistance',
            'activities' => ['Financial Assistance Distribution', 'Food Assistance Distribution', 'Relief Goods Distribution', 'Senior Citizens\' Assistance']
        ],
        [
            'suffix' => 'Basic Life Support & First Aid Skills Training',
            'type' => 'Education and Skills Training',
            'activities' => ['First Aid Training', 'Basic Life Support Training', 'Emergency Response Training', 'Job Skills Training']
        ],
        [
            'suffix' => 'Youth Leadership Assembly & Summer Sports Clinic',
            'type' => 'Youth and Children',
            'activities' => ['Youth Leadership Seminar', 'Summer Sports Clinic', 'Basketball Tournament', 'SK Youth Assembly']
        ],
        [
            'suffix' => 'Livelihood Assistance & Small Business Seminar',
            'type' => 'Livelihood and Employment',
            'activities' => ['Livelihood Assistance Program', 'Livelihood Training', 'Small Business Seminar', 'Free Resume Writing Workshop']
        ],
        [
            'suffix' => 'Free Anti-Rabies Vaccination & Pet Care Program',
            'type' => 'Animal Welfare',
            'activities' => ['Free Anti-Rabies Vaccination', 'Free Pet Checkup', 'Pet Deworming', 'Responsible Pet Ownership Seminar']
        ],
        [
            'suffix' => 'Urban Backyard Gardening & Seed Distribution',
            'type' => 'Agricultural Programs',
            'activities' => ['Free Seed Distribution', 'Urban Gardening Program', 'Organic Farming Seminar', 'Seedling Distribution']
        ],
        [
            'suffix' => 'Purok Consultation & Barangay Assembly Day',
            'type' => 'Government and Community Services',
            'activities' => ['Barangay Assembly', 'Purok Assembly', 'Community Consultation', 'Public Information Campaign']
        ],
        [
            'suffix' => 'Inter-Purok Sportsfest & Community Fitness Zumba',
            'type' => 'Sports and Recreation',
            'activities' => ['Barangay Sportsfest', 'Inter-Purok Basketball Tournament', 'Zumba Session', 'Community Aerobics']
        ],
        [
            'suffix' => 'Barangay Community Day & Special Gathering',
            'type' => 'Community Celebrations and Special Events',
            'activities' => ['Barangay Fiesta', 'Araw ng Barangay', 'Family Day Celebration', 'Community Concert']
        ]
    ];

    $eventId = 1;
    for ($pId = 1; $pId <= 10; $pId++) {
        $p = $puroks[$pId];
        $pName = $p['name'];
        $lat = (float) $p['lat'];
        $lng = (float) $p['lng'];

        for ($evIdx = 0; $evIdx < 5; $evIdx++) {
            $theme = $eventThemes[($eventId - 1) % count($eventThemes)];
            $title = "{$pName} {$theme['suffix']}";
            $actType = $theme['type'];
            $venue = "{$pName} Community Covered Area & Assembly Grounds";
            $activitiesJson = json_encode($theme['activities'], JSON_UNESCAPED_UNICODE);
            
            $daysOffset = ($eventId % 2 == 0) ? (15 - $eventId) : (-1 * (($eventId % 8) + 1));
            $isPast = ($daysOffset < 0);
            $offsetStr = ($daysOffset >= 0) ? "+{$daysOffset} days" : "{$daysOffset} days";
            $startDate = date('Y-m-d H:i:s', strtotime("2026-10-20 09:00:00 {$offsetStr}"));
            $endDate = date('Y-m-d H:i:s', strtotime("{$startDate} + 3 hours"));
            $status = $isPast ? 'Completed' : 'Scheduled';
            $targetPart = 60 + ($eventId * 2);
            $actualPart = $isPast ? ($targetPart - 5) : 0;
            $eval = $isPast ? "High community turnout in {$pName}. Evacuation timing benchmarks successfully met." : NULL;
            $assigned = "Responders Team Alpha ({$pName} Unit)";
            $desc = "Dedicated community activity for {$pName} residents, emphasizing practical engagement and preparedness for {$p['hazard']}.";
            $createdAt = date('Y-m-d H:i:s', strtotime("2026-10-01 08:00:00 + " . ($eventId * 6) . " hours"));

            $evStmt->execute([
                $eventId, $title, $actType, $venue, $startDate, $endDate,
                $assigned, $targetPart, $actualPart, $desc, $status,
                $eval, $activitiesJson, $activitiesJson, $createdAt,
                $lat, $lng
            ]);

            // Register the 5 residents of this purok as attendees
            $baseResidentId = (($pId - 1) * 5) + 1;
            for ($rOffset = 0; $rOffset < 5; $rOffset++) {
                $curResId = $baseResidentId + $rOffset;
                $curUserId = 52 + $curResId; // maps resident_1 (id 1) to user id 53
                $rData = $pdo->query("SELECT * FROM residents WHERE id = {$curResId}")->fetch(PDO::FETCH_ASSOC);
                $msg = "BARANGAY DISASTER ADVISORY\nHi {$rData['first_name']}!\nYou are registered for {$title} at {$venue}.\nDate: {$startDate}.";
                $attStmt->execute([
                    $eventId, $curUserId, $rData['full_name'], $rData['phone'], $createdAt, $msg
                ]);
            }

            $eventId++;
        }
    }

    // Event 51: Financial Aid Distribution (Scheduled / Active)
    $ev51Activities = json_encode([
        'Financial Assistance Distribution',
        'Food Assistance Distribution',
        'Relief Goods Distribution',
        'Senior Citizens\' Assistance'
    ], JSON_UNESCAPED_UNICODE);

    $evStmt->execute([
        51,
        'Hinaplanon Emergency Flood Relief Cash Assistance Distribution',
        'Social Welfare and Assistance',
        'Hinaplanon Covered Court & Gymnasium',
        '2026-10-24 09:00:00',
        '2026-10-24 16:00:00',
        'Hon. Rodrigo Almonte (Barangay Head) & City Social Welfare Officers',
        300,
        215,
        'Official payout and releasing of the Emergency Flood Relief Financial Assistance (₱5,000 per verified household). Beneficiaries must bring a valid government ID and Barangay Certificate upon claiming.',
        'Scheduled',
        NULL,
        $ev51Activities,
        $ev51Activities,
        '2026-10-18 08:00:00',
        8.246596,
        124.259474
    ]);

    // Event 52: Missed Concluded Event ("natapos nah")
    $ev52Activities = json_encode([
        'Emergency Evacuation Drill',
        'Disaster Preparedness Seminar',
        'Flood Preparedness Seminar',
        'Basic Life Support Training'
    ], JSON_UNESCAPED_UNICODE);

    $evStmt->execute([
        52,
        'Hinaplanon Riverine Flood Evacuation & Early Warning Drill',
        'Disaster Preparedness and Safety',
        'Purok Riverside 1 Multi-Purpose Assembly Area',
        '2026-10-16 08:30:00',
        '2026-10-16 11:30:00',
        'BDRRMC Rescue Alpha Team & Purok Leaders',
        120,
        95,
        'Concluded comprehensive riverine flood response and siren evacuation drill for low-lying riverbank households along the Mandulog River.',
        'Completed',
        'Concluded with 95 community participants. Evacuation staging and siren protocols successfully tested.',
        $ev52Activities,
        $ev52Activities,
        '2026-10-10 08:00:00',
        8.246596,
        124.259474
    ]);
    // NOTE: Resident accounts (user 53..57) are NOT registered in event_attendees for Event 52, marking it as a missed event.

    // -------------------------------------------------------------------------
    // 9. CLEAN NOTIFICATIONS (Residents, Responders, ICDRRMO, Barangay Head)
    // -------------------------------------------------------------------------
    echo "[9/10] Seeding Role-Scoped System Notifications...\n";
    $pdo->exec("TRUNCATE TABLE `notifications`");
    $pdo->exec("TRUNCATE TABLE `notification_reads`");

    $notifications = [
        // Resident notifications for Hinaplanon (target_barangay_id: 12)
        [1, 'resident', 12, NULL, 'Financial Aid Payout Schedule: Emergency Flood Relief Cash Assistance', 'Payout for Emergency Flood Relief Cash Assistance (FAA-2026-HIN-001) is active at Hinaplanon Covered Court. Beneficiaries must present valid government ID.', 'urgent', 'financial_aid', 1, 2, '2026-10-14 09:00:00'],
        [2, 'resident', 12, NULL, 'Relief Pack Distribution: Standard Family Food Packs Available', 'Emergency relief packs and hygiene kits are being distributed to displaced families at Hinaplanon NHS Gymnasium and Covered Court.', 'informational', 'resource_allocation', 1, 2, '2026-10-14 10:30:00'],
        [3, 'resident', 12, NULL, 'Upcoming Drill: Community Flash Flood & Evacuation Simulation', 'All residents across Hinaplanon puroks are invited to participate in the scheduled flood evacuation drills.', 'informational', 'preparedness', 1, 2, '2026-10-15 08:00:00'],
        [4, 'resident', 12, NULL, 'Weather Advisory: Mandulog River Water Level Elevated', 'PAGASA heavy rainfall warning active. Mandulog river level is elevated. Residents in low-lying riverbank zones must maintain vigilance.', 'warning', 'disaster_request', 1, 1, '2026-10-14 07:15:00'],
        [5, 'resident', 12, NULL, 'Financial Aid Distribution: Coastal & Riverbank Recovery Grant', 'Claim schedule for Coastal & Riverbank Recovery Grant (FAA-2026-HIN-002) is open for registered beneficiaries.', 'informational', 'financial_aid', 2, 2, '2026-10-15 11:00:00'],
        [6, 'resident', 12, NULL, 'Evacuation Shelter Notice: Hinaplanon NHS Gym Open', 'Hinaplanon National High School Gymnasium is currently open and providing hot meals, sleeping cots, and emergency sanitation for evacuees.', 'informational', 'evacuation', 1, 2, '2026-10-14 12:00:00'],

        // Responder notifications for Hinaplanon (target_barangay_id: 12)
        [7, 'responder', 12, NULL, 'Incident Alert: Heavy Rainfall & River Surge Warning', 'Mandulog River telemetry active. All 50 assigned purok responders are placed on heightened standby alert.', 'urgent', 'disaster_request', 1, 1, '2026-10-14 06:45:00'],
        [8, 'responder', 12, NULL, 'Field Deployment: Team Alpha Assigned to Riverine Corridors', 'Water rescue units dispatched to Purok Riverside 1 and Purok Sambag for pre-emptive evacuation assistance.', 'urgent', 'disaster_request', 1, 2, '2026-10-15 02:30:00'],
        [9, 'responder', 12, NULL, 'Resource Dispatch: Emergency Medical Kits & Life Vests Prepositioned', 'Hinaplanon Operations Desk has prepositioned trauma bags, life jackets, and dewatering pumps across covered centers.', 'informational', 'resource_allocation', 1, 1, '2026-10-14 08:30:00'],
        [10, 'responder', 12, NULL, 'Training Schedule: Community First Aid & Rescuer Refresher', 'Mandatory refresher training for all active barangay emergency responders on water rescue and trauma stabilization.', 'informational', 'preparedness', 2, 1, '2026-10-12 10:00:00'],

        // ICDRRMO notifications
        [11, 'icdrrmo', NULL, NULL, 'Hinaplanon Puroks Monitored: 10 Active Jurisdictions', 'All 10 Hinaplanon puroks report active GIS telemetry. AI heatmap reflects real-time susceptible zones.', 'informational', 'inventory', 1, 1, '2026-10-15 09:00:00'],
        [12, 'icdrrmo', NULL, NULL, 'Central & Barangay Inventory Synchronized: Complete Stock Parity', 'Logistics parity achieved: ICDRRMO Depot and Hinaplanon BDRRMC share full resource catalog availability.', 'informational', 'inventory', 1, 1, '2026-10-14 06:40:00'],

        // Barangay Head notifications
        [13, 'barangay_head', 12, NULL, 'Allocation Approved: Emergency Flood Relief Cash Assistance', 'ICDRRMO has approved PHP 1,500,000.00 Calamity Fund allocation for Hinaplanon flash flood response.', 'informational', 'financial_aid', 1, 1, '2026-10-14 08:10:00'],
        [14, 'barangay_head', 12, NULL, 'Resource Dispatch Confirmed: Logistics Parity Active', 'Local stockroom inventory synchronized with central depot. Ready for in-place dynamic distribution.', 'informational', 'resource_allocation', 1, 1, '2026-10-14 08:45:00']
    ];

    $notifStmt = $pdo->prepare("INSERT INTO `notifications` (
        `id`, `target_role`, `target_barangay_id`, `target_user_id`, `title`, `message`, 
        `alert_level`, `related_module`, `related_id`, `created_by`, `created_at`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($notifications as $n) {
        $notifStmt->execute($n);
    }

    // -------------------------------------------------------------------------
    // 10. RESPONDER FIELD UPDATES & SYSTEM LOGS
    // -------------------------------------------------------------------------
    echo "[10/10] Seeding Responder Field Updates & Audit Logs...\n";
    $pdo->exec("TRUNCATE TABLE `responder_field_updates`");
    $fuStmt = $pdo->prepare("INSERT INTO `responder_field_updates` (
        `responder_id`, `disaster_request_id`, `barangay_id`, `purok`, `operational_status`, 
        `condition_overview`, `casualties`, `injuries`, `rescued_individuals`, `resources_utilized`, `remarks`, `created_at`
    ) VALUES (?, ?, 12, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    for ($pId = 1; $pId <= 10; $pId++) {
        $pName = $puroks[$pId]['name'];
        $respUserId = 2 + (($pId - 1) * 5) + 1; // Pick the first responder of that purok
        $fuStmt->execute([
            $respUserId, $pId, $pName, 'On Site',
            "Active responder field monitoring conducted at {$pName}. Water levels safe, pathways passable.",
            0, 0, rand(5, 25), "Life Vests, First Aid Kit, Wellington Boots",
            "Continuous community monitoring maintained by assigned purok responder unit.",
            date('Y-m-d H:i:s', strtotime("2026-10-18 08:00:00 - {$pId} hours"))
        ]);
    }

    $pdo->exec("TRUNCATE TABLE `system_logs`");
    $sysLogs = [
        [1, 1, 'Engr. Armando Castillo', 'icdrrmo', 'SYSTEM_INIT', 'System', 'Master data seeding completed: 10 Hinaplanon puroks, 102 accounts, dense per-purok records.', '127.0.0.1', '2026-10-18 08:00:00'],
        [2, 2, 'Hon. Rodrigo Almonte', 'barangay_head', 'INVENTORY_SYNC', 'Inventory', 'Verified identical resources catalog between ICDRRMO and Barangay Hinaplanon.', '127.0.0.1', '2026-10-18 08:30:00'],
        [3, 2, 'Hon. Rodrigo Almonte', 'barangay_head', 'ALLOCATE_FINANCIAL_AID', 'Financial Aid', 'Initialized 5 comprehensive financial aid programs across all Hinaplanon puroks.', '127.0.0.1', '2026-10-18 09:00:00']
    ];
    $logStmt = $pdo->prepare("INSERT INTO `system_logs` (`id`, `user_id`, `user_name`, `role`, `action`, `module`, `details`, `ip_address`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($sysLogs as $sl) {
        $logStmt->execute($sl);
    }

    // Re-enable Foreign Key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "\n========================================================\n";
    echo "🎉 SUCCESS: All database tables seeded successfully!\n";
    echo "========================================================\n";
    echo "Summary of populated test data:\n";
    echo "  - Hinaplanon Puroks: " . count($puroks) . " (Strictly 10 Puroks in Hinaplanon only)\n";
    echo "  - Total Users: 102 (1 ICDRRMO, 1 Barangay Head, 50 Responders, 50 Residents)\n";
    echo "  - Passwords: All set to '12345678'\n";
    echo "  - Resident Accounts: 50 (Divided 5 per purok)\n";
    echo "  - Responder Accounts: 50 (Divided 5 per purok)\n";
    echo "  - Resource Catalog: " . count($allCatalogResources) . " items (Identical across ICDRRMO & Hinaplanon)\n";
    echo "  - Disaster Reports: " . ($reportId - 1) . " (>= 5 reports per purok)\n";
    echo "  - Resource Transactions: 50 (5 transactions per purok)\n";
    echo "  - Financial Aid Programs: " . count($allocations) . " (PHP 4.4M total budget)\n";
    echo "  - Beneficiary Distributions: " . ($distId - 1) . " (5 distributions per purok)\n";
    echo "  - Preparedness Events: " . ($eventId - 1) . " (5 events per purok with registered attendees)\n";
    echo "========================================================\n\n";

} catch (Throwable $e) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "\n[ERROR] Seeding failed:\n" . $e->getMessage() . "\n\n";
    exit(1);
}
