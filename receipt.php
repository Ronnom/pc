<?php
require_once 'includes/init.php';
requireLogin();
requirePermission('sales.view');

$pageTitle = 'Receipt';
$salesModule = new SalesModule();
$txId = (int)($_GET['transaction_id'] ?? 0);
$transaction = $txId > 0 ? $salesModule->getTransaction($txId) : null;
$items = $transaction ? $salesModule->getTransactionItems($txId) : [];
$payments = $transaction ? $salesModule->getPayments($txId) : [];
$paid = 0;
foreach ($payments as $p) {
    $paid += (float)$p['amount'];
}

include 'templates/header.php';
?>
<div class="row">
    <div class="col-lg-7 mx-auto">
        <?php if (!$transaction): ?>
            <div class="alert alert-warning">Receipt not found.</div>
        <?php else: ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h5 class="mb-0">Receipt</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="window.print()">Print</button>
                        <a href="<?php echo getBaseUrl(); ?>/transaction_history.php" class="btn btn-outline-secondary">History</a>
                    </div>
                </div>
                <div class="card-body small" id="receipt-print">
                    <div class="text-center mb-2">
                        <h6 class="mb-0"><?php echo escape(APP_NAME); ?></h6>
                        <div><?php echo escape(APP_URL); ?></div>
                    </div>
                    <div>Transaction: <strong><?php echo escape($transaction['transaction_number']); ?></strong></div>
                    <div>Date: <?php echo escape(formatDateTime($transaction['transaction_date'])); ?></div>
                    <div>Cashier: <?php echo escape($transaction['cashier_name']); ?></div>
                    <div>Customer: <?php echo escape(trim(($transaction['first_name'] ?? '') . ' ' . ($transaction['last_name'] ?? '')) ?: 'Guest'); ?></div>
                    <hr>
                    <table class="table table-sm">
                        <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td>
                                    <?php echo escape($it['product_name']); ?>
                                    <?php if (!empty($it['serial_number'])): ?>
                                        <div class="text-muted" style="font-size: 11px;">SN: <?php echo escape($it['serial_number']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$it['quantity']; ?></td>
                                <td><?php echo number_format((float)$it['unit_price'], 2); ?></td>
                                <td><?php echo number_format((float)$it['total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <hr>
                    <div class="d-flex justify-content-between"><span>Subtotal</span><span><?php echo number_format((float)$transaction['subtotal'], 2); ?></span></div>
                    <div class="d-flex justify-content-between"><span>Tax</span><span><?php echo number_format((float)$transaction['tax_amount'], 2); ?></span></div>
                    <div class="d-flex justify-content-between"><span>Discount</span><span><?php echo number_format((float)$transaction['discount_amount'], 2); ?></span></div>
                    <div class="d-flex justify-content-between fw-bold"><span>Total</span><span><?php echo number_format((float)$transaction['total_amount'], 2); ?></span></div>
                    <div class="d-flex justify-content-between"><span>Paid</span><span><?php echo number_format($paid, 2); ?></span></div>
                    <div class="d-flex justify-content-between"><span>Change</span><span><?php echo number_format(max(0, $paid - (float)$transaction['total_amount']), 2); ?></span></div>
                    <hr>
                    <div class="mb-1">Payment Methods:</div>
                    <ul class="mb-2">
                        <?php foreach ($payments as $p): ?>
                            <li><?php echo escape(ucfirst($p['payment_method'])); ?> - <?php echo number_format((float)$p['amount'], 2); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="text-center">
                        <img alt="QR" src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?php echo urlencode($transaction['transaction_number']); ?>">
                        <div class="mt-2">Returns accepted with receipt within policy period.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
