<?php
/**
 * Customer Profile Page
 * Display customer information, statistics, purchase history, and management options
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('customers.view');

$db = getDB();
$customer_id = (int)($_GET['id'] ?? 0);

function customerProfileHasColumn($db, $columnName) {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'customers'
           AND COLUMN_NAME = ?",
        [$columnName]
    );
    return (int)($row['cnt'] ?? 0) > 0;
}

$hasCustomerType = customerProfileHasColumn($db, 'customer_type');
$hasType = customerProfileHasColumn($db, 'type');
$hasIsActive = customerProfileHasColumn($db, 'is_active');
$hasStatus = customerProfileHasColumn($db, 'status');
$hasLoyaltyPoints = customerProfileHasColumn($db, 'loyalty_points');
$hasLoyaltyTier = customerProfileHasColumn($db, 'loyalty_tier');

$customerTypeExpr = $hasCustomerType
    ? "c.`customer_type`"
    : ($hasType ? "c.`type`" : "'individual'");
$customerStatusExpr = $hasIsActive
    ? "c.`is_active`"
    : ($hasStatus ? "c.`status`" : "1");
$loyaltyPointsExpr = $hasLoyaltyPoints ? "c.`loyalty_points`" : "0";
$loyaltyTierExpr = $hasLoyaltyTier ? "c.`loyalty_tier`" : "'None'";

if (!$customer_id) {
    setFlashMessage('error', 'Invalid customer ID');
    redirect('customers.php');
}

// Fetch customer
$customer = $db->fetchOne(
    "SELECT c.*,
            {$customerTypeExpr} AS customer_type,
            {$customerStatusExpr} AS is_active,
            {$loyaltyPointsExpr} AS loyalty_points,
            {$loyaltyTierExpr} AS loyalty_tier
     FROM customers c
     WHERE c.id = ?",
    [$customer_id]
);

if (!$customer) {
    setFlashMessage('error', 'Customer not found');
    redirect('customers.php');
}

// Handle status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    
    if ($action === 'toggle_status') {
        if ($hasIsActive) {
            $db->query("UPDATE customers SET `is_active` = IF(`is_active`=1,0,1) WHERE id = ?", [$customer_id]);
            $customer['is_active'] = !$customer['is_active'];
            logUserActivity('customer_status_toggle', 'Toggled status for customer ' . $customer['first_name'] . ' ' . $customer['last_name'], [
                'customer_id' => $customer_id,
                'new_status' => $customer['is_active'] ? 'active' : 'inactive'
            ]);
            setFlashMessage('success', 'Customer status updated');
        } elseif ($hasStatus) {
            $db->query("UPDATE customers SET `status` = IF(`status`=1,0,1) WHERE id = ?", [$customer_id]);
            $customer['is_active'] = !$customer['is_active'];
            logUserActivity('customer_status_toggle', 'Toggled status for customer ' . $customer['first_name'] . ' ' . $customer['last_name'], [
                'customer_id' => $customer_id,
                'new_status' => $customer['is_active'] ? 'active' : 'inactive'
            ]);
            setFlashMessage('success', 'Customer status updated');
        } else {
            setFlashMessage('error', 'Customer status column is not available in this schema.');
        }
    } elseif ($action === 'update_notes') {
        $notes = trim($_POST['notes'] ?? '');
        $db->query(
            "UPDATE customers SET notes = ? WHERE id = ?",
            [$notes, $customer_id]
        );
        $customer['notes'] = $notes;
        logUserActivity('customer_notes_update', 'Updated notes for customer ' . $customer['first_name'], [
            'customer_id' => $customer_id
        ]);
        setFlashMessage('success', 'Notes updated');
    }
}

// Get customer statistics
$stats = $db->fetchOne(
    "SELECT 
        COUNT(DISTINCT t.id) as total_transactions,
        SUM(CASE WHEN t.status = 'completed' THEN t.total_amount ELSE 0 END) as total_spent,
        AVG(CASE WHEN t.status = 'completed' THEN t.total_amount ELSE NULL END) as avg_order,
        MAX(CASE WHEN t.status = 'completed' THEN t.transaction_date ELSE NULL END) as last_purchase,
        DATEDIFF(NOW(), MAX(CASE WHEN t.status = 'completed' THEN t.transaction_date ELSE NULL END)) as days_since_purchase
     FROM transactions t
     WHERE t.customer_id = ?",
    [$customer_id]
);

// Get favorite category
$favorite_category = $db->fetchOne(
    "SELECT c.id, c.name, COUNT(ti.id) as item_count
     FROM transaction_items ti
     JOIN products p ON ti.product_id = p.id
     JOIN categories c ON p.category_id = c.id
     JOIN transactions t ON ti.transaction_id = t.id
     WHERE t.customer_id = ? AND t.status = 'completed'
     GROUP BY c.id
     ORDER BY item_count DESC
     LIMIT 1",
    [$customer_id]
);

// Get purchase history
$purchase_history = $db->fetchAll(
    "SELECT t.id, t.transaction_number, t.transaction_date, t.total_amount, 
            t.status, t.payment_status, COUNT(ti.id) as item_count
     FROM transactions t
     LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
     WHERE t.customer_id = ?
     GROUP BY t.id
     ORDER BY t.transaction_date DESC
     LIMIT 10",
    [$customer_id]
);

// Get payment methods used
$payment_methods = $db->fetchAll(
    "SELECT DISTINCT p.payment_method, COUNT(p.id) as usage_count
     FROM payments p
     JOIN transactions t ON p.transaction_id = t.id
     WHERE t.customer_id = ?
     GROUP BY p.payment_method
     ORDER BY usage_count DESC",
    [$customer_id]
);

$pageTitle = 'Customer Profile: ' . $customer['first_name'] . ' ' . $customer['last_name'];
include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-0"><?php echo escape($customer['first_name'] . ' ' . $customer['last_name']); ?></h1>
                <small class="text-muted">ID: <?php echo escape($customer['customer_code']); ?></small>
            </div>
            <div>
                <a href="<?php echo getBaseUrl(); ?>/customer_form.php?id=<?php echo $customer_id; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <a href="<?php echo getBaseUrl(); ?>/loyalty.php?id=<?php echo $customer_id; ?>" class="btn btn-outline-info">
                    <i class="bi bi-gift"></i> Loyalty Details
                </a>
                <a href="<?php echo getBaseUrl(); ?>/communications.php?id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-envelope"></i> Send Message
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Status Bar -->
<div class="row mb-4">
    <div class="col-12">
        <div class="alert <?php echo $customer['is_active'] ? 'alert-success' : 'alert-warning'; ?>" role="alert">
            <strong>Status:</strong> 
            <span class="badge <?php echo $customer['is_active'] ? 'bg-success' : 'bg-warning'; ?>">
                <?php echo $customer['is_active'] ? 'ACTIVE' : 'INACTIVE'; ?>
            </span>
            <form method="POST" class="d-inline-block" style="margin-left: 20px;">
                <input type="hidden" name="action" value="toggle_status">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <?php echo $customer['is_active'] ? 'Deactivate' : 'Reactivate'; ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Key Statistics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Total Spent (Lifetime)</div>
                <div class="h3 mb-0 text-success"><?php echo formatCurrency($stats['total_spent'] ?? 0); ?></div>
                <small class="text-muted"><?php echo (int)($stats['total_transactions'] ?? 0); ?> transactions</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Avg Order Value</div>
                <div class="h3 mb-0" style="color: #0066cc;"><?php echo formatCurrency($stats['avg_order'] ?? 0); ?></div>
                <small class="text-muted">Average per transaction</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Last Purchase</div>
                <div class="h5 mb-0">
                    <?php echo $stats['last_purchase'] ? formatDate($stats['last_purchase']) : 'Never'; ?>
                </div>
                <small class="text-muted">
                    <?php 
                    if ($stats['days_since_purchase']) {
                        echo $stats['days_since_purchase'] . ' days ago';
                    }
                    ?>
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Loyalty Program</div>
                <div class="h5 mb-0"><?php echo escape($customer['loyalty_tier'] ?? 'None'); ?></div>
                <small class="text-muted"><?php echo (int)($customer['loyalty_points'] ?? 0); ?> points</small>
            </div>
        </div>
    </div>
</div>

<!-- Customer Information -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Personal Information</div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-4">Full Name:</dt>
                    <dd class="col-sm-8">
                        <?php echo escape($customer['first_name'] . ' ' . ($customer['middle_name'] ? $customer['middle_name'] . ' ' : '') . $customer['last_name']); ?>
                    </dd>
                    
                    <dt class="col-sm-4">Email:</dt>
                    <dd class="col-sm-8">
                        <a href="mailto:<?php echo escape($customer['email']); ?>">
                            <?php echo escape($customer['email']); ?>
                        </a>
                    </dd>
                    
                    <dt class="col-sm-4">Phone:</dt>
                    <dd class="col-sm-8">
                        <a href="tel:<?php echo escape($customer['phone']); ?>">
                            <?php echo formatPhoneNumber($customer['phone']); ?>
                        </a>
                    </dd>
                    
                    <?php if (!empty($customer['dob'])): ?>
                    <dt class="col-sm-4">Date of Birth:</dt>
                    <dd class="col-sm-8"><?php echo formatDate($customer['dob']); ?></dd>
                    <?php endif; ?>
                    
                    <dt class="col-sm-4">Type:</dt>
                    <dd class="col-sm-8"><?php echo ucfirst($customer['customer_type']); ?></dd>
                    
                    <?php if ($customer['customer_type'] === 'business' && !empty($customer['tax_id'])): ?>
                    <dt class="col-sm-4">Tax ID:</dt>
                    <dd class="col-sm-8"><?php echo escape($customer['tax_id']); ?></dd>
                    <?php endif; ?>
                    
                    <dt class="col-sm-4">Member Since:</dt>
                    <dd class="col-sm-8"><?php echo formatDate($customer['created_at']); ?></dd>
                </dl>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Address Information</div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-4">Street:</dt>
                    <dd class="col-sm-8"><?php echo escape($customer['address'] ?? '-'); ?></dd>
                    
                    <dt class="col-sm-4">City:</dt>
                    <dd class="col-sm-8"><?php echo escape($customer['city'] ?? '-'); ?></dd>
                    
                    <dt class="col-sm-4">Province:</dt>
                    <dd class="col-sm-8"><?php echo escape($customer['province'] ?? '-'); ?></dd>
                    
                    <dt class="col-sm-4">Postal Code:</dt>
                    <dd class="col-sm-8"><?php echo escape($customer['postal_code'] ?? '-'); ?></dd>
                    
                    <dt class="col-sm-4">Country:</dt>
                    <dd class="col-sm-8"><?php echo escape($customer['country'] ?? '-'); ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Favorite Category & Payment Methods -->
<div class="row mb-4">
    <?php if ($favorite_category): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Favorite Category</div>
            <div class="card-body text-center">
                <h4><?php echo escape($favorite_category['name']); ?></h4>
                <p class="text-muted"><?php echo $favorite_category['item_count']; ?> items purchased</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($payment_methods)): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Preferred Payment Methods</div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <?php foreach ($payment_methods as $method): ?>
                    <li class="mb-2">
                        <i class="bi bi-check-circle"></i>
                        <?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?>
                        <span class="badge bg-secondary float-end"><?php echo $method['usage_count']; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Purchase History -->
<div class="card mb-4">
    <div class="card-header">
        Recent Purchases
        <a href="<?php echo getBaseUrl(); ?>/transaction_history.php?customer=<?php echo urlencode($customer['first_name']); ?>" class="btn btn-sm btn-outline-primary float-end">
            View All Transactions
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($purchase_history)): ?>
            <div class="alert alert-info mb-0">No purchase history found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Transaction #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchase_history as $txn): ?>
                        <tr>
                            <td><strong><?php echo escape($txn['transaction_number']); ?></strong></td>
                            <td><?php echo formatDateTime($txn['transaction_date']); ?></td>
                            <td><?php echo (int)$txn['item_count']; ?></td>
                            <td><?php echo formatCurrency($txn['total_amount']); ?></td>
                            <td><?php echo getStatusBadge($txn['status']); ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/invoice.php?id=<?php echo $txn['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Invoice">
                                    <i class="bi bi-receipt"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Notes Section -->
<div class="card">
    <div class="card-header">Notes & Comments</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_notes">
            <div class="mb-3">
                <textarea class="form-control" name="notes" rows="4" placeholder="Add internal notes about this customer..."><?php echo escape($customer['notes'] ?? ''); ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Save Notes
            </button>
        </form>
    </div>
</div>

<?php
/**
 * Format phone number for display
 */
function formatPhoneNumber($phone) {
    if (empty($phone)) return '';
    // Remove all non-digits
    $digits = preg_replace('/[^\d]/', '', $phone);
    // Format if 10 digits
    if (strlen($digits) === 10) {
        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }
    return $phone;
}

include 'templates/footer.php'; ?>
