<?php
// ============================================================================
// Backend Services: Decision Tree Resource Allocation Recommender Engine
// Explainable decision support based on NDRRMC & DSWD Disaster Preparedness Guidelines
// ============================================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../database/Connection.php';
require_once __DIR__ . '/Helpers.php';

class DecisionTreeRecommender {
    private PDO $db;

    public function __construct() {
        $this->db = getDBConnection();
    }

    /**
     * Run Decision Tree Analysis on a given Disaster Request
     * @param int $requestId
     * @param string $modality 'both', 'resources', or 'financial_aid'
     * @param array $options Optional custom parameters ['financial_aid_amount', 'financial_aid_sponsor', 'financial_aid_target']
     * @return array Result of analysis
     */
    public function evaluateRequest(int $requestId, string $modality = 'both', array $options = []): array {
        if (!in_array($modality, ['both', 'resources', 'financial_aid'])) {
            $modality = 'both';
        }

        // 1. Fetch disaster request with barangay & purok details
        $stmt = $this->db->prepare("
            SELECT r.*, b.name AS barangay_name, b.risk_level AS barangay_risk_level
            FROM disaster_reports r
            JOIN barangays b ON r.barangay_id = b.id
            WHERE r.id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            throw new Exception("Disaster request #$requestId not found.");
        }

        // Fetch warehouse inventory
        $invStmt = $this->db->query("SELECT id, code, name, category, available_quantity, unit FROM resources");
        $inventory = [];
        while ($row = $invStmt->fetch()) {
            $inventory[$row['code']] = $row;
        }

        // Decision Tree Factors
        $disaster   = $request['disaster_type'];
        $severity   = $request['severity'];
        $urgency    = $request['urgency'];
        $families   = (int)$request['affected_families'];
        $displaced  = (int)$request['displaced_families'];
        $injuries   = (int)$request['injuries_count'];
        $casualties = (int)$request['casualties_count'];

        if ($families <= 0) {
            $families = max(1, (int)($request['affected_individuals'] / 4.5));
        }
        if ($displaced <= 0 && in_array($severity, ['High', 'Critical'])) {
            $displaced = (int)($families * 0.6);
        }

        $decisionSteps = [];
        $recommendedItems = [];
        $priority = 'Medium';
        $baseConfidence = 85.0;
        $severityBonus = 0.0;

        // --- TREE LEVEL 1: Disaster Classification & Modality ---
        $modalityTitle = ($modality === 'resources') ? 'Physical Resources Only' : (($modality === 'financial_aid') ? 'Emergency Financial Aid (Ayuda) Only' : 'Comprehensive Dual Support');
        $decisionSteps[] = "Root [Disaster: $disaster | Modality: $modalityTitle]";

        // --- TREE LEVEL 2: Severity Assessment ---
        $decisionSteps[] = "Branch [Severity: $severity]";
        if ($severity === 'Critical') {
            $priority = 'Critical';
            $severityBonus = 5.0;
        } elseif ($severity === 'High') {
            $priority = 'High';
            $severityBonus = 3.0;
        } elseif ($severity === 'Moderate') {
            $priority = 'Medium';
            $severityBonus = 0.0;
        } else {
            $priority = 'Low';
            $severityBonus = -5.0;
        }

        // --- TREE LEVEL 3: Population & Displacement Scale ---
        $decisionSteps[] = "Branch [Affected: $families Families, Displaced: $displaced Families]";

        // --- TREE LEVEL 4: Urgency Weighting ---
        $decisionSteps[] = "Branch [Urgency: $urgency]";

        // Calculate Specific Resources based on Tree Branches (only if modality covers resources):
        if ($modality !== 'financial_aid') {
            // 1. Food Packs Calculation (NDRRMC Standard: 1 pack per family per 3 days)
            $foodRatio = 1.0;
            if ($severity === 'Moderate') $foodRatio = 0.75;
            if ($severity === 'Low') $foodRatio = 0.5;
            $foodQty = max(10, (int)ceil($families * $foodRatio));

            if (isset($inventory['RES-FOOD-001'])) {
                $recommendedItems[] = [
                    'resource_id' => $inventory['RES-FOOD-001']['id'],
                    'code' => 'RES-FOOD-001',
                    'name' => $inventory['RES-FOOD-001']['name'],
                    'quantity' => $foodQty,
                    'unit' => $inventory['RES-FOOD-001']['unit']
                ];
            }

            // 2. Ready-to-Eat Emergency Meals (for Immediate urgency or high displacement)
            if ($urgency === 'Immediate' || $displaced > 50) {
                $rteQty = max(20, (int)ceil($displaced * 1.5));
                if (isset($inventory['RES-FOOD-002'])) {
                $recommendedItems[] = [
                    'resource_id' => $inventory['RES-FOOD-002']['id'],
                    'code' => 'RES-FOOD-002',
                    'name' => $inventory['RES-FOOD-002']['name'],
                    'quantity' => $rteQty,
                    'unit' => $inventory['RES-FOOD-002']['unit']
                ];
            }
        }

        // 3. Potable Water Bottles (6L container)
        $waterQty = max(15, (int)ceil($families * 0.8));
        if (isset($inventory['RES-WATER-001'])) {
            $recommendedItems[] = [
                'resource_id' => $inventory['RES-WATER-001']['id'],
                'code' => 'RES-WATER-001',
                'name' => $inventory['RES-WATER-001']['name'],
                'quantity' => $waterQty,
                'unit' => $inventory['RES-WATER-001']['unit']
            ];
        }

        // 4. Family Hygiene Kits (Camp sanitation)
        $hygQty = max(10, (int)ceil(($displaced > 0 ? $displaced : $families) * 0.7));
        if (isset($inventory['RES-HYG-001'])) {
            $recommendedItems[] = [
                'resource_id' => $inventory['RES-HYG-001']['id'],
                'code' => 'RES-HYG-001',
                'name' => $inventory['RES-HYG-001']['name'],
                'quantity' => $hygQty,
                'unit' => $inventory['RES-HYG-001']['unit']
            ];
        }

        // 5. Specialized Water Rescue or Clearance Equipment
        if (in_array($disaster, ['Flood', 'Flash Flood', 'Storm Surge'])) {
            if (in_array($severity, ['High', 'Critical'])) {
                $boatsNeeded = max(1, (int)ceil($displaced / 150));
                $lifeVestsNeeded = max(20, $boatsNeeded * 30);

                if (isset($inventory['RES-RESC-001'])) {
                    $recommendedItems[] = [
                        'resource_id' => $inventory['RES-RESC-001']['id'],
                        'code' => 'RES-RESC-001',
                        'name' => $inventory['RES-RESC-001']['name'],
                        'quantity' => min(5, $boatsNeeded),
                        'unit' => $inventory['RES-RESC-001']['unit']
                    ];
                }
                if (isset($inventory['RES-RESC-002'])) {
                    $recommendedItems[] = [
                        'resource_id' => $inventory['RES-RESC-002']['id'],
                        'code' => 'RES-RESC-002',
                        'name' => $inventory['RES-RESC-002']['name'],
                        'quantity' => $lifeVestsNeeded,
                        'unit' => $inventory['RES-RESC-002']['unit']
                    ];
                }
            }
        } elseif (in_array($disaster, ['Typhoon', 'Landslide', 'Earthquake'])) {
            if (in_array($severity, ['High', 'Critical'])) {
                if (isset($inventory['RES-RESC-003'])) {
                    $recommendedItems[] = [
                        'resource_id' => $inventory['RES-RESC-003']['id'],
                        'code' => 'RES-RESC-003',
                        'name' => $inventory['RES-RESC-003']['name'],
                        'quantity' => min(4, max(1, (int)ceil($families / 100))),
                        'unit' => $inventory['RES-RESC-003']['unit']
                    ];
                }
            }
        }

        // 6. Shelter Modular Tents for Displaced Families
        if ($displaced > 0 && in_array($disaster, ['Typhoon', 'Fire', 'Flood', 'Storm Surge', 'Earthquake'])) {
            $tentsNeeded = min(150, (int)ceil($displaced * 0.6));
            if (isset($inventory['RES-SHEL-001'])) {
                $recommendedItems[] = [
                    'resource_id' => $inventory['RES-SHEL-001']['id'],
                    'code' => 'RES-SHEL-001',
                    'name' => $inventory['RES-SHEL-001']['name'],
                    'quantity' => $tentsNeeded,
                    'unit' => $inventory['RES-SHEL-001']['unit']
                ];
            }
        }

            // 7. Medical Supplies
            $firstAidQty = max(5, (int)ceil(($families / 40) + ($injuries * 2)));
            if (isset($inventory['RES-MED-001'])) {
                $recommendedItems[] = [
                    'resource_id' => $inventory['RES-MED-001']['id'],
                    'code' => 'RES-MED-001',
                    'name' => $inventory['RES-MED-001']['name'],
                    'quantity' => $firstAidQty,
                    'unit' => $inventory['RES-MED-001']['unit']
                ];
            }
        } // End if ($modality !== 'financial_aid')

        // --- Optional ICDRRMO customization: override quantities / add resources ---
        if ($modality !== 'financial_aid' && !empty($options['custom_resources']) && is_array($options['custom_resources'])) {
            $invById = [];
            foreach ($inventory as $invRow) {
                $invById[(int)$invRow['id']] = $invRow;
            }
            $customCount = 0;
            foreach ($options['custom_resources'] as $resId => $qty) {
                $resId = (int)$resId;
                $qty = (int)$qty;
                if ($qty <= 0 || !isset($invById[$resId])) {
                    continue;
                }
                $found = false;
                foreach ($recommendedItems as &$existing) {
                    if ((int)$existing['resource_id'] === $resId) {
                        $existing['quantity'] = $qty;
                        $found = true;
                        break;
                    }
                }
                unset($existing);
                if (!$found) {
                    $recommendedItems[] = [
                        'resource_id' => $resId,
                        'code' => $invById[$resId]['code'],
                        'name' => $invById[$resId]['name'],
                        'quantity' => $qty,
                        'unit' => $invById[$resId]['unit']
                    ];
                }
                $customCount++;
            }
            if ($customCount > 0) {
                $decisionSteps[] = "Custom Resources [$customCount item(s) adjusted or added by ICDRRMO]";
            }
        }

        // --- TREE LEVEL 5: Stock Sufficiency & Feasibility Check ---
        $stockDeficit = false;
        $stockBonus = 4.0;
        $feasibilityLabel = "All recommended supplies in stock at Central Depot";
        $feasibilityShort = "100% Supplies In Stock";

        if ($modality === 'financial_aid') {
            $decisionSteps[] = "LGU Calamity Liquidity [Quick Response Fund cash reserves verified for immediate disbursement]";
            $feasibilityLabel = "LGU Calamity Cash Reserve Liquidity Verified";
            $feasibilityShort = "Quick Response Fund Liquidity Verified";
            $stockBonus = 4.0;
        } else {
            foreach ($recommendedItems as &$item) {
                $code = $item['code'];
                $avail = $inventory[$code]['available_quantity'] ?? 0;
                $req = $item['quantity'];
                if ($avail < $req) {
                    $stockDeficit = true;
                }
            }

            if ($stockDeficit) {
                $decisionSteps[] = "Stock Feasibility [Bottlenecks Detected: Stock shortfall on some items; Human review should adjust or request external mutual aid]";
                $stockBonus = -6.0;
                $feasibilityLabel = "Central Depot Stock Shortfall (-6%)";
                $feasibilityShort = "Stock Bottleneck Deficit";
            } else {
                $decisionSteps[] = "Stock Feasibility [All recommended supplies in stock at Central Depot (+4%)]";
                $stockBonus = 4.0;
                $feasibilityLabel = "All recommended supplies in stock at Central Depot (+4%)";
                $feasibilityShort = "100% Supplies In Stock";
            }
        }

        // --- TREE LEVEL 6: Emergency Financial Aid (Ayuda) Allocation Analysis ---
        $targetAidFamilies = 0;
        $aidRatePerFamily = 0.00;
        $totalAidBudget = 0.00;
        $aidSource = !empty($options['financial_aid_sponsor']) 
            ? trim($options['financial_aid_sponsor']) 
            : (!empty($options['financial_aid_source']) ? trim($options['financial_aid_source']) : 'LGU Calamity Fund (QRF)');

        if ($modality === 'resources') {
            $decisionSteps[] = "Ayuda Evaluation [Deferred: Physical Relief Resources prioritization selected]";
        } else {
            // Target scope: displaced families vs all affected families
            $targetScope = $options['financial_aid_target'] ?? 'displaced';
            if ($targetScope === 'all_affected' || $displaced <= 0) {
                $targetAidFamilies = $families > 0 ? $families : 1;
            } else {
                $targetAidFamilies = $displaced > 0 ? $displaced : $families;
            }
            if ($targetAidFamilies <= 0) $targetAidFamilies = 1;

            $customAmount = isset($options['financial_aid_amount']) && is_numeric($options['financial_aid_amount']) && (float)$options['financial_aid_amount'] > 0
                ? (float)$options['financial_aid_amount']
                : null;

            if ($customAmount !== null) {
                $aidRatePerFamily = $customAmount;
            } else {
                $aidRatePerFamily = 3000.00; // Baseline Moderate
                if ($severity === 'Critical') {
                    $aidRatePerFamily = 5000.00;
                } elseif ($severity === 'High') {
                    $aidRatePerFamily = 4000.00;
                } elseif ($severity === 'Low') {
                    $aidRatePerFamily = 2000.00;
                }
            }
            $totalAidBudget = round($targetAidFamilies * $aidRatePerFamily, 2);

            $decisionSteps[] = "Ayuda Feasibility [Emergency Cash Aid: ₱" . number_format($aidRatePerFamily, 2) . "/fam for " . number_format($targetAidFamilies) . " families = ₱" . number_format($totalAidBudget, 2) . " from " . clean($aidSource) . "]";
        }

        // --- TREE LEVEL 7: Confidence Score Synthesis ---
        // Formula: Base 85% + Severity Bonus (+5%/+3%/0%/-5%) + Feasibility Bonus (+4%/-6%)
        $calculatedConfidence = $baseConfidence + $severityBonus + $stockBonus;
        $confidence = min(96.0, max(75.0, round($calculatedConfidence, 1)));
        $decisionPathString = implode(" -> ", $decisionSteps);

        // Structured Breakdown for Tooltip & Explainability
        $confidenceBreakdown = [
            'base' => 85.0,
            'severity_bonus' => $severityBonus,
            'severity_label' => $severity,
            'feasibility_bonus' => $stockBonus,
            'feasibility_label' => $feasibilityLabel,
            'feasibility_short' => $feasibilityShort,
            'total' => $confidence,
            'modality' => $modality,
            'formula' => "85% (Base) " . ($severityBonus >= 0 ? "+ {$severityBonus}%" : "{$severityBonus}%") . " ({$severity} Severity) " . ($stockBonus >= 0 ? "+ {$stockBonus}%" : "{$stockBonus}%") . " ({$feasibilityShort}) = " . round($confidence, 0) . "%"
        ];

        // Explanatory Reason with Selected Modality (Resources, Ayuda, or Both)
        if ($modality === 'resources') {
            $reason = "Recommendation generated using Decision Support System for $disaster in {$request['barangay_name']} ({$request['purok_name']}). "
                    . "Assessment parameters: $severity severity, $urgency urgency, $families affected families ($displaced displaced). "
                    . "Intervention Plan: Physical Relief Allocation prioritized (NDRRMC relief supply matrix and depot stock analysis). "
                    . ($stockDeficit ? "Notice: Central inventory has partial stock constraints for one or more physical items; human review may adjust quantities or request mutual aid." : "Central inventory possesses adequate stock for physical resource dispatch.");
        } elseif ($modality === 'financial_aid') {
            $reason = "Recommendation generated using Decision Support System for $disaster in {$request['barangay_name']} ({$request['purok_name']}). "
                    . "Assessment parameters: $severity severity, $urgency urgency, $families affected families ($displaced displaced). "
                    . "Intervention Plan: Emergency Financial Aid (Ayuda) Cash Grant prioritized: Recommended budget of ₱" . number_format($totalAidBudget, 2) . " (₱" . number_format($aidRatePerFamily, 2) . " per family for $targetAidFamilies families) under $aidSource.";
        } else {
            $reason = "Recommendation generated using Decision Support System for $disaster in {$request['barangay_name']} ({$request['purok_name']}). "
                    . "Assessment parameters: $severity severity, $urgency urgency, $families affected families ($displaced displaced). "
                    . "Dual Intervention Plan: 1) Physical Relief Allocation (NDRRMC relief supply matrix and depot stock analysis); "
                    . "2) Emergency Financial Aid (Ayuda): Recommended budget of ₱" . number_format($totalAidBudget, 2) . " (₱" . number_format($aidRatePerFamily, 2) . " per family for $targetAidFamilies families) under $aidSource. "
                    . ($stockDeficit ? "Notice: Central inventory has partial stock constraints for one or more physical items; human review may adjust quantities or request mutual aid." : "Central inventory possesses adequate stock for physical resource dispatch.");
        }

        // Check if recommendation already exists for this request
        $chkStmt = $this->db->prepare("SELECT id, recommendation_code FROM recommendations WHERE disaster_request_id = ?");
        $chkStmt->execute([$requestId]);
        $existingRec = $chkStmt->fetch();

        $this->db->beginTransaction();
        try {
            $recId = null;
            $code = null;
            if ($existingRec) {
                $recId = $existingRec['id'];
                $code = $existingRec['recommendation_code'];
                $updStmt = $this->db->prepare("
                    UPDATE recommendations SET
                        priority = ?,
                        modality = ?,
                        confidence_score = ?,
                        decision_path = ?,
                        recommendation_reason = ?,
                        financial_aid_recommended_budget = ?,
                        financial_aid_per_family = ?,
                        financial_aid_target_families = ?,
                        financial_aid_source = ?,
                        status = 'Pending Review',
                        reviewed_by = NULL,
                        reviewed_at = NULL,
                        review_remarks = NULL
                    WHERE id = ?
                ");
                $updStmt->execute([$priority, $modality, $confidence, $decisionPathString, $reason, $totalAidBudget, $aidRatePerFamily, $targetAidFamilies, $aidSource, $recId]);

                // Return any stock previously allocated by this recommendation before discarding its items
                $oldItemsStmt = $this->db->prepare("SELECT resource_id, allocated_quantity FROM recommended_items WHERE recommendation_id = ? AND allocated_quantity > 0 AND status = 'Allocated'");
                $oldItemsStmt->execute([$recId]);
                $restoreStmt = $this->db->prepare("
                    UPDATE resources SET
                        available_quantity = available_quantity + ?,
                        in_use_quantity = GREATEST(0, in_use_quantity - ?)
                    WHERE id = ?
                ");
                foreach ($oldItemsStmt->fetchAll() as $old) {
                    $restoreStmt->execute([$old['allocated_quantity'], $old['allocated_quantity'], $old['resource_id']]);
                }

                $delStmt = $this->db->prepare("DELETE FROM recommended_items WHERE recommendation_id = ?");
                $delStmt->execute([$recId]);
            } else {
                $code = 'REC-' . date('Y') . '-' . str_pad((string)$requestId, 3, '0', STR_PAD_LEFT);
                $insStmt = $this->db->prepare("
                    INSERT INTO recommendations (recommendation_code, disaster_request_id, priority, modality, confidence_score, decision_path, recommendation_reason, financial_aid_recommended_budget, financial_aid_per_family, financial_aid_target_families, financial_aid_source, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Review', NOW())
                ");
                $insStmt->execute([$code, $requestId, $priority, $modality, $confidence, $decisionPathString, $reason, $totalAidBudget, $aidRatePerFamily, $targetAidFamilies, $aidSource]);
                $recId = (int)$this->db->lastInsertId();
            }

            // Insert recommended items
            if (!empty($recommendedItems)) {
                $itemStmt = $this->db->prepare("
                    INSERT INTO recommended_items (recommendation_id, resource_id, recommended_quantity, approved_quantity, allocated_quantity, status, added_type)
                    VALUES (?, ?, ?, NULL, 0, 'Pending', 'ai_recommended')
                ");
                foreach ($recommendedItems as $item) {
                    $itemStmt->execute([$recId, $item['resource_id'], $item['quantity']]);
                }
            }

            // Update Disaster Request aid_support (status remains Ongoing until concluded by Barangay Head)
            $reqUpd = $this->db->prepare("UPDATE disaster_reports SET aid_support = ?, status = 'Ongoing' WHERE id = ?");
            $reqUpd->execute([$modality, $requestId]);

            // Add notification for ICDRRMO personnel
            $notifStmt = $this->db->prepare("
                INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
                VALUES ('icdrrmo', ?, ?, ?, 'warning', 'recommendation', ?, NOW())
            ");
            $notifMsg = "Decision Support recommendation ready for {$request['barangay_name']} ({$request['disaster_type']} - $severity). Modality: $modalityTitle. Priority: $priority.";
            $notifStmt->execute([$request['barangay_id'], "Recommendation Ready ($priority)", $notifMsg, $recId]);

            logSystemEvent('RUN_RECOMMENDER', 'Decision Tree Engine', "Generated recommendation #$recId for Disaster Request #$requestId ($disaster, $severity, modality: $modality)");

            $this->db->commit();

            return [
                'success' => true,
                'recommendation_id' => $recId,
                'recommendation_code' => $code,
                'priority' => $priority,
                'modality' => $modality,
                'confidence' => $confidence,
                'confidence_breakdown' => $confidenceBreakdown,
                'items_count' => count($recommendedItems),
                'decision_path' => $decisionPathString,
                'reason' => $reason,
                'financial_aid' => [
                    'recommended_budget' => $totalAidBudget,
                    'per_family' => $aidRatePerFamily,
                    'target_families' => $targetAidFamilies,
                    'funding_source' => $aidSource
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Human Review: ICDRRMO Approves, Modifies, or Rejects Recommendation
     */
    public function reviewRecommendation(int $recommendationId, string $action, array $modifiedQuantities = [], string $remarks = '', ?int $reviewerId = null): array {
        $stmt = $this->db->prepare("
            SELECT r.*, dr.id AS request_id, dr.barangay_id, dr.tracking_code, b.name AS barangay_name
            FROM recommendations r
            JOIN disaster_reports dr ON r.disaster_request_id = dr.id
            JOIN barangays b ON dr.barangay_id = b.id
            WHERE r.id = ?
        ");
        $stmt->execute([$recommendationId]);
        $rec = $stmt->fetch();

        if (!$rec) {
            throw new Exception("Recommendation #$recommendationId not found.");
        }

        $this->db->beginTransaction();
        try {
            $itemsStmt = $this->db->prepare("SELECT * FROM recommended_items WHERE recommendation_id = ?");
            $itemsStmt->execute([$recommendationId]);
            $items = $itemsStmt->fetchAll();

            if ($action === 'approve' || $action === 'modify') {
                $status = ($action === 'modify') ? 'Modified' : 'Approved';

                foreach ($items as $item) {
                    $resId = $item['resource_id'];
                    $qtyToApprove = (int)$item['recommended_quantity'];
                    if ($action === 'modify' && isset($modifiedQuantities[$resId])) {
                        $qtyToApprove = max(0, (int)$modifiedQuantities[$resId]);
                    }

                    $stockStmt = $this->db->prepare("SELECT available_quantity, in_use_quantity FROM resources WHERE id = ? FOR UPDATE");
                    $stockStmt->execute([$resId]);
                    $stock = $stockStmt->fetch();

                    if (!$stock || $stock['available_quantity'] < $qtyToApprove) {
                        $actualAlloc = min((int)($stock['available_quantity'] ?? 0), $qtyToApprove);
                    } else {
                        $actualAlloc = $qtyToApprove;
                    }

                    $updStock = $this->db->prepare("
                        UPDATE resources SET
                            available_quantity = available_quantity - ?,
                            in_use_quantity = in_use_quantity + ?
                        WHERE id = ?
                    ");
                    $updStock->execute([$actualAlloc, $actualAlloc, $resId]);

                    $updItem = $this->db->prepare("
                        UPDATE recommended_items SET
                            approved_quantity = ?,
                            allocated_quantity = ?,
                            status = 'Allocated'
                        WHERE id = ?
                    ");
                    $updItem->execute([$qtyToApprove, $actualAlloc, $item['id']]);

                    $itemAddedType = $item['added_type'] ?? 'ai_recommended';
                    $typeLabel = ($itemAddedType === 'added_by_admin') ? 'Added by Admin' : 'AI Recommended';

                    $trxStmt = $this->db->prepare("
                        INSERT INTO resource_transactions (resource_id, transaction_type, quantity, reference_type, reference_id, added_type, remarks, performed_by, created_at)
                        VALUES (?, 'Allocation', ?, 'disaster_request', ?, ?, ?, ?, NOW())
                    ");
                    $trxRemarks = "Allocated via approved recommendation #{$rec['recommendation_code']} [Source: {$typeLabel}]";
                    $trxStmt->execute([$resId, $actualAlloc, $rec['request_id'], $itemAddedType, $trxRemarks, $reviewerId]);
                }

                $recUpd = $this->db->prepare("
                    UPDATE recommendations SET
                        status = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_remarks = ?
                    WHERE id = ?
                ");
                $recUpd->execute([$status, $reviewerId, $remarks, $recommendationId]);

                // Note: The incident report status remains Ongoing until concluded by Barangay Head.
                // The allocation approval is tracked on the recommendation record.

                $notifStmt = $this->db->prepare("
                    INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
                    VALUES ('barangay_head', ?, ?, ?, 'informational', 'disaster_request', ?, NOW())
                ");
                $notifMsg = "Resource allocation approved for Request {$rec['tracking_code']}. Supplies have been allocated from Central Inventory.";
                $notifStmt->execute([$rec['barangay_id'], "Resource Allocation Approved", $notifMsg, $rec['request_id']]);

                logSystemEvent('APPROVE_RECOMMENDATION', 'Decision Tree Engine', "Reviewed and approved recommendation #$recommendationId ($status) for {$rec['barangay_name']}", $reviewerId);

            } elseif ($action === 'reject') {
                $recUpd = $this->db->prepare("
                    UPDATE recommendations SET
                        status = 'Rejected',
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_remarks = ?
                    WHERE id = ?
                ");
                $recUpd->execute([$reviewerId, $remarks, $recommendationId]);

                $updItem = $this->db->prepare("UPDATE recommended_items SET status = 'Rejected' WHERE recommendation_id = ?");
                $updItem->execute([$recommendationId]);

                // Note: The incident report status remains Ongoing until concluded by Barangay Head.
                // The allocation review status is recorded on the recommendation record.

                logSystemEvent('REJECT_RECOMMENDATION', 'Decision Tree Engine', "Rejected recommendation #$recommendationId. Remarks: $remarks", $reviewerId);
            }

            $this->db->commit();
            return ['success' => true, 'status' => $action];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
