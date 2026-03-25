-- diagnostic_datetime_issues_safe.sql
-- Safe diagnostic - works in strict mode
-- Find which column contains empty string '' values

USE pc_pos;

-- Safer approach: check table existence first
SHOW TABLES;

-- Check column types and nullability
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND DATA_TYPE IN ('date','datetime','timestamp')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Check for NULL vs non-NULL values in key datetime columns (safe in strict mode)
SELECT 'products - NULL check' AS check_name;
SELECT COUNT(*) AS total, 
       SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS null_count,
       SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS not_null_count
FROM products;

SELECT 'users - NULL check' AS check_name;
SELECT COUNT(*) AS total, 
       SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS null_count,
       SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS not_null_count,
       SUM(CASE WHEN last_login IS NULL THEN 1 ELSE 0 END) AS last_login_null,
       SUM(CASE WHEN last_login IS NOT NULL THEN 1 ELSE 0 END) AS last_login_not_null
FROM users;

SELECT 'quotes - NULL check' AS check_name;
SELECT COUNT(*) AS total, 
       SUM(CASE WHEN emailed_at IS NULL THEN 1 ELSE 0 END) AS emailed_at_null,
       SUM(CASE WHEN converted_at IS NULL THEN 1 ELSE 0 END) AS converted_at_null
FROM quotes;

-- Show sample rows from key tables
SELECT 'Sample users rows:' AS info;
SELECT id, username, deleted_at, last_login FROM users LIMIT 3;

SELECT 'Sample products rows:' AS info;
SELECT id, sku, name, deleted_at FROM products LIMIT 3;

SELECT 'Sample quotes rows:' AS info;
SELECT id, quote_number, emailed_at, converted_at FROM quotes LIMIT 3;
