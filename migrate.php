<?php
// ============================================================================
// Intelligent Database Migration & Deployment Synchronization Script
// Compatible with: Railway CLI, TiDB Cloud Serverless, and Local XAMPP
//
// Usage:
//   railway run php migrate.php          (Smart migration on remote Railway/TiDB)
//   railway run php migrate.php --fresh  (Fresh wipe & re-seed on remote Railway/TiDB)
//   php migrate.php                      (Local migration)
//   php migrate.php --fresh              (Local fresh reset & re-seed)
// ============================================================================

// Prevent execution via public web browser if desired, but allow CLI
if (php_sapi_name() !== 'cli' && !isset($_GET['allow_web'])) {
    header('Content-Type: text/plain');
    echo "Notice: This migration tool is designed for CLI execution (e.g. 'railway run php migrate.php' or 'php migrate.php').\n";
    echo "To run from a browser, append ?allow_web=1 to the URL.\n";
    exit(0);
}

// Adjust base directory based on file location (root vs database/)
$rootDir = file_exists(__DIR__ . '/backend/config/config.php') ? __DIR__ : dirname(__DIR__);

require_once $rootDir . '/backend/config/config.php';
require_once $rootDir . '/backend/database/Connection.php';

$args = $argv ?? [];
$isFresh = in_array('--fresh', $args) || in_array('--reset', $args) || in_array('-f', $args);
$seedOnly = in_array('--seed', $args);

echo "\n========================================================\n";
echo "  Barangay Disaster System - Database Migration\n";
echo "========================================================\n";
echo "Host:     " . DB_HOST . ":" . DB_PORT . "\n";
echo "Database: " . DB_NAME . "\n";
echo "User:     " . DB_USER . "\n";
echo "SSL:      " . (DB_SSL_ENABLE ? "Enabled (TLS/SSL)" : "Disabled") . "\n";
echo "Mode:     " . ($isFresh ? "FRESH RESET (--fresh)" : ($seedOnly ? "SEED ONLY (--seed)" : "SMART MIGRATION")) . "\n";
echo "--------------------------------------------------------\n";
echo "Connecting to database...\n";

