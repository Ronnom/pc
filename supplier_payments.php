<?php
// supplier_payments.php
// Record/view supplier payments, link to PO, outstanding, aging
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
if (!has_permission('suppliers.payments')) {
    http_response_code(403);
    exit('Forbidden');
}
$pdo = get_db_connection();

// Get suppliers for dropdown
$suppliers = $pdo->query("SELECT id, name, company_name FROM suppliers WHERE status = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission for new payment
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_payment') {
    $payment_data = [
        'supplier_id' => intval($_POST['supplier_id'] ?? 0),
        'po_id' => intval($_POST['po_id'] ?? 0) ?: null,
        'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
        'amount' => floatval($_POST['amount'] ?? 0),
        'payment_method' => $_POST['payment_method'] ?? '',
        'reference' => trim($_POST['reference'] ?? ''),
        'notes' => trim($_POST['notes'] ?? '')
    ];

    // Validation
    if (!$payment_data['supplier_id']) $errors[] = 'Supplier is required.';
    if ($payment_data['amount'] <= 0) $errors[] = 'Payment amount must be greater than 0.';
    if (!$payment_data['payment_method']) $errors[] = 'Payment method is required.';
    if (!$payment_data['payment_date']) $errors[] = 'Payment date is required.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO supplier_payments (
                    supplier_id, po_id, payment_date, amount, payment_method, reference, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $payment_data['supplier_id'], $payment_data['po_id'], $payment_data['payment_date'],
                $payment_data['amount'], $payment_data['payment_method'], $payment_data['reference'],
                $payment_data['notes'], $_SESSION['user_id']
            ]);

            log_activity('supplier_payment', $payment_data['supplier_id'],
                "Payment recorded: \${$payment_data['amount']} via {$payment_data['payment_method']}");

            header('Location: supplier_payments.php?success=1');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get payments with pagination
$params = [
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'page' => max(1, intval($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, intval($_GET['per_page'] ?? 20)))
];

