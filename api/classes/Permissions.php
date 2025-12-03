<?php
/**
 * Permissions Management Class
 *
 * Handles user permissions, role-based permissions, and permission checking
 */

require_once __DIR__ . '/Database.php';

class Permissions {

    /**
     * Check if user has a specific permission
     * @param int $userId User ID
     * @param string $permissionName Permission name
     * @return bool
     */
    public static function hasPermission($userId, $permissionName) {
        $db = Database::getInstance();

        // First check direct user permissions (including expired ones)
        $result = $db->queryOne("
            SELECT COUNT(*) as count FROM user_permissions up
            INNER JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.name = ?
            AND (up.expires_at IS NULL OR up.expires_at > NOW())
        ", [$userId, $permissionName]);

        if ($result && $result['count'] > 0) {
            return true;
        }

        // If no direct permission, check role-based permissions
        $user = self::getUserWithRole($userId);
        if (!$user || !isset($user['role'])) {
            return false;
        }

        $result = $db->queryOne("
            SELECT COUNT(*) as count FROM role_permissions rp
            INNER JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role = ? AND p.name = ?
        ", [$user['role'], $permissionName]);

        return $result && $result['count'] > 0;
    }

    /**
     * Check if current user has permission
     * @param string $permissionName Permission name
     * @return bool
     */
    public static function currentUserHasPermission($permissionName) {
        $userId = self::getCurrentUserId();
        return $userId ? self::hasPermission($userId, $permissionName) : false;
    }

    /**
     * Get all permissions for a user
     * @param int $userId User ID
     * @return array Array of permission names
     */
    public static function getUserPermissions($userId) {
        $db = Database::getInstance();
        $permissions = [];

        // Get direct user permissions
        $directPermissions = $db->query("
            SELECT p.name FROM user_permissions up
            INNER JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND (up.expires_at IS NULL OR up.expires_at > NOW())
        ", [$userId]);
        $directNames = array_column($directPermissions, 'name');
        $permissions = array_merge($permissions, $directNames);

        // Get role-based permissions
        $user = self::getUserWithRole($userId);
        if ($user && isset($user['role'])) {
            $rolePermissions = $db->query("
                SELECT p.name FROM role_permissions rp
                INNER JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role = ?
            ", [$user['role']]);
            $roleNames = array_column($rolePermissions, 'name');
            $permissions = array_merge($permissions, $roleNames);
        }

        return array_unique($permissions);
    }

    /**
     * Get all available permissions
     * @param string|null $category Filter by category
     * @return array Array of permission data
     */
    public static function getAllPermissions($category = null) {
        $db = Database::getInstance();

        $sql = "SELECT id, name, display_name, description, category FROM permissions";
        $params = [];

        if ($category) {
            $sql .= " WHERE category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY category, name";

        return $db->query($sql, $params);
    }

    /**
     * Grant permission to user
     * @param int $userId User ID
     * @param string $permissionName Permission name
     * @param int $grantedBy User ID who granted the permission
     * @param string|null $expiresAt Expiration date (YYYY-MM-DD HH:MM:SS)
     * @return bool Success
     */
    public static function grantPermission($userId, $permissionName, $grantedBy, $expiresAt = null) {
        $db = Database::getInstance();

        // Get permission ID
        $permission = $db->queryOne("SELECT id FROM permissions WHERE name = ?", [$permissionName]);

        if (!$permission) {
            return false; // Permission doesn't exist
        }

        // Insert or update user permission
        $result = $db->execute("
            INSERT INTO user_permissions (user_id, permission_id, granted_by, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            granted_by = VALUES(granted_by),
            expires_at = VALUES(expires_at),
            granted_at = CURRENT_TIMESTAMP
        ", [$userId, $permission['id'], $grantedBy, $expiresAt]);

        return $result > 0;
    }

    /**
     * Revoke permission from user
     * @param int $userId User ID
     * @param string $permissionName Permission name
     * @return bool Success
     */
    public static function revokePermission($userId, $permissionName) {
        $db = Database::getInstance();

        $result = $db->execute("
            DELETE up FROM user_permissions up
            INNER JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.name = ?
        ", [$userId, $permissionName]);

        return $result > 0;
    }

    /**
     * Get users with a specific permission
     * @param string $permissionName Permission name
     * @return array Array of user data
     */
    public static function getUsersWithPermission($permissionName) {
        $db = Database::getInstance();

        return $db->query("
            SELECT DISTINCT u.id, u.username, u.role FROM users u
            INNER JOIN user_permissions up ON u.id = up.user_id
            INNER JOIN permissions p ON up.permission_id = p.id
            WHERE p.name = ? AND (up.expires_at IS NULL OR up.expires_at > NOW())
            UNION
            SELECT DISTINCT u.id, u.username, u.role FROM users u
            INNER JOIN role_permissions rp ON u.role = rp.role
            INNER JOIN permissions p ON rp.permission_id = p.id
            WHERE p.name = ?
        ", [$permissionName, $permissionName]);
    }

    /**
     * Set default permissions for a role
     * @param string $role Role name (admin, operator, viewer)
     * @param array $permissionNames Array of permission names
     * @return bool Success
     */
    public static function setRolePermissions($role, $permissionNames) {
        $db = Database::getInstance();

        // Remove existing role permissions
        $db->execute("DELETE FROM role_permissions WHERE role = ?", [$role]);

        // Add new permissions
        foreach ($permissionNames as $permissionName) {
            $db->execute("
                INSERT INTO role_permissions (role, permission_id)
                SELECT ?, id FROM permissions WHERE name = ?
            ", [$role, $permissionName]);
        }

        return true;
    }

    /**
     * Get permissions for a role
     * @param string $role Role name
     * @return array Array of permission data
     */
    public static function getRolePermissions($role) {
        $db = Database::getInstance();

        return $db->query("
            SELECT p.id, p.name, p.display_name, p.description, p.category
            FROM role_permissions rp
            INNER JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role = ?
            ORDER BY p.category, p.name
        ", [$role]);
    }

    /**
     * Create a new permission
     * @param string $name Permission name (unique)
     * @param string $displayName Human-readable name
     * @param string $description Description
     * @param string $category Category for grouping
     * @return bool Success
     */
    public static function createPermission($name, $displayName, $description = '', $category = 'general') {
        $db = Database::getInstance();

        try {
            $db->insert('permissions', [
                'name' => $name,
                'display_name' => $displayName,
                'description' => $description,
                'category' => $category
            ]);
            return true;
        } catch (Exception $e) {
            // Permission might already exist (unique constraint)
            return false;
        }
    }

    // Helper methods

    /**
     * Get current user ID
     * @return int|null
     */
    private static function getCurrentUserId() {
        require_once __DIR__ . '/../auth.php';
        return getCurrentUserId();
    }

    /**
     * Get user with role
     * @param int $userId User ID
     * @return array|null
     */
    private static function getUserWithRole($userId) {
        $db = Database::getInstance();
        return $db->queryOne("SELECT id, username, role FROM users WHERE id = ?", [$userId]);
    }

    /**
     * Require permission - return 403 if user doesn't have permission
     * @param string $permissionName Permission name
     */
    public static function requirePermission($permissionName) {
        require_once __DIR__ . '/../auth.php';
        requireAuth();

        if (!self::currentUserHasPermission($permissionName)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions']);
            exit;
        }
    }
}
?>
