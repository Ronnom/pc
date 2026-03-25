-- diagnostic_datetime_issues.sql
-- Find which column contains empty string '' values (not NULL, not '0000-00-00')

USE pc_pos;

-- Check each main table for empty strings in datetime columns
SELECT 'users.created_at' AS location, COUNT(*) AS cnt FROM users WHERE created_at = '';
SELECT 'users.updated_at' AS location, COUNT(*) AS cnt FROM users WHERE updated_at = '';
SELECT 'users.last_login' AS location, COUNT(*) AS cnt FROM users WHERE last_login = '';
SELECT 'users.deleted_at' AS location, COUNT(*) AS cnt FROM users WHERE deleted_at = '';

SELECT 'products.created_at' AS location, COUNT(*) AS cnt FROM products WHERE created_at = '';
SELECT 'products.updated_at' AS location, COUNT(*) AS cnt FROM products WHERE updated_at = '';
SELECT 'products.deleted_at' AS location, COUNT(*) AS cnt FROM products WHERE deleted_at = '';

SELECT 'customers.created_at' AS location, COUNT(*) AS cnt FROM customers WHERE created_at = '';
SELECT 'customers.updated_at' AS location, COUNT(*) AS cnt FROM customers WHERE updated_at = '';
SELECT 'customers.deleted_at' AS location, COUNT(*) AS cnt FROM customers WHERE deleted_at = '';

SELECT 'suppliers.created_at' AS location, COUNT(*) AS cnt FROM suppliers WHERE created_at = '';
SELECT 'suppliers.updated_at' AS location, COUNT(*) AS cnt FROM suppliers WHERE updated_at = '';
SELECT 'suppliers.deleted_at' AS location, COUNT(*) AS cnt FROM suppliers WHERE deleted_at = '';

SELECT 'quotes.created_at' AS location, COUNT(*) AS cnt FROM quotes WHERE created_at = '';
SELECT 'quotes.updated_at' AS location, COUNT(*) AS cnt FROM quotes WHERE updated_at = '';
SELECT 'quotes.emailed_at' AS location, COUNT(*) AS cnt FROM quotes WHERE emailed_at = '';
SELECT 'quotes.converted_at' AS location, COUNT(*) AS cnt FROM quotes WHERE converted_at = '';

SELECT 'transactions.created_at' AS location, COUNT(*) AS cnt FROM transactions WHERE created_at = '';
SELECT 'transactions.updated_at' AS location, COUNT(*) AS cnt FROM transactions WHERE updated_at = '';

SELECT 'warranty_claims.created_at' AS location, COUNT(*) AS cnt FROM warranty_claims WHERE created_at = '';
SELECT 'warranty_claims.updated_at' AS location, COUNT(*) AS cnt FROM warranty_claims WHERE updated_at = '';

-- List ALL columns that are DATETIME/DATE/TIMESTAMP in DB to check for others
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND DATA_TYPE IN ('date','datetime','timestamp')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
