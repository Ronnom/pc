-- Dev-safe migration: add indexes, generated columns, view, constraints, and tracking columns
-- Created: 2026-02-17
-- This migration is idempotent and includes an UP and DOWN procedure.
-- Run the file in a MySQL 8+ dev environment. Review before applying on production.

DELIMITER $$

/*
UP: apply changes
*/
-- Ensure previous procedure definitions removed so migration is idempotent
DROP PROCEDURE IF EXISTS migrate_dev_safe_up;
DROP PROCEDURE IF EXISTS migrate_dev_safe_down;

CREATE PROCEDURE migrate_dev_safe_up()
BEGIN
    DECLARE cnt INT DEFAULT 0;

    -- 1) Create `warranties` view if not exists and if `warranty` table exists (safe alias to avoid immediate rename)
    SELECT COUNT(*) INTO cnt
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty';
    IF cnt > 0 THEN
        SELECT COUNT(*) INTO cnt
        FROM information_schema.VIEWS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranties';
        IF cnt = 0 THEN
            SET @s = 'CREATE OR REPLACE VIEW warranties AS SELECT * FROM warranty';
            PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;

    -- 2) Add CHECK constraints (MySQL 8+). Guarded by checking TABLE_CONSTRAINTS.
    -- Add price/cost check only if products table and columns exist
    SELECT COUNT(*) INTO cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price';
    IF cnt > 0 THEN
        SELECT COUNT(*) INTO cnt
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND CONSTRAINT_NAME = 'chk_price_nonneg';
        IF cnt = 0 THEN
            SET @s = 'ALTER TABLE products ADD CONSTRAINT chk_price_nonneg CHECK (price >= 0 AND cost >= 0)';
            PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;

    -- Add quantity check only if stock_movements and column exists
    SELECT COUNT(*) INTO cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'quantity';
    IF cnt > 0 THEN
        SELECT COUNT(*) INTO cnt
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND CONSTRAINT_NAME = 'chk_quantity_nonneg';
        IF cnt = 0 THEN
            SET @s = 'ALTER TABLE stock_movements ADD CONSTRAINT chk_quantity_nonneg CHECK (quantity >= 0)';
            PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;

    -- 3) Add composite indexes if missing
    SELECT COUNT(*) INTO cnt
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_status_date';
    IF cnt = 0 THEN
        SET @s = 'ALTER TABLE transactions ADD INDEX idx_status_date (status, transaction_date)';
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_user_date';
    IF cnt = 0 THEN
        SET @s = 'ALTER TABLE transactions ADD INDEX idx_user_date (user_id, transaction_date)';
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND INDEX_NAME = 'idx_transaction_product';
    IF cnt = 0 THEN
        SET @s = 'ALTER TABLE transaction_items ADD INDEX idx_transaction_product (transaction_id, product_id)';
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- 4) Fulltext index on products.name if missing
    SELECT COUNT(*) INTO cnt
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_ft_name';
    IF cnt = 0 THEN
        SET @s = 'ALTER TABLE products ADD FULLTEXT INDEX idx_ft_name (name)';
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- 5) Add generated columns from JSON `specs` and indexes (guarded by column checks)
    -- Add generated columns from JSON `specs` only if `specs` column exists
    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'specs';
    IF cnt > 0 THEN
        SELECT COUNT(*) INTO cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'model_number';
        IF cnt = 0 THEN
            SET @s = 'ALTER TABLE products 
                 ADD COLUMN model_number VARCHAR(100) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(specs, ''$.model_number''))) VIRTUAL,
                 ADD COLUMN warranty_months INT GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(specs, ''$.warranty_months'')) AS UNSIGNED)) VIRTUAL,
                 ADD INDEX idx_model_number (model_number),
                 ADD INDEX idx_warranty_months (warranty_months)';
            PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;

    -- 6) Add audit/tracking and soft-delete columns to products (if missing)
    -- Add audit/tracking and soft-delete columns to products (if missing)
    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'created_by';
    IF cnt = 0 THEN
        SET @s = 'ALTER TABLE products ADD COLUMN created_by INT NULL'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'updated_by';
    IF cnt = 0 THEN
        SET @s = 'ALTER TABLE products ADD COLUMN updated_by INT NULL'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'deleted_at';
    IF cnt = 0 THEN
        SET @s = 'ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- Add foreign keys for created_by/updated_by (guarded by FK existence and column presence)
    SELECT COUNT(*) INTO cnt FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND CONSTRAINT_NAME = 'fk_products_created_by';
    IF cnt = 0 THEN
        SELECT COUNT(*) INTO cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'created_by';
        IF cnt > 0 THEN
            SET @s = 'ALTER TABLE products ADD CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;

    SELECT COUNT(*) INTO cnt FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND CONSTRAINT_NAME = 'fk_products_updated_by';
    IF cnt = 0 THEN
        SELECT COUNT(*) INTO cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'updated_by';
        IF cnt > 0 THEN
            SET @s = 'ALTER TABLE products ADD CONSTRAINT fk_products_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;

    -- 7) Convert suppliers.bank_details to VARBINARY if currently TEXT (to prepare for encrypted storage) - guarded check
    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'bank_details' AND DATA_TYPE != 'varbinary';
    IF cnt > 0 THEN
        -- convert to VARBINARY(2048) if not already varbinary (this will truncate if content longer; review in dev)
        SET @s = 'ALTER TABLE suppliers MODIFY bank_details VARBINARY(2048)';
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

