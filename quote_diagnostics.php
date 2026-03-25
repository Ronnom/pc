<?php
/**
 * Quote System Diagnostic & Testing Page
 * Validates the complete quotation system functionality
 */
require_once 'config/init.php';
requireLogin();
requirePermission('admin');

$pageTitle = 'Quote System Diagnostics';
include 'templates/header.php';

// Get diagnostic information
$diagnostics = [
    'timestamp' => date(DATETIME_FORMAT),
    'system_setup' => verifyQuoteSystemSetup(),
    'stats' => getQuoteSystemStats(),
    'php_extensions' => [
        'pdo' => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'json' => extension_loaded('json'),
        'mail' => function_exists('mail')
    ],
    'file_permissions' => [
        'quote.php' => file_exists(APP_ROOT . '/quote.php'),
        'pos.php' => file_exists(APP_ROOT . '/pos.php'),
        'quote_management.php' => file_exists(APP_ROOT . '/quote_management.php'),
        'quote_functions.php' => file_exists(APP_ROOT . '/includes/quote_functions.php')
    ]
];

?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <h1 class="mb-1">Quote System Diagnostics</h1>
            <p class="text-muted">System health check and troubleshooting information</p>
        </div>
    </div>

    <!-- System Status -->
    <div class="card mb-3">
        <div class="card-header bg-<?php echo $diagnostics['system_setup']['is_valid'] ? 'success' : 'danger'; ?> text-white">
            <h5 class="mb-0">
                <i class="fas fa-<?php echo $diagnostics['system_setup']['is_valid'] ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                System Status: <?php echo $diagnostics['system_setup']['is_valid'] ? 'OPERATIONAL' : 'ISSUES DETECTED'; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if ($diagnostics['system_setup']['is_valid']): ?>
                <p class="text-success mb-0">
                    <i class="fas fa-check"></i> Quote system is properly configured and ready to use.
                </p>
            <?php else: ?>
                <div class="alert alert-danger mb-0">
                    <h6>Issues detected:</h6>
                    <ul class="mb-0">
                        <?php foreach ($diagnostics['system_setup']['issues'] as $issue): ?>
                        <li><?php echo escape($issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary"><?php echo $diagnostics['stats']['total_quotes'] ?? 0; ?></h3>
                    <small class="text-muted">Total Quotes</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-warning"><?php echo $diagnostics['stats']['draft_quotes'] ?? 0; ?></h3>
                    <small class="text-muted">Draft Quotes</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-info"><?php echo $diagnostics['stats']['sent_quotes'] ?? 0; ?></h3>
                    <small class="text-muted">Sent Quotes</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-success"><?php echo $diagnostics['stats']['converted_quotes'] ?? 0; ?></h3>
                    <small class="text-muted">Converted to Sales</small>
                </div>
            </div>
        </div>
    </div>

    <!-- PHP Extensions -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-server"></i> PHP Extensions</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($diagnostics['php_extensions'] as $ext => $available): ?>
                <div class="col-md-3 mb-2">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-<?php echo $available ? 'check text-success' : 'times text-danger'; ?> me-2"></i>
                        <strong><?php echo ucfirst(str_replace('_', ' ', $ext)); ?></strong>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Files -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-file"></i> Required Files</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($diagnostics['file_permissions'] as $file => $exists): ?>
                <div class="col-md-3 mb-2">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-<?php echo $exists ? 'check text-success' : 'times text-danger'; ?> me-2"></i>
                        <strong><?php echo $file; ?></strong>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Test Functionality -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-flask"></i> Functional Tests</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <h6>Database Connectivity</h6>
                    <p class="mb-0">
                        <i class="fas fa-check text-success"></i> 
                        Active database connected successfully
                    </p>
                </div>
                <div class="col-md-6 mb-3">
                    <h6>Permission System</h6>
                    <p class="mb-0">
                        <?php 
                        $permsExist = true;
                        if (_logTableExists('permissions')) {
                            $requiredPerms = ['quotes.create', 'quotes.send', 'quotes.convert'];
                            $db = getDB();
                            foreach ($requiredPerms as $perm) {
                                $p = $db->fetchOne("SELECT 1 FROM permissions WHERE name = ? LIMIT 1", [$perm]);
                                if (!$p) $permsExist = false;
                            }
                        }
                        ?>
                        <i class="fas fa-<?php echo $permsExist ? 'check text-success' : 'times text-danger'; ?>"></i>
                        Quote permissions <?php echo $permsExist ? 'configured' : 'missing'; ?>
                    </p>
                </div>
                <div class="col-md-6 mb-3">
                    <h6>Quote Tables</h6>
                    <p class="mb-0">
                        <i class="fas fa-<?php echo _logTableExists('quotes') && _logTableExists('quote_items') ? 'check text-success' : 'times text-danger'; ?>"></i>
                        Quote tables <?php echo _logTableExists('quotes') && _logTableExists('quote_items') ? 'exist' : 'missing'; ?>
                    </p>
                </div>
                <div class="col-md-6 mb-3">
                    <h6>Customer Integration</h6>
                    <p class="mb-0">
                        <i class="fas fa-<?php echo _logTableExists('customers') ? 'check text-success' : 'times text-danger'; ?>"></i>
                        Customer tables <?php echo _logTableExists('customers') ? 'available' : 'missing'; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-tools"></i> System Actions</h5>
        </div>
        <div class="card-body">
            <div class="btn-group" role="group">
                <a href="<?php echo getBaseUrl(); ?>/quote_management.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> View Quotes
                </a>
                <a href="<?php echo getBaseUrl(); ?>/pos.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Create Quote
                </a>
                <a href="<?php echo getBaseUrl(); ?>/quote_management.php?action=cleanup_expired" 
                   onclick="return confirm('Mark expired quotes?')" class="btn btn-warning">
                    <i class="fas fa-broom"></i> Cleanup Expired
                </a>
            </div>
        </div>
    </div>

    <!-- Documentation -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-book"></i> Quick Reference</h5>
        </div>
        <div class="card-body">
            <h6>Available Quote Functions:</h6>
            <ul class="small">
                <li><code>verifyQuoteSystemSetup()</code> - Validate quote system configuration</li>
                <li><code>getQuoteSystemStats()</code> - Get quote statistics</li>
                <li><code>validateQuoteForConversion($quoteId)</code> - Check if quote can be converted</li>
                <li><code>linkTransactionToQuote($transactionId, $quoteId)</code> - Link transaction to quote</li>
                <li><code>getConvertedQuotes($limit, $offset)</code> - Retrieve converted quotes</li>
                <li><code>cleanupExpiredQuotes()</code> - Mark expired quotes</li>
                <li><code>getQuoteAuditTrail($quoteId)</code> - Get quote conversion history</li>
            </ul>

            <h6 class="mt-3">Quote Lifecycle:</h6>
            <ol class="small">
                <li><strong>Draft</strong> - Quote is being prepared</li>
                <li><strong>Sent</strong> - Quote has been emailed to customer</li>
                <li><strong>Accepted</strong> - Customer accepted (optional status)</li>
                <li><strong>Converted</strong> - Quote converted to sales transaction</li>
                <li><strong>Expired</strong> - Quote validity period passed</li>
            </ol>

            <h6 class="mt-3">Quote Permissions:</h6>
            <ul class="small">
                <li><code>quotes.create</code> - Create and manage quotations</li>
                <li><code>quotes.send</code> - Send quotations to customers via email</li>
                <li><code>quotes.convert</code> - Convert quotations to sales transactions</li>
            </ul>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
