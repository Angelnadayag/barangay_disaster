<?php
// ============================================================================
// Backend Functions: Evacuation - Distribute Relief to Sheltered Family
// Logs food pack, hygiene kit, or hot meal disbursements per sheltered family,
// auto-increments relief ledger counts, and logs audit trail.
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/barangay/evacuation.php'));

if (!in_array($user['role'], ['barangay_head', 'icdrrmo', 'responder'], true)) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid request method.');
}

$checkinId = (int)($_POST['checkin_id'] ?? 0);
$itemType = trim($_POST['item_type'] ?? 'Family Food Pack');
$quantity = max(1, (int)($_POST['quantity'] ?? 1));
$unit = trim($_POST['unit'] ?? 'packs');
$notes = trim($_POST['notes'] ?? '');

if ($checkinId <= 0) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Invalid check-in record ID.'], 400);
    }
    redirectWithFlash($returnUrl, 'error', 'Invalid check-in record ID.');
}

try {
    // 1. Fetch check-in record
    $chkStmt = $db->prepare("
        SELECT c.*, ea.name AS center_name, ea.barangay_id
        FROM evacuation_family_checkins c
        JOIN evacuation_areas ea ON c.evacuation_area_id = ea.id
        WHERE c.id = ?
    ");
    $chkStmt->execute([$checkinId]);
    $checkin = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$checkin) {
        throw new Exception("Sheltered family check-in record not found.");
    }

    if ($user['role'] === 'barangay_head' && (int)$checkin['barangay_id'] !== (int)$user['barangay_id']) {
        throw new Exception("You are not authorized to distribute relief outside your barangay jurisdiction.");
    }

    $distBy = $user['full_name'] ?? 'Camp Relief Officer';

    // 2. Insert into evacuation_relief_logs
    $logStmt = $db->prepare("
        INSERT INTO evacuation_relief_logs (
            checkin_id, evacuation_area_id, household_id, family_name,
            item_type, quantity, unit, distributed_by, notes, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, NOW()
        )
    ");
    $logStmt->execute([
        $checkinId, (int)$checkin['evacuation_area_id'], (int)$checkin['household_id'],
        $checkin['family_name'], $itemType, $quantity, $unit, $distBy, $notes
    ]);

    // 3. Increment counters in evacuation_family_checkins
    $isMeal = stripos($itemType, 'meal') !== false || stripos($itemType, 'soup') !== false;
    if ($isMeal) {
        $updStmt = $db->prepare("UPDATE evacuation_family_checkins SET hot_meals_claimed = hot_meals_claimed + ? WHERE id = ?");
        $updStmt->execute([$quantity, $checkinId]);
    } else {
        $updStmt = $db->prepare("UPDATE evacuation_family_checkins SET relief_packs_given = relief_packs_given + ? WHERE id = ?");
        $updStmt->execute([$quantity, $checkinId]);
    }

    // 4. Optionally decrement camp resource stock if matching item found in evacuation_center_resources
    try {
        $resMatch = $db->prepare("
            SELECT id, quantity FROM evacuation_center_resources 
            WHERE evacuation_area_id = ? AND resource_name LIKE ? AND quantity >= ?
            LIMIT 1
        ");
        $resMatch->execute([(int)$checkin['evacuation_area_id'], "%$itemType%", $quantity]);
        $campRes = $resMatch->fetch(PDO::FETCH_ASSOC);
        if ($campRes) {
            $deductStmt = $db->prepare("UPDATE evacuation_center_resources SET quantity = quantity - ? WHERE id = ?");
            $deductStmt->execute([$quantity, (int)$campRes['id']]);
        }
    } catch (Throwable $t) {
        // Continue even if stock deduction fails
    }

    logSystemEvent(
        'DISTRIBUTE_EVACUEE_RELIEF',
        'Evacuation',
        "Distributed {$quantity} {$unit} of {$itemType} to {$checkin['family_name']} ({$checkin['household_code']}) at {$checkin['center_name']}."
    );

    $msg = "Distributed {$quantity} {$unit} of {$itemType} to {$checkin['family_name']} successfully!";

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $msg,
            'checkin_id' => $checkinId,
            'new_relief_packs' => (int)$checkin['relief_packs_given'] + ($isMeal ? 0 : $quantity),
            'new_meals' => (int)$checkin['hot_meals_claimed'] + ($isMeal ? $quantity : 0)
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $msg);
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    redirectWithFlash($returnUrl, 'error', $e->getMessage());
}
