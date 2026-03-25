<?php
/**
 * Legacy bootstrap compatibility layer.
 * Keeps old pages working while the codebase transitions to includes/init.php.
 */

require_once dirname(__DIR__) . '/includes/init.php';

if (!function_exists('get_db_connection')) {
    function get_db_connection() {
        return getDB()->getConnection();
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return isAdmin();
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isLoggedIn();
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        return requireLogin();
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        requireLogin();
        if (!isAdmin()) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

if (!function_exists('generate_transaction_number')) {
    function generate_transaction_number() {
        return generateTransactionNumber();
    }
}

if (!function_exists('has_permission')) {
    function has_permission($permissionName) {
        return hasPermission($permissionName);
    }
}

if (!function_exists('require_permission')) {
    function require_permission($permissionName) {
        return requirePermission($permissionName);
    }
}

if (!function_exists('has_any_permission')) {
    function has_any_permission(array $permissions) {
        return hasAnyPermission($permissions);
    }
}

if (!function_exists('has_all_permissions')) {
    function has_all_permissions(array $permissions) {
        return hasAllPermissions($permissions);
    }
}
