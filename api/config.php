<?php
/**
 * Arista Switch Management Platform - Configuration File
 * 
 * IMPORTANT: This file contains all configuration settings.
 * DO NOT use .env files - all configuration is in this PHP file.
 * 
 * SECURITY: Change all default values before production deployment!
 */

// ============================================
// Database Configuration
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'switchdb');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ============================================
// Session Configuration
// ============================================
define('SESSION_LIFETIME', 3600); // 1 hour in seconds
define('SESSION_NAME', 'ARISTA_SWITCH_SESSION');
define('SESSION_COOKIE_SECURE', false); // Set to true in production with HTTPS
define('SESSION_COOKIE_HTTPONLY', true); // Prevent JavaScript access
define('SESSION_COOKIE_SAMESITE', 'Strict'); // CSRF protection

// ============================================
// Security Configuration
// ============================================
// IMPORTANT: Generate a strong encryption key (32+ characters)
// You can generate one using: php -r "echo bin2hex(random_bytes(32));"
define('ENCRYPTION_KEY', 'CHANGE-THIS-TO-A-RANDOM-32-CHAR-KEY-12345678901234567890123456789012');
define('ENCRYPTION_METHOD', 'AES-256-CBC');

// CSRF Token Configuration
define('CSRF_TOKEN_LIFETIME', 1800); // 30 minutes in seconds
define('CSRF_TOKEN_LENGTH', 64); // Token length in characters

// Password Configuration
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', false);

// ============================================
// Arista eAPI Configuration
// ============================================
define('EAPI_DEFAULT_PORT', 443);
define('EAPI_DEFAULT_TIMEOUT', 10); // seconds
define('EAPI_DEFAULT_HTTPS', true);
define('EAPI_VERIFY_SSL', false); // Set to true in production with valid certificates
define('EAPI_MAX_RETRIES', 3); // Maximum retry attempts for failed requests

// ============================================
// Application Configuration
// ============================================
define('APP_NAME', 'Arista Switch Management Platform');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'UTC'); // Set your timezone, e.g., 'America/New_York'
define('APP_DEBUG', true); // Set to false in production

// Base URL Configuration (adjust for your setup)
define('BASE_URL', 'http://localhost/arista'); // Change this to your actual URL
define('API_BASE_URL', BASE_URL . '/api');
define('FRONTEND_BASE_URL', BASE_URL . '/frontend');

// ============================================
// File Upload Configuration (if needed)
// ============================================
define('UPLOAD_MAX_SIZE', 10485760); // 10MB in bytes
define('UPLOAD_ALLOWED_TYPES', ['csv', 'txt']);

// Firmware storage configuration
define('FIRMWARE_STORAGE_PATH', __DIR__ . '/../firmware');
define('FIRMWARE_MAX_SIZE', 2147483648); // 2GB default limit
define('FIRMWARE_ALLOWED_EXTENSIONS', ['swix','swi','swp','tar','gz','tgz','rpm','bin','img']);

// ============================================
// Logging Configuration
// ============================================
define('LOG_ENABLED', true);
define('LOG_FILE', __DIR__ . '/../logs/application.log');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR

// ============================================
// Database Connection
// ============================================
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    if (APP_DEBUG) {
        die("Database connection failed: " . $e->getMessage());
    } else {
        die("Database connection failed. Please contact the administrator.");
    }
}

// ============================================
// Timezone Configuration
// ============================================
date_default_timezone_set(APP_TIMEZONE);

// ============================================
// Error Reporting (Development only)
// ============================================
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ============================================
// Helper Functions
// ============================================

/**
 * Get database connection instance
 * @return PDO
 */
function getDB() {
    global $pdo;
    return $pdo;
}

/**
 * Get configuration value
 * @param string $key Configuration key
 * @param mixed $default Default value if key doesn't exist
 * @return mixed
 */
function config($key, $default = null) {
    return defined($key) ? constant($key) : $default;
}

