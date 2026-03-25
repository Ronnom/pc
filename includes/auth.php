<?php
/**
 * Authentication Module
 * Enhanced with session timeout, remember me, password history
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

function authTableHasColumn($tableName, $columnName) {
    static $columnCache = [];
    $cacheKey = $tableName . '.' . $columnName;
    if (array_key_exists($cacheKey, $columnCache)) {
        return $columnCache[$cacheKey];
    }

    try {
        $db = getDB();
        $column = $db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$tableName, $columnName]
        );
        $columnCache[$cacheKey] = !empty($column);
    } catch (Exception $e) {
        $columnCache[$cacheKey] = false;
    }

    return $columnCache[$cacheKey];
}

function isRememberTokenStorageAvailable() {
    return authTableHasColumn('remember_tokens', 'user_id')
        && authTableHasColumn('remember_tokens', 'token')
        && authTableHasColumn('remember_tokens', 'expires_at');
}

/**
 * Check session timeout
 */
function checkSessionTimeout() {
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    $timeout = SESSION_TIMEOUT;
    if (isset($_SESSION['timeout_override'])) {
        $timeout = $_SESSION['timeout_override'];
    }
    
    if (time() - $_SESSION['last_activity'] > $timeout) {
        logout();
        setFlashMessage('error', 'Your session has expired. Please login again.');
        redirect(getBaseUrl() . '/login.php');
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Check remember me token
 */
function checkRememberMe() {
    if (!isRememberTokenStorageAvailable()) {
        return false;
    }

    if (isset($_COOKIE['remember_token'])) {
        $db = getDB();
        $token = sanitize($_COOKIE['remember_token']);
        
        $rememberToken = $db->fetchOne(
            "SELECT rt.*, u.*
             FROM remember_tokens rt 
             INNER JOIN users u ON rt.user_id = u.id 
             WHERE rt.token = ? AND rt.expires_at > NOW() AND u.is_active = 1",
            [$token]
        );
        
        if ($rememberToken) {
            // Set session
            $_SESSION['user_id'] = $rememberToken['user_id'] ?? $rememberToken['id'];
            $_SESSION['username'] = $rememberToken['username'];
            $_SESSION['role_id'] = $rememberToken['role_id'] ?? null;
            $_SESSION['is_admin'] = isset($rememberToken['is_admin']) ? (int)$rememberToken['is_admin'] : 0;
            $_SESSION['last_activity'] = time();
            
            // Update last login
            $db->update('users', 
                ['last_login' => date(DATETIME_FORMAT)],
                'id = ?',
                [$rememberToken['user_id']]
            );
            
            logUserActivity($rememberToken['user_id'], 'login', 'auth', 'User logged in via remember me');
            return true;
        } else {
            // Invalid token, clear cookie
            setcookie('remember_token', '', time() - 3600, '/');
        }
    }
    return false;
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    // Check remember me first
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        checkRememberMe();
    }
    
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        // Check session timeout
        checkSessionTimeout();
        return true;
    }
    
    return false;
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }

    $db = getDB();
    $userId = getCurrentUserId();

    // Support both schema variants: users.role_id -> roles.name, or users.is_admin without role mapping.
    try {
        $hasRoleId = $db->fetchOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'users'
             AND COLUMN_NAME = 'role_id'"
        );

        if ($hasRoleId) {
            return $db->fetchOne(
                "SELECT u.*, r.name as role_name
                 FROM users u
                 LEFT JOIN roles r ON u.role_id = r.id
                 WHERE u.id = ? AND u.is_active = 1",
                [$userId]
            );
        }

        $user = $db->fetchOne(
            "SELECT u.*
             FROM users u
             WHERE u.id = ? AND u.is_active = 1",
            [$userId]
        );

        if ($user) {
            $isAdminFlag = isset($user['is_admin']) && (int)$user['is_admin'] === 1;
            $user['role_name'] = $isAdminFlag ? 'administrator' : ($user['role_name'] ?? 'user');
        }

        return $user;
    } catch (Exception $e) {
        error_log('getCurrentUser schema compatibility error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long.";
    }
    
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }
    
    return $errors;
}

/**
 * Check if password was used recently
 */
