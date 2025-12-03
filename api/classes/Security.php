<?php
/**
 * Security Helper Class
 * 
 * Provides CSRF protection, encryption, and security utilities
 */

require_once __DIR__ . '/../config.php';

class Security {
    /**
     * Generate a CSRF token for a user
     * @param int $userId User ID
     * @return string CSRF token
     */
    public static function generateCSRFToken($userId) {
        $db = getDB();
        
        // Generate random token
        $token = bin2hex(random_bytes(CSRF_TOKEN_LENGTH / 2));
        
        // Calculate expiration time
        $expiresAt = date('Y-m-d H:i:s', time() + CSRF_TOKEN_LIFETIME);
        
        // Store token in database
        try {
            $stmt = $db->prepare("
                INSERT INTO csrf_tokens (token, user_id, expires_at)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$token, $userId, $expiresAt]);
            
            // Clean up expired tokens for this user
            self::cleanupExpiredTokens($userId);
            
            return $token;
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                error_log("Failed to generate CSRF token: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Validate a CSRF token
     * @param string $token Token to validate
     * @param int $userId User ID
     * @return bool True if valid, false otherwise
     */
    public static function validateCSRFToken($token, $userId) {
        if (empty($token) || empty($userId)) {
            return false;
        }
        
        $db = getDB();
        
        // Check if token exists and is valid
        $stmt = $db->prepare("
            SELECT id FROM csrf_tokens
            WHERE token = ? AND user_id = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token, $userId]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Allow token reuse until expiration to avoid invalidation during parallel actions
            // Previously: one-time tokens were deleted here
            return true;
        }
        
        return false;
    }
    
    /**
     * Clean up expired CSRF tokens for a user
     * @param int $userId User ID
     */
    private static function cleanupExpiredTokens($userId) {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM csrf_tokens WHERE user_id = ? AND expires_at <= NOW()");
        $stmt->execute([$userId]);
    }
    
    /**
     * Clean up all expired CSRF tokens
     */
    public static function cleanupAllExpiredTokens() {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM csrf_tokens WHERE expires_at <= NOW()");
        $stmt->execute();
    }
    
    /**
     * Encrypt data using AES-256-CBC
     * @param string $data Data to encrypt
     * @return string Encrypted data (base64 encoded)
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        $key = ENCRYPTION_KEY;
        $method = ENCRYPTION_METHOD;
        
        // Generate IV
        $ivLength = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        // Encrypt
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        
        // Combine IV and encrypted data
        $combined = $iv . $encrypted;
        
        // Return base64 encoded
        return base64_encode($combined);
    }
    
    /**
     * Decrypt data using AES-256-CBC
     * @param string $encryptedData Encrypted data (base64 encoded)
     * @return string Decrypted data
     */
    public static function decrypt($encryptedData) {
        if (empty($encryptedData)) {
            return '';
        }
        
        $key = ENCRYPTION_KEY;
        $method = ENCRYPTION_METHOD;
        
        // Decode from base64
        $combined = base64_decode($encryptedData);
        
        if ($combined === false) {
            return false;
        }
        
        // Extract IV
        $ivLength = openssl_cipher_iv_length($method);
        $iv = substr($combined, 0, $ivLength);
        $encrypted = substr($combined, $ivLength);
        
        // Decrypt
        $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);
        
        return $decrypted;
    }
    
    /**
     * Sanitize string input
     * @param string $input Input string
     * @return string Sanitized string
     */
    public static function sanitizeString($input) {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize array input
     * @param array $input Input array
     * @return array Sanitized array
     */
    public static function sanitizeArray($input) {
        $sanitized = [];
        foreach ($input as $key => $value) {
            $key = self::sanitizeString($key);
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
            } else {
                $sanitized[$key] = self::sanitizeString($value);
            }
        }
        return $sanitized;
    }
    
    /**
     * Generate a random password
     * @param int $length Password length
     * @return string Random password
     */
    public static function generateRandomPassword($length = 16) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($characters) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[random_int(0, $max)];
        }
        
        return $password;
    }
    
    /**
     * Validate password strength
     * @param string $password Password to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validatePassword($password) {
        $errors = [];
        
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long";
        }
        
        if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }
        
        if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }
        
        if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }
        
        if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

