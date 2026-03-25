<?php
/**
 * Transaction History Page
 * List all transactions with advanced search, filtering, and export
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.view');

$pageTitle = 'Transaction History';
$db = getDB();
$paymentsMethodColumn = tableColumnExists('payments', 'payment_method') ? 'payment_method' : 'method';
$usersHasFirstLast = tableColumnExists('users', 'first_name') && tableColumnExists('users', 'last_name');
$cashierNameExpr = $usersHasFirstLast
    ? "COALESCE(NULLIF(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')), ' '), u.username, 'Unknown')"
    : "COALESCE(NULLIF(u.full_name, ''), u.username, 'Unknown')";

// Handle search/filter parameters
$filters = [
    'transaction_id' => trim($_GET['transaction_id'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
    'customer' => trim($_GET['customer'] ?? ''),
    'cashier' => trim($_GET['cashier'] ?? ''),
    'payment_method' => trim($_GET['payment_method'] ?? ''),
    'amount_min' => isset($_GET['amount_min']) && $_GET['amount_min'] !== '' ? (float)$_GET['amount_min'] : null,
    'amount_max' => isset($_GET['amount_max']) && $_GET['amount_max'] !== '' ? (float)$_GET['amount_max'] : null,
    'status' => trim($_GET['status'] ?? ''),
    'export' => trim($_GET['export'] ?? ''),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, (int)($_GET['per_page'] ?? 10)))
];

// Build WHERE clause
$where_parts = [];
$where_params = [];

if (!empty($filters['transaction_id'])) {
    $where_parts[] = "t.transaction_number LIKE ?";
    $where_params[] = '%' . $filters['transaction_id'] . '%';
}

if (!empty($filters['date_from'])) {
    $where_parts[] = "DATE(t.transaction_date) >= ?";
    $where_params[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $where_parts[] = "DATE(t.transaction_date) <= ?";
    $where_params[] = $filters['date_to'];
}

if (!empty($filters['customer'])) {
    $where_parts[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
    $search = '%' . $filters['customer'] . '%';
    $where_params[] = $search;
    $where_params[] = $search;
    $where_params[] = $search;
    $where_params[] = $search;
}

if (!empty($filters['cashier'])) {
    if ($usersHasFirstLast) {
        $where_parts[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)";
    } else {
        $where_parts[] = "(u.full_name LIKE ? OR u.username LIKE ?)";
    }
    $search = '%' . $filters['cashier'] . '%';
    $where_params[] = $search;
    $where_params[] = $search;
    if ($usersHasFirstLast) {
        $where_params[] = $search;
    }
}

if (!empty($filters['payment_method'])) {
    $where_parts[] = "p.{$paymentsMethodColumn} = ?";
    $where_params[] = $filters['payment_method'];
}

if ($filters['amount_min'] !== null) {
    $where_parts[] = "t.total_amount >= ?";
    $where_params[] = $filters['amount_min'];
}

if ($filters['amount_max'] !== null) {
    $where_parts[] = "t.total_amount <= ?";
    $where_params[] = $filters['amount_max'];
}

if (!empty($filters['status'])) {
    $where_parts[] = "t.status = ?";
    $where_params[] = $filters['status'];
}

$where_sql = !empty($where_parts) ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

// Count total records
$count_row = $db->fetchOne(
    "SELECT COUNT(DISTINCT t.id) as count FROM transactions t
     LEFT JOIN customers c ON t.customer_id = c.id
     LEFT JOIN users u ON t.user_id = u.id
     LEFT JOIN payments p ON t.id = p.transaction_id
     {$where_sql}",
    $where_params
);
$total_count = (int)($count_row['count'] ?? 0);

// Handle export
if (!empty($filters['export'])) {
    requirePermission('sales.export');
    
    $export_data = $db->fetchAll(
        "SELECT DISTINCT t.id, t.transaction_number, t.transaction_date, 
                CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) as customer_name,
                c.phone, c.email,
                {$cashierNameExpr} as cashier_name,
                t.subtotal, t.tax_amount, t.discount_amount, t.total_amount,
                t.status, t.payment_status,
                GROUP_CONCAT(DISTINCT p.{$paymentsMethodColumn}) as payment_methods
         FROM transactions t
         LEFT JOIN customers c ON t.customer_id = c.id
         LEFT JOIN users u ON t.user_id = u.id
         LEFT JOIN payments p ON t.id = p.transaction_id
         {$where_sql}
         GROUP BY t.id
         ORDER BY t.transaction_date DESC",
        $where_params
    );

    if ($filters['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Transaction #', 'Date', 'Customer', 'Phone', 'Email', 'Cashier',
            'Subtotal', 'Tax', 'Discount', 'Total', 'Status', 'Payment Status', 'Payment Methods'
        ]);
        
        foreach ($export_data as $row) {
            fputcsv($output, [
                $row['transaction_number'],
                $row['transaction_date'],
                $row['customer_name'],
                $row['phone'] ?? '',
                $row['email'] ?? '',
                $row['cashier_name'],
                number_format($row['subtotal'], 2),
                number_format($row['tax_amount'], 2),
                number_format($row['discount_amount'], 2),
                number_format($row['total_amount'], 2),
                $row['status'],
                $row['payment_status'],
                $row['payment_methods'] ?? ''
            ]);
        }
        fclose($output);
        exit;
    } elseif ($filters['export'] === 'xlsx' || $filters['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d_His') . '.xls"');

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Transaction #', 'Date', 'Customer', 'Phone', 'Email', 'Cashier',
            'Subtotal', 'Tax', 'Discount', 'Total', 'Status', 'Payment Status', 'Payment Methods'
        ]);

        foreach ($export_data as $row) {
            fputcsv($output, [
                $row['transaction_number'],
                $row['transaction_date'],
                $row['customer_name'],
                $row['phone'] ?? '',
                $row['email'] ?? '',
                $row['cashier_name'],
                number_format($row['subtotal'], 2),
                number_format($row['tax_amount'], 2),
                number_format($row['discount_amount'], 2),
                number_format($row['total_amount'], 2),
                $row['status'],
                $row['payment_status'],
                $row['payment_methods'] ?? ''
            ]);
        }
        fclose($output);
        exit;
    }
}

// Pagination
$offset = ($filters['page'] - 1) * $filters['per_page'];
$total_pages = ceil($total_count / $filters['per_page']);

// Fetch transactions for current page
$transactions = $db->fetchAll(
    "SELECT DISTINCT t.id, t.transaction_number, t.transaction_date,
            CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) as customer_name,
            c.id as customer_id, c.phone, c.email,
            {$cashierNameExpr} as cashier_name,
            t.subtotal, t.tax_amount, t.discount_amount, t.total_amount,
            t.status, t.payment_status,
            GROUP_CONCAT(DISTINCT p.{$paymentsMethodColumn}) as payment_methods
     FROM transactions t
     LEFT JOIN customers c ON t.customer_id = c.id
     LEFT JOIN users u ON t.user_id = u.id
     LEFT JOIN payments p ON t.id = p.transaction_id
     {$where_sql}
     GROUP BY t.id
     ORDER BY t.transaction_date DESC
     LIMIT " . (int)$filters['per_page'] . " OFFSET " . (int)$offset,
    $where_params
);

// Get payment methods for filter
$payment_methods = $db->fetchAll("SELECT DISTINCT {$paymentsMethodColumn} as payment_method FROM payments ORDER BY {$paymentsMethodColumn}");
$statuses = ['completed', 'pending', 'refunded', 'voided', 'on-hold'];
$showing_from = $total_count > 0 ? ($offset + 1) : 0;
$showing_to = min($offset + $filters['per_page'], $total_count);

include 'templates/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    .txn-dashboard{--card:#fff;--border:#e2e8f0;--muted:#64748b;--text:#0f172a;--accent:#4f46e5;--accent-dark:#4338ca;--accent-soft:#eef2ff;--shadow:0 10px 24px rgba(15,23,42,.06);font-family:'Inter',system-ui,sans-serif;color:var(--text)}
    .txn-dashboard .shell{max-width:1480px;margin:0 auto;padding:8px 0 24px}
    .txn-dashboard .saas-card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
    .txn-dashboard .page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px}
    .txn-dashboard .page-head h1{margin:0;font-size:1.8rem;font-weight:700}
    .txn-dashboard .page-head p{margin:6px 0 0;color:var(--muted)}
    .txn-dashboard .btn-primary-saas{background:var(--accent);border:1px solid var(--accent);color:#fff;border-radius:10px;padding:.72rem 1rem;font-weight:600}
    .txn-dashboard .btn-primary-saas:hover{background:var(--accent-dark);border-color:var(--accent-dark);color:#fff}
    .txn-dashboard .btn-outline-saas{background:#fff;border:1px solid #cbd5e1;color:#334155;border-radius:10px;padding:.72rem 1rem;font-weight:600}
    .txn-dashboard .btn-outline-saas:hover{background:#f8fafc;color:#1e293b}
    .txn-dashboard .filter-card{padding:20px}
    .txn-dashboard .filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
    .txn-dashboard .form-label{font-size:.78rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
    .txn-dashboard .form-control,.txn-dashboard .form-select{border-radius:10px;border:1px solid #dbe1ea;padding:.72rem .9rem;box-shadow:none}
    .txn-dashboard .form-control:focus,.txn-dashboard .form-select:focus{border-color:#a5b4fc;box-shadow:0 0 0 .18rem rgba(79,70,229,.12)}
    .txn-dashboard .filter-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:18px}
    .txn-dashboard .export-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px}
    .txn-dashboard .export-actions{display:flex;gap:10px;flex-wrap:wrap}
    .txn-dashboard .muted-copy{color:var(--muted);font-size:.95rem}
    .txn-dashboard .table-card{overflow:hidden}
    .txn-dashboard .table-shell{overflow:auto}
    .txn-dashboard .txn-table{margin:0;min-width:1060px}
    .txn-dashboard .txn-table thead th{padding:1rem .9rem;border-bottom:1px solid #e2e8f0;font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);background:#fff}
    .txn-dashboard .txn-table tbody td{padding:1rem .9rem;border-bottom:1px solid #eef2f7;vertical-align:middle}
    .txn-dashboard .txn-table tbody tr{transition:.15s ease}
    .txn-dashboard .txn-table tbody tr:hover{background:#f8fafc}
    .txn-dashboard .txn-no{font-weight:700;color:#0f172a}
    .txn-dashboard .txn-sub{font-size:.82rem;color:var(--muted);margin-top:2px}
    .txn-dashboard .money{text-align:right;white-space:nowrap}
    .txn-dashboard .money strong{font-weight:700}
    .txn-dashboard .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.34rem .72rem;font-size:.74rem;font-weight:700}
    .txn-dashboard .status-completed{background:#dcfce7;color:#166534}
    .txn-dashboard .status-refunded{background:#fee2e2;color:#991b1b}
    .txn-dashboard .status-pending{background:#fef3c7;color:#92400e}
    .txn-dashboard .status-voided{background:#e5e7eb;color:#374151}
    .txn-dashboard .status-on-hold{background:#ede9fe;color:#6d28d9}
    .txn-dashboard .status-default{background:#e0e7ff;color:#3730a3}
    .txn-dashboard .action-cell{white-space:nowrap;text-align:center}
    .txn-dashboard .icon-btn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #dbe1ea;background:#fff;color:#334155;text-decoration:none}
    .txn-dashboard .icon-btn:hover{background:var(--accent-soft);border-color:#a5b4fc;color:var(--accent-dark)}
    .txn-dashboard .pagination-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding:18px 20px}
    .txn-dashboard .per-page-form{display:flex;align-items:center;gap:10px;color:var(--muted)}
    .txn-dashboard .per-page-form .form-select{width:auto;min-width:92px;padding:.55rem 2rem .55rem .8rem}
    .txn-dashboard .pagination{margin:0}
    .txn-dashboard .page-link{border:none;border-radius:10px;color:#334155;padding:.55rem .85rem}
    .txn-dashboard .page-item.active .page-link{background:var(--accent);color:#fff}
    .txn-dashboard .page-link:hover{background:#eef2ff;color:#4338ca}
    .txn-dashboard .empty-state{padding:56px 24px;text-align:center}
    .txn-dashboard .empty-illustration{width:84px;height:84px;border-radius:24px;background:linear-gradient(135deg,#eef2ff,#e0f2fe);display:inline-flex;align-items:center;justify-content:center;color:#4f46e5;font-size:2rem;margin-bottom:18px}
    .txn-dashboard .empty-state h3{margin-bottom:8px;font-size:1.15rem}
    .txn-dashboard .empty-state p{color:var(--muted);margin-bottom:18px}
    .txn-dashboard .skeleton-wrap{display:none;padding:20px}
    .txn-dashboard.loading .skeleton-wrap{display:block}
    .txn-dashboard.loading .results-content{display:none}
    .txn-dashboard .skeleton-line{height:14px;border-radius:999px;background:linear-gradient(90deg,#e5e7eb 25%,#f1f5f9 50%,#e5e7eb 75%);background-size:200% 100%;animation:txnShimmer 1.4s infinite}
    .txn-dashboard .skeleton-line + .skeleton-line{margin-top:12px}
    .txn-dashboard .skeleton-line.short{width:42%}
    .txn-dashboard .skeleton-line.med{width:68%}
    @keyframes txnShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
    @media (max-width:1199px){.txn-dashboard .filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media (max-width:991px){.txn-dashboard .filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:575px){.txn-dashboard .filter-grid{grid-template-columns:1fr}.txn-dashboard .page-head{align-items:stretch}.txn-dashboard .page-head a{width:100%}.txn-dashboard .filter-actions .btn{width:100%}.txn-dashboard .pagination-bar{align-items:flex-start}}
</style>

<div class="txn-dashboard loading" id="txnDashboard">
    <div class="shell">
        <div class="page-head">
            <div>
                <h1>Transaction History</h1>
                <p>Search, export, and review transaction records for finance and operations.</p>
            </div>
            <a href="<?php echo getBaseUrl(); ?>/daily_sales.php" class="btn btn-outline-saas">
                <i class="bi bi-graph-up me-1"></i> Daily Sales Dashboard
            </a>
        </div>

        <div class="saas-card filter-card mb-4">
            <form method="GET">
                <div class="filter-grid">
                    <div>
                        <label class="form-label">Transaction ID</label>
                        <input type="text" class="form-control" name="transaction_id" value="<?php echo escape($filters['transaction_id']); ?>" placeholder="TXN-...">
                    </div>
                    <div>
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo escape($filters['date_from']); ?>" placeholder="mm/dd/yyyy">
                    </div>
                    <div>
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo escape($filters['date_to']); ?>" placeholder="mm/dd/yyyy">
                    </div>
                    <div>
                        <label class="form-label">Amount Min</label>
                        <input type="number" class="form-control" name="amount_min" value="<?php echo $filters['amount_min'] !== null ? escape((string)$filters['amount_min']) : ''; ?>" placeholder="0.00" step="0.01">
                    </div>
                    <div>
                        <label class="form-label">Amount Max</label>
                        <input type="number" class="form-control" name="amount_max" value="<?php echo $filters['amount_max'] !== null ? escape((string)$filters['amount_max']) : ''; ?>" placeholder="0.00" step="0.01">
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('-', ' ', $status)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Customer Name/Email/Phone</label>
                        <input type="text" class="form-control" name="customer" value="<?php echo escape($filters['customer']); ?>" placeholder="Search customer...">
                    </div>
                    <div>
                        <label class="form-label">Cashier</label>
                        <input type="text" class="form-control" name="cashier" value="<?php echo escape($filters['cashier']); ?>" placeholder="Cashier name...">
                    </div>
                    <div>
                        <label class="form-label">Payment Method</label>
                        <select class="form-select" name="payment_method">
                            <option value="">All Methods</option>
                            <?php foreach ($payment_methods as $pm): ?>
                            <option value="<?php echo escape((string)$pm['payment_method']); ?>" <?php echo $filters['payment_method'] === $pm['payment_method'] ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', (string)$pm['payment_method'])); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Per Page</label>
                        <select class="form-select" name="per_page">
                            <?php foreach ([10, 25, 50, 100] as $perPage): ?>
                            <option value="<?php echo $perPage; ?>" <?php echo (int)$filters['per_page'] === $perPage ? 'selected' : ''; ?>><?php echo $perPage; ?> per page</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <a href="<?php echo getBaseUrl(); ?>/transaction_history.php" class="btn btn-outline-saas">Reset</a>
                    <button type="submit" class="btn btn-primary-saas">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                </div>
            </form>
        </div>

        <div class="export-bar">
            <div class="export-actions">
                <button type="button" class="btn btn-outline-saas" onclick="exportData('csv')">
                    <i class="bi bi-file-earmark-text me-1"></i> Export CSV
                </button>
                <button type="button" class="btn btn-outline-saas" onclick="exportData('excel')">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
                </button>
            </div>
            <div class="muted-copy">Exports <?php echo number_format($total_count); ?> matching records</div>
        </div>

        <div class="saas-card table-card">
            <div class="skeleton-wrap">
                <div class="skeleton-line med"></div>
                <div class="skeleton-line"></div>
                <div class="skeleton-line"></div>
                <div class="skeleton-line short"></div>
            </div>
            <div class="results-content">
                <?php if (empty($transactions)): ?>
                    <div class="empty-state">
                        <div class="empty-illustration"><i class="bi bi-receipt-cutoff"></i></div>
                        <h3>No transactions match these filters</h3>
                        <p>Try widening the date range, clearing a payment filter, or resetting the search.</p>
                        <a href="<?php echo getBaseUrl(); ?>/transaction_history.php" class="btn btn-outline-saas">Clear filters</a>
                    </div>
                <?php else: ?>
                    <div class="table-shell">
                        <table class="table txn-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>TRANSACTION</th>
                                    <th>DATE/TIME</th>
                                    <th>CUSTOMER</th>
                                    <th>CASHIER</th>
                                    <th class="text-end">SUBTOTAL</th>
                                    <th class="text-end">TOTAL</th>
                                    <th>STATUS</th>
                                    <th>PAYMENT</th>
                                    <th class="text-center">ACTION</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $txn): ?>
                                    <?php
                                    $customerDisplay = trim((string)($txn['customer_name'] ?? ''));
                                    if ($customerDisplay === '') {
                                        $customerDisplay = 'Guest';
                                    }
                                    $statusKey = strtolower((string)($txn['status'] ?? ''));
                                    $statusClass = in_array($statusKey, ['completed', 'refunded', 'pending', 'voided', 'on-hold'], true)
                                        ? $statusKey
                                        : 'default';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="txn-no"><?php echo escape($txn['transaction_number']); ?></div>
                                            <div class="txn-sub">#<?php echo (int)$txn['id']; ?></div>
                                        </td>
                                        <td><?php echo escape(date('Y-m-d H:i:s', strtotime((string)$txn['transaction_date']))); ?></td>
                                        <td>
                                            <div><?php echo escape($customerDisplay); ?></div>
                                            <?php if (!empty($txn['email']) || !empty($txn['phone'])): ?>
                                                <div class="txn-sub"><?php echo escape((string)($txn['email'] ?: $txn['phone'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo escape((string)$txn['cashier_name']); ?></td>
                                        <td class="money"><?php echo formatCurrency($txn['subtotal']); ?></td>
                                        <td class="money"><strong><?php echo formatCurrency($txn['total_amount']); ?></strong></td>
                                        <td><span class="status-pill status-<?php echo escape($statusClass); ?>"><?php echo escape(ucfirst(str_replace('-', ' ', $statusKey ?: 'unknown'))); ?></span></td>
                                        <td><?php echo escape(ucfirst(str_replace('_', ' ', (string)($txn['payment_methods'] ?? 'N/A')))); ?></td>
                                        <td class="action-cell">
                                            <a href="<?php echo getBaseUrl(); ?>/invoice.php?id=<?php echo (int)$txn['id']; ?>" class="icon-btn" title="View receipt">
                                                <i class="bi bi-receipt"></i>
                                            </a>
                                            <?php if (hasPermission('sales.void') && $txn['status'] === 'completed'): ?>
                                                <a href="<?php echo getBaseUrl(); ?>/void_transaction.php?id=<?php echo (int)$txn['id']; ?>" class="icon-btn ms-1" title="Void transaction">
                                                    <i class="bi bi-x-circle"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-bar border-top">
                        <form method="GET" class="per-page-form">
                            <?php foreach ($filters as $key => $value): ?>
                                <?php if ($key !== 'per_page' && $key !== 'page' && $key !== 'export' && $value !== '' && $value !== null): ?>
                                    <input type="hidden" name="<?php echo escape($key); ?>" value="<?php echo escape((string)$value); ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <span>Show</span>
                            <select name="per_page" class="form-select" onchange="this.form.submit()">
                                <?php foreach ([10, 25, 50, 100] as $perPage): ?>
                                <option value="<?php echo $perPage; ?>" <?php echo (int)$filters['per_page'] === $perPage ? 'selected' : ''; ?>><?php echo $perPage; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span>per page</span>
                        </form>

                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Pagination">
                            <ul class="pagination">
                                <?php if ($filters['page'] > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo getBaseUrl(); ?>/transaction_history.php?<?php echo buildQueryString($filters, ['page' => 1]); ?>">First</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo getBaseUrl(); ?>/transaction_history.php?<?php echo buildQueryString($filters, ['page' => $filters['page'] - 1]); ?>">Previous</a>
                                </li>
                                <?php endif; ?>
                                <?php for ($i = max(1, $filters['page'] - 2); $i <= min($total_pages, $filters['page'] + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $filters['page'] ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo getBaseUrl(); ?>/transaction_history.php?<?php echo buildQueryString($filters, ['page' => $i]); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <?php if ($filters['page'] < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo getBaseUrl(); ?>/transaction_history.php?<?php echo buildQueryString($filters, ['page' => $filters['page'] + 1]); ?>">Next</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo getBaseUrl(); ?>/transaction_history.php?<?php echo buildQueryString($filters, ['page' => $total_pages]); ?>">Last</a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>

                        <div class="muted-copy">Showing <?php echo number_format($showing_from); ?>-<?php echo number_format($showing_to); ?> of <?php echo number_format($total_count); ?> results</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function exportData(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = '<?php echo getBaseUrl(); ?>/transaction_history.php?' + params.toString();
}
window.addEventListener('load', function () {
    const dashboard = document.getElementById('txnDashboard');
    if (dashboard) {
        dashboard.classList.remove('loading');
    }
});
</script>

<?php 
/**
 * Helper function to build query string
 */
function buildQueryString($filters, $overrides = []) {
    $params = array_merge($filters, $overrides);
    $params = array_filter($params, function($v) {
        return $v !== '' && $v !== null;
    });
    return http_build_query($params);
}
?>

<?php include 'templates/footer.php'; ?>
