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
     * @return array Result of analysis
     */
    public function evaluateRequest(int $requestId): array {
        // 1. Fetch disaster request with barangay & purok details
        $stmt = $this->db->prepare("
            SELECT r.*, b.name AS barangay_name, b.risk_level AS barangay_risk_level
            FROM disaster_requests r
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

        // --- TREE LEVEL 1: Disaster Classification ---
        $decisionSteps[] = "Root [Disaster: $disaster]";

        // --- TREE LEVEL 2: Severity Assessment ---
        $decisionSteps[] = "Branch [Severity: $severity]";
        if ($severity === 'Critical') {
            $priority = 'Critical';
            $baseConfidence += 5.0;
        } elseif ($severity === 'High') {
            $priority = 'High';
            $baseConfidence += 3.0;
        } elseif ($severity === 'Moderate') {
            $priority = 'Medium';
        } else {
            $priority = 'Low';
        }

        // --- TREE LEVEL 3: Population & Displacement Scale ---
        $decisionSteps[] = "Branch [Affected: $families Families, Displaced: $displaced Families]";

        // --- TREE LEVEL 4: Urgency Weighting ---
        $decisionSteps[] = "Branch [Urgency: $urgency]";

        // Calculate Specific Resources based on Tree Branches:

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

        // --- TREE LEVEL 5: Stock Sufficiency & Feasibility Check ---
        $stockDeficit = false;
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
            $baseConfidence -= 6.0;
        } else {
            $decisionSteps[] = "Stock Feasibility [All recommended supplies in stock at Central Depot]";
            $baseConfidence += 4.0;
        }

        $confidence = min(96.0, max(75.0, round($baseConfidence, 1)));
        $decisionPathString = implode(" -> ", $decisionSteps);

        // Explanatory Reason
        $reason = "Recommendation generated using Decision Tree disaster response matrix for $disaster in {$request['barangay_name']} ({$request['purok_name']}). "
                . "Condition parameters: $severity severity, $urgency urgency, $families affected families ($displaced displaced). "
                . "Recommended allocation adheres to standard NDRRMC/DSWD relief ratios and specialized hazard mitigation protocols. "
                . ($stockDeficit ? "Notice: Central inventory has partial stock constraints for one or more items; reviewing officer may adjust quantities accordingly." : "Central inventory possesses adequate stock for full allocation dispatch.");

        // Check if recommendation already exists for this request
        $chkStmt = $this->db->prepare("SELECT id FROM recommendations WHERE disaster_request_id = ?");
        $chkStmt->execute([$requestId]);
        $existingRec = $chkStmt->fetch();

        $this->db->beginTransaction();
        try {
            $recId = null;
            if ($existingRec) {
                $recId = $existingRec['id'];
                $updStmt = $this->db->prepare("
                    UPDATE recommendations SET
                        priority = ?,
                        confidence_score = ?,
                        decision_path = ?,
                        recommendation_reason = ?,
                        status = 'Pending Review',
                        reviewed_by = NULL,
                        reviewed_at = NULL,
                        review_remarks = NULL
                    WHERE id = ?
                ");
                $updStmt->execute([$priority, $confidence, $decisionPathString, $reason, $recId]);

                $delStmt = $this->db->prepare("DELETE FROM recommended_items WHERE recommendation_id = ?");
                $delStmt->execute([$recId]);
            } else {
                $code = 'REC-' . date('Y') . '-' . str_pad((string)$requestId, 3, '0', STR_PAD_LEFT);
                $insStmt = $this->db->prepare("
                    INSERT INTO recommendations (recommendation_code, disaster_request_id, priority, confidence_score, decision_path, recommendation_reason, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'Pending Review', NOW())
                ");
                $insStmt->execute([$code, $requestId, $priority, $confidence, $decisionPathString, $reason]);
                $recId = (int)$this->db->lastInsertId();
            }

            // Insert recommended items
            $itemStmt = $this->db->prepare("
                INSERT INTO recommended_items (recommendation_id, resource_id, recommended_quantity, approved_quantity, allocated_quantity, status)
                VALUES (?, ?, ?, NULL, 0, 'Pending')
            ");
            foreach ($recommendedItems as $item) {
                $itemStmt->execute([$recId, $item['resource_id'], $item['quantity']]);
            }

            // Update Disaster Request status
            $reqUpd = $this->db->prepare("UPDATE disaster_requests SET status = 'Recommendation Ready' WHERE id = ?");
            $reqUpd->execute([$requestId]);

            // Add notification for ICDRRMO personnel
            $notifStmt = $this->db->prepare("
                INSERT INTO notifications (target_role, target_barangay_id, title, message, alert_level, related_module, related_id, created_at)
                VALUES ('icdrrmo', ?, ?, ?, 'warning', 'recommendation', ?, NOW())
            ");
            $notifMsg = "Decision Tree recommendation ready for {$request['barangay_name']} ({$request['disaster_type']} - $severity). Priority: $priority.";
            $notifStmt->execute([$request['barangay_id'], "Resource Recommendation Ready ($priority)", $notifMsg, $recId]);

            logSystemEvent('RUN_RECOMMENDER', 'Decision Tree Engine', "Generated recommendation #$recId for Disaster Request #$requestId ($disaster, $severity)");

            $this->db->commit();

            return [
                'success' => true,
                'recommendation_id' => $recId,
                'priority' => $priority,
                'confidence' => $confidence,
                'items_count' => count($recommendedItems),
                'decision_path' => $decisionPathString,
                'reason' => $reason
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
            JOIN disaster_requests dr ON r.disaster_request_id = dr.id
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

                    $trxStmt = $this->db->prepare("
                        INSERT INTO resource_transactions (resource_id, transaction_type, quantity, reference_type, reference_id, remarks, performed_by, created_at)
                        VALUES (?, 'Allocation', ?, 'disaster_request', ?, ?, ?, NOW())
                    ");
                    $trxStmt->execute([$resId, $actualAlloc, $rec['request_id'], "Allocated via approved recommendation #{$rec['recommendation_code']}", $reviewerId]);
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

                $reqUpd = $this->db->prepare("UPDATE disaster_requests SET status = 'Allocated' WHERE id = ?");
                $reqUpd->execute([$rec['request_id']]);

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

                $reqUpd = $this->db->prepare("UPDATE disaster_requests SET status = 'Under Review' WHERE id = ?");
                $reqUpd->execute([$rec['request_id']]);

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
