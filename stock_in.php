<?php
/**
 * Stock-In (Receiving) Page
 * Receive stock from purchase orders or suppliers
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.view');

$pageTitle = 'Stock Receiving';
$stockModule = new StockManagementModule();
$purchaseOrdersModule = new PurchaseOrdersModule();
$suppliersModule = new SuppliersModule();
$productsModule = new ProductsModule();
$db = getDB();

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$poId = $_GET['po_id'] ?? null;
$viewId = $_GET['view'] ?? null;

if ($viewId !== null && $id === null) {
    $id = (int)$viewId;
}
if ($id && !isset($_GET['action'])) {
    $action = 'view';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    try {
        if ($action === 'add') {
            $data = [
                'purchase_order_id' => !empty($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : null,
                'supplier_id' => (int)$_POST['supplier_id'],
                'receiving_date' => $_POST['receiving_date'] ?? date(DATE_FORMAT),
                'invoice_number' => sanitize($_POST['invoice_number'] ?? ''),
                'invoice_date' => !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : null,
                'payment_status' => $_POST['payment_status'] ?? 'unpaid',
                'notes' => sanitize($_POST['notes'] ?? ''),
                'items' => []
            ];
            
            // Process items
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['cost_per_unit'])) {
                        $data['items'][] = [
                            'product_id' => (int)$item['product_id'],
                            'quantity' => (int)$item['quantity'],
                            'cost_per_unit' => (float)$item['cost_per_unit'],
                            'location' => sanitize($item['location'] ?? ''),
                            'batch_number' => sanitize($item['batch_number'] ?? ''),
                            'expiry_date' => !empty($item['expiry_date']) ? $item['expiry_date'] : null
                        ];
                    }
                }
            }
            
            if (empty($data['items'])) {
                throw new Exception('Please add at least one item');
            }
            
            $receivingId = $stockModule->createStockReceiving($data);
            setFlashMessage('success', 'Stock received successfully.');
            redirect(getBaseUrl() . "/stock_in.php?view={$receivingId}");
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get data
$receiving = null;
$receivingItems = [];
if ($id) {
    try {
        try {
            $receiving = $db->fetchOne(
                "SELECT sr.*, s.name as supplier_name, u.username as created_by_name
                 FROM stock_receiving sr
                 INNER JOIN suppliers s ON sr.supplier_id = s.id
                 LEFT JOIN users u ON sr.created_by = u.id
                 WHERE sr.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            try {
                $receiving = $db->fetchOne(
                    "SELECT sr.*, COALESCE(s.name, s.company_name) as supplier_name, '' as created_by_name
                     FROM stock_receiving sr
                     LEFT JOIN suppliers s ON sr.supplier_id = s.id
                     WHERE sr.id = ?",
                    [$id]
                );
            } catch (Exception $e2) {
                $receiving = $db->fetchOne(
                    "SELECT sr.*, CONCAT('Supplier #', sr.supplier_id) as supplier_name, '' as created_by_name
                     FROM stock_receiving sr
                     WHERE sr.id = ?",
                    [$id]
                );
            }
        }
    } catch (Exception $e) {
        $receiving = null;
        $receivingItems = [];
        setFlashMessage('error', 'Stock receiving tables are not available. Please run stock management schema updates.');
    }
    
    if ($receiving) {
        try {
            $receivingItems = $db->fetchAll(
                "SELECT sri.*, p.name as product_name, p.sku
                 FROM stock_receiving_items sri
                 INNER JOIN products p ON sri.product_id = p.id
                 WHERE sri.receiving_id = ?",
                [$id]
            );
        } catch (Exception $e) {
            $receivingItems = $db->fetchAll(
                "SELECT sri.*, p.name as product_name, '' as sku
                 FROM stock_receiving_items sri
                 LEFT JOIN products p ON sri.product_id = p.id
                 WHERE sri.receiving_id = ?",
                [$id]
            );
        }
    }
}

// Get options
$suppliers = $suppliersModule->getSuppliers(['search' => ''], 1000, 0);
$locations = $stockModule->getLocations();

// Get pending POs if creating from PO
$pendingPOs = [];
if ($poId || $action === 'add') {
    $pendingPOs = $purchaseOrdersModule->getPurchaseOrders(['status' => 'confirmed'], 1000, 0);
}

include 'templates/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Stock Receiving</h1>
            <?php if (hasPermission('inventory.adjust')): ?>
            <a href="<?php echo getBaseUrl(); ?>/stock_in.php?action=add" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Receive Stock
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Receivings -->
<div class="card">
    <div class="card-body">
        <?php
        try {
            try {
                $receivings = $db->fetchAll(
                    "SELECT sr.*, s.name as supplier_name, u.username as created_by_name
                     FROM stock_receiving sr
                     INNER JOIN suppliers s ON sr.supplier_id = s.id
                     LEFT JOIN users u ON sr.created_by = u.id
                     ORDER BY sr.created_at DESC
                     LIMIT 50"
                );
            } catch (Exception $e) {
                try {
                    $receivings = $db->fetchAll(
                        "SELECT sr.*, COALESCE(s.name, s.company_name) as supplier_name, '' as created_by_name
                         FROM stock_receiving sr
                         LEFT JOIN suppliers s ON sr.supplier_id = s.id
                         ORDER BY sr.id DESC
                         LIMIT 50"
                    );
                } catch (Exception $e2) {
                    $receivings = $db->fetchAll(
                        "SELECT sr.*, CONCAT('Supplier #', sr.supplier_id) as supplier_name, '' as created_by_name
                         FROM stock_receiving sr
                         ORDER BY sr.id DESC
                         LIMIT 50"
                    );
                }
            }
        } catch (Exception $e) {
            $receivings = [];
            setFlashMessage('error', 'Stock receiving tables are not available. Please run stock management schema updates.');
        }
        ?>
        
        <?php if (empty($receivings)): ?>
            <p class="text-muted text-center mb-0">No stock receivings found</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Receiving #</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>PO #</th>
                            <th>Invoice #</th>
                            <th>Amount</th>
                            <th>Payment Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receivings as $rec): ?>
                        <tr>
                            <td><a href="<?php echo getBaseUrl(); ?>/stock_in.php?view=<?php echo $rec['id']; ?>"><?php echo escape($rec['receiving_number']); ?></a></td>
                            <td><?php echo formatDate($rec['receiving_date']); ?></td>
                            <td><?php echo escape($rec['supplier_name']); ?></td>
                            <td><?php echo $rec['purchase_order_id'] ? 'PO-' . $rec['purchase_order_id'] : '-'; ?></td>
                            <td><?php echo escape($rec['invoice_number'] ?? '-'); ?></td>
                            <td><?php echo formatCurrency($rec['total_amount']); ?></td>
                            <td><?php echo getStatusBadge($rec['payment_status']); ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/stock_in.php?view=<?php echo $rec['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'add'): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Receive Stock</h1>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="" id="receivingForm">
                    <?php echo csrfField(); ?>
                    
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supp): ?>
                                <option value="<?php echo $supp['id']; ?>"><?php echo escape($supp['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="purchase_order_id" class="form-label">Purchase Order (Optional)</label>
                            <select class="form-select" id="purchase_order_id" name="purchase_order_id">
                                <option value="">None</option>
                                <?php foreach ($pendingPOs as $po): ?>
                                <option value="<?php echo $po['id']; ?>"><?php echo escape($po['po_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="receiving_date" class="form-label">Receiving Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="receiving_date" name="receiving_date" required value="<?php echo date(DATE_FORMAT); ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label for="invoice_number" class="form-label">Invoice Number</label>
                            <input type="text" class="form-control" id="invoice_number" name="invoice_number">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="invoice_date" class="form-label">Invoice Date</label>
                            <input type="date" class="form-control" id="invoice_date" name="invoice_date">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="payment_status" class="form-label">Payment Status</label>
                            <select class="form-select" id="payment_status" name="payment_status">
                                <option value="unpaid">Unpaid</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Items -->
                    <div class="mb-4">
                        <h5>Items</h5>
                        <div id="itemsContainer">
                            <div class="item-row border p-3 mb-3">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Product</label>
                                        <select class="form-select product-select" name="items[0][product_id]" required>
                                            <option value="">Select Product</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" class="form-control" name="items[0][quantity]" required min="1">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Cost/Unit</label>
                                        <input type="number" step="0.01" class="form-control" name="items[0][cost_per_unit]" required min="0">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Location</label>
                                        <select class="form-select" name="items[0][location]">
                                            <option value="">Default</option>
                                            <?php foreach ($locations as $loc): ?>
                                            <option value="<?php echo escape($loc['code']); ?>"><?php echo escape($loc['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Batch #</label>
                                        <input type="text" class="form-control" name="items[0][batch_number]">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary" onclick="addItemRow()">
                            <i class="bi bi-plus"></i> Add Item
                        </button>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo getBaseUrl(); ?>/stock_in.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Receive Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let itemIndex = 1;

function addItemRow() {
    const container = document.getElementById('itemsContainer');
    const newRow = document.createElement('div');
    newRow.className = 'item-row border p-3 mb-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Product</label>
                <select class="form-select product-select" name="items[${itemIndex}][product_id]" required>
                    <option value="">Select Product</option>
                </select>
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" name="items[${itemIndex}][quantity]" required min="1">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">Cost/Unit</label>
                <input type="number" step="0.01" class="form-control" name="items[${itemIndex}][cost_per_unit]" required min="0">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">Location</label>
                <select class="form-select" name="items[${itemIndex}][location]">
                    <option value="">Default</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo escape($loc['code']); ?>"><?php echo escape($loc['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">Batch #</label>
                <input type="text" class="form-control" name="items[${itemIndex}][batch_number]">
                <button type="button" class="btn btn-sm btn-danger mt-2" onclick="this.closest('.item-row').remove()">
                    <i class="bi bi-trash"></i> Remove
                </button>
            </div>
        </div>
    `;
    container.appendChild(newRow);
    itemIndex++;
}
</script>

<?php elseif ($action === 'view' && $receiving): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Receiving: <?php echo escape($receiving['receiving_number']); ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Receiving Details</div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th width="200">Receiving Number</th>
                        <td><?php echo escape($receiving['receiving_number']); ?></td>
                    </tr>
                    <tr>
                        <th>Date</th>
                        <td><?php echo formatDate($receiving['receiving_date']); ?></td>
                    </tr>
                    <tr>
                        <th>Supplier</th>
                        <td><?php echo escape($receiving['supplier_name']); ?></td>
                    </tr>
                    <tr>
                        <th>Invoice Number</th>
                        <td><?php echo escape($receiving['invoice_number'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Total Amount</th>
                        <td><strong><?php echo formatCurrency($receiving['total_amount']); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Payment Status</th>
                        <td><?php echo getStatusBadge($receiving['payment_status']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">Items Received</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Cost/Unit</th>
                                <th>Total</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receivingItems as $item): ?>
                            <tr>
                                <td><?php echo escape($item['product_name']); ?> (<?php echo escape($item['sku']); ?>)</td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td><?php echo formatCurrency($item['cost_per_unit']); ?></td>
                                <td><?php echo formatCurrency($item['total_cost']); ?></td>
                                <td><?php echo escape($item['location'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>

