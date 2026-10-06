<?php
// ============================================================================
// Intelligent Database Migration Script
// Compatible with: Local XAMPP, Railway CLI, and TiDB Cloud Serverless
//
// Usage:
//   railway run php database/migrate.php   (Migrates your remote TiDB database)
//   php database/migrate.php               (Migrates your local MySQL database)
// ============================================================================

require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/database/Connection.php';

echo "\n========================================================\n";
echo " Barangay Disaster System - Database Migration\n";
echo "========================================================\n";
echo "Host:     " . DB_HOST . ":" . DB_PORT . "\n";
echo "Database: " . DB_NAME . "\n";
echo "User:     " . DB_USER . "\n";
echo "SSL:      " . (DB_SSL_ENABLE ? "Enabled (TLS)" : "Disabled") . "\n";
echo "--------------------------------------------------------\n";
echo "Connecting to database...\n";

try {
    $pdo = getDBConnection();
    // Enable multi-statement execution if supported
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    echo "[✓] Connected successfully!\n\n";

    // Disable Foreign Key checks during migration
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    $schemaFile = __DIR__ . '/schema.sql';
    $seedsFile  = __DIR__ . '/seeds.sql';

    if (!file_exists($schemaFile)) {
        die("[ERROR] schema.sql not found at: {$schemaFile}\n");
    }

    echo "Step 1: Running schema.sql...\n";
    $schemaContent = file_get_contents($schemaFile);
    // Execute schema
    $pdo->exec($schemaContent);
    echo "[✓] Database schema created successfully!\n\n";

    if (file_exists($seedsFile)) {
        echo "Step 2: Importing seed data from seeds.sql...\n";
        $seedsContent = file_get_contents($seedsFile);
        
        // Strip LOCK TABLES / UNLOCK TABLES for TiDB Cloud compatibility
        $seedsContent = preg_replace('/LOCK TABLES `[^`]+` WRITE;/i', '', $seedsContent);
        $seedsContent = preg_replace('/UNLOCK TABLES;/i', '', $seedsContent);
        $seedsContent = preg_replace('/\/\*!40000 ALTER TABLE `[^`]+` (DISABLE|ENABLE) KEYS \*\/;/i', '', $seedsContent);

        $pdo->exec($seedsContent);
        echo "[✓] Seed data imported successfully!\n\n";
    }

    // Re-enable Foreign Key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // Fetch and display tables created
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "========================================================\n";
    echo "🎉 SUCCESS: Database migration finished successfully!\n";
    echo "Total tables in database (" . count($tables) . "):\n";
    foreach ($tables as $idx => $table) {
        echo "  - " . $table . "\n";
    }
    echo "========================================================\n\n";

} catch (Throwable $e) {
    echo "\n[ERROR] Migration failed:\n" . $e->getMessage() . "\n\n";
    exit(1);
}
