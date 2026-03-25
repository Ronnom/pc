-- Safe rename migration: `warranty` -> `warranties`
-- Created: 2026-02-17
-- Usage:
-- 1) Run this file on a development copy to validate: it will create a `warranties` view alias.
-- 2) To perform the actual rename, call the stored procedure: `CALL rename_warranty_to_warranties();`
-- 3) To undo the rename (if required), call: `CALL rename_warranties_to_warranty_undo();`
-- NOTE: RENAME TABLE is atomic but irreversible; test thoroughly in dev/staging first.

DELIMITER $$

-- 0) Create view alias `warranties` if the `warranty` table exists (safe alias while code is updated)
-- Use dynamic SQL to avoid error when `warranty` doesn't exist.
SELECT COUNT(*) INTO @__wcnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty';
SET @__sql = IF(@__wcnt > 0, 'CREATE OR REPLACE VIEW warranties AS SELECT * FROM warranty', 'SELECT "no_op"');
PREPARE __stmt FROM @__sql; EXECUTE __stmt; DEALLOCATE PREPARE __stmt;

-- 1) Procedure to perform rename (idempotent check)
CREATE PROCEDURE rename_warranty_to_warranties()
BEGIN
    DECLARE cnt_parent INT DEFAULT 0;
    DECLARE cnt_target INT DEFAULT 0;

    SELECT COUNT(*) INTO cnt_parent FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty';
    SELECT COUNT(*) INTO cnt_target FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranties';

    IF cnt_parent = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Source table `warranty` does not exist.';
    END IF;

    IF cnt_target > 0 THEN
        -- Already renamed; nothing to do
        SELECT 'Target table `warranties` already exists. No action taken.' AS info;
        LEAVE rename_warranty_to_warranties;
    END IF;

    -- Perform atomic rename
    SET @s = 'RENAME TABLE warranty TO warranties';
    PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

    -- If a view named `warranties` existed earlier, drop it because table now exists
    IF (SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranties') > 0 THEN
        SET @s = 'DROP VIEW IF EXISTS warranties'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT 'Rename completed: `warranty` -> `warranties`' AS info;
END$$

-- 2) Procedure to undo rename (if called on mistake)
CREATE PROCEDURE rename_warranties_to_warranty_undo()
BEGIN
    DECLARE cnt_old INT DEFAULT 0;
    DECLARE cnt_new INT DEFAULT 0;

    SELECT COUNT(*) INTO cnt_new FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranties';
    SELECT COUNT(*) INTO cnt_old FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty';

    IF cnt_new = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Table `warranties` does not exist; cannot undo.';
    END IF;

    IF cnt_old > 0 THEN
        SELECT 'A table named `warranty` already exists; abort undo.' AS info; LEAVE rename_warranties_to_warranty_undo;
    END IF;

    SET @s = 'RENAME TABLE warranties TO warranty'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    SELECT 'Undo completed: `warranties` -> `warranty`' AS info;
END$$

-- Clean up procedure definitions (optional): leave for manual invocation and auditing
-- DROP PROCEDURE IF EXISTS rename_warranty_to_warranties; -- keep for explicit call
-- DROP PROCEDURE IF EXISTS rename_warranties_to_warranty_undo;

DELIMITER ;

-- Guidance:
-- - After calling `CALL rename_warranty_to_warranties();` update application code references (PHP files, includes, queries) from `warranty` to `warranties`.
-- - Verify all foreign keys continue to function (InnoDB maintains FK integrity across RENAME TABLE in the same schema).
-- - Run integration tests and search/replace in the codebase for references to `warranty`.
