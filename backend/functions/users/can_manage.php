<?php
// ============================================================================
// Helper: User Permission Check
// ============================================================================

function canUserManageTarget($user, $targetUserId, $db, $targetType = null) {
    // User management is restricted to ICDRRMO only (Barangay Heads no longer manage users)
    return ($user['role'] ?? '') === 'icdrrmo';
}
