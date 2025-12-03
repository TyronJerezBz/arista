<?php
/**
 * Create User Endpoint
 * POST /api/users/create.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require admin role
requireRole('admin');

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Get input data
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';
$role = $input['role'] ?? 'viewer';

// Validate input
$errors = [];

if (empty($username)) {
    $errors[] = 'Username is required';
} elseif (!Validator::validateUsername($username)) {
    $errors[] = 'Invalid username format';
}

if (empty($password)) {
    $errors[] = 'Password is required';
} else {
    $passwordValidation = Security::validatePassword($password);
    if (!$passwordValidation['valid']) {
        $errors = array_merge($errors, $passwordValidation['errors']);
    }
}

if (!Validator::validateUserRole($role)) {
    $errors[] = 'Invalid role';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check if username already exists
$db = Database::getInstance();
$existing = $db->queryOne("SELECT id FROM users WHERE username = ?", [$username]);
if ($existing) {
    http_response_code(409);
    echo json_encode(['error' => 'Username already exists']);
    exit;
}

// Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$userId = $db->insert('users', [
    'username' => $username,
    'password_hash' => $passwordHash,
    'role' => $role
]);

// Log action
logUserAction('Create user', $userId, [
    'username' => $username,
    'role' => $role
]);

// Get created user (without password)
$user = $db->queryOne("SELECT id, username, role FROM users WHERE id = ?", [$userId]);

echo json_encode([
    'success' => true,
    'message' => 'User created successfully',
    'user' => $user
]);

