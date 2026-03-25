<?php
/**
 * Inventory Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class InventoryModule {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }

    private function getThresholdColumn() {
        if (tableColumnExists('products', 'min_stock_level')) {
            return 'min_stock_level';
        }
        if (tableColumnExists('products', 'reorder_level')) {
            return 'reorder_level';
        }
        return null;
    }
    
    /**
     * Get stock movements
     */
    public function getStockMovements($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["1=1"];
        $params = [];
        
        if (!empty($filters['product_id'])) {
            $where[] = "sm.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        if (!empty($filters['movement_type'])) {
            $where[] = "sm.movement_type = ?";
            $params[] = $filters['movement_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(sm.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(sm.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT sm.*, p.name as product_name, p.sku, u.username as created_by_name 
                FROM stock_movements sm 
                INNER JOIN products p ON sm.product_id = p.id 
                LEFT JOIN users u ON sm.created_by = u.id 
                WHERE {$whereClause} 
                ORDER BY sm.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get inventory adjustments
     */
    public function getAdjustments($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["1=1"];
        $params = [];
        
        if (!empty($filters['product_id'])) {
            $where[] = "ia.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT ia.*, p.name as product_name, p.sku, u.username as created_by_name 
                FROM inventory_adjustments ia 
                INNER JOIN products p ON ia.product_id = p.id 
                LEFT JOIN users u ON ia.created_by = u.id 
                WHERE {$whereClause} 
                ORDER BY ia.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Create inventory adjustment
     */
    public function createAdjustment($productId, $quantityAfter, $reason, $notes = null) {
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
        
        $this->db->beginTransaction();
        
        try {
            // Create adjustment record
            $adjustmentId = $this->db->insert('inventory_adjustments', [
                'adjustment_number' => generateAdjustmentNumber(),
                'product_id' => $productId,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'adjustment_type' => $difference > 0 ? 'increase' : 'decrease',
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => getCurrentUserId()
            ]);
            
            // Update stock
            $productsModule->updateStock(
                $productId,
                abs($difference),
                'adjustment',
                'inventory_adjustment',
                $adjustmentId,
                $reason
            );
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'create', 'inventory', "Created inventory adjustment for product: {$product['name']}");
            
            return $adjustmentId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get low stock products
     */
    public function getLowStockProducts() {
        $thresholdColumn = $this->getThresholdColumn();
        $condition = $thresholdColumn
            ? "stock_quantity <= {$thresholdColumn}"
            : "stock_quantity <= 5";

        return $this->db->fetchAll(
            "SELECT * FROM products 
             WHERE {$condition} AND is_active = 1 
             ORDER BY stock_quantity ASC"
        );
    }
}

