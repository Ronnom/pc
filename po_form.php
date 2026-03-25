<?php
// po_form.php
// Create/edit PO, add items, approval, print/email
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
if (!has_permission('purchase.create') && !has_permission('purchase.edit')) {
    http_response_code(403);
    exit('Forbidden');
}
$pdo = get_db_connection();

function table_has_column_po_form($pdo, $table, $column) {
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

function po_form_log_activity($action, $entityId, $description) {
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

function normalize_po_status_po_form($status) {
    return strtolower(trim((string)$status));
}

function po_allowed_next_statuses_po_form($currentStatus) {
    $currentStatus = normalize_po_status_po_form($currentStatus);
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

function po_can_transition_po_form($fromStatus, $toStatus) {
    $fromStatus = normalize_po_status_po_form($fromStatus);
    $toStatus = normalize_po_status_po_form($toStatus);
    if ($fromStatus === $toStatus) {
        return true;
    }
    return in_array($toStatus, po_allowed_next_statuses_po_form($fromStatus), true);
}

$suppliersHasStatus = table_has_column_po_form($pdo, 'suppliers', 'status');
$suppliersHasIsActive = table_has_column_po_form($pdo, 'suppliers', 'is_active');
$suppliersHasCompanyName = table_has_column_po_form($pdo, 'suppliers', 'company_name');
$productsHasUnit = table_has_column_po_form($pdo, 'products', 'unit');
$poHasExpectedDeliveryDate = table_has_column_po_form($pdo, 'purchase_orders', 'expected_delivery_date');
$poHasExpectedDate = table_has_column_po_form($pdo, 'purchase_orders', 'expected_date');
$poHasTotalAmount = table_has_column_po_form($pdo, 'purchase_orders', 'total_amount');
$poHasStatus = table_has_column_po_form($pdo, 'purchase_orders', 'status');
$poHasNotes = table_has_column_po_form($pdo, 'purchase_orders', 'notes');
$poHasTerms = table_has_column_po_form($pdo, 'purchase_orders', 'terms');
$poHasCreatedBy = table_has_column_po_form($pdo, 'purchase_orders', 'created_by');
$poHasApprovalRequired = table_has_column_po_form($pdo, 'purchase_orders', 'approval_required');
$poiHasTotalCost = table_has_column_po_form($pdo, 'purchase_order_items', 'total_cost');
$poiHasTotal = table_has_column_po_form($pdo, 'purchase_order_items', 'total');
$canManagePoStatus = isAdmin() || has_permission('purchase.approve');
$canEmailSupplier = isAdmin()
    || (function_exists('isManager') && isManager())
    || (function_exists('has_permission') ? has_permission('purchase.email') : (function_exists('hasPermission') ? hasPermission('purchase.email') : false))
    || (function_exists('has_permission') ? has_permission('purchase.approve') : (function_exists('hasPermission') ? hasPermission('purchase.approve') : false));

$editing = isset($_GET['id']);
$po = null;
$po_items = [];
$errors = [];

if ($editing) {
    if (!has_permission('purchase.edit') && !$canEmailSupplier) {
        http_response_code(403);
        exit('Forbidden');
    }
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$po) exit('PO not found.');

    // Get PO items
    $poItemUnitSelect = $productsHasUnit ? "p.unit" : "'pcs' AS unit";
    $stmt = $pdo->prepare("
        SELECT poi.*, p.name as product_name, p.sku, {$poItemUnitSelect}
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ");
    $stmt->execute([$po['id']]);
    $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    if (!has_permission('purchase.create')) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$supplierWhere = '';
if ($suppliersHasStatus) {
    $supplierWhere = 'WHERE status = 1';
} elseif ($suppliersHasIsActive) {
    $supplierWhere = 'WHERE is_active = 1';
}

$companyNameSelect = $suppliersHasCompanyName ? 'company_name' : 'NULL AS company_name';
$suppliers = $pdo->query("SELECT id, name, {$companyNameSelect}, email FROM suppliers {$supplierWhere} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$productUnitSelect = $productsHasUnit ? "unit" : "'pcs' AS unit";
$products = $pdo->query("SELECT id, sku, name, cost_price, supplier_id, {$productUnitSelect} FROM products WHERE is_active = 1 AND sku NOT IN ('REPAIR-SERVICE','SERVICE-FEE') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$preselectProductId = (int)($_GET['product_id'] ?? 0);
$preselectQuantity = isset($_GET['qty']) ? max(0.01, (float)$_GET['qty']) : 1;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'supplier_id' => intval($_POST['supplier_id'] ?? 0),
        'order_date' => $_POST['order_date'] ?? date('Y-m-d'),
        'notes' => trim($_POST['notes'] ?? ''),
        'terms' => trim($_POST['terms'] ?? ''),
        'items' => $_POST['items'] ?? []
    ];

    // Validation
    if (!$data['supplier_id']) $errors[] = 'Supplier is required.';
    if (!$data['order_date']) $errors[] = 'Order date is required.';
    if (empty($data['items'])) $errors[] = 'At least one item is required.';

    // Validate items
    $subtotal = 0;
    $valid_items = [];
    foreach ($data['items'] as $item) {
        $product_id = intval($item['product_id'] ?? 0);
        $quantity = floatval($item['quantity'] ?? 0);
        $unit_cost = floatval($item['unit_cost'] ?? 0);

        if (!$product_id || !$quantity || !$unit_cost) {
            $errors[] = 'All item fields are required.';
            continue;
        }
        if ($quantity <= 0) $errors[] = 'Quantity must be greater than 0.';
        if ($unit_cost <= 0) $errors[] = 'Unit cost must be greater than 0.';

        $total = $quantity * $unit_cost;
        $subtotal += $total;
        $valid_items[] = [
            'product_id' => $product_id,
            'quantity' => $quantity,
            'unit_cost' => $unit_cost,
            'total_cost' => $total
        ];
    }

    $requestedStatusForUpdate = null;
    if ($editing && $poHasStatus && $canManagePoStatus && isset($_POST['status'])) {
        $allowedStatuses = ['draft', 'approved', 'sent', 'will_be_delivered', 'confirmed', 'partially_received', 'completed', 'rejected', 'cancelled', 'open', 'pending'];
        $requestedStatus = normalize_po_status_po_form($_POST['status'] ?? '');
        if (!in_array($requestedStatus, $allowedStatuses, true)) {
            $errors[] = 'Invalid purchase order status selected.';
        } else {
            $currentStatus = normalize_po_status_po_form($po['status'] ?? 'draft');
            if (!po_can_transition_po_form($currentStatus, $requestedStatus)) {
                $errors[] = 'Invalid status transition from ' . str_replace('_', ' ', $currentStatus) . ' to ' . str_replace('_', ' ', $requestedStatus) . '.';
            } else {
                $requestedStatusForUpdate = $requestedStatus;
            }
        }
    }

    if (empty($errors)) {
        try {
            $total_amount = $subtotal;

            // Check approval requirement
            $approval_required = $total_amount > 1000; // Configurable threshold

            if ($editing) {
                // Update PO (schema-aware)
                $updateParts = [];
                $updateValues = [];

                $updateParts[] = "supplier_id = ?";
                $updateValues[] = $data['supplier_id'];
                $updateParts[] = "order_date = ?";
                $updateValues[] = $data['order_date'];

                if ($poHasExpectedDeliveryDate) {
                    $updateParts[] = "expected_delivery_date = ?";
                    $updateValues[] = ($_POST['expected_delivery_date'] ?? '') ?: null;
                } elseif ($poHasExpectedDate) {
                    $updateParts[] = "expected_date = ?";
                    $updateValues[] = ($_POST['expected_delivery_date'] ?? '') ?: null;
                }

                if ($poHasTotalAmount) {
                    $updateParts[] = "total_amount = ?";
                    $updateValues[] = $total_amount;
                }
                if ($poHasNotes) {
                    $updateParts[] = "notes = ?";
                    $updateValues[] = $data['notes'];
                }
                if ($poHasTerms) {
                    $updateParts[] = "terms = ?";
                    $updateValues[] = $data['terms'];
                }
                if ($poHasApprovalRequired) {
                    $updateParts[] = "approval_required = ?";
                    $updateValues[] = $approval_required ? 1 : 0;
                }
                if ($poHasStatus && $canManagePoStatus && $requestedStatusForUpdate !== null) {
                    $updateParts[] = "status = ?";
                    $updateValues[] = $requestedStatusForUpdate;
                }

                $updateValues[] = $po['id'];
                $pdo->prepare("UPDATE purchase_orders SET " . implode(', ', $updateParts) . " WHERE id = ?")
                    ->execute($updateValues);

                // Delete existing items and re-insert
                $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$po['id']]);
                if ($poiHasTotalCost) {
                    $stmt = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?)");
                } elseif ($poiHasTotal) {
                    $stmt = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost, total) VALUES (?, ?, ?, ?, ?)");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost) VALUES (?, ?, ?, ?)");
                }
                foreach ($valid_items as $item) {
                    if ($poiHasTotalCost || $poiHasTotal) {
                        $stmt->execute([$po['id'], $item['product_id'], $item['quantity'], $item['unit_cost'], $item['total_cost']]);
                    } else {
                        $stmt->execute([$po['id'], $item['product_id'], $item['quantity'], $item['unit_cost']]);
                    }
                }

                po_form_log_activity('po_updated', $po['id'], "Updated PO: {$po['po_number']}");
            } else {
                // Generate PO number (unique)
                $po_number = '';
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE po_number = ?");
                for ($i = 0; $i < 5; $i++) {
                    $candidate = generatePONumber();
                    $checkStmt->execute([$candidate]);
                    $exists = (int)$checkStmt->fetchColumn();
                    if ($exists === 0) {
                        $po_number = $candidate;
                        break;
                    }
                }
                if ($po_number === '') {
                    throw new Exception('Unable to generate a unique PO number. Please retry.');
                }

                // Create PO (schema-aware)
                $insertData = [
                    'po_number' => $po_number,
                    'supplier_id' => $data['supplier_id'],
                    'order_date' => $data['order_date']
                ];
                if ($poHasExpectedDeliveryDate) {
                    $insertData['expected_delivery_date'] = ($_POST['expected_delivery_date'] ?? '') ?: null;
                } elseif ($poHasExpectedDate) {
                    $insertData['expected_date'] = ($_POST['expected_delivery_date'] ?? '') ?: null;
                }
                if ($poHasTotalAmount) $insertData['total_amount'] = $total_amount;
                if ($poHasStatus) $insertData['status'] = 'draft';
                if ($poHasNotes) $insertData['notes'] = $data['notes'];
                if ($poHasTerms) $insertData['terms'] = $data['terms'];
                if ($poHasCreatedBy) $insertData['created_by'] = $_SESSION['user_id'];
                if ($poHasApprovalRequired) $insertData['approval_required'] = $approval_required ? 1 : 0;

                $columns = array_keys($insertData);
                $placeholders = array_fill(0, count($columns), '?');
                $values = array_values($insertData);
                $pdo->prepare(
                    "INSERT INTO purchase_orders (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
                )->execute($values);

                $po_id = $pdo->lastInsertId();

                // Insert items
                if ($poiHasTotalCost) {
                    $stmt = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?)");
                } elseif ($poiHasTotal) {
                    $stmt = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost, total) VALUES (?, ?, ?, ?, ?)");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost) VALUES (?, ?, ?, ?)");
                }
                foreach ($valid_items as $item) {
                    if ($poiHasTotalCost || $poiHasTotal) {
                        $stmt->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_cost'], $item['total_cost']]);
                    } else {
                        $stmt->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_cost']]);
                    }
                }

                po_form_log_activity('po_created', $po_id, "Created PO: {$po_number}");
            }

            header('Location: purchase_orders.php?success=1');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle actions