try {
    $pdo = getDBConnection();
    // Enable emulation for executing statements
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    echo "[✓] Connected to database successfully!\n\n";

    // Disable Foreign Key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    $schemaFile = $rootDir . '/database/schema.sql';
    $seedsFile  = $rootDir . '/database/seeds.sql';

    if (!file_exists($schemaFile)) {
        die("[ERROR] schema.sql not found at: {$schemaFile}\n");
    }

    // Check existing tables
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Helper function to reliably execute SQL files statement-by-statement
    // Prevents TLS buffer truncation issues on TiDB Cloud Serverless & remote databases
    $executeSqlFile = function(PDO $db, string $filePath, string $label) {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }
        $content = file_get_contents($filePath);

        // Clean TiDB / MySQL incompatibilities
        $content = preg_replace('/LOCK TABLES `?[^`\s]+`? WRITE;/i', '', $content);
        $content = preg_replace('/UNLOCK TABLES;/i', '', $content);
        $content = preg_replace('/\/\*!40000 ALTER TABLE `?[^`\s]+`? (DISABLE|ENABLE) KEYS \*\/;/i', '', $content);

        // Split into individual SQL statements by semicolon followed by newline
        $statements = preg_split('/;\s*(\r\n|\r|\n)/', $content);
        $executed = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            if (strpos($stmt, '--') === 0) continue;
            $db->exec($stmt);
            $executed++;
        }
        echo "[✓] {$label} applied successfully ({$executed} statements executed).\n\n";
    };

    if ($isFresh || empty($existingTables)) {
        // --------------------------------------------------------------------
        // FULL SCHEMA SETUP / FRESH RESET
        // --------------------------------------------------------------------
        echo ($isFresh ? "Step 1: Dropping and recreating tables (--fresh)...\n" : "Step 1: Initializing fresh database schema...\n");

        if ($isFresh && !empty($existingTables)) {
            echo "  [!] Cleanly dropping " . count($existingTables) . " existing tables for a clean wipe...\n";
            foreach ($existingTables as $tbl) {
                $pdo->exec("DROP TABLE IF EXISTS `{$tbl}`;");
            }
            echo "  [✓] All previous tables dropped.\n";
        }

        $executeSqlFile($pdo, $schemaFile, "Database schema");

        if (file_exists($seedsFile)) {
            echo "Step 2: Importing seed data from seeds.sql...\n";
            $executeSqlFile($pdo, $seedsFile, "Seed data");
        }

        // Master PHP Seeder (Puroks, Users, Reports, Aid, Inventory, Households 30-50 & Demographics)
        $seedAllFile = $rootDir . '/database/seed_all.php';
        if (file_exists($seedAllFile)) {
            echo "Step 3: Populating master database records & households (seed_all.php)...\n";
            require $seedAllFile;
        }
    } else {
        // --------------------------------------------------------------------
        // SMART INCREMENTAL MIGRATION (Preserves existing data on deployment)
        // --------------------------------------------------------------------
        echo "Step 1: Checking database tables and schema version...\n";
        echo "Found " . count($existingTables) . " existing tables in database.\n";

        // 0. Rename legacy tables if present
        $legacyRenames = [
            'disaster_requests' => 'disaster_reports',
            'activity_attendees' => 'event_attendees',
            'preparedness_activities' => 'events',
        ];
        foreach ($legacyRenames as $oldTable => $newTable) {
            if (in_array($oldTable, $existingTables, true) && !in_array($newTable, $existingTables, true)) {
                echo "  [+] Renaming table `{$oldTable}` to `{$newTable}`...\n";
                $pdo->exec("RENAME TABLE `{$oldTable}` TO `{$newTable}`;");
                $existingTables = array_values(array_diff($existingTables, [$oldTable]));
                $existingTables[] = $newTable;
            }
        }

        // 1. Ensure all schema tables exist
        $schemaContent = file_get_contents($schemaFile);
        $createStatements = [];
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)` \((?:[^;]|\n)+?\)[^;]*;/i', $schemaContent, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $tableName = $match[1];
            if (!in_array($tableName, $existingTables, true)) {
                echo "  [+] Creating missing table: `{$tableName}`...\n";
                $pdo->exec($match[0]);
                $existingTables[] = $tableName;
            }
        }

        // 2. Safely apply column additions and enum enhancements
        echo "Step 2: Synchronizing table columns and schema updates...\n";

        // Helper to check if column exists
        $hasColumn = function(PDO $db, string $table, string $column): bool {
            try {
                $q = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
                return $q && $q->rowCount() > 0;
            } catch (Throwable $e) {
                return false;
            }
        };

        // Table: events
        if (in_array('events', $existingTables, true)) {
            if (!$hasColumn($pdo, 'events', 'target_puroks')) {
                echo "  [+] Adding column `target_puroks` to `events`...\n";
                $pdo->exec("ALTER TABLE `events` ADD COLUMN `target_puroks` varchar(255) NOT NULL DEFAULT 'All Puroks' AFTER `venue`;");
            }
            if (!$hasColumn($pdo, 'events', 'latitude')) {
                echo "  [+] Adding column `latitude` to `events`...\n";
                $pdo->exec("ALTER TABLE `events` ADD COLUMN `latitude` decimal(10,6) DEFAULT NULL AFTER `venue`;");
            }
            if (!$hasColumn($pdo, 'events', 'longitude')) {
                echo "  [+] Adding column `longitude` to `events`...\n";
                $pdo->exec("ALTER TABLE `events` ADD COLUMN `longitude` decimal(10,6) DEFAULT NULL AFTER `latitude`;");
            }
            if (!$hasColumn($pdo, 'events', 'is_archived')) {
                echo "  [+] Adding column `is_archived` to `events`...\n";
                $pdo->exec("ALTER TABLE `events` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`;");
            }
            if (!$hasColumn($pdo, 'events', 'content_execution')) {
                echo "  [+] Adding column `content_execution` to `events`...\n";
                $pdo->exec("ALTER TABLE `events` ADD COLUMN `content_execution` text DEFAULT NULL AFTER `evaluation_summary`;");
            }
            if (!$hasColumn($pdo, 'events', 'expected_activities')) {
                echo "  [+] Adding column `expected_activities` to `events`...\n";
                $pdo->exec("ALTER TABLE `events` ADD COLUMN `expected_activities` text DEFAULT NULL AFTER `content_execution`;");
            }
            if (!$hasColumn($pdo, 'events', 'cancellation_reason')) {
                echo "  [+] Adding column `cancellation_reason` to `events`...\n";
                $pdo->exec("ALTER TABLE `events` ADD COLUMN `cancellation_reason` text DEFAULT NULL AFTER `expected_activities`;");
            }
            // Update status enum if needed to support Archived and Ended
            try {
                echo "  [+] Ensuring `events.status` enum includes 'Ended' and 'Archived'...\n";
                $pdo->exec("ALTER TABLE `events` MODIFY COLUMN `status` enum('Scheduled','Ongoing','Completed','Cancelled','Archived','Ended') DEFAULT 'Scheduled';");
            } catch (Throwable $e) {}
            // Ensure activity_type is VARCHAR(100) to support all 13 activity categories
            try {
                echo "  [+] Ensuring `events.activity_type` is VARCHAR(100)...\n";
                $pdo->exec("ALTER TABLE `events` MODIFY COLUMN `activity_type` varchar(100) NOT NULL DEFAULT 'Disaster Preparedness and Safety';");
            } catch (Throwable $e) {}
        }

        // Table: event_attendees
        if (in_array('event_attendees', $existingTables, true)) {
            if (!$hasColumn($pdo, 'event_attendees', 'attended')) {
                echo "  [+] Adding column `attended` to `event_attendees`...\n";
                $pdo->exec("ALTER TABLE `event_attendees` ADD COLUMN `attended` tinyint(1) NOT NULL DEFAULT 1 AFTER `resident_name`;");
            }
        }

        // Table: users
        if (in_array('users', $existingTables, true)) {
            if (!$hasColumn($pdo, 'users', 'agency_id')) {
                echo "  [+] Adding column `agency_id` to `users`...\n";
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `agency_id` int(11) DEFAULT NULL AFTER `role`;");
            }
            if (!$hasColumn($pdo, 'users', 'position')) {
                echo "  [+] Adding column `position` to `users`...\n";
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `position` varchar(100) DEFAULT NULL AFTER `agency_id`;");
            }
        }

        // Table: disaster_reports (Geolocation, Street View Pinpoint & Aid Support)
        if (in_array('disaster_reports', $existingTables, true)) {
            if (!$hasColumn($pdo, 'disaster_reports', 'coordinates_lat')) {
                echo "  [+] Adding column `coordinates_lat` to `disaster_reports`...\n";
                $pdo->exec("ALTER TABLE `disaster_reports` ADD COLUMN `coordinates_lat` decimal(10,6) DEFAULT NULL AFTER `purok_name`;");
            }
            if (!$hasColumn($pdo, 'disaster_reports', 'coordinates_lng')) {
                echo "  [+] Adding column `coordinates_lng` to `disaster_reports`...\n";
                $pdo->exec("ALTER TABLE `disaster_reports` ADD COLUMN `coordinates_lng` decimal(10,6) DEFAULT NULL AFTER `coordinates_lat`;");
            }
            if (!$hasColumn($pdo, 'disaster_reports', 'incident_location')) {
                echo "  [+] Adding column `incident_location` to `disaster_reports`...\n";
                $pdo->exec("ALTER TABLE `disaster_reports` ADD COLUMN `incident_location` varchar(255) DEFAULT NULL AFTER `coordinates_lng`;");
            }
            if (!$hasColumn($pdo, 'disaster_reports', 'aid_support')) {
                echo "  [+] Adding column `aid_support` to `disaster_reports`...\n";
                $pdo->exec("ALTER TABLE `disaster_reports` ADD COLUMN `aid_support` enum('resources','financial_aid','both') NOT NULL DEFAULT 'resources' AFTER `urgency`;");
            }
        }

        // Table: recommendations (Support selection between resources, financial_aid, or both & funding sponsor)
        if (in_array('recommendations', $existingTables, true)) {
            if (!$hasColumn($pdo, 'recommendations', 'modality')) {
                echo "  [+] Adding column `modality` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `modality` enum('both','resources','financial_aid') NOT NULL DEFAULT 'both' AFTER `priority`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_recommended_budget')) {
                echo "  [+] Adding column `financial_aid_recommended_budget` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_recommended_budget` decimal(12,2) DEFAULT NULL AFTER `recommendation_reason`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_per_family')) {
                echo "  [+] Adding column `financial_aid_per_family` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_per_family` decimal(10,2) DEFAULT NULL AFTER `financial_aid_recommended_budget`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_target_families')) {
                echo "  [+] Adding column `financial_aid_target_families` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_target_families` int(11) DEFAULT NULL AFTER `financial_aid_per_family`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_source')) {
                echo "  [+] Adding column `financial_aid_source` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_source` varchar(150) DEFAULT NULL AFTER `financial_aid_target_families`;");
            } else {
                $pdo->exec("ALTER TABLE `recommendations` MODIFY COLUMN `financial_aid_source` varchar(150) DEFAULT NULL;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_approved_budget')) {
                echo "  [+] Adding column `financial_aid_approved_budget` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_approved_budget` decimal(12,2) DEFAULT NULL AFTER `financial_aid_source`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_added_type')) {
                echo "  [+] Adding column `financial_aid_added_type` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_added_type` enum('ai_recommended','added_by_admin') NOT NULL DEFAULT 'ai_recommended' AFTER `financial_aid_approved_budget`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_allocation_id')) {
                echo "  [+] Adding column `financial_aid_allocation_id` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_allocation_id` int(11) DEFAULT NULL AFTER `financial_aid_added_type`;");
            }
        }

        // Table: recommended_items (Track whether item was generated by AI or added by admin)
        if (in_array('recommended_items', $existingTables, true)) {
            if (!$hasColumn($pdo, 'recommended_items', 'added_type')) {
                echo "  [+] Adding column `added_type` to `recommended_items`...\n";
                $pdo->exec("ALTER TABLE `recommended_items` ADD COLUMN `added_type` enum('ai_recommended','added_by_admin') NOT NULL DEFAULT 'ai_recommended' AFTER `status`;");
            }
        }

        // Table: resource_transactions (Track transaction item provenance)
        if (in_array('resource_transactions', $existingTables, true)) {
            if (!$hasColumn($pdo, 'resource_transactions', 'added_type')) {
                echo "  [+] Adding column `added_type` to `resource_transactions`...\n";
                $pdo->exec("ALTER TABLE `resource_transactions` ADD COLUMN `added_type` enum('ai_recommended','added_by_admin') DEFAULT NULL AFTER `reference_id`;");
            }
        }

        // Table: financial_aid_allocations (Track whether program was AI or admin defined)
        if (in_array('financial_aid_allocations', $existingTables, true)) {
            if (!$hasColumn($pdo, 'financial_aid_allocations', 'added_type')) {
                echo "  [+] Adding column `added_type` to `financial_aid_allocations`...\n";
                $pdo->exec("ALTER TABLE `financial_aid_allocations` ADD COLUMN `added_type` enum('ai_recommended','added_by_admin') NOT NULL DEFAULT 'ai_recommended' AFTER `status`;");
            }
        }

        // Table: financial_aid_distributions (Link distribution to household families)
        if (in_array('financial_aid_distributions', $existingTables, true)) {
            if (!$hasColumn($pdo, 'financial_aid_distributions', 'household_id')) {
                echo "  [+] Adding column `household_id` to `financial_aid_distributions`...\n";
                $pdo->exec("ALTER TABLE `financial_aid_distributions` ADD COLUMN `household_id` int(11) DEFAULT NULL AFTER `resident_id`;");
                $pdo->exec("ALTER TABLE `financial_aid_distributions` ADD KEY `idx_dist_hh` (`household_id`);");
            }
        }

        // Table: evacuation_family_checkins (Household & Family Tracking in Evacuation Centers)
        if (!in_array('evacuation_family_checkins', $existingTables, true)) {
            echo "  [+] Creating table `evacuation_family_checkins`...\n";
            $pdo->exec("
                CREATE TABLE `evacuation_family_checkins` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `evacuation_area_id` int(11) NOT NULL,
                  `household_id` int(11) NOT NULL,
                  `family_name` varchar(150) NOT NULL,
                  `household_code` varchar(50) NOT NULL,
                  `head_full_name` varchar(150) NOT NULL,
                  `head_phone` varchar(30) DEFAULT NULL,
                  `purok_name` varchar(100) NOT NULL,
                  `room_tent_number` varchar(100) DEFAULT NULL,
                  `members_count` int(11) NOT NULL DEFAULT 1,
                  `seniors_count` int(11) NOT NULL DEFAULT 0,
                  `minors_count` int(11) NOT NULL DEFAULT 0,
                  `pwd_count` int(11) NOT NULL DEFAULT 0,
                  `special_medical_needs` varchar(255) DEFAULT NULL,
                  `relief_packs_given` int(11) NOT NULL DEFAULT 0,
                  `hot_meals_claimed` int(11) NOT NULL DEFAULT 0,
                  `status` enum('Sheltered','Transferred','Decamped / Returned Home') NOT NULL DEFAULT 'Sheltered',
                  `checkin_time` datetime DEFAULT current_timestamp(),
                  `checkout_time` datetime DEFAULT NULL,
                  `checked_in_by` varchar(100) DEFAULT NULL,
                  `notes` text DEFAULT NULL,
                  `created_at` datetime DEFAULT current_timestamp(),
                  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_evac_area` (`evacuation_area_id`),
                  KEY `idx_hh_id` (`household_id`),
                  KEY `idx_status` (`status`),
                  CONSTRAINT `fk_evac_checkins_area` FOREIGN KEY (`evacuation_area_id`) REFERENCES `evacuation_areas` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $existingTables[] = 'evacuation_family_checkins';
        }

        // Table: evacuation_relief_logs (Relief Packs & Hot Meals Given to Evacuee Families)
        if (!in_array('evacuation_relief_logs', $existingTables, true)) {
            echo "  [+] Creating table `evacuation_relief_logs`...\n";
            $pdo->exec("
                CREATE TABLE `evacuation_relief_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `checkin_id` int(11) NOT NULL,
                  `evacuation_area_id` int(11) NOT NULL,
                  `household_id` int(11) NOT NULL,
                  `family_name` varchar(150) NOT NULL,
                  `item_type` varchar(100) NOT NULL DEFAULT 'Family Food Pack',
                  `quantity` int(11) NOT NULL DEFAULT 1,
                  `unit` varchar(50) NOT NULL DEFAULT 'packs',
                  `distributed_by` varchar(100) DEFAULT NULL,
                  `notes` varchar(255) DEFAULT NULL,
                  `created_at` datetime DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_erl_checkin` (`checkin_id`),
                  KEY `idx_erl_area` (`evacuation_area_id`),
                  CONSTRAINT `fk_erl_checkin` FOREIGN KEY (`checkin_id`) REFERENCES `evacuation_family_checkins` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $existingTables[] = 'evacuation_relief_logs';
        }

        // Table: hazard_type_options (Multi-purok hazard scoping)
        if (in_array('hazard_type_options', $existingTables, true)) {
            if (!$hasColumn($pdo, 'hazard_type_options', 'purok_id')) {
                echo "  [+] Adding column `purok_id` to `hazard_type_options`...\n";
                $pdo->exec("ALTER TABLE `hazard_type_options` ADD COLUMN `purok_id` int(11) DEFAULT NULL AFTER `id`;");
            }
            if (!$hasColumn($pdo, 'hazard_type_options', 'barangay_id')) {
                echo "  [+] Adding column `barangay_id` to `hazard_type_options`...\n";
                $pdo->exec("ALTER TABLE `hazard_type_options` ADD COLUMN `barangay_id` int(11) DEFAULT NULL AFTER `purok_id`;");
            }
        }

        // Table: resources (Support custom dynamic categories and supplier_id relationship)
        if (in_array('resources', $existingTables, true)) {
            try {
                $pdo->exec("ALTER TABLE `resources` MODIFY COLUMN `category` varchar(150) NOT NULL;");
            } catch (Throwable $e) {}

            $resCols = $pdo->query("DESCRIBE `resources`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('supplier_donor', $resCols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `resources` DROP COLUMN `supplier_donor`;");
                } catch (Throwable $e) {}
            }
            if (in_array('storage_location', $resCols, true)) {
                try {
                    echo "  [-] Dropping column `storage_location` from `resources`...\n";
                    $pdo->exec("ALTER TABLE `resources` DROP COLUMN `storage_location`;");
                } catch (Throwable $e) {
                    echo "  [!] Notice dropping storage_location: " . $e->getMessage() . "\n";
                }
            }
            if (!in_array('supplier_id', $resCols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `resources` ADD COLUMN `supplier_id` int(11) DEFAULT NULL AFTER `batch_number`;");
                    $pdo->exec("ALTER TABLE `resources` ADD KEY `supplier_id` (`supplier_id`);");
                    $pdo->exec("ALTER TABLE `resources` ADD CONSTRAINT `resources_ibfk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `resource_suppliers` (`id`) ON DELETE SET NULL;");
                } catch (Throwable $e) {}
            }
        }

        // Seed resource options tables if they were just created
        if (in_array('resource_categories', $existingTables, true)) {
            $catCount = (int)$pdo->query("SELECT COUNT(*) FROM `resource_categories`")->fetchColumn();
            if ($catCount === 0) {
                echo "  [+] Seeding default resource categories...\n";
                $catStmt = $pdo->prepare("INSERT IGNORE INTO `resource_categories` (`name`) VALUES (?)");
                foreach (['Relief Goods', 'Medical Supplies', 'Rescue Equipment', 'Emergency Supplies', 'Shelter & Sanitation'] as $c) {
                    $catStmt->execute([$c]);
                }
                $pdo->exec("INSERT IGNORE INTO `resource_categories` (`name`) SELECT DISTINCT TRIM(`category`) FROM `resources` WHERE `category` IS NOT NULL AND TRIM(`category`) != '';");
            }
        }

        if (in_array('resource_brands', $existingTables, true)) {
            $brandCount = (int)$pdo->query("SELECT COUNT(*) FROM `resource_brands`")->fetchColumn();
            if ($brandCount === 0) {
                echo "  [+] Seeding default resource brands...\n";
                $brandStmt = $pdo->prepare("INSERT IGNORE INTO `resource_brands` (`name`) VALUES (?)");
                foreach (['Generic / Unbranded', 'DSWD Relief', 'Honda', 'Yamaha', 'Stihl', '3M', 'Johnson & Johnson', 'Standard LGU', 'Lucky Me', 'Nescafe', 'Red Cross', 'NDRRMC', 'DOH', 'Local Supplier'] as $b) {
                    $brandStmt->execute([$b]);
                }
                $pdo->exec("INSERT IGNORE INTO `resource_brands` (`name`) SELECT DISTINCT TRIM(`brand`) FROM `resources` WHERE `brand` IS NOT NULL AND TRIM(`brand`) != '';");
            }
        }

        if (in_array('resource_suppliers', $existingTables, true)) {
            $suppCount = (int)$pdo->query("SELECT COUNT(*) FROM `resource_suppliers`")->fetchColumn();
            if ($suppCount === 0) {
                echo "  [+] Seeding default resource suppliers...\n";
                $suppStmt = $pdo->prepare("INSERT IGNORE INTO `resource_suppliers` (`name`) VALUES (?)");
                foreach (['LGU General Procurement', 'DSWD Regional Office', 'DOH Central Depot', 'Office of Civil Defense', 'Philippine Red Cross', 'Private Donor', 'Barangay Disaster Fund', 'City Disaster Risk Reduction and Management Office'] as $s) {
                    $suppStmt->execute([$s]);
                }
            }
        }

        // Table: agency_resources (Junction table linking agencies and static resources)
        if (!in_array('agency_resources', $existingTables, true)) {
            echo "  [+] Creating `agency_resources` junction table...\n";
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `agency_resources` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `agency_id` int(11) NOT NULL,
                  `resource_id` int(11) NOT NULL,
                  `total_quantity` int(11) NOT NULL DEFAULT 0,
                  `available_quantity` int(11) NOT NULL DEFAULT 0,
                  `in_use_quantity` int(11) NOT NULL DEFAULT 0,
                  `damaged_quantity` int(11) NOT NULL DEFAULT 0,
                  `min_threshold` int(11) NOT NULL DEFAULT 50,
                  `batch_number` varchar(50) DEFAULT NULL,
                  `supplier_id` int(11) DEFAULT NULL,
                  `date_acquired` date DEFAULT NULL,
                  `expiry_date` date DEFAULT NULL,
                  `cost_per_unit` decimal(10,2) DEFAULT NULL,
                  `item_condition` enum('New','Good','Fair','Poor','Expired') NOT NULL DEFAULT 'New',
                  `status` enum('active','archived') NOT NULL DEFAULT 'active',
                  `created_at` datetime DEFAULT current_timestamp(),
                  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `agency_id` (`agency_id`),
                  KEY `resource_id` (`resource_id`),
                  KEY `supplier_id` (`supplier_id`),
                  UNIQUE KEY `agency_resource_unique` (`agency_id`, `resource_id`),
                  CONSTRAINT `agency_resources_ibfk_1` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `agency_resources_ibfk_2` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `agency_resources_ibfk_3` FOREIGN KEY (`supplier_id`) REFERENCES `resource_suppliers` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $existingTables[] = 'agency_resources';
        }

        if (in_array('agency_resources', $existingTables, true)) {
            $arCount = (int)$pdo->query("SELECT COUNT(*) FROM `agency_resources`")->fetchColumn();
            if ($arCount === 0) {
                echo "  [+] Linking catalog resources into `agency_resources` for agencies...\n";
                $icdAgencyId = (int)$pdo->query("SELECT id FROM agencies WHERE agency_type = 'ICDRRMO' LIMIT 1")->fetchColumn() ?: 1;
                $pdo->exec("
                    INSERT IGNORE INTO `agency_resources` (
                        agency_id, resource_id, total_quantity, available_quantity, in_use_quantity,
                        damaged_quantity, min_threshold, batch_number, supplier_id, date_acquired,
                        expiry_date, cost_per_unit, item_condition, status
                    )
                    SELECT 
                        {$icdAgencyId}, id, total_quantity, available_quantity, in_use_quantity,
                        damaged_quantity, min_threshold, batch_number, supplier_id, date_acquired,
                        expiry_date, cost_per_unit, item_condition, status
                    FROM `resources`
                ");
            }
        }

        // Table: event_attendees (Support attendee archive status)
        if (in_array('event_attendees', $existingTables, true)) {
            if (!$hasColumn($pdo, 'event_attendees', 'is_archived')) {
                echo "  [+] Adding column `is_archived` to `event_attendees`...\n";
                $pdo->exec("ALTER TABLE `event_attendees` ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone`;");
            }
        }

        // Table: barangay_resource_requests (Delivery receipt tracking)
        if (in_array('barangay_resource_requests', $existingTables, true)) {
            if (!$hasColumn($pdo, 'barangay_resource_requests', 'receipt_status')) {
                echo "  [+] Adding column `receipt_status` to `barangay_resource_requests`...\n";
                $pdo->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `receipt_status` enum('Pending','Received','Not Received') NOT NULL DEFAULT 'Pending' AFTER `status`;");
            }
            if (!$hasColumn($pdo, 'barangay_resource_requests', 'received_at')) {
                echo "  [+] Adding column `received_at` to `barangay_resource_requests`...\n";
                $pdo->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `received_at` datetime DEFAULT NULL AFTER `receipt_status`;");
            }
            if (!$hasColumn($pdo, 'barangay_resource_requests', 'received_by')) {
                echo "  [+] Adding column `received_by` to `barangay_resource_requests`...\n";
                $pdo->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `received_by` int(11) DEFAULT NULL AFTER `received_at`;");
                try {
                    $pdo->exec("ALTER TABLE `barangay_resource_requests` ADD KEY `received_by` (`received_by`);");
                    $pdo->exec("ALTER TABLE `barangay_resource_requests` ADD CONSTRAINT `barangay_resource_requests_ibfk_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;");
                } catch (Throwable $e) {}
            }
            if (!$hasColumn($pdo, 'barangay_resource_requests', 'receipt_remarks')) {
                echo "  [+] Adding column `receipt_remarks` to `barangay_resource_requests`...\n";
                $pdo->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `receipt_remarks` text DEFAULT NULL AFTER `received_by`;");
            }
        }

        // Table: notifications (Support target purok scoping)
        if (in_array('notifications', $existingTables, true)) {
            if (!$hasColumn($pdo, 'notifications', 'target_puroks')) {
                echo "  [+] Adding column `target_puroks` to `notifications`...\n";
                $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `target_puroks` varchar(255) DEFAULT NULL AFTER `target_barangay_id`;");
            }
        }

        if ($seedOnly) {
            if (file_exists($seedsFile)) {
                echo "Step 3: Re-seeding database from seeds.sql (--seed)...\n";
                $executeSqlFile($pdo, $seedsFile, "Seed data");
            }
            $seedAllFile = $rootDir . '/database/seed_all.php';
            if (file_exists($seedAllFile)) {
                echo "Step 4: Populating master database records & households (seed_all.php)...\n";
                require $seedAllFile;
            }
        }
    }

    // Re-enable Foreign Key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // Fetch and display tables in database
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "========================================================\n";
    echo "🎉 SUCCESS: Database migration finished successfully!\n";
    echo "Total tables verified (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        echo "  - " . $t . "\n";
    }
    echo "Tip: Run 'C:\\xampp\\php\\php.exe migrate.php --fresh' (or 'php migrate.php --fresh') if you want a complete database reset.\n\n";

} catch (Throwable $e) {
    echo "\n[ERROR] Migration failed:\n" . $e->getMessage() . "\n\n";
    exit(1);
}
