<?php
/**
 * Grant Permission to User Endpoint
 * POST /api/permissions/grant.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Permissions.php';

// Require permission to grant permissions
requirePermission('permissions.grant');

// Get current user ID for audit trail
$currentUserId = getCurrentUserId();

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$permissionName = $input['permission_name'] ?? null;
$expiresAt = $input['expires_at'] ?? null;

if (!$userId || !$permissionName) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID and permission name are required']);
    exit;
}

// Validate expires_at format if provided
if ($expiresAt && !strtotime($expiresAt)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid expiration date format']);
    exit;
}

try {
    $success = Permissions::grantPermission($userId, $permissionName, $currentUserId, $expiresAt);

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Permission granted successfully'
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to grant permission']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
