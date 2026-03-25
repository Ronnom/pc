<?php
/**
 * Users Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class UsersModule {
    private $db;
    private $userColumns = null;
    
    public function __construct() {
        $this->db = getDB();
    }

    private function usersHasColumn($columnName) {
        return tableColumnExists('users', $columnName);
    }

    private function loadUserColumns() {
        if ($this->userColumns !== null) {
            return;
        }
        $this->userColumns = [];
        $columns = $this->db->fetchAll(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'"
        );
        foreach ($columns as $column) {
            $this->userColumns[$column['COLUMN_NAME']] = true;
        }
    }

    private function filterUserData(array $data) {
        $this->loadUserColumns();
        $filtered = [];
        foreach ($data as $key => $value) {
            if (isset($this->userColumns[$key])) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function rolesEnabledCondition() {
        if (tableColumnExists('roles', 'is_active')) {
            return " WHERE is_active = 1";
        }
        return "";
    }
    
    /**
     * Get all users
     */
    public function getUsers($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["1=1"];
        $params = [];
        $hasRoleId = $this->usersHasColumn('role_id');
        $hasFullName = $this->usersHasColumn('full_name');
        $hasFirstName = $this->usersHasColumn('first_name');
        $hasLastName = $this->usersHasColumn('last_name');
        $searchFields = ["u.username LIKE ?", "u.email LIKE ?"];
        
        if ($hasRoleId && !empty($filters['role_id'])) {
            $where[] = "u.role_id = ?";
            $params[] = $filters['role_id'];
        }
        
        if (!empty($filters['search'])) {
            if ($hasFullName) {
                $searchFields[] = "u.full_name LIKE ?";
            }
            if ($hasFirstName) {
                $searchFields[] = "u.first_name LIKE ?";
            }
            if ($hasLastName) {
                $searchFields[] = "u.last_name LIKE ?";
            }
            $where[] = '(' . implode(' OR ', $searchFields) . ')';
            $searchTerm = '%' . $filters['search'] . '%';
            for ($i = 0; $i < count($searchFields); $i++) {
                $params[] = $searchTerm;
            }
        }
        
        $whereClause = implode(' AND ', $where);
        
        $roleSelect = $hasRoleId ? "r.name as role_name" : "CASE WHEN u.is_admin = 1 THEN 'Administrator' ELSE 'User' END as role_name";
        $roleJoin = $hasRoleId ? "LEFT JOIN roles r ON u.role_id = r.id" : "";
        $sql = "SELECT u.*, {$roleSelect}
                FROM users u
                {$roleJoin}
                WHERE {$whereClause}
                ORDER BY u.created_at DESC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get user by ID
     */
    public function getUser($id) {
        $hasRoleId = $this->usersHasColumn('role_id');
        $roleSelect = $hasRoleId ? "r.name as role_name" : "CASE WHEN u.is_admin = 1 THEN 'Administrator' ELSE 'User' END as role_name";
        $roleJoin = $hasRoleId ? "LEFT JOIN roles r ON u.role_id = r.id" : "";

        return $this->db->fetchOne(
            "SELECT u.*, {$roleSelect}
             FROM users u
             {$roleJoin}
             WHERE u.id = ?",
            [$id]
        );
    }
    
    /**
     * Create user
     */
    public function createUser($data) {
        // Check if username exists
        $existing = $this->db->fetchOne("SELECT id FROM users WHERE username = ?", [$data['username']]);
        if ($existing) {
            throw new Exception('Username already exists');
        }
        
        // Check if email exists
        $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ?", [$data['email']]);
        if ($existing) {
            throw new Exception('Email already exists');
        }
        
        // Hash password
        $data['password_hash'] = hashPassword($data['password']);
        unset($data['password']);
        
        $insertData = $this->filterUserData($data);
        $userId = $this->db->insert('users', $insertData);
        
        logUserActivity(getCurrentUserId(), 'create', 'users', "Created user: {$data['username']}");
        
        return $userId;
    }
    
    /**
     * Update user
     */
    public function updateUser($id, $data) {
        $user = $this->getUser($id);
        if (!$user) {
            throw new Exception('User not found');
        }
        
        // Check username uniqueness
        if (isset($data['username']) && $data['username'] != $user['username']) {
            $existing = $this->db->fetchOne("SELECT id FROM users WHERE username = ? AND id != ?", [$data['username'], $id]);
            if ($existing) {
                throw new Exception('Username already exists');
            }
        }
        
        // Check email uniqueness
        if (isset($data['email']) && $data['email'] != $user['email']) {
            $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$data['email'], $id]);
            if ($existing) {
                throw new Exception('Email already exists');
            }
        }
        
        // Hash password if provided
        if (!empty($data['password'])) {
            $data['password_hash'] = hashPassword($data['password']);
            unset($data['password']);
        }
        
        $updateData = $this->filterUserData($data);
        if (!empty($updateData)) {
            $this->db->update('users', $updateData, 'id = ?', [$id]);
        }
        
        logUserActivity(getCurrentUserId(), 'update', 'users', "Updated user: {$user['username']}");
        
        return true;
    }
    
    /**
     * Delete user (soft delete)
     */
    public function deleteUser($id) {
        if ($id == getCurrentUserId()) {
            throw new Exception('Cannot delete your own account');
        }
        
        $user = $this->getUser($id);
        if (!$user) {
            throw new Exception('User not found');
        }
        
        $this->db->update('users', ['is_active' => 0], 'id = ?', [$id]);
        
        logUserActivity(getCurrentUserId(), 'delete', 'users', "Deleted user: {$user['username']}");
        
        return true;
    }
    
    /**
     * Get all roles
     */
    public function getRoles() {
        if (!_logTableExists('roles')) {
            return [];
        }
        return $this->db->fetchAll("SELECT * FROM roles" . $this->rolesEnabledCondition() . " ORDER BY name");
    }
    
    /**
     * Get role permissions
     */
    public function getRolePermissions($roleId) {
        if (!_logTableExists('permissions') || !_logTableExists('role_permissions')) {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT p.* FROM permissions p 
             INNER JOIN role_permissions rp ON p.id = rp.permission_id 
             WHERE rp.role_id = ? 
             ORDER BY p.module, p.name",
            [$roleId]
        );
    }
    
    /**
     * Get all permissions
     */
    public function getPermissions() {
        if (!_logTableExists('permissions')) {
            return [];
        }
        return $this->db->fetchAll("SELECT * FROM permissions ORDER BY module, name");
    }
}

