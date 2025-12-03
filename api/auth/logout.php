<?php
/**
 * User Logout Endpoint
 * POST /api/auth/logout.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get user ID before logout
$userId = getCurrentUserId();

// Log logout action
logAction('User logout', 'user', $userId, ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

// Logout user
logoutUser();

// Return success
echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully'
]);

