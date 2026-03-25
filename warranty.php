<?php
/**
 * Warranty Management
 * Register warranties, expiry alerts, claim processing, and history by product/serial.
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.view');

$db = getDB();
$pageTitle = 'Warranty Management';

// Determine which warranty table name to use. Prefer plural `warranties` if present.
$warranty_table = getWarrantyTableName();

if ($warranty_table === null) {
    setFlashMessage('error', 'Warranty table is missing. Run database schema updates first.');
    include 'templates/header.php';
    echo '<div class="alert alert-danger">Warranty module is unavailable because required tables are missing.</div>';
    include 'templates/footer.php';
    exit;
}

// Auto-expire old active warranties.
try {
    $db->query("UPDATE {$warranty_table} SET status = 'expired' WHERE status = 'active' AND warranty_end < CURDATE()");
} catch (Exception $e) {
    // Non-blocking.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'register') {
            requirePermission('sales.create');

            $transactionItemId = (int)($_POST['transaction_item_id'] ?? 0);
            $productId = (int)($_POST['product_id'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $serial = trim((string)($_POST['serial_number'] ?? ''));
            $warrantyStart = trim((string)($_POST['warranty_start'] ?? date('Y-m-d')));
            $warrantyEnd = trim((string)($_POST['warranty_end'] ?? ''));

            if ($productId <= 0) {
                throw new Exception('Product is required.');
            }
            if ($transactionItemId <= 0) {
                throw new Exception('Transaction Item ID is required for warranty registration.');
            }
            if ($warrantyEnd === '') {
                $p = $db->fetchOne("SELECT warranty_period FROM products WHERE id = ?", [$productId]);
                $months = (int)($p['warranty_period'] ?? 0);
                if ($months <= 0) {
                    $months = 12;
                }
                $warrantyEnd = date('Y-m-d', strtotime($warrantyStart . " +{$months} months"));
            }

            $db->insert($warranty_table, [
                'transaction_item_id' => $transactionItemId,
                'product_id' => $productId,
                'serial_number' => $serial !== '' ? $serial : null,
                'customer_id' => $customerId > 0 ? $customerId : null,
                'warranty_start' => $warrantyStart,
                'warranty_end' => $warrantyEnd,
                'status' => 'active'
            ]);

            setFlashMessage('success', 'Warranty registered successfully.');
            redirect(getBaseUrl() . '/warranty.php');
        }

        if ($action === 'create_claim') {
            requirePermission('sales.create');
            if (! _logTableExists('warranty_claims')) {
                throw new Exception('Warranty claims table is missing.');
            }

            $warrantyId = (int)($_POST['warranty_id'] ?? 0);
            $claimDate = trim((string)($_POST['claim_date'] ?? date('Y-m-d')));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($warrantyId <= 0) {
                throw new Exception('Warranty selection is required.');
            }
            $existingClaim = $db->fetchOne(
                "SELECT id
                 FROM warranty_claims
                 WHERE warranty_id = ?
                   AND status NOT IN ('completed','rejected')
                 LIMIT 1",
                [$warrantyId]
            );
            if (!empty($existingClaim['id'])) {
                throw new Exception('An active warranty claim already exists for this warranty.');
            }

            $db->insert('warranty_claims', [
                'warranty_id' => $warrantyId,
                'claim_date' => $claimDate,
                'status' => 'pending',
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => getCurrentUserId()
            ]);

            $db->query("UPDATE {$warranty_table} SET status = 'claimed' WHERE id = ?", [$warrantyId]);
            setFlashMessage('success', 'Warranty claim submitted.');
            redirect(getBaseUrl() . '/warranty.php');
        }

        if ($action === 'update_claim_status') {
            requirePermission('sales.edit');
            $claimId = (int)($_POST['claim_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'pending'));
            $allowed = ['pending', 'approved', 'rejected', 'completed'];
            if (!in_array($status, $allowed, true)) {
                throw new Exception('Invalid claim status.');
            }
            $db->query("UPDATE warranty_claims SET status = ? WHERE id = ?", [$status, $claimId]);
            setFlashMessage('success', 'Claim status updated.');
            redirect(getBaseUrl() . '/warranty.php');
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        redirect(getBaseUrl() . '/warranty.php');
    }
}

// Ensure basic filter defaults to avoid undefined index warnings
$allowedStatuses = ['active', 'expired', 'claimed'];
$historyFilter = (string)($_GET['history'] ?? 'product');
$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => in_array($_GET['status'] ?? '', $allowedStatuses, true) ? $_GET['status'] : '',
    'product' => (int)($_GET['product'] ?? 0),
    'customer' => (int)($_GET['customer'] ?? 0),
    'history' => in_array($historyFilter, ['product', 'serial'], true) ? $historyFilter : 'product',
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, (int)($_GET['per_page'] ?? 20))
    )
];

// Sanitize and validate resolved warranty table name for SQL usage.
$warranty_table = is_string($warranty_table) ? preg_replace('/[^A-Za-z0-9_]/', '', $warranty_table) : $warranty_table;
if ($warranty_table !== null) {
    $warranty_table = trim($warranty_table);
}

$where = [];
$whereParams = [];

if ($filters['q'] !== '') {
    $like = '%' . $filters['q'] . '%';
    $where[] = "(w.serial_number LIKE ? OR p.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
    $whereParams[] = $like;
    $whereParams[] = $like;
    $whereParams[] = $like;
    $whereParams[] = $like;
}
if ($filters['status'] !== '') {
    $where[] = "w.status = ?";
    $whereParams[] = $filters['status'];
}
if ($filters['product'] > 0) {
    $where[] = "w.product_id = ?";
    $whereParams[] = $filters['product'];
}
if ($filters['customer'] > 0) {
    $where[] = "w.customer_id = ?";
    $whereParams[] = $filters['customer'];
}

$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
$offset = ($filters['page'] - 1) * $filters['per_page'];

$countRow = null;
if ($warranty_table === null) {
    setFlashMessage('error', 'Warranty table is not available.');
    include 'templates/header.php';
    echo '<div class="alert alert-danger">Warranty module is unavailable because required tables are missing.</div>';
    include 'templates/footer.php';
    exit;
}

$wt = $warranty_table; // already sanitized above
try {
    $countRow = $db->fetchOne(
        "SELECT COUNT(*) AS total
         FROM `{$wt}` w
         LEFT JOIN products p ON p.id = w.product_id
         LEFT JOIN customers c ON c.id = w.customer_id
         {$whereSql}",
        $whereParams
    );
} catch (Exception $e) {
    error_log('warranty.php countRow query failed: ' . $e->getMessage());
    setFlashMessage('error', 'Database error while loading warranty records.');
    include 'templates/header.php';
    echo '<div class="alert alert-danger">Database error while loading warranty records.</div>';
    include 'templates/footer.php';
    exit;
}
$totalRows = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $filters['per_page']));

$warranties = $db->fetchAll(
    "SELECT w.*,
            p.name AS product_name,
            p.sku,
            CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name
     FROM {$warranty_table} w
     LEFT JOIN products p ON p.id = w.product_id
     LEFT JOIN customers c ON c.id = w.customer_id
     {$whereSql}
     ORDER BY w.warranty_end ASC
     LIMIT " . (int)$filters['per_page'] . " OFFSET " . (int)$offset,
    $whereParams
);

 $metrics = $db->fetchOne(
    "SELECT
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_cnt,
        SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) AS claimed_cnt,
        SUM(CASE WHEN status = 'active' AND warranty_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expiring_cnt
     FROM {$warranty_table}"
);

 $expiring = $db->fetchAll(
    "SELECT w.id, w.serial_number, w.warranty_end, p.name AS product_name,
        CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name
     FROM {$warranty_table} w
     LEFT JOIN products p ON p.id = w.product_id
     LEFT JOIN customers c ON c.id = w.customer_id
     WHERE w.status = 'active' AND w.warranty_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY w.warranty_end ASC
     LIMIT 20"
);

$claims = [];
if (_logTableExists('warranty_claims')) {
    $claims = $db->fetchAll(
        "SELECT wc.*, w.serial_number, p.name AS product_name,
            CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name
         FROM warranty_claims wc
         INNER JOIN {$warranty_table} w ON w.id = wc.warranty_id
         LEFT JOIN products p ON p.id = w.product_id
         LEFT JOIN customers c ON c.id = w.customer_id
         ORDER BY wc.created_at DESC
         LIMIT 30"
    );
}

$historyRows = [];
if ($filters['history'] === 'product') {
    $historyRows = $db->fetchAll(
        "SELECT p.id, p.name, p.sku,
            COUNT(w.id) AS warranty_count,
            SUM(CASE WHEN w.status = 'active' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN w.status = 'claimed' THEN 1 ELSE 0 END) AS claimed_count
         FROM {$warranty_table} w
         LEFT JOIN products p ON p.id = w.product_id
         GROUP BY p.id, p.name, p.sku
         ORDER BY warranty_count DESC
         LIMIT 50"
    );
} else {
    $historyRows = $db->fetchAll(
        "SELECT w.serial_number, p.name AS product_name, p.sku, w.status, w.warranty_start, w.warranty_end
         FROM {$warranty_table} w
         LEFT JOIN products p ON p.id = w.product_id
         WHERE w.serial_number IS NOT NULL AND w.serial_number <> ''
         ORDER BY w.created_at DESC
         LIMIT 100"
    );
}

$products = $db->fetchAll("SELECT id, name, sku FROM products ORDER BY name ASC LIMIT 300");
$customers = $db->fetchAll("SELECT id, first_name, last_name FROM customers ORDER BY first_name ASC, last_name ASC LIMIT 300");

include 'templates/header.php';
?>

<style>
    .warranty-page { --bg:#f8fafc; --card:#ffffff; --border:#e5e7eb; --text:#0f172a; --muted:#6b7280; --accent:#2563eb; }
    .warranty-page { background: var(--bg); padding: 16px; border-radius: 12px; }
    .warranty-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; }
    .kpi-card { background:var(--card); border:1px solid var(--border); border-radius:12px; }
    .section-card { background:var(--card); border:1px solid var(--border); border-radius:12px; }
    .section-card .card-header { background:transparent; border-bottom:1px solid var(--border); font-weight:600; }
    .table thead th { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
    .table tbody tr:hover { background:#f8fafc; }
    @media (max-width: 992px) { .warranty-header { flex-direction:column; align-items:flex-start; } }
</style>

<div class="warranty-page">
    <div class="warranty-header mb-3">
        <div>
            <h1 class="h3 mb-0">Warranty Management</h1>
            <small class="text-muted">Registration, expiry alerts, claims, and history</small>
        </div>
        <a class="btn btn-primary" href="<?php echo getBaseUrl(); ?>/warranty_intake.php">Claim</a>
    </div>

<div class="row mb-3">
    <div class="col-md-3"><div class="card kpi-card"><div class="card-body"><div class="text-muted small">Active</div><div class="h4 mb-0"><?php echo (int)($metrics['active_cnt'] ?? 0); ?></div></div></div></div>
    <div class="col-md-3"><div class="card kpi-card"><div class="card-body"><div class="text-muted small">Expiring (30 days)</div><div class="h4 mb-0 text-warning"><?php echo (int)($metrics['expiring_cnt'] ?? 0); ?></div></div></div></div>
    <div class="col-md-3"><div class="card kpi-card"><div class="card-body"><div class="text-muted small">Expired</div><div class="h4 mb-0 text-danger"><?php echo (int)($metrics['expired_cnt'] ?? 0); ?></div></div></div></div>
    <div class="col-md-3"><div class="card kpi-card"><div class="card-body"><div class="text-muted small">Claimed</div><div class="h4 mb-0 text-info"><?php echo (int)($metrics['claimed_cnt'] ?? 0); ?></div></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card section-card">
            <div class="card-header">Search & Filters</div>
            <div class="card-body">
                <form method="GET" class="row g-2">
                    <div class="col-md-4"><input class="form-control" type="text" name="q" value="<?php echo escape($filters['q']); ?>" placeholder="Search serial/product/customer"></div>
                    <div class="col-md-2">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?php echo $st; ?>" <?php echo $filters['status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="product">
                            <option value="0">All Products</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>" <?php echo $filters['product'] === (int)$p['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape($p['name'] . ' (' . ($p['sku'] ?? '-') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="customer">
                            <option value="0">All Customers</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo $filters['customer'] === (int)$c['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="history">
                            <option value="product" <?php echo $filters['history'] === 'product' ? 'selected' : ''; ?>>History by Product</option>
                            <option value="serial" <?php echo $filters['history'] === 'serial' ? 'selected' : ''; ?>>History by Serial</option>
                        </select>
                    </div>
                    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply</button></div>
                    <div class="col-md-2"><a class="btn btn-outline-secondary w-100" href="<?php echo getBaseUrl(); ?>/warranty.php">Reset</a></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4"></div>
</div>

<?php if (!empty($expiring)): ?>
<div class="card section-card mb-3">
    <div class="card-header text-warning">Expiry Alerts (Next 30 Days)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>ID</th><th>Product</th><th>Customer</th><th>Serial</th><th>Ends</th></tr></thead>
                <tbody>
                <?php foreach ($expiring as $e): ?>
                    <tr>
                        <td>#<?php echo (int)$e['id']; ?></td>
                        <td><?php echo escape($e['product_name'] ?? '-'); ?></td>
                        <td><?php echo escape(trim((string)($e['customer_name'] ?? '-'))); ?></td>
                        <td><?php echo escape($e['serial_number'] ?? '-'); ?></td>
                        <td><?php echo escape($e['warranty_end']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


    <div class="row g-3 mb-3">
        <div class="col-lg-12">
            <div class="card section-card">
                <div class="card-header">Warranty History (<?php echo $filters['history'] === 'product' ? 'Product' : 'Serial'; ?>)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                            <?php if ($filters['history'] === 'product'): ?>
                                <tr><th>Product</th><th>SKU</th><th>Total</th><th>Active</th><th>Claimed</th></tr>
                            <?php else: ?>
                                <tr><th>Serial</th><th>Product</th><th>SKU</th><th>Status</th><th>End</th></tr>
                            <?php endif; ?>
                            </thead>
                            <tbody>
                            <?php if (empty($historyRows)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No history rows.</td></tr>
                            <?php else: ?>
                                <?php foreach ($historyRows as $h): ?>
                                    <?php if ($filters['history'] === 'product'): ?>
                                        <tr>
                                            <td><?php echo escape($h['name'] ?? '-'); ?></td>
                                            <td><?php echo escape($h['sku'] ?? '-'); ?></td>
                                            <td><?php echo (int)$h['warranty_count']; ?></td>
                                            <td><?php echo (int)$h['active_count']; ?></td>
                                            <td><?php echo (int)$h['claimed_count']; ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td><?php echo escape($h['serial_number'] ?? '-'); ?></td>
                                            <td><?php echo escape($h['product_name'] ?? '-'); ?></td>
                                            <td><?php echo escape($h['sku'] ?? '-'); ?></td>
                                            <td><?php echo getStatusBadge($h['status']); ?></td>
                                            <td><?php echo escape($h['warranty_end']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php if (!empty($claims)): ?>
<div class="card section-card mb-3">
    <div class="card-header">Recent Warranty Claims</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>ID</th><th>Product</th><th>Customer</th><th>Serial</th><th>Date</th><th>Type</th><th>Status</th><th>Update</th></tr></thead>
                <tbody>
                <?php foreach ($claims as $cl): ?>
                    <?php
                        $actionType = strtolower((string)($cl['resolution_action'] ?? ''));
                        if (strpos($actionType, 'exchange') !== false) {
                            $typeLabel = 'Exchange';
                            $typeBadge = 'bg-primary';
                        } elseif (strpos($actionType, 'repair') !== false) {
                            $typeLabel = 'Repair';
                            $typeBadge = 'bg-secondary';
                        } else {
                            $typeLabel = 'Unspecified';
                            $typeBadge = 'bg-light text-dark';
                        }
                    ?>
                    <tr>
                        <td>#<?php echo (int)$cl['id']; ?></td>
                        <td>
                            <button type="button"
                                    class="btn btn-link p-0 text-decoration-none claim-detail-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#claimDetailModal"
                                    data-claim-id="<?php echo (int)$cl['id']; ?>"
                                    data-product="<?php echo escape($cl['product_name'] ?? '-'); ?>"
                                    data-customer="<?php echo escape(trim((string)($cl['customer_name'] ?? '-'))); ?>"
                                    data-serial="<?php echo escape($cl['serial_number'] ?? '-'); ?>"
                                    data-date="<?php echo escape($cl['claim_date']); ?>"
                                    data-status="<?php echo escape($cl['status']); ?>"
                                    data-type="<?php echo escape($typeLabel); ?>"
                                    data-type-badge="<?php echo escape($typeBadge); ?>"
                                    data-reason="<?php echo escape($cl['claim_reason'] ?? $cl['notes'] ?? ''); ?>"
                                    data-action="<?php echo escape($cl['resolution_action'] ?? ''); ?>">
                                <?php echo escape($cl['product_name'] ?? '-'); ?>
                            </button>
                        </td>
                        <td><?php echo escape(trim((string)($cl['customer_name'] ?? '-'))); ?></td>
                        <td><?php echo escape($cl['serial_number'] ?? '-'); ?></td>
                        <td><?php echo escape($cl['claim_date']); ?></td>
                        <td><span class="badge <?php echo $typeBadge; ?>"><?php echo $typeLabel; ?></span></td>
                        <td><?php echo getStatusBadge($cl['status']); ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-1">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_claim_status">
                                <input type="hidden" name="claim_id" value="<?php echo (int)$cl['id']; ?>">
                                <select class="form-select form-select-sm" name="status" style="max-width: 150px;">
                                    <?php foreach (['pending','approved','rejected','completed'] as $st): ?>
                                        <option value="<?php echo $st; ?>" <?php echo $cl['status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="claimDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Claim Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2"><strong id="cdProduct">-</strong></div>
                <div class="small text-muted mb-2">Customer: <span id="cdCustomer">-</span></div>
                <div class="small text-muted">Serial: <span id="cdSerial">-</span></div>
                <div class="small text-muted">Date: <span id="cdDate">-</span></div>
                <div class="mt-2">Type: <span class="badge bg-light text-dark" id="cdType">-</span></div>
                <div class="mt-2">Status: <span class="badge bg-light text-dark" id="cdStatus">-</span></div>
                <div class="mt-3">
                    <label class="form-label">Reason for Claim</label>
                    <textarea class="form-control" id="cdReason" rows="3" readonly></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" class="btn btn-primary" id="cdPrimaryActionBtn">Process Exchange</a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const buttons = document.querySelectorAll(".claim-detail-btn");
    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    const cdReason = document.getElementById("cdReason");
    const cdPrimaryActionBtn = document.getElementById("cdPrimaryActionBtn");
    const cdType = document.getElementById("cdType");
    buttons.forEach(btn => {
        btn.addEventListener("click", () => {
            const serial = btn.dataset.serial || '';
            setText("cdProduct", btn.dataset.product || '-');
            setText("cdCustomer", btn.dataset.customer || '-');
            setText("cdSerial", serial || '-');
            setText("cdDate", btn.dataset.date || '-');
            setText("cdType", btn.dataset.type || '-');
            setText("cdStatus", btn.dataset.status || '-');
            if (cdReason) cdReason.value = btn.dataset.reason || '';
            if (cdType) {
                cdType.className = "badge " + (btn.dataset.typeBadge || "bg-light text-dark");
            }
            if (cdPrimaryActionBtn) {
                const type = (btn.dataset.type || '').toLowerCase();
                if (type === 'exchange') {
                    cdPrimaryActionBtn.textContent = "Process Exchange";
                    cdPrimaryActionBtn.href = "<?php echo getBaseUrl(); ?>/warranty_intake.php?serial=" + encodeURIComponent(serial) + "&claim=recorded";
                } else {
                    cdPrimaryActionBtn.textContent = "Send for Repair";
                    cdPrimaryActionBtn.href = "<?php echo getBaseUrl(); ?>/warranty_intake.php?serial=" + encodeURIComponent(serial);
                }
            }
        });
    });
});
</script>

<?php include 'templates/footer.php'; ?>
</div>
