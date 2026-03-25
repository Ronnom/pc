<?php
/**
 * Enhanced Products Module
 * Comprehensive product management with all features
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class ProductsModuleEnhanced {
    private $db;
    private $productsColumns = null;
    private $tableExistsCache = [];
    
    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Load and cache products table columns
     */
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

    /**
     * Check if a column exists in products table
     */
    private function hasProductsColumn($columnName) {
        $this->loadProductsColumns();
        return isset($this->productsColumns[$columnName]);
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
        $this->tableExistsCache[$tableName] = (int)($row['cnt'] ?? 0) > 0;
        return $this->tableExistsCache[$tableName];
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
    
    /**
     * Generate SKU automatically
     */
    public function generateSKU($categoryId = null, $brand = null) {
        $prefix = '';
        if ($categoryId) {
            $hasSlugCol = (int)($this->db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'slug'"
            )['cnt'] ?? 0) > 0;
            if ($hasSlugCol) {
                $category = $this->db->fetchOne("SELECT slug FROM categories WHERE id = ?", [$categoryId]);
                if ($category && !empty($category['slug'])) {
                    $prefix = strtoupper(substr($category['slug'], 0, 3));
                }
            }
        }
        if ($brand) {
            $prefix .= strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $brand), 0, 3));
        }
        if (empty($prefix)) {
            $prefix = 'PRD';
        }
        
        $counter = 1;
        do {
            $sku = $prefix . '-' . str_pad($counter, 6, '0', STR_PAD_LEFT);
            $exists = $this->db->fetchOne("SELECT id FROM products WHERE sku = ?", [$sku]);
            $counter++;
        } while ($exists);
        
        return $sku;
    }
    
    /**
     * Calculate markup percentage
     */
    public function calculateMarkup($costPrice, $sellingPrice) {
        if ($costPrice == 0) return 0;
        return round((($sellingPrice - $costPrice) / $costPrice) * 100, 2);
    }
    
    /**
     * Get products with advanced filters
     */
    public function getProducts($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0, $orderBy = 'created_at', $orderDir = 'DESC') {
        $where = [];
        $params = [];
        $priceColumn = $this->getPriceColumn();
        $thresholdColumn = $this->getThresholdColumn();

        if ($this->hasProductsColumn('deleted_at')) {
            $where[] = "p.deleted_at IS NULL";
        }
        
        if (!isset($filters['include_inactive']) || !$filters['include_inactive']) {
            $where[] = "p.is_active = 1";
        }
        
        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $where[] = "p.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['brand']) && $this->hasProductsColumn('brand')) {
            $where[] = "p.brand = ?";
            $params[] = $filters['brand'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $searchFields = ["p.name", "p.sku"];
            $searchParams = [$searchTerm, $searchTerm];

            if ($this->hasProductsColumn('barcode')) {
                $searchFields[] = "p.barcode";
                $searchParams[] = $searchTerm;
            }
            if ($this->hasProductsColumn('brand')) {
                $searchFields[] = "p.brand";
                $searchParams[] = $searchTerm;
            }
            if ($this->hasProductsColumn('model')) {
                $searchFields[] = "p.model";
                $searchParams[] = $searchTerm;
            }

            $searchConditions = [];
            foreach ($searchFields as $field) {
                $searchConditions[] = "{$field} LIKE ?";
            }

            $where[] = '(' . implode(' OR ', $searchConditions) . ')';
            $params = array_merge($params, $searchParams);
        }
        
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = $thresholdColumn
                ? "p.stock_quantity <= p.{$thresholdColumn}"
                : "p.stock_quantity <= 5";
        }

        if (empty($filters['include_service'])) {
            $where[] = "p.sku NOT IN ('REPAIR-SERVICE','SERVICE-FEE')";
        }
        
        if (isset($filters['out_of_stock']) && $filters['out_of_stock']) {
            $where[] = "p.stock_quantity = 0";
        }
        
        if (!empty($filters['price_min'])) {
            $where[] = "p.{$priceColumn} >= ?";
            $params[] = $filters['price_min'];
        }
        
        if (!empty($filters['price_max'])) {
            $where[] = "p.{$priceColumn} <= ?";
            $params[] = $filters['price_max'];
        }
        
        if (isset($filters['stock_status'])) {
            if ($filters['stock_status'] === 'in_stock') {
                $where[] = "p.stock_quantity > 0";
            } elseif ($filters['stock_status'] === 'out_of_stock') {
                $where[] = "p.stock_quantity = 0";
            } elseif ($filters['stock_status'] === 'low_stock') {
                $where[] = $thresholdColumn
                    ? "p.stock_quantity <= p.{$thresholdColumn} AND p.stock_quantity > 0"
                    : "p.stock_quantity <= 5 AND p.stock_quantity > 0";
            }
        }
        
        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);
        
        // Validate order by
        $allowedOrderBy = ['name', 'sku', 'cost_price', $priceColumn, 'stock_quantity', 'created_at', 'updated_at'];
        if ($this->hasProductsColumn('brand')) {
            $allowedOrderBy[] = 'brand';
        }
        if ($orderBy === 'selling_price') {
            $orderBy = $priceColumn;
        }
        if (!in_array($orderBy, $allowedOrderBy)) {
            $orderBy = 'created_at';
        }
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

        $priceAliasSelect = $priceColumn === 'sell_price'
            ? ", p.sell_price AS selling_price"
            : ", p.selling_price";
        $thresholdAliasSelect = $thresholdColumn
            ? ", p.{$thresholdColumn} AS min_stock_level"
            : ", 5 AS min_stock_level";
        $maxStockAliasSelect = $this->hasProductsColumn('max_stock_level')
            ? ", p.max_stock_level"
            : ", NULL AS max_stock_level";
        $primaryImageSelect = $this->tableExists('product_images')
            ? ", (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as primary_image"
            : ", NULL AS primary_image";
        
        $sql = "SELECT p.*, c.name as category_name, s.name as supplier_name
                       {$priceAliasSelect}
                       {$thresholdAliasSelect}
                       {$maxStockAliasSelect}
                       {$primaryImageSelect}
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                LEFT JOIN suppliers s ON p.supplier_id = s.id 
                WHERE {$whereClause} 
                ORDER BY p.{$orderBy} {$orderDir}
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get product count with filters
     */
    public function getProductCount($filters = []) {
        $where = [];
        $params = [];
        $priceColumn = $this->getPriceColumn();
        $thresholdColumn = $this->getThresholdColumn();

        if ($this->hasProductsColumn('deleted_at')) {
            $where[] = "p.deleted_at IS NULL";
        }
        
        if (!isset($filters['include_inactive']) || !$filters['include_inactive']) {
            $where[] = "p.is_active = 1";
        }
        
        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = ?";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = "p.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }

        if (!empty($filters['brand']) && $this->hasProductsColumn('brand')) {
            $where[] = "p.brand = ?";
            $params[] = $filters['brand'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $searchFields = ["p.name", "p.sku"];
            $searchParams = [$searchTerm, $searchTerm];

            if ($this->hasProductsColumn('barcode')) {
                $searchFields[] = "p.barcode";
                $searchParams[] = $searchTerm;
            }
            if ($this->hasProductsColumn('brand')) {
                $searchFields[] = "p.brand";
                $searchParams[] = $searchTerm;
            }
            if ($this->hasProductsColumn('model')) {
                $searchFields[] = "p.model";
                $searchParams[] = $searchTerm;
            }

            $searchConditions = [];
            foreach ($searchFields as $field) {
                $searchConditions[] = "{$field} LIKE ?";
            }

            $where[] = '(' . implode(' OR ', $searchConditions) . ')';
            $params = array_merge($params, $searchParams);
        }

        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = $thresholdColumn
                ? "p.stock_quantity <= p.{$thresholdColumn}"
                : "p.stock_quantity <= 5";
        }

        if (empty($filters['include_service'])) {
            $where[] = "p.sku NOT IN ('REPAIR-SERVICE','SERVICE-FEE')";
        }

        if (isset($filters['out_of_stock']) && $filters['out_of_stock']) {
            $where[] = "p.stock_quantity = 0";
        }

        if (!empty($filters['price_min'])) {
            $where[] = "p.{$priceColumn} >= ?";
            $params[] = $filters['price_min'];
        }

        if (!empty($filters['price_max'])) {
            $where[] = "p.{$priceColumn} <= ?";
            $params[] = $filters['price_max'];
        }

        if (isset($filters['stock_status'])) {
            if ($filters['stock_status'] === 'in_stock') {
                $where[] = "p.stock_quantity > 0";
            } elseif ($filters['stock_status'] === 'out_of_stock') {
                $where[] = "p.stock_quantity = 0";
            } elseif ($filters['stock_status'] === 'low_stock') {
                $where[] = $thresholdColumn
                    ? "p.stock_quantity <= p.{$thresholdColumn} AND p.stock_quantity > 0"
                    : "p.stock_quantity <= 5 AND p.stock_quantity > 0";
            }
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);
        
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM products p WHERE {$whereClause}",
            $params
        );
        
        return $result['count'];
    }
    
    /**
     * Get product by ID (including deleted)
     */
    public function getProduct($id, $includeDeleted = false) {
        $where = "p.id = ?";
        $priceColumn = $this->getPriceColumn();
        $thresholdColumn = $this->getThresholdColumn();
        if (!$includeDeleted && $this->hasProductsColumn('deleted_at')) {
            $where .= " AND p.deleted_at IS NULL";
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
     * Get product sales history
     */
    public function getSalesHistory($productId, $days = 30) {
        return $this->db->fetchAll(
            "SELECT DATE(ti.created_at) as sale_date, SUM(ti.quantity) as total_quantity, SUM(ti.total) as total_amount
             FROM transaction_items ti
             INNER JOIN transactions t ON ti.transaction_id = t.id
             WHERE ti.product_id = ? AND t.status = 'completed' AND ti.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(ti.created_at)
             ORDER BY sale_date DESC",
            [$productId, $days]
        );
    }
    
    /**
     * Check if product has sales history
     */
    public function hasSalesHistory($productId) {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM transaction_items WHERE product_id = ?",
            [$productId]
        );
        return $result['count'] > 0;
    }
    
    /**
     * Soft delete product
     */
    public function softDelete($productId) {
        $product = $this->getProduct($productId);
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        if ($this->hasSalesHistory($productId)) {
            throw new Exception('Cannot delete product with sales history. Use deactivate instead.');
        }
        
        $updateData = ['is_active' => 0];
        if ($this->hasProductsColumn('deleted_at')) {
            $updateData['deleted_at'] = date(DATETIME_FORMAT);
        }
        if ($this->hasProductsColumn('deleted_by')) {
            $updateData['deleted_by'] = getCurrentUserId();
        }

        $this->db->update('products', $updateData, 'id = ?', [$productId]);
        
        logUserActivity(getCurrentUserId(), 'delete', 'products', "Soft deleted product: {$product['name']}");
        
        return true;
    }
    
    /**
     * Restore deleted product
     */
    public function restoreProduct($productId) {
        $product = $this->getProduct($productId, true);
        if (!$product) {
            throw new Exception('Product not found');
        }
        if ($this->hasProductsColumn('deleted_at') && empty($product['deleted_at'])) {
            throw new Exception('Product not found or not deleted');
        }

        $updateData = ['is_active' => 1];
        if ($this->hasProductsColumn('deleted_at')) {
            $updateData['deleted_at'] = null;
        }
        if ($this->hasProductsColumn('deleted_by')) {
            $updateData['deleted_by'] = null;
        }

        $this->db->update('products', $updateData, 'id = ?', [$productId]);
        
        logUserActivity(getCurrentUserId(), 'restore', 'products', "Restored product: {$product['name']}");
        
        return true;
    }
    
    /**
     * Get all brands
     */
    public function getBrands() {
        if (!$this->hasProductsColumn('brand')) {
            return [];
        }

        $where = ["brand IS NOT NULL", "brand != ''"];
        if ($this->hasProductsColumn('deleted_at')) {
            $where[] = "deleted_at IS NULL";
        }

        $sql = "SELECT DISTINCT brand FROM products WHERE " . implode(' AND ', $where) . " ORDER BY brand";
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get PC component specifications template
     */
    public function getComponentSpecsTemplate($categorySlug) {
        $templates = [
            'cpus' => [
                'socket' => 'Socket Type',
                'cores' => 'Core Count',
                'threads' => 'Thread Count',
                'base_clock' => 'Base Clock (GHz)',
                'boost_clock' => 'Boost Clock (GHz)',
                'tdp' => 'TDP (W)',
                'cache' => 'Cache (MB)',
                'integrated_graphics' => 'Integrated Graphics'
            ],
            'gpus' => [
                'memory_size' => 'Memory Size (GB)',
                'memory_type' => 'Memory Type (GDDR5/GDDR6/GDDR6X)',
                'cuda_cores' => 'CUDA Cores / Stream Processors',
                'power_connectors' => 'Power Connectors',
                'ports' => 'Video Output Ports',
                'boost_clock' => 'Boost Clock (MHz)',
                'base_clock' => 'Base Clock (MHz)',
                'length' => 'Card Length (mm)'
            ],
            'motherboards' => [
                'chipset' => 'Chipset',
                'socket' => 'Socket Type',
                'ram_slots' => 'RAM Slots',
                'max_ram' => 'Max RAM (GB)',
                'ram_type' => 'Supported RAM Type',
                'form_factor' => 'Form Factor',
                'pcie_slots' => 'PCIe Slots',
                'sata_ports' => 'SATA Ports',
                'm2_slots' => 'M.2 Slots',
                'usb_ports' => 'USB Ports'
            ],
            'ram' => [
                'type' => 'Type (DDR4/DDR5)',
                'speed' => 'Speed (MHz)',
                'capacity' => 'Capacity per Module (GB)',
                'modules' => 'Number of Modules',
                'total_capacity' => 'Total Capacity (GB)',
                'latency' => 'Latency (CL)',
                'voltage' => 'Voltage (V)',
                'heat_spreader' => 'Heat Spreader'
            ],
            'storage' => [
                'capacity' => 'Capacity (GB/TB)',
                'interface' => 'Interface (SATA/NVMe)',
                'form_factor' => 'Form Factor (2.5"/M.2)',
                'read_speed' => 'Read Speed (MB/s)',
                'write_speed' => 'Write Speed (MB/s)',
                'cache' => 'Cache (MB)',
                'endurance' => 'Endurance (TBW)',
                'warranty' => 'Warranty (Years)'
            ],
            'power-supplies' => [
                'wattage' => 'Wattage (W)',
                'efficiency_rating' => 'Efficiency Rating (80 Plus)',
                'modular_type' => 'Modular Type',
                'fan_size' => 'Fan Size (mm)',
                'connectors' => 'Connectors',
                'protection' => 'Protection Features'
            ],
            'cases' => [
                'form_factor' => 'Supported Form Factor',
                'dimensions' => 'Dimensions (LxWxH)',
                'material' => 'Material',
                'front_panel' => 'Front Panel I/O',
                'fan_support' => 'Fan Support',
                'radiator_support' => 'Radiator Support',
                'drive_bays' => 'Drive Bays'
            ],
            'cooling' => [
                'type' => 'Type (Air/Liquid)',
                'socket_support' => 'Socket Support',
                'fan_size' => 'Fan Size (mm)',
                'noise_level' => 'Noise Level (dBA)',
                'dimensions' => 'Dimensions',
                'radiator_size' => 'Radiator Size (if AIO)'
            ],
            'intel-cpus' => [
                'generation' => 'Generation (12th/13th/14th)',
                'socket' => 'Socket Type',
                'cores' => 'Core Count',
                'threads' => 'Thread Count',
                'base_clock' => 'Base Clock (GHz)',
                'boost_clock' => 'Boost Clock (GHz)',
                'tdp' => 'TDP (W)',
                'cache' => 'Cache (MB)',
                'integrated_graphics' => 'Integrated Graphics'
            ],
            'amd-cpus' => [
                'generation' => 'Generation (Ryzen 5000/7000/8000)',
                'socket' => 'Socket Type',
                'cores' => 'Core Count',
                'threads' => 'Thread Count',
                'base_clock' => 'Base Clock (GHz)',
                'boost_clock' => 'Boost Clock (GHz)',
                'tdp' => 'TDP (W)',
                'cache' => 'Cache (MB)',
                'integrated_graphics' => 'Integrated Graphics'
            ],
            'atx-motherboards' => [
                'chipset' => 'Chipset',
                'socket' => 'Socket Type',
                'ram_slots' => 'RAM Slots',
                'max_ram' => 'Max RAM (GB)',
                'ram_type' => 'Supported RAM Type',
                'form_factor' => 'Form Factor (ATX)',
                'pcie_slots' => 'PCIe Slots',
                'sata_ports' => 'SATA Ports',
                'm2_slots' => 'M.2 Slots',
                'usb_ports' => 'USB Ports'
            ],
            'micro-atx-motherboards' => [
                'chipset' => 'Chipset',
                'socket' => 'Socket Type',
                'ram_slots' => 'RAM Slots',
                'max_ram' => 'Max RAM (GB)',
                'ram_type' => 'Supported RAM Type',
                'form_factor' => 'Form Factor (Micro-ATX)',
                'pcie_slots' => 'PCIe Slots',
                'sata_ports' => 'SATA Ports',
                'm2_slots' => 'M.2 Slots',
                'usb_ports' => 'USB Ports'
            ],
            'mini-itx-motherboards' => [
                'chipset' => 'Chipset',
                'socket' => 'Socket Type',
                'ram_slots' => 'RAM Slots',
                'max_ram' => 'Max RAM (GB)',
                'ram_type' => 'Supported RAM Type',
                'form_factor' => 'Form Factor (Mini-ITX)',
                'pcie_slots' => 'PCIe Slots',
                'sata_ports' => 'SATA Ports',
                'm2_slots' => 'M.2 Slots',
                'usb_ports' => 'USB Ports'
            ],
            'ddr4-ram' => [
                'speed' => 'Speed (MHz)',
                'capacity' => 'Capacity per Module (GB)',
                'modules' => 'Number of Modules',
                'total_capacity' => 'Total Capacity (GB)',
                'latency' => 'Latency (CL)',
                'voltage' => 'Voltage (V)',
                'heat_spreader' => 'Heat Spreader',
                'rgb' => 'RGB Lighting'
            ],
            'ddr5-ram' => [
                'speed' => 'Speed (MHz)',
                'capacity' => 'Capacity per Module (GB)',
                'modules' => 'Number of Modules',
                'total_capacity' => 'Total Capacity (GB)',
                'latency' => 'Latency (CL)',
                'voltage' => 'Voltage (V)',
                'heat_spreader' => 'Heat Spreader',
                'rgb' => 'RGB Lighting'
            ],
            'hdd' => [
                'capacity' => 'Capacity (GB/TB)',
                'interface' => 'Interface (SATA)',
                'form_factor' => 'Form Factor (3.5")',
                'rpm' => 'RPM',
                'cache' => 'Cache (MB)',
                'warranty' => 'Warranty (Years)'
            ],
            'ssd' => [
                'capacity' => 'Capacity (GB/TB)',
                'interface' => 'Interface (SATA)',
                'form_factor' => 'Form Factor (2.5")',
                'read_speed' => 'Read Speed (MB/s)',
                'write_speed' => 'Write Speed (MB/s)',
                'cache' => 'Cache (MB)',
                'endurance' => 'Endurance (TBW)',
                'warranty' => 'Warranty (Years)'
            ],
            'nvme' => [
                'capacity' => 'Capacity (GB/TB)',
                'interface' => 'Interface (PCIe Gen)',
                'form_factor' => 'Form Factor (M.2)',
                'read_speed' => 'Read Speed (MB/s)',
                'write_speed' => 'Write Speed (MB/s)',
                'cache' => 'Cache (MB)',
                'endurance' => 'Endurance (TBW)',
                'warranty' => 'Warranty (Years)',
                'heatsink' => 'Includes Heatsink'
            ]
        ];
        
        return $templates[$categorySlug] ?? [];
    }
}

