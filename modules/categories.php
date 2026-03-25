<?php
/**
 * Categories Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class CategoriesModule {
    private $db;
    private $categoryColumns = null;
    
    public function __construct() {
        $this->db = getDB();
    }

    private function loadCategoryColumns() {
        if ($this->categoryColumns !== null) {
            return;
        }

        $this->categoryColumns = [];
        $columns = $this->db->fetchAll(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'categories'"
        );
        foreach ($columns as $column) {
            $this->categoryColumns[$column['COLUMN_NAME']] = true;
        }
    }

    private function hasCategoryColumn($columnName) {
        $this->loadCategoryColumns();
        return isset($this->categoryColumns[$columnName]);
    }

    private function getCategoryOrderClause() {
        if ($this->hasCategoryColumn('sort_order')) {
            return "sort_order, name";
        }
        return "name";
    }

    private function filterCategoryData(array $data) {
        $this->loadCategoryColumns();
        $filtered = [];
        foreach ($data as $key => $value) {
            if (isset($this->categoryColumns[$key])) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }
    
    /**
     * Get all categories
     */
    public function getCategories($parentId = null) {
        $sql = "SELECT * FROM categories WHERE is_active = 1";
        $params = [];
        
        if ($parentId === null) {
            $sql .= " AND parent_id IS NULL";
        } else {
            $sql .= " AND parent_id = ?";
            $params[] = $parentId;
        }
        
        $sql .= " ORDER BY " . $this->getCategoryOrderClause();
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get category by ID
     */
    public function getCategory($id) {
        return $this->db->fetchOne(
            "SELECT c.*, p.name as parent_name 
             FROM categories c 
             LEFT JOIN categories p ON c.parent_id = p.id 
             WHERE c.id = ?",
            [$id]
        );
    }
    
    /**
     * Get category tree
     */
    public function getCategoryTree() {
        $categories = $this->db->fetchAll(
            "SELECT * FROM categories WHERE is_active = 1 ORDER BY " . $this->getCategoryOrderClause()
        );
        
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] === null) {
                $tree[$category['id']] = $category;
                $tree[$category['id']]['children'] = [];
            }
        }
        
        foreach ($categories as $category) {
            if ($category['parent_id'] !== null && isset($tree[$category['parent_id']])) {
                $tree[$category['parent_id']]['children'][] = $category;
            }
        }
        
        return $tree;
    }
    
    /**
     * Create category
     */
    public function createCategory($data) {
        if ($this->hasCategoryColumn('slug') && empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['name']);
        }

        $insertData = $this->filterCategoryData($data);
        $categoryId = $this->db->insert('categories', $insertData);
        
        logUserActivity(getCurrentUserId(), 'create', 'categories', "Created category: {$data['name']}");
        
        return $categoryId;
    }
    
    /**
     * Update category
     */
    public function updateCategory($id, $data) {
        if ($this->hasCategoryColumn('slug') && isset($data['name']) && empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['name']);
        }

        $updateData = $this->filterCategoryData($data);
        if (!empty($updateData)) {
            $this->db->update('categories', $updateData, 'id = ?', [$id]);
        }
        
        logUserActivity(getCurrentUserId(), 'update', 'categories', "Updated category ID: {$id}");
        
        return true;
    }
    
    /**
     * Delete category (soft delete)
     */
    public function deleteCategory($id) {
        // Check if category has products
        $productCount = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM products WHERE category_id = ?",
            [$id]
        );
        
        if ($productCount['count'] > 0) {
            throw new Exception('Cannot delete category with existing products');
        }
        
        // Check if category has children
        $childrenCount = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM categories WHERE parent_id = ?",
            [$id]
        );
        
        if ($childrenCount['count'] > 0) {
            throw new Exception('Cannot delete category with subcategories');
        }
        
        $this->db->update('categories', ['is_active' => 0], 'id = ?', [$id]);
        
        logUserActivity(getCurrentUserId(), 'delete', 'categories', "Deleted category ID: {$id}");
        
        return true;
    }
    
    /**
     * Generate slug
     */
    private function generateSlug($name) {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}

