<?php
/**
 * Input Validation Class
 * 
 * Provides validation functions for various input types
 */

class Validator {
    /**
     * Validate IP address (IPv4 or IPv6)
     * @param string $ip IP address
     * @return bool
     */
    public static function validateIP($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * Validate IPv4 address
     * @param string $ip IP address
     * @return bool
     */
    public static function validateIPv4($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
    
    /**
     * Validate IPv6 address
     * @param string $ip IP address
     * @return bool
     */
    public static function validateIPv6($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
    
    /**
     * Validate hostname
     * @param string $hostname Hostname
     * @return bool
     */
    public static function validateHostname($hostname) {
        // Allows: letters, numbers, hyphens, underscores, dots
        // Supports both single-label (BHP-Arita_Core1) and FQDN (switch01.datacenter.com)
        // Max 253 characters
        if (empty($hostname) || strlen($hostname) > 253) {
            return false;
        }
        
        // Allow alphanumeric, hyphens, underscores, dots
        // Must start and end with alphanumeric
        $pattern = '/^[a-zA-Z0-9]([a-zA-Z0-9\-_\.]{0,251}[a-zA-Z0-9])?$/';
        return preg_match($pattern, $hostname) === 1;
    }
    
    /**
     * Validate VLAN ID (1-4094)
     * @param mixed $vlanId VLAN ID
     * @return bool
     */
    public static function validateVlanId($vlanId) {
        if (!is_numeric($vlanId)) {
            return false;
        }
        
        $vlanId = (int)$vlanId;
        return $vlanId >= 1 && $vlanId <= 4094;
    }
    
    /**
     * Validate interface name (e.g., Ethernet1, Port-Channel1)
     * @param string $interface Interface name
     * @return bool
     */
    public static function validateInterfaceName($interface) {
        if (empty($interface) || strlen($interface) > 32) {
            return false;
        }
        
        // Arista interface naming patterns:
        // EthernetX, ManagementX, Port-ChannelX, LoopbackX, VlanX, etc.
        // Allow alphanumeric, hyphens, underscores, and slashes
        $pattern = '/^[a-zA-Z][a-zA-Z0-9_\/\-]*[0-9]+$/';
        return preg_match($pattern, $interface) === 1;
    }
    
    /**
     * Validate username
     * @param string $username Username
     * @return bool
     */
    public static function validateUsername($username) {
        if (empty($username) || strlen($username) > 64) {
            return false;
        }
        
        // Allow alphanumeric, underscore, hyphen, dot
        // Must start with letter or number
        $pattern = '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,62}[a-zA-Z0-9]$|^[a-zA-Z0-9]$/';
        return preg_match($pattern, $username) === 1;
    }
    
    /**
     * Validate email address
     * @param string $email Email address
     * @return bool
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate port number (1-65535)
     * @param mixed $port Port number
     * @return bool
     */
    public static function validatePort($port) {
        if (!is_numeric($port)) {
            return false;
        }
        
        $port = (int)$port;
        return $port >= 1 && $port <= 65535;
    }
    
    /**
     * Validate integer
     * @param mixed $value Value to validate
     * @param int|null $min Minimum value (optional)
     * @param int|null $max Maximum value (optional)
     * @return bool
     */
    public static function validateInteger($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return false;
        }
        
        $value = (int)$value;
        
        if ($min !== null && $value < $min) {
            return false;
        }
        
        if ($max !== null && $value > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate string length
     * @param string $value String to validate
     * @param int|null $min Minimum length (optional)
     * @param int|null $max Maximum length (optional)
     * @return bool
     */
    public static function validateStringLength($value, $min = null, $max = null) {
        $length = strlen($value);
        
        if ($min !== null && $length < $min) {
            return false;
        }
        
        if ($max !== null && $length > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate required field
     * @param mixed $value Value to validate
     * @return bool
     */
    public static function validateRequired($value) {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        
        return $value !== null && $value !== '';
    }
    
    /**
     * Validate enum value
     * @param mixed $value Value to validate
     * @param array $allowedValues Allowed values
     * @return bool
     */
    public static function validateEnum($value, $allowedValues) {
        return in_array($value, $allowedValues, true);
    }
    
    /**
     * Validate switch role
     * @param string $role Role value
     * @return bool
     */
    public static function validateSwitchRole($role) {
        $allowedRoles = ['core', 'distribution', 'access', 'edge', 'spine', 'leaf', 'aggregation'];
        return in_array(strtolower($role), $allowedRoles);
    }
    
    /**
     * Validate user role
     * @param string $role Role value
     * @return bool
     */
    public static function validateUserRole($role) {
        return in_array($role, ['admin', 'operator', 'viewer'], true);
    }
    
    /**
     * Validate alert severity
     * @param string $severity Severity value
     * @return bool
     */
    public static function validateSeverity($severity) {
        return in_array($severity, ['info', 'warning', 'critical'], true);
    }
    
    /**
     * Validate interface mode
     * @param string $mode Mode value
     * @return bool
     */
    public static function validateInterfaceMode($mode) {
        return in_array($mode, ['access', 'trunk'], true);
    }
    
    /**
     * Validate switch status
     * @param string $status Status value
     * @return bool
     */
    public static function validateSwitchStatus($status) {
        return in_array($status, ['up', 'down', 'unknown'], true);
    }
    
    /**
     * Validate interface status
     * @param string $status Status value
     * @return bool
     */
    public static function validateInterfaceStatus($status) {
        return in_array($status, ['up', 'down', 'unknown'], true);
    }
}

