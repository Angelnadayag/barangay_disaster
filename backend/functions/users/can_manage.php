<?php
// ============================================================================
// Helper: User Permission Check
// ============================================================================

function canUserManageTarget($user, $targetUserId, $db, $targetType = null) {
    if ($user['role'] === 'icdrrmo') {
        return true;
    }
    if ($user['role'] === 'barangay_head') {
        // If targetType is explicitly resident, or if checking residents table
        if ($targetType === 'resident') {
            $stmt = $db->prepare("SELECT barangay_id FROM residents WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $res = $stmt->fetch();
            if ($res && (int)$res['barangay_id'] === (int)$user['barangay_id']) {
                return true;
            }
        }

        // Check users table
        $stmt = $db->prepare("SELECT role, barangay_id FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch();
        if ($target && in_array($target['role'], ['resident', 'responder'], true) && (int)$target['barangay_id'] === (int)$user['barangay_id']) {
            return true;
        }

        // Check residents table as fallback
        $rStmt = $db->prepare("SELECT barangay_id FROM residents WHERE id = ?");
        $rStmt->execute([$targetUserId]);
        $rTarget = $rStmt->fetch();
        if ($rTarget && (int)$rTarget['barangay_id'] === (int)$user['barangay_id']) {
            return true;
        }
    }
    return false;
}
