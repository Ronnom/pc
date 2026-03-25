<?php
require_once 'includes/init.php';
requireLogin();
requirePermission('sales.create');
$pageTitle = 'POS Payment';
include 'templates/header.php';
?>
<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Payment Processing</h5>
            </div>
            <div class="card-body">
                <p class="mb-3">Use checkout for full payment finalization including split payments, cash change, and receipt generation.</p>
                <div class="alert alert-info mb-3">
                    Supported methods: Cash, Card, Digital Wallet, Bank Transfer, Check, Split Payment.
                </div>
                <div class="d-grid gap-2">
                    <a class="btn btn-primary" href="<?php echo getBaseUrl(); ?>/checkout.php">Go to Checkout</a>
                    <a class="btn btn-outline-secondary" href="<?php echo getBaseUrl(); ?>/cart.php">Back to Cart</a>
                    <a class="btn btn-outline-secondary" href="<?php echo getBaseUrl(); ?>/pos.php">Back to POS</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
