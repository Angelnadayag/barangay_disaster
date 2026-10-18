<?php
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/database/Connection.php';

$pdo = getDBConnection();
$tables = [
    'agencies',
    'barangays',
    'purok_cluster_options',
    'hazard_type_options',
    'resource_categories',
    'resource_brands',
    'resource_suppliers',
    'resources',
    'agency_resources',
    'users',
    'residents',
    'puroks',
    'evacuation_areas',
    'evacuation_center_resources',
    'disaster_reports',
    'barangay_resource_requests',
    'recommendations',
    'recommended_items',
    'financial_aid_allocations',
    'financial_aid_distributions',
    'financial_aid_audits',
    'events',
    'event_attendees',
    'notifications',
    'notification_reads',
    'responder_field_updates',
    'resource_transactions',
    'system_logs',
    'sms_logs'
];

$output = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
$output .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
$output .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
$output .= "/*!40101 SET NAMES utf8mb4 */;\n";
$output .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n";
$output .= "/*!40103 SET TIME_ZONE='+00:00' */;\n";
$output .= "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n";
$output .= "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
$output .= "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n";

foreach ($tables as $table) {
    $stmt = $pdo->query("SELECT * FROM `$table`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        continue;
    }

    $output .= "TRUNCATE TABLE `$table`;\n";
    $output .= "LOCK TABLES `$table` WRITE;\n";
    $output .= "/*!40000 ALTER TABLE `$table` DISABLE KEYS */;\n";

    $cols = array_keys($rows[0]);
    $escapedCols = array_map(function($c) { return "`$c`"; }, $cols);
    $colList = implode(', ', $escapedCols);

    // Group rows into chunks of 50 to prevent huge single queries
    $chunks = array_chunk($rows, 50);
    foreach ($chunks as $chunk) {
        $valRows = [];
        foreach ($chunk as $row) {
            $vals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $vals[] = 'NULL';
                } elseif (is_numeric($val) && !preg_match('/^0[0-9]+/', $val)) {
                    $vals[] = $val;
                } else {
                    $vals[] = $pdo->quote($val);
                }
            }
            $valRows[] = "(" . implode(',', $vals) . ")";
        }
        $output .= "INSERT INTO `$table` ($colList) VALUES \n" . implode(",\n", $valRows) . ";\n";
    }

    $output .= "/*!40000 ALTER TABLE `$table` ENABLE KEYS */;\n";
    $output .= "UNLOCK TABLES;\n\n";
}

$output .= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n";
$output .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";
$output .= "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n";
$output .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
$output .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
$output .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
$output .= "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n";

file_put_contents(__DIR__ . '/seeds.sql', $output);
echo "Successfully updated database/seeds.sql (" . strlen($output) . " bytes)!\n";
