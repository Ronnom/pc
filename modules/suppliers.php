<?php
/**
 * Suppliers Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class SuppliersModule {
    private $db;
    private $supplierColumns = null;
    
    public function __construct() {
        $this->db = getDB();
    }

    private function loadSupplierColumns() {
        if ($this->supplierColumns !== null) {
            return;
        }

        $this->supplierColumns = [];
        $columns = $this->db->fetchAll(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'suppliers'"
        );
        foreach ($columns as $column) {
            $this->supplierColumns[$column['COLUMN_NAME']] = true;
        }
    }

    private function hasSupplierColumn($columnName) {
        $this->loadSupplierColumns();
        return isset($this->supplierColumns[$columnName]);
    }

    private function filterSupplierData(array $data) {
        $this->loadSupplierColumns();
        $filtered = [];
        foreach ($data as $key => $value) {
            if (isset($this->supplierColumns[$key])) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }
    
    /**
     * Get all suppliers
     */
    public function getSuppliers($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["1=1"];
        $params = [];

        if ($this->hasSupplierColumn('is_active')) {
            $where[] = "is_active = 1";
        } elseif ($this->hasSupplierColumn('deleted_at')) {
            $where[] = "deleted_at IS NULL";
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';

            $searchFields = ["name LIKE ?", "email LIKE ?", "phone LIKE ?"];
            if ($this->hasSupplierColumn('contact_person')) {
                $searchFields[] = "contact_person LIKE ?";
            } elseif ($this->hasSupplierColumn('contact_name')) {
                $searchFields[] = "contact_name LIKE ?";
            }

            $where[] = '(' . implode(' OR ', $searchFields) . ')';
            for ($i = 0; $i < count($searchFields); $i++) {
                $params[] = $searchTerm;
            }
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT * FROM suppliers 
                WHERE {$whereClause} 
                ORDER BY name 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get supplier by ID
     */
    public function getSupplier($id) {
        return $this->db->fetchOne("SELECT * FROM suppliers WHERE id = ?", [$id]);
    }
    
    /**
     * Create supplier
     */
    public function createSupplier($data) {
        $insertData = $this->filterSupplierData($data);
        $supplierId = $this->db->insert('suppliers', $insertData);
        
        logUserActivity(getCurrentUserId(), 'create', 'suppliers', "Created supplier: {$data['name']}");
        
        return $supplierId;
    }
    
    /**
     * Update supplier
     */
    public function updateSupplier($id, $data) {
        $updateData = $this->filterSupplierData($data);
        if (!empty($updateData)) {
            $this->db->update('suppliers', $updateData, 'id = ?', [$id]);
        }
        
        logUserActivity(getCurrentUserId(), 'update', 'suppliers', "Updated supplier ID: {$id}");
        
        return true;
    }
    
    /**
     * Delete supplier (soft delete)
     */
    public function deleteSupplier($id) {
        if ($this->hasSupplierColumn('is_active')) {
            $this->db->update('suppliers', ['is_active' => 0], 'id = ?', [$id]);
        } elseif ($this->hasSupplierColumn('deleted_at')) {
            $this->db->update('suppliers', ['deleted_at' => date(DATETIME_FORMAT)], 'id = ?', [$id]);
        }
        
        logUserActivity(getCurrentUserId(), 'delete', 'suppliers', "Deleted supplier ID: {$id}");
        
        return true;
    }
}

