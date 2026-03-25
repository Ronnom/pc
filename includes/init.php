<?php
/**
 * Initialization File
 * Loads all core files and initializes the application
 */

// Define application root
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Load configuration FIRST (before using any constants)
require_once APP_ROOT . '/config/config.php';

// Start session (after config is loaded)
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Load database connection
require_once APP_ROOT . '/config/database.php';

// Load security modules
require_once APP_ROOT . '/includes/security.php';
require_once APP_ROOT . '/includes/csrf.php';

// Load utility functions
require_once APP_ROOT . '/includes/functions.php';

// Load quote system functions
require_once APP_ROOT . '/includes/quote_functions.php';

// Load authentication
require_once APP_ROOT . '/includes/auth.php';

// Load core modules manually (since they don't follow strict autoloader naming)
require_once APP_ROOT . '/modules/products.php';
require_once APP_ROOT . '/modules/products_enhanced.php';
require_once APP_ROOT . '/modules/categories.php';
require_once APP_ROOT . '/modules/suppliers.php';
require_once APP_ROOT . '/modules/customers.php';
require_once APP_ROOT . '/modules/users.php';
require_once APP_ROOT . '/modules/sales.php';
require_once APP_ROOT . '/modules/purchase_orders.php';
require_once APP_ROOT . '/modules/inventory.php';
require_once APP_ROOT . '/modules/stock_management.php';

// Auto-load additional modules (for modules that follow naming convention)
spl_autoload_register(function ($class) {
    // Skip if class already exists
    if (class_exists($class, false)) {
        return;
    }
    
    // Try exact match first
    $file = APP_ROOT . '/modules/' . strtolower($class) . '.php';
    if (file_exists($file) && !class_exists($class, false)) {
        require_once $file;
    }
    
    // Try with "module" suffix removed
    $className = str_replace('Module', '', $class);
    $file = APP_ROOT . '/modules/' . strtolower($className) . '.php';
    if (file_exists($file) && !class_exists($class, false)) {
        require_once $file;
    }
    
    // Try products_enhanced pattern
    if (strpos($class, 'Enhanced') !== false) {
        $baseName = str_replace('Enhanced', '', $class);
        $file = APP_ROOT . '/modules/' . strtolower($baseName) . '_enhanced.php';
        if (file_exists($file) && !class_exists($class, false)) {
            require_once $file;
        }
    }
});

