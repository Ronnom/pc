<?php
// suppliers.php
// Supplier database: list, search, filter, sort, quick actions
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
if (!has_permission('suppliers.view')) {
    http_response_code(403);
    exit('Forbidden');
}
$pdo = get_db_connection();

function supplierTableExists($pdo, $tableName) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function supplierColumnExists($pdo, $tableName, $columnName) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$tableName, $columnName]);
    return (int)$stmt->fetchColumn() > 0;
}

$hasSupplierCode = supplierColumnExists($pdo, 'suppliers', 'supplier_code');
$hasCompanyName = supplierColumnExists($pdo, 'suppliers', 'company_name');
$hasStatus = supplierColumnExists($pdo, 'suppliers', 'status');
$hasIsActive = supplierColumnExists($pdo, 'suppliers', 'is_active');
$hasContactPerson = supplierColumnExists($pdo, 'suppliers', 'contact_person');
$hasContactName = supplierColumnExists($pdo, 'suppliers', 'contact_name');
$hasSupplierRatings = supplierTableExists($pdo, 'supplier_ratings');
$hasStockReceiving = supplierTableExists($pdo, 'stock_receiving');

$statusExpr = $hasStatus ? "s.status" : ($hasIsActive ? "s.is_active" : "1");
$supplierCodeExpr = $hasSupplierCode ? "s.supplier_code" : "CONCAT('SUP-', LPAD(s.id, 5, '0'))";
$companyNameExpr = $hasCompanyName ? "s.company_name" : "NULL";
$contactExpr = $hasContactPerson
    ? "s.contact_person"
    : ($hasContactName ? "s.contact_name" : "NULL");

$params = [
    'q' => $_GET['q'] ?? '',
    'status' => $_GET['status'] ?? '',
    'product' => $_GET['product'] ?? '',
    'sort' => $_GET['sort'] ?? 'name',
    'order' => $_GET['order'] ?? 'asc',
    'page' => max(1, intval($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, intval($_GET['per_page'] ?? 20)))
];
$where = [];
$sql_params = [];
if ($params['q']) {
    $searchFields = [];
    if ($hasSupplierCode) $searchFields[] = "s.supplier_code LIKE ?";
    $searchFields[] = "s.name LIKE ?";
    if ($hasCompanyName) $searchFields[] = "s.company_name LIKE ?";
    if ($hasContactPerson) {
        $searchFields[] = "s.contact_person LIKE ?";
    } elseif ($hasContactName) {
        $searchFields[] = "s.contact_name LIKE ?";
    }
    $searchFields[] = "s.email LIKE ?";
    $searchFields[] = "s.phone LIKE ?";
    $where[] = '(' . implode(' OR ', $searchFields) . ')';
    foreach ($searchFields as $unused) $sql_params[] = '%' . $params['q'] . '%';
}
if ($params['status'] !== '') {
    if ($hasStatus || $hasIsActive) {
        $where[] = "{$statusExpr} = ?";
        $sql_params[] = $params['status'] === 'active' ? 1 : 0;
    }
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$sort_map = [
    'name' => 'name',
    'purchases' => 'total_purchases',
    'rating' => 'rating'
];
$order_by = $sort_map[$params['sort']] ?? 'name';
$order_dir = strtolower($params['order']) === 'desc' ? 'DESC' : 'ASC';
$offset = ($params['page'] - 1) * $params['per_page'];
$ratingExpr = $hasSupplierRatings
    ? "(SELECT AVG(rating) FROM supplier_ratings sr WHERE sr.supplier_id = s.id)"
    : "NULL";
$leadTimeExpr = $hasStockReceiving
    ? "(SELECT AVG(DATEDIFF(sr.receiving_date, po.order_date))
        FROM purchase_orders po
        INNER JOIN stock_receiving sr ON sr.purchase_order_id = po.id
        WHERE po.supplier_id = s.id
          AND sr.receiving_date IS NOT NULL
          AND po.order_date IS NOT NULL)"
    : "NULL";

$sql = "SELECT s.*, 
        {$supplierCodeExpr} AS supplier_code_display,
        {$companyNameExpr} AS company_name_display,
        {$contactExpr} AS contact_display,
        {$statusExpr} AS supplier_status,
        (SELECT SUM(po.total_amount) FROM purchase_orders po WHERE po.supplier_id = s.id) AS total_purchases,
        (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count,
        {$ratingExpr} AS avg_rating,
        {$leadTimeExpr} AS avg_lead_time_days
        FROM suppliers s $where_sql ORDER BY $order_by $order_dir LIMIT $offset, {$params['per_page']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($sql_params);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) FROM suppliers s $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($sql_params);
$total_suppliers = $count_stmt->fetchColumn();
$total_pages = ceil($total_suppliers / $params['per_page']);

include 'templates/header.php';
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">Supplier Database</h1>
                    <div class="card-tools">
                        <a href="supplier_form.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Supplier
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Search and Filter Form -->
                    <form method="GET" class="mb-3">
                        <div class="row">
                            <div class="col-md-4">
                                <input type="text" name="q" class="form-control" placeholder="Search suppliers..." value="<?= htmlspecialchars($params['q']) ?>">
                            </div>
                            <div class="col-md-2">
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="active" <?= $params['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $params['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="sort" class="form-control">
                                    <option value="name" <?= $params['sort'] === 'name' ? 'selected' : '' ?>>Name</option>
                                    <option value="purchases" <?= $params['sort'] === 'purchases' ? 'selected' : '' ?>>Total Purchases</option>
                                    <option value="rating" <?= $params['sort'] === 'rating' ? 'selected' : '' ?>>Rating</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="order" class="form-control">
                                    <option value="asc" <?= $params['order'] === 'asc' ? 'selected' : '' ?>>Ascending</option>
                                    <option value="desc" <?= $params['order'] === 'desc' ? 'selected' : '' ?>>Descending</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-secondary btn-block">Search</button>
                            </div>
                        </div>
                    </form>

                    <!-- Suppliers Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Total Purchases</th>
                                    <th>Lead Time</th>
                                    <th>Rating</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td><?= htmlspecialchars($supplier['supplier_code_display']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($supplier['name']) ?></strong>
                                        <?php if (!empty($supplier['company_name_display'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($supplier['company_name_display']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($supplier['contact_display'] ?? $supplier['contact_person'] ?? $supplier['contact_name'] ?? '') ?></td>
                                    <td>
                                        <?php if ($supplier['email']): ?>
                                        <a href="mailto:<?= htmlspecialchars($supplier['email']) ?>"><?= htmlspecialchars($supplier['email']) ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($supplier['phone']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= !empty($supplier['supplier_status']) ? 'success' : 'secondary' ?>">
                                            <?= !empty($supplier['supplier_status']) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td>$<?= number_format($supplier['total_purchases'] ?? 0, 2) ?></td>
                                    <td>
                                        <?php if ($supplier['avg_lead_time_days'] !== null): ?>
                                        <?= number_format((float)$supplier['avg_lead_time_days'], 1) ?> days
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($supplier['avg_rating']): ?>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?= $i <= $supplier['avg_rating'] ? 'text-warning' : 'text-muted' ?>"></i>
                                        <?php endfor; ?>
                                        <?php else: ?>
                                        <span class="text-muted">No rating</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="supplier_form.php?id=<?= $supplier['id'] ?>" class="btn btn-warning btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="po_form.php?supplier_id=<?= $supplier['id'] ?>" class="btn btn-success btn-sm" title="New PO">
                                                <i class="fas fa-plus"></i> PO
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Supplier pagination">
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
<?php
include 'templates/footer.php';
