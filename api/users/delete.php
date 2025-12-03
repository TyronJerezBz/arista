<?php
/**
 * Delete User Endpoint
 * DELETE /api/users/delete.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../log_action.php';

// Only allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require admin role
requireRole('admin');

// Get user ID
$userId = $_GET['id'] ?? null;
if (!$userId || !is_numeric($userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}
$userId = (int)$userId;

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Prevent self-deletion
$currentUser = getCurrentUser();
if ($userId === $currentUser['id']) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot delete your own account']);
    exit;
}

// Check if user exists
$db = Database::getInstance();
$user = $db->queryOne("SELECT id, username FROM users WHERE id = ?", [$userId]);
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Log action before deletion
logUserAction('Delete user', $userId, [
    'username' => $user['username']
]);

// Delete user
$db->delete('users', 'id = ?', [$userId]);

echo json_encode([
    'success' => true,
    'message' => 'User deleted successfully'
]);

