<?php
/**
 * Seeder: Purok Household Families & Members
 * Populates 32 to 48 household families per purok across all 24 Puroks of Hinaplanon.
 * Preserves user created records (such as Nadayag family).
 * Enforces strictly 1 Authorized Representative per household.
 * Updates dynamic demographic counts for puroks and barangay.
 */

require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/database/Connection.php';
require_once __DIR__ . '/../backend/services/Helpers.php';

echo "========================================================\n";
echo "  Seeding Purok Household Families (30-50 per Purok)\n";
echo "========================================================\n";

$db = getDBConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$surnames = [
    'Mendoza', 'Santos', 'Reyes', 'Villanueva', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Torres', 'Tomas',
    'Andrada', 'Castillo', 'Flores', 'Villamor', 'Morales', 'Ramos', 'Gomez', 'Tan', 'Fernandez', 'Sotto',
    'Cañete', 'Delos Reyes', 'Dela Torre', 'Corpuz', 'Alcantara', 'Celdran', 'Pimentel', 'Padilla', 'Navarro',
    'Mercado', 'Aguilar', 'Salazar', 'Valdez', 'Domingo', 'Soriano', 'Santiago', 'De Guzman', 'Panganiban',
    'Magno', 'Valderrama', 'Balgos', 'Badelles', 'Ferrer', 'Ilagan', 'Custodio', 'Evangelista', 'Macaraeg',
    'Manalo', 'Natividad', 'Palma', 'Quizon', 'Rosario', 'Tolentino', 'Umali', 'Vergara', 'Yap', 'Zamora',
    'Abad', 'Beltran', 'Capistrano', 'David', 'Enriquez', 'Fajardo', 'Gutierrez', 'Hidalgo', 'Ignacio',
    'Javier', 'Lacson', 'Montano', 'Noriega', 'Ortega', 'Pascual', 'Quevedo', 'Rivera', 'Samonte', 'Tuazon',
    'Valenzuela', 'Yambao', 'Bernardo', 'Cabrera', 'Dizon', 'Escobar', 'Fuentes', 'Galang', 'Hernandez',
    'Ibáñez', 'Jimenez', 'Lim', 'Miranda', 'Nuñez', 'Peralta', 'Quiambao', 'Roxas', 'Silva', 'Tañada'
];

$firstNamesM = [
    'Juan', 'Jose', 'Mark', 'Danilo', 'Carlo', 'Rogelio', 'Eduardo', 'Alejandro', 'Felipe', 'Jaime',
    'Ernesto', 'Salvador', 'Roberto', 'Arsenio', 'Vicente', 'Rolando', 'Christian', 'Ferdinand', 'Hernan',
    'Karlo', 'Melchor', 'Antonio', 'Ramon', 'Gabriel', 'Angelo', 'Paolo', 'Manuel', 'Ricardo', 'Francisco',
    'Alberto', 'Emilio', 'Alfredo', 'Benjamin', 'Cesar', 'Nestor', 'Dominic', 'Rene', 'Gerardo', 'Arturo',
    'Noel', 'Edgardo', 'Efren', 'Rodrigo', 'Wilfredo', 'Renato', 'Dan', 'Patrick', 'Leo', 'Raul', 'Marvin'
];

$firstNamesF = [
    'Maria', 'Grace', 'Elena', 'Sarah', 'Lorna', 'Corazon', 'Teresa', 'Remedios', 'Carmen', 'Filomena',
    'Rosario', 'Fatima', 'Beatrice', 'Divina', 'Esperanza', 'Gemma', 'Imelda', 'Jocelyn', 'Leila', 'Nenita',
    'Ana', 'Cristina', 'Patricia', 'Angelica', 'Rowena', 'Maricel', 'Lourdes', 'Cynthia', 'Victoria', 'Leonora',
    'Clarissa', 'Evelyn', 'Gloria', 'Marilou', 'Rosalinda', 'Zenaida', 'Norma', 'Perla', 'Estrella', 'Vilma',
    'Cecilia', 'Rosanna', 'Maribel', 'Judith', 'Rebecca', 'Consuelo', 'Priscilla', 'Aida', 'Fe', 'Lydia'
];

