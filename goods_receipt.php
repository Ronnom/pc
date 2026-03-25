<?php
// goods_receipt.php
// Receive goods against PO, update inventory, quality check
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
if (!has_permission('inventory.receive')
    && !isAdmin()
    && (function_exists('isManager') ? !isManager() : true)
    && !(function_exists('has_permission') ? has_permission('purchase.approve') : false)) {
    http_response_code(403);
    exit('Forbidden');
}
$pdo = get_db_connection();
$po_id = $_GET['po_id'] ?? null;
if (!$po_id) exit('No PO specified.');

function table_has_column_gr($pdo, $table, $column) {
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

function table_exists_gr($pdo, $table) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function gr_log_activity($action, $entityId, $description) {
    if (!function_exists('logUserActivity')) {
        return;
    }
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        logUserActivity($userId, $action, 'goods_receipt', $description, $entityId);
    } else {
        logUserActivity($action, $description, 'goods_receipt', $entityId);
    }
}

$suppliersHasCompanyName = table_has_column_gr($pdo, 'suppliers', 'company_name');
$poHasExpectedDeliveryDate = table_has_column_gr($pdo, 'purchase_orders', 'expected_delivery_date');
$poHasExpectedDate = table_has_column_gr($pdo, 'purchase_orders', 'expected_date');
$productsHasUnit = table_has_column_gr($pdo, 'products', 'unit');
$poiHasReceivedQuantity = table_has_column_gr($pdo, 'purchase_order_items', 'received_quantity');
$stockReceivingExists = table_exists_gr($pdo, 'stock_receiving');
$stockReceivingItemsExists = table_exists_gr($pdo, 'stock_receiving_items');
$stockMovementsExists = table_exists_gr($pdo, 'stock_movements');
$stockMovementsSchema = [
    'exists' => $stockMovementsExists,
    'product_id' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'product_id'),
    'movement_type' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'movement_type'),
    'type' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'type'),
    'direction' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'direction'),
    'quantity' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'quantity'),
    'qty' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'qty'),
    'reference_type' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'reference_type'),
    'reference_id' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'reference_id'),
    'notes' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'notes'),
    'created_by' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'created_by'),
    'user_id' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'user_id'),
    'movement_date' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'movement_date'),
    'date' => $stockMovementsExists && table_has_column_gr($pdo, 'stock_movements', 'date')
];
$serialTrackingExists = table_exists_gr($pdo, 'product_serial_numbers');
$serialHasStockedCostPrice = $serialTrackingExists && table_has_column_gr($pdo, 'product_serial_numbers', 'stocked_cost_price');

