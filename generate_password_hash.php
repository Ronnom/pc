<?php
/**
 * Generate Password Hash
 * This will show you the correct hash for admin123
 */

$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Password Hash Generator</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='row justify-content-center'>
            <div class='col-md-8'>
                <div class='card'>
                    <div class='card-header bg-primary text-white'>
                        <h4>Password Hash for: admin123</h4>
                    </div>
                    <div class='card-body'>
                        <h5>Generated Hash:</h5>
                        <pre style='background: #f8f9fa; padding: 15px; border-radius: 5px; word-break: break-all;'>{$hash}</pre>
                        
                        <h5 class='mt-4'>SQL to Update:</h5>
                        <pre style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>UPDATE users 
SET password_hash = '{$hash}',
    is_active = 1
WHERE username = 'admin' OR email = 'admin@pcpos.local';</pre>
                        
                        <div class='alert alert-info mt-4'>
                            <strong>Instructions:</strong>
                            <ol>
                                <li>Copy the SQL above</li>
                                <li>Open phpMyAdmin</li>
                                <li>Select your database (pc_pos)</li>
                                <li>Go to SQL tab</li>
                                <li>Paste and run the SQL</li>
                                <li>Try logging in with: admin / admin123</li>
                            </ol>
                        </div>
                        
                        <div class='text-center mt-4'>
                            <a href='login.php' class='btn btn-primary'>Go to Login</a>
                            <a href='check_admin.php' class='btn btn-outline-secondary'>Check Admin Status</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>";