$childNamesM = [
    'Joshua', 'Nathan', 'Ethan', 'Justin', 'Kyle', 'Miguel', 'Elijah', 'Carl', 'Liam', 'Lucas',
    'Daniel', 'Jacob', 'Gabriel', 'Kian', 'Marcus', 'Matthew', 'Alexander', 'Adrian', 'Sean', 'Noah'
];

$childNamesF = [
    'Princess', 'Hannah', 'Kristine', 'Chloe', 'Sofia', 'Angel', 'Samantha', 'Nicole', 'Bea', 'Jasmine',
    'Andrea', 'Alyssa', 'Mia', 'Kylie', 'Brianna', 'Ella', 'Samantha', 'Althea', 'Faith', 'Stephanie'
];

try {
    // Preserve any existing user-added household (Nadayag family)
    $userAdded = $db->query("SELECT * FROM purok_households WHERE family_name LIKE '%Nadayag%'")->fetchAll(PDO::FETCH_ASSOC);

    // Truncate tables for fresh comprehensive population
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    $db->exec("TRUNCATE TABLE `household_members`");
    $db->exec("TRUNCATE TABLE `purok_households`");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    $db->beginTransaction();

    // Re-insert preserved households if any
    if (!empty($userAdded)) {
        $insHhStmt = $db->prepare("
            INSERT INTO purok_households (
                id, barangay_id, purok_id, purok_name, household_code, family_name,
                head_resident_id, head_full_name, head_relation, head_phone, head_gender, head_age,
                is_authorized_rep, recognition_status, authorized_id_type, authorized_id_number,
                house_number, total_members, seniors_count, minors_count, pwd_count,
                housing_type, economic_status, evacuation_priority, emergency_notes, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($userAdded as $uHh) {
            $insHhStmt->execute(array_values($uHh));
            // Add authorized member row
            $db->prepare("
                INSERT INTO household_members (
                    household_id, full_name, relationship, gender, age, is_authorized_rep, is_senior, is_minor, is_pwd, health_conditions
                ) VALUES (?, ?, 'Household Head (Authorized)', ?, ?, 1, 0, 0, 0, 'None')
            ")->execute([$uHh['id'], $uHh['head_full_name'], $uHh['head_gender'] ?: 'Male', $uHh['head_age'] ?: 38]);
        }
    }

    // Fetch existing registered residents from residents table
    $registeredResidents = $db->query("SELECT id, full_name, phone, gender, age, purok FROM residents ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $resMap = [];
    foreach ($registeredResidents as $r) {
        $resMap[$r['purok']][] = $r;
    }

    // Fetch all 24 puroks of Hinaplanon
    $puroks = $db->query("SELECT id, name FROM puroks WHERE barangay_id = 12 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    sortPuroksMainFirst($puroks);

    $hhInsertStmt = $db->prepare("
        INSERT INTO purok_households (
            barangay_id, purok_id, purok_name, household_code, family_name,
            head_resident_id, head_full_name, head_relation, head_phone, head_gender, head_age,
            is_authorized_rep, recognition_status, authorized_id_type, authorized_id_number,
            house_number, total_members, seniors_count, minors_count, pwd_count,
            housing_type, economic_status, evacuation_priority, emergency_notes, status
        ) VALUES (
            12, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            1, 'Recognized & Authorized', ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, 'active'
        )
    ");

    $memInsertStmt = $db->prepare("
        INSERT INTO household_members (
            household_id, full_name, relationship, gender, age, is_authorized_rep, is_senior, is_minor, is_pwd, health_conditions
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $totalCreatedHouseholds = 0;
    $totalCreatedMembers = 0;
    $surnameIdx = 0;

    foreach ($puroks as $p) {
        $pId = (int)$p['id'];
        $pName = $p['name'];
        preg_match('/(\d+)/', $pName, $numMatch);
        $pNum = !empty($numMatch[1]) ? (int)$numMatch[1] : $pId;

        // Target: between 32 and 45 households per purok (averaging ~38)
        $targetCount = rand(34, 46);
        $purokResidents = $resMap[$pName] ?? [];
        $resIdx = 0;

        echo "Seeding {$targetCount} households in {$pName}...\n";

        for ($h = 1; $h <= $targetCount; $h++) {
            $code = sprintf("HH-%02d-%03d", $pNum, $h);

            // Check if this household matches a registered resident
            $matchedResident = null;
            if ($resIdx < count($purokResidents)) {
                $matchedResident = $purokResidents[$resIdx];
                $resIdx++;
            }

            $surname = $surnames[$surnameIdx % count($surnames)];
            $surnameIdx++;
            $familyName = "{$surname} Family";

            $isFemaleHead = (rand(1, 100) <= 40); // 40% female head, 60% male head
            $headFn = $isFemaleHead ? $firstNamesF[array_rand($firstNamesF)] : $firstNamesM[array_rand($firstNamesM)];
            $headFullName = $matchedResident ? $matchedResident['full_name'] : "{$headFn} {$surname}";
            $headGender = $matchedResident ? ($matchedResident['gender'] ?: ($isFemaleHead ? 'Female' : 'Male')) : ($isFemaleHead ? 'Female' : 'Male');
            $headAge = $matchedResident ? ((int)$matchedResident['age'] ?: rand(30, 65)) : rand(28, 68);
            $headPhone = $matchedResident ? $matchedResident['phone'] : ('+63 9' . rand(12, 98) . ' ' . rand(100, 999) . ' ' . rand(1000, 9999));
            $headResId = $matchedResident ? (int)$matchedResident['id'] : null;

            $relation = (rand(1, 100) <= 85) ? 'Household Head' : ($isFemaleHead ? 'Spouse' : 'Eldest Child');
            $idType = ($headAge >= 60) ? 'Senior Citizen ID' : 'PhilSys National ID';
            $idNum = sprintf("PSN-2026-%04d-%04d", rand(1000, 9999), rand(1000, 9999));
            $houseNum = "Block " . rand(1, 12) . ", Lot " . rand(1, 28) . ", Zone " . rand(1, 4);

            // Determine member size (2 to 6 individuals)
            $familySize = rand(2, 6);
            $hasSenior = ($headAge >= 60) ? 1 : (rand(1, 100) <= 25 ? 1 : 0);
            $hasMinor = ($familySize >= 3) ? rand(1, min(3, $familySize - 1)) : (rand(1, 100) <= 40 ? 1 : 0);
            $hasPwd = (rand(1, 100) <= 8 ? 1 : 0);

            $housingTypes = ['Concrete', 'Semi-Concrete', 'Wood/Light Materials'];
            $housing = $housingTypes[rand(0, 2)];
            $econStatuses = ['Indigent / Low Income', 'Lower Middle Income', 'Middle Income'];
            $econ = $econStatuses[rand(0, 2)];
            $evacPriority = ($hasPwd > 0) ? 'High Priority' : ($hasSenior > 0 ? 'Priority' : 'Standard');
            $notes = ($hasPwd > 0) ? 'Mobility impaired resident. Requires wheelchair transport during flood alerts.' :
                     (($hasSenior > 0) ? 'Elderly maintenance medications; primary health monitoring required.' : 'Standard go-bag prepped with battery radio and emergency supplies.');

            $hhInsertStmt->execute([
                $pId, $pName, $code, $familyName,
                $headResId, $headFullName, $relation, $headPhone, $headGender, $headAge,
                $idType, $idNum,
                $houseNum, $familySize, $hasSenior, $hasMinor, $hasPwd,
                $housing, $econ, $evacPriority, $notes
            ]);
            $hhId = (int)$db->lastInsertId();
            $totalCreatedHouseholds++;

            // 1. Insert 1 Authorized Representative member
            $isHeadSenior = ($headAge >= 60) ? 1 : 0;
            $isHeadMinor = ($headAge < 18) ? 1 : 0;
            $memInsertStmt->execute([
                $hhId, $headFullName, "{$relation} (Authorized Rep)", $headGender, $headAge,
                1, $isHeadSenior, $isHeadMinor, ($hasPwd && $isHeadSenior ? 1 : 0),
                ($isHeadSenior ? 'Hypertension maintenance' : 'None')
            ]);
            $totalCreatedMembers++;

            // 2. Insert Spouse (if familySize >= 2 and head is adult)
            $remaining = $familySize - 1;
            if ($remaining > 0) {
                $spouseGender = ($headGender === 'Male') ? 'Female' : 'Male';
                $spouseFn = ($spouseGender === 'Female') ? $firstNamesF[array_rand($firstNamesF)] : $firstNamesM[array_rand($firstNamesM)];
                $spouseAge = max(20, min(80, $headAge + rand(-4, 4)));
                $memInsertStmt->execute([
                    $hhId, "{$spouseFn} {$surname}", 'Spouse', $spouseGender, $spouseAge,
                    0, ($spouseAge >= 60 ? 1 : 0), 0, 0, 'None'
                ]);
                $totalCreatedMembers++;
                $remaining--;
            }

            // 3. Insert Children or other relatives
            while ($remaining > 0) {
                $isChild = ($remaining <= $hasMinor || rand(1, 100) <= 80);
                if ($isChild) {
                    $cGender = (rand(1, 100) <= 50) ? 'Male' : 'Female';
                    $cFn = ($cGender === 'Male') ? $childNamesM[array_rand($childNamesM)] : $childNamesF[array_rand($childNamesF)];
                    $cAge = rand(2, 17);
                    $memInsertStmt->execute([
                        $hhId, "{$cFn} {$surname}", ($cGender === 'Male' ? 'Son' : 'Daughter'), $cGender, $cAge,
                        0, 0, 1, 0, 'None'
                    ]);
                } else {
                    $gGender = (rand(1, 100) <= 50) ? 'Male' : 'Female';
                    $gFn = ($gGender === 'Male') ? $firstNamesM[array_rand($firstNamesM)] : $firstNamesF[array_rand($firstNamesF)];
                    $gAge = rand(62, 84);
                    $memInsertStmt->execute([
                        $hhId, "{$gFn} {$surname}", ($gGender === 'Male' ? 'Grandfather' : 'Grandmother'), $gGender, $gAge,
                        0, 1, 0, 0, 'Arthritis / Maintenance'
                    ]);
                }
                $totalCreatedMembers++;
                $remaining--;
            }
        }
    }

    if ($db->inTransaction()) {
        $db->commit();
    }

    echo "\n--------------------------------------------------------\n";
    echo "  Synchronizing Dynamic Purok & Barangay Demographics...\n";
    echo "--------------------------------------------------------\n";

    // Synchronize all 24 puroks
    foreach ($puroks as $p) {
        $synced = syncPurokDemographics($db, $p['id']);
        echo "  [{$p['name']}] Synced -> Households: {$synced['households']} | Population: {$synced['population']}\n";
    }

    echo "\n========================================================\n";
    echo "🎉 SUCCESS: Seeding Completed!\n";
    echo "========================================================\n";
    echo "  Total Household Families: {$totalCreatedHouseholds}\n";
    echo "  Total Individual Members: {$totalCreatedMembers}\n";
    echo "  Average per Purok: ~" . round($totalCreatedHouseholds / count($puroks)) . " households\n";
    echo "========================================================\n\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR during seeding: " . $e->getMessage() . "\n";
    exit(1);
}
