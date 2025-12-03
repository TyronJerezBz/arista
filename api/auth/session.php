<?php
/**
 * Get Current Session Endpoint
 * GET /api/auth/session.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'authenticated' => false,
        'user' => null
    ]);
    exit;
}

// Get current user
$user = getCurrentUser();

if (!$user) {
    echo json_encode([
        'authenticated' => false,
        'user' => null
    ]);
    exit;
}

// Generate new CSRF token
try {
    $csrfToken = Security::generateCSRFToken($user['id']);
    if (!$csrfToken) {
        // If token generation fails, return an error but still allow the session to be valid
        // This prevents complete lockout due to CSRF token generation issues
        error_log("Warning: Failed to generate CSRF token for user " . $user['id']);
    }
} catch (Exception $e) {
    error_log("Error generating CSRF token: " . $e->getMessage());
    $csrfToken = null;
}

// Return user data
echo json_encode([
    'authenticated' => true,
    'user' => $user,
    'csrf_token' => $csrfToken ?: null
]);

