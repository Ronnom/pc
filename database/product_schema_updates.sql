-- ============================================
-- Product/Inventory Management Schema Updates
-- Idempotent updates for products table
-- Compatible with common MySQL/MariaDB versions in XAMPP
-- ============================================

SET @db_name = DATABASE();

-- --------------------------------------------
-- Columns
-- --------------------------------------------

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'brand') = 0,
    "ALTER TABLE `products` ADD COLUMN `brand` varchar(100) DEFAULT NULL AFTER `name`",
    "SELECT 'Column brand already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'model') = 0,
    "ALTER TABLE `products` ADD COLUMN `model` varchar(100) DEFAULT NULL AFTER `brand`",
    "SELECT 'Column model already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'location') = 0,
    "ALTER TABLE `products` ADD COLUMN `location` varchar(100) DEFAULT NULL AFTER `stock_quantity`",
    "SELECT 'Column location already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'warranty_period') = 0,
    "ALTER TABLE `products` ADD COLUMN `warranty_period` int(11) DEFAULT NULL COMMENT 'Warranty period in months' AFTER `location`",
    "SELECT 'Column warranty_period already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'markup_percentage') = 0,
    "ALTER TABLE `products` ADD COLUMN `markup_percentage` decimal(5,2) DEFAULT NULL AFTER `selling_price`",
    "SELECT 'Column markup_percentage already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'deleted_at') = 0,
    "ALTER TABLE `products` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL AFTER `is_active`",
    "SELECT 'Column deleted_at already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'deleted_by') = 0,
    "ALTER TABLE `products` ADD COLUMN `deleted_by` int(11) DEFAULT NULL AFTER `deleted_at`",
    "SELECT 'Column deleted_by already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------
-- Indexes
-- --------------------------------------------

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND INDEX_NAME = 'brand') = 0,
    "ALTER TABLE `products` ADD INDEX `brand` (`brand`)",
    "SELECT 'Index brand already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND INDEX_NAME = 'model') = 0,
    "ALTER TABLE `products` ADD INDEX `model` (`model`)",
    "SELECT 'Index model already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND INDEX_NAME = 'deleted_at') = 0,
    "ALTER TABLE `products` ADD INDEX `deleted_at` (`deleted_at`)",
    "SELECT 'Index deleted_at already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------
-- Foreign key: deleted_by -> users.id
-- --------------------------------------------

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db_name
       AND CONSTRAINT_NAME = 'products_ibfk_deleted_by'
       AND TABLE_NAME = 'products') = 0,
    "ALTER TABLE `products` ADD CONSTRAINT `products_ibfk_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL",
    "SELECT 'FK products_ibfk_deleted_by already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------
-- Data normalization
-- --------------------------------------------

UPDATE `products`
SET `deleted_at` = NULL
WHERE `deleted_at` IS NULL;

