<?php
/**
 * Authentication Helper Functions
 *
 * Provides functions for user authentication, session management, RBAC, and permissions
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/Permissions.php';

// Include Security class for CSRF
require_once __DIR__ . '/classes/Security.php';

/**
 * Start session with secure settings
 */
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session settings
        ini_set('session.cookie_httponly', SESSION_COOKIE_HTTPONLY ? 1 : 0);
        ini_set('session.cookie_secure', SESSION_COOKIE_SECURE ? 1 : 0);
        ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_lifetime', SESSION_LIFETIME);
        
        session_name(SESSION_NAME);
        session_start();
        
        // Regenerate session ID periodically for security
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            // Regenerate ID every 30 minutes
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    startSecureSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    startSecureSession();
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user
 * @return array|null
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    return $user ?: null;
}

/**
 * Check if user has required role
 * @param string|array $requiredRole Required role(s) - 'admin', 'operator', 'viewer', or array
 * @return bool
 */
function hasRole($requiredRole) {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    
    if (is_array($requiredRole)) {
        return in_array($user['role'], $requiredRole);
    }
    
    // Role hierarchy: admin > operator > viewer
    $roleHierarchy = ['viewer' => 1, 'operator' => 2, 'admin' => 3];
    $userLevel = $roleHierarchy[$user['role']] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

/**
 * Require authentication - redirect to login if not logged in
 */
function requireAuth() {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
}

/**
 * Require specific role - return 403 if user doesn't have required role
 * @param string|array $requiredRole Required role(s)
 */
function requireRole($requiredRole) {
    requireAuth();

    if (!hasRole($requiredRole)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }
}

/**
 * Check if user has a specific permission
 * @param string $permissionName Permission name
 * @param int|null $userId User ID (null for current user)
 * @return bool
 */
function hasPermission($permissionName, $userId = null) {
    $userId = $userId ?? getCurrentUserId();
    return $userId ? Permissions::hasPermission($userId, $permissionName) : false;
}

/**
 * Require specific permission - return 403 if user doesn't have permission
 * @param string $permissionName Permission name
 */
function requirePermission($permissionName) {
    Permissions::requirePermission($permissionName);
}

/**
 * Get all permissions for current user
 * @return array Array of permission names
 */
function getCurrentUserPermissions() {
    $userId = getCurrentUserId();
    return $userId ? Permissions::getUserPermissions($userId) : [];
}

/**
 * Login user
 * @param string $username
 * @param string $password
 * @return array|false Returns user array on success, false on failure
 */
function loginUser($username, $password) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    
    // Start session and set user data
    startSecureSession();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    
    // Update last login (if column exists)
    try {
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
    } catch (PDOException $e) {
        // Column might not exist, ignore the error
        if (APP_DEBUG) {
            error_log("Failed to update last_login: " . $e->getMessage());
        }
    }
    
    // Remove password hash from return
    unset($user['password_hash']);
    
    return $user;
}

/**
 * Logout user
 */
function logoutUser() {
    startSecureSession();
    
    // Clear all session data
    $_SESSION = [];
    
    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy session
    session_destroy();
}

/**
 * Get CSRF token for current user
 * @return string
 */
function getCSRFToken() {
    requireAuth();
    return Security::generateCSRFToken(getCurrentUserId());
}

/**
 * Validate CSRF token
 * @param string $token Token to validate
 * @return bool
 */
function validateCSRFToken($token) {
    requireAuth();
    return Security::validateCSRFToken($token, getCurrentUserId());
}

/**
 * Require CSRF token - return 403 if token is invalid
 * @param string $token Token to validate
 */
function requireCSRF($token) {
    if (!validateCSRFToken($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

