<?php
/**
 * Admin User Diagnostic Script
 * Check admin user status and test password
 */

// Simple initialization
define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';

try {
    $db = getDB();
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Admin User Diagnostic</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        <style>
            body { background-color: #f5f5f5; padding: 20px; }
            pre { background: #f8f9fa; padding: 15px; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='row justify-content-center'>
                <div class='col-md-10'>
                    <div class='card shadow'>
                        <div class='card-header bg-primary text-white'>
                            <h4 class='mb-0'>Admin User Diagnostic</h4>
                        </div>
                        <div class='card-body'>";
    
    // Check if admin user exists
    $admin = $db->fetchOne(
        "SELECT * FROM users WHERE username = 'admin' OR email = 'admin@pcpos.local'"
    );
    
    if (!$admin) {
        echo "<div class='alert alert-danger'>
                <h5>❌ Admin User Not Found</h5>
                <p>The admin user does not exist in the database.</p>
                <p><strong>Solution:</strong> Run the database schema to create the admin user:</p>
                <pre>mysql -u root -p pc_pos < database/schema.sql</pre>
              </div>";
    } else {
        echo "<div class='alert alert-success'>
                <h5>✓ Admin User Found</h5>
                <table class='table table-bordered'>
                    <tr><th>ID</th><td>{$admin['id']}</td></tr>
                    <tr><th>Username</th><td>{$admin['username']}</td></tr>
                    <tr><th>Email</th><td>{$admin['email']}</td></tr>
                    <tr><th>Role ID</th><td>{$admin['role_id']}</td></tr>
                    <tr><th>Is Active</th><td>" . ($admin['is_active'] ? 'Yes' : 'No') . "</td></tr>
                    <tr><th>Password Hash</th><td><code style='font-size: 0.8em;'>{$admin['password_hash']}</code></td></tr>
                </table>
              </div>";
        
        // Test password verification
        $testPassword = 'admin123';
        $verifyResult = password_verify($testPassword, $admin['password_hash']);
        
        echo "<div class='alert " . ($verifyResult ? 'alert-success' : 'alert-danger') . "'>
                <h5>" . ($verifyResult ? '✓' : '❌') . " Password Verification</h5>
                <p><strong>Testing password:</strong> admin123</p>
                <p><strong>Result:</strong> " . ($verifyResult ? 'PASSWORD MATCHES ✓' : 'PASSWORD DOES NOT MATCH ❌') . "</p>
              </div>";
        
        if (!$verifyResult) {
            echo "<div class='alert alert-warning'>
                    <h5>⚠️ Password Hash Mismatch</h5>
                    <p>The password hash in the database does not match 'admin123'.</p>
                    <p><strong>Solution:</strong> Run the password reset script:</p>
                    <p><a href='reset_admin_password.php' class='btn btn-primary'>Reset Admin Password</a></p>
                    <p>Or run this SQL:</p>
                    <pre>UPDATE users SET password_hash = '" . password_hash($testPassword, PASSWORD_DEFAULT) . "' WHERE username = 'admin';</pre>
                  </div>";
        }
        
        // Check if user is active
        if (!$admin['is_active']) {
            echo "<div class='alert alert-warning'>
                    <h5>⚠️ Admin User is Inactive</h5>
                    <p>The admin user exists but is marked as inactive.</p>
                    <p><strong>Solution:</strong> Run this SQL to activate:</p>
                    <pre>UPDATE users SET is_active = 1 WHERE username = 'admin';</pre>
                  </div>";
        }
        
        // Test login function
        echo "<div class='alert alert-info'>
                <h5>Login Function Test</h5>";
        
        // Simulate login
        $testUsername = 'admin';
        $testPassword = 'admin123';
        
        $testUser = $db->fetchOne(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1",
            [$testUsername, $testUsername]
        );
        
        if ($testUser) {
            $passwordMatch = password_verify($testPassword, $testUser['password_hash']);
            echo "<p><strong>User lookup:</strong> ✓ Found</p>";
            echo "<p><strong>Password check:</strong> " . ($passwordMatch ? '✓ Matches' : '❌ Does not match') . "</p>";
            
            if ($passwordMatch) {
                echo "<p class='text-success'><strong>✓ Login should work!</strong></p>";
            } else {
                echo "<p class='text-danger'><strong>❌ Login will fail - password hash is incorrect</strong></p>";
            }
        } else {
            echo "<p class='text-danger'><strong>❌ User not found or inactive</strong></p>";
        }
        
        echo "</div>";
    }
    
    // Show all users
    $allUsers = $db->fetchAll("SELECT id, username, email, is_active, role_id FROM users");
    echo "<div class='alert alert-info'>
            <h5>All Users in Database</h5>
            <table class='table table-sm'>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Active</th>
                        <th>Role ID</th>
                    </tr>
                </thead>
                <tbody>";
    foreach ($allUsers as $user) {
        echo "<tr>
                <td>{$user['id']}</td>
                <td>{$user['username']}</td>
                <td>{$user['email']}</td>
                <td>" . ($user['is_active'] ? 'Yes' : 'No') . "</td>
                <td>{$user['role_id']}</td>
              </tr>";
    }
    echo "</tbody></table></div>";
    
    echo "        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>
            <h5>Database Error</h5>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
            <p>Please check your database connection settings in config/config.php</p>
          </div>";
}

