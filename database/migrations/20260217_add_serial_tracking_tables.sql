-- ============================================
-- Add Serial Tracking Tables (Safe Migration)
-- Single-Location Mode: In-Store Only
-- Date: 2026-02-17
-- ============================================

START TRANSACTION;

-- Keep a location table for FK integrity, but enforce one logical location (id=1).
CREATE TABLE IF NOT EXISTS `stock_locations` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `name` varchar(100) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `address` text,
  `is_active` tinyint(1) DEFAULT 1,
  `store_address` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `single_location_check` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backward-compat columns for existing deployments using older stock location queries.
ALTER TABLE `stock_locations` ADD COLUMN IF NOT EXISTS `name` varchar(100) DEFAULT NULL;
ALTER TABLE `stock_locations` ADD COLUMN IF NOT EXISTS `code` varchar(50) DEFAULT NULL;
ALTER TABLE `stock_locations` ADD COLUMN IF NOT EXISTS `address` text;
ALTER TABLE `stock_locations` ADD COLUMN IF NOT EXISTS `is_active` tinyint(1) DEFAULT 1;
ALTER TABLE `stock_locations` ADD COLUMN IF NOT EXISTS `store_address` text NOT NULL;

-- Seed/refresh single store location.
INSERT INTO `stock_locations` (`id`, `name`, `code`, `address`, `is_active`, `store_address`)
VALUES (1, 'Main Store', 'STORE', 'In-store inventory location', 1, 'In-store inventory location')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `code` = VALUES(`code`),
  `address` = VALUES(`address`),
  `is_active` = VALUES(`is_active`),
  `store_address` = VALUES(`store_address`);

-- Serial table aligned to normalized_schema_revised.sql key types (BIGINT UNSIGNED).
CREATE TABLE IF NOT EXISTS `product_serial_numbers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `serial_number` varchar(100) NOT NULL,
  `location_id` tinyint unsigned NOT NULL DEFAULT 1,
  `status` enum('in_stock','sold','returned','damaged','stolen') DEFAULT 'in_stock',
  `transaction_id` BIGINT UNSIGNED DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `serial_number` (`serial_number`),
  KEY `product_id` (`product_id`),
  KEY `location_id` (`location_id`),
  KEY `status` (`status`),
  KEY `transaction_id` (`transaction_id`),
  CONSTRAINT `product_serial_numbers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_serial_numbers_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `stock_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `product_serial_numbers_ibfk_3` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For existing rows, keep all serials pinned to the single store location.
UPDATE `product_serial_numbers`
SET `location_id` = 1
WHERE `location_id` IS NULL OR `location_id` <> 1;

COMMIT;
