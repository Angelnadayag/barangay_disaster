<?php
// ============================================================================
// Function: Preparedness Activities - Schedule New Activity
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';
require_once __DIR__ . '/../../services/SmsService.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$defaultReturn = ($user['role'] === 'barangay_head') ? (BASE_URL . '/views/barangay/events.php') : (BASE_URL . '/views/icdrrmo/events.php');
$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? $defaultReturn);

if (!in_array($user['role'], ['icdrrmo', 'barangay_head'], true)) {
    redirectWithFlash($returnUrl, 'error', 'Unauthorized to schedule activities.');
}

$title = trim($_POST['title'] ?? '');
$type = trim($_POST['activity_type'] ?? 'Health and Medical');
$barangayId = !empty($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
if (empty($barangayId) && !empty($user['barangay_id'])) {
    $barangayId = (int)$user['barangay_id'];
}

// Venue handling from select or custom text input
$venue = trim($_POST['venue'] ?? ($_POST['venue_select'] ?? ''));
if ($venue === '__custom__' || empty($venue)) {
    $venue = trim($_POST['venue_custom'] ?? '');
}

$startDate = trim($_POST['start_datetime'] ?? '');
$endDate = trim($_POST['end_datetime'] ?? '');
$personnel = trim($_POST['assigned_personnel'] ?? '');
$target = max(1, (int)($_POST['target_participants'] ?? 50));
$description = trim($_POST['description'] ?? '');

// Target Puroks handling (single, multi, or 'All Puroks')
$targetPuroksInput = $_POST['target_puroks'] ?? 'All Puroks';
if (is_array($targetPuroksInput)) {
    $filteredPuroks = array_values(array_filter(array_map('trim', $targetPuroksInput)));
    if (empty($filteredPuroks) || in_array('All Puroks', $filteredPuroks, true)) {
        $targetPuroks = 'All Puroks';
    } else {
        $targetPuroks = implode(', ', $filteredPuroks);
    }
} else {
    $targetPuroks = trim((string)$targetPuroksInput);
    if ($targetPuroks === '' || $targetPuroks === 'All' || strcasecmp($targetPuroks, 'All Puroks') === 0) {
        $targetPuroks = 'All Puroks';
    }
}

// Process expected activities list as initial content execution
$contentExecutionInput = $_POST['content_execution'] ?? ($_POST['expected_activities'] ?? []);
if (is_array($contentExecutionInput)) {
    $contentExecutionJson = json_encode(array_values(array_filter($contentExecutionInput)), JSON_UNESCAPED_UNICODE);
} else {
    $contentExecutionJson = trim((string)$contentExecutionInput);
}

// Target Type handling (household_families vs all_members)
$targetType = trim($_POST['target_type'] ?? 'household_families');
if (!in_array($targetType, ['household_families', 'all_members'], true)) {
    $targetType = 'household_families';
}

if (empty($title) || empty($venue) || empty($startDate) || empty($endDate)) {
    redirectWithFlash($returnUrl, 'error', 'Please provide all required fields including venue.');
}

try {
    // Self-heal target_puroks, target_type, content_execution & expected_activities columns if missing
    $colsTp = $db->query("SHOW COLUMNS FROM events LIKE 'target_puroks'")->fetchAll();
    if (empty($colsTp)) {
        $db->exec("ALTER TABLE events ADD COLUMN target_puroks VARCHAR(255) NOT NULL DEFAULT 'All Puroks' AFTER venue");
    }
    $colsTt = $db->query("SHOW COLUMNS FROM events LIKE 'target_type'")->fetchAll();
    if (empty($colsTt)) {
        $db->exec("ALTER TABLE events ADD COLUMN target_type VARCHAR(50) NOT NULL DEFAULT 'household_families' AFTER target_puroks");
    }
    $colsCe = $db->query("SHOW COLUMNS FROM events LIKE 'content_execution'")->fetchAll();
    if (empty($colsCe)) {
        $db->exec("ALTER TABLE events ADD COLUMN content_execution TEXT NULL AFTER evaluation_summary");
    }
    $colsEa = $db->query("SHOW COLUMNS FROM events LIKE 'expected_activities'")->fetchAll();
    if (empty($colsEa)) {
        $db->exec("ALTER TABLE events ADD COLUMN expected_activities TEXT NULL AFTER content_execution");
    }

    // Look up coordinates from evacuation areas, puroks, or barangays
    $eventLat = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $eventLng = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    if (!$eventLat || !$eventLng) {
        $evacCoordStmt = $db->prepare("SELECT coordinates_lat, coordinates_lng FROM evacuation_areas WHERE (barangay_id = ? OR barangay_id IS NULL) AND name = ? LIMIT 1");
        $evacCoordStmt->execute([$barangayId, $venue]);
        $evacCoord = $evacCoordStmt->fetch(PDO::FETCH_ASSOC);
        if ($evacCoord && $evacCoord['coordinates_lat'] && $evacCoord['coordinates_lng']) {
            $eventLat = (float)$evacCoord['coordinates_lat'];
            $eventLng = (float)$evacCoord['coordinates_lng'];
        } else {
            $pCoordStmt = $db->prepare("SELECT coordinates_lat, coordinates_lng FROM puroks WHERE barangay_id = ? AND ? LIKE CONCAT('%', name, '%') LIMIT 1");
            $pCoordStmt->execute([$barangayId, $venue]);
            $pCoord = $pCoordStmt->fetch(PDO::FETCH_ASSOC);
            if ($pCoord) {
                $eventLat = $pCoord['coordinates_lat'];
                $eventLng = $pCoord['coordinates_lng'];
            } else {
                $bCoordStmt = $db->prepare("SELECT coordinates_lat, coordinates_lng FROM barangays WHERE id = ?");
                $bCoordStmt->execute([$barangayId]);
                $bCoord = $bCoordStmt->fetch(PDO::FETCH_ASSOC);
                if ($bCoord) {
                    $eventLat = $bCoord['coordinates_lat'];
                    $eventLng = $bCoord['coordinates_lng'];
                }
            }
        }
    }

    $initialStatus = (strtotime($startDate) <= time()) ? 'Ongoing' : 'Scheduled';

    $stmt = $db->prepare("
        INSERT INTO events (
            title, activity_type, barangay_id, venue, target_puroks, target_type, latitude, longitude, start_datetime, end_datetime,
            assigned_personnel, target_participants, actual_participants, description,
            status, content_execution, expected_activities, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $title, $type, $barangayId, $venue, $targetPuroks, $targetType, $eventLat, $eventLng, $startDate, $endDate,
        $personnel, $target, $description, $initialStatus, $contentExecutionJson, $contentExecutionJson, $user['id']
    ]);
    $actId = $db->lastInsertId();

    // Auto-sync into financial_aid_allocations if it's a Social Welfare / Financial Aid event
    syncEventFinancialAidAllocation($db, $actId, $barangayId);

    // Broadcast in-app notification for this activity to barangay residents and responders (strictly scoped by target puroks)
    $colsTpNotif = $db->query("SHOW COLUMNS FROM notifications LIKE 'target_puroks'")->fetchAll();
    if (empty($colsTpNotif)) {
        $db->exec("ALTER TABLE notifications ADD COLUMN target_puroks VARCHAR(255) NULL AFTER target_barangay_id");
    }
    $notifCategory = determineEventNotificationCategory($type, $contentExecutionInput);
    if ($notifCategory === 'financial_aid') {
        $notifTitle = "Financial Aid: $title";
        $catLabel = "Financial Aid";
    } elseif ($notifCategory === 'resources') {
        $notifTitle = "Resources: $title";
        $catLabel = "Relief & Resources";
    } else {
        $notifTitle = "Community Event: $title";
        $catLabel = "Community Event";
    }

    $notif = $db->prepare("
        INSERT INTO notifications (target_role, target_barangay_id, target_puroks, title, message, alert_level, related_module, related_id, created_by, created_at)
        VALUES ('all', ?, ?, ?, ?, 'informational', ?, ?, ?, NOW())
    ");
    $scopeDesc = ($targetPuroks === 'All Puroks') ? "Barangay-wide" : "Target Puroks: $targetPuroks";
    $notifMsg = "Barangay notice: $title ($type) scheduled at $venue on " . date('M d, Y h:i A', strtotime($startDate)) . " ($scopeDesc).";
    $notif->execute([$barangayId, $targetPuroks, $notifTitle, $notifMsg, $notifCategory, $actId, $user['id']]);

    // Send SMS alerts to residents of this barangay (filtered by target puroks if specified)
    $smsSentCount = 0;
    if ($barangayId) {
        $brgyStmt = $db->prepare("SELECT name FROM barangays WHERE id = ?");
        $brgyStmt->execute([$barangayId]);
        $brgyRow = $brgyStmt->fetch(PDO::FETCH_ASSOC);
        $brgyName = !empty($brgyRow['name']) ? ucwords(strtolower(trim($brgyRow['name']))) : '';
        $committeeHeader = $brgyName 
            ? "BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE ($brgyName)"
            : "BARANGAY DISASTER RISK REDUCTION AND MANAGEMENT COMMITTEE";

        $resSql = "
            SELECT id, first_name, last_name, full_name, phone, purok 
            FROM residents 
            WHERE barangay_id = ? AND phone IS NOT NULL AND TRIM(phone) != '' AND status != 'archived'
        ";
        $resParams = [$barangayId];

        if ($targetPuroks !== 'All Puroks') {
            $purokList = array_values(array_filter(array_map('trim', explode(',', $targetPuroks))));
            if (!empty($purokList)) {
                $placeholders = implode(',', array_fill(0, count($purokList), '?'));
                $resSql .= " AND purok IN ($placeholders)";
                $resParams = array_merge($resParams, $purokList);
            }
        }

        $resStmt = $db->prepare($resSql);
        $resStmt->execute($resParams);
        $residents = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        $fStart = date('M d, Y h:i A', strtotime($startDate));
        $fEnd = date('M d, Y h:i A', strtotime($endDate));
        $lead = $personnel ?: 'Barangay Disaster Team';

        foreach ($residents as $r) {
            $rName = clean($r['full_name'] ?: ($r['first_name'] . ' ' . $r['last_name']));
            $smsText = $committeeHeader . "\n"
                . "Hi $rName!\n"
                . "A new event has been scheduled in your barangay:\n"
                . "Event: $title\n"
                . "Target Scope: $targetPuroks\n"
                . "Venue: $venue\n"
                . "Start: $fStart\n"
                . "End: $fEnd\n"
                . "Lead Facilitator: $lead\n"
                . "Details: " . ($description ?: 'Community preparedness activity.') . "\n"
                . "Please be guided accordingly. Thank you!";

            SmsService::send($r['phone'], $smsText, $rName, 'event_created', $actId);
            $smsSentCount++;
        }
    }

    logSystemEvent('SCHEDULE_ACTIVITY', 'Preparedness', "Scheduled activity $title ($type, Scope: $targetPuroks) and sent SMS notices to $smsSentCount residents.");

    $flashMsg = "Preparedness activity scheduled successfully." . ($smsSentCount > 0 ? " SMS alerts sent to $smsSentCount resident(s)." : "");
    redirectWithFlash($returnUrl, 'success', $flashMsg);
} catch (Exception $e) {
    redirectWithFlash($returnUrl, 'error', 'Database Error: ' . $e->getMessage());
}
