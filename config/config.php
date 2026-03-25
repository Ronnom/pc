<?php
/**
 * PC POS & Inventory Management System
 * Core Configuration File
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Error Reporting (Set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('America/New_York');

// Application Settings
define('APP_NAME', 'PC POS System');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/pc_pos');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'pc_pos');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Session Configuration
define('SESSION_NAME', 'PC_POS_SESSION');
define('SESSION_LIFETIME', 1800); // 30 minutes (default)
define('SESSION_TIMEOUT', 1800); // 30 minutes - configurable session timeout
define('REMEMBER_ME_LIFETIME', 2592000); // 30 days for remember me

// Security Settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);
define('PASSWORD_HISTORY_COUNT', 3); // Prevent reusing last 3 passwords
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('PASSWORD_RESET_TOKEN_EXPIRY', 3600); // 1 hour

// File Upload Settings
define('UPLOAD_DIR', APP_ROOT . '/uploads/');
define('UPLOAD_MAX_SIZE', 5242880); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// Pagination
define('ITEMS_PER_PAGE', 20);

// Date/Time Formats
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('TIME_FORMAT', 'H:i:s');

// Currency
define('CURRENCY_SYMBOL', '$');
define('CURRENCY_CODE', 'USD');

// Paths
define('INCLUDES_PATH', APP_ROOT . '/includes/');
define('MODULES_PATH', APP_ROOT . '/modules/');
define('ASSETS_PATH', APP_ROOT . '/assets/');
define('TEMPLATES_PATH', APP_ROOT . '/templates/');

