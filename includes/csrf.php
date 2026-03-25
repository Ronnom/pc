<?php
/**
 * CSRF Protection Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = generateToken();
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Get CSRF token (for forms)
 */
function getCSRFToken() {
    return generateCSRFToken();
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Validate CSRF token from POST request
 */
function validateCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST[CSRF_TOKEN_NAME] ?? '';
        if (!verifyCSRFToken($token)) {
            http_response_code(403);
            die('CSRF token validation failed. Please refresh the page and try again.');
        }
    }
}

/**
 * Generate CSRF token input field
 */
function csrfField() {
    $token = getCSRFToken();
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . escape($token) . '">';
}

/**
 * Generate CSRF token for AJAX requests
 */
function getCSRFTokenForAjax() {
    return [
        'name' => CSRF_TOKEN_NAME,
        'value' => getCSRFToken()
    ];
}

