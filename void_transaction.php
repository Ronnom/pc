<?php
/**
 * Void Transaction Page
 * Admin-only: void completed transactions with inventory restoration and audit trail
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.admin');

$db = getDB();
$transaction_id = (int)($_GET['id'] ?? 0);

if (!$transaction_id) {
    setFlashMessage('error', 'Invalid transaction ID');
    redirect('transaction_history.php');
}

// Fetch transaction
$txn = $db->fetch(
    "SELECT t.*, c.first_name, c.last_name, u.first_name as cashier_first, u.last_name as cashier_last
     FROM transactions t
     LEFT JOIN customers c ON t.customer_id = c.id
     LEFT JOIN users u ON t.user_id = u.id
     WHERE t.id = ?",
    [$transaction_id]
);

if (!$txn) {
    setFlashMessage('error', 'Transaction not found');
    redirect('transaction_history.php');
}

// Only allow voiding completed or pending transactions
if (!in_array($txn['status'], ['completed', 'pending'])) {
    setFlashMessage('error', 'Only completed or pending transactions can be voided');
    redirect('invoice.php?id=' . $transaction_id);
}

// Get transaction items
$items = $db->fetchAll(
    "SELECT ti.*, p.name, p.product_code
     FROM transaction_items ti
     JOIN products p ON ti.product_id = p.id
     WHERE ti.transaction_id = ?",
    [$transaction_id]
);

// Get payments
$payments = $db->fetchAll(
    "SELECT * FROM payments WHERE transaction_id = ?",
    [$transaction_id]
);

// Handle void request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $void_reason = trim($_POST['void_reason'] ?? '');
    $confirm_void = isset($_POST['confirm_void']);

    if (!$confirm_void || empty($void_reason)) {
        setFlashMessage('error', 'Please confirm void and provide a reason');
    } else {
        try {
            // Start transaction
            $db->beginTransaction();

            // Restore inventory for each item
            foreach ($items as $item) {
                // Update product stock
                $db->execute(
                    "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?",
                    [$item['quantity'], $item['product_id']]
                );

                // Log stock adjustment
                $db->insert('product_stock_adjustments', [
                    'product_id' => $item['product_id'],
                    'adjustment_type' => 'refund',
                    'quantity' => $item['quantity'],
                    'reason' => 'Transaction void: ' . $void_reason,
                    'reference_id' => $transaction_id,
                    'adjusted_by_user_id' => getCurrentUserId(),
                    'adjusted_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Update transaction status
            $new_status = 'voided';
            $db->execute(
                "UPDATE transactions
                 SET status = ?, payment_status = 'pending', notes = CONCAT(IFNULL(notes, ''), '\n\n[VOIDED] Reason: ', ?)
                 WHERE id = ?",
                [$new_status, $void_reason, $transaction_id]
            );

            // Log activity
            logUserActivity('transaction_void', 'Voided transaction #' . $txn['transaction_number'], [
                'transaction_id' => $transaction_id,
                'void_reason' => $void_reason,
                'items_restored' => count($items),
                'amount_voided' => $txn['total_amount']
            ]);

            // Commit transaction
            $db->commit();

            setFlashMessage('success', 'Transaction successfully voided. Inventory restored. ' . count($items) . ' items returned to stock.');
            redirect('transaction_history.php');

        } catch (Exception $e) {
            $db->rollback();
            setFlashMessage('error', 'Error voiding transaction: ' . $e->getMessage());
        }
    }
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-3">Void Transaction</h1>
        <p class="text-muted">
            <strong>⚠️ WARNING:</strong> This action cannot be undone. Voiding will:
            <ul>
                <li>Mark transaction as VOIDED</li>
                <li>Restore all <?php echo count($items); ?> items to inventory</li>
                <li>Create audit trail with reason</li>
            </ul>
        </p>
    </div>
</div>

<!-- Transaction Details -->
<div class="card mb-4">
    <div class="card-header">Transaction Details</div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <dl class="row">
                    <dt class="col-sm-4">Transaction #:</dt>
                    <dd class="col-sm-8"><strong><?php echo escape($txn['transaction_number']); ?></strong></dd>

                    <dt class="col-sm-4">Date:</dt>
                    <dd class="col-sm-8"><?php echo formatDateTime($txn['transaction_date']); ?></dd>

                    <dt class="col-sm-4">Customer:</dt>
                    <dd class="col-sm-8"><?php echo escape($txn['first_name'] . ' ' . $txn['last_name']); ?></dd>

                    <dt class="col-sm-4">Cashier:</dt>
                    <dd class="col-sm-8"><?php echo escape($txn['cashier_first'] . ' ' . $txn['cashier_last']); ?></dd>
                </dl>
            </div>
            <div class="col-md-6">
                <dl class="row">
                    <dt class="col-sm-4">Subtotal:</dt>
                    <dd class="col-sm-8"><?php echo formatCurrency($txn['subtotal']); ?></dd>

                    <dt class="col-sm-4">Tax:</dt>
                    <dd class="col-sm-8"><?php echo formatCurrency($txn['tax_amount']); ?></dd>

                    <dt class="col-sm-4">Discount:</dt>
                    <dd class="col-sm-8">-<?php echo formatCurrency($txn['discount_amount']); ?></dd>

                    <dt class="col-sm-4">Total:</dt>
                    <dd class="col-sm-8"><strong><?php echo formatCurrency($txn['total_amount']); ?></strong></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Items Being Restored -->
<div class="card mb-4">
    <div class="card-header">Items to be Restored (<?php echo count($items); ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo escape($item['name']); ?></td>
                        <td><code><?php echo escape($item['product_code']); ?></code></td>
                        <td class="text-center"><?php echo formatDecimal($item['quantity'], 2); ?></td>
                        <td><?php echo formatCurrency($item['unit_price']); ?></td>
                        <td><?php echo formatCurrency($item['total']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Void Form -->
<div class="card mb-4 border-danger">
    <div class="card-header bg-danger text-white">
        <strong>Void Transaction Form</strong>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Reason for Void <span class="text-danger">*</span></label>
                <textarea class="form-control" name="void_reason" rows="4" placeholder="Enter detailed reason for voiding this transaction..." required></textarea>
                <small class="text-muted">This will be recorded in the audit trail.</small>
            </div>

            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="confirm_void" id="confirm_void" required>
                    <label class="form-check-label" for="confirm_void">
                        I confirm that I want to PERMANENTLY VOID this transaction.<br>
                        <small class="text-danger">This action cannot be undone.</small>
                    </label>
                </div>
            </div>

            <div class="d-grid gap-2 d-sm-flex justify-content-sm-end">
                <a href="<?php echo getBaseUrl(); ?>/invoice.php?id=<?php echo $transaction_id; ?>" class="btn btn-outline-secondary">
                    Cancel
                </a>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-exclamation-triangle"></i> Void Transaction
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Payments Info -->
<?php if (!empty($payments)): ?>
<div class="card mb-4">
    <div class="card-header">Payment Information</div>
    <div class="card-body">
        <p class="text-muted">
            After void, the following payments will remain in the system for reconciliation:
        </p>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th>Amount</th>
                        <th>Reference</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                        <td><?php echo formatCurrency($payment['amount']); ?></td>
                        <td><?php echo escape($payment['reference_number'] ?? '-'); ?></td>
                        <td><?php echo formatDateTime($payment['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted small">
            <strong>Note:</strong> You may need to process refunds through your payment processor separately.
        </p>
    </div>
</div>
<?php endif; ?>

<?php include 'templates/footer.php'; ?>
