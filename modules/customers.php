<?php
/**
 * Customers Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class CustomersModule {
    private $db;
    private $customerColumns = null;
    
    public function __construct() {
        $this->db = getDB();
    }

    private function loadCustomerColumns() {
        if ($this->customerColumns !== null) {
            return;
        }

        $this->customerColumns = [];
        $columns = $this->db->fetchAll(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'customers'"
        );
        foreach ($columns as $column) {
            $this->customerColumns[$column['COLUMN_NAME']] = true;
        }
    }

    private function hasCustomerColumn($columnName) {
        $this->loadCustomerColumns();
        return isset($this->customerColumns[$columnName]);
    }

    private function normalizePhone($phone) {
        return preg_replace('/\D+/', '', (string)$phone);
    }

    private function validateCustomerContactData(array &$data) {
        if (array_key_exists('phone', $data) && $data['phone'] !== null && $data['phone'] !== '') {
            $normalizedPhone = $this->normalizePhone($data['phone']);
            if ($normalizedPhone === '' || strlen($normalizedPhone) > 11) {
                throw new Exception('Phone number must contain digits only and must not exceed 11 digits.');
            }
            $data['phone'] = $normalizedPhone;
        }

        if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== '') {
            $email = trim((string)$data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a complete email address such as name@gmail.com.');
            }
            $data['email'] = $email;
        }
    }

    private function filterCustomerData(array $data) {
        $this->loadCustomerColumns();
        $filtered = [];
        foreach ($data as $key => $value) {
            if (isset($this->customerColumns[$key])) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }
    
    /**
     * Get all customers
     */
    public function getCustomers($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["1=1"];
        $params = [];

        if ($this->hasCustomerColumn('is_active')) {
            $where[] = "is_active = 1";
        } elseif ($this->hasCustomerColumn('deleted_at')) {
            $where[] = "deleted_at IS NULL";
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';

            $searchFields = [
                "first_name LIKE ?",
                "last_name LIKE ?",
                "CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?",
                "email LIKE ?",
                "phone LIKE ?"
            ];
            if ($this->hasCustomerColumn('customer_code')) {
                $searchFields[] = "customer_code LIKE ?";
            }

            $where[] = '(' . implode(' OR ', $searchFields) . ')';
            for ($i = 0; $i < count($searchFields); $i++) {
                $params[] = $searchTerm;
            }
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT * FROM customers 
                WHERE {$whereClause} 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get customer by ID
     */
    public function getCustomer($id) {
        return $this->db->fetchOne("SELECT * FROM customers WHERE id = ?", [$id]);
    }
    
    /**
     * Create customer
     */
    public function createCustomer($data) {
        $this->validateCustomerContactData($data);

        // Generate customer code only when schema supports it.
        if ($this->hasCustomerColumn('customer_code') && empty($data['customer_code'])) {
            $data['customer_code'] = 'CUST-' . strtoupper(substr(md5(uniqid()), 0, 8));
        }
        
        // Check if customer code exists when applicable.
        if ($this->hasCustomerColumn('customer_code') && !empty($data['customer_code'])) {
            $existing = $this->db->fetchOne("SELECT id FROM customers WHERE customer_code = ?", [$data['customer_code']]);
            if ($existing) {
                throw new Exception('Customer code already exists');
            }
        }

        $insertData = $this->filterCustomerData($data);
        $customerId = $this->db->insert('customers', $insertData);

        $first = (string)($data['first_name'] ?? '');
        $last = (string)($data['last_name'] ?? '');
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = 'Customer #' . $customerId;
        }
        logUserActivity(getCurrentUserId(), 'create', 'customers', "Created customer: {$name}");
        
        return $customerId;
    }
    
    /**
     * Update customer
     */
    public function updateCustomer($id, $data) {
        $this->validateCustomerContactData($data);

        $updateData = $this->filterCustomerData($data);
        if (!empty($updateData)) {
            $this->db->update('customers', $updateData, 'id = ?', [$id]);
        }
        
        logUserActivity(getCurrentUserId(), 'update', 'customers', "Updated customer ID: {$id}");
        
        return true;
    }
    
    /**
     * Delete customer (soft delete)
     */
    public function deleteCustomer($id) {
        if ($this->hasCustomerColumn('is_active')) {
            $this->db->update('customers', ['is_active' => 0], 'id = ?', [$id]);
        } elseif ($this->hasCustomerColumn('deleted_at')) {
            $this->db->update('customers', ['deleted_at' => date(DATETIME_FORMAT)], 'id = ?', [$id]);
        }
        
        logUserActivity(getCurrentUserId(), 'delete', 'customers', "Deleted customer ID: {$id}");
        
        return true;
    }
}

