<?php
/**
 * Get User Permissions Endpoint
 * GET /api/permissions/user_permissions.php?user_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Permissions.php';
require_once __DIR__ . '/../classes/Database.php';

// Require admin permission
requirePermission('permissions.manage');

$userId = $_GET['user_id'] ?? null;
if (!$userId || !is_numeric($userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

$userId = (int)$userId;
$db = Database::getInstance();

// Get user info
$user = $db->queryOne("SELECT id, username, role FROM users WHERE id = ?", [$userId]);
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Get user's direct permissions (with grant details)
$directPermissions = $db->query("
    SELECT
        p.id,
        p.name,
        p.display_name,
        p.category,
        up.granted_at,
        up.expires_at,
        u.username as granted_by_username
    FROM user_permissions up
    INNER JOIN permissions p ON up.permission_id = p.id
    LEFT JOIN users u ON up.granted_by = u.id
    WHERE up.user_id = ? AND (up.expires_at IS NULL OR up.expires_at > NOW())
    ORDER BY p.category, p.name
", [$userId]);

// Get role-based permissions
$rolePermissions = Permissions::getRolePermissions($user['role']);

// Get all available permissions for comparison
$allPermissions = Permissions::getAllPermissions();

echo json_encode([
    'success' => true,
    'user' => $user,
    'direct_permissions' => $directPermissions,
    'role_permissions' => $rolePermissions,
    'all_permissions' => $allPermissions
]);
?>
