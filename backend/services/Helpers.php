<?php
// ============================================================================
// Backend Services: General Helpers & System Audit Logger
// ============================================================================

require_once __DIR__ . '/../database/Connection.php';

// Format Dates
function formatDate($datetime, $format = 'M d, Y h:i A') {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '—';
    try {
        $dt = new DateTime($datetime);
        return $dt->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

// Sanitize string
function clean($str) {
    return htmlspecialchars(trim((string)$str), ENT_QUOTES, 'UTF-8');
}

// Render status badges with semantic colors
function renderStatusBadge($status) {
    $statusMap = [
        'active' => ['class' => 'badge-success', 'label' => 'Active'],
        'inactive' => ['class' => 'badge-danger', 'label' => 'Deactivated'],
        'deactivated' => ['class' => 'badge-danger', 'label' => 'Deactivated'],
        'pending' => ['class' => 'badge-warning', 'label' => 'Pending'],
        'archived' => ['class' => 'badge-neutral', 'label' => 'Archived'],
        'Submitted' => ['class' => 'badge-info', 'label' => 'Submitted'],
        'Under Review' => ['class' => 'badge-warning', 'label' => 'Under Review'],
        'Recommendation Ready' => ['class' => 'badge-info', 'label' => 'Recommendation Ready'],
        'Approved' => ['class' => 'badge-success', 'label' => 'Approved'],
        'Allocated' => ['class' => 'badge-success', 'label' => 'Allocated'],
        'Dispatched' => ['class' => 'badge-info', 'label' => 'Dispatched'],
        'Completed' => ['class' => 'badge-success', 'label' => 'Completed'],
        'Rejected' => ['class' => 'badge-danger', 'label' => 'Rejected'],
        'Pending Review' => ['class' => 'badge-warning', 'label' => 'Pending Review'],
        'Modified' => ['class' => 'badge-info', 'label' => 'Modified & Approved'],
        'Open / Active' => ['class' => 'badge-danger', 'label' => 'Open / Active'],
        'Standby' => ['class' => 'badge-neutral', 'label' => 'Standby'],
        'At Capacity' => ['class' => 'badge-danger', 'label' => 'At Capacity'],
        'Closed' => ['class' => 'badge-neutral', 'label' => 'Closed'],
        'Scheduled' => ['class' => 'badge-info', 'label' => 'Scheduled'],
        'Ongoing' => ['class' => 'badge-warning', 'label' => 'Ongoing'],
        'Cancelled' => ['class' => 'badge-danger', 'label' => 'Cancelled'],
        'Available' => ['class' => 'badge-success', 'label' => 'Available'],
        'Responding' => ['class' => 'badge-warning', 'label' => 'Responding'],
        'On Site' => ['class' => 'badge-danger', 'label' => 'On Site'],
        'Low' => ['class' => 'badge-neutral', 'label' => 'Low'],
        'Moderate' => ['class' => 'badge-warning', 'label' => 'Moderate'],
        'High' => ['class' => 'badge-warning', 'label' => 'High'],
        'Critical' => ['class' => 'badge-danger', 'label' => 'Critical'],
        'Immediate' => ['class' => 'badge-danger', 'label' => 'Immediate']
    ];

    if (empty($status)) {
        return '<span class="badge badge-neutral" style="color:var(--color-text-muted);font-style:italic;">Unassigned</span>';
    }

    $badge = $statusMap[$status] ?? ['class' => 'badge-neutral', 'label' => clean($status)];
    return '<span class="badge ' . $badge['class'] . '">' . $badge['label'] . '</span>';
}

// Format Role Name
function formatRoleName($role) {
    switch ($role) {
        case 'icdrrmo':
            return 'ICDRRMO Admin';
        case 'barangay_head':
            return 'Barangay Head';
        case 'responder':
            return 'Responder / Rescuer';
        case 'resident':
            return 'Resident';
        default:
            return ucwords(str_replace('_', ' ', $role));
    }
}

// Immutable System Event Logger
function logSystemEvent($action, $module, $details, $userId = null, $userName = null, $role = null) {
    try {
        $db = getDBConnection();
        if ($userId === null && isset($_SESSION['user']['id'])) {
            $userId = $_SESSION['user']['id'];
            $userName = $_SESSION['user']['full_name'];
            $role = $_SESSION['user']['role'];
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $db->prepare("
            INSERT INTO system_logs (user_id, user_name, role, action, module, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $userName ?? 'System',
            $role ?? 'system',
            $action,
            $module,
            $details,
            $ip
        ]);
    } catch (Exception $e) {
        error_log("Failed to write system audit event: " . $e->getMessage());
    }
}

// Redirect with flash message (Traditional PHP form handling)
function redirectWithFlash($url, $status, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($status === 'success') {
        $_SESSION['flash_success'] = $message;
    } else {
        $_SESSION['flash_error'] = $message;
    }

    // Fallback if client is sending AJAX/JSON
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
              || (!empty($_POST['is_ajax']) || !empty($_GET['is_ajax']));

    if ($isAjax) {
        jsonResponse([
            'success' => ($status === 'success'),
            'message' => $message,
            'redirect' => $url
        ], ($status === 'success' ? 200 : 400));
    }

    header("Location: " . $url);
    exit;
}
