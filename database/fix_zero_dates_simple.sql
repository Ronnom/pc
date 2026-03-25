-- fix_zero_dates_simple.sql
-- Simple direct SQL (no stored procedures) to convert zero-dates to NULL
-- Run in MySQL client or phpMyAdmin
--
-- BACKUP FIRST: mysqldump -u root -p pc_pos > backup.sql
-- Then run: mysql -u root -p pc_pos < fix_zero_dates_simple.sql

-- Save original sql_mode and relax it temporarily
SET @OLD_SQL_MODE=@@SQL_MODE;
SET @NEW_SQL_MODE = REPLACE(REPLACE(@@SQL_MODE,'NO_ZERO_DATE',''),'NO_ZERO_IN_DATE','');
SET SQL_MODE=@NEW_SQL_MODE;

-- ============================================================
-- UPDATE all nullable date/datetime/timestamp columns
-- Convert zero-dates to NULL
-- ============================================================

-- Users table
UPDATE `users` SET `last_login` = NULL WHERE `last_login` IN ('0000-00-00','0000-00-00 00:00:00');
UPDATE `users` SET `deleted_at` = NULL WHERE `deleted_at` IN ('0000-00-00','0000-00-00 00:00:00');

-- Suppliers
UPDATE `suppliers` SET `deleted_at` = NULL WHERE `deleted_at` IN ('0000-00-00','0000-00-00 00:00:00');

-- Customers
UPDATE `customers` SET `deleted_at` = NULL WHERE `deleted_at` IN ('0000-00-00','0000-00-00 00:00:00');

-- Products
UPDATE `products` SET `deleted_at` = NULL WHERE `deleted_at` IN ('0000-00-00','0000-00-00 00:00:00');

-- Warranties
UPDATE `warranties` SET `deleted_at` = NULL WHERE `deleted_at` IN ('0000-00-00','0000-00-00 00:00:00');

-- Quotes
UPDATE `quotes` SET `emailed_at` = NULL WHERE `emailed_at` IN ('0000-00-00','0000-00-00 00:00:00');
UPDATE `quotes` SET `converted_at` = NULL WHERE `converted_at` IN ('0000-00-00','0000-00-00 00:00:00');

-- ============================================================
-- ALTER columns to be explicitly NULL DEFAULT NULL
-- ============================================================

ALTER TABLE `users` MODIFY `last_login` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `users` MODIFY `deleted_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `suppliers` MODIFY `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `customers` MODIFY `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `products` MODIFY `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `warranties` MODIFY `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `quotes` MODIFY `emailed_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `quotes` MODIFY `converted_at` DATETIME NULL DEFAULT NULL;

-- ============================================================
-- Restore original sql_mode
-- ============================================================

SET SQL_MODE=@OLD_SQL_MODE;

-- Done
SELECT 'Zero-dates conversion complete!' AS status;
