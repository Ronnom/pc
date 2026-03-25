-- Migration: Add brand column to products (idempotent)

SET @schema := DATABASE();
SET @has_brand := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'brand'
);
SET @sql := IF(@has_brand = 0,
  "ALTER TABLE products ADD COLUMN brand VARCHAR(150) NULL AFTER supplier_id",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
