<?php
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/database/Connection.php';

$db = getDBConnection();
$check = (int)$db->query("SELECT COUNT(*) FROM disaster_reports")->fetchColumn();

if ($check === 0) {
    $stmt = $db->prepare("
        INSERT INTO disaster_reports (
            tracking_code, barangay_id, purok_name, coordinates_lat, coordinates_lng, incident_location,
            disaster_type, severity, urgency,
            affected_families, affected_individuals, displaced_families,
            casualties_count, injuries_count, missing_count,
            requested_assistance, situation_overview, status, submitted_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        'REQ-2026-001', 12, 'Purok Riverside 1', 8.248100, 124.261200,
        'Along Mandulog River Dike near Footbridge',
        'Flash Flood', 'Critical', 'Immediate',
        45, 180, 45,
        0, 2, 0,
        '100 Food Packs, 2 Rescue Rubber Boats, First Aid Kits',
        'Mandulog river water level breached secondary embankment. 45 families evacuated to Hinaplanon National High School.',
        'Ongoing', 2
    ]);

    $stmt->execute([
        'REQ-2026-002', 12, 'Purok San Miguel', 8.244200, 124.257500,
        'San Miguel Main Access Road, Purok 3',
        'Flood', 'High', 'High',
        28, 112, 10,
        0, 0, 0,
        '50 Relief Family Packs, Sandbags for water diversion',
        'Knee-deep urban flooding due to blocked main canal drainage. Road impassable to light vehicles.',
        'Ongoing', 2
    ]);

    echo "Sample reports inserted successfully.\n";
} else {
    echo "Reports already exist in database.\n";
}
