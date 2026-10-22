<?php
// ============================================================================
// Function: Responder - Quick Operational Status Update & Mission Response
// Enforces single active response constraint (no multiple simultaneous responses)
// ============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../database/Connection.php';
require_once __DIR__ . '/../../services/Auth.php';
require_once __DIR__ . '/../../services/Helpers.php';

requireLogin();
$user = getCurrentUser();
$db = getDBConnection();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
    || isset($_POST['ajax']);

$returnUrl = $_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/views/responder/dashboard.php'));

if ($user['role'] !== 'responder') {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    }
    redirectWithFlash($returnUrl, 'error', 'Unauthorized.');
}

$action = trim($_POST['action'] ?? '');
$rawStatus = trim($_POST['status'] ?? '');
$requestId = !empty($_POST['disaster_request_id']) ? (int)$_POST['disaster_request_id'] : null;

// Determine target operational status
if ($action === 'respond') {
    $status = 'On Duty';
} elseif ($action === 'cancel' || $action === 'finish') {
    $status = 'Available';
} elseif (!empty($rawStatus)) {
    $status = in_array($rawStatus, ['Responding', 'On Duty'], true) ? 'On Duty' : $rawStatus;
} else {
    $status = 'Available';
}

$purok = trim($_POST['purok'] ?? ($user['purok'] ?? 'Hinaplanon Base'));
$barangayId = (int)($user['barangay_id'] ?? 0);
if ($barangayId <= 0) {
    $bStmt = $db->prepare("SELECT barangay_id FROM users WHERE id = ?");
    $bStmt->execute([(int)$user['id']]);
    $barangayId = (int)$bStmt->fetchColumn() ?: 12;
}

try {
    // Enforce: 1 respond only can be active by the responder (multiple simultaneous responses not allowed)
    if (in_array($status, ['On Duty', 'Responding'], true)) {
        if (!$requestId) {
            if ($isAjax) {
                jsonResponse(['success' => false, 'message' => 'Incident ID required to respond.'], 400);
            }
            redirectWithFlash($returnUrl, 'error', 'Incident ID required.');
        }

        $checkStmt = $db->prepare("
            SELECT disaster_request_id, operational_status 
            FROM responder_field_updates 
            WHERE responder_id = ? 
            ORDER BY created_at DESC, id DESC 
            LIMIT 1
        ");
        $checkStmt->execute([(int)$user['id']]);
        $last = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($last && in_array($last['operational_status'], ['On Duty', 'Responding'], true) && !empty($last['disaster_request_id'])) {
            if ((int)$last['disaster_request_id'] !== $requestId) {
                if ($isAjax) {
                    jsonResponse([
                        'success' => false,
                        'message' => 'You already have an active response mission. Finish or cancel it before responding to another incident.'
                    ], 400);
                }
                redirectWithFlash($returnUrl, 'error', 'You already have an active response mission.');
            }
        }
    }

    // Set saved request ID based on status
    $savedRequestId = in_array($status, ['On Duty', 'Responding'], true) ? $requestId : null;

    if ($action === 'cancel') {
        $remarks = "Cancelled response for incident #$requestId";
    } elseif ($action === 'finish') {
        $remarks = "Finished response mission for incident #$requestId";
    } elseif ($action === 'respond') {
        $remarks = "Accepted response mission for incident #$requestId";
    } else {
        $remarks = $savedRequestId ? "Mission response for incident #$savedRequestId: $status" : "Operational status switcher: $status";
    }

    $stmt = $db->prepare("
        INSERT INTO responder_field_updates (
            responder_id, disaster_request_id, barangay_id, purok, operational_status, condition_overview,
            casualties, injuries, rescued_individuals, resources_utilized, remarks, created_at
        ) VALUES (?, ?, ?, ?, ?, 'Operational mission status toggle.', 0, 0, 0, 'Standard equipment', ?, NOW())
    ");
    $stmt->execute([$user['id'], $savedRequestId, $barangayId, $purok, $status, $remarks]);

    logSystemEvent('FIELD_STATUS_TOGGLE', 'Responder Field', "Responder {$user['full_name']} status: $status" . ($savedRequestId ? " (Incident #$savedRequestId)" : ''));

    $isOnDuty = in_array($status, ['On Duty', 'Responding'], true);

    if ($isAjax) {
        jsonResponse([
            'success' => true,
            'message' => $isOnDuty ? "Operational status: On Duty" : "Operational status: Available",
            'status' => $status,
            'is_on_duty' => $isOnDuty,
            'active_incident_id' => $savedRequestId
        ]);
    }

    redirectWithFlash($returnUrl, 'success', $isOnDuty ? "Operational status: On Duty." : "Operational status: Available.");
} catch (Exception $e) {
    if ($isAjax) {
        jsonResponse(['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()], 500);
    }
    redirectWithFlash($returnUrl, 'error', 'Failed to update status: ' . $e->getMessage());
}
