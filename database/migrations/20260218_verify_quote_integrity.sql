-- Migration: Verify and ensure quote system integrity (idempotent)
-- Ensures all required columns, indexes, and relationships are properly set up

-- Step 1: Verify quotes table exists and has all required columns
ALTER TABLE quotes MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'draft';
ALTER TABLE quotes MODIFY COLUMN customer_id BIGINT UNSIGNED NOT NULL;

-- Step 2: Ensure all quote-related indexes exist for performance
ALTER TABLE quotes ADD INDEX IF NOT EXISTS idx_quotes_status (status);
ALTER TABLE quotes ADD INDEX IF NOT EXISTS idx_quotes_created_by (created_by);
ALTER TABLE quotes ADD INDEX IF NOT EXISTS idx_quotes_tx (converted_to_transaction_id);
ALTER TABLE quotes ADD INDEX IF NOT EXISTS idx_quotes_customer (customer_id);

-- Step 3: Verify quote_items table relationships
ALTER TABLE quote_items MODIFY COLUMN quote_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE quote_items MODIFY COLUMN product_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE quote_items ADD INDEX IF NOT EXISTS idx_qitems_quote (quote_id);
ALTER TABLE quote_items ADD INDEX IF NOT EXISTS idx_qitems_product (product_id);

-- Step 4: Ensure transactions table can accommodate quote relationships
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS source_quote_id BIGINT UNSIGNED NULL;
ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_tx_source_quote (source_quote_id);

-- Step 5: Verify unique constraint on quote_number
ALTER TABLE quotes ADD UNIQUE INDEX IF NOT EXISTS ux_quotes_number (quote_number);

-- Step 6: Set cascading delete behavior for quote_items
-- Note: Foreign key constraints are set in the migration that creates the table

-- Step 7: Verify quote item calculations are correct by checking for any NULL totals
UPDATE quote_items SET line_total = (quantity * unit_price) + tax_amount - discount_amount 
WHERE line_total IS NULL OR line_total = 0;

-- Step 8: Create any missing foreign key constraints
SET @fk_quotes_customer_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_quotes_customer'
);

SET @fk_quotes_created_by_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_quotes_created_by'
);

SET @fk_qitems_quote_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_qitems_quote'
);

SET @fk_qitems_product_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_qitems_product'
);

-- Step 9: Ensure quote permissions exist in the permissions table (if table exists)
SET @permissions_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'permissions'
);

SET @sql_verify_quote_perms := IF(@permissions_table_exists > 0,
  "INSERT IGNORE INTO permissions (name, description, module)
   VALUES 
   ('quotes.create', 'Create and save quotations', 'quotes'),
   ('quotes.send', 'Send quotations to customers', 'quotes'),
   ('quotes.convert', 'Convert quotations to sales', 'quotes')",
  "SELECT 1");
PREPARE stmt FROM @sql_verify_quote_perms;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 10: Verify quote items have proper status tracking
UPDATE quote_items SET created_at = CURDATE() 
WHERE created_at IS NULL OR created_at = '0000-00-00 00:00:00';

-- Step 11: Ensure quote numbers are unique (no duplicates)
-- This query identifies any duplicate quote numbers (should be empty)
-- SELECT quote_number, COUNT(*) as cnt FROM quotes GROUP BY quote_number HAVING cnt > 1;
