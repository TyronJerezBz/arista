<?php
/**
 * List All Permissions Endpoint
 * GET /api/permissions/list.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Permissions.php';

// Require admin permission
requirePermission('permissions.manage');

// Get optional category filter
$category = $_GET['category'] ?? null;

$permissions = Permissions::getAllPermissions($category);

echo json_encode([
    'success' => true,
    'permissions' => $permissions
]);
?>