function isPasswordInHistory($userId, $password) {
    $db = getDB();
    
    // Get last N password hashes
    $history = $db->fetchAll(
        "SELECT password_hash FROM password_history 
         WHERE user_id = ? 
         ORDER BY created_at DESC 
         LIMIT ?",
        [$userId, PASSWORD_HISTORY_COUNT]
    );
    
    foreach ($history as $record) {
        if (password_verify($password, $record['password_hash'])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Save password to history
 */
function savePasswordToHistory($userId, $passwordHash) {
    $db = getDB();
    
    // Save current password to history
    $db->insert('password_history', [
        'user_id' => $userId,
        'password_hash' => $passwordHash
    ]);
    
    // Keep only last N passwords
    $db->query(
        "DELETE FROM password_history 
         WHERE user_id = ? AND id NOT IN (
             SELECT id FROM (
                 SELECT id FROM password_history 
                 WHERE user_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?
             ) AS temp
         )",
        [$userId, $userId, PASSWORD_HISTORY_COUNT]
    );
}

/**
 * Login user
 */
function login($username, $password, $rememberMe = false) {
    $db = getDB();
    
    // Rate limiting disabled for now
    // if (!checkRateLimit('login_' . $username, LOGIN_MAX_ATTEMPTS, LOGIN_LOCKOUT_TIME)) {
    //     setFlashMessage('error', 'Too many login attempts. Please try again later.');
    //     return false;
    // }
    
    // Get user
    $user = $db->fetchOne(
        "SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1",
        [$username, $username]
    );
    
    if (!$user || !verifyPassword($password, $user['password_hash'])) {
        setFlashMessage('error', 'Invalid username or password.');
        return false;
    }
    
    // Rate limiting disabled - no need to clear
    // clearRateLimit('login_' . $username);
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role_id'] = $user['role_id'] ?? null;
    $_SESSION['is_admin'] = isset($user['is_admin']) ? (int)$user['is_admin'] : 0;
    $_SESSION['last_activity'] = time();
    
    // Handle remember me
    if ($rememberMe && isRememberTokenStorageAvailable()) {
        $token = generateToken(64);
        $expiresAt = date(DATETIME_FORMAT, time() + REMEMBER_ME_LIFETIME);
        
        $db->insert('remember_tokens', [
            'user_id' => $user['id'],
            'token' => $token,
            'expires_at' => $expiresAt
        ]);
        
        // Set cookie (30 days)
        setcookie('remember_token', $token, time() + REMEMBER_ME_LIFETIME, '/', '', false, true);
    } elseif ($rememberMe) {
        // Do not break login when remember_tokens table is not installed.
        error_log('Remember me requested but remember_tokens storage is unavailable.');
    }
    
    // Update last login (optional - won't fail login if column doesn't exist)
    try {
        // Check if column exists first
        $columnCheck = $db->fetchOne(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'users' 
             AND COLUMN_NAME = 'last_login'"
        );
        
        if ($columnCheck) {
            $db->update('users', 
                ['last_login' => date(DATETIME_FORMAT)],
                'id = ?',
                [$user['id']]
            );
        }
    } catch (Exception $e) {
        // Silently fail - login should still work
        error_log("Failed to update last_login: " . $e->getMessage());
    }
    
    // Log activity (optional - won't fail login if it fails)
    try {
        if (function_exists('logUserActivity')) {
            logUserActivity($user['id'], 'login', 'auth', 'User logged in');
        }
    } catch (Exception $e) {
        // Silently fail
        error_log("Failed to log activity: " . $e->getMessage());
    }
    
    return true;
}

/**
 * Logout user
 */
function logout() {
    $userId = $_SESSION['user_id'] ?? null;

    if ($userId) {
        logUserActivity($userId, 'logout', 'auth', 'User logged out');
    }

    // Delete remember me token without calling isLoggedIn(), which can recurse
    // when logout() is triggered from session-timeout enforcement.
    if (isset($_COOKIE['remember_token']) && isRememberTokenStorageAvailable()) {
        $db = getDB();
        $db->delete('remember_tokens', 'token = ?', [sanitize($_COOKIE['remember_token'])]);
        setcookie('remember_token', '', time() - 3600, '/');
    } elseif (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
    }

    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        setFlashMessage('error', 'Please login to access this page.');
        redirect(getBaseUrl() . '/login.php');
    }
}

/**
 * Change user password
 */
function changePassword($userId, $currentPassword, $newPassword) {
    $db = getDB();
    
    // Get user
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Verify current password
    if (!verifyPassword($currentPassword, $user['password_hash'])) {
        throw new Exception('Current password is incorrect');
    }
    
    // Validate new password strength
    $errors = validatePasswordStrength($newPassword);
    if (!empty($errors)) {
        throw new Exception(implode(' ', $errors));
    }
    
    // Check password history
    if (isPasswordInHistory($userId, $newPassword)) {
        throw new Exception('You cannot reuse your last ' . PASSWORD_HISTORY_COUNT . ' passwords');
    }
    
    // Save old password to history
    savePasswordToHistory($userId, $user['password_hash']);
    
    // Update password
    $newHash = hashPassword($newPassword);
    $db->update('users', 
        ['password_hash' => $newHash],
        'id = ?',
        [$userId]
    );
    
    logUserActivity($userId, 'password_change', 'auth', 'User changed password');
    
    return true;
}

/**
 * Generate password reset token
 */
function generatePasswordResetToken($email) {
    $db = getDB();
    
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email]);
    if (!$user) {
        // Don't reveal if user exists
        return null;
    }
    
    // Delete old tokens
    $db->delete('password_reset_tokens', 'user_id = ?', [$user['id']]);
    
    // Generate new token
    $token = generateToken(64);
    $expiresAt = date(DATETIME_FORMAT, time() + PASSWORD_RESET_TOKEN_EXPIRY);
    
    $db->insert('password_reset_tokens', [
        'user_id' => $user['id'],
        'token' => $token,
        'expires_at' => $expiresAt
    ]);
    
    return [
        'user' => $user,
        'token' => $token,
        'expires_at' => $expiresAt
    ];
}

