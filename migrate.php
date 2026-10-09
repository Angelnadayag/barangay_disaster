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

        // Table: preparedness_activities
        if (in_array('preparedness_activities', $existingTables, true)) {
            if (!$hasColumn($pdo, 'preparedness_activities', 'is_archived')) {
                echo "  [+] Adding column `is_archived` to `preparedness_activities`...\n";
                $pdo->exec("ALTER TABLE `preparedness_activities` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`;");
            }
            if (!$hasColumn($pdo, 'preparedness_activities', 'content_execution')) {
                echo "  [+] Adding column `content_execution` to `preparedness_activities`...\n";
                $pdo->exec("ALTER TABLE `preparedness_activities` ADD COLUMN `content_execution` text DEFAULT NULL AFTER `evaluation_summary`;");
            }
            if (!$hasColumn($pdo, 'preparedness_activities', 'expected_activities')) {
                echo "  [+] Adding column `expected_activities` to `preparedness_activities`...\n";
                $pdo->exec("ALTER TABLE `preparedness_activities` ADD COLUMN `expected_activities` text DEFAULT NULL AFTER `content_execution`;");
            }
            if (!$hasColumn($pdo, 'preparedness_activities', 'cancellation_reason')) {
                echo "  [+] Adding column `cancellation_reason` to `preparedness_activities`...\n";
                $pdo->exec("ALTER TABLE `preparedness_activities` ADD COLUMN `cancellation_reason` text DEFAULT NULL AFTER `expected_activities`;");
            }
            // Update status enum if needed to support Archived
            try {
                $pdo->exec("ALTER TABLE `preparedness_activities` MODIFY COLUMN `status` enum('Scheduled','Ongoing','Completed','Cancelled','Archived') DEFAULT 'Scheduled';");
            } catch (Throwable $e) {}
        }

        // Table: activity_attendees
        if (in_array('activity_attendees', $existingTables, true)) {
            if (!$hasColumn($pdo, 'activity_attendees', 'attended')) {
                echo "  [+] Adding column `attended` to `activity_attendees`...\n";
                $pdo->exec("ALTER TABLE `activity_attendees` ADD COLUMN `attended` tinyint(1) NOT NULL DEFAULT 1 AFTER `resident_name`;");
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

        // Optional seeding if requested
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
