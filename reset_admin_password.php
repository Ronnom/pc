<?php
/**
 * Reset Admin Password Script
 * Run this once to set the correct admin password
 * DELETE THIS FILE after use for security!
 * 
 * Access: http://localhost/pc_pos/reset_admin_password.php
 */

// Simple initialization without full init (to avoid session issues)
define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';

$newPassword = 'admin123';

try {
    $db = getDB();
    
    // Get admin user
    $admin = $db->fetchOne(
        "SELECT * FROM users WHERE username = 'admin' OR email = 'admin@pcpos.local'"
    );
    
    if (!$admin) {
        die("Admin user not found. Please create an admin user first by running database/schema.sql");
    }
    
    // Generate new password hash using PHP's password_hash
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password
    $db->update('users', 
        ['password_hash' => $passwordHash],
        'id = ?',
        [$admin['id']]
    );
    
    // Verify the hash works
    $verify = password_verify($newPassword, $passwordHash);
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Password Reset</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        <style>
            body { background-color: #f5f5f5; padding-top: 50px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='row justify-content-center'>
                <div class='col-md-6'>
                    <div class='card shadow'>
                        <div class='card-body p-5'>
                            <div class='text-center mb-4'>
                                <div style='font-size: 4rem; color: #28a745;'>✓</div>
                            </div>
                            <div class='alert alert-success'>
                                <h4 class='alert-heading'>✓ Admin Password Reset Successful!</h4>
                                <hr>
                                <p class='mb-2'><strong>Username:</strong> admin</p>
                                <p class='mb-2'><strong>Password:</strong> admin123</p>
                                <p class='mb-0'><strong>Verification:</strong> " . ($verify ? "✓ Hash verified" : "✗ Hash verification failed") . "</p>
                            </div>
                            <div class='alert alert-warning'>
                                <strong>⚠️ SECURITY WARNING:</strong><br>
                                Delete this file (reset_admin_password.php) immediately after use!
                            </div>
                            <div class='text-center mt-4'>
                                <a href='login.php' class='btn btn-primary btn-lg'>
                                    Go to Login Page
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
    
} catch (Exception $e) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Password Reset Error</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head>
    <body>
        <div class='container mt-5'>
            <div class='row justify-content-center'>
                <div class='col-md-6'>
                    <div class='alert alert-danger'>
                        <h4>Error Resetting Password</h4>
                        <p>" . htmlspecialchars($e->getMessage()) . "</p>
                        <hr>
                        <p class='mb-0'>Please check:</p>
                        <ul>
                            <li>Database connection is working</li>
                            <li>Users table exists</li>
                            <li>Admin user exists in database</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
}

