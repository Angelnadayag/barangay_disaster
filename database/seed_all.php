<?php
/**
 * Barangay Disaster System - Master Comprehensive Data Seeder
 * Populates complete, realistic, coherent, and abundant test data across all system modules.
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

$defaultPasswordHash = '$2y$10$05YsJXrRv.0Cs5LXV.vqU.ZO19JXh2XjI/CnnIzuAoVqmQML9eCV2'; // 'password'

try {
    echo "Starting database seeding...\n\n";

    // -------------------------------------------------------------------------
    // 1. PUROKS (Hinaplanon and Key City Puroks)
    // -------------------------------------------------------------------------
    echo "[1/11] Seeding Puroks & Purok Cluster Options...\n";
    
    // Clear and re-populate puroks
    $pdo->exec("TRUNCATE TABLE `puroks`");

    $hinaplanonPuroks = [
        ['name' => 'Purok Riverside 1', 'hazard' => 'Flash Flood, Mandulog River Overflow', 'risk' => 'Critical', 'hh' => 450, 'pop' => 2050, 'lat' => 8.246800, 'lng' => 124.258800],
        ['name' => 'Purok Riverside 2', 'hazard' => 'Flash Flood, River Inundation', 'risk' => 'Critical', 'hh' => 520, 'pop' => 2350, 'lat' => 8.248200, 'lng' => 124.260500],
        ['name' => 'Purok Sambag', 'hazard' => 'Flash Flood, Riverbank Erosion', 'risk' => 'Critical', 'hh' => 430, 'pop' => 1940, 'lat' => 8.249800, 'lng' => 124.262000],
        ['name' => 'Purok Baybay', 'hazard' => 'Coastal Storm Surge, Tidal Overflow', 'risk' => 'Critical', 'hh' => 480, 'pop' => 2150, 'lat' => 8.251500, 'lng' => 124.254200],
        ['name' => 'Purok San Miguel', 'hazard' => 'Urban Flood, Strong Winds', 'risk' => 'High', 'hh' => 680, 'pop' => 3010, 'lat' => 8.245500, 'lng' => 124.259800],
        ['name' => 'Purok Katipunan', 'hazard' => 'Flash Flood, Creek Overflow', 'risk' => 'High', 'hh' => 390, 'pop' => 1720, 'lat' => 8.249000, 'lng' => 124.258000],
        ['name' => 'Purok Orchids', 'hazard' => 'High Inundation Zone, Saturated Silt', 'risk' => 'High', 'hh' => 360, 'pop' => 1610, 'lat' => 8.242000, 'lng' => 124.260500],
        ['name' => 'Purok Ilaya', 'hazard' => 'River Swell, Swift Current', 'risk' => 'High', 'hh' => 400, 'pop' => 1800, 'lat' => 8.250500, 'lng' => 124.266200],
        ['name' => 'Purok Mahayahay', 'hazard' => 'Localized Ponding, Drainage Congestion', 'risk' => 'Moderate', 'hh' => 410, 'pop' => 1850, 'lat' => 8.244800, 'lng' => 124.261000],
        ['name' => 'Purok Sto. Niño', 'hazard' => 'Localized Ponding, Heavy Rainfall', 'risk' => 'Moderate', 'hh' => 510, 'pop' => 2280, 'lat' => 8.243500, 'lng' => 124.257500],
        ['name' => 'Purok Centro', 'hazard' => 'Urban Flood, High Density Congestion', 'risk' => 'Moderate', 'hh' => 620, 'pop' => 2780, 'lat' => 8.246200, 'lng' => 124.261800],
        ['name' => 'Purok Kalubihan', 'hazard' => 'Slope Failure, Torrential Runoff', 'risk' => 'Moderate', 'hh' => 310, 'pop' => 1390, 'lat' => 8.252000, 'lng' => 124.264500],
        ['name' => 'Purok Pag-asa', 'hazard' => 'Low Hazard Zone', 'risk' => 'Low', 'hh' => 350, 'pop' => 1540, 'lat' => 8.247200, 'lng' => 124.262500],
        ['name' => 'Purok Mabuhay', 'hazard' => 'Urban Drainage Overflow', 'risk' => 'Low', 'hh' => 320, 'pop' => 1420, 'lat' => 8.244000, 'lng' => 124.263800],
        ['name' => 'Purok Dahlia', 'hazard' => 'Low Risk Flatland', 'risk' => 'Low', 'hh' => 290, 'pop' => 1300, 'lat' => 8.243000, 'lng' => 124.266000],
        ['name' => 'Purok Pine Tree', 'hazard' => 'Low Hazard Zone', 'risk' => 'Low', 'hh' => 280, 'pop' => 1240, 'lat' => 8.254000, 'lng' => 124.267000]
    ];

    $purokStmt = $pdo->prepare("INSERT INTO `puroks` (`barangay_id`, `name`, `hazard_types`, `risk_level`, `households`, `population`, `coordinates_lat`, `coordinates_lng`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    foreach ($hinaplanonPuroks as $p) {
        $purokStmt->execute([12, $p['name'], $p['hazard'], $p['risk'], $p['hh'], $p['pop'], $p['lat'], $p['lng']]);
    }

    // Other barangay puroks for citywide map context
    $otherPuroks = [
        [38, 'Purok 1 Baybay', 'Coastal Storm Surge, Tidal Overflow', 'High', 600, 2700, 8.214800, 124.237200],
        [38, 'Purok 3 Highway', 'Traffic Hazard, Urban Flood', 'Moderate', 850, 3800, 8.216500, 124.239500],
        [38, 'Purok Seaside 2', 'Coastal Wave Runup', 'High', 510, 2300, 8.213800, 124.236500],
        [35, 'Purok Gumamela', 'Riverbank Erosion, Flash Flood', 'Critical', 490, 2200, 8.224200, 124.234000],
        [35, 'Purok Santan', 'Iligan River Overtopping', 'Critical', 530, 2400, 8.223000, 124.234800],
        [35, 'Purok Rosal', 'High Inundation Zone', 'High', 420, 1900, 8.223800, 124.235500],
        [23, 'Purok Dahlia', 'Low Risk Flatland', 'Low', 320, 1400, 8.229500, 124.254800],
        [23, 'Purok Orchid', 'Moderate Creek Ponding', 'Moderate', 410, 1820, 8.228800, 124.253200],
        [25, 'Purok Centro Commercial', 'Urban Drainage Overflow', 'Moderate', 550, 2500, 8.229200, 124.234500],
        [25, 'Purok Riverside Poblacion', 'Riverine Inundation', 'High', 380, 1750, 8.228200, 124.233500],
        [21, 'Purok Mandulog Bridge Area', 'Extreme Flash Flood, Debris Flow', 'Critical', 580, 2600, 8.244800, 124.305200],
        [21, 'Purok Upland Crossing', 'Slope Failure, Landslide', 'High', 430, 1920, 8.245500, 124.307500],
        [6, 'Purok Timoga Spring', 'Coastal Inundation, Flash Flood', 'High', 620, 2800, 8.187800, 124.171800],
        [6, 'Purok Mimbalot Waterfall', 'Rockfall, Torrential Overflow', 'High', 480, 2100, 8.188800, 124.173500],
        [42, 'Purok Upper Mandulog 1', 'River Swell, Swift Current', 'High', 540, 2420, 8.256200, 124.269200],
        [42, 'Purok Hillside', 'Localized Sloping Runoff', 'Moderate', 390, 1750, 8.255200, 124.267500]
    ];
    foreach ($otherPuroks as $p) {
        $purokStmt->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]]);
    }

    // Sync purok cluster options
    $pdo->exec("TRUNCATE TABLE `purok_cluster_options`");
    $clusterStmt = $pdo->prepare("INSERT INTO `purok_cluster_options` (`barangay_id`, `name`) VALUES (?, ?)");
    foreach ($hinaplanonPuroks as $p) {
        $clusterStmt->execute([12, $p['name']]);
    }
    foreach ($otherPuroks as $p) {
        $clusterStmt->execute([$p[0], $p[1]]);
    }

    // -------------------------------------------------------------------------
    // 2. USERS (ICDRRMO, Barangay Heads, Responders)
    // -------------------------------------------------------------------------
    echo "[2/11] Seeding System Users & Responders...\n";
    $usersData = [
        [1, 'icdrrmo_admin', $defaultPasswordHash, 'Engr.', 'Armando Castillo', 'Engr. Armando Castillo', 'armando.castillo@icdrrmo.iligan.gov.ph', '+63 917 100 2000', 'Male', 48, 'icdrrmo', 1, 'Operations Head / Director', 'Available', NULL, NULL, 'active'],
        [2, 'brgy_hinaplanon', $defaultPasswordHash, 'Hon.', 'Rodrigo Almonte', 'Hon. Rodrigo Almonte', 'captain.almonte@hinaplanon.iligan.gov.ph', '+63 917 234 5678', 'Male', 52, 'barangay_head', 13, 'Barangay Captain / BDRRMC Chairman', 'Available', 12, NULL, 'active'],
        [3, 'brgy_tomas_cabili', $defaultPasswordHash, 'Hon.', 'Maria Elena Santos', 'Hon. Maria Elena Santos', 'captain.santos@tomascabili.iligan.gov.ph', '+63 918 345 6789', 'Female', 45, 'barangay_head', 39, 'Barangay Captain / BDRRMC Chairman', 'Available', 38, NULL, 'active'],
        [4, 'responder_kurt', $defaultPasswordHash, 'Kurt', 'Christian Morales', 'Kurt Christian Morales', 'kurt.rescuer@iligan.gov.ph', '+63 929 111 2233', 'Male', 29, 'responder', 13, 'Team Alpha Rescue', 'Available', 12, 'Purok Riverside 1', 'active'],
        [5, 'responder_sarah', $defaultPasswordHash, 'Sarah', 'Jane Del Fierro', 'Sarah Jane Del Fierro', 'sarah.medic@iligan.gov.ph', '+63 929 333 4455', 'Female', 31, 'responder', 13, 'Emergency Medical Team', 'Available', 12, 'Purok San Miguel', 'active'],
        [6, 'responder_mark', $defaultPasswordHash, 'Mark', 'Anthony Reyes', 'Mark Anthony Reyes', 'mark.reyes@iligan.gov.ph', '+63 929 444 5566', 'Male', 33, 'responder', 13, 'Water Search & Rescue Lead', 'Available', 12, 'Purok Riverside 2', 'active'],
        [7, 'responder_elena', $defaultPasswordHash, 'Elena', 'Marie Gomez', 'Elena Marie Gomez', 'elena.gomez@iligan.gov.ph', '+63 929 555 6677', 'Female', 28, 'responder', 13, 'Disaster Triage Specialist', 'Available', 12, 'Purok Centro', 'active'],
        [8, 'brgy_tambacan', $defaultPasswordHash, 'Hon.', 'Danilo Cabahug', 'Hon. Danilo Cabahug', 'captain.cabahug@tambacan.iligan.gov.ph', '+63 919 456 7890', 'Male', 50, 'barangay_head', 36, 'Barangay Captain / BDRRMC Chairman', 'Available', 35, NULL, 'active']
    ];

    $uStmt = $pdo->prepare("INSERT INTO `users` (`id`, `username`, `password`, `first_name`, `last_name`, `full_name`, `email`, `phone`, `gender`, `age`, `role`, `agency_id`, `position`, `availability`, `barangay_id`, `purok`, `status`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) 
        ON DUPLICATE KEY UPDATE `username`=VALUES(`username`), `password`=VALUES(`password`), `first_name`=VALUES(`first_name`), `last_name`=VALUES(`last_name`), `full_name`=VALUES(`full_name`), `email`=VALUES(`email`), `phone`=VALUES(`phone`), `gender`=VALUES(`gender`), `age`=VALUES(`age`), `role`=VALUES(`role`), `agency_id`=VALUES(`agency_id`), `position`=VALUES(`position`), `availability`=VALUES(`availability`), `barangay_id`=VALUES(`barangay_id`), `purok`=VALUES(`purok`), `status`=VALUES(`status`)");
    foreach ($usersData as $u) {
        $uStmt->execute($u);
    }

    // -------------------------------------------------------------------------
    // 3. RESIDENTS (Hinaplanon Residents)
    // -------------------------------------------------------------------------
    echo "[3/11] Seeding 65 Realistic Hinaplanon Residents...\n";
    $pdo->exec("TRUNCATE TABLE `residents`");

    $residentNames = [
        ['Angel', 'Nadayag', 'Female', 25, 'Purok Mahayahay', '09701965951', 'angelnadayag', 'angelnadayag@gmail.com'],
        ['Carlo', 'Mendoza', 'Male', 34, 'Purok Riverside 1', '09358889901', 'carlo_mendoza', 'carlo.mendoza@gmail.com'],
        ['Lorna', 'Bautista', 'Female', 42, 'Purok San Miguel', '09367778812', 'lorna_bautista', 'lorna.bautista@yahoo.com'],
        ['Juan', 'Dela Cruz', 'Male', 48, 'Purok Riverside 1', '09171234501', 'juan_delacruz', 'juan.delacruz@gmail.com'],
        ['Maria Clara', 'Santos', 'Female', 38, 'Purok Riverside 2', '09171234502', 'mariaclara_santos', 'mclara.santos@gmail.com'],
        ['Jose Rizalino', 'Mercado', 'Male', 52, 'Purok Sambag', '09171234503', 'jose_mercado', 'j.mercado@gmail.com'],
        ['Corazon', 'Aquino-Tan', 'Female', 61, 'Purok Baybay', '09171234504', 'corazon_tan', 'cora.tan@yahoo.com'],
        ['Antonio', 'Luna', 'Male', 44, 'Purok Katipunan', '09171234505', 'antonio_luna', 'antonio.luna@gmail.com'],
        ['Teresa', 'Magbanua', 'Female', 29, 'Purok Orchids', '09171234506', 'teresa_magbanua', 'teresa.m@gmail.com'],
        ['Danilo', 'Villanueva', 'Male', 41, 'Purok Ilaya', '09171234507', 'danilo_villanueva', 'danilo.v@gmail.com'],
        ['Remedios', 'Ramos', 'Female', 55, 'Purok Sto. Niño', '09171234508', 'remedios_ramos', 'remedios.ramos@yahoo.com'],
        ['Rodrigo', 'Fernandez', 'Male', 36, 'Purok Centro', '09171234509', 'rodrigo_f', 'rodrigo.f@gmail.com'],
        ['Grace', 'Poe-Tan', 'Female', 33, 'Purok Kalubihan', '09171234510', 'grace_poetan', 'grace.tan@gmail.com'],
        ['Benjamin', 'Alcantara', 'Male', 59, 'Purok Pag-asa', '09171234511', 'benjamin_a', 'benjamin.alcantara@yahoo.com'],
        ['Carmen', 'Valderrama', 'Female', 47, 'Purok Mabuhay', '09171234512', 'carmen_v', 'carmen.v@gmail.com'],
        ['Alberto', 'Celdran', 'Male', 50, 'Purok Dahlia', '09171234513', 'alberto_c', 'alberto.celdran@gmail.com'],
        ['Filomena', 'Roxas', 'Female', 63, 'Purok Pine Tree', '09171234514', 'filomena_r', 'filomena.roxas@yahoo.com'],
        ['Emmanuel', 'Dy', 'Male', 31, 'Purok Riverside 1', '09171234515', 'emmanuel_dy', 'emmanuel.dy@gmail.com'],
        ['Rosario', 'Mercado', 'Female', 46, 'Purok Riverside 2', '09171234516', 'rosario_m', 'rosario.mercado@gmail.com'],
        ['Francisco', 'Pimentel', 'Male', 54, 'Purok Sambag', '09171234517', 'francisco_p', 'francisco.p@yahoo.com'],
        ['Elena', 'Santos', 'Female', 27, 'Purok Baybay', '09171234518', 'elena_s', 'elena.santos@gmail.com'],
        ['Manuel', 'Padilla', 'Male', 39, 'Purok San Miguel', '09171234519', 'manuel_p', 'manuel.padilla@gmail.com'],
        ['Rogelio', 'Soriano', 'Male', 43, 'Purok Katipunan', '09171234520', 'rogelio_s', 'rogelio.soriano@yahoo.com'],
        ['Lope', 'Belmonte', 'Male', 65, 'Purok Orchids', '09171234521', 'lope_b', 'lope.belmonte@gmail.com'],
        ['Daniel', 'Quibranza', 'Male', 49, 'Purok Ilaya', '09171234522', 'daniel_q', 'daniel.quibranza@gmail.com'],
        ['Oscar', 'Montemayor', 'Male', 57, 'Purok Mahayahay', '09171234523', 'oscar_m', 'oscar.montemayor@yahoo.com'],
        ['Sultan', 'Omar Disomimba', 'Male', 51, 'Purok Sto. Niño', '09171234524', 'omar_disomimba', 'omar.disomimba@gmail.com'],
        ['Fatima', 'Macapagal', 'Female', 32, 'Purok Centro', '09171234525', 'fatima_m', 'fatima.macapagal@gmail.com'],
        ['Guillermo', 'Yap', 'Male', 40, 'Purok Kalubihan', '09171234526', 'guillermo_y', 'guillermo.yap@yahoo.com'],
        ['Arthur', 'Villamor', 'Male', 48, 'Purok Pag-asa', '09171234527', 'arthur_v', 'arthur.villamor@gmail.com'],
        ['Danilo', 'Sotto', 'Male', 35, 'Purok Mabuhay', '09171234528', 'danilo_s', 'danilo.sotto@gmail.com'],
        ['Carmelo', 'Perez', 'Male', 53, 'Purok Dahlia', '09171234529', 'carmelo_p', 'carmelo.perez@yahoo.com'],
        ['Gabriel', 'Alonto', 'Male', 46, 'Purok Pine Tree', '09171234530', 'gabriel_a', 'gabriel.alonto@gmail.com'],
        ['Usman', 'Macapado', 'Male', 62, 'Purok Riverside 1', '09171234531', 'usman_m', 'usman.macapado@gmail.com'],
        ['Cristopher', 'Cruz', 'Male', 30, 'Purok Riverside 2', '09171234532', 'cristopher_c', 'cristopher.cruz@yahoo.com'],
        ['Eduardo', 'Ramos', 'Male', 44, 'Purok Sambag', '09171234533', 'eduardo_r', 'eduardo.ramos@gmail.com'],
        ['Teodoro', 'Maglangit', 'Male', 56, 'Purok Baybay', '09171234534', 'teodoro_m', 'teodoro.maglangit@gmail.com'],
        ['Alejandro', 'Gomez', 'Male', 37, 'Purok San Miguel', '09171234535', 'alejandro_g', 'alejandro.gomez@yahoo.com'],
        ['Bonifacio', 'Capistrano', 'Male', 60, 'Purok Katipunan', '09171234536', 'bonifacio_c', 'bonifacio.capistrano@gmail.com'],
        ['Datu Ali', 'Marohom', 'Male', 45, 'Purok Orchids', '09171234537', 'ali_marohom', 'ali.marohom@gmail.com'],
        ['Felipe', 'Tan', 'Male', 58, 'Purok Ilaya', '09171234538', 'felipe_t', 'felipe.tan@yahoo.com'],
        ['Bernardo', 'Silvosa', 'Male', 42, 'Purok Mahayahay', '09171234539', 'bernardo_s', 'bernardo.silvosa@gmail.com'],
        ['Jaime', 'Fernandez', 'Male', 38, 'Purok Sto. Niño', '09171234540', 'jaime_f', 'jaime.fernandez@gmail.com'],
        ['Ernesto', 'Sotto', 'Male', 52, 'Purok Centro', '09171234541', 'ernesto_s', 'ernesto.sotto@yahoo.com'],
        ['Nestor', 'Quijano', 'Male', 47, 'Purok Kalubihan', '09171234542', 'nestor_q', 'nestor.quijano@gmail.com'],
        ['Salvador', 'Mendoza', 'Male', 64, 'Purok Pag-asa', '09171234543', 'salvador_m', 'salvador.mendoza@gmail.com'],
        ['Roberto', 'Echavez', 'Male', 50, 'Purok Mabuhay', '09171234544', 'roberto_e', 'roberto.echavez@yahoo.com'],
        ['Arsenio', 'Balgos', 'Male', 41, 'Purok Dahlia', '09171234545', 'arsenio_b', 'arsenio.balgos@gmail.com'],
        ['Mario', 'Macarambon', 'Male', 36, 'Purok Pine Tree', '09171234546', 'mario_m', 'mario.macarambon@gmail.com'],
        ['Vicente', 'Badelles', 'Male', 55, 'Purok Riverside 1', '09171234547', 'vicente_b', 'vicente.badelles@yahoo.com'],
        ['Maria Teresa', 'Lim', 'Female', 49, 'Purok Riverside 2', '09171234548', 'mariateresa_l', 'teresa.lim@gmail.com'],
        ['Rolando', 'Cañete', 'Male', 58, 'Purok Sambag', '09171234549', 'rolando_c', 'rolando.canete@gmail.com'],
        ['Beatrice', 'Almonte', 'Female', 24, 'Purok Baybay', '09171234550', 'beatrice_a', 'beatrice.almonte@gmail.com'],
        ['Christian', 'Delos Reyes', 'Male', 28, 'Purok San Miguel', '09171234551', 'christian_dr', 'christian.dr@yahoo.com'],
        ['Divina', 'Pastrano', 'Female', 39, 'Purok Katipunan', '09171234552', 'divina_p', 'divina.pastrano@gmail.com'],
        ['Esperanza', 'Salcedo', 'Female', 66, 'Purok Orchids', '09171234553', 'esperanza_s', 'esperanza.salcedo@gmail.com'],
        ['Ferdinand', 'Castillo', 'Male', 37, 'Purok Ilaya', '09171234554', 'ferdinand_c', 'ferdinand.castillo@yahoo.com'],
        ['Gemma', 'Torralba', 'Female', 43, 'Purok Mahayahay', '09171234555', 'gemma_t', 'gemma.torralba@gmail.com'],
        ['Hernan', 'Dela Torre', 'Male', 51, 'Purok Sto. Niño', '09171234556', 'hernan_dt', 'hernan.delatorre@gmail.com'],
        ['Imelda', 'Valdez', 'Female', 58, 'Purok Centro', '09171234557', 'imelda_v', 'imelda.valdez@yahoo.com'],
        ['Jocelyn', 'Suarez', 'Female', 31, 'Purok Kalubihan', '09171234558', 'jocelyn_s', 'jocelyn.suarez@gmail.com'],
        ['Karlo', 'Magno', 'Male', 26, 'Purok Pag-asa', '09171234559', 'karlo_m', 'karlo.magno@gmail.com'],
        ['Leila', 'Benitez', 'Female', 35, 'Purok Mabuhay', '09171234560', 'leila_b', 'leila.benitez@yahoo.com'],
        ['Melchor', 'Panganiban', 'Male', 46, 'Purok Dahlia', '09171234561', 'melchor_p', 'melchor.panganiban@gmail.com'],
        ['Nenita', 'Corpuz', 'Female', 62, 'Purok Pine Tree', '09171234562', 'nenita_c', 'nenita.corpuz@gmail.com']
    ];

    $resStmt = $pdo->prepare("INSERT INTO `residents` (`id`, `username`, `password`, `first_name`, `last_name`, `full_name`, `email`, `phone`, `gender`, `age`, `barangay_id`, `purok`, `status`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 12, ?, ?, NOW())");
    $resId = 1;
    foreach ($residentNames as $r) {
        $status = ($resId == 63) ? 'pending' : (($resId == 64) ? 'inactive' : 'active');
        $fullName = $r[0] . ' ' . $r[1];
        $resStmt->execute([$resId, $r[6], $defaultPasswordHash, $r[0], $r[1], $fullName, $r[7], $r[5], $r[2], $r[3], $r[4], $status]);
        $resId++;
    }

    // -------------------------------------------------------------------------
    // 4. EVACUATION CENTERS & RESOURCES
    // -------------------------------------------------------------------------
    echo "[4/11] Seeding Evacuation Shelters & Prepositioned Resources...\n";
    $pdo->exec("TRUNCATE TABLE `evacuation_areas`");
    $evacAreas = [
        [1, 'Hinaplanon National High School Gymnasium', 12, 'National Highway, Brgy. Hinaplanon, Iligan City', 'School Gym', 1200, 280, 350, 'Open / Active', 'Accessible (All Vehicles)', 1, 1, 1, 'Mr. Alberto Tan', '+63 917 444 5566', 8.246800, 124.261500],
        [2, 'Hinaplanon Barangay Multi-Purpose Covered Court', 12, 'Purok San Miguel, Hinaplanon, Iligan City', 'Barangay Multi-Purpose Hall', 800, 180, 120, 'Open / Active', 'Accessible (All Vehicles)', 1, 1, 1, 'Barangay Secretary Reyes', '+63 917 555 6677', 8.245800, 124.260000],
        [3, 'Hinaplanon Central Elementary School Building A', 12, 'Purok Centro, Hinaplanon, Iligan City', 'School Gym', 950, 210, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Principal Marissa Cruz', '+63 917 666 7788', 8.246300, 124.261900],
        [4, 'Purok San Miguel Community Chapel & Hall', 12, 'Purok San Miguel Interior, Hinaplanon, Iligan City', 'Church / Chapel', 400, 90, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Rev. Pastor Danilo Cruz', '+63 917 777 8899', 8.245200, 124.259200],
        [5, 'St. Michael Parish Pastoral Evacuation Hall', 12, 'Purok Pag-asa Boundary, Hinaplanon, Iligan City', 'Civic Center', 650, 150, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Sr. Maria Lopez', '+63 917 888 9900', 8.247500, 124.263000],
        [6, 'Hinaplanon Upland Covered Sports Pavilion', 12, 'Purok Pine Tree, Hinaplanon, Iligan City', 'Designated Open Ground', 1100, 250, 0, 'Standby', 'High-Clearance / 4x4 Only', 1, 1, 0, 'Capt. Rodrigo Almonte', '+63 917 234 5678', 8.254200, 124.267200],
        [7, 'Tomas Cabili Central School Evacuation Building', 38, 'Brgy. Tomas Cabili proper, Iligan City', 'School Gym', 1500, 350, 420, 'Open / Active', 'Accessible (All Vehicles)', 1, 1, 1, 'Mrs. Remedios Perez', '+63 918 666 7788', 8.216200, 124.239200],
        [8, 'Tambacan Coastal Civic Evacuation Center', 35, 'Purok Gumamela, Brgy. Tambacan, Iligan City', 'Civic Center', 950, 210, 0, 'Standby', 'High-Clearance / 4x4 Only', 1, 1, 0, 'Officer Victor Cruz', '+63 919 777 8899', 8.224000, 124.235200],
        [9, 'Iligan City National High School Complex', 23, 'Gen. Wood St., Brgy. Pala-o, Iligan City', 'School Gym', 2200, 500, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Dr. Arnaldo Gomez', '+63 920 888 9911', 8.228500, 124.253500],
        [10, 'Mandulog Riverine Community Refuge Center', 21, 'Purok Crossing, Brgy. Mandulog, Iligan City', 'Civic Center', 1100, 240, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Officer Datu Usman', '+63 917 888 3344', 8.244800, 124.306800],
        [11, 'Buru-un Central Elementary Covered Court', 6, 'National Highway, Brgy. Buru-un, Iligan City', 'Barangay Multi-Purpose Hall', 850, 190, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Captain Mendoza', '+63 917 777 2211', 8.188500, 124.172500],
        [12, 'Poblacion Multi-Purpose Civic Sanctuary', 25, 'Cabili Avenue, Brgy. Poblacion, Iligan City', 'Civic Center', 1600, 360, 0, 'Standby', 'Accessible (All Vehicles)', 1, 1, 1, 'Admin Villamor', '+63 917 555 1199', 8.228900, 124.234800]
    ];

    $evacStmt = $pdo->prepare("INSERT INTO `evacuation_areas` (`id`, `name`, `barangay_id`, `location_address`, `center_type`, `capacity_individuals`, `capacity_families`, `current_evacuees_count`, `status`, `accessibility`, `has_potable_water`, `has_electricity`, `has_medical_station`, `contact_officer`, `contact_number`, `coordinates_lat`, `coordinates_lng`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($evacAreas as $ea) {
        $evacStmt->execute($ea);
    }

    // Preposition resources across evacuation centers
    $pdo->exec("TRUNCATE TABLE `evacuation_center_resources`");
    $ecrStmt = $pdo->prepare("INSERT INTO `evacuation_center_resources` (`evacuation_area_id`, `resource_id`, `resource_name`, `category`, `unit`, `quantity`, `status`) VALUES (?, ?, ?, ?, ?, ?, 'Available')");
    $sampleECR = [
        [1, 1, 'Standard Family Food Pack (3-day ration)', 'Relief Goods', 'packs', 450],
        [1, 3, 'Bottled Mineral Drinking Water (6L Dispenser Bottle)', 'Relief Goods', 'bottles', 600],
        [1, 4, 'Family Hygiene & Sanitation Kit', 'Shelter & Sanitation', 'kits', 250],
        [1, 10, 'Family Evacuation Modular Privacy Tent', 'Shelter & Sanitation', 'units', 110],
        [1, 5, 'First-Aid Kits (Disaster Multi-Trauma Response Bag)', 'Medical Supplies', 'kits', 20],
        [1, 8, 'Standard Personal Flotation Device (SOLAS Type-III PFD)', 'Rescue Equipment', 'pieces', 60],
        [1, 113, 'Heavy-Duty Silent Inverter Generators', 'Emergency Supplies', 'units', 3],
        [2, 1, 'Standard Family Food Pack (3-day ration)', 'Relief Goods', 'packs', 280],
        [2, 3, 'Bottled Mineral Drinking Water (6L Dispenser Bottle)', 'Relief Goods', 'bottles', 350],
        [2, 4, 'Family Hygiene & Sanitation Kit', 'Shelter & Sanitation', 'kits', 150],
        [2, 10, 'Family Evacuation Modular Privacy Tent', 'Shelter & Sanitation', 'units', 60],
        [2, 5, 'First-Aid Kits (Disaster Multi-Trauma Response Bag)', 'Medical Supplies', 'kits', 12],
        [2, 114, 'Rechargeable High-Lumen LED Work Lights', 'Emergency Supplies', 'sets', 6],
        [3, 1, 'Standard Family Food Pack (3-day ration)', 'Relief Goods', 'packs', 200],
        [3, 3, 'Bottled Mineral Drinking Water (6L Dispenser Bottle)', 'Relief Goods', 'bottles', 300],
        [3, 10, 'Family Evacuation Modular Privacy Tent', 'Shelter & Sanitation', 'units', 45],
        [7, 1, 'Standard Family Food Pack (3-day ration)', 'Relief Goods', 'packs', 350],
        [7, 3, 'Bottled Mineral Drinking Water (6L Dispenser Bottle)', 'Relief Goods', 'bottles', 500],
        [7, 10, 'Family Evacuation Modular Privacy Tent', 'Shelter & Sanitation', 'units', 95]
    ];
    foreach ($sampleECR as $ecr) {
        $ecrStmt->execute($ecr);
    }

    // -------------------------------------------------------------------------
    // 5. DISASTER REPORTS & INCIDENTS
    // -------------------------------------------------------------------------
    echo "[5/11] Seeding Disaster Reports & Active Incidents...\n";
    $pdo->exec("TRUNCATE TABLE `disaster_reports`");
    $disasterReports = [
        [1, 'REQ-2026-001', 12, 'Purok Riverside 1', 8.246800, 124.258800, 'Riverside Corridor Lowland', 'Flash Flood', 'Critical', 'Immediate', 'both', 350, 1540, 220, 0, 4, 0, 'Requesting urgent dispatch of 350 Family Food Packs, 200 Hygiene Kits, 2 Inflatable Rescue Boats with operators, and 60 Life Vests due to rising Mandulog River overflow.', 'Mandulog river level reached 4.2 meters (Red Warning Stage). Water has entered low-lying residential clusters in Purok Riverside 1. 220 families evacuated to Hinaplanon NHS Gym.', NULL, 'Ongoing', 2, '2026-10-14 06:30:00', NULL],
        [2, 'REQ-2026-002', 38, 'Purok 1 Baybay', 8.214800, 124.237200, 'Coastal Seawall Sector', 'Storm Surge', 'High', 'High', 'resources', 180, 790, 95, 0, 1, 0, 'Requesting 180 Family Food Packs, 100 Hygiene Kits, 50 Modular Tents, and First Aid replenishment for coastal evacuees.', 'High tide coupled with localized squall caused 1.8-meter sea swells battering coastal households along Purok 1 Baybay.', NULL, 'Ongoing', 3, '2026-10-14 08:15:00', NULL],
        [3, 'REQ-2026-003', 12, 'Purok San Miguel', 8.245500, 124.259800, 'Lower Commercial & Residential Basin', 'Flood', 'Moderate', 'Medium', 'both', 90, 395, 25, 0, 0, 0, 'Pre-emptive prepositioning of 90 Food Packs and 2 Portable Water Pumps in case creek drainage overflows.', 'Heavy continuous rain recorded over the hinterlands. Creek drainage in Purok San Miguel experiencing sluggish runoff.', NULL, 'Ongoing', 2, '2026-10-15 10:20:00', NULL],
        [4, 'REQ-2026-004', 12, 'Purok Baybay', 8.251500, 124.254200, 'Hinaplanon Coastal Frontage', 'Storm Surge', 'High', 'High', 'both', 140, 620, 65, 0, 1, 0, 'Requesting 140 Family Food Packs, 70 Modular Tents, and Emergency Cash Assistance for displaced shoreline fisherfolk families.', 'Strong coastal wave action breached the secondary breakwater, affecting beachside residential structures.', NULL, 'Ongoing', 2, '2026-10-15 14:45:00', NULL],
        [5, 'REQ-2026-005', 12, 'Purok Sambag', 8.249800, 124.262000, 'Mandulog River Bend Basin', 'Flash Flood', 'Critical', 'Immediate', 'both', 210, 950, 130, 0, 2, 0, 'Urgent rescue support needed: 210 Food Packs, 120 Hygiene Kits, 1 Rescue Boat, and Financial Calamity Aid.', 'Mandulog tributary overflowed during midnight flash flood. Mud silt and swift currents trapped households in the inner loop.', NULL, 'Ongoing', 2, '2026-10-16 02:15:00', NULL],
        [6, 'REQ-2026-006', 12, 'Purok Katipunan', 8.249000, 124.258000, 'Katipunan Creek Embankment', 'Flood', 'Moderate', 'Medium', 'resources', 75, 330, 15, 0, 0, 0, '75 Family Food Packs and 40 Hygiene Kits requested for pre-emptive evacuees.', 'Localized creek overflow due to clogged culverts during torrential downpour.', 'Culverts declogged by barangay maintenance team. Floodwater subsided completely. Relief packs distributed.', 'Completed', 2, '2026-10-10 09:00:00', '2026-10-11 17:00:00'],
        [7, 'REQ-2026-007', 12, 'Purok Centro', 8.246200, 124.261800, 'Purok Centro Public Market Vicinity', 'Fire', 'High', 'Immediate', 'both', 45, 190, 45, 0, 3, 0, 'Immediate emergency shelter, 45 modular tents, food packs, and emergency cash assistance for burned residential block.', 'Residential fire broke out due to faulty electrical connection in dense housing cluster.', 'Fire extinguished by BFP. All 45 displaced families received modular tents and emergency aid.', 'Completed', 2, '2026-10-08 13:20:00', '2026-10-09 18:00:00'],
        [8, 'REQ-2026-008', 35, 'Purok Gumamela', 8.224200, 124.234000, 'Iligan Riverbank Embankment', 'Flood', 'Critical', 'High', 'resources', 110, 490, 60, 0, 0, 0, 'Requesting 110 Food Packs, 50 Hygiene Kits, and 200 Sandbags for riverbank erosion defense.', 'Iligan River water level rising rapidly. Riverbank soil erosion threatening 60 residential houses.', NULL, 'Ongoing', 8, '2026-10-16 11:30:00', NULL],
        [9, 'REQ-2026-009', 21, 'Purok Mandulog Bridge Area', 8.244800, 124.305200, 'Mandulog Highway Slopes', 'Landslide', 'Critical', 'Immediate', 'resources', 160, 710, 90, 0, 5, 0, 'Heavy rescue equipment, chainsaws, 160 food packs, trauma first aid kits, and heavy machinery support.', 'Rain-induced landslide covered 2 lanes of highway and pushed mud into 30 residential homes.', NULL, 'Ongoing', 1, '2026-10-16 15:40:00', NULL],
        [10, 'REQ-2026-010', 12, 'Purok Orchids', 8.242000, 124.260500, 'Lowland Wetland Fringe', 'Flash Flood', 'High', 'High', 'both', 85, 380, 35, 0, 0, 0, '85 Food Packs, 50 Hygiene Kits, and Cash Assistance for displaced wetland families.', 'Flash flood from swollen upland creek inundated 35 homes up to knee-deep.', 'Floodwaters drained into Mandulog outlet. Families safely returned home after cleaning operations.', 'Completed', 2, '2026-10-12 08:30:00', '2026-10-13 16:30:00']
    ];

    $repStmt = $pdo->prepare("INSERT INTO `disaster_reports` (`id`, `tracking_code`, `barangay_id`, `purok_name`, `coordinates_lat`, `coordinates_lng`, `incident_location`, `disaster_type`, `severity`, `urgency`, `aid_support`, `affected_families`, `affected_individuals`, `displaced_families`, `casualties_count`, `injuries_count`, `missing_count`, `requested_assistance`, `situation_overview`, `resolution_notes`, `status`, `submitted_by`, `created_at`, `completed_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($disasterReports as $dr) {
        $repStmt->execute($dr);
    }

    // -------------------------------------------------------------------------
    // 6. DSS RECOMMENDATIONS & RECOMMENDED ITEMS
    // -------------------------------------------------------------------------
    echo "[6/11] Seeding AI Recommendations & Decision Tree Logs...\n";
    $pdo->exec("TRUNCATE TABLE `recommendations`");
    $pdo->exec("TRUNCATE TABLE `recommended_items`");

    $recs = [
        [1, 'REC-2026-001', 1, 'Critical', 'both', 93.80, 
         'Root [Disaster: Flood] -> Branch [Severity: Critical (Water Depth > 1.5m)] -> Branch [Population: 350 Families] -> Branch [Urgency: Immediate] -> Stock Feasibility Check [Central Depot: Food Packs 1200 >= 350; Rescue Boats 12 >= 2; PFDs 450 >= 60; Hygiene Kits 850 >= 200] -> Outcome: Tier-1 Flood Assistance Package Activated.',
         'Model evaluated disaster conditions: River overflow in Purok Riverside 1 (Mandulog watershed), critical severity with 350 affected families (220 displaced). Recommends 1:1 family food pack ration for immediate 3-day buffer, 2 water rescue craft, 60 life vests, 200 hygiene kits, and emergency financial assistance.',
         1750000.00, 5000.00, 350, 'LGU Calamity Fund (QRF)', 1750000.00, 'ai_recommended', 'Approved', 1, '2026-10-14 07:00:00', 'Approved by ICDRRMO Operations Head. Prioritized convoy clearance to Hinaplanon.', '2026-10-14 06:45:00'],
        
        [2, 'REC-2026-002', 2, 'High', 'resources', 89.20,
         'Root [Disaster: Storm Surge] -> Branch [Severity: High (Coastal Inundation)] -> Branch [Population: 180 Families] -> Branch [Urgency: High] -> Stock Feasibility Check [Food Packs: 180; Hygiene Kits: 100; Modular Tents: 50] -> Outcome: Tier-2 Coastal Displacement Package.',
         'Based on coastal surge impact displacing 95 families into Tomas Cabili Central School, the model recommends 180 food packs, 100 hygiene kits, and 50 modular privacy tents to safeguard evacuees.',
         0.00, 0.00, 0, 'LGU Calamity Fund (QRF)', 0.00, 'ai_recommended', 'Approved', 1, '2026-10-14 09:00:00', 'Authorized dispatch from Depot Bay A & Bay B.', '2026-10-14 08:30:00'],

        [3, 'REC-2026-003', 3, 'Moderate', 'both', 86.50,
         'Root [Disaster: Flood] -> Branch [Severity: Moderate (Creek Ponding)] -> Branch [Population: 90 Families] -> Branch [Urgency: Medium] -> Stock Feasibility Check [Food Packs: 90; Water Pumps: 2] -> Outcome: Precautionary Prepositioning Package.',
         'Precautionary allocation for Purok San Miguel lowlands to mitigate potential overflow. Includes 90 family food packs and 2 high-capacity portable drainage pumps.',
         450000.00, 5000.00, 90, 'LGU Calamity Fund (QRF)', 450000.00, 'ai_recommended', 'Approved', 1, '2026-10-15 11:00:00', 'Approved for standby deployment at Barangay Covered Court.', '2026-10-15 10:40:00'],

        [4, 'REC-2026-004', 4, 'High', 'both', 91.00,
         'Root [Disaster: Storm Surge] -> Branch [Severity: High (Seawall Breach)] -> Branch [Population: 140 Families] -> Branch [Urgency: High] -> Stock Feasibility Check [Food Packs: 140; Modular Tents: 70; Cash Grant: Verified] -> Outcome: Coastal Relief & Livelihood Protection Tier.',
         'Purok Baybay coastal surge impact requires 140 family food packs, 70 modular tents, and emergency cash assistance of PHP 4,000 per family for urgent shelter repairs.',
         560000.00, 4000.00, 140, 'DSWD Assistance to Individuals in Crisis (AICS)', 560000.00, 'ai_recommended', 'Pending Review', NULL, NULL, NULL, '2026-10-15 15:00:00'],

        [5, 'REC-2026-005', 5, 'Critical', 'both', 94.50,
         'Root [Disaster: Flash Flood] -> Branch [Severity: Critical (Swift Inundation)] -> Branch [Population: 210 Families] -> Branch [Urgency: Immediate] -> Stock Feasibility Check [Food Packs: 210; Rescue Boats: 1; PFDs: 40; Hygiene Kits: 120] -> Outcome: Emergency Flash Flood Tier-1 Response.',
         'Urgent recommendation for Purok Sambag river bend flash flood: 210 food packs, 120 hygiene kits, 1 inflatable rescue craft with operator crew, 40 life vests, and cash assistance of PHP 3,000 per family.',
         630000.00, 3000.00, 210, 'LGU Calamity Fund (QRF)', 630000.00, 'ai_recommended', 'Pending Review', NULL, NULL, NULL, '2026-10-16 02:45:00']
    ];

    $recStmt = $pdo->prepare("INSERT INTO `recommendations` (`id`, `recommendation_code`, `disaster_request_id`, `priority`, `modality`, `confidence_score`, `decision_path`, `recommendation_reason`, `financial_aid_recommended_budget`, `financial_aid_per_family`, `financial_aid_target_families`, `financial_aid_source`, `financial_aid_approved_budget`, `financial_aid_added_type`, `status`, `reviewed_by`, `reviewed_at`, `review_remarks`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($recs as $r) {
        $recStmt->execute($r);
    }

    $recItems = [
        [1, 1, 1, 350, 350, 350, 'Allocated'],
        [2, 1, 4, 200, 200, 200, 'Allocated'],
        [3, 1, 7, 2, 2, 2, 'Allocated'],
        [4, 1, 8, 60, 60, 60, 'Allocated'],
        [5, 1, 5, 15, 15, 15, 'Allocated'],
        [6, 2, 1, 180, 180, 180, 'Allocated'],
        [7, 2, 4, 100, 100, 100, 'Allocated'],
        [8, 2, 10, 50, 50, 50, 'Allocated'],
        [9, 3, 1, 90, 90, 90, 'Allocated'],
        [10, 3, 128, 2, 2, 2, 'Allocated'],
        [11, 4, 1, 140, NULL, 0, 'Pending'],
        [12, 4, 10, 70, NULL, 0, 'Pending'],
        [13, 5, 1, 210, NULL, 0, 'Pending'],
        [14, 5, 4, 120, NULL, 0, 'Pending'],
        [15, 5, 7, 1, NULL, 0, 'Pending'],
        [16, 5, 8, 40, NULL, 0, 'Pending']
    ];
    $itemStmt = $pdo->prepare("INSERT INTO `recommended_items` (`id`, `recommendation_id`, `resource_id`, `recommended_quantity`, `approved_quantity`, `allocated_quantity`, `status`, `added_type`) VALUES (?, ?, ?, ?, ?, ?, ?, 'ai_recommended')");
    foreach ($recItems as $ri) {
        $itemStmt->execute($ri);
    }

    // -------------------------------------------------------------------------
    // 7. FINANCIAL AID ALLOCATIONS, DISTRIBUTIONS & AUDITS
    // -------------------------------------------------------------------------
    echo "[7/11] Seeding Financial Aid Allocations, Beneficiary Distributions & Audit Trails...\n";
    $pdo->exec("TRUNCATE TABLE `financial_aid_allocations`");
    $pdo->exec("TRUNCATE TABLE `financial_aid_distributions`");
    $pdo->exec("TRUNCATE TABLE `financial_aid_audits`");

    $allocations = [
        [1, 'FAA-2026-HIN-001', 'Emergency Flood Relief Cash Assistance', 'Flash Flood', 12, 'Purok Riverside 1', 1500000.00, 5000.00, 1050000.00, 450000.00, 'LGU Calamity Fund (QRF)', 300, 'Active', 'ai_recommended', 'Emergency cash assistance approved under Executive Resolution for Mandulog River flash flood affected households.', 1, '2026-10-14 08:00:00'],
        [2, 'FAA-2026-HIN-002', 'Purok Baybay Coastal Surge Livelihood Grant', 'Storm Surge', 12, 'Purok Baybay', 800000.00, 4000.00, 520000.00, 280000.00, 'DSWD Assistance to Individuals in Crisis (AICS)', 200, 'Active', 'ai_recommended', 'Special livelihood support grant for coastal artisanal fisherfolk whose gear was damaged by storm surge swells.', 1, '2026-10-14 10:30:00'],
        [3, 'FAA-2026-HIN-003', 'Purok Sambag Flash Flood Immediate Recovery Aid', 'Flash Flood', 12, 'Purok Sambag', 600000.00, 3000.00, 600000.00, 0.00, 'Barangay Disaster Quick Response Fund', 200, 'Completed', 'ai_recommended', 'Immediate 100% completed payout for 200 low-income households in Purok Sambag.', 2, '2026-10-10 09:00:00'],
        [4, 'FAA-2026-HIN-004', 'Hinaplanon General Vulnerable Household & Senior Calamity Aid', 'General Preparedness', 12, 'All Puroks', 1000000.00, 5000.00, 350000.00, 650000.00, 'City Mayor Special Relief Assistance', 200, 'Active', 'added_by_admin', 'Targeted financial assistance program focusing on PWDs, single parents, and senior citizen headed households across all Hinaplanon puroks.', 2, '2026-10-12 11:00:00'],
        [5, 'FAA-2026-HIN-005', 'Purok San Miguel Monsoon Drainage Assistance Fund', 'Flood', 12, 'Purok San Miguel', 450000.00, 3000.00, 0.00, 450000.00, 'LGU Calamity Fund (QRF)', 150, 'Active', 'ai_recommended', 'Standby financial assistance allocation scheduled for disbursement following community verification.', 1, '2026-10-15 11:30:00']
    ];

    $allocStmt = $pdo->prepare("INSERT INTO `financial_aid_allocations` (`id`, `allocation_code`, `program_name`, `disaster_type`, `barangay_id`, `target_purok`, `total_budget`, `amount_per_beneficiary`, `disbursed_budget`, `remaining_budget`, `funding_source`, `target_beneficiaries`, `status`, `added_type`, `notes`, `allocated_by`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($allocations as $a) {
        $allocStmt->execute($a);
    }

    // Seed 50+ distributions linked to our 65 residents
    $distStmt = $pdo->prepare("INSERT INTO `financial_aid_distributions` (`allocation_id`, `barangay_id`, `resident_id`, `beneficiary_name`, `purok`, `phone`, `amount`, `claim_status`, `claim_date`, `reference_no`, `sms_status`, `disbursed_by`, `remarks`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $auditStmt = $pdo->prepare("INSERT INTO `financial_aid_audits` (`allocation_id`, `distribution_id`, `barangay_id`, `action`, `details`, `amount`, `actor_id`, `actor_name`, `actor_role`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Log allocation creations in audit
    foreach ($allocations as $a) {
        $auditStmt->execute([$a[0], NULL, 12, 'Allocated', "Created financial aid program '{$a[2]}' with total budget of PHP " . number_format($a[6], 2), $a[6], $a[15], ($a[15] == 1 ? 'Engr. Armando Castillo' : 'Hon. Rodrigo Almonte'), ($a[15] == 1 ? 'icdrrmo' : 'barangay_head'), $a[16]]);
    }

    $distId = 1;
    // Distribution for Allocation 1 (FAA-2026-HIN-001) - 25 beneficiaries
    for ($i = 1; $i <= 25; $i++) {
        $r = $residentNames[$i - 1];
        $isClaimed = ($i <= 18);
        $claimStatus = $isClaimed ? 'Claimed' : 'Pending';
        $claimDate = $isClaimed ? date('Y-m-d H:i:s', strtotime("2026-10-14 10:00:00 + " . ($i * 30) . " minutes")) : NULL;
        $refNo = $isClaimed ? 'REF-2026-1014-' . str_pad($distId, 4, '0', STR_PAD_LEFT) : NULL;
        $remarks = $isClaimed ? 'Disbursed at Hinaplanon Covered Court. Verified with PhilSys National ID.' : 'Scheduled for batch 2 payout.';
        $disbursedBy = $isClaimed ? 2 : NULL;

        $distStmt->execute([1, 12, $i, $r[0] . ' ' . $r[1], $r[4], $r[5], 5000.00, $claimStatus, $claimDate, $refNo, 'sent', $disbursedBy, $remarks, '2026-10-14 08:30:00']);
        
        if ($isClaimed) {
            $auditStmt->execute([1, $distId, 12, 'Disbursed', "Released PHP 5,000.00 to {$r[0]} {$r[1]} ({$r[4]}) with Ref #{$refNo}", 5000.00, 2, 'Hon. Rodrigo Almonte', 'barangay_head', $claimDate]);
        }
        $distId++;
    }

    // Distribution for Allocation 2 (FAA-2026-HIN-002) - 15 beneficiaries
    for ($i = 26; $i <= 40; $i++) {
        $r = $residentNames[$i - 1];
        $isClaimed = ($i <= 35);
        $claimStatus = $isClaimed ? 'Claimed' : 'Pending';
        $claimDate = $isClaimed ? date('Y-m-d H:i:s', strtotime("2026-10-15 09:30:00 + " . ($i * 20) . " minutes")) : NULL;
        $refNo = $isClaimed ? 'REF-2026-1015-' . str_pad($distId, 4, '0', STR_PAD_LEFT) : NULL;
        $remarks = $isClaimed ? 'Claimed by family head. Signed disbursement voucher.' : 'Awaiting payout batch notification.';
        $disbursedBy = $isClaimed ? 2 : NULL;

        $distStmt->execute([2, 12, $i, $r[0] . ' ' . $r[1], $r[4], $r[5], 4000.00, $claimStatus, $claimDate, $refNo, 'sent', $disbursedBy, $remarks, '2026-10-14 11:00:00']);

        if ($isClaimed) {
            $auditStmt->execute([2, $distId, 12, 'Disbursed', "Released PHP 4,000.00 coastal aid to {$r[0]} {$r[1]} ({$r[4]}) Ref #{$refNo}", 4000.00, 2, 'Hon. Rodrigo Almonte', 'barangay_head', $claimDate]);
        }
        $distId++;
    }

    // Distribution for Allocation 4 (FAA-2026-HIN-004) - 15 beneficiaries
    for ($i = 41; $i <= 55; $i++) {
        $r = $residentNames[$i - 1];
        $isClaimed = ($i <= 48);
        $claimStatus = $isClaimed ? 'Claimed' : 'Pending';
        $claimDate = $isClaimed ? date('Y-m-d H:i:s', strtotime("2026-10-13 13:00:00 + " . ($i * 25) . " minutes")) : NULL;
        $refNo = $isClaimed ? 'REF-2026-1013-' . str_pad($distId, 4, '0', STR_PAD_LEFT) : NULL;
        $remarks = $isClaimed ? 'Special payout released for vulnerable sector household.' : 'Verification documents pending.';
        $disbursedBy = $isClaimed ? 2 : NULL;

        $distStmt->execute([4, 12, $i, $r[0] . ' ' . $r[1], $r[4], $r[5], 5000.00, $claimStatus, $claimDate, $refNo, 'sent', $disbursedBy, $remarks, '2026-10-12 12:00:00']);

        if ($isClaimed) {
            $auditStmt->execute([4, $distId, 12, 'Disbursed', "Disbursed PHP 5,000.00 vulnerable aid to {$r[0]} {$r[1]} Ref #{$refNo}", 5000.00, 2, 'Hon. Rodrigo Almonte', 'barangay_head', $claimDate]);
        }
        $distId++;
    }

    // -------------------------------------------------------------------------
    // 8. EVENTS & PREPAREDNESS ACTIVITIES
    // -------------------------------------------------------------------------
    echo "[8/11] Seeding Realistic Preparedness Events & Drills...\n";
    $pdo->exec("TRUNCATE TABLE `events`");
    $pdo->exec("TRUNCATE TABLE `event_attendees`");

    $eventsData = [
        [1, '4th Quarter Nationwide Simultaneous Earthquake Drill (NSED)', 'Disaster Drill', 12, 'Hinaplanon National High School Grounds', '2026-10-24 09:00:00', '2026-10-24 11:30:00', 'Engr. Armando Castillo & Kurt Morales', 300, 0, 'Community-wide earthquake evacuation simulation focusing on "Duck, Cover, and Hold" protocols, secondary fire response, and designated safe rally zone management.', 'Scheduled', 0, NULL, 'Standard NSED Protocol', 'Simulation, evacuation drills, head counting', NULL, 1, '2026-10-10 10:00:00'],
        [2, 'Mandulog River Community Flood Preparedness & Water Rescue', 'First Aid / Rescuer Training', 12, 'Mandulog Riverbank Operations Base', '2026-10-28 08:00:00', '2026-10-28 16:00:00', 'ICDRRMO Water Search & Rescue Unit', 60, 0, 'Hands-on practical training covering rope throwing, capsize recovery, outboard motor handling, and hypothermia first aid for coastal and riverine responders.', 'Scheduled', 0, NULL, 'Water rescue and survival techniques', 'Practical exercises along riverbank', NULL, 1, '2026-10-11 11:30:00'],
        [3, 'Community Basic Life Support & Trauma First Aid Training', 'First Aid / Rescuer Training', 12, 'Hinaplanon Barangay Covered Court', '2026-11-05 08:30:00', '2026-11-05 15:30:00', 'Sarah Jane Del Fierro, EMT Lead', 80, 0, 'Intensive BLS certification seminar covering cardiopulmonary resuscitation (CPR), AED usage, wound packing, and triage classification.', 'Scheduled', 0, NULL, 'CPR certification and trauma emergency care', 'Lectures, mannequin practice, return demonstration', NULL, 2, '2026-10-12 14:00:00'],
        [4, 'Household Emergency Preparedness & Go-Bag IEC Workshop', 'IEC Campaign', 12, 'Purok San Miguel Community Hall', '2026-09-28 13:00:00', '2026-09-28 16:30:00', 'Nurse Sarah Jane & Barangay Council', 120, 114, 'Educational seminar demonstrating 72-hour family emergency kit preparation, early warning color alerts, and pre-emptive evacuation procedures.', 'Completed', 0, 'High community participation. 114 families certified on the household emergency Go-Bag checklist.', 'Lecture and demonstration', 'Family checklist evaluation, bag inspection', NULL, 2, '2026-09-20 09:00:00'],
        [5, 'Purok Flood Hazard Mapping & Evacuation Route Walkthrough', 'Hazard Mapping Workshop', 12, 'Purok Riverside 1 Hall & Vicinity', '2026-10-02 09:00:00', '2026-10-02 12:00:00', 'Engr. Armando Castillo & Brgy Council', 80, 76, 'Field assessment and participatory community hazard mapping identifying vulnerable riverbank households and primary evacuation routes.', 'Completed', 0, 'Successfully pinpointed 4 high-risk culverts and verified safe high-ground pathways for flood season.', 'Ground mapping walkthrough', 'On-ground route inspection and signboard posting', NULL, 1, '2026-09-25 15:00:00'],
        [6, 'Barangay Commercial District Fire Safety & Evacuation Simulation', 'Disaster Drill', 12, 'Hinaplanon Central Commercial Corridor', '2026-11-12 14:00:00', '2026-11-12 17:00:00', 'BFP Iligan & Responders Team Alpha', 150, 0, 'Coordinated fire drill involving local commercial establishments, residential tenants, and BFP ladder trucks for high-density fire containment.', 'Scheduled', 0, NULL, 'Simulated structural fire containment', 'Hose handling, fire extinguisher usage, patient carry', NULL, 2, '2026-10-13 16:00:00']
    ];

    $evStmt = $pdo->prepare("INSERT INTO `events` (`id`, `title`, `activity_type`, `barangay_id`, `venue`, `start_datetime`, `end_datetime`, `assigned_personnel`, `target_participants`, `actual_participants`, `description`, `status`, `is_archived`, `evaluation_summary`, `content_execution`, `expected_activities`, `cancellation_reason`, `created_by`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($eventsData as $ev) {
        $evStmt->execute($ev);
    }

    // Seed 40+ event attendees across events
    $attStmt = $pdo->prepare("INSERT INTO `event_attendees` (`activity_id`, `user_id`, `resident_name`, `attended`, `phone`, `registered_at`, `sms_status`, `sms_message`) VALUES (?, ?, ?, ?, ?, ?, 'sent', ?)");
    $attendeeId = 1;
    for ($i = 1; $i <= 35; $i++) {
        $r = $residentNames[$i - 1];
        $actId = ($i <= 15) ? 1 : (($i <= 25) ? 2 : 3);
        $msg = "BARANGAY DISASTER ADVISORY\nHi {$r[0]}!\nYou are confirmed registered for our community event:\nActivity: " . $eventsData[$actId - 1][1] . "\nVenue: " . $eventsData[$actId - 1][4] . "\nPlease be at the venue 15 minutes before schedule.";
        $attStmt->execute([$actId, $i, $r[0] . ' ' . $r[1], 1, $r[5], '2026-10-14 09:00:00', $msg]);
    }
    // Completed event 4 attendees
    for ($i = 36; $i <= 50; $i++) {
        $r = $residentNames[$i - 1];
        $msg = "BARANGAY DISASTER ADVISORY\nHi {$r[0]}!\nThank you for attending the Household Emergency Preparedness Workshop at Purok San Miguel.";
        $attStmt->execute([4, $i, $r[0] . ' ' . $r[1], 1, $r[5], '2026-09-28 12:30:00', $msg]);
    }

    // -------------------------------------------------------------------------
    // 9. NOTIFICATIONS (Residents, Responders, ICDRRMO)
    // -------------------------------------------------------------------------
    echo "[9/11] Seeding Clean, Strictly Scoped Notifications...\n";
    $pdo->exec("TRUNCATE TABLE `notifications`");
    $pdo->exec("TRUNCATE TABLE `notification_reads`");

    $notifications = [
        // Resident notifications for Hinaplanon (target_barangay_id: 12)
        [1, 'resident', 12, NULL, 'Financial Aid Payout Schedule: Emergency Flood Relief Cash Assistance', 'Payout for Emergency Flood Relief Cash Assistance (FAA-2026-HIN-001) is active at Hinaplanon Covered Court. Beneficiaries must present valid government ID.', 'urgent', 'financial_aid', 1, 2, '2026-10-14 09:00:00'],
        [2, 'resident', 12, NULL, 'Relief Pack Distribution: Standard Family Food Packs Available', 'Emergency relief packs and hygiene kits are being distributed to displaced families at Hinaplanon NHS Gymnasium and Covered Court.', 'informational', 'resource_allocation', 1, 2, '2026-10-14 10:30:00'],
        [3, 'resident', 12, NULL, 'Upcoming Drill: 4th Quarter Nationwide Simultaneous Earthquake Drill (NSED)', 'You are invited to join the upcoming NSED scheduled on October 24, 2026 at 9:00 AM at Hinaplanon National High School Grounds.', 'informational', 'preparedness', 1, 2, '2026-10-15 08:00:00'],
        [4, 'resident', 12, NULL, 'Weather Advisory: Mandulog River Water Level Elevated', 'PAGASA heavy rainfall warning active. Mandulog river level is elevated. Residents in low-lying riverbank zones must maintain vigilance.', 'warning', 'disaster_request', 1, 1, '2026-10-14 07:15:00'],
        [5, 'resident', 12, NULL, 'Financial Aid Distribution: Purok Baybay Livelihood Grant', 'Claim schedule for Purok Baybay Coastal Surge Livelihood Grant (FAA-2026-HIN-002) is open for registered fisherfolk families.', 'informational', 'financial_aid', 2, 2, '2026-10-15 11:00:00'],
        [6, 'resident', 12, NULL, 'Evacuation Shelter Notice: Hinaplanon NHS Gym Open', 'Hinaplanon National High School Gymnasium is currently open and providing hot meals, sleeping cots, and emergency sanitation for evacuees.', 'informational', 'evacuation', 1, 2, '2026-10-14 12:00:00'],
        [7, 'resident', 12, NULL, 'Upcoming Seminar: River Water Rescue & Community Flood Preparedness', 'Community training on water safety and basic rescue will be conducted on October 28 at the Mandulog Riverbank Operations Base.', 'informational', 'preparedness', 2, 2, '2026-10-16 08:30:00'],

        // Responder notifications for Hinaplanon (target_barangay_id: 12)
        [8, 'responder', 12, NULL, 'Incident Alert: Flash Flood Warning in Purok Riverside 1', 'Mandulog River stage cresting at 4.2m. Responders Team Alpha deployed with 2 rescue craft to assist elderly and child evacuation.', 'urgent', 'disaster_request', 1, 1, '2026-10-14 06:45:00'],
        [9, 'responder', 12, NULL, 'Field Deployment: Team Alpha Assigned to Purok Sambag', 'Water rescue unit dispatched to Purok Sambag inner loop for trapped household assistance. Bring flotation lines and trauma kit.', 'urgent', 'disaster_request', 5, 2, '2026-10-16 02:30:00'],
        [10, 'responder', 12, NULL, 'Training Notice: Mandulog River Water Rescue Refresher', 'Mandatory refresher training for all active barangay emergency responders on October 28 at Mandulog Riverbank Operations Base.', 'informational', 'preparedness', 2, 1, '2026-10-12 10:00:00'],
        [11, 'responder', 12, NULL, 'Resource Dispatch: 60 SOLAS Life Vests Dispatched to Hinaplanon', 'ICDRRMO logistics depot has transferred 60 Type-III life vests and 2 rescue boats to Hinaplanon Operations Desk.', 'informational', 'resource_allocation', 1, 1, '2026-10-14 08:30:00'],
        [12, 'responder', 12, NULL, 'Incident Update: Purok Katipunan Drainage Declogged', 'Flood waters subsided at Purok Katipunan following culvert clearing. Responders stand down from high alert to monitoring stage.', 'informational', 'disaster_request', 6, 2, '2026-10-11 17:15:00'],

        // ICDRRMO & Citywide notifications
        [13, 'icdrrmo', NULL, NULL, 'Decision Tree Recommendation Ready: REQ-2026-001 (Hinaplanon)', 'Decision Tree evaluated critical flood conditions with 350 affected families. 93.8% confidence recommendation ready for review.', 'warning', 'recommendation', 1, 2, '2026-10-14 06:40:00'],
        [14, 'icdrrmo', NULL, NULL, 'Active Incident Reported: Mandulog Landslide REQ-2026-009', 'Heavy rainfall induced slope failure blocking 2 highway lanes in Purok Mandulog Bridge Area. Heavy equipment needed.', 'urgent', 'disaster_request', 9, 1, '2026-10-16 15:45:00'],
        [15, 'icdrrmo', NULL, NULL, 'Central Depot Stock Notice: Food Packs & Water Sufficient', 'Central logistics depot reports 1,100 packs of Standard Family Food Packs and 1,650 bottles of drinking water available.', 'informational', 'inventory', 1, 1, '2026-10-15 09:00:00'],

        // Barangay Head notifications
        [16, 'barangay_head', 12, NULL, 'Allocation Approved: Emergency Flood Relief Cash Assistance', 'ICDRRMO has approved PHP 1,500,000.00 Calamity Fund allocation for Purok Riverside 1 flash flood response.', 'informational', 'financial_aid', 1, 1, '2026-10-14 08:10:00'],
        [17, 'barangay_head', 12, NULL, 'Resource Dispatch Confirmed: Convoy Cleared for Hinaplanon', 'Logistics convoy transporting 350 food packs, 200 hygiene kits, and rescue gear is en route to Hinaplanon Covered Court.', 'informational', 'resource_allocation', 1, 1, '2026-10-14 08:45:00'],
        [18, 'barangay_head', 12, NULL, 'Pre-disaster Risk Assessment Warning: Continuous Rainfall', 'Hydromet telemetry indicates cumulative 24-hr rainfall exceeding 95mm across Mandulog catchment. Monitor river sensors.', 'warning', 'disaster_request', 1, 1, '2026-10-14 05:30:00']
    ];

    $notifStmt = $pdo->prepare("INSERT INTO `notifications` (`id`, `target_role`, `target_barangay_id`, `target_user_id`, `title`, `message`, `alert_level`, `related_module`, `related_id`, `created_by`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($notifications as $n) {
        $notifStmt->execute($n);
    }

    // -------------------------------------------------------------------------
    // 10. RESPONDER FIELD STATUS UPDATES
    // -------------------------------------------------------------------------
    echo "[10/11] Seeding Responder Field Status Reports...\n";
    $pdo->exec("TRUNCATE TABLE `responder_field_updates`");
    $fieldUpdates = [
        [1, 4, 1, 12, 'Purok Riverside 1', 'On Site', 'Water level along Purok Riverside 1 has crested at 1.4 meters in the lowest cluster. Team Alpha deployed 1 rescue craft. 18 senior citizens and children successfully assisted to Hinaplanon NHS Gym.', 0, 2, 28, '1 Inflatable Rescue Boat, 12 Life Vests, 1 First Aid Kit', 'Mandulog bridge spotters stationed to track debris flow.', '2026-10-14 09:30:00'],
        [2, 5, 1, 12, 'Purok Riverside 1', 'On Site', 'Triage station established at Hinaplanon Covered Court entrance. 4 evacuees treated for minor abrasions and hypothermia warming.', 0, 4, 0, 'Disaster Multi-Trauma Response Bag, Antiseptic Solution, Mylar Blankets', 'Sufficient medical supplies on hand for triage phase.', '2026-10-14 11:15:00'],
        [3, 6, 5, 12, 'Purok Sambag', 'On Site', 'Assisted evacuation of 45 residents across swollen tributary creek using floating safety line and life jackets.', 0, 1, 45, 'Water Rescue Throw Bags, 14 Life Vests, Static Kernmantle Rope', 'Tributary current still strong but receding slowly.', '2026-10-16 04:30:00'],
        [4, 7, 7, 12, 'Purok Centro', 'Completed', 'Assisted BFP triage team during commercial cluster residential fire. 3 smoke inhalation patients treated with oxygen therapy.', 0, 3, 0, 'Portable Oxygen Cylinder, Burn Dressing Kits, Digital Pulse Oximeters', 'All patients stable. No casualties recorded.', '2026-10-08 16:45:00'],
        [5, 4, 6, 12, 'Purok Katipunan', 'Completed', 'Assisted community clearing team in unclogging culverts at Katipunan creek. Water drained within 90 minutes.', 0, 0, 15, 'High-Output LED Searchlight, Wellington Rubber Boots, Leather Gloves', 'Culvert clear. Road passable to light vehicles.', '2026-10-11 16:00:00']
    ];

    $fuStmt = $pdo->prepare("INSERT INTO `responder_field_updates` (`id`, `responder_id`, `disaster_request_id`, `barangay_id`, `purok`, `operational_status`, `condition_overview`, `casualties`, `injuries`, `rescued_individuals`, `resources_utilized`, `remarks`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($fieldUpdates as $fu) {
        $fuStmt->execute($fu);
    }

    // -------------------------------------------------------------------------
    // 11. SYSTEM LOGS & AUDIT SUMMARY
    // -------------------------------------------------------------------------
    echo "[11/11] Seeding System Logs & Audit Trails...\n";
    $pdo->exec("TRUNCATE TABLE `system_logs`");
    $sysLogs = [
        [1, 1, 'Engr. Armando Castillo', 'icdrrmo', 'SYSTEM_INIT', 'System', 'Initialized system with verified OpenStreetMap coordinates and active GIS heatmap dataset.', '127.0.0.1', '2026-10-01 08:00:00'],
        [2, 2, 'Hon. Rodrigo Almonte', 'barangay_head', 'SUBMIT_DISASTER_REPORT', 'Disaster Reports', 'Submitted active disaster report REQ-2026-001 for Purok Riverside 1 flash flood.', '127.0.0.1', '2026-10-14 06:30:00'],
        [3, 1, 'Engr. Armando Castillo', 'icdrrmo', 'RUN_RECOMMENDER', 'Decision Tree Engine', 'Triggered Decision Tree recommendation REC-2026-001 with 93.80% confidence score.', '127.0.0.1', '2026-10-14 06:45:00'],
        [4, 1, 'Engr. Armando Castillo', 'icdrrmo', 'APPROVE_RECOMMENDATION', 'Decision Tree Engine', 'Approved Decision Tree recommendation REC-2026-001 for Barangay Hinaplanon.', '127.0.0.1', '2026-10-14 07:00:00'],
        [5, 1, 'Engr. Armando Castillo', 'icdrrmo', 'ALLOCATE_FINANCIAL_AID', 'Financial Aid', 'Created financial aid program FAA-2026-HIN-001 (Emergency Flood Relief Cash Assistance) with PHP 1.5M budget.', '127.0.0.1', '2026-10-14 08:00:00'],
        [6, 2, 'Hon. Rodrigo Almonte', 'barangay_head', 'DISBURSE_FINANCIAL_AID', 'Financial Aid', 'Disbursed batch 1 financial aid payouts to 18 beneficiaries in Purok Riverside 1.', '127.0.0.1', '2026-10-14 11:30:00'],
        [7, 4, 'Kurt Christian Morales', 'responder', 'FIELD_STATUS_UPDATE', 'Responder Field', 'Updated status to On Site at Purok Riverside 1 with 28 rescued individuals.', '127.0.0.1', '2026-10-14 09:30:00'],
        [8, 2, 'Hon. Rodrigo Almonte', 'barangay_head', 'SCHEDULE_ACTIVITY', 'Preparedness', 'Scheduled 4th Quarter Nationwide Simultaneous Earthquake Drill (NSED) for Barangay Hinaplanon.', '127.0.0.1', '2026-10-10 10:00:00']
    ];

    $logStmt = $pdo->prepare("INSERT INTO `system_logs` (`id`, `user_id`, `user_name`, `role`, `action`, `module`, `details`, `ip_address`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($sysLogs as $sl) {
        $logStmt->execute($sl);
    }

    // Re-enable Foreign Key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "\n========================================================\n";
    echo "🎉 SUCCESS: All database tables seeded successfully!\n";
    echo "========================================================\n";
    echo "Summary of populated test data:\n";
    echo "  - Hinaplanon Puroks: " . count($hinaplanonPuroks) . " (Full coverage of all risk levels & coordinates)\n";
    echo "  - Total Puroks: " . (count($hinaplanonPuroks) + count($otherPuroks)) . "\n";
    echo "  - Registered Residents: " . count($residentNames) . " (Realistic names, contact phones & puroks)\n";
    echo "  - System Users & Responders: " . count($usersData) . "\n";
    echo "  - Evacuation Centers: " . count($evacAreas) . " (6 in Hinaplanon with prepositioned items)\n";
    echo "  - Disaster Reports: " . count($disasterReports) . " (Ongoing & Completed across disaster types)\n";
    echo "  - AI Decision Recommendations: " . count($recs) . " (High confidence, explainable trees)\n";
    echo "  - Financial Aid Allocations: " . count($allocations) . " (PHP 4.35M total allocated budgets)\n";
    echo "  - Beneficiary Distributions: " . ($distId - 1) . " (Mix of Claimed & Pending with audit logs)\n";
    echo "  - Preparedness Drills & Events: " . count($eventsData) . " (Clean events with " . ($attendeeId + 50 - 1) . " attendees)\n";
    echo "  - Scoped Notifications: " . count($notifications) . " (Strictly scoped by barangay & role)\n";
    echo "========================================================\n\n";

} catch (Throwable $e) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "\n[ERROR] Seeding failed:\n" . $e->getMessage() . "\n\n";
    exit(1);
}
