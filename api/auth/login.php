<?php
/**
 * User Login Endpoint
 * POST /api/auth/login.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

// Validate input
if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password are required']);
    exit;
}

// Sanitize username
$username = Security::sanitizeString($username);

// Attempt login
$user = loginUser($username, $password);

if ($user === false) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password']);
    exit;
}

// Generate CSRF token (may fail if there's an issue, but login should still work)
$csrfToken = false;
try {
    $csrfToken = Security::generateCSRFToken($user['id']);
} catch (Exception $e) {
    // CSRF token generation failed, but login is still valid
    // Log error but don't fail the login
    if (APP_DEBUG) {
        error_log("CSRF token generation failed: " . $e->getMessage());
    }
}

// Log login action
require_once __DIR__ . '/../log_action.php';
logAction('User login', 'user', $user['id'], ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

// Return success with user data and CSRF token (if generated)
$response = [
    'success' => true,
    'user' => $user
];

if ($csrfToken) {
    $response['csrf_token'] = $csrfToken;
}

echo json_encode($response);