$where = [];
$sql_params = [];
if ($params['supplier_id']) {
    $where[] = "sp.supplier_id = ?";
    $sql_params[] = $params['supplier_id'];
}
if ($params['date_from']) {
    $where[] = "sp.payment_date >= ?";
    $sql_params[] = $params['date_from'];
}
if ($params['date_to']) {
    $where[] = "sp.payment_date <= ?";
    $sql_params[] = $params['date_to'];
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$offset = ($params['page'] - 1) * $params['per_page'];
$payments_sql = "
    SELECT sp.*, s.name as supplier_name, s.company_name, po.po_number, u.first_name, u.last_name
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN purchase_orders po ON sp.po_id = po.id
    LEFT JOIN users u ON sp.created_by = u.id
    $where_sql
    ORDER BY sp.payment_date DESC, sp.id DESC
    LIMIT $offset, {$params['per_page']}
";
$stmt = $pdo->prepare($payments_sql);
$stmt->execute($sql_params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count
$count_sql = "SELECT COUNT(*) FROM supplier_payments sp $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($sql_params);
$total_payments = $count_stmt->fetchColumn();
$total_pages = ceil($total_payments / $params['per_page']);

// Get outstanding balances
$outstanding_sql = "
    SELECT s.id, s.name, s.company_name,
           COALESCE(SUM(po.total_amount), 0) as total_po_amount,
           COALESCE(SUM(sp.amount), 0) as total_paid,
           (COALESCE(SUM(po.total_amount), 0) - COALESCE(SUM(sp.amount), 0)) as outstanding
    FROM suppliers s
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id AND po.status IN ('confirmed', 'partially_received', 'completed')
    LEFT JOIN supplier_payments sp ON s.id = sp.supplier_id
    WHERE s.status = 1
    GROUP BY s.id, s.name, s.company_name
    HAVING outstanding > 0
    ORDER BY outstanding DESC
";
$outstanding_balances = $pdo->query($outstanding_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get aging report (simplified)
$aging_sql = "
    SELECT
        CASE
            WHEN DATEDIFF(CURDATE(), po.order_date) <= 30 THEN 'current'
            WHEN DATEDIFF(CURDATE(), po.order_date) <= 60 THEN '30_days'
            WHEN DATEDIFF(CURDATE(), po.order_date) <= 90 THEN '60_days'
            ELSE '90_plus_days'
        END as age_group,
        COUNT(*) as po_count,
        SUM(po.total_amount - COALESCE(paid.amount, 0)) as amount
    FROM purchase_orders po
    LEFT JOIN (
        SELECT po_id, SUM(amount) as amount
        FROM supplier_payments
        GROUP BY po_id
    ) paid ON po.id = paid.po_id
    WHERE po.status IN ('confirmed', 'partially_received', 'completed')
    AND (po.total_amount - COALESCE(paid.amount, 0)) > 0
    GROUP BY age_group
";
$aging_data = $pdo->query($aging_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get POs for the selected supplier (for payment form)
$supplier_pos = [];
if (isset($_GET['supplier_id'])) {
    $stmt = $pdo->prepare("
        SELECT po.id, po.po_number, po.total_amount,
               COALESCE(SUM(sp.amount), 0) as paid_amount,
               (po.total_amount - COALESCE(SUM(sp.amount), 0)) as outstanding
        FROM purchase_orders po
        LEFT JOIN supplier_payments sp ON po.id = sp.po_id
        WHERE po.supplier_id = ? AND po.status IN ('confirmed', 'partially_received', 'completed')
        GROUP BY po.id, po.po_number, po.total_amount
        HAVING outstanding > 0
        ORDER BY po.order_date DESC
    ");
    $stmt->execute([$_GET['supplier_id']]);
    $supplier_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include 'templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">
                        <i class="fas fa-credit-card"></i> Supplier Payments
                    </h1>
                    <div class="card-tools">
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addPaymentModal">
                            <i class="fas fa-plus"></i> Record Payment
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check"></i> Payment recorded successfully!
                    </div>
                    <?php endif; ?>

                    <!-- Outstanding Balances -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Outstanding Balances</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($outstanding_balances)): ?>
                                    <p class="text-muted mb-0">No outstanding balances.</p>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Outstanding</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($outstanding_balances, 0, 5) as $balance): ?>
                                                <tr>
                                                    <td>
                                                        <?= htmlspecialchars($balance['name']) ?>
                                                        <?php if ($balance['company_name']): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($balance['company_name']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-danger font-weight-bold">
                                                        $<?= number_format($balance['outstanding'], 2) ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Aging Report -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Payables Aging</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Age Group</th>
                                                    <th>POs</th>
                                                    <th>Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $age_groups = [
                                                    'current' => 'Current',
                                                    '30_days' => '31-60 days',
                                                    '60_days' => '61-90 days',
                                                    '90_plus_days' => '90+ days'
                                                ];
                                                foreach ($age_groups as $key => $label):
                                                    $data = array_filter($aging_data, fn($d) => $d['age_group'] === $key);
                                                    $data = $data ? array_values($data)[0] : ['po_count' => 0, 'amount' => 0];
                                                ?>
                                                <tr>
                                                    <td><?= $label ?></td>
                                                    <td><?= $data['po_count'] ?></td>
                                                    <td>$<?= number_format($data['amount'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search and Filter Form -->
                    <form method="GET" class="mb-3">
                        <div class="row">
                            <div class="col-md-3">
                                <select name="supplier_id" class="form-control">
                                    <option value="">All Suppliers</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= $supplier['id'] ?>" <?= $params['supplier_id'] == $supplier['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($supplier['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($params['date_from']) ?>" placeholder="From Date">
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($params['date_to']) ?>" placeholder="To Date">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-secondary btn-block">Filter</button>
                            </div>
                        </div>
                    </form>

                    <!-- Payments Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Supplier</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th>PO</th>
                                    <th>Created By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars($payment['supplier_name']) ?>
                                        <?php if ($payment['company_name']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($payment['company_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-weight-bold">$<?= number_format($payment['amount'], 2) ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($payment['reference'] ?: 'N/A') ?></td>
                                    <td>
                                        <?php if ($payment['po_number']): ?>
                                        <a href="po_form.php?id=<?= $payment['po_id'] ?>"><?= htmlspecialchars($payment['po_number']) ?></a>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? '')) ?>
                                        <br><small class="text-muted"><?= date('M d, Y', strtotime($payment['created_at'])) ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Payment pagination">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $i === $params['page'] ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Supplier Payment</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_payment">
                <div class="modal-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="supplier_id">Supplier *</label>
                                <select id="supplier_id" name="supplier_id" class="form-control" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= $supplier['id'] ?>" <?= (isset($_GET['supplier_id']) && $_GET['supplier_id'] == $supplier['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($supplier['name']) ?>
                                        <?php if ($supplier['company_name']): ?>
                                        (<?= htmlspecialchars($supplier['company_name']) ?>)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="payment_date">Payment Date *</label>
                                <input type="date" id="payment_date" name="payment_date" class="form-control" required
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="amount">Amount *</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" id="amount" name="amount" class="form-control" min="0.01" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="payment_method">Payment Method *</label>
                                <select id="payment_method" name="payment_method" class="form-control" required>
                                    <option value="">Select Method</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="check">Check</option>
                                    <option value="credit_card">Credit Card</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="po_id">Link to Purchase Order (Optional)</label>
                        <select id="po_id" name="po_id" class="form-control">
                            <option value="">Select PO</option>
                            <?php foreach ($supplier_pos as $po): ?>
                            <option value="<?= $po['id'] ?>" data-outstanding="<?= $po['outstanding'] ?>">
                                <?= htmlspecialchars($po['po_number']) ?> - Outstanding: $<?= number_format($po['outstanding'], 2) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reference">Reference/Check Number</label>
                        <input type="text" id="reference" name="reference" class="form-control"
                               placeholder="Check #, Transaction ID, etc.">
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2"
                                  placeholder="Payment notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Update PO options when supplier changes
document.getElementById('supplier_id').addEventListener('change', function() {
    const supplierId = this.value;
    const poSelect = document.getElementById('po_id');

    if (!supplierId) {
        poSelect.innerHTML = '<option value="">Select PO</option>';
        return;
    }

    // This would need AJAX in a real implementation
    // For now, we'll just clear the options
    poSelect.innerHTML = '<option value="">Select PO</option>';
});
</script>

<?php
include 'templates/footer.php';
