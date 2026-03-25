<?php
/**
 * Stock Transfer Page
 * Transfer feature has been disabled
 */

require_once 'includes/init.php';
requireLogin();

$pageTitle = 'Stock Transfers - Feature Disabled';

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Stock Transfers</h1>
            <a href="<?php echo getBaseUrl(); ?>/stock_tracking.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Stock Tracking
            </a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-x-circle text-warning" style="font-size: 4rem;"></i>
                <h3 class="mt-3">Stock Transfer Feature Disabled</h3>
                <p class="text-muted">The stock transfer feature has been disabled as per system configuration.</p>
                <p class="text-muted">Please use stock adjustments for any inventory corrections.</p>
                <a href="<?php echo getBaseUrl(); ?>/stock_adjustment.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Go to Stock Adjustments
                </a>
            </div>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
            
            $transferId = $stockModule->createStockTransfer($data);
            setFlashMessage('success', 'Stock transfer created successfully.');
            redirect(getBaseUrl() . "/transfer.php?view={$transferId}");
        } elseif ($action === 'complete' && $id) {
            $stockModule->completeStockTransfer($id);
            setFlashMessage('success', 'Stock transfer completed.');
            redirect(getBaseUrl() . '/transfer.php');
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get locations
$locations = $stockModule->getLocations();

// Get transfers
$transfers = $db->fetchAll(
    "SELECT st.*, l1.name as from_location, l2.name as to_location, u.username as created_by_name 
     FROM stock_transfers st 
     INNER JOIN stock_locations l1 ON st.from_location_id = l1.id 
     INNER JOIN stock_locations l2 ON st.to_location_id = l2.id 
     LEFT JOIN users u ON st.created_by = u.id 
     ORDER BY st.created_at DESC 
     LIMIT 50"
);

include 'templates/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Stock Transfers</h1>
            <a href="<?php echo getBaseUrl(); ?>/transfer.php?action=add" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> New Transfer
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($transfers)): ?>
            <p class="text-muted text-center mb-0">No transfers found</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Transfer #</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $transfer): ?>
                        <tr>
                            <td><a href="<?php echo getBaseUrl(); ?>/transfer.php?view=<?php echo $transfer['id']; ?>"><?php echo escape($transfer['transfer_number']); ?></a></td>
                            <td><?php echo escape($transfer['from_location']); ?></td>
                            <td><?php echo escape($transfer['to_location']); ?></td>
                            <td><?php echo formatDate($transfer['transfer_date']); ?></td>
                            <td><?php echo getStatusBadge($transfer['status']); ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/transfer.php?view=<?php echo $transfer['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($transfer['status'] === 'pending' || $transfer['status'] === 'in_transit'): ?>
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Complete this transfer?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="complete">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-check"></i> Complete
                                    </button>
                                </form>
                                <?php endif; ?>
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
        <h1 class="h3 mb-0">New Stock Transfer</h1>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label for="from_location_id" class="form-label">From Location <span class="text-danger">*</span></label>
                            <select class="form-select" id="from_location_id" name="from_location_id" required>
                                <option value="">Select Location</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>"><?php echo escape($loc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="to_location_id" class="form-label">To Location <span class="text-danger">*</span></label>
                            <select class="form-select" id="to_location_id" name="to_location_id" required>
                                <option value="">Select Location</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>"><?php echo escape($loc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="transfer_date" class="form-label">Transfer Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="transfer_date" name="transfer_date" required value="<?php echo date(DATE_FORMAT); ?>">
                        </div>
                    </div>
                    
                    <!-- Items -->
                    <div class="mb-4">
                        <h5>Items to Transfer</h5>
                        <div id="itemsContainer">
                            <div class="item-row border p-3 mb-3">
                                <div class="row">
                                    <div class="col-md-5 mb-3">
                                        <label class="form-label">Product</label>
                                        <select class="form-select product-select" name="items[0][product_id]" required>
                                            <option value="">Select Product</option>
                                            <?php
                                            $products = $productsModule->getProducts([], 1000, 0);
                                            foreach ($products as $prod):
                                            ?>
                                            <option value="<?php echo $prod['id']; ?>"><?php echo escape($prod['name']); ?> (<?php echo escape($prod['sku']); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" class="form-control" name="items[0][quantity]" required min="1">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Serial Numbers (comma-separated)</label>
                                        <input type="text" class="form-control" name="items[0][serial_numbers]" placeholder="SN1, SN2, SN3">
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
                        <a href="<?php echo getBaseUrl(); ?>/transfer.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Transfer</button>
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
            <div class="col-md-5 mb-3">
                <label class="form-label">Product</label>
                <select class="form-select product-select" name="items[${itemIndex}][product_id]" required>
                    <option value="">Select Product</option>
                    <?php foreach ($products as $prod): ?>
                    <option value="<?php echo $prod['id']; ?>"><?php echo escape($prod['name']); ?> (<?php echo escape($prod['sku']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" name="items[${itemIndex}][quantity]" required min="1">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Serial Numbers</label>
                <input type="text" class="form-control" name="items[${itemIndex}][serial_numbers]" placeholder="SN1, SN2, SN3">
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

<?php endif; ?>

<?php include 'templates/footer.php'; ?>