-- Add discontinued column
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'discontinued') = 0,
    "ALTER TABLE `products` ADD COLUMN `discontinued` tinyint(1) DEFAULT 0 AFTER `is_active`",
    "SELECT 'Column discontinued already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add reorder_level column
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND COLUMN_NAME = 'reorder_level') = 0,
    "ALTER TABLE `products` ADD COLUMN `reorder_level` int(11) DEFAULT 0 AFTER `min_stock_level`",
    "SELECT 'Column reorder_level already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create product_price_history table
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'product_price_history') = 0,
    "CREATE TABLE `product_price_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL,
        `old_cost_price` decimal(10,2) DEFAULT NULL,
        `new_cost_price` decimal(10,2) DEFAULT NULL,
        `old_selling_price` decimal(10,2) DEFAULT NULL,
        `new_selling_price` decimal(10,2) DEFAULT NULL,
        `changed_by` int(11) NOT NULL,
        `reason` varchar(200) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `product_id` (`product_id`),
        KEY `changed_by` (`changed_by`),
        KEY `created_at` (`created_at`),
        CONSTRAINT `product_price_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
        CONSTRAINT `product_price_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "SELECT 'Table product_price_history already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create product_stock_adjustments table
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'product_stock_adjustments') = 0,
    "CREATE TABLE `product_stock_adjustments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL,
        `adjustment_type` enum('manual','sale','purchase','return','damage','transfer') NOT NULL,
        `quantity_change` int(11) NOT NULL,
        `previous_quantity` int(11) NOT NULL,
        `new_quantity` int(11) NOT NULL,
        `reason` text,
        `reference_id` int(11) DEFAULT NULL COMMENT 'PO ID, transaction ID, etc.',
        `adjusted_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `product_id` (`product_id`),
        KEY `adjustment_type` (`adjustment_type`),
        KEY `created_at` (`created_at`),
        CONSTRAINT `product_stock_adjustments_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
        CONSTRAINT `product_stock_adjustments_ibfk_2` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "SELECT 'Table product_stock_adjustments already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create product_bulk_operations_log table
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'product_bulk_operations_log') = 0,
    "CREATE TABLE `product_bulk_operations_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `operation_type` varchar(50) NOT NULL,
        `affected_products_count` int(11) NOT NULL,
        `parameters` text,
        `performed_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `operation_type` (`operation_type`),
        KEY `created_at` (`created_at`),
        CONSTRAINT `product_bulk_operations_log_ibfk_1` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "SELECT 'Table product_bulk_operations_log already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Insert PC component categories with hierarchy
INSERT IGNORE INTO categories (name, slug, description, parent_id, sort_order) VALUES
-- Main categories
('CPUs', 'cpus', 'Central Processing Units', NULL, 1),
('GPUs', 'gpus', 'Graphics Processing Units', NULL, 2),
('Motherboards', 'motherboards', 'Computer Motherboards', NULL, 3),
('RAM', 'ram', 'Random Access Memory', NULL, 4),
('Storage', 'storage', 'Storage Devices', NULL, 5),
('Power Supplies', 'power-supplies', 'Power Supply Units', NULL, 6),
('Cases', 'cases', 'Computer Cases', NULL, 7),
('Cooling', 'cooling', 'Cooling Solutions', NULL, 8),
('Peripherals', 'peripherals', 'Computer Peripherals', NULL, 9);

-- CPU subcategories
SET @cpu_id = (SELECT id FROM categories WHERE slug = 'cpus' LIMIT 1);
INSERT IGNORE INTO categories (name, slug, description, parent_id, sort_order) VALUES
('Intel CPUs', 'intel-cpus', 'Intel Processors', @cpu_id, 1),
('AMD CPUs', 'amd-cpus', 'AMD Processors', @cpu_id, 2);

-- Motherboard subcategories
SET @mb_id = (SELECT id FROM categories WHERE slug = 'motherboards' LIMIT 1);
INSERT IGNORE INTO categories (name, slug, description, parent_id, sort_order) VALUES
('ATX Motherboards', 'atx-motherboards', 'ATX Form Factor', @mb_id, 1),
('Micro-ATX Motherboards', 'micro-atx-motherboards', 'Micro-ATX Form Factor', @mb_id, 2),
('Mini-ITX Motherboards', 'mini-itx-motherboards', 'Mini-ITX Form Factor', @mb_id, 3);

-- RAM subcategories
SET @ram_id = (SELECT id FROM categories WHERE slug = 'ram' LIMIT 1);
INSERT IGNORE INTO categories (name, slug, description, parent_id, sort_order) VALUES
('DDR4 RAM', 'ddr4-ram', 'DDR4 Memory Modules', @ram_id, 1),
('DDR5 RAM', 'ddr5-ram', 'DDR5 Memory Modules', @ram_id, 2);

-- Storage subcategories
SET @storage_id = (SELECT id FROM categories WHERE slug = 'storage' LIMIT 1);
INSERT IGNORE INTO categories (name, slug, description, parent_id, sort_order) VALUES
('HDD', 'hdd', 'Hard Disk Drives', @storage_id, 1),
('SSD', 'ssd', 'Solid State Drives', @storage_id, 2),
('NVMe', 'nvme', 'NVMe Drives', @storage_id, 3);

-- Update existing products to set discontinued = 0 if NULL
UPDATE products SET discontinued = 0 WHERE discontinued IS NULL;