$companyNameSelect = $suppliersHasCompanyName ? "s.company_name" : "NULL AS company_name";
$stmt = $pdo->prepare("SELECT po.*, s.name as supplier_name, {$companyNameSelect}
                       FROM purchase_orders po
                       LEFT JOIN suppliers s ON po.supplier_id = s.id
                       WHERE po.id = ?");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$po) exit('PO not found.');

// Accept legacy and current PO workflow states that are valid for receiving.
$receivableStatuses = ['approved', 'sent', 'will_be_delivered', 'confirmed', 'partially_received', 'open', 'pending'];
$poStatus = strtolower(trim((string)($po['status'] ?? '')));
$isReceivableStatus = in_array($poStatus, $receivableStatuses, true);
$isCompletedStatus = ($poStatus === 'completed');
if (!$isReceivableStatus && !$isCompletedStatus) {
    exit('PO is not in a receivable status.');
}

function gr_insert_stock_movement($pdo, $payload, $schema) {
    if (empty($schema['exists'])) {
        return false;
    }

    $columns = [];
    $values = [];

    if (!empty($schema['product_id'])) {
        $columns[] = 'product_id';
        $values[] = (int)$payload['product_id'];
    } else {
        return false;
    }

    if (!empty($schema['movement_type'])) {
        $columns[] = 'movement_type';
        $values[] = 'in';
    } elseif (!empty($schema['type'])) {
        $columns[] = 'type';
        $values[] = 'in';
    } elseif (!empty($schema['direction'])) {
        $columns[] = 'direction';
        $values[] = 'in';
    } else {
        return false;
    }

    if (!empty($schema['quantity'])) {
        $columns[] = 'quantity';
        $values[] = (float)$payload['quantity'];
    } elseif (!empty($schema['qty'])) {
        $columns[] = 'qty';
        $values[] = (float)$payload['quantity'];
    } else {
        return false;
    }

    if (!empty($schema['reference_type'])) {
        $columns[] = 'reference_type';
        $values[] = 'goods_receipt';
    }
    if (!empty($schema['reference_id'])) {
        $columns[] = 'reference_id';
        $values[] = (int)$payload['reference_id'];
    }
    if (!empty($schema['notes'])) {
        $columns[] = 'notes';
        $values[] = (string)$payload['notes'];
    }
    if (!empty($schema['created_by'])) {
        $columns[] = 'created_by';
        $values[] = $payload['user_id'] ? (int)$payload['user_id'] : null;
    } elseif (!empty($schema['user_id'])) {
        $columns[] = 'user_id';
        $values[] = $payload['user_id'] ? (int)$payload['user_id'] : null;
    }
    if (!empty($schema['movement_date'])) {
        $columns[] = 'movement_date';
        $values[] = (string)$payload['movement_date'];
    }
    if (!empty($schema['date'])) {
        $columns[] = 'date';
        $values[] = (string)$payload['movement_date'];
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO stock_movements (" . implode(',', $columns) . ") VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($values);
}

$unitSelect = $productsHasUnit ? "p.unit" : "'pcs' AS unit";
$receivedSelect = $poiHasReceivedQuantity ? "poi.received_quantity" : "0 AS received_quantity";
$remainingSelect = $poiHasReceivedQuantity
    ? "(poi.quantity - poi.received_quantity) as remaining_quantity"
    : "poi.quantity as remaining_quantity";
$items = $pdo->prepare("
    SELECT poi.*, p.name as product_name, p.sku, {$unitSelect},
           {$receivedSelect},
           {$remainingSelect}
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.purchase_order_id = ?
    ORDER BY poi.id
");
$items->execute([$po_id]);
$po_items = $items->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = false;
$poItemsById = [];
foreach ($po_items as $po_item) {
    $poItemsById[(string)$po_item['id']] = $po_item;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isReceivableStatus) {
        $errors[] = 'This purchase order is already completed and can no longer receive additional quantities.';
    }

    $receiving_data = [
        'receiving_date' => $_POST['receiving_date'] ?? date('Y-m-d'),
        'invoice_number' => trim((string)($_POST['invoice_number'] ?? '')),
        'invoice_date' => $_POST['invoice_date'] ?? '',
        'notes' => trim($_POST['notes'] ?? ''),
        'items' => $_POST['items'] ?? []
    ];

    // Validation
    if (!$receiving_data['receiving_date']) $errors[] = 'Receiving date is required.';
    if (empty($receiving_data['items'])) $errors[] = 'No items to receive.';

    $total_received_value = 0;
    $has_receipts = false;

    foreach ($receiving_data['items'] as $item_id => $item_data) {
        $received_quantity = floatval($item_data['received_quantity'] ?? 0);

        if ($received_quantity > 0) {
            $has_receipts = true;
            $item = $poItemsById[(string)$item_id] ?? null;

            if ($item && $received_quantity > $item['remaining_quantity']) {
                $errors[] = "Received quantity for {$item['product_name']} exceeds remaining quantity.";
            }

            if ($item) {
                $total_received_value += $received_quantity * $item['unit_cost'];
            }

            if ($serialTrackingExists) {
                if (floor($received_quantity) != $received_quantity) {
                    $errors[] = "Received quantity for {$item['product_name']} must be a whole number for serial tracking.";
                }

                $serials = $_POST['serials'][$item_id] ?? [];
                $serials = is_array($serials) ? array_values(array_filter(array_map('trim', $serials), static function ($v) {
                    return $v !== '';
                })) : [];
                if (count($serials) !== (int)$received_quantity) {
                    $errors[] = "Please enter/scan exactly {$received_quantity} serial number(s) for {$item['product_name']}.";
                }
            }
        }
    }

    if (!$has_receipts) $errors[] = 'At least one item must have a received quantity.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $receiving_id = null;
            $receiving_number = null;
            if ($stockReceivingExists) {
                // Generate receiving number
                $year = date('Y');
                $seq = $pdo->query("SELECT LPAD(COUNT(*)+1,4,'0') FROM stock_receiving WHERE YEAR(receiving_date) = $year")->fetchColumn();
                $receiving_number = "GR-{$year}-{$seq}";

                // Insert stock receiving
                $stmt = $pdo->prepare("
                    INSERT INTO stock_receiving (
                        receiving_number, purchase_order_id, supplier_id, receiving_date,
                        invoice_number, invoice_date, total_amount, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $receiving_number, $po_id, $po['supplier_id'], $receiving_data['receiving_date'],
                    $receiving_data['invoice_number'], $receiving_data['invoice_date'] ?: null,
                    $total_received_value, $receiving_data['notes'], $_SESSION['user_id']
                ]);

                $receiving_id = $pdo->lastInsertId();
            }

            // Process items
            foreach ($receiving_data['items'] as $item_id => $item_data) {
                $received_quantity = floatval($item_data['received_quantity'] ?? 0);

                if ($received_quantity > 0) {
                    $item = $poItemsById[(string)$item_id] ?? null;

                    if ($item) {
                        // Insert stock receiving item if tracking tables exist
                        if ($stockReceivingExists && $stockReceivingItemsExists && $receiving_id) {
                            $stmt = $pdo->prepare("
                                INSERT INTO stock_receiving_items (
                                    receiving_id, product_id, quantity, received_quantity, cost_per_unit,
                                    total_cost
                                ) VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $receiving_id, $item['product_id'], $item['quantity'], $received_quantity,
                                $item['unit_cost'], $received_quantity * $item['unit_cost']
                            ]);
                        }

                        // Update inventory
                        $stmt = $pdo->prepare("
                            UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?
                        ");
                        $stmt->execute([$received_quantity, $item['product_id']]);

                        // Record goods receipt in stock movements so it appears in recent movement history.
                        $movementRefId = $receiving_id ? (int)$receiving_id : (int)$po_id;
                        $movementNotes = "Received via PO {$po['po_number']}" . ($receiving_number ? " / {$receiving_number}" : '');
                        gr_insert_stock_movement($pdo, [
                            'product_id' => (int)$item['product_id'],
                            'quantity' => $received_quantity,
                            'reference_id' => $movementRefId,
                            'notes' => $movementNotes,
                            'user_id' => $_SESSION['user_id'] ?? null,
                            'movement_date' => $receiving_data['receiving_date']
                        ], $stockMovementsSchema);

                        // Update PO item received quantity when available
                        $new_received = $item['received_quantity'] + $received_quantity;
                        if ($poiHasReceivedQuantity) {
                            $stmt = $pdo->prepare("
                                UPDATE purchase_order_items SET received_quantity = ? WHERE id = ?
                            ");
                            $stmt->execute([$new_received, $item_id]);

                        }

                        if ($serialTrackingExists) {
                            $serials = $_POST['serials'][$item_id] ?? [];
                            $serials = is_array($serials) ? array_values(array_filter(array_map('trim', $serials), static function ($v) {
                                return $v !== '';
                            })) : [];

                            if (count($serials) !== count(array_unique(array_map('strtolower', $serials)))) {
                                throw new Exception("Duplicate serial numbers detected for {$item['product_name']}.");
                            }

                            if (!empty($serials)) {
                                $placeholders = implode(',', array_fill(0, count($serials), '?'));
                                $existingStmt = $pdo->prepare("SELECT serial_number FROM product_serial_numbers WHERE serial_number IN ({$placeholders})");
                                $existingStmt->execute($serials);
                                $existing = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
                                if (!empty($existing)) {
                                    throw new Exception("Serial number(s) already exist: " . implode(', ', $existing));
                                }

                                $insertSerialStmt = $pdo->prepare(
                                    $serialHasStockedCostPrice
                                        ? "INSERT INTO product_serial_numbers (product_id, serial_number, status, stocked_cost_price, notes)
                                           VALUES (?, ?, 'in_stock', ?, ?)"
                                        : "INSERT INTO product_serial_numbers (product_id, serial_number, status, notes)
                                           VALUES (?, ?, 'in_stock', ?)"
                                );
                                foreach ($serials as $serial) {
                                    if ($serialHasStockedCostPrice) {
                                        $insertSerialStmt->execute([
                                            $item['product_id'],
                                            $serial,
                                            $item['unit_cost'],
                                            "Received via PO {$po['po_number']}" . ($receiving_number ? " / {$receiving_number}" : '')
                                        ]);
                                    } else {
                                        $insertSerialStmt->execute([
                                            $item['product_id'],
                                            $serial,
                                            "Received via PO {$po['po_number']}" . ($receiving_number ? " / {$receiving_number}" : '')
                                        ]);
                                    }
                                }
                            }
                        }

                        // Log stock movement
                        $grLabel = $receiving_number ?: ('PO-' . $po['po_number']);
                        gr_log_activity('stock_received', $item['product_id'],
                            "Received {$received_quantity} units via {$grLabel}");
                    }
                }
            }

            // Business rule: once receipt is completed with scanned serials, mark PO completed immediately.
            $new_status = 'completed';
            $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $po_id]);

            $pdo->commit();

            $grMessage = $receiving_number ? "Goods received for PO {$po['po_number']}, GR: {$receiving_number}" : "Goods received for PO {$po['po_number']}";
            gr_log_activity('goods_received', $po_id, $grMessage);

            $success = true;
            $message = $receiving_number
                ? "Goods received successfully. GR Number: {$receiving_number}"
                : "Goods received successfully.";

            // Redirect after success
            header("Location: purchase_orders.php?success=1&message=" . urlencode($message));
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Goods Receipt';
$additionalCSS = [getBaseUrl() . '/assets/css/goods_receipt.css'];
include 'templates/header.php';

// Get current user info for "Received By" field
$currentUser = getCurrentUser();
$receivedByName = trim((string)(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')));
if ($receivedByName === '') {
    $receivedByName = $currentUser['username'] ?? 'Current User';
}
$receivedByRole = getUserRoleLabel($currentUser) ?? 'Warehouse Staff';
?>

<div class="container-fluid goods-receipt-page">
    <!-- Page Header -->
    <div class="page-header-section mb-4">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h1 class="page-title mb-1">Goods Receipt</h1>
                <div class="page-subtitle">
                    <i class="fas fa-box-open text-muted"></i>
                    <span class="text-muted">PO-<?= htmlspecialchars($po['po_number']) ?></span>
                </div>
            </div>
            <a href="purchase_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Purchase Orders
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <!-- Alerts -->
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['message'] ?? 'Goods received successfully!') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-exclamation-circle"></i> <strong>Errors:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!$isReceivableStatus): ?>
            <div class="alert alert-info mb-3" role="alert">
                <i class="fas fa-info-circle"></i> This purchase order is already completed. Viewing is allowed, but further receiving is disabled.
            </div>
            <?php endif; ?>

            <!-- PO Information Card -->
            <div class="card-modern po-summary-card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-group mb-3">
                                <label class="info-label">Supplier</label>
                                <div class="info-value">
                                    <?= htmlspecialchars($po['supplier_name']) ?>
                                    <?php if ($po['company_name']): ?>
                                    <div class="text-muted small"><?= htmlspecialchars($po['company_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-group mb-3">
                                <label class="info-label">Order Date</label>
                                <div class="info-value"><?= date('M d, Y', strtotime($po['order_date'])) ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-group mb-3">
                                <label class="info-label">Total Amount</label>
                                <div class="info-value amount-value">
                                    $<?= number_format($po['total_amount'], 2) ?>
                                </div>
                            </div>
                            <div class="info-group mb-3">
                                <label class="info-label">Status</label>
                                <div class="info-value">
                                    <span class="badge-modern badge-<?= get_status_badge_class($po['status']) ?>">
                                        <?= htmlspecialchars(strtolower(trim((string)$po['status'])) === 'will_be_delivered' ? 'On Order' : ucfirst(str_replace('_', ' ', $po['status']))) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Receiving Form Card -->
            <div class="card-modern">
                <div class="card-body">
                    <h5 class="section-title mb-4">Receiving Details</h5>

    <form method="POST">
                        <!-- Receiving Details Grid -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="receiving_date" class="form-label">Receiving Date *</label>
                                    <input type="date" id="receiving_date" name="receiving_date" class="form-control-modern" required
                                           value="<?= htmlspecialchars($_POST['receiving_date'] ?? date('Y-m-d')) ?>">
                                </div>
                            </div>
                            <?php if ($stockReceivingExists): ?>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="invoice_number" class="form-label">Invoice Number</label>
                                    <input type="text" id="invoice_number" name="invoice_number" class="form-control-modern"
                                           value="<?= htmlspecialchars($_POST['invoice_number'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="invoice_date" class="form-label">Invoice Date</label>
                                    <input type="date" id="invoice_date" name="invoice_date" class="form-control-modern"
                                           value="<?= htmlspecialchars($_POST['invoice_date'] ?? '') ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">Received By</label>
                                    <div class="info-display">
                                        <i class="fas fa-user-check text-primary"></i>
                                        <span class="fw-600"><?= htmlspecialchars($receivedByName) ?></span>
                                        <span class="text-muted">(<?= htmlspecialchars($receivedByRole) ?>)</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-4">
                            <label for="notes" class="form-label">Receiving Notes</label>
                            <textarea id="notes" name="notes" class="form-control-modern" rows="2"
                                      placeholder="Add any notes about this receipt..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>

                        <!-- Items to Receive Section -->
                        <h5 class="section-title mt-5 mb-3">Items to Receive</h5>
                        <div class="gr-table-wrapper">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th class="col-gr-product">PRODUCT</th>
                                        <th class="col-gr-ordered text-center">ORDERED</th>
                                        <th class="col-gr-receiving text-center">RECEIVING QTY</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($po_items as $item): ?>
                                    <tr class="gr-item-row" data-item-id="<?= $item['id'] ?>">
                                        <!-- Product Info -->
                                        <td class="col-gr-product">
                                            <div class="gr-product-cell">
                                                <div class="product-sku"><?= htmlspecialchars($item['sku']) ?></div>
                                                <div class="product-name"><?= htmlspecialchars($item['product_name']) ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Ordered Quantity -->
                                        <td class="col-gr-ordered text-center">
                                            <span class="qty-badge"><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?></span>
                                        </td>

                                        <!-- Receiving Quantity Input -->
                                        <td class="col-gr-receiving">
                                            <input type="number" name="items[<?= $item['id'] ?>][received_quantity]"
                                                   class="form-control-modern gr-qty-input" 
                                                    min="0" max="<?= $item['remaining_quantity'] ?>"
                                                   step="0.01" placeholder="0" <?= !$isReceivableStatus ? 'disabled' : '' ?>>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($serialTrackingExists): ?>
                        <div class="card-modern mt-4" id="serial-scan-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                                    <div>
                                        <h5 class="section-title mb-1">Serial Number Scanning</h5>
                                        <div class="text-muted small" id="serial-scan-summary">Enter a receiving quantity to start scanning serial numbers.</div>
                                    </div>
                                    <span class="badge-modern badge-info" id="serial-scan-progress">0 / 0 scanned</span>
                                </div>

                                <div id="serial-scan-empty" class="text-muted small">No serial-tracked quantities queued yet.</div>

                                <div id="serial-scan-workspace" style="display:none;">
                                    <div class="mb-2">
                                        <div class="fw-600" id="serial-scan-product">Select an item</div>
                                        <div class="text-muted small" id="serial-scan-step">Serial 1 of 1</div>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
                                        <input type="text" id="serial-scan-input" class="form-control-modern" style="max-width:420px;" placeholder="Scan or enter serial number" autocomplete="off" spellcheck="false" <?= !$isReceivableStatus ? 'disabled' : '' ?>>
                                        <button type="button" class="btn btn-outline-secondary" id="serial-scan-back" <?= !$isReceivableStatus ? 'disabled' : '' ?>>Back</button>
                                        <button type="button" class="btn btn-primary" id="serial-scan-next" <?= !$isReceivableStatus ? 'disabled' : '' ?>>Next</button>
                                    </div>
                                    <div class="text-muted small mb-3">Press Enter after each scan. Quantities must match the number of captured serials.</div>
                                    <div id="serial-scan-error" class="alert alert-danger py-2 px-3 mb-3" style="display:none;"></div>
                                    <div id="serial-scan-list" class="d-grid gap-2"></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="action-buttons-container mt-5">
                            <button type="submit" class="btn btn-primary btn-lg" id="complete-receipt-btn" <?= !$isReceivableStatus ? 'disabled' : '' ?>>
                                <i class="fas fa-check-circle"></i> Complete Receipt
                            </button>
                            <a href="purchase_orders.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Receiving quantity input focus & blur effects
    const qtyInputs = document.querySelectorAll('.gr-qty-input');
    qtyInputs.forEach(function(input) {
        input.addEventListener('focus', function() {
            this.parentElement.style.boxShadow = '0 0 0 3px var(--primary-100)';
        });
        input.addEventListener('blur', function() {
            this.parentElement.style.boxShadow = '';
        });
    });

    // Table row hover effects
    const rows = document.querySelectorAll('.gr-item-row');
    rows.forEach(function(row) {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'var(--primary-50)';
        });
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });

    const form = document.querySelector('form');
    const serialTrackingEnabled = <?= $serialTrackingExists ? 'true' : 'false' ?>;
    const serialPostedState = <?= json_encode($_POST['serials'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const serialItemMeta = <?= json_encode(array_map(static function ($item) {
        return [
            'id' => (int)$item['id'],
            'sku' => (string)$item['sku'],
            'name' => (string)$item['product_name']
        ];
    }, $po_items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const serialMetaById = {};
    serialItemMeta.forEach(function(item) {
        serialMetaById[String(item.id)] = item;
    });

    let serialRequirements = [];
    let serialState = {};
    let activeRequirementIndex = 0;
    let activeStepIndex = 0;

    function getSerialElements() {
        return {
            card: document.getElementById('serial-scan-card'),
            empty: document.getElementById('serial-scan-empty'),
            workspace: document.getElementById('serial-scan-workspace'),
            summary: document.getElementById('serial-scan-summary'),
            progress: document.getElementById('serial-scan-progress'),
            product: document.getElementById('serial-scan-product'),
            step: document.getElementById('serial-scan-step'),
            input: document.getElementById('serial-scan-input'),
            back: document.getElementById('serial-scan-back'),
            next: document.getElementById('serial-scan-next'),
            error: document.getElementById('serial-scan-error'),
            list: document.getElementById('serial-scan-list')
        };
    }

    function getTotalRequiredSerials() {
        return serialRequirements.reduce(function(total, item) {
            return total + item.qty;
        }, 0);
    }

    function getCapturedSerialCount() {
        return serialRequirements.reduce(function(total, item) {
            const chosen = serialState[String(item.itemId)] || [];
            return total + chosen.filter(Boolean).length;
        }, 0);
    }

    function setSerialError(message) {
        const { error } = getSerialElements();
        if (!error) return;
        error.textContent = message || '';
        error.style.display = message ? 'block' : 'none';
    }

    function syncSerialHiddenInputs() {
        if (!form) return;
        form.querySelectorAll('input[data-serial-hidden="1"]').forEach(function(input) {
            input.remove();
        });

        Object.keys(serialState).forEach(function(itemId) {
            (serialState[itemId] || []).filter(Boolean).forEach(function(serial) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = `serials[${itemId}][]`;
                hidden.value = serial;
                hidden.setAttribute('data-serial-hidden', '1');
                form.appendChild(hidden);
            });
        });
    }

    function findFirstIncompletePosition() {
        for (let i = 0; i < serialRequirements.length; i += 1) {
            const requirement = serialRequirements[i];
            const chosen = serialState[String(requirement.itemId)] || [];
            for (let s = 0; s < requirement.qty; s += 1) {
                if (!chosen[s]) {
                    return { requirementIndex: i, stepIndex: s };
                }
            }
        }
        if (!serialRequirements.length) {
            return { requirementIndex: 0, stepIndex: 0 };
        }
        return {
            requirementIndex: serialRequirements.length - 1,
            stepIndex: Math.max(0, serialRequirements[serialRequirements.length - 1].qty - 1)
        };
    }

    function moveSerialCursor(delta) {
        let reqIndex = activeRequirementIndex;
        let stepIndex = activeStepIndex + delta;
        while (reqIndex >= 0 && reqIndex < serialRequirements.length) {
            const qty = serialRequirements[reqIndex].qty;
            if (stepIndex >= 0 && stepIndex < qty) {
                activeRequirementIndex = reqIndex;
                activeStepIndex = stepIndex;
                return true;
            }
            if (stepIndex < 0) {
                reqIndex -= 1;
                if (reqIndex < 0) break;
                stepIndex = serialRequirements[reqIndex].qty - 1;
            } else {
                reqIndex += 1;
                stepIndex = 0;
            }
        }
        return false;
    }

    function buildSerialRequirements() {
        const nextRequirements = [];
        qtyInputs.forEach(function(input) {
            const qty = parseFloat(input.value) || 0;
            if (qty <= 0) return;
            if (!Number.isInteger(qty)) return;
            const row = input.closest('.gr-item-row');
            const itemId = row ? row.getAttribute('data-item-id') : '';
            if (!itemId) return;
            const meta = serialMetaById[String(itemId)] || {};
            nextRequirements.push({
                itemId: String(itemId),
                qty: qty,
                sku: meta.sku || '',
                name: meta.name || ''
            });
        });
        serialRequirements = nextRequirements;

        const nextState = {};
        serialRequirements.forEach(function(requirement) {
            const existing = Array.isArray(serialState[requirement.itemId]) ? serialState[requirement.itemId] : [];
            nextState[requirement.itemId] = existing.slice(0, requirement.qty);
        });
        serialState = nextState;

        const nextPos = findFirstIncompletePosition();
        activeRequirementIndex = nextPos.requirementIndex;
        activeStepIndex = nextPos.stepIndex;
        syncSerialHiddenInputs();
        renderSerialScanner();
    }

    function renderSerialList(requirement) {
        const { list } = getSerialElements();
        if (!list || !requirement) return;
        const chosen = serialState[String(requirement.itemId)] || [];
        if (!chosen.filter(Boolean).length) {
            list.innerHTML = '<div class="text-muted small">No serial numbers captured for this item yet.</div>';
            return;
        }
        list.innerHTML = chosen
            .filter(Boolean)
            .map(function(serial, index) {
                return `<div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 bg-white">
                    <span class="text-muted small">Serial ${index + 1}</span>
                    <span class="fw-600">${serial}</span>
                </div>`;
            })
            .join('');
    }

    function renderSerialScanner() {
        const elements = getSerialElements();
        if (!serialTrackingEnabled || !elements.card) return;

        const totalRequired = getTotalRequiredSerials();
        const captured = getCapturedSerialCount();
        elements.progress.textContent = `${captured} / ${totalRequired} scanned`;

        if (!serialRequirements.length) {
            elements.empty.style.display = '';
            elements.workspace.style.display = 'none';
            elements.summary.textContent = 'Enter a receiving quantity to start scanning serial numbers.';
            setSerialError('');
            return;
        }

        elements.empty.style.display = 'none';
        elements.workspace.style.display = '';

        const requirement = serialRequirements[activeRequirementIndex];
        const chosen = serialState[String(requirement.itemId)] || [];
        const currentValue = chosen[activeStepIndex] || '';
        elements.summary.textContent = captured === totalRequired
            ? 'All required serial numbers are captured.'
            : 'Scan serial numbers one by one based on the receiving quantities.';
        elements.product.textContent = [requirement.sku, requirement.name].filter(Boolean).join(' • ');
        elements.step.textContent = `Serial ${activeStepIndex + 1} of ${requirement.qty}`;
        elements.input.value = currentValue;
        elements.back.disabled = activeRequirementIndex === 0 && activeStepIndex === 0;
        elements.next.textContent = (activeRequirementIndex === serialRequirements.length - 1 && activeStepIndex === requirement.qty - 1)
            ? 'Finish'
            : 'Next';
        renderSerialList(requirement);

        if (!elements.input.disabled) {
            window.setTimeout(function() {
                elements.input.focus();
                elements.input.select();
            }, 0);
        }
    }

    function isDuplicateSerial(itemId, value) {
        const normalized = value.trim().toLowerCase();
        return (serialState[String(itemId)] || []).some(function(serial, index) {
            return index !== activeStepIndex && serial && serial.trim().toLowerCase() === normalized;
        });
    }

    function handleSerialNext() {
        const elements = getSerialElements();
        const requirement = serialRequirements[activeRequirementIndex];
        if (!elements.input || !requirement) return;

        const value = elements.input.value.trim();
        if (!value) {
            setSerialError('Scan or enter a serial number first.');
            return;
        }
        if (isDuplicateSerial(requirement.itemId, value)) {
            setSerialError('Duplicate serial number detected for this item.');
            return;
        }

        const chosen = serialState[String(requirement.itemId)] || [];
        chosen[activeStepIndex] = value;
        serialState[String(requirement.itemId)] = chosen;
        syncSerialHiddenInputs();
        setSerialError('');

        if (moveSerialCursor(1)) {
            renderSerialScanner();
            return;
        }

        renderSerialScanner();
    }

    function initializeSerialStateFromPost() {
        Object.keys(serialPostedState || {}).forEach(function(itemId) {
            const postedSerials = Array.isArray(serialPostedState[itemId]) ? serialPostedState[itemId] : [];
            serialState[String(itemId)] = postedSerials
                .map(function(value) { return String(value || '').trim(); })
                .filter(function(value) { return value !== ''; });
        });
        syncSerialHiddenInputs();
    }

    initializeSerialStateFromPost();
    buildSerialRequirements();

    qtyInputs.forEach(function(input) {
        input.addEventListener('input', buildSerialRequirements);
    });

    const serialElements = getSerialElements();
    if (serialElements.input) {
        serialElements.input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                handleSerialNext();
            }
        });
    }
    if (serialElements.back) {
        serialElements.back.addEventListener('click', function() {
            if (moveSerialCursor(-1)) {
                setSerialError('');
                renderSerialScanner();
            }
        });
    }
    if (serialElements.next) {
        serialElements.next.addEventListener('click', handleSerialNext);
    }

    // Form submission confirmation
    if (form) {
        form.addEventListener('submit', function(e) {
            let hasValues = false;
            qtyInputs.forEach(function(input) {
                if (parseFloat(input.value) > 0) {
                    hasValues = true;
                }
            });

            if (!hasValues) {
                e.preventDefault();
                alert('Please enter at least one receiving quantity.');
                return false;
            }

            if (serialTrackingEnabled) {
                buildSerialRequirements();
                syncSerialHiddenInputs();

                for (const requirement of serialRequirements) {
                    if (!Number.isInteger(requirement.qty)) {
                        e.preventDefault();
                        alert('Serial tracking requires whole-number quantities.');
                        return false;
                    }
                    const chosen = serialState[String(requirement.itemId)] || [];
                    if (chosen.filter(Boolean).length !== requirement.qty) {
                        const nextPos = findFirstIncompletePosition();
                        activeRequirementIndex = nextPos.requirementIndex;
                        activeStepIndex = nextPos.stepIndex;
                        renderSerialScanner();
                        e.preventDefault();
                        alert(`Please scan exactly ${requirement.qty} serial number(s) for ${[requirement.sku, requirement.name].filter(Boolean).join(' • ')}.`);
                        return false;
                    }
                    const normalized = chosen.map(function(v) { return v.toLowerCase(); });
                    if (new Set(normalized).size !== chosen.length) {
                        e.preventDefault();
                        alert(`Duplicate serial numbers detected for ${[requirement.sku, requirement.name].filter(Boolean).join(' • ')}.`);
                        return false;
                    }
                }
            }
        });
    }

    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert:not(.alert-dismissible)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (alert.parentElement) {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.3s ease-in-out';
                setTimeout(function() {
                    alert.remove();
                }, 300);
            }
        }, 5000);
    });

    // Mobile responsive table adjustments
    function adjustTableForMobile() {
        if (window.innerWidth < 768) {
            document.querySelectorAll('.col-gr-ordered').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.col-gr-prev-received').forEach(el => el.style.display = 'none');
        }
    }
    adjustTableForMobile();
    window.addEventListener('resize', adjustTableForMobile);
});
</script>

<?php
function get_status_badge_class($status) {
    $classes = [
        'draft' => 'secondary',
        'approved' => 'success',
        'sent' => 'info',
        'will_be_delivered' => 'info',
        'confirmed' => 'primary',
        'partially_received' => 'warning',
        'rejected' => 'danger',
        'completed' => 'success',
        'cancelled' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}
?>

<?php
include 'templates/footer.php';


