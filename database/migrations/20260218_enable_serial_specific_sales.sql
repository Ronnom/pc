-- Migration: Enable serial-specific sales, returns, and cost tracking
-- Safe/idempotent for existing installs.

-- 1) Add per-unit serial link + captured serial cost to transaction_items
SET @ti_has_serial_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transaction_items'
    AND COLUMN_NAME = 'product_serial_number_id'
);
SET @sql_add_ti_serial_id := IF(@ti_has_serial_id = 0,
  'ALTER TABLE transaction_items ADD COLUMN product_serial_number_id BIGINT UNSIGNED NULL AFTER product_id',
  'SELECT 1');
PREPARE stmt FROM @sql_add_ti_serial_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ti_has_serial_cost := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transaction_items'
    AND COLUMN_NAME = 'serial_cost_price'
);
SET @sql_add_ti_serial_cost := IF(@ti_has_serial_cost = 0,
  'ALTER TABLE transaction_items ADD COLUMN serial_cost_price DECIMAL(15,2) NULL AFTER unit_price',
  'SELECT 1');
PREPARE stmt FROM @sql_add_ti_serial_cost;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_ti_serial_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transaction_items'
    AND INDEX_NAME = 'idx_titems_serial'
);
SET @sql_add_idx_ti_serial := IF(@idx_ti_serial_exists = 0,
  'ALTER TABLE transaction_items ADD INDEX idx_titems_serial (product_serial_number_id)',
  'SELECT 1');
PREPARE stmt FROM @sql_add_idx_ti_serial;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_ti_serial_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transaction_items'
    AND CONSTRAINT_NAME = 'fk_titems_serial'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_ti_serial := IF(@fk_ti_serial_exists = 0,
  'ALTER TABLE transaction_items
     ADD CONSTRAINT fk_titems_serial
     FOREIGN KEY (product_serial_number_id) REFERENCES product_serial_numbers(id)
     ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql_add_fk_ti_serial;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Add stocked-in unit cost snapshot to product serials
SET @psn_has_stocked_cost := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_serial_numbers'
    AND COLUMN_NAME = 'stocked_cost_price'
);
SET @sql_add_psn_stocked_cost := IF(@psn_has_stocked_cost = 0,
  'ALTER TABLE product_serial_numbers ADD COLUMN stocked_cost_price DECIMAL(15,2) NULL AFTER transaction_id',
  'SELECT 1');
PREPARE stmt FROM @sql_add_psn_stocked_cost;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Backfill serial cost from products for existing in-stock/sold serial rows when missing
UPDATE product_serial_numbers psn
JOIN products p ON p.id = psn.product_id
SET psn.stocked_cost_price = p.cost_price
WHERE psn.stocked_cost_price IS NULL;

-- 4) Backfill transaction item serial cost where serial is already linked
UPDATE transaction_items ti
JOIN product_serial_numbers psn ON psn.id = ti.product_serial_number_id
SET ti.serial_cost_price = psn.stocked_cost_price
WHERE ti.serial_cost_price IS NULL;
