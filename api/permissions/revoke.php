<?php
/**
 * Revoke Permission from User Endpoint
 * POST /api/permissions/revoke.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Permissions.php';

// Require permission to revoke permissions
requirePermission('permissions.revoke');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$permissionName = $input['permission_name'] ?? null;

if (!$userId || !$permissionName) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID and permission name are required']);
    exit;
}

try {
    $success = Permissions::revokePermission($userId, $permissionName);

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Permission revoked successfully'
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to revoke permission or permission was not granted']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
