<?php
// ============================================================================
// Helper: User Permission Check
// ============================================================================

function canUserManageTarget($user, $targetUserId, $db) {
    if ($user['role'] === 'icdrrmo') {
        return true;
    }
    if ($user['role'] === 'barangay_head') {
        $stmt = $db->prepare("SELECT role, barangay_id FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch();
        if ($target && in_array($target['role'], ['resident', 'responder'], true) && (int)$target['barangay_id'] === (int)$user['barangay_id']) {
            return true;
        }
    }
    return false;
}
