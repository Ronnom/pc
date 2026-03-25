<?php
// purchase_orders.php
// PO list, search, filter, view, quick actions
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
if (!has_permission('purchase.view')) {
    http_response_code(403);
    exit('Forbidden');
}
$pdo = get_db_connection();
$canManagePoStatus = isAdmin() || has_permission('purchase.approve');

function table_has_column($pdo, $table, $column) {
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

function po_list_log_activity($action, $entityId, $description) {
    if (!function_exists('logUserActivity')) {
        return;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        logUserActivity($userId, $action, 'purchase_orders', $description, $entityId);
    } else {
        logUserActivity($action, $description, 'purchase_orders', $entityId);
    }
}

function normalize_po_status_po_list($status) {
    return strtolower(trim((string)$status));
}

function po_allowed_next_statuses_po_list($currentStatus) {
    $currentStatus = normalize_po_status_po_list($currentStatus);
    $map = [
        'draft' => ['approved', 'rejected', 'cancelled'],
        'open' => ['approved', 'rejected', 'cancelled'],
        'pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['sent', 'rejected', 'cancelled'],
        'sent' => ['will_be_delivered', 'cancelled'],
        'will_be_delivered' => ['confirmed', 'partially_received', 'completed', 'cancelled'],
        'confirmed' => ['partially_received', 'completed', 'cancelled'],
        'partially_received' => ['completed', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => []
    ];
    return $map[$currentStatus] ?? [];
}

function po_can_transition_po_list($fromStatus, $toStatus) {
    $fromStatus = normalize_po_status_po_list($fromStatus);
    $toStatus = normalize_po_status_po_list($toStatus);
    if ($fromStatus === $toStatus) {
        return true;
    }
    return in_array($toStatus, po_allowed_next_statuses_po_list($fromStatus), true);
}

$suppliersHasStatus = table_has_column($pdo, 'suppliers', 'status');
$suppliersHasIsActive = table_has_column($pdo, 'suppliers', 'is_active');
$suppliersHasCompanyName = table_has_column($pdo, 'suppliers', 'company_name');
$usersHasFirstName = table_has_column($pdo, 'users', 'first_name');
$usersHasLastName = table_has_column($pdo, 'users', 'last_name');
$usersHasFullName = table_has_column($pdo, 'users', 'full_name');
$poiHasReceivedQuantity = table_has_column($pdo, 'purchase_order_items', 'received_quantity');
$poHasCreatedBy = table_has_column($pdo, 'purchase_orders', 'created_by');
$poHasNotes = table_has_column($pdo, 'purchase_orders', 'notes');
$poHasApprovedBy = table_has_column($pdo, 'purchase_orders', 'approved_by');
$poHasApprovedAt = table_has_column($pdo, 'purchase_orders', 'approved_at');
$poHasRejectedBy = table_has_column($pdo, 'purchase_orders', 'rejected_by');
$poHasRejectedAt = table_has_column($pdo, 'purchase_orders', 'rejected_at');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_action'], $_POST['po_id'])) {
    if (!$canManagePoStatus) {
        http_response_code(403);
        exit('Forbidden');
    }

    $poId = (int)$_POST['po_id'];
    $poAction = trim((string)$_POST['po_action']);
    $redirectQuery = [];

    try {
        $poRow = $pdo->prepare("SELECT id, po_number, status FROM purchase_orders WHERE id = ?");
        $poRow->execute([$poId]);
        $po = $poRow->fetch(PDO::FETCH_ASSOC);

        if (!$po) {
            $redirectQuery['error'] = 'Purchase order not found.';
        } elseif ($poAction === 'approve') {
            if (!po_can_transition_po_list($po['status'] ?? 'draft', 'approved')) {
                $redirectQuery['error'] = 'This purchase order cannot be approved in its current state.';
            } else {
                $updateParts = ["status = 'approved'"];
                $updateValues = [];
                if ($poHasApprovedBy) {
                    $updateParts[] = "approved_by = ?";
                    $updateValues[] = $_SESSION['user_id'] ?? null;
                }
                if ($poHasApprovedAt) {
                    $updateParts[] = "approved_at = NOW()";
                }
                $updateValues[] = $poId;
                $pdo->prepare("UPDATE purchase_orders SET " . implode(', ', $updateParts) . " WHERE id = ?")->execute($updateValues);
                po_list_log_activity('po_approved', $poId, "Approved PO: {$po['po_number']}");
                $redirectQuery['message'] = 'Purchase order approved.';
            }
        } elseif ($poAction === 'reject') {
            if (!po_can_transition_po_list($po['status'] ?? 'draft', 'rejected')) {
                $redirectQuery['error'] = 'This purchase order cannot be rejected in its current state.';
            } else {
                $reason = trim((string)($_POST['rejection_reason'] ?? ''));
                if ($reason === '') {
                    $reason = 'No reason provided';
                }

                $updateParts = ["status = 'rejected'"];
                $updateValues = [];
                if ($poHasRejectedBy) {
                    $updateParts[] = "rejected_by = ?";
                    $updateValues[] = $_SESSION['user_id'] ?? null;
                }
                if ($poHasRejectedAt) {
                    $updateParts[] = "rejected_at = NOW()";
                }
                if ($poHasNotes) {
                    $updateParts[] = "notes = CONCAT(COALESCE(notes, ''), ?)";
                    $updateValues[] = "\n\nREJECTED: " . $reason;
                }
                $updateValues[] = $poId;
                $pdo->prepare("UPDATE purchase_orders SET " . implode(', ', $updateParts) . " WHERE id = ?")->execute($updateValues);
                po_list_log_activity('po_rejected', $poId, "Rejected PO: {$po['po_number']} ({$reason})");
                $redirectQuery['message'] = 'Purchase order rejected.';
            }
        } elseif ($poAction === 'mark_will_be_delivered') {
            if (!po_can_transition_po_list($po['status'] ?? 'draft', 'will_be_delivered')) {
                $redirectQuery['error'] = 'This purchase order cannot be marked as will be delivered in its current state.';
            } else {
                $pdo->prepare("UPDATE purchase_orders SET status = 'will_be_delivered' WHERE id = ?")->execute([$poId]);
                po_list_log_activity('po_status_updated', $poId, "Updated PO {$po['po_number']} status to will_be_delivered");
                $redirectQuery['message'] = 'Purchase order marked as On Order.';
            }
        } else {
            $redirectQuery['error'] = 'Invalid action.';
        }
    } catch (Exception $e) {
        $redirectQuery['error'] = 'Failed to update purchase order status.';
    }

    header('Location: purchase_orders.php' . (!empty($redirectQuery) ? '?' . http_build_query($redirectQuery) : ''));
    exit;
}

$params = [
    'q' => $_GET['q'] ?? '',
    'status' => $_GET['status'] ?? '',
    'supplier' => $_GET['supplier'] ?? '',
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'page' => max(1, intval($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, intval($_GET['per_page'] ?? 20)))
];
$where = [];
$sql_params = [];
if ($params['q']) {
    $where[] = "(po.po_number LIKE ? OR po.notes LIKE ? OR s.name LIKE ?)";
    foreach (range(1,3) as $i) $sql_params[] = '%' . $params['q'] . '%';
}
if ($params['status']) {
    $where[] = "po.status = ?";
    $sql_params[] = $params['status'];
} else {
    $where[] = "po.status <> 'completed'";
}
if ($params['supplier']) {
    $where[] = "po.supplier_id = ?";
    $sql_params[] = $params['supplier'];
}
if ($params['supplier_id']) {
    $where[] = "po.supplier_id = ?";
    $sql_params[] = $params['supplier_id'];
}
if ($params['date_from']) {
    $where[] = "po.order_date >= ?";
    $sql_params[] = $params['date_from'];
}
if ($params['date_to']) {
    $where[] = "po.order_date <= ?";
    $sql_params[] = $params['date_to'];
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$offset = ($params['page'] - 1) * $params['per_page'];

// Get suppliers for filter dropdown
$supplierWhere = '';
if ($suppliersHasStatus) {
    $supplierWhere = 'WHERE status = 1';
} elseif ($suppliersHasIsActive) {
    $supplierWhere = 'WHERE is_active = 1';
}
$suppliers = $pdo->query("SELECT id, name FROM suppliers {$supplierWhere} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get POs with supplier info
$companyNameSelect = $suppliersHasCompanyName ? "s.company_name" : "NULL as company_name";
$createdBySelect = ($usersHasFirstName && $usersHasLastName)
    ? "u.first_name, u.last_name, NULL as full_name, u.username"
    : (($usersHasFullName)
        ? "NULL as first_name, NULL as last_name, u.full_name, u.username"
        : "NULL as first_name, NULL as last_name, NULL as full_name, u.username");
$createdBySelect = $poHasCreatedBy
    ? $createdBySelect
    : "NULL as first_name, NULL as last_name, NULL as full_name, NULL as username";
$totalReceivedSubquery = $poiHasReceivedQuantity
    ? "(SELECT SUM(poi.received_quantity) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)"
    : "(SELECT SUM(CASE WHEN po.status IN ('completed','partially_received') THEN poi.quantity ELSE 0 END) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)";
$createdByJoin = $poHasCreatedBy ? "LEFT JOIN users u ON po.created_by = u.id" : "";
$sql = "SELECT po.*, s.name as supplier_name, {$companyNameSelect},
               {$createdBySelect},
               (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) as item_count,
               {$totalReceivedSubquery} as total_received,
               (SELECT SUM(poi.quantity) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) as total_ordered
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        {$createdByJoin}
        $where_sql ORDER BY po.order_date DESC LIMIT $offset, {$params['per_page']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($sql_params);
$pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($sql_params);
$total_pos = $count_stmt->fetchColumn();
$total_pages = ceil($total_pos / $params['per_page']);

include 'templates/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="page-header-section mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="page-title mb-1">Purchase Orders</h1>
                <p class="text-muted mb-0">Manage and track active supplier orders</p>
            </div>
            <div class="d-flex gap-2">
                <a href="purchase_orders_archive.php" class="btn btn-outline-secondary btn-lg">
                    <i class="fas fa-archive"></i> <span class="ml-2">Archive</span>
                </a>
                <a href="po_form.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus-circle"></i> <span class="ml-2">Create PO</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="card card-modern">
        <!-- Filter & Search Toolbar -->
        <div class="card-header card-header-modern bg-white border-bottom py-4">
            <form method="GET" class="po-filter-form">
                <div class="filter-toolbar">
                    <!-- Search Bar (Primary) -->
                    <div class="filter-group search-group">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input 
                                type="text" 
                                name="q" 
                                class="form-control form-control-modern" 
                                placeholder="Search by PO number, supplier..." 
                                value="<?= htmlspecialchars($params['q']) ?>"
                            >
                        </div>
                    </div>

                    <!-- Filter Groups -->
                    <div class="filter-groups-container">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="form-control form-control-modern">
                                <option value="">All Status</option>
                                <option value="draft" <?= $params['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="sent" <?= $params['status'] === 'sent' ? 'selected' : '' ?>>Sent</option>
                                <option value="will_be_delivered" <?= $params['status'] === 'will_be_delivered' ? 'selected' : '' ?>>On Order</option>
                                <option value="confirmed" <?= $params['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                <option value="partially_received" <?= $params['status'] === 'partially_received' ? 'selected' : '' ?>>Partially Received</option>
                                <option value="approved" <?= $params['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= $params['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                <option value="cancelled" <?= $params['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Supplier</label>
                            <select name="supplier" class="form-control form-control-modern">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= $supplier['id'] ?>" <?= $params['supplier'] == $supplier['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($supplier['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group date-range-group">
                            <label class="filter-label">Order Date</label>
                            <div class="date-range-inputs">
                                <input 
                                    type="date" 
                                    name="date_from" 
                                    class="form-control form-control-modern" 
                                    value="<?= htmlspecialchars($params['date_from']) ?>"
                                    placeholder="From"
                                >
                                <span class="date-range-separator">to</span>
                                <input 
                                    type="date" 
                                    name="date_to" 
                                    class="form-control form-control-modern" 
                                    value="<?= htmlspecialchars($params['date_to']) ?>"
                                    placeholder="To"
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="purchase_orders.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <div class="card-body card-body-modern p-0">
            <!-- Alerts -->
            <?php if (isset($_GET['message'])): ?>
            <div class="alert alert-success alert-modern alert-dismissible fade show">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($_GET['message']) ?></span>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($_GET['error']) ?></span>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php endif; ?>

            <!-- PO Table (Redesigned) -->
            <div class="po-table-wrapper">
                <table class="table table-modern table-hover po-table">
                    <thead class="thead-modern">
                        <tr>
                            <th class="col-po-info">
                                <span class="th-label">PO & Supplier</span>
                            </th>
                            <th class="col-amount text-right">
                                <span class="th-label">Amount</span>
                            </th>
                            <th class="col-progress">
                                <span class="th-label">Progress</span>
                            </th>
                            <th class="col-status">
                                <span class="th-label">Status</span>
                            </th>
                            <th class="col-created">
                                <span class="th-label">Created</span>
                            </th>
                            <th class="col-actions text-right">
                                <span class="th-label">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pos as $po): ?>
                        <tr class="po-row" data-po-id="<?= $po['id'] ?>">
                            <!-- PO Number & Supplier -->
                            <td class="col-po-info">
                                <div class="po-info-cell">
                                    <a href="po_form.php?id=<?= $po['id'] ?>" class="po-number">
                                        <?= htmlspecialchars($po['po_number']) ?>
                                    </a>
                                    <div class="po-supplier-info">
                                        <span class="supplier-name"><?= htmlspecialchars($po['supplier_name']) ?></span>
                                        <?php if ($po['company_name']): ?>
                                        <span class="supplier-company"><?= htmlspecialchars($po['company_name']) ?></span>
                                        <?php endif; ?>
                                        <span class="po-date-mobile">
                                            <i class="fas fa-calendar-alt"></i>
                                            <?= date('M d, Y', strtotime($po['order_date'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <!-- Total Amount -->
                            <td class="col-amount text-right">
                                <span class="amount-value">$<?= number_format($po['total_amount'], 2) ?></span>
                            </td>

                            <!-- Progress Bar -->
                            <td class="col-progress">
                                <?php if ($po['total_ordered'] > 0): ?>
                                    <?php
                                    $progress = ($po['total_received'] ?? 0) / $po['total_ordered'] * 100;
                                    $progress_class = $progress == 100 ? 'progress-success' : ($progress > 0 ? 'progress-partial' : 'progress-pending');
                                    ?>
                                    <div class="progress-container">
                                        <div class="progress progress-modern <?= $progress_class ?>">
                                            <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                                        </div>
                                        <span class="progress-text">
                                            <?= $po['total_received'] ?? 0 ?>/<?= $po['total_ordered'] ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- Status Badge -->
                            <td class="col-status">
                                <span class="badge badge-modern badge-<?= get_status_badge_class($po['status']) ?>">
                                    <?= htmlspecialchars(format_po_status_label($po['status'])) ?>
                                </span>
                            </td>

                            <!-- Created By & Date -->
                            <td class="col-created">
                                <div class="created-info">
                                    <?php
                                    $createdByName = trim((string)(($po['first_name'] ?? '') . ' ' . ($po['last_name'] ?? '')));
                                    if ($createdByName === '') {
                                        $createdByName = trim((string)($po['full_name'] ?? ''));
                                    }
                                    if ($createdByName === '') {
                                        $createdByName = (string)($po['username'] ?? 'User');
                                    }
                                    ?>
                                    <span class="created-by"><?= htmlspecialchars($createdByName) ?></span>
                                    <span class="created-date"><?= date('M d, Y', strtotime($po['created_at'])) ?></span>
                                </div>
                            </td>

                            <!-- Actions -->
                            <td class="col-actions text-right">
                                <div class="action-buttons">
                                    <a 
                                        href="po_form.php?id=<?= $po['id'] ?>" 
                                        class="action-btn action-view" 
                                        title="View Details"
                                        data-bs-toggle="tooltip"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if ($po['status'] === 'draft'): ?>
                                    <a 
                                        href="po_form.php?id=<?= $po['id'] ?>" 
                                        class="action-btn action-edit" 
                                        title="Edit"
                                        data-bs-toggle="tooltip"
                                    >
                                        <i class="fas fa-pencil-alt"></i>
                                    </a>
                                    <?php endif; ?>

                                    <?php if ($canManagePoStatus && po_can_transition_po_list($po['status'] ?? 'draft', 'approved')): ?>
                                    <form method="POST" class="d-inline po-action-form">
                                        <input type="hidden" name="po_action" value="approve">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                        <button 
                                            type="submit" 
                                            class="action-btn action-approve" 
                                            title="Approve"
                                            data-bs-toggle="tooltip"
                                        >
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" class="d-inline po-action-form js-po-reject-form">
                                        <input type="hidden" name="po_action" value="reject">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                        <input type="hidden" name="rejection_reason" value="">
                                        <button 
                                            type="submit" 
                                            class="action-btn action-reject" 
                                            title="Reject"
                                            data-bs-toggle="tooltip"
                                        >
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($canManagePoStatus && po_can_transition_po_list($po['status'] ?? 'draft', 'will_be_delivered')): ?>
                                    <form method="POST" class="d-inline po-action-form">
                                        <input type="hidden" name="po_action" value="mark_will_be_delivered">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                        <button
                                            type="submit"
                                            class="action-btn action-receive"
                                            title="Mark as On Order"
                                            data-bs-toggle="tooltip"
                                        >
                                            <i class="fas fa-truck"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if (in_array($po['status'], ['approved', 'will_be_delivered', 'confirmed', 'partially_received'], true)): ?>
                                    <a 
                                        href="goods_receipt.php?po_id=<?= $po['id'] ?>" 
                                        class="action-btn action-receive" 
                                        title="Receive Goods"
                                        data-bs-toggle="tooltip"
                                    >
                                        <i class="fas fa-box"></i>
                                    </a>
                                    <?php endif; ?>

                                    <!-- More Actions Dropdown -->
                                    <div class="dropdown d-inline">
                                        <button 
                                            class="action-btn action-more" 
                                            type="button" 
                                            data-bs-toggle="dropdown"
                                            aria-haspopup="true"
                                            aria-expanded="false"
                                            title="More Options"
                                        >
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right po-actions-menu">
                                            <a class="dropdown-item" href="po_form.php?id=<?= $po['id'] ?>&action=print" target="_blank">
                                                <i class="fas fa-print"></i> Print
                                            </a>
                                            <?php if (isAdmin()
                                                || (function_exists('isManager') && isManager())
                                                || (function_exists('has_permission') ? has_permission('purchase.email') : (function_exists('hasPermission') ? hasPermission('purchase.email') : false))
                                                || (function_exists('has_permission') ? has_permission('purchase.approve') : (function_exists('hasPermission') ? hasPermission('purchase.approve') : false))): ?>
                                            <a class="dropdown-item" href="po_form.php?id=<?= $po['id'] ?>&action=email">
                                                <i class="fas fa-envelope"></i> Email Supplier
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($po['status'] !== 'completed' && $po['status'] !== 'cancelled'): ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger" href="po_form.php?id=<?= $po['id'] ?>&action=cancel">
                                                <i class="fas fa-times-circle"></i> Cancel Order
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <?php if (empty($pos)): ?>
            <div class="empty-state-container">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Purchase Orders Found</h3>
                    <p>There are no purchase orders matching your criteria. Try adjusting your filters or create a new one.</p>
                    <a href="po_form.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus"></i> Create First PO
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Purchase Orders pagination" class="pagination-container">
                <ul class="pagination pagination-modern">
                    <?php if ($params['page'] > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => 1])) ?>">
                            <i class="fas fa-chevron-left"></i> First
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $params['page'] - 1])) ?>">Previous</a>
                    </li>
                    <?php endif; ?>

                    <?php 
                    $start = max(1, $params['page'] - 2);
                    $end = min($total_pages, $params['page'] + 2);
                    if ($start > 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?= $i === $params['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($end < $total_pages): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>

                    <?php if ($params['page'] < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $params['page'] + 1])) ?>">Next</a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($params, ['page' => $total_pages])) ?>">
                            Last <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
function get_status_badge_class($status) {
    $classes = [
        'draft' => 'secondary',
        'open' => 'secondary',
        'pending' => 'warning',
        'sent' => 'info',
        'will_be_delivered' => 'info',
        'confirmed' => 'primary',
        'approved' => 'success',
        'rejected' => 'danger',
        'partially_received' => 'warning',
        'completed' => 'success',
        'cancelled' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}

function format_po_status_label($status) {
    $status = strtolower(trim((string)$status));
    if ($status === 'will_be_delivered') {
        return 'On Order';
    }
    return ucwords(str_replace('_', ' ', $status));
}
?>
<?php
include 'templates/footer.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize tooltips (Bootstrap 5)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })

    // Reject form handler
    document.querySelectorAll('.js-po-reject-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var reason = window.prompt('Enter rejection reason:');
            if (reason === null) {
                event.preventDefault();
                return;
            }
            form.querySelector('input[name="rejection_reason"]').value = reason.trim();
        });
    });

    // Smooth table row interactions
    const rows = document.querySelectorAll('.po-row');
    rows.forEach(function(row) {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'var(--secondary-50)';
        });

        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });

    // Action button tooltips and confirmations
    const actionButtons = document.querySelectorAll('.action-btn.action-approve, .action-btn.action-reject');
    actionButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            // Tooltip already handled by Bootstrap
        });
    });

    // Mobile responsive adjustments
    if (window.innerWidth < 768) {
        const tables = document.querySelectorAll('.table-modern');
        tables.forEach(function(table) {
            table.classList.add('table-responsive');
        });
    }
});

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert-modern');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            $(alert).fadeOut('slow', function() {
                $(this).remove();
            });
        }, 5000);
    });
});
</script>
<?php
