<?php
/**
 * Products Module
 * Handles all product-related operations
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

// Prevent class redeclaration
if (class_exists('ProductsModule', false)) {
    return;
}

class ProductsModule {
    private $db;
    private $productsColumns = null;
    private $tableExistsCache = [];
    
    public function __construct() {
        $this->db = getDB();
    }

    private function loadProductsColumns() {
        if ($this->productsColumns !== null) {
            return;
        }
        $this->productsColumns = [];
        $columns = $this->db->fetchAll(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'products'"
        );
        foreach ($columns as $column) {
            $this->productsColumns[$column['COLUMN_NAME']] = true;
        }
    }

    private function hasProductsColumn($columnName) {
        $this->loadProductsColumns();
        return isset($this->productsColumns[$columnName]);
    }

    private function getPriceColumn() {
        return $this->hasProductsColumn('sell_price') ? 'sell_price' : 'selling_price';
    }

    private function getThresholdColumn() {
        if ($this->hasProductsColumn('min_stock_level')) {
            return 'min_stock_level';
        }
        if ($this->hasProductsColumn('reorder_level')) {
            return 'reorder_level';
        }
        return null;
    }

    private function tableExists($tableName) {
        if (isset($this->tableExistsCache[$tableName])) {
            return $this->tableExistsCache[$tableName];
        }

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$tableName]
        );
        $this->tableExistsCache[$tableName] = !empty($row['cnt']);
        return $this->tableExistsCache[$tableName];
    }
    
    /**
     * Get all products with filters
     */
    public function getProducts($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["p.is_active = 1"];
        $params = [];
        $thresholdColumn = $this->getThresholdColumn();
        
        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $where[] = "p.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = $thresholdColumn ? "p.stock_quantity <= p.{$thresholdColumn}" : "p.stock_quantity <= 5";
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT p.*, c.name as category_name, s.name as supplier_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                LEFT JOIN suppliers s ON p.supplier_id = s.id 
                WHERE {$whereClause} 
                ORDER BY p.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get product by SKU
     */
    public function getProductBySKU($sku) {
        return $this->db->fetchOne(
            "SELECT * FROM products WHERE sku = ?",
            [$sku]
        );
    }
    
    /**
     * Create product
     */
    public function createProduct($data) {
        $priceColumn = $this->getPriceColumn();

        if ($priceColumn === 'sell_price') {
            if (isset($data['selling_price']) && !isset($data['sell_price'])) {
                $data['sell_price'] = $data['selling_price'];
            }
            unset($data['selling_price']);
        } else {
            if (isset($data['sell_price']) && !isset($data['selling_price'])) {
                $data['selling_price'] = $data['sell_price'];
            }
            unset($data['sell_price']);
        }

        if (!$this->hasProductsColumn('min_stock_level')) {
            unset($data['min_stock_level']);
        }
        if (!$this->hasProductsColumn('max_stock_level')) {
            unset($data['max_stock_level']);
        }
        if (!$this->hasProductsColumn('barcode')) {
            unset($data['barcode']);
        }
        if (!$this->hasProductsColumn('tax_rate')) {
            unset($data['tax_rate']);
        }
        if (!$this->hasProductsColumn('is_taxable')) {
            unset($data['is_taxable']);
        }

        // Generate slug only when schema supports it.
        if ($this->hasProductsColumn('slug')) {
            $data['slug'] = $this->generateSlug($data['name']);
        }
        
        // Check if SKU exists
        if ($this->getProductBySKU($data['sku'])) {
            throw new Exception('SKU already exists');
        }
        
        $productId = $this->db->insert('products', $data);
        
        // Log price history
        if (!empty($data['cost_price'])) {
            $this->logPriceChange($productId, null, $data['cost_price'], 'cost', getCurrentUserId(), 'Initial cost price');
        }
        $newSell = $priceColumn === 'sell_price'
            ? ($data['sell_price'] ?? null)
            : ($data['selling_price'] ?? null);
        if (!empty($newSell)) {
            $this->logPriceChange($productId, null, $newSell, 'selling', getCurrentUserId(), 'Initial selling price');
        }
        
        logUserActivity(getCurrentUserId(), 'create', 'products', "Created product: {$data['name']}");
        
        return $productId;
    }
    
    /**
     * Update product
     */
    public function updateProduct($id, $data) {
        $product = $this->getProduct($id);
        $priceColumn = $this->getPriceColumn();
        if (!$product) {
            throw new Exception('Product not found');
        }

        if ($priceColumn === 'sell_price') {
            if (isset($data['selling_price']) && !isset($data['sell_price'])) {
                $data['sell_price'] = $data['selling_price'];
            }
            unset($data['selling_price']);
        } else {
            if (isset($data['sell_price']) && !isset($data['selling_price'])) {
                $data['selling_price'] = $data['sell_price'];
            }
            unset($data['sell_price']);
        }

        if (!$this->hasProductsColumn('min_stock_level')) {
            unset($data['min_stock_level']);
        }
        if (!$this->hasProductsColumn('max_stock_level')) {
            unset($data['max_stock_level']);
        }
        if (!$this->hasProductsColumn('barcode')) {
            unset($data['barcode']);
        }
        if (!$this->hasProductsColumn('tax_rate')) {
            unset($data['tax_rate']);
        }
        if (!$this->hasProductsColumn('is_taxable')) {
            unset($data['is_taxable']);
        }
        
        // Log price changes
        if (isset($data['cost_price']) && $data['cost_price'] != $product['cost_price']) {
            $this->logPriceChange($id, $product['cost_price'], $data['cost_price'], 'cost', getCurrentUserId());
        }
        $incomingSell = $priceColumn === 'sell_price'
            ? ($data['sell_price'] ?? null)
            : ($data['selling_price'] ?? null);
        $existingSell = $priceColumn === 'sell_price'
            ? ($product['sell_price'] ?? $product['selling_price'] ?? null)
            : ($product['selling_price'] ?? $product['sell_price'] ?? null);
        if ($incomingSell !== null && (float)$incomingSell !== (float)$existingSell) {
            $this->logPriceChange($id, $existingSell, $incomingSell, 'selling', getCurrentUserId());
        }
        
        if (isset($data['name'])) {
            if ($this->hasProductsColumn('slug')) {
                $data['slug'] = $this->generateSlug($data['name']);
            }
        }
        
        $this->db->update('products', $data, 'id = ?', [$id]);
        
        logUserActivity(getCurrentUserId(), 'update', 'products', "Updated product: {$product['name']}");
        
        return true;
    }
    
    /**
     * Delete product (soft delete)
     */
    public function deleteProduct($id) {
        $product = $this->getProduct($id);
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        // Check if product has sales history
        $hasSales = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM transaction_items WHERE product_id = ?",
            [$id]
        );
        
        if ($hasSales['count'] > 0) {
            throw new Exception('Cannot delete product with sales history. Use deactivate instead.');
        }
        
        // Soft delete
        $deleteData = ['is_active' => 0];
        if ($this->hasProductsColumn('deleted_at')) {
            $deleteData['deleted_at'] = date(DATETIME_FORMAT);
        }
        if ($this->hasProductsColumn('deleted_by')) {
            $deleteData['deleted_by'] = getCurrentUserId();
        }
        $this->db->update('products', $deleteData, 'id = ?', [$id]);
        
        logUserActivity(getCurrentUserId(), 'delete', 'products', "Soft deleted product: {$product['name']}");
        
        return true;
    }
    
    /**
     * Restore deleted product
     */
    public function restoreProduct($id) {
        $product = $this->getProduct($id, true);
        if (!$product || ($this->hasProductsColumn('deleted_at') && !$product['deleted_at'])) {
            throw new Exception('Product not found or not deleted');
        }

        $restoreData = ['is_active' => 1];
        if ($this->hasProductsColumn('deleted_at')) {
            $restoreData['deleted_at'] = null;
        }
        if ($this->hasProductsColumn('deleted_by')) {
            $restoreData['deleted_by'] = null;
        }
        $this->db->update('products', $restoreData, 'id = ?', [$id]);
        
        logUserActivity(getCurrentUserId(), 'restore', 'products', "Restored product: {$product['name']}");
        
        return true;
    }
    
    /**
     * Get product by ID (including deleted)
     */
    public function getProduct($id, $includeDeleted = false) {
        $where = "p.id = ?";
        $priceColumn = $this->getPriceColumn();
        $thresholdColumn = $this->getThresholdColumn();
        if (!$includeDeleted && $this->hasProductsColumn('deleted_at')) {
            $where .= " AND (p.deleted_at IS NULL OR p.deleted_at = '')";
        }

        $priceAliasSelect = $priceColumn === 'sell_price'
            ? ", p.sell_price AS selling_price"
            : ", p.selling_price";
        $thresholdAliasSelect = $thresholdColumn
            ? ", p.{$thresholdColumn} AS min_stock_level"
            : ", 5 AS min_stock_level";
        $maxStockAliasSelect = $this->hasProductsColumn('max_stock_level')
            ? ", p.max_stock_level"
            : ", NULL AS max_stock_level";
        
        return $this->db->fetchOne(
            "SELECT p.*, c.name as category_name, s.name as supplier_name
                    {$priceAliasSelect}
                    {$thresholdAliasSelect}
                    {$maxStockAliasSelect}
             FROM products p 
             LEFT JOIN categories c ON p.category_id = c.id 
             LEFT JOIN suppliers s ON p.supplier_id = s.id 
             WHERE {$where}",
            [$id]
        );
    }
    
    /**
     * Get product specifications
     */
    public function getProductSpecifications($productId) {
        if (!$this->tableExists('product_specifications')) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT * FROM product_specifications WHERE product_id = ? ORDER BY spec_group, sort_order",
            [$productId]
        );
    }
    
    /**
     * Save product specifications
     */
    public function saveProductSpecifications($productId, $specs) {
        if (!$this->tableExists('product_specifications')) {
            return true;
        }

        // Delete existing specs
        $this->db->delete('product_specifications', 'product_id = ?', [$productId]);
        
        // Insert new specs
        foreach ($specs as $spec) {
            $this->db->insert('product_specifications', [
                'product_id' => $productId,
                'spec_key' => $spec['key'],
                'spec_value' => $spec['value'],
                'spec_group' => $spec['group'] ?? null,
                'sort_order' => $spec['sort_order'] ?? 0
            ]);
        }
        
        return true;
    }
    
    /**
     * Get product images
     */
    public function getProductImages($productId) {
        if (!$this->tableExists('product_images')) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order",
            [$productId]
        );
    }
    
    /**
     * Add product image
     */
    public function addProductImage($productId, $imagePath, $altText = null, $isPrimary = false) {
        if (!$this->tableExists('product_images')) {
            return 0;
        }

        // If this is primary, unset other primary images
        if ($isPrimary) {
            $this->db->update('product_images', ['is_primary' => 0], 'product_id = ?', [$productId]);
        }
        
        return $this->db->insert('product_images', [
            'product_id' => $productId,
            'image_path' => $imagePath,
            'alt_text' => $altText,
            'is_primary' => $isPrimary ? 1 : 0
        ]);
    }
    
    /**
     * Delete product image
     */
    public function deleteProductImage($imageId) {
        if (!$this->tableExists('product_images')) {
            return true;
        }
        return $this->db->delete('product_images', 'id = ?', [$imageId]);
    }
    
    /**
     * Update stock quantity
     */
    public function updateStock($productId, $quantity, $type = 'adjustment', $referenceType = null, $referenceId = null, $notes = null) {
        $product = $this->getProduct($productId);
        if (!$product) {
            throw new Exception('Product not found');
        }

        $conn = $this->db->getConnection();
        $startedTransaction = !$conn->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        
        try {
            // Update stock
            if ($type === 'in' || $type === 'adjustment') {
                $newQuantity = $product['stock_quantity'] + $quantity;
            } else {
                $newQuantity = max(0, $product['stock_quantity'] - $quantity);
            }
            
            $this->db->update('products', 
                ['stock_quantity' => $newQuantity],
                'id = ?',
                [$productId]
            );
            
            // Log stock movement (schema-aware: created_by is optional)
            $movementData = [
                'product_id' => $productId,
                'movement_type' => $type,
                'quantity' => abs($quantity),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes
            ];
            if (tableColumnExists('stock_movements', 'created_by')) {
                $movementData['created_by'] = getCurrentUserId();
            }
            $this->db->insert('stock_movements', $movementData);
            
            if ($startedTransaction) {
                $this->db->commit();
            }
            
            logUserActivity(getCurrentUserId(), 'stock_update', 'inventory', "Updated stock for product: {$product['name']}");
            
            return true;
        } catch (Exception $e) {
            if ($startedTransaction && $conn->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }
    
    /**
     * Log price change
     */
    private function logPriceChange($productId, $oldPrice, $newPrice, $priceType, $userId, $reason = null) {
        if (!$this->tableExists('price_history')) {
            return;
        }

        $this->db->insert('price_history', [
            'product_id' => $productId,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'price_type' => $priceType,
            'changed_by' => $userId,
            'reason' => $reason
        ]);
    }
    
    /**
     * Generate slug from name
     */
    private function generateSlug($name) {
        if (!$this->hasProductsColumn('slug')) {
            return '';
        }

        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Ensure uniqueness
        $baseSlug = $slug;
        $counter = 1;
        while ($this->db->fetchOne("SELECT id FROM products WHERE slug = ?", [$slug])) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
}

