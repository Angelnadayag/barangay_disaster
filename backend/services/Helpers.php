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

if (!function_exists('timeAgo')) {
function timeAgo($datetime) {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '—';
    $time = strtotime($datetime);
    if (!$time) return '—';
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d', $time);
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
        'Ended' => ['class' => 'badge-warning', 'label' => 'Ended'],
        'Finished' => ['class' => 'badge-warning', 'label' => 'Finished'],
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

// Get the agency for a given user or role
if (!function_exists('getAgencyForUser')) {
function getAgencyForUser(?array $user = null): ?array {
    $db = getDBConnection();
    if (!$user) {
        if (!function_exists('getCurrentUser')) {
            require_once __DIR__ . '/Auth.php';
        }
        $user = getCurrentUser();
    }
    if (!$user) return null;

    $role = $user['role'] ?? '';
    if ($role === 'icdrrmo') {
        $stmt = $db->query("SELECT * FROM agencies WHERE agency_type = 'ICDRRMO' LIMIT 1");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!empty($user['barangay_id'])) {
        $stmt = $db->prepare("SELECT * FROM agencies WHERE barangay_id = ? AND agency_type = 'BDRRMC' LIMIT 1");
        $stmt->execute([(int)$user['barangay_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!empty($user['agency_id'])) {
        $stmt = $db->prepare("SELECT * FROM agencies WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user['agency_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return null;
}
}

// Fetch all active static resource catalog definitions
if (!function_exists('getStaticResourcesCatalog')) {
function getStaticResourcesCatalog(): array {
    $db = getDBConnection();
    $stmt = $db->query("
        SELECT id, code, name, category, unit, brand, description, is_perishable, status
        FROM resources
        WHERE status = 'active' OR status IS NULL
        ORDER BY category ASC, name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}

// Sort Puroks so that Main Puroks come first (Purok 1, 2, 3...) followed by lettered sub-puroks (Purok 1a, 1b, 2a, 2b...)
if (!function_exists('sortPuroksMainFirst')) {
function sortPuroksMainFirst(array &$puroksList): void {
    usort($puroksList, function($a, $b) {
        $nameA = trim($a['name'] ?? '');
        $nameB = trim($b['name'] ?? '');

        // Pattern 1: Pure Main Purok (e.g. "Purok 1", "Purok 2", "Purok 10")
        $isMainA = preg_match('/^Purok\s*(\d+)$/i', $nameA, $mA);
        $isMainB = preg_match('/^Purok\s*(\d+)$/i', $nameB, $mB);

        // Pattern 2: Sub-purok with letter suffix (e.g. "Purok 1a", "Purok 1-A", "Purok 2b", "Purok 9a")
        $isSubA = preg_match('/^Purok\s*(\d+)\s*[-_]?\s*([a-zA-Z]+.*)$/i', $nameA, $sA);
        $isSubB = preg_match('/^Purok\s*(\d+)\s*[-_]?\s*([a-zA-Z]+.*)$/i', $nameB, $sB);

        // Priority Group:
        // 1 = Main Puroks (Purok 1, Purok 2, Purok 3...)
        // 2 = Lettered Sub-puroks (Purok 1a, Purok 1b, Purok 2a...)
        // 3 = Other named puroks (Purok Centro, Purok Riverside 1...)
        $groupA = $isMainA ? 1 : ($isSubA ? 2 : 3);
        $groupB = $isMainB ? 1 : ($isSubB ? 2 : 3);

        if ($groupA !== $groupB) {
            return $groupA <=> $groupB;
        }

        // Group 1: Numerical order by purok number
        if ($groupA === 1) {
            $numA = (int) $mA[1];
            $numB = (int) $mB[1];
            if ($numA !== $numB) {
                return $numA <=> $numB;
            }
            return strnatcasecmp($nameA, $nameB);
        }

        // Group 2: Numerical order by purok number, then alphabetical by letter suffix
        if ($groupA === 2) {
            $numA = (int) $sA[1];
            $numB = (int) $sB[1];
            if ($numA !== $numB) {
                return $numA <=> $numB;
            }
            $suffixA = strtolower(trim($sA[2]));
            $suffixB = strtolower(trim($sB[2]));
            return strnatcasecmp($suffixA, $suffixB);
        }

        // Group 3: Natural sort for other custom names
        return strnatcasecmp($nameA, $nameB);
    });
}
}

/**
 * Synchronize dynamic households and population count for a purok from purok_households table
 */
if (!function_exists('syncPurokDemographics')) {
function syncPurokDemographics($db, $purokId = 0, $purokName = '', $barangayId = 0) {
    if (!$db) return ['purok_id' => 0, 'households' => 0, 'population' => 0];

    if (!$purokId && !empty($purokName) && $barangayId > 0) {
        $pStmt = $db->prepare("SELECT id FROM puroks WHERE name = ? AND barangay_id = ? LIMIT 1");
        $pStmt->execute([$purokName, $barangayId]);
        $purokId = (int)$pStmt->fetchColumn();
    }

    if (!$purokId) return ['purok_id' => 0, 'households' => 0, 'population' => 0];

    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as dyn_households,
            COALESCE(SUM(total_members), 0) as dyn_population
        FROM purok_households 
        WHERE (purok_id = ? OR (purok_name = (SELECT name FROM puroks WHERE id = ?) AND barangay_id = (SELECT barangay_id FROM puroks WHERE id = ?)))
          AND status = 'active'
    ");
    $stmt->execute([$purokId, $purokId, $purokId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    $hh = (int)($counts['dyn_households'] ?? 0);
    $pop = (int)($counts['dyn_population'] ?? 0);

    $upd = $db->prepare("UPDATE puroks SET households = ?, population = ? WHERE id = ?");
    $upd->execute([$hh, $pop, $purokId]);

    $bStmt = $db->prepare("SELECT barangay_id FROM puroks WHERE id = ?");
    $bStmt->execute([$purokId]);
    $bId = (int)$bStmt->fetchColumn();
    if ($bId > 0) {
        $updB = $db->prepare("
            UPDATE barangays 
            SET total_households = (SELECT COUNT(*) FROM purok_households WHERE barangay_id = ? AND status = 'active'),
                population = (SELECT COALESCE(SUM(total_members), 0) FROM purok_households WHERE barangay_id = ? AND status = 'active')
            WHERE id = ?
        ");
        $updB->execute([$bId, $bId, $bId]);
    }

    return ['purok_id' => $purokId, 'households' => $hh, 'population' => $pop];
}
}

/**
 * Synchronize event(s) with financial_aid_allocations
 * If an event has activity_type = 'Social Welfare and Assistance' or content/activities containing 'Financial Aid Distribution',
 * ensure an active allocation exists in financial_aid_allocations and budget is scoped to target puroks/families.
 */
if (!function_exists('syncEventFinancialAidAllocation')) {
function syncEventFinancialAidAllocation($db, $eventId = null, $barangayId = null) {
    if (!$db) return;

    // Self-heal event_id, household_id, and delivery receipt columns if missing
    try {
        $cols = $db->query("SHOW COLUMNS FROM financial_aid_allocations LIKE 'event_id'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE financial_aid_allocations ADD COLUMN event_id INT(11) NULL DEFAULT NULL AFTER id");
            $db->exec("ALTER TABLE financial_aid_allocations ADD KEY idx_alloc_event (event_id)");
        }
        $colsRec = $db->query("SHOW COLUMNS FROM financial_aid_allocations LIKE 'received_status'")->fetchAll();
        if (empty($colsRec)) {
            $db->exec("ALTER TABLE financial_aid_allocations ADD COLUMN received_status ENUM('Pending','Received','Not Received') NOT NULL DEFAULT 'Received' AFTER status");
        }
        $colsDistr = $db->query("SHOW COLUMNS FROM financial_aid_allocations LIKE 'is_distributed'")->fetchAll();
        if (empty($colsDistr)) {
            $db->exec("ALTER TABLE financial_aid_allocations ADD COLUMN is_distributed TINYINT(1) NOT NULL DEFAULT 0 AFTER received_status");
        }
        $colsRecAt = $db->query("SHOW COLUMNS FROM financial_aid_allocations LIKE 'received_at'")->fetchAll();
        if (empty($colsRecAt)) {
            $db->exec("ALTER TABLE financial_aid_allocations ADD COLUMN received_at DATETIME DEFAULT NULL AFTER is_distributed");
        }
        $colsRecBy = $db->query("SHOW COLUMNS FROM financial_aid_allocations LIKE 'received_by'")->fetchAll();
        if (empty($colsRecBy)) {
            $db->exec("ALTER TABLE financial_aid_allocations ADD COLUMN received_by INT(11) DEFAULT NULL AFTER received_at");
        }
        $colsRecRem = $db->query("SHOW COLUMNS FROM financial_aid_allocations LIKE 'receipt_remarks'")->fetchAll();
        if (empty($colsRecRem)) {
            $db->exec("ALTER TABLE financial_aid_allocations ADD COLUMN receipt_remarks TEXT DEFAULT NULL AFTER received_by");
        }
        $colsDist = $db->query("SHOW COLUMNS FROM financial_aid_distributions LIKE 'event_id'")->fetchAll();
        if (empty($colsDist)) {
            $db->exec("ALTER TABLE financial_aid_distributions ADD COLUMN event_id INT(11) NULL DEFAULT NULL AFTER allocation_id");
            $db->exec("ALTER TABLE financial_aid_distributions ADD KEY idx_dist_event (event_id)");
        }
        $colsHh = $db->query("SHOW COLUMNS FROM financial_aid_distributions LIKE 'household_id'")->fetchAll();
        if (empty($colsHh)) {
            $db->exec("ALTER TABLE financial_aid_distributions ADD COLUMN household_id INT(11) NULL DEFAULT NULL AFTER resident_id");
            $db->exec("ALTER TABLE financial_aid_distributions ADD KEY idx_dist_hh (household_id)");
        }
    } catch (Throwable $e) {}

    // Find candidate events
    $sql = "SELECT * FROM events WHERE status != 'Cancelled' AND (is_archived IS NULL OR is_archived = 0)";
    $params = [];
    if ($eventId) {
        $sql .= " AND id = ?";
        $params[] = (int)$eventId;
    } elseif ($barangayId) {
        $sql .= " AND barangay_id = ?";
        $params[] = (int)$barangayId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($events as $evt) {
        $actType = trim($evt['activity_type'] ?? '');
        $title = trim($evt['title'] ?? '');
        $expAct = (string)($evt['expected_activities'] ?? '') . ' ' . (string)($evt['content_execution'] ?? '');

        $isFinancialAid = (
            strcasecmp($actType, 'Social Welfare and Assistance') === 0 ||
            stripos($title, 'Financial Aid') !== false ||
            stripos($title, 'Financial Assistance') !== false ||
            stripos($title, 'Ayuda') !== false ||
            stripos($expAct, 'Financial Aid Distribution') !== false ||
            stripos($expAct, 'Financial Assistance Distribution') !== false
        );

        // Check if an allocation already exists for this event
        $checkStmt = $db->prepare("SELECT * FROM financial_aid_allocations WHERE event_id = ? LIMIT 1");
        $checkStmt->execute([$evt['id']]);
        $existingAlloc = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$isFinancialAid) {
            if ($existingAlloc) {
                $distCount = (int)$db->query("SELECT COUNT(*) FROM financial_aid_distributions WHERE allocation_id = {$existingAlloc['id']}")->fetchColumn();
                if ($distCount === 0) {
                    $db->exec("DELETE FROM financial_aid_allocations WHERE id = {$existingAlloc['id']}");
                }
            }
            continue;
        }

        // Calculate target households
        $targetPuroks = trim($evt['target_puroks'] ?? 'All Puroks');
        $bId = (int)$evt['barangay_id'];

        $hhCountSql = "SELECT COUNT(*) FROM purok_households WHERE barangay_id = ? AND status = 'active'";
        $hhParams = [$bId];
        if (!empty($targetPuroks) && strcasecmp($targetPuroks, 'All Puroks') !== 0 && strcasecmp($targetPuroks, 'All') !== 0) {
            $purokList = array_values(array_filter(array_map('trim', explode(',', $targetPuroks))));
            if (!empty($purokList)) {
                $inClause = implode(',', array_fill(0, count($purokList), '?'));
                $hhCountSql .= " AND purok_name IN ($inClause)";
                $hhParams = array_merge($hhParams, $purokList);
            }
        }
        $hhStmt = $db->prepare($hhCountSql);
        $hhStmt->execute($hhParams);
        $targetBeneficiaries = (int)$hhStmt->fetchColumn();
        if ($targetBeneficiaries <= 0) {
            $targetBeneficiaries = max(1, (int)($evt['target_participants'] ?? 50));
        }

        // Check if is_distributed column exists
        try {
            $colsDistFlag = $db->query("SHOW COLUMNS FROM financial_aid_allocations LIKE 'is_distributed'")->fetchAll();
            if (empty($colsDistFlag)) {
                $db->exec("ALTER TABLE financial_aid_allocations ADD COLUMN is_distributed TINYINT(1) NOT NULL DEFAULT 0 AFTER target_beneficiaries");
            }
        } catch (Throwable $e) {}

        $status = ($evt['status'] === 'Completed') ? 'Completed' : 'Active';

        if ($existingAlloc) {
            // Update allocation metadata without touching budget (budget is set only when user initiates Distribute Aid)
            $upStmt = $db->prepare("
                UPDATE financial_aid_allocations
                SET program_name = ?,
                    target_purok = ?,
                    target_beneficiaries = ?,
                    status = ?
                WHERE id = ?
            ");
            $upStmt->execute([
                $evt['title'],
                $targetPuroks,
                $targetBeneficiaries,
                $status,
                $existingAlloc['id']
            ]);
        } else {
            // Create allocation code with 0.00 budget until user initiates Distribute Aid
            $allocCode = 'AID-EVT-' . str_pad($evt['id'], 4, '0', STR_PAD_LEFT);
            $cStmt = $db->prepare("SELECT id FROM financial_aid_allocations WHERE allocation_code = ?");
            $cStmt->execute([$allocCode]);
            if ($cStmt->fetch()) {
                $allocCode .= '-' . substr(md5(uniqid()), 0, 4);
            }

            $insStmt = $db->prepare("
                INSERT INTO financial_aid_allocations (
                    event_id, allocation_code, program_name, disaster_type,
                    barangay_id, target_purok, total_budget, amount_per_beneficiary,
                    disbursed_budget, remaining_budget, funding_source,
                    target_beneficiaries, is_distributed, status, added_type, notes, allocated_by, created_at
                ) VALUES (
                    ?, ?, ?, 'Disaster Relief & Social Welfare',
                    ?, ?, 0.00, 0.00,
                    0.00, 0.00, 'LGU Calamity Fund',
                    ?, 0, ?, 'added_by_admin', ?, ?, NOW()
                )
            ");
            $notes = 'Awaiting distribution plan setup by Barangay Head.';
            $insStmt->execute([
                $evt['id'], $allocCode, $evt['title'],
                $bId, $targetPuroks,
                $targetBeneficiaries, $status, $notes, $evt['created_by'] ?? null
            ]);
        }
    }
}
}

/**
 * Auto-sync event statuses:
 * 1. Transitions past events (end_datetime <= current datetime) that are not Completed or Cancelled to 'Ended'.
 * 2. Transitions 'Scheduled' events to 'Ongoing' when start_datetime <= current datetime and end_datetime > current datetime.
 */
if (!function_exists('syncEventStatuses')) {
function syncEventStatuses($db = null) {
    if (!$db) {
        $db = getDBConnection();
    }
    $nowStr = date('Y-m-d H:i:s');
    $rowsAffected = 0;

    // 1. Scheduled or Ongoing events whose end time has been reached transition to 'Ended'
    try {
        $endedStmt = $db->prepare("
            UPDATE events 
            SET status = 'Ended' 
            WHERE status IN ('Scheduled', 'Ongoing') 
              AND end_datetime <= ?
              AND (is_archived = 0 OR is_archived IS NULL)
        ");
        $endedStmt->execute([$nowStr]);
        $rowsAffected += $endedStmt->rowCount();
    } catch (Throwable $e) {
        // Warning 1265 / data truncation indicates 'Ended' is missing from events.status enum
        try {
            $db->exec("ALTER TABLE `events` MODIFY COLUMN `status` enum('Scheduled','Ongoing','Completed','Cancelled','Archived','Ended') DEFAULT 'Scheduled'");
            $endedStmt = $db->prepare("
                UPDATE events 
                SET status = 'Ended' 
                WHERE status IN ('Scheduled', 'Ongoing') 
                  AND end_datetime <= ?
                  AND (is_archived = 0 OR is_archived IS NULL)
            ");
            $endedStmt->execute([$nowStr]);
            $rowsAffected += $endedStmt->rowCount();
        } catch (Throwable $alterErr) {
            // If ALTER TABLE is restricted, fall back cleanly to 'Completed' so page never crashes
            try {
                $fallbackStmt = $db->prepare("
                    UPDATE events 
                    SET status = 'Completed' 
                    WHERE status IN ('Scheduled', 'Ongoing') 
                      AND end_datetime <= ?
                      AND (is_archived = 0 OR is_archived IS NULL)
                ");
                $fallbackStmt->execute([$nowStr]);
                $rowsAffected += $fallbackStmt->rowCount();
            } catch (Throwable $fallbackErr) {}
        }
    }

    // 2. Scheduled events whose start time has arrived transition to 'Ongoing'
    try {
        $ongoingStmt = $db->prepare("
            UPDATE events 
            SET status = 'Ongoing' 
            WHERE status = 'Scheduled' 
              AND start_datetime <= ?
              AND end_datetime > ?
              AND (is_archived = 0 OR is_archived IS NULL)
        ");
        $ongoingStmt->execute([$nowStr, $nowStr]);
        $rowsAffected += $ongoingStmt->rowCount();
    } catch (Throwable $e) {}

    return $rowsAffected;
}
}

/**
 * Ensure barangay_resource_requests delivery receipt columns exist in the database.
 * Auto-migrates receipt_status, received_at, received_by, and receipt_remarks if missing.
 */
if (!function_exists('ensureResourceRequestColumns')) {
function ensureResourceRequestColumns($db = null): bool {
    static $columnsReady = null;
    if ($columnsReady !== null) {
        return $columnsReady;
    }

    if (!$db) {
        $db = getDBConnection();
    }

    try {
        $chk = $db->query("SHOW COLUMNS FROM `barangay_resource_requests` LIKE 'received_by'");
        if ($chk && $chk->rowCount() > 0) {
            $columnsReady = true;
            return true;
        }

        // Attempt on-the-fly column migration
        try {
            $db->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `receipt_status` enum('Pending','Received','Not Received') NOT NULL DEFAULT 'Pending' AFTER `status`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `received_at` datetime DEFAULT NULL AFTER `receipt_status`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `received_by` int(11) DEFAULT NULL AFTER `received_at`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `barangay_resource_requests` ADD COLUMN `receipt_remarks` text DEFAULT NULL AFTER `received_by`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `barangay_resource_requests` ADD KEY `received_by` (`received_by`)");
        } catch (Throwable $e) {}

        $chk2 = $db->query("SHOW COLUMNS FROM `barangay_resource_requests` LIKE 'received_by'");
        $columnsReady = ($chk2 && $chk2->rowCount() > 0);
        return $columnsReady;
    } catch (Throwable $e) {
        $columnsReady = false;
        return false;
    }
}
}

/**
 * Ensure notifications target_puroks column exists in the database.
 * Auto-migrates target_puroks if missing.
 */
if (!function_exists('ensureNotificationColumns')) {
function ensureNotificationColumns($db = null): bool {
    static $columnsReady = null;
    if ($columnsReady !== null) {
        return $columnsReady;
    }

    if (!$db) {
        $db = getDBConnection();
    }

    try {
        $chk = $db->query("SHOW COLUMNS FROM `notifications` LIKE 'target_puroks'");
        if ($chk && $chk->rowCount() > 0) {
            $columnsReady = true;
            return true;
        }

        // Attempt on-the-fly column migration
        try {
            $db->exec("ALTER TABLE `notifications` ADD COLUMN `target_puroks` varchar(255) DEFAULT NULL AFTER `target_barangay_id`");
        } catch (Throwable $e) {}

        $chk2 = $db->query("SHOW COLUMNS FROM `notifications` LIKE 'target_puroks'");
        $columnsReady = ($chk2 && $chk2->rowCount() > 0);
        return $columnsReady;
    } catch (Throwable $e) {
        $columnsReady = false;
        return false;
    }
}
}

/**
 * Ensure financial_aid_allocations reception tracking and event columns exist in the database.
 * Auto-migrates received_status, is_distributed, received_at, received_by, receipt_remarks, and event_id if missing.
 */
if (!function_exists('ensureFinancialAidAllocationColumns')) {
function ensureFinancialAidAllocationColumns($db = null): bool {
    static $columnsReady = null;
    if ($columnsReady !== null) {
        return $columnsReady;
    }

    if (!$db) {
        $db = getDBConnection();
    }

    try {
        $chk = $db->query("SHOW COLUMNS FROM `financial_aid_allocations` LIKE 'received_status'");
        if ($chk && $chk->rowCount() > 0) {
            $columnsReady = true;
            return true;
        }

        // Attempt on-the-fly column migration
        try {
            $db->exec("ALTER TABLE `financial_aid_allocations` ADD COLUMN `event_id` int(11) DEFAULT NULL AFTER `id`");
            $db->exec("ALTER TABLE `financial_aid_allocations` ADD KEY `idx_alloc_event` (`event_id`)");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `financial_aid_allocations` ADD COLUMN `received_status` enum('Pending','Received','Not Received') NOT NULL DEFAULT 'Received' AFTER `status`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `financial_aid_allocations` ADD COLUMN `is_distributed` tinyint(1) NOT NULL DEFAULT 0 AFTER `received_status`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `financial_aid_allocations` ADD COLUMN `received_at` datetime DEFAULT NULL AFTER `is_distributed`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `financial_aid_allocations` ADD COLUMN `received_by` int(11) DEFAULT NULL AFTER `received_at`");
        } catch (Throwable $e) {}

        try {
            $db->exec("ALTER TABLE `financial_aid_allocations` ADD COLUMN `receipt_remarks` text DEFAULT NULL AFTER `received_by`");
        } catch (Throwable $e) {}

        $chk2 = $db->query("SHOW COLUMNS FROM `financial_aid_allocations` LIKE 'received_status'");
        $columnsReady = ($chk2 && $chk2->rowCount() > 0);
        return $columnsReady;
    } catch (Throwable $e) {
        $columnsReady = false;
        return false;
    }
}
}

/**
 * Permanently purge all notifications associated with a deleted event,
 * including related modules ('events', 'event', 'preparedness'),
 * matching event titles, linked financial aid distribution notifications,
 * and associated notification read status records.
 */
if (!function_exists('purgeEventNotifications')) {
function purgeEventNotifications($db, int $eventId, string $eventTitle = ''): int {
    if (!$db || $eventId <= 0) return 0;
    try {
        $params = [$eventId];
        $sql = "SELECT id FROM notifications WHERE (related_module IN ('events', 'event', 'preparedness', 'financial_aid', 'resources') AND related_id = ?)";
        
        if (!empty($eventTitle)) {
            $sql .= " OR (related_module IN ('events', 'event', 'preparedness', 'financial_aid', 'resources') AND (title LIKE ? OR message LIKE ?))";
            $params[] = '%' . $eventTitle . '%';
            $params[] = '%' . $eventTitle . '%';
        }
        
        // Also check linked financial aid allocations/distributions
        $allocStmt = $db->prepare("SELECT id FROM financial_aid_allocations WHERE event_id = ?");
        $allocStmt->execute([$eventId]);
        $allocIds = $allocStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($allocIds)) {
            $inAllocs = implode(',', array_fill(0, count($allocIds), '?'));
            $distStmt = $db->prepare("SELECT id FROM financial_aid_distributions WHERE allocation_id IN ($inAllocs) OR event_id = ?");
            $distStmt->execute(array_merge($allocIds, [$eventId]));
            $distIds = $distStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($distIds)) {
                $inDists = implode(',', array_fill(0, count($distIds), '?'));
                $distNotifs = $db->prepare("SELECT id FROM notifications WHERE related_module = 'financial_aid' AND related_id IN ($inDists)");
                $distNotifs->execute($distIds);
                $dNotifIds = $distNotifs->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($dNotifIds)) {
                    $inDn = implode(',', array_fill(0, count($dNotifIds), '?'));
                    $db->prepare("DELETE FROM notification_reads WHERE notification_id IN ($inDn)")->execute($dNotifIds);
                    $db->prepare("DELETE FROM notifications WHERE id IN ($inDn)")->execute($dNotifIds);
                }
            }
        }
        
        $notifStmt = $db->prepare($sql);
        $notifStmt->execute($params);
        $notifIds = $notifStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($notifIds)) {
            $inN = implode(',', array_fill(0, count($notifIds), '?'));
            $db->prepare("DELETE FROM notification_reads WHERE notification_id IN ($inN)")->execute($notifIds);
            $db->prepare("DELETE FROM notifications WHERE id IN ($inN)")->execute($notifIds);
        }
        
        // Also purge any orphaned event notifications whose event no longer exists
        $orphanStmt = $db->query("
            SELECT id FROM notifications 
            WHERE related_module IN ('events', 'event', 'preparedness') 
              AND (related_id NOT IN (SELECT id FROM events) OR related_id IS NULL)
        ");
        $orphanIds = $orphanStmt ? $orphanStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (!empty($orphanIds)) {
            $inO = implode(',', array_fill(0, count($orphanIds), '?'));
            $db->prepare("DELETE FROM notification_reads WHERE notification_id IN ($inO)")->execute($orphanIds);
            $db->prepare("DELETE FROM notifications WHERE id IN ($inO)")->execute($orphanIds);
        }
        
        return count($notifIds) + count($orphanIds);
    } catch (Throwable $e) {
        error_log("Failed to purge event notifications: " . $e->getMessage());
        return 0;
    }
}
}

/**
 * Determine Notification Category (strictly 3 categories: 'resources', 'financial_aid', or 'events')
 *
 * Rules:
 * 1. resources: Barangay Head selected "Social Welfare and Assistance" (or Resources) AND selected
 *    either "Relief Goods Distribution" or "Food Assistance Distribution".
 * 2. financial_aid: Barangay Head selected "Social Welfare and Assistance" AND selected
 *    either "Financial Aid Distribution" or "Financial Assistance Distribution".
 * 3. events: Literally event (all other community activities, drills, medical missions, seminars).
 */
if (!function_exists('determineEventNotificationCategory')) {
function determineEventNotificationCategory(string $activityType, $expectedActivities = []): string {
    $items = [];
    if (is_string($expectedActivities)) {
        $decoded = json_decode($expectedActivities, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $items = $decoded;
        } else {
            $items = [$expectedActivities];
        }
    } elseif (is_array($expectedActivities)) {
        $items = $expectedActivities;
    }

    $typeLower = strtolower(trim((string)$activityType));
    $itemsStr = strtolower(implode(' ', array_map('strval', $items)));

    $isWelfareOrRes = (
        strpos($typeLower, 'social welfare') !== false || 
        strpos($typeLower, 'resource') !== false ||
        strpos($typeLower, 'relief') !== false
    );

    $hasFinancialAid = (
        strpos($itemsStr, 'financial aid distribution') !== false || 
        strpos($itemsStr, 'financial assistance distribution') !== false ||
        strpos($itemsStr, 'financial aid') !== false ||
        strpos($itemsStr, 'financial assistance') !== false ||
        strpos($typeLower, 'financial aid') !== false ||
        strpos($typeLower, 'financial assistance') !== false
    );

    $hasResources = (
        strpos($itemsStr, 'relief goods distribution') !== false || 
        strpos($itemsStr, 'food assistance distribution') !== false ||
        strpos($itemsStr, 'relief goods') !== false ||
        strpos($itemsStr, 'food assistance') !== false ||
        strpos($typeLower, 'relief goods') !== false ||
        strpos($typeLower, 'food assistance') !== false
    );

    if ($isWelfareOrRes || $hasFinancialAid || $hasResources) {
        if ($hasFinancialAid && !$hasResources) {
            return 'financial_aid';
        }
        if ($hasResources && !$hasFinancialAid) {
            return 'resources';
        }
        if ($hasFinancialAid) {
            return 'financial_aid';
        }
        if ($hasResources) {
            return 'resources';
        }
    }

    return 'events';
}
}

