<?php
/**
 * List Users with Permissions Endpoint
 * GET /api/permissions/users.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Permissions.php';
require_once __DIR__ . '/../classes/Database.php';

// Require admin permission
requirePermission('permissions.manage');

$db = Database::getInstance();

// Get all users with their roles and permission counts
$users = $db->query("
    SELECT
        u.id,
        u.username,
        u.role,
        u.last_login,
        COUNT(DISTINCT up.permission_id) as direct_permissions,
        COUNT(DISTINCT rp.permission_id) as role_permissions
    FROM users u
    LEFT JOIN user_permissions up ON u.id = up.user_id AND (up.expires_at IS NULL OR up.expires_at > NOW())
    LEFT JOIN role_permissions rp ON u.role = rp.role
    GROUP BY u.id, u.username, u.role, u.last_login
    ORDER BY u.username
");

echo json_encode([
    'success' => true,
    'users' => $users
]);
?>
