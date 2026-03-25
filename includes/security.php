<?php
/**
 * Security Functions
 * XSS prevention, input sanitization, password hashing
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Sanitize input to prevent XSS attacks
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize output for display
 */
function escape($data) {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
}

/**
 * Hash password using bcrypt
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Generate random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (basic)
 */
function isValidPhone($phone) {
    $phone = preg_replace('/[^0-9+()-]/', '', $phone);
    return strlen($phone) >= 10;
}

/**
 * Sanitize filename
 */
function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    return $filename;
}

/**
 * Check if string contains only alphanumeric and allowed characters
 */
function isValidString($string, $allowed = '') {
    $pattern = '/^[a-zA-Z0-9' . preg_quote($allowed, '/') . ']+$/';
    return preg_match($pattern, $string);
}

/**
 * Rate limiting check
 */
function checkRateLimit($key, $maxAttempts = 30, $timeWindow = 3600) {
    $sessionKey = 'rate_limit_' . $key;
    
    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = [
            'attempts' => 0,
            'reset_time' => time() + $timeWindow
        ];
    }
    
    // Reset if time window expired
    if (time() > $_SESSION[$sessionKey]['reset_time']) {
        $_SESSION[$sessionKey] = [
            'attempts' => 0,
            'reset_time' => time() + $timeWindow
        ];
    }
    
    $_SESSION[$sessionKey]['attempts']++;
    
    if ($_SESSION[$sessionKey]['attempts'] > $maxAttempts) {
        return false;
    }
    
    return true;
}

/**
 * Clear rate limit
 */
function clearRateLimit($key) {
    $sessionKey = 'rate_limit_' . $key;
    unset($_SESSION[$sessionKey]);
}

