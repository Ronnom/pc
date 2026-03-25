<?php
/**
 * Database Connection Module
 * Handles all database operations using PDO with prepared statements
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class Database {
    private static $instance = null;
    private $connection = null;
    private $tableColumnsCache = [];
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please contact the administrator.");
        }
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Execute a query and return results
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // Log full PDO message and SQL for diagnostics
            error_log("Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            // Re-throw a more detailed exception in development to aid debugging.
            // The detailed PDO message is already logged; rethrowing it helps the UI show the cause.
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Fetch a single row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Insert a record and return the last insert ID
     */
    public function insert($table, $data) {
        $data = $this->filterDataToExistingColumns($table, $data);
        if (empty($data)) {
            throw new Exception("Insert failed: no valid columns for table '{$table}'.");
        }

        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $fieldsList = implode(', ', $fields);
        
        $sql = "INSERT INTO `{$table}` ({$fieldsList}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        return $this->connection->lastInsertId();
    }
    
    /**
     * Update records
     */
    public function update($table, $data, $where, $whereParams = []) {
        $data = $this->filterDataToExistingColumns($table, $data);
        if (empty($data)) {
            // Nothing to update after filtering unknown columns.
            return null;
        }

        $setParts = [];

        // Avoid mixing named and positional parameters in the same query.
        if (strpos($where, '?') !== false) {
            foreach (array_keys($data) as $field) {
                $setParts[] = "`{$field}` = ?";
            }
            $setClause = implode(', ', $setParts);

            $sql = "UPDATE `{$table}` SET {$setClause} WHERE {$where}";
            $params = array_merge(array_values($data), $whereParams);
            return $this->query($sql, $params);
        }

        foreach (array_keys($data) as $field) {
            $setParts[] = "`{$field}` = :{$field}";
        }
        $setClause = implode(', ', $setParts);

        $sql = "UPDATE `{$table}` SET {$setClause} WHERE {$where}";
        $params = array_merge($data, $whereParams);
        return $this->query($sql, $params);
    }
    
    /**
     * Delete records
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        return $this->query($sql, $params);
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Return existing column names for a table (cached).
     */
    private function getTableColumns($table) {
        if (isset($this->tableColumnsCache[$table])) {
            return $this->tableColumnsCache[$table];
        }

        $rows = $this->fetchAll(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['COLUMN_NAME']] = true;
        }

        $this->tableColumnsCache[$table] = $columns;
        return $columns;
    }

    /**
     * Remove keys that are not valid columns in the target table.
     */
    private function filterDataToExistingColumns($table, $data) {
        if (!is_array($data) || empty($data)) {
            return [];
        }

        $columns = $this->getTableColumns($table);
        $filtered = [];
        foreach ($data as $field => $value) {
            if (isset($columns[$field])) {
                $filtered[$field] = $value;
            }
        }

        return $filtered;
    }
}

// Global function to get database instance
function getDB() {
    return Database::getInstance();
}