$action = $_GET['action'] ?? '';
if ($action && $editing) {
    switch ($action) {
        case 'print':
            // Generate PDF (placeholder - would need PDF library)
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $po['po_number'] . '.pdf"');
            echo "PDF content would go here";
            exit;

        case 'email':
            if (!$canEmailSupplier) {
                http_response_code(403);
                exit('Forbidden');
            }
            // Email PO to supplier (placeholder)
            $stmt = $pdo->prepare("SELECT email FROM suppliers WHERE id = ?");
            $stmt->execute([$po['supplier_id']]);
            $supplier_email = $stmt->fetchColumn();
            if ($supplier_email) {
                // Send email logic here
                if ($poHasStatus && normalize_po_status_po_form($po['status'] ?? '') !== 'approved') {
                    header('Location: po_form.php?id=' . $po['id'] . '&error=Only approved purchase orders can be emailed to supplier.');
                    exit;
                }
                if ($poHasStatus && normalize_po_status_po_form($po['status'] ?? '') === 'approved') {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'sent' WHERE id = ?")->execute([$po['id']]);
                    $po['status'] = 'sent';
                }
                po_form_log_activity('po_emailed', $po['id'], 'PO emailed to supplier');
                header('Location: po_form.php?id=' . $po['id'] . '&message=PO emailed successfully');
            } else {
                header('Location: po_form.php?id=' . $po['id'] . '&error=No supplier email found');
            }
            exit;

        case 'cancel':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!po_can_transition_po_form($po['status'] ?? 'draft', 'cancelled')) {
                    header('Location: po_form.php?id=' . $po['id'] . '&error=This purchase order cannot be cancelled from its current status.');
                    exit;
                }
                $cancel_reason = trim($_POST['cancel_reason'] ?? '');
                if ($poHasNotes) {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\n\nCANCELLED: ', ?) WHERE id = ?")
                        ->execute([$cancel_reason, $po['id']]);
                } else {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE id = ?")
                        ->execute([$po['id']]);
                }
                po_form_log_activity('po_cancelled', $po['id'], "PO cancelled: {$cancel_reason}");
                header('Location: purchase_orders.php?success=1');
                exit;
            }
            // Show cancel form
            break;

        case 'approve':
            if (!isAdmin()) {
                http_response_code(403);
                exit('Forbidden');
            }
            if (!po_can_transition_po_form($po['status'] ?? 'draft', 'approved')) {
                header('Location: po_form.php?id=' . $po['id'] . '&error=This purchase order cannot be approved from its current status.');
                exit;
            }
            $pdo->prepare("UPDATE purchase_orders SET status = 'approved' WHERE id = ?")
                ->execute([$po['id']]);
            po_form_log_activity('po_approved', $po['id'], "PO approved: {$po['po_number']}");
            header('Location: po_form.php?id=' . $po['id'] . '&message=PO approved successfully');
            exit;

        case 'reject':
            if (!isAdmin()) {
                http_response_code(403);
                exit('Forbidden');
            }
            if (!po_can_transition_po_form($po['status'] ?? 'draft', 'rejected')) {
                header('Location: po_form.php?id=' . $po['id'] . '&error=This purchase order cannot be rejected from its current status.');
                exit;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $reject_reason = trim($_POST['reject_reason'] ?? '');
                if ($reject_reason === '') {
                    $reject_reason = 'No reason provided';
                }
                if ($poHasNotes) {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'rejected', notes = CONCAT(COALESCE(notes, ''), '\n\nREJECTED: ', ?) WHERE id = ?")
                        ->execute([$reject_reason, $po['id']]);
                } else {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'rejected' WHERE id = ?")
                        ->execute([$po['id']]);
                }
                po_form_log_activity('po_rejected', $po['id'], "PO rejected: {$po['po_number']} ({$reject_reason})");
                header('Location: po_form.php?id=' . $po['id'] . '&message=PO rejected successfully');
                exit;
            }
            // Show reject form
            break;
    }
}

