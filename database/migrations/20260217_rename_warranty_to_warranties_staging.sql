-- Staging-friendly rename: warranty -> warranties
-- Created: 2026-02-17
-- This script is safe to run non-interactively. It:
--  1) Creates a `warranties` view alias if `warranty` exists (safe no-op otherwise)
--  2) Drops any `warranties` view to allow an atomic RENAME TABLE
--  3) Conditionally performs `RENAME TABLE warranty TO warranties` only when
--     a `warranty` base table exists and `warranties` does not exist as a table
-- Usage (PowerShell):
--   & "C:\\xampp\\mysql\\bin\\mysql.exe" -u <user> -p -D <staging_db> < 20260217_rename_warranty_to_warranties_staging.sql

-- 0) Create an alias view (so code can read plural name during rollout)
SET @cnt = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty');
SET @sql = IF(@cnt > 0, 'CREATE OR REPLACE VIEW warranties AS SELECT * FROM warranty', 'SELECT "no_op"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1) Ensure any existing `warranties` VIEW is removed before rename (safe even if none)
DROP VIEW IF EXISTS warranties;

-- 2) Conditionally perform atomic rename if appropriate
SET @parent = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty');
SET @target = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranties' AND TABLE_TYPE = 'BASE TABLE');
SET @do_rename_sql = IF(@parent = 1 AND @target = 0, 'RENAME TABLE warranty TO warranties', 'SELECT "rename_skipped_or_already"');
PREPARE stmt2 FROM @do_rename_sql; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- 3) Final sanity: report current state
SELECT 'post_rename_tables' AS stage, TABLE_NAME, TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('warranty','warranties');

-- End of script
