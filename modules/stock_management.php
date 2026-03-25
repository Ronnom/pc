<?php
/**
 * Stock Management Module
 * Comprehensive stock management functionality
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class StockManagementModule {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }

    private function getThresholdColumn() {
        if ($this->tableHasColumn('products', 'min_stock_level')) {
            return 'min_stock_level';
        }
        if ($this->tableHasColumn('products', 'reorder_level')) {
            return 'reorder_level';
        }
        return null;
    }

    /**
     * Check if table exists in current schema
     */
    private function tableExists($tableName) {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$tableName]
        );
        return (int)($row['cnt'] ?? 0) > 0;
    }

    /**
     * Check if column exists on a table
     */
    private function tableHasColumn($tableName, $columnName) {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$tableName, $columnName]
        );
        return (int)($row['cnt'] ?? 0) > 0;
    }
    
    /**
     * Generate receiving number
     */
    public function generateReceivingNumber() {
        return 'REC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }
    
    /**
     * Generate transfer number
     */
    public function generateTransferNumber() {
        return 'TRF-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }
    
    /**
     * Generate audit number
     */
    public function generateAuditNumber() {
        return 'AUD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }
    
    /**
     * Create stock receiving
     */
    public function createStockReceiving($data) {
        $this->db->beginTransaction();
        
        try {
            // Create receiving record
            $receivingId = $this->db->insert('stock_receiving', [
                'receiving_number' => $this->generateReceivingNumber(),
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'receiving_date' => $data['receiving_date'] ?? date(DATE_FORMAT),
                'invoice_number' => $data['invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'total_amount' => 0, // Will be calculated
                'payment_status' => $data['payment_status'] ?? 'unpaid',
                'notes' => $data['notes'] ?? null,
                'created_by' => getCurrentUserId()
            ]);
            
            $totalAmount = 0;
            $productsModule = new ProductsModule();
            
            // Process receiving items
            foreach ($data['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['cost_per_unit'];
                $totalAmount += $itemTotal;
                
                // Insert receiving item
                $this->db->insert('stock_receiving_items', [
                    'receiving_id' => $receivingId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'cost_per_unit' => $item['cost_per_unit'],
                    'total_cost' => $itemTotal,
                    'location' => $item['location'] ?? null,
                    'batch_number' => $item['batch_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null
                ]);
                
                // Update stock
                $productsModule->updateStock(
                    $item['product_id'],
                    $item['quantity'],
                    'in',
                    'stock_receiving',
                    $receivingId,
                    "Stock received - {$data['receiving_number']}"
                );
                
                // Create cost layer for FIFO/LIFO
                $this->createCostLayer($item['product_id'], $receivingId, $item['quantity'], $item['cost_per_unit']);
            }
            
            // Update total amount
            $this->db->update('stock_receiving',
                ['total_amount' => $totalAmount],
                'id = ?',
                [$receivingId]
            );
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'create', 'stock', "Created stock receiving: {$receivingId}");
            
            return $receivingId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Create stock adjustment with approval check
     */
    public function createStockAdjustment($productId, $quantityAfter, $reason, $reasonCategory = 'other', $notes = null) {
        $productsModule = new ProductsModule();
        $product = $productsModule->getProduct($productId);
        
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        $quantityBefore = $product['stock_quantity'];
        $difference = $quantityAfter - $quantityBefore;
        
        if ($difference == 0) {
            throw new Exception('No adjustment needed');
        }
        
        // Calculate adjustment value
        $adjustmentValue = abs($difference) * $product['cost_price'];
        $stockValue = $product['stock_quantity'] * $product['cost_price'];
        
        // Check if approval required (>10% of stock value)
        $requiresApproval = false;
        if ($stockValue > 0) {
            $percentage = ($adjustmentValue / $stockValue) * 100;
            $requiresApproval = $percentage > 10;
        }
        
        $this->db->beginTransaction();
        
        try {
            // Create adjustment record
            $adjustmentId = $this->db->insert('stock_adjustments', [
                'adjustment_number' => generateAdjustmentNumber(),
                'product_id' => $productId,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'adjustment_type' => $difference > 0 ? 'increase' : 'decrease',
                'reason' => $reason,
                'reason_category' => $reasonCategory,
                'adjustment_value' => $adjustmentValue,
                'requires_approval' => $requiresApproval ? 1 : 0,
                'approval_status' => $requiresApproval ? 'pending' : 'approved',
                'notes' => $notes,
                'created_by' => getCurrentUserId()
            ]);
            
            // If approved or doesn't require approval, update stock
            if (!$requiresApproval || $requiresApproval && isset($_POST['auto_approve'])) {
                $productsModule->updateStock(
                    $productId,
                    abs($difference),
                    'adjustment',
                    'inventory_adjustment',
                    $adjustmentId,
                    $reason
                );
            }
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'create', 'stock', "Created stock adjustment for product: {$product['name']}");
            
            return $adjustmentId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Approve stock adjustment
     */
    public function approveAdjustment($adjustmentId, $approved = true, $rejectionReason = null) {
        $adjustment = $this->db->fetchOne(
            "SELECT * FROM stock_adjustments WHERE id = ?",
            [$adjustmentId]
        );
        
        if (!$adjustment) {
            throw new Exception('Adjustment not found');
        }
        
        if ($adjustment['approval_status'] !== 'pending') {
            throw new Exception('Adjustment already processed');
        }
        
        $this->db->beginTransaction();
        
        try {
            if ($approved) {
                // Update stock
                $productsModule = new ProductsModule();
                $difference = $adjustment['quantity_after'] - $adjustment['quantity_before'];
                
                $productsModule->updateStock(
                    $adjustment['product_id'],
                    abs($difference),
                    'adjustment',
                    'inventory_adjustment',
                    $adjustmentId,
                    $adjustment['reason']
                );
                
                // Update adjustment
                $this->db->update('stock_adjustments', [
                    'approval_status' => 'approved',
                    'approved_by' => getCurrentUserId(),
                    'approved_at' => date(DATETIME_FORMAT)
                ], 'id = ?', [$adjustmentId]);
            } else {
                $this->db->update('stock_adjustments', [
                    'approval_status' => 'rejected',
                    'rejection_reason' => $rejectionReason
                ], 'id = ?', [$adjustmentId]);
            }
            
            $this->db->commit();
            
            logUserActivity(
                getCurrentUserId(),
                $approved ? 'approve' : 'reject',
                'stock',
                ($approved ? 'Approved' : 'Rejected') . " adjustment: {$adjustmentId}"
            );
            
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get low stock alerts
     */
    public function getLowStockAlerts($status = 'active') {
        $where = "sa.status = ?";
        $params = [$status];
        $thresholdColumn = $this->getThresholdColumn();
        $thresholdSelect = $thresholdColumn ? "p.{$thresholdColumn} as min_stock_level" : "5 as min_stock_level";
        
        if ($status === 'active') {
            $where .= " AND (sa.snoozed_until IS NULL OR sa.snoozed_until < NOW())";
        }
        
        return $this->db->fetchAll(
            "SELECT sa.*, p.name as product_name, p.sku, p.stock_quantity, {$thresholdSelect}, p.cost_price
             FROM stock_alerts sa
             INNER JOIN products p ON sa.product_id = p.id
             WHERE {$where}
             ORDER BY sa.created_at DESC",
            $params
        );
    }
    
    /**
     * Create or update stock alert
     */
    public function createStockAlert($productId, $alertType = 'low_stock') {
        $product = $this->db->fetchOne("SELECT * FROM products WHERE id = ?", [$productId]);
        $thresholdColumn = $this->getThresholdColumn();
        
        if (!$product) {
            return false;
        }
        
        // Check if alert already exists
        $existing = $this->db->fetchOne(
            "SELECT * FROM stock_alerts WHERE product_id = ? AND alert_type = ? AND status = 'active'",
            [$productId, $alertType]
        );
        
        if ($existing) {
            // Update existing alert
            $this->db->update('stock_alerts', [
                'current_quantity' => $product['stock_quantity'],
                'updated_at' => date(DATETIME_FORMAT)
            ], 'id = ?', [$existing['id']]);
        } else {
            // Create new alert
            $this->db->insert('stock_alerts', [
                'product_id' => $productId,
                'alert_type' => $alertType,
                'threshold_quantity' => $thresholdColumn ? (int)$product[$thresholdColumn] : 5,
                'current_quantity' => $product['stock_quantity'],
                'status' => 'active'
            ]);
        }
        
        return true;
    }
    
    /**
     * Get reorder suggestions
     */
    public function getReorderSuggestions($days = 30) {
        $thresholdColumn = $this->getThresholdColumn();
        $thresholdExpr = $thresholdColumn ? "p.{$thresholdColumn}" : "5";

        return $this->db->fetchAll(
            "SELECT p.*, 
                    {$thresholdExpr} - p.stock_quantity as suggested_qty,
                    (SELECT SUM(quantity) FROM transaction_items ti 
                     INNER JOIN transactions t ON ti.transaction_id = t.id 
                     WHERE ti.product_id = p.id AND t.status = 'completed' 
                     AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL ? DAY)) as sales_velocity
             FROM products p
             WHERE p.stock_quantity <= {$thresholdExpr}
             AND p.is_active = 1 
             AND p.deleted_at IS NULL
             ORDER BY ({$thresholdExpr} - p.stock_quantity) DESC",
            [$days]
        );
    }
    
    /**
     * Create stock transfer
     */
    public function createStockTransfer($data) {
        $this->db->beginTransaction();
        
        try {
            $transferId = $this->db->insert('stock_transfers', [
                'transfer_number' => $this->generateTransferNumber(),
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'transfer_date' => $data['transfer_date'] ?? date(DATE_FORMAT),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'status' => 'pending',
                'approval_status' => $data['requires_approval'] ? 'pending' : 'approved',
                'notes' => $data['notes'] ?? null,
                'created_by' => getCurrentUserId()
            ]);
            
            // Insert transfer items
            foreach ($data['items'] as $item) {
                $this->db->insert('stock_transfer_items', [
                    'transfer_id' => $transferId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'serial_numbers' => isset($item['serial_numbers']) ? implode(',', $item['serial_numbers']) : null
                ]);
            }
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'create', 'stock', "Created stock transfer: {$transferId}");
            
            return $transferId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Complete stock transfer
     */
    public function completeStockTransfer($transferId) {
        $transfer = $this->db->fetchOne(
            "SELECT * FROM stock_transfers WHERE id = ?",
            [$transferId]
        );
        
        if (!$transfer) {
            throw new Exception('Transfer not found');
        }
        
        if ($transfer['status'] !== 'pending' && $transfer['status'] !== 'in_transit') {
            throw new Exception('Transfer already completed or cancelled');
        }
        
        $this->db->beginTransaction();
        
        try {
            $items = $this->db->fetchAll(
                "SELECT * FROM stock_transfer_items WHERE transfer_id = ?",
                [$transferId]
            );
            
            $productsModule = new ProductsModule();
            
            foreach ($items as $item) {
                // Decrease from source location
                $productsModule->updateStock(
                    $item['product_id'],
                    $item['quantity'],
                    'out',
                    'stock_transfer',
                    $transferId,
                    "Transferred to location {$transfer['to_location_id']}"
                );
                
                // Increase at destination location
                $productsModule->updateStock(
                    $item['product_id'],
                    $item['quantity'],
                    'in',
                    'stock_transfer',
                    $transferId,
                    "Received from location {$transfer['from_location_id']}"
                );
            }
            
            // Update transfer status
            $this->db->update('stock_transfers', [
                'status' => 'completed'
            ], 'id = ?', [$transferId]);
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'complete', 'stock', "Completed stock transfer: {$transferId}");
            
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Create inventory audit
     */
    public function createInventoryAudit($data) {
        $auditId = $this->db->insert('inventory_audits', [
            'audit_number' => $this->generateAuditNumber(),
            'location_id' => $data['location_id'] ?? null,
            'audit_date' => $data['audit_date'] ?? date(DATE_FORMAT),
            'status' => 'scheduled',
            'notes' => $data['notes'] ?? null,
            'created_by' => getCurrentUserId()
        ]);
        
        logUserActivity(getCurrentUserId(), 'create', 'stock', "Created inventory audit: {$auditId}");
        
        return $auditId;
    }
    
    /**
     * Calculate inventory value
     */
    public function calculateInventoryValue($costingMethod = 'Average') {
        $method = strtoupper($costingMethod);
        
        if ($method === 'FIFO' || $method === 'LIFO') {
            // Use cost layers
            return $this->db->fetchAll(
                "SELECT SUM(remaining_quantity * cost_per_unit) as total_value
                 FROM stock_cost_layers
                 WHERE remaining_quantity > 0"
            );
        } else {
            // Average cost
            return $this->db->fetchAll(
                "SELECT SUM(stock_quantity * cost_price) as total_value
                 FROM products
                 WHERE is_active = 1 AND deleted_at IS NULL"
            );
        }
    }
    
    /**
     * Create cost layer (for FIFO/LIFO)
     */
    private function createCostLayer($productId, $receivingId, $quantity, $costPerUnit) {
        $this->db->insert('stock_cost_layers', [
            'product_id' => $productId,
            'receiving_id' => $receivingId,
            'quantity' => $quantity,
            'cost_per_unit' => $costPerUnit,
            'total_cost' => $quantity * $costPerUnit,
            'remaining_quantity' => $quantity,
            'layer_date' => date(DATE_FORMAT)
        ]);
    }
    
    /**
     * Get stock locations
     */
    public function getLocations() {
        if (!$this->tableExists('stock_locations')) {
            return [];
        }

        $orderField = $this->tableHasColumn('stock_locations', 'name')
            ? 'name'
            : ($this->tableHasColumn('stock_locations', 'store_address') ? 'store_address' : 'id');

        if ($this->tableHasColumn('stock_locations', 'is_active')) {
            return $this->db->fetchAll("SELECT * FROM stock_locations WHERE is_active = 1 ORDER BY {$orderField}");
        }

        if ($this->tableHasColumn('stock_locations', 'status')) {
            return $this->db->fetchAll("SELECT * FROM stock_locations WHERE status = 1 ORDER BY {$orderField}");
        }

        return $this->db->fetchAll("SELECT * FROM stock_locations ORDER BY {$orderField}");
    }
}

