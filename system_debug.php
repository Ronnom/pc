<?php
/**
 * SYSTEM DEBUG & DIAGNOSTIC TOOL
 * Checks: Database connectivity, quote tables, permissions, user rights, file integrity
 */

// Basic error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/config/init.php';
    $loggedIn = false;
    $currentUser = null;
} catch (Exception $e) {
    echo "CRITICAL: Cannot load init.php\n";
    echo "Error: " . htmlspecialchars($e->getMessage()) . "\n";
    exit(1);
}

// If logged in, require authentication
if (isset($_SESSION['user_id'])) {
    $loggedIn = true;
    $currentUser = $_SESSION['username'] ?? 'Unknown';
} else {
    // Allow public check if not logged in (but skip permission details)
    $loggedIn = false;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PC_POS System Diagnostics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .container { max-width: 1200px; margin: 30px auto; }
        .status-ok { color: #28a745; font-weight: bold; }
        .status-error { color: #dc3545; font-weight: bold; }
        .status-warn { color: #ffc107; font-weight: bold; }
        .check-item { line-height: 2; border-bottom: 1px solid #e9ecef; padding: 12px 0; }
        .check-item:last-child { border-bottom: none; }
        .icon { margin-right: 8px; }
        h2 { margin-top: 30px; margin-bottom: 15px; font-size: 1.3rem; font-weight: 600; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; }
        .badge { margin-left: 10px; }
        pre { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 12px; overflow-x: auto; max-height: 300px; }
        .alert { margin-top: 10px; }
    </style>
</head>
<body>

<div class="container">
    <div class="row mb-4">
        <div class="col">
            <h1>PC_POS System Diagnostics</h1>
            <p class="text-muted">Complete system health check for Quote functionality</p>
            <?php if ($loggedIn): ?>
                <p><strong>Logged in as:</strong> <?php echo htmlspecialchars($currentUser); ?> 
                   <span class="badge bg-info">Authenticated</span></p>
            <?php else: ?>
                <p><span class="badge bg-warning text-dark">Not authenticated - some checks skipped</span></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- 1. DATABASE CONNECTION -->
    <h2>1. Database Connection</h2>
    <div class="card">
        <div class="card-body">
            <?php
            try {
                $db = getDB();
                echo '<div class="check-item">';
                echo '<span class="icon">✓</span>';
                echo '<span class="status-ok">Connected to database</span>';
                echo '</div>';
                
                // Get database info
                $result = $db->query("SELECT VERSION() AS version, DATABASE() AS db_name")->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    echo '<div class="check-item">MySQL Version: <strong>' . htmlspecialchars($result['version']) . '</strong></div>';
                    echo '<div class="check-item">Current Database: <strong>' . htmlspecialchars($result['db_name']) . '</strong></div>';
                }
            } catch (Exception $e) {
                echo '<div class="check-item"><span class="icon">✗</span>';
                echo '<span class="status-error">Database Connection Failed</span></div>';
                echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            }
            ?>
        </div>
    </div>

    <!-- 2. QUOTE TABLES -->
    <h2>2. Quote Tables Status</h2>
    <div class="card">
        <div class="card-body">
            <?php
            try {
                $db = getDB();
                
                // Check if tables exist
                $tables = ['quotes', 'quote_items'];
                foreach ($tables as $table) {
                    $checkQuery = "SELECT 1 FROM information_schema.TABLES 
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
                    $result = $db->fetchOne($checkQuery, [$table]);
                    
                    if ($result) {
                        echo '<div class="check-item">';
                        echo '<span class="icon">✓</span>';
                        echo '<span class="status-ok">Table `' . htmlspecialchars($table) . '` exists</span>';
                        
                        // Show row count
                        $countResult = $db->fetchOne("SELECT COUNT(*) as cnt FROM " . $table);
                        if ($countResult) {
                            echo ' <span class="badge bg-info">' . (int)$countResult['cnt'] . ' rows</span>';
                        }
                        echo '</div>';
                    } else {
                        echo '<div class="check-item">';
                        echo '<span class="icon">✗</span>';
                        echo '<span class="status-error">Table `' . htmlspecialchars($table) . '` MISSING</span>';
                        echo '<div class="alert alert-danger mt-2">';
                        echo '<strong>ACTION REQUIRED:</strong> Run the database migration to create quote tables.<br>';
                        echo 'Execute: <code>normalized_schema_revised.sql</code> in your MySQL database.';
                        echo '</div>';
                        echo '</div>';
                    }
                }
            } catch (Exception $e) {
                echo '<div class="check-item"><span class="icon">✗</span>';
                echo '<span class="status-error">Error checking tables: ' . htmlspecialchars($e->getMessage()) . '</span></div>';
            }
            ?>
        </div>
    </div>

    <!-- 3. PERMISSIONS TABLE -->
    <h2>3. Quote Permissions</h2>
    <div class="card">
        <div class="card-body">
            <?php
            try {
                $db = getDB();
                
                // Check permissions table
                $permCheckQuery = "SELECT 1 FROM information_schema.TABLES 
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions' LIMIT 1";
                $permTableExists = $db->fetchOne($permCheckQuery);
                
                if (!$permTableExists) {
                    echo '<div class="check-item"><span class="icon">✗</span>';
                    echo '<span class="status-error">Permissions table does not exist</span></div>';
                } else {
                    echo '<div class="check-item"><span class="icon">✓</span>';
                    echo '<span class="status-ok">Permissions table exists</span></div>';
                    
                    // Check for quote permissions
                    $perms = ['quotes.create', 'quotes.send', 'quotes.convert'];
                    foreach ($perms as $perm) {
                        $result = $db->fetchOne(
                            "SELECT id FROM permissions WHERE name = ? LIMIT 1",
                            [$perm]
                        );
                        
                        if ($result) {
                            echo '<div class="check-item">';
                            echo '<span class="icon">✓</span>';
                            echo '<span class="status-ok">Permission `' . htmlspecialchars($perm) . '` exists</span>';
                            echo '</div>';
                        } else {
                            echo '<div class="check-item">';
                            echo '<span class="icon">✗</span>';
                            echo '<span class="status-error">Permission `' . htmlspecialchars($perm) . '` MISSING</span>';
                            echo '</div>';
                        }
                    }
                }
            } catch (Exception $e) {
                echo '<div class="check-item"><span class="icon">✗</span>';
                echo '<span class="status-error">Error: ' . htmlspecialchars($e->getMessage()) . '</span></div>';
            }
            ?>
        </div>
    </div>

    <!-- 4. USER PERMISSIONS (if logged in) -->
    <?php if ($loggedIn): ?>
    <h2>4. Your User Permissions</h2>
    <div class="card">
        <div class="card-body">
            <?php
            try {
                $db = getDB();
                $userId = $_SESSION['user_id'] ?? null;
                
                if (!$userId) {
                    echo '<div class="alert alert-warning">Cannot determine user ID</div>';
                } else {
                    // Get user's role
                    $userResult = $db->fetchOne(
                        "SELECT role_id FROM users WHERE id = ? LIMIT 1",
                        [$userId]
                    );
                    
                    if (!$userResult || !$userResult['role_id']) {
                        echo '<div class="check-item"><span class="icon">⚠</span>';
                        echo '<span class="status-warn">No role assigned to user</span></div>';
                    } else {
                        $roleId = $userResult['role_id'];
                        
                        // Get role name
                        $roleResult = $db->fetchOne(
                            "SELECT name FROM roles WHERE id = ? LIMIT 1",
                            [$roleId]
                        );
                        
                        if ($roleResult) {
                            echo '<div class="check-item">';
                            echo '<span class="icon">ℹ</span>';
                            echo 'Your Role: <strong>' . htmlspecialchars($roleResult['name']) . '</strong>';
                            echo '</div>';
                        }
                        
                        // Get permissions
                        $permsResult = $db->fetchAll(
                            "SELECT p.name FROM permissions p
                             JOIN role_permissions rp ON p.id = rp.permission_id
                             WHERE rp.role_id = ?
                             ORDER BY p.name",
                            [$roleId]
                        );
                        
                        echo '<div class="check-item">';
                        echo 'Total Permissions: <strong>' . count($permsResult) . '</strong>';
                        echo '</div>';
                        
                        // Check for quote permissions
                        $hasQuotePerms = false;
                        foreach ($permsResult as $perm) {
                            if (strpos($perm['name'], 'quotes.') === 0) {
                                echo '<div class="check-item">';
                                echo '<span class="icon">✓</span>';
                                echo '<span class="status-ok">You have: ' . htmlspecialchars($perm['name']) . '</span>';
                                echo '</div>';
                                $hasQuotePerms = true;
                            }
                        }
                        
                        if (!$hasQuotePerms) {
                            echo '<div class="check-item">';
                            echo '<span class="icon">✗</span>';
                            echo '<span class="status-error">You do NOT have any quote permissions</span>';
                            echo '<div class="alert alert-danger mt-2">';
                            echo '<strong>ACTION REQUIRED:</strong> Admin must assign quote permissions to your role.';
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error checking permissions: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 5. FILE INTEGRITY -->
    <h2>5. Quote System Files</h2>
    <div class="card">
        <div class="card-body">
            <?php
            $requiredFiles = [
                'quote.php' => 'Main quote page',
                'quote_management.php' => 'Quote management dashboard',
                'quote_diagnostics.php' => 'Diagnostic tool',
                'includes/quote_functions.php' => 'Quote utility functions',
                'pos.php' => 'POS interface'
            ];
            
            foreach ($requiredFiles as $file => $desc) {
                $fullPath = __DIR__ . '/' . $file;
                if (file_exists($fullPath)) {
                    echo '<div class="check-item">';
                    echo '<span class="icon">✓</span>';
                    echo '<span class="status-ok">✓ ' . htmlspecialchars($file) . '</span>';
                    echo ' <small class="text-muted">(' . htmlspecialchars($desc) . ')</small>';
                    
                    $size = filesize($fullPath);
                    echo ' <span class="badge bg-info">' . round($size / 1024, 1) . ' KB</span>';
                    echo '</div>';
                } else {
                    echo '<div class="check-item">';
                    echo '<span class="icon">✗</span>';
                    echo '<span class="status-error">✗ ' . htmlspecialchars($file) . ' MISSING</span>';
                    echo '</div>';
                }
            }
            ?>
        </div>
    </div>

    <!-- 6. QUICK ACTIONS -->
    <h2>6. Quick Actions</h2>
    <div class="card">
        <div class="card-body">
            <p class="mb-3">Use these links to test your quote system:</p>
            <div class="btn-group" role="group">
                <?php if ($loggedIn): ?>
                    <a href="/quote_diagnostics.php" class="btn btn-primary" target="_blank">
                        Run Full Diagnostics
                    </a>
                    <a href="/quote.php?id=1" class="btn btn-secondary" target="_blank">
                        Test Quote Display (ID=1)
                    </a>
                    <a href="/quote_management.php" class="btn btn-secondary" target="_blank">
                        Quote Management
                    </a>
                <?php else: ?>
                    <span class="text-muted">Please log in to access quote pages</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 7. DATABASE SCHEMA CHECK -->
    <h2>7. Database Schema Details</h2>
    <div class="card">
        <div class="card-body">
            <?php
            try {
                $db = getDB();
                
                // List all tables
                $tables = $db->fetchAll("SELECT TABLE_NAME FROM information_schema.TABLES 
                                        WHERE TABLE_SCHEMA = DATABASE() 
                                        ORDER BY TABLE_NAME");
                
                echo '<strong>Tables in database (' . count($tables) . '):</strong>';
                echo '<div class="mt-2">';
                
                $quoteTableFound = false;
                foreach ($tables as $table) {
                    $badge = '';
                    if ($table['TABLE_NAME'] === 'quotes' || $table['TABLE_NAME'] === 'quote_items') {
                        $badge = ' <span class="badge bg-success">Quote System</span>';
                        $quoteTableFound = true;
                    }
                    echo '<span class="badge bg-secondary">' . htmlspecialchars($table['TABLE_NAME']) . '</span>' . $badge . ' ';
                }
                echo '</div>';
                
                if (!$quoteTableFound) {
                    echo '<div class="alert alert-danger mt-3">';
                    echo '<strong>CRITICAL:</strong> Quote tables (`quotes`, `quote_items`) not found in database.<br>';
                    echo '<strong>Solution:</strong> You must execute <code>database/normalized_schema_revised.sql</code> on your MySQL database.';
                    echo '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="mt-5 mb-5" style="text-align: center; color: #666; font-size: 0.9rem;">
        <p>System Diagnostics generated: <?php echo date('Y-m-d H:i:s'); ?></p>
        <p>This tool helps identify configuration issues with the quote system.</p>
    </div>

</div>

</body>
</html>
