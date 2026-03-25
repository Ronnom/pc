<?php
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (!has_permission('purchase.view')) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = get_db_connection();

function po_archive_table_has_column($pdo, $table, $column) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function po_archive_status_label($status) {
    $status = strtolower(trim((string)$status));
    if ($status === 'will_be_delivered') {
        return 'On Order';
    }
    return ucwords(str_replace('_', ' ', $status));
}

function po_archive_badge_class($status) {
    $status = strtolower(trim((string)$status));
    $classes = [
        'completed' => 'success',
        'partially_received' => 'warning',
        'cancelled' => 'danger',
        'approved' => 'primary',
        'sent' => 'info',
        'confirmed' => 'primary',
        'draft' => 'secondary',
        'rejected' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}

$suppliersHasStatus = po_archive_table_has_column($pdo, 'suppliers', 'status');
$suppliersHasIsActive = po_archive_table_has_column($pdo, 'suppliers', 'is_active');
$suppliersHasCompanyName = po_archive_table_has_column($pdo, 'suppliers', 'company_name');
$usersHasFirstName = po_archive_table_has_column($pdo, 'users', 'first_name');
$usersHasLastName = po_archive_table_has_column($pdo, 'users', 'last_name');
$usersHasFullName = po_archive_table_has_column($pdo, 'users', 'full_name');
$poiHasReceivedQuantity = po_archive_table_has_column($pdo, 'purchase_order_items', 'received_quantity');
$poHasCreatedBy = po_archive_table_has_column($pdo, 'purchase_orders', 'created_by');

$params = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'supplier' => trim((string)($_GET['supplier'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, (int)($_GET['per_page'] ?? 20)))
];

$where = ["po.status = 'completed'"];
$sqlParams = [];

if ($params['q'] !== '') {
    $where[] = "(po.po_number LIKE ? OR s.name LIKE ? OR po.notes LIKE ?)";
    $like = '%' . $params['q'] . '%';
    $sqlParams[] = $like;
    $sqlParams[] = $like;
    $sqlParams[] = $like;
}

if ($params['supplier'] !== '') {
    $where[] = "po.supplier_id = ?";
    $sqlParams[] = $params['supplier'];
}

if ($params['date_from'] !== '') {
    $where[] = "po.order_date >= ?";
    $sqlParams[] = $params['date_from'];
}

if ($params['date_to'] !== '') {
    $where[] = "po.order_date <= ?";
    $sqlParams[] = $params['date_to'];
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$offset = ($params['page'] - 1) * $params['per_page'];

$supplierWhere = '';
if ($suppliersHasStatus) {
    $supplierWhere = 'WHERE status = 1';
} elseif ($suppliersHasIsActive) {
    $supplierWhere = 'WHERE is_active = 1';
}
$suppliers = $pdo->query("SELECT id, name FROM suppliers {$supplierWhere} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$companyNameSelect = $suppliersHasCompanyName ? "s.company_name" : "NULL as company_name";
$createdBySelect = ($usersHasFirstName && $usersHasLastName)
    ? "u.first_name, u.last_name, NULL as full_name, u.username"
    : ($usersHasFullName
        ? "NULL as first_name, NULL as last_name, u.full_name, u.username"
        : "NULL as first_name, NULL as last_name, NULL as full_name, u.username");
$createdBySelect = $poHasCreatedBy
    ? $createdBySelect
    : "NULL as first_name, NULL as last_name, NULL as full_name, NULL as username";
$totalReceivedSubquery = $poiHasReceivedQuantity
    ? "(SELECT SUM(poi.received_quantity) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)"
    : "(SELECT SUM(poi.quantity) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)";
$createdByJoin = $poHasCreatedBy ? "LEFT JOIN users u ON po.created_by = u.id" : "";

$sql = "SELECT po.*, s.name AS supplier_name, {$companyNameSelect},
               {$createdBySelect},
               (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count,
               {$totalReceivedSubquery} AS total_received,
               (SELECT SUM(poi.quantity) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS total_ordered
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        {$createdByJoin}
        {$whereSql}
        ORDER BY po.order_date DESC
        LIMIT " . (int)$params['per_page'] . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($sqlParams);
$pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM purchase_orders po
     LEFT JOIN suppliers s ON po.supplier_id = s.id
     {$whereSql}"
);
$countStmt->execute($sqlParams);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $params['per_page']));

include 'templates/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-1">Archived Purchase Orders</h1>
            <p class="text-muted mb-0">Completed purchase orders are stored here.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="purchase_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Active Orders
            </a>
            <a href="po_form.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Create PO
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="q" class="form-control" placeholder="PO number, supplier, notes" value="<?= htmlspecialchars($params['q']) ?>">
                </div>
                <div class="col-lg-3">
                    <label class="form-label">Supplier</label>
                    <select name="supplier" class="form-select">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int)$supplier['id'] ?>" <?= $params['supplier'] == $supplier['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($supplier['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($params['date_from']) ?>">
                </div>
                <div class="col-lg-2">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($params['date_to']) ?>">
                </div>
                <div class="col-lg-1 d-grid">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <?php if (empty($pos)): ?>
                <div class="text-center text-muted py-5">No archived purchase orders found.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Ordered</th>
                                <th>Received</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pos as $po): ?>
                                <?php
                                $createdBy = trim(
                                    (string)($po['full_name'] ?? '') !== ''
                                        ? (string)$po['full_name']
                                        : trim((string)($po['first_name'] ?? '') . ' ' . (string)($po['last_name'] ?? ''))
                                );
                                if ($createdBy === '') {
                                    $createdBy = (string)($po['username'] ?? '-');
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($po['po_number']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars((string)$po['order_date']) ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($po['supplier_name'] ?? '-') ?></div>
                                        <?php if (!empty($po['company_name'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($po['company_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)($po['total_ordered'] ?? 0) ?></td>
                                    <td><?= (int)($po['total_received'] ?? 0) ?></td>
                                    <td><?= (int)($po['item_count'] ?? 0) ?></td>
                                    <td>
                                        <span class="badge bg-<?= po_archive_badge_class($po['status'] ?? '') ?>">
                                            <?= htmlspecialchars(po_archive_status_label($po['status'] ?? '')) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($createdBy) ?></td>
                                    <td class="text-end">
                                        <a href="po_view.php?id=<?= (int)$po['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small">
                        Showing <?= $totalRows ? ($offset + 1) : 0 ?>-<?= min($totalRows, $offset + $params['per_page']) ?> of <?= $totalRows ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <select id="archivePerPage" class="form-select form-select-sm" style="width: auto;">
                            <option value="10" <?= $params['per_page'] === 10 ? 'selected' : '' ?>>10</option>
                            <option value="20" <?= $params['per_page'] === 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $params['per_page'] === 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $params['per_page'] === 100 ? 'selected' : '' ?>>100</option>
                        </select>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($params['page'] > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $params['page'] - 1])) ?>">Previous</a></li>
                                <?php endif; ?>
                                <?php for ($page = max(1, $params['page'] - 2); $page <= min($totalPages, $params['page'] + 2); $page++): ?>
                                    <li class="page-item <?= $page === $params['page'] ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $page])) ?>"><?= $page ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($params['page'] < $totalPages): ?>
                                    <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $params['page'] + 1])) ?>">Next</a></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const perPage = document.getElementById('archivePerPage');
    if (!perPage) {
        return;
    }
    perPage.addEventListener('change', function () {
        const params = new URLSearchParams(window.location.search);
        params.set('per_page', this.value);
        params.set('page', '1');
        window.location.href = 'purchase_orders_archive.php?' + params.toString();
    });
});
</script>

<?php include 'templates/footer.php'; ?>
