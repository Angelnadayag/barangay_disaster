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

    if ($isFresh || empty($existingTables)) {
        // --------------------------------------------------------------------
        // FULL SCHEMA SETUP / FRESH RESET
        // --------------------------------------------------------------------
        echo ($isFresh ? "Step 1: Dropping and recreating tables (--fresh)...\n" : "Step 1: Initializing fresh database schema...\n");

        $schemaContent = file_get_contents($schemaFile);
        $pdo->exec($schemaContent);
        echo "[✓] Database schema initialized successfully!\n\n";

        if (file_exists($seedsFile)) {
            echo "Step 2: Importing seed data from seeds.sql...\n";
            $seedsContent = file_get_contents($seedsFile);

            // Clean TiDB incompatibilities
            $seedsContent = preg_replace('/LOCK TABLES `[^`]+` WRITE;/i', '', $seedsContent);
            $seedsContent = preg_replace('/UNLOCK TABLES;/i', '', $seedsContent);
            $seedsContent = preg_replace('/\/\*!40000 ALTER TABLE `[^`]+` (DISABLE|ENABLE) KEYS \*\/;/i', '', $seedsContent);

            $pdo->exec($seedsContent);
            echo "[✓] Seed data imported successfully!\n\n";
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
            // Update status enum if needed to support Archived
            try {
                $pdo->exec("ALTER TABLE `events` MODIFY COLUMN `status` enum('Scheduled','Ongoing','Completed','Cancelled','Archived') DEFAULT 'Scheduled';");
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

        // Table: disaster_reports (Geolocation & Street View Pinpoint)
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
        }

        // Table: recommendations (Support selection between resources, financial_aid, or both & funding sponsor)
        if (in_array('recommendations', $existingTables, true)) {
            if (!$hasColumn($pdo, 'recommendations', 'modality')) {
                echo "  [+] Adding column `modality` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `modality` enum('both','resources','financial_aid') NOT NULL DEFAULT 'both' AFTER `priority`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_source')) {
                echo "  [+] Adding column `financial_aid_source` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_source` varchar(150) NOT NULL DEFAULT 'LGU Calamity Fund (QRF)' AFTER `financial_aid_target_families`;");
            }
            if (!$hasColumn($pdo, 'recommendations', 'financial_aid_added_type')) {
                echo "  [+] Adding column `financial_aid_added_type` to `recommendations`...\n";
                $pdo->exec("ALTER TABLE `recommendations` ADD COLUMN `financial_aid_added_type` enum('ai_recommended','added_by_admin') NOT NULL DEFAULT 'ai_recommended' AFTER `financial_aid_source`;");
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
        if ($seedOnly && file_exists($seedsFile)) {
            echo "Step 3: Re-seeding database from seeds.sql (--seed)...\n";
            $seedsContent = file_get_contents($seedsFile);
            $seedsContent = preg_replace('/LOCK TABLES `[^`]+` WRITE;/i', '', $seedsContent);
            $seedsContent = preg_replace('/UNLOCK TABLES;/i', '', $seedsContent);
            $seedsContent = preg_replace('/\/\*!40000 ALTER TABLE `[^`]+` (DISABLE|ENABLE) KEYS \*\/;/i', '', $seedsContent);
            $pdo->exec($seedsContent);
            echo "[✓] Seed data applied successfully!\n\n";
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
    echo "========================================================\n";
    echo "Tip: Run 'railway run php migrate.php --fresh' if you want a complete database reset.\n\n";

} catch (Throwable $e) {
    echo "\n[ERROR] Migration failed:\n" . $e->getMessage() . "\n\n";
    exit(1);
}
