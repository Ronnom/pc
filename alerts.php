<?php
/**
 * Stock Alerts Page
 * Low stock alerts and notifications
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.view');

$pageTitle = 'Stock Alerts';
$stockModule = new StockManagementModule();
$db = getDB();

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    try {
        if ($action === 'snooze' && $id) {
            $snoozeUntil = $_POST['snooze_until'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
            $reason = sanitize($_POST['snooze_reason'] ?? '');
            
            $db->update('stock_alerts', [
                'status' => 'snoozed',
                'snoozed_until' => $snoozeUntil,
                'snooze_reason' => $reason
            ], 'id = ?', [$id]);
            
            setFlashMessage('success', 'Alert snoozed successfully.');
        } elseif ($action === 'dismiss' && $id) {
            $reason = sanitize($_POST['dismiss_reason'] ?? '');
            
            $db->update('stock_alerts', [
                'status' => 'dismissed',
                'dismissed_by' => getCurrentUserId(),
                'dismissed_at' => date(DATETIME_FORMAT),
                'dismiss_reason' => $reason
            ], 'id = ?', [$id]);
            
            setFlashMessage('success', 'Alert dismissed successfully.');
        } elseif ($action === 'resolve' && $id) {
            $db->update('stock_alerts', [
                'status' => 'resolved'
            ], 'id = ?', [$id]);
            
            setFlashMessage('success', 'Alert marked as resolved.');
        }
        
        redirect(getBaseUrl() . '/alerts.php');
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get alerts
$statusFilter = $_GET['status'] ?? 'active';
$alerts = $stockModule->getLowStockAlerts($statusFilter);

// Count active alerts
$activeCount = count($stockModule->getLowStockAlerts('active'));

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">
                Stock Alerts
                <?php if ($activeCount > 0): ?>
                <span class="badge bg-danger"><?php echo $activeCount; ?> Active</span>
                <?php endif; ?>
            </h1>
            <div class="btn-group" role="group">
                <a href="?status=active" class="btn btn-outline-danger <?php echo $statusFilter === 'active' ? 'active' : ''; ?>">Active</a>
                <a href="?status=snoozed" class="btn btn-outline-warning <?php echo $statusFilter === 'snoozed' ? 'active' : ''; ?>">Snoozed</a>
                <a href="?status=dismissed" class="btn btn-outline-secondary <?php echo $statusFilter === 'dismissed' ? 'active' : ''; ?>">Dismissed</a>
                <a href="?status=resolved" class="btn btn-outline-success <?php echo $statusFilter === 'resolved' ? 'active' : ''; ?>">Resolved</a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <?php if (empty($alerts)): ?>
                    <p class="text-muted text-center mb-0">No alerts found</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Alert Type</th>
                                    <th>Threshold</th>
                                    <th>Current Stock</th>
                                    <th>Difference</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alerts as $alert): ?>
                                <tr class="<?php echo $alert['status'] === 'active' ? 'table-warning' : ''; ?>">
                                    <td>
                                        <strong><?php echo escape($alert['product_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo escape($alert['sku']); ?></small>
                                    </td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $alert['alert_type'])); ?></td>
                                    <td><?php echo $alert['threshold_quantity']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $alert['current_quantity'] == 0 ? 'danger' : ($alert['current_quantity'] <= $alert['threshold_quantity'] ? 'warning' : 'success'); ?>">
                                            <?php echo $alert['current_quantity']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $diff = $alert['threshold_quantity'] - $alert['current_quantity'];
                                        echo $diff > 0 ? "<span class='text-danger'>-{$diff}</span>" : "<span class='text-success'>+{$diff}</span>";
                                        ?>
                                    </td>
                                    <td><?php echo getStatusBadge($alert['status']); ?></td>
                                    <td><?php echo formatDateTime($alert['created_at']); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?php echo getBaseUrl(); ?>/products.php?view=<?php echo $alert['product_id']; ?>" class="btn btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($alert['status'] === 'active'): ?>
                                            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#snoozeModal<?php echo $alert['id']; ?>">
                                                <i class="bi bi-clock"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#dismissModal<?php echo $alert['id']; ?>">
                                                <i class="bi bi-x"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Snooze Modal -->
                                <div class="modal fade" id="snoozeModal<?php echo $alert['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="snooze">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Snooze Alert</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label for="snooze_until" class="form-label">Snooze Until</label>
                                                        <input type="datetime-local" class="form-control" id="snooze_until" name="snooze_until" value="<?php echo date('Y-m-d\TH:i', strtotime('+7 days')); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="snooze_reason" class="form-label">Reason</label>
                                                        <textarea class="form-control" id="snooze_reason" name="snooze_reason" rows="2"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-warning">Snooze</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Dismiss Modal -->
                                <div class="modal fade" id="dismissModal<?php echo $alert['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="dismiss">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Dismiss Alert</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label for="dismiss_reason" class="form-label">Reason</label>
                                                        <textarea class="form-control" id="dismiss_reason" name="dismiss_reason" rows="2"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Dismiss</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