include 'templates/header.php';
?>

<div class="container-fluid po-form-page">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">
                        <i class="fas fa-file-invoice"></i>
                        <?= $editing ? 'Edit' : 'Create' ?> Purchase Order
                        <?php if ($editing): ?>
                        <small class="text-muted">(<?= htmlspecialchars($po['po_number']) ?>)</small>
                        <?php endif; ?>
                    </h1>
                    <div class="card-tools">
                        <?php if ($editing): ?>
                        <a href="purchase_orders.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to POs
                        </a>
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-toggle="dropdown">
                                <i class="fas fa-bolt"></i> Actions
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="?id=<?= $po['id'] ?>&action=print" target="_blank">
                                    <i class="fas fa-print"></i> Print
                                </a>
                                <?php if ($canEmailSupplier): ?>
                                <a class="dropdown-item" href="?id=<?= $po['id'] ?>&action=email">
                                    <i class="fas fa-paper-plane"></i> Email Supplier
                                </a>
                                <?php endif; ?>
                                <?php if (isAdmin() && in_array($po['status'], ['draft', 'sent', 'open', 'pending', 'confirmed'], true)): ?>
                                <a class="dropdown-item text-success" href="?id=<?= $po['id'] ?>&action=approve">
                                    <i class="fas fa-check-circle"></i> Approve
                                </a>
                                <a class="dropdown-item text-danger" href="?id=<?= $po['id'] ?>&action=reject">
                                    <i class="fas fa-ban"></i> Reject
                                </a>
                                <?php endif; ?>
                                <?php if ($po['status'] !== 'completed' && $po['status'] !== 'cancelled'): ?>
                                <a class="dropdown-item" href="?id=<?= $po['id'] ?>&action=cancel">
                                    <i class="fas fa-xmark"></i> Cancel
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['message'])): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($_GET['message']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($_GET['error']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($action === 'cancel' && $editing): ?>
                    <!-- Cancel PO Form -->
                    <div class="alert alert-warning">
                        <h5>Cancel Purchase Order</h5>
                        <p>Are you sure you want to cancel this PO? This action cannot be undone.</p>
                        <form method="POST">
                            <div class="form-group">
                                <label for="cancel_reason">Cancellation Reason</label>
                                <textarea id="cancel_reason" name="cancel_reason" class="form-control" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger">Cancel PO</button>
                            <a href="?id=<?= $po['id'] ?>" class="btn btn-secondary">Back</a>
                        </form>
                    </div>
                    <?php elseif ($action === 'reject' && $editing && isAdmin()): ?>
                    <div class="alert alert-danger">
                        <h5>Reject Purchase Order</h5>
                        <p>Provide a reason for rejection.</p>
                        <form method="POST">
                            <div class="form-group">
                                <label for="reject_reason">Rejection Reason</label>
                                <textarea id="reject_reason" name="reject_reason" class="form-control" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger"><i class="fas fa-ban"></i> Reject PO</button>
                            <a href="?id=<?= $po['id'] ?>" class="btn btn-secondary">Back</a>
                        </form>
                    </div>
                    <?php else: ?>

                    <form method="POST" id="po-form">
                        <div class="row">
                            <div class="col-md-6">
                                <h4 class="po-section-title"><i class="fas fa-receipt"></i> Order Information</h4>
                                <div class="form-group">
                                    <label for="supplier_id">Supplier *</label>
                                    <select id="supplier_id" name="supplier_id" class="form-control" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= $supplier['id'] ?>"
                                                <?= ($po['supplier_id'] ?? $_GET['supplier_id'] ?? '') == $supplier['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($supplier['name']) ?>
                                            <?php if ($supplier['company_name']): ?>
                                            (<?= htmlspecialchars($supplier['company_name']) ?>)
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="order_date">Order Date *</label>
                                    <input type="date" id="order_date" name="order_date" class="form-control" required
                                           value="<?= htmlspecialchars($po['order_date'] ?? date('Y-m-d')) ?>">
                                </div>
                                <?php if ($editing && $poHasStatus && $canManagePoStatus): ?>
                                <div class="form-group">
                                    <label for="status">PO Status</label>
                                    <select id="status" name="status" class="form-control">
                                        <?php
                                        $currentStatus = normalize_po_status_po_form($po['status'] ?? 'draft');
                                        $statusLabels = [
                                            'draft' => 'Draft',
                                            'open' => 'Open',
                                            'pending' => 'Pending',
                                            'approved' => 'Approved',
                                            'sent' => 'Sent',
                                            'will_be_delivered' => 'On Order',
                                            'confirmed' => 'Confirmed',
                                            'partially_received' => 'Partially Received',
                                            'completed' => 'Completed',
                                            'rejected' => 'Rejected',
                                            'cancelled' => 'Cancelled'
                                        ];
                                        $statusOptions = array_values(array_unique(array_merge([$currentStatus], po_allowed_next_statuses_po_form($currentStatus))));
                                        foreach ($statusOptions as $statusOption):
                                            if (!isset($statusLabels[$statusOption])) {
                                                continue;
                                            }
                                        ?>
                                        <option value="<?= htmlspecialchars($statusOption) ?>" <?= $currentStatus === $statusOption ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($statusLabels[$statusOption]) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h4 class="po-section-title"><i class="fas fa-file-lines"></i> Terms & Notes</h4>
                                <div class="form-group">
                                    <label for="terms">Terms & Conditions</label>
                                    <textarea id="terms" name="terms" class="form-control" rows="4"
                                              placeholder="Payment terms, delivery conditions, etc."><?= htmlspecialchars($po['terms'] ?? '') ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="notes">Notes</label>
                                    <textarea id="notes" name="notes" class="form-control" rows="3"
                                              placeholder="Internal notes..."><?= htmlspecialchars($po['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <h4 class="mt-4 po-section-title"><i class="fas fa-boxes-stacked"></i> Order Items</h4>
                        <div id="items-container">
                            <?php if (!empty($po_items)): ?>
                            <?php foreach ($po_items as $index => $item): ?>
                            <div class="item-row row mb-2">
                                <div class="col-md-4">
                                    <select name="items[<?= $index ?>][product_id]" class="form-control product-select" required>
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                        <option value="<?= $product['id'] ?>" data-cost="<?= $product['cost_price'] ?>" data-unit="<?= $product['unit'] ?>" data-supplier="<?= (int)($product['supplier_id'] ?? 0) ?>"
                                                <?= $item['product_id'] == $product['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($product['sku'] . ' - ' . $product['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="items[<?= $index ?>][quantity]" class="form-control quantity-input"
                                           placeholder="Qty" min="0.01" step="0.01" required
                                           value="<?= htmlspecialchars($item['quantity']) ?>">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="items[<?= $index ?>][unit_cost]" class="form-control unit-cost-input"
                                           placeholder="Unit Cost" min="0.01" step="0.01" required
                                           value="<?= htmlspecialchars($item['unit_cost']) ?>">
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control total-input" readonly
                                           value="$<?= number_format($item['quantity'] * $item['unit_cost'], 2) ?>">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-item">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="item-row row mb-2">
                                <div class="col-md-4">
                                    <select name="items[0][product_id]" class="form-control product-select" required>
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                        <option value="<?= $product['id'] ?>" data-cost="<?= $product['cost_price'] ?>" data-unit="<?= $product['unit'] ?>" data-supplier="<?= (int)($product['supplier_id'] ?? 0) ?>"
                                                <?= $preselectProductId === (int)$product['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($product['sku'] . ' - ' . $product['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="items[0][quantity]" class="form-control quantity-input"
                                           placeholder="Qty" min="0.01" step="0.01" required
                                           value="<?= htmlspecialchars($preselectQuantity) ?>">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="items[0][unit_cost]" class="form-control unit-cost-input"
                                           placeholder="Unit Cost" min="0.01" step="0.01" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control total-input" readonly>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-item">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <button type="button" id="add-item" class="btn btn-outline-primary">
                                    <i class="fas fa-circle-plus"></i> Add Item
                                </button>
                            </div>
                            <div class="col-md-6 text-right">
                                <div class="form-group">
                                    <label>Total Amount: <span id="grand-total">$0.00</span></label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-floppy-disk"></i> <?= $editing ? 'Update' : 'Create' ?> Purchase Order
                            </button>
                            <a href="purchase_orders.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let itemIndex = <?= count($po_items) ?: 1 ?>;

    // Add item button
    document.getElementById('add-item').addEventListener('click', function() {
        addItemRow();
    });

    // Product select change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('product-select')) {
            const option = e.target.selectedOptions[0];
            const cost = option.getAttribute('data-cost');
            const supplierId = option.getAttribute('data-supplier');
            const supplierSelect = document.getElementById('supplier_id');
            const row = e.target.closest('.item-row');
            const unitCostInput = row.querySelector('.unit-cost-input');
            if (cost && !unitCostInput.value) {
                unitCostInput.value = cost;
                calculateRowTotal(row);
                calculateGrandTotal();
            }
            if (supplierSelect && supplierId) {
                supplierSelect.value = supplierId;
            }
        }
    });

    // Quantity and unit cost change
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity-input') || e.target.classList.contains('unit-cost-input')) {
            calculateRowTotal(e.target.closest('.item-row'));
            calculateGrandTotal();
        }
    });

    // Remove item
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-item') || e.target.closest('.remove-item')) {
            const row = e.target.closest('.item-row');
            if (document.querySelectorAll('.item-row').length > 1) {
                row.remove();
                calculateGrandTotal();
            }
        }
    });

    function addItemRow() {
        const container = document.getElementById('items-container');
        const row = document.createElement('div');
        row.className = 'item-row row mb-2';
        row.innerHTML = `
            <div class="col-md-4">
                <select name="items[${itemIndex}][product_id]" class="form-control product-select" required>
                    <option value="">Select Product</option>
                    <?php foreach ($products as $product): ?>
                    <option value="<?= $product['id'] ?>" data-cost="<?= $product['cost_price'] ?>" data-unit="<?= $product['unit'] ?>" data-supplier="<?= (int)($product['supplier_id'] ?? 0) ?>">
                        <?= htmlspecialchars($product['sku'] . ' - ' . $product['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" name="items[${itemIndex}][quantity]" class="form-control quantity-input"
                       placeholder="Qty" min="0.01" step="0.01" required>
            </div>
            <div class="col-md-2">
                <input type="number" name="items[${itemIndex}][unit_cost]" class="form-control unit-cost-input"
                       placeholder="Unit Cost" min="0.01" step="0.01" required>
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control total-input" readonly>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger btn-sm remove-item">
                    <i class="fas fa-trash-can"></i>
                </button>
            </div>
        `;
        container.appendChild(row);
        itemIndex++;
    }

    function calculateRowTotal(row) {
        const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
        const unitCost = parseFloat(row.querySelector('.unit-cost-input').value) || 0;
        const total = quantity * unitCost;
        row.querySelector('.total-input').value = '$' + total.toFixed(2);
    }

    function calculateGrandTotal() {
        let total = 0;
        document.querySelectorAll('.total-input').forEach(function(input) {
            const value = parseFloat(input.value.replace('$', '')) || 0;
            total += value;
        });
        document.getElementById('grand-total').textContent = '$' + total.toFixed(2);
    }

    const preselectedProductId = <?= (int)$preselectProductId ?>;
    const preselectedQuantity = <?= (float)$preselectQuantity ?>;
    if (!<?= $editing ? 'true' : 'false' ?> && preselectedProductId) {
        const firstSelect = document.querySelector('.product-select');
        if (firstSelect) {
            firstSelect.value = String(preselectedProductId);
            firstSelect.dispatchEvent(new Event('change'));
        }
        const qtyInput = document.querySelector('.quantity-input');
        if (qtyInput && preselectedQuantity) {
            qtyInput.value = preselectedQuantity;
            calculateRowTotal(qtyInput.closest('.item-row'));
        }
    }

    // Initial calculation
    calculateGrandTotal();

    // Auto-select supplier if empty and products already selected
    const supplierSelect = document.getElementById('supplier_id');
    if (supplierSelect && !supplierSelect.value) {
        const firstSelected = document.querySelector('.product-select option:checked');
        const supplierId = firstSelected?.getAttribute('data-supplier');
        if (supplierId) {
            supplierSelect.value = supplierId;
        }
    }
});
</script>

<?php
include 'templates/footer.php';