END$$

CALL migrate_dev_safe_up();
DROP PROCEDURE IF EXISTS migrate_dev_safe_up; 

/*
DOWN: reverse the above where reasonably reversible
*/
CREATE PROCEDURE migrate_dev_safe_down()
BEGIN
    DECLARE cnt INT DEFAULT 0;

    -- 1) Drop indexes if present
    SELECT COUNT(*) INTO cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_status_date';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE transactions DROP INDEX idx_status_date'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_user_date';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE transactions DROP INDEX idx_user_date'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND INDEX_NAME = 'idx_transaction_product';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE transaction_items DROP INDEX idx_transaction_product'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_ft_name';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE products DROP INDEX idx_ft_name'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- 2) Drop generated columns and their indexes
    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'model_number';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE products DROP INDEX idx_model_number'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        SET @s = 'ALTER TABLE products DROP INDEX idx_warranty_months'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        SET @s = 'ALTER TABLE products DROP COLUMN model_number, DROP COLUMN warranty_months'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- 3) Drop created_by/updated_by/deleted_at and FK constraints
    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'created_by';
    IF cnt > 0 THEN
        -- drop foreign keys if exist
        SET @s = 'ALTER TABLE products DROP FOREIGN KEY fk_products_created_by';
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END; -- ignore errors
            PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END;
        SET @s = 'ALTER TABLE products DROP FOREIGN KEY fk_products_updated_by';
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END;
        SET @s = 'ALTER TABLE products DROP COLUMN created_by, DROP COLUMN updated_by, DROP COLUMN deleted_at'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- 4) Revert suppliers.bank_details back to TEXT if we changed it
    SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'bank_details' AND DATA_TYPE = 'varbinary';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE suppliers MODIFY bank_details TEXT'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- 5) Drop CHECK constraints (best-effort)
    SELECT COUNT(*) INTO cnt
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND CONSTRAINT_NAME = 'chk_price_nonneg';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE products DROP CHECK chk_price_nonneg'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO cnt
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND CONSTRAINT_NAME = 'chk_quantity_nonneg';
    IF cnt > 0 THEN
        SET @s = 'ALTER TABLE stock_movements DROP CHECK chk_quantity_nonneg'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    -- 6) Drop warranties view if exists
    SELECT COUNT(*) INTO cnt FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranties';
    IF cnt > 0 THEN
        SET @s = 'DROP VIEW IF EXISTS warranties'; PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

END$$

-- CALL migrate_dev_safe_down(); -- Uncomment to run down
DROP PROCEDURE IF EXISTS migrate_dev_safe_down;

DELIMITER ;

-- Notes:
-- - Review the conversion of suppliers.bank_details before applying (this migration converts TEXT -> VARBINARY(2048)).
-- - CHECK constraints require MySQL 8+. If your server ignores CHECK, they will be no-ops.
-- - Generated columns require MySQL 5.7+/8+ depending on JSON support.
-- - Run this migration on a development copy and verify application compatibility before applying to production.
