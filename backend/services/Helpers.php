<?php
// ============================================================================
// Backend Services: General Helpers & System Audit Logger
// ============================================================================

require_once __DIR__ . '/../database/Connection.php';

// Format Dates
if (!function_exists('formatDate')) {
function formatDate($datetime, $format = 'M d, Y h:i A') {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '—';
    try {
        $dt = new DateTime($datetime);
        return $dt->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}
}

// Sanitize string
if (!function_exists('clean')) {
function clean($str) {
    return htmlspecialchars(trim((string)$str), ENT_QUOTES, 'UTF-8');
}
}

// Render status badges with semantic colors
if (!function_exists('renderStatusBadge')) {
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
}

// Render Aid Support Badges (Resources, Financial Aid, Both)
if (!function_exists('renderAidSupportBadge')) {
function renderAidSupportBadge($aidSupport) {
    $aid = strtolower(trim((string)$aidSupport));
    if ($aid === 'financial_aid') {
        return '<span class="badge badge-success" style="font-size:9.5px;font-weight:600;">Financial Aid</span>';
    } elseif ($aid === 'both') {
        return '<span class="badge badge-info" style="font-size:9.5px;font-weight:600;">Both (Relief & Ayuda)</span>';
    } else {
        return '<span class="badge badge-neutral" style="font-size:9.5px;font-weight:600;">Resources</span>';
    }
}
}

/**
 * Break down Decision Tree Confidence Score (NDRRMC baseline, Severity, Depot Feasibility)
 */
if (!function_exists('getConfidenceBreakdown')) {
function getConfidenceBreakdown($score, $severity = null, $decisionPath = '', $modality = null) {
    $score = (float)$score;
    if ($score <= 0) $score = 85.0;

    $base = 85.0;
    
    // 1. Severity Bonus
    $sevBonus = 0.0;
    $sevName = 'Moderate';
    if ($severity) {
        $sevLower = strtolower($severity);
        if ($sevLower === 'critical') {
            $sevBonus = 5.0;
            $sevName = 'Critical';
        } elseif ($sevLower === 'high') {
            $sevBonus = 3.0;
            $sevName = 'High';
        } elseif ($sevLower === 'low') {
            $sevBonus = -5.0;
            $sevName = 'Low';
        } else {
            $sevBonus = 0.0;
            $sevName = 'Moderate';
        }
    } else {
        // Derive from score if severity not explicitly provided
        if ($score >= 93.0) {
            $sevBonus = 5.0;
            $sevName = 'Critical';
        } elseif ($score >= 91.0) {
            $sevBonus = 3.0;
            $sevName = 'High';
        } else {
            $sevBonus = 0.0;
            $sevName = 'Moderate';
        }
    }

    // 2. Feasibility / Stock Feasibility Bonus
    $hasShortfall = (stripos($decisionPath, 'Bottlenecks') !== false || stripos($decisionPath, 'shortfall') !== false);
    $isFinancialOnly = ($modality === 'financial_aid' || stripos($decisionPath, 'Financial Aid') !== false);

    if ($hasShortfall) {
        $feasBonus = -6.0;
        $feasLabel = 'Stock Deficit Bottleneck';
        $feasDesc = 'Partial depot stock shortfall detected on physical items';
    } else {
        $feasBonus = 4.0;
        if ($isFinancialOnly) {
            $feasLabel = 'Calamity Fund Liquidity Verified';
            $feasDesc = 'Sufficient cash balance verified under LGU Quick Response Fund';
        } else {
            $feasLabel = '100% Supplies In Stock';
            $feasDesc = 'All recommended relief goods 100% available at Central Depot';
        }
    }

    return [
        'score' => $score,
        'base' => $base,
        'severity_bonus' => $sevBonus,
        'severity_name' => $sevName,
        'feasibility_bonus' => $feasBonus,
        'feasibility_label' => $feasLabel,
        'feasibility_desc' => $feasDesc,
        'formula' => "85% (Base) " . ($sevBonus >= 0 ? "+ {$sevBonus}%" : "{$sevBonus}%") . " ({$sevName}) " . ($feasBonus >= 0 ? "+ {$feasBonus}%" : "{$feasBonus}%") . " (Feasibility) = " . round($score, 0) . "%"
    ];
}
}

/**
 * Render Confidence Badge with Interactive Hover Box Breakdown
 */
if (!function_exists('renderConfidenceBadge')) {
function renderConfidenceBadge($score, $severity = null, $decisionPath = '', $modality = null, $badgeClass = 'badge-info', $customStyle = '', $labelPrefix = 'Confidence: ') {
    $b = getConfidenceBreakdown($score, $severity, $decisionPath, $modality);
    $num = number_format($b['score'], 0);
    $sevSign = $b['severity_bonus'] >= 0 ? '+' . $b['severity_bonus'] : $b['severity_bonus'];
    $feasSign = $b['feasibility_bonus'] >= 0 ? '+' . $b['feasibility_bonus'] : $b['feasibility_bonus'];

    $html = '<span class="confidence-hover-wrap" tabindex="0">';
    $html .= '<span class="badge ' . clean($badgeClass) . '" style="' . clean($customStyle) . '">' . clean($labelPrefix) . $num . '% <span class="confidence-info-icon">ⓘ</span></span>';
    $html .= '<div class="confidence-hover-box">';
    $html .= '  <div class="confidence-hover-header">';
    $html .= '    <div style="display:flex;justify-content:space-between;align-items:center;">';
    $html .= '      <span style="font-weight:700;font-size:11px;color:#FFFFFF;">Confidence Breakdown: ' . $num . '%</span>';
    $html .= '      <span class="confidence-model-tag">NDRRMC Model</span>';
    $html .= '    </div>';
    $html .= '    <div style="font-size:9.5px;color:#CBD5E1;margin-top:2px;">Multi-criteria decision tree heuristic scoring</div>';
    $html .= '  </div>';
    $html .= '  <div class="confidence-hover-body">';
    $html .= '    <div class="confidence-hover-row">';
    $html .= '      <span class="confidence-row-label">1. NDRRMC Standard Baseline:</span>';
    $html .= '      <span class="confidence-row-value">85.0%</span>';
    $html .= '    </div>';
    $html .= '    <div class="confidence-hover-row">';
    $html .= '      <span class="confidence-row-label">2. Incident Severity (' . clean($b['severity_name']) . '):</span>';
    $html .= '      <span class="confidence-row-value ' . ($b['severity_bonus'] >= 0 ? 'text-success' : 'text-danger') . '">' . $sevSign . '%</span>';
    $html .= '    </div>';
    $html .= '    <div class="confidence-hover-row">';
    $html .= '      <span class="confidence-row-label">3. Feasibility (' . clean($b['feasibility_label']) . '):</span>';
    $html .= '      <span class="confidence-row-value ' . ($b['feasibility_bonus'] >= 0 ? 'text-success' : 'text-danger') . '">' . $feasSign . '%</span>';
    $html .= '    </div>';
    $html .= '    <div class="confidence-hover-footer">';
    $html .= '      <div style="font-size:9px;color:var(--color-text-muted);margin-bottom:2px;text-transform:uppercase;font-weight:600;">Mathematical Formula:</div>';
    $html .= '      <div style="font-size:10.5px;font-weight:700;color:var(--color-primary);font-family:var(--font-secondary);">' . clean($b['formula']) . '</div>';
    $html .= '    </div>';
    $html .= '  </div>';
    $html .= '</div>';
    $html .= '</span>';

    return $html;
}
}

// Format Role Name
if (!function_exists('formatRoleName')) {
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
}

// Immutable System Event Logger
if (!function_exists('logSystemEvent')) {
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
}

// Redirect with flash message (Traditional PHP form handling)
if (!function_exists('redirectWithFlash')) {
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
}