/**
 * Verify password reset token
 */
function verifyPasswordResetToken($token) {
    $db = getDB();
    
    $resetToken = $db->fetchOne(
        "SELECT prt.*, u.id as user_id, u.email 
         FROM password_reset_tokens prt 
         INNER JOIN users u ON prt.user_id = u.id 
         WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used = 0 AND u.is_active = 1",
        [$token]
    );
    
    return $resetToken;
}

/**
 * Reset password using token
 */
function resetPassword($token, $newPassword) {
    $db = getDB();
    
    $resetToken = verifyPasswordResetToken($token);
    if (!$resetToken) {
        throw new Exception('Invalid or expired reset token');
    }
    
    // Validate password strength
    $errors = validatePasswordStrength($newPassword);
    if (!empty($errors)) {
        throw new Exception(implode(' ', $errors));
    }
    
    // Check password history
    if (isPasswordInHistory($resetToken['user_id'], $newPassword)) {
        throw new Exception('You cannot reuse your last ' . PASSWORD_HISTORY_COUNT . ' passwords');
    }
    
    // Get current password hash
    $user = $db->fetchOne("SELECT password_hash FROM users WHERE id = ?", [$resetToken['user_id']]);
    
    // Save old password to history
    savePasswordToHistory($resetToken['user_id'], $user['password_hash']);
    
    // Update password
    $newHash = hashPassword($newPassword);
    $db->update('users', 
        ['password_hash' => $newHash],
        'id = ?',
        [$resetToken['user_id']]
    );
    
    // Mark token as used
    $db->update('password_reset_tokens',
        ['used' => 1],
        'id = ?',
        [$resetToken['id']]
    );
    
    // Delete all remember me tokens for security
    if (isRememberTokenStorageAvailable()) {
        $db->delete('remember_tokens', 'user_id = ?', [$resetToken['user_id']]);
    }
    
    logUserActivity($resetToken['user_id'], 'password_reset', 'auth', 'User reset password via token');
    
    return true;
}

/**
 * Check if user has permission
 */
function hasPermission($permissionName) {
    if (!isLoggedIn()) {
        return false;
    }

    // Administrator has full access across all modules.
    if (isAdmin()) {
        return true;
    }

    if (empty($_SESSION['role_id'])) {
        return false;
    }

    $db = getDB();
    try {
        $permission = $db->fetchOne(
            "SELECT COUNT(*) as count
             FROM role_permissions rp
             INNER JOIN permissions p ON rp.permission_id = p.id
             WHERE rp.role_id = ? AND p.name = ?",
            [$_SESSION['role_id'], $permissionName]
        );

        return isset($permission['count']) && (int)$permission['count'] > 0;
    } catch (Exception $e) {
        // role_permissions/permissions may not exist in simplified schemas.
        error_log('hasPermission schema compatibility error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Require permission
 */
function requirePermission($permissionName) {
    requireLogin();
    
    if (!hasPermission($permissionName)) {
        http_response_code(403);
        // For AJAX requests, return JSON response
        if (!empty($_GET['ajax']) || !empty($_POST['csrf_token'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
            exit;
        }
        die('Access denied. You do not have permission to perform this action.');
    }
}

/**
 * Check if user has any of the permissions
 */
function hasAnyPermission($permissions) {
    foreach ($permissions as $permission) {
        if (hasPermission($permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has all permissions
 */
function hasAllPermissions($permissions) {
    foreach ($permissions as $permission) {
        if (!hasPermission($permission)) {
            return false;
        }
    }
    return true;
}

/**
 * Check if user is admin
 */
function isAdmin() {
    if (!isLoggedIn()) {
        return false;
    }

    if (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) {
        return true;
    }

    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    if (isset($user['is_admin']) && (int)$user['is_admin'] === 1) {
        return true;
    }

    return isset($user['role_name']) && strtolower((string)$user['role_name']) === 'administrator';
}

/**
 * Check if user is cashier
 */
function isCashier() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    return $user && strtolower($user['role_name']) === 'cashier';
}

/**
 * Check if user is manager
 */
function isManager() {
    if (!isLoggedIn()) {
        return false;
    }

    $user = getCurrentUser();
    return $user && strtolower($user['role_name']) === 'manager';
}

/**
 * Check if user is technician
 */
function isTechnician() {
    if (!isLoggedIn()) {
        return false;
    }

    $user = getCurrentUser();
    return $user && strtolower($user['role_name']) === 'technician';
}

/**
 * Check if user is inventory manager
 */
function isInventoryManager() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    return $user && strtolower($user['role_name']) === 'inventory manager';
}
