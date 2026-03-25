-- Add product metadata columns, product_images and product_specifications tables
-- Safe, idempotent migration for adding fields required by product_form.php
-- Created: 2026-02-17

START TRANSACTION;

-- 1) Add commonly-needed product columns if missing
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `brand` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `model` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `serial_number` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `min_stock_level` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reorder_level` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `max_stock_level` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `barcode` VARCHAR(128) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_taxable` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `markup_percentage` DECIMAL(5,2) DEFAULT NULL;

-- 2) Ensure a selling price column exists (some deployments use `selling_price`)
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `selling_price` DECIMAL(15,2) DEFAULT NULL;

-- Backfill `selling_price` from `sell_price` if empty
UPDATE `products` SET `selling_price` = `sell_price` WHERE `selling_price` IS NULL AND `sell_price` IS NOT NULL;

-- 3) Product specifications table (key/value pairs for dynamic specs)
CREATE TABLE IF NOT EXISTS `product_specifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `spec_key` VARCHAR(150) NOT NULL,
  `spec_value` TEXT NOT NULL,
  `spec_group` VARCHAR(100) DEFAULT 'General',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ps_product` (`product_id`),
  CONSTRAINT `fk_ps_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Product images table
CREATE TABLE IF NOT EXISTS `product_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `image_path` VARCHAR(1024) NOT NULL,
  `alt_text` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pi_product` (`product_id`),
  CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pi_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Optional: ensure product_serial_numbers exists (created by other migration); create minimal if missing
CREATE TABLE IF NOT EXISTS `product_serial_numbers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `serial_number` VARCHAR(255) NOT NULL,
  `status` ENUM('in_stock','sold','returned','damaged','stolen') DEFAULT 'in_stock',
  `location_id` TINYINT UNSIGNED DEFAULT 1,
  `transaction_id` BIGINT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_psn_serial` (`serial_number`),
  KEY `idx_psn_product` (`product_id`),
  CONSTRAINT `fk_psn_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Add helpful indexes
ALTER TABLE `products` 
  ADD INDEX IF NOT EXISTS `idx_products_name` (`name`(191)),
  ADD INDEX IF NOT EXISTS `idx_products_sku` (`sku`(64)),
  ADD INDEX IF NOT EXISTS `idx_products_barcode` (`barcode`(64));

COMMIT;

-- End of migration
