<?php
// ============================================================================
// Backend Services: Authentication & Strict RBAC Enforcement
// ============================================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../database/Connection.php';
require_once __DIR__ . '/Helpers.php';

class Auth {
    public static function isLoggedIn(): bool {
        return !empty($_SESSION['user']) && !empty($_SESSION['user']['id']);
    }

    public static function getCurrentUser(): ?array {
        if (!isset($_SESSION['user']['id'])) {
            return null;
        }
        if (empty($_SESSION['user']['barangay_id']) && ($_SESSION['user']['role'] ?? '') === 'barangay_head') {
            try {
                $db = getDBConnection();
                $stmt = $db->prepare("
                    SELECT u.*, b.name AS barangay_name 
                    FROM users u 
                    LEFT JOIN barangays b ON u.barangay_id = b.id 
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $stmt->execute([(int)$_SESSION['user']['id']]);
                $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($fresh && !empty($fresh['barangay_id'])) {
                    unset($fresh['password']);
                    $_SESSION['user'] = $fresh;
                }
            } catch (Throwable $e) {}
        }
        return $_SESSION['user'] ?? null;
    }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: ' . BASE_URL . '/views/auth/login.php');
            exit;
        }
    }

    public static function requireRole($allowedRoles = []): void {
        self::requireLogin();
        $user = self::getCurrentUser();

        if (is_string($allowedRoles)) {
            $allowedRoles = [$allowedRoles];
        }

        if (!in_array($user['role'], $allowedRoles, true)) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><head><title>403 Access Denied</title>';
            echo '<link rel="stylesheet" href="' . BASE_URL . '/frontend/css/app.css">';
            echo '</head><body style="display:flex;align-items:center;justify-content:center;height:100vh;">';
            echo '<div class="card" style="max-width:420px;text-align:center;padding:24px;">';
            echo '<h2 style="color:var(--color-danger);margin-bottom:8px;">Access Restricted</h2>';
            echo '<p style="color:var(--color-text-secondary);margin-bottom:16px;">Your current role (' . htmlspecialchars(formatRoleName($user['role'])) . ') is not authorized to access this module.</p>';
            echo '<a href="' . BASE_URL . '/index.php" class="btn btn-primary">Return to My Dashboard</a>';
            echo '</div></body></html>';
            exit;
        }
    }

    public static function switchDemoRole(string $role): bool {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT u.*, b.name AS barangay_name 
            FROM users u 
            LEFT JOIN barangays b ON u.barangay_id = b.id 
            WHERE u.role = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$role]);
        $user = $stmt->fetch();

        if ($user) {
            unset($user['password']);
            $_SESSION['user'] = $user;
            logSystemEvent('DEMO_ROLE_SWITCH', 'Auth', 'Switched session to persona: ' . $user['username'] . ' (' . $role . ')', $user['id'], $user['full_name'], $user['role']);
            return true;
        }
        return false;
    }
}

// Global helper functions
function isLoggedIn(): bool { return Auth::isLoggedIn(); }
function getCurrentUser(): ?array { return Auth::getCurrentUser(); }
function requireLogin(): void { Auth::requireLogin(); }
function requireRole($allowedRoles): void { Auth::requireRole($allowedRoles); }
function switchDemoRole(string $role): bool { return Auth::switchDemoRole($role); }
function getRoleFolder(string $role): string {
    return ($role === 'barangay_head') ? 'barangay' : $role;
}
