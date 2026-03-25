-- Migration: roles linkage + returns module (header/detail model)
-- Compatible with existing installs that may still have legacy `returns` schema.

-- 1) Link users to roles via role_id (safe/idempotent)
SET @users_has_role_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'role_id'
);
SET @sql_add_users_role_id := IF(@users_has_role_id = 0,
  'ALTER TABLE users ADD COLUMN role_id INT UNSIGNED NULL AFTER password_hash',
  'SELECT 1');
PREPARE stmt FROM @sql_add_users_role_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_users_role_id_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_role_id'
);
SET @sql_idx_users_role_id := IF(@idx_users_role_id_exists = 0,
  'ALTER TABLE users ADD INDEX idx_users_role_id (role_id)',
  'SELECT 1');
PREPARE stmt FROM @sql_idx_users_role_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_users_role_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_user_role'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_users_role := IF(@fk_users_role_exists = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql_fk_users_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Returns header table (new shape)
CREATE TABLE IF NOT EXISTS returns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  return_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_refund_amount DECIMAL(15,2) NOT NULL,
  restocking_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_returns_transaction (transaction_id),
  KEY idx_returns_user (user_id),
  CONSTRAINT fk_returns_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_returns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Returns detail table
CREATE TABLE IF NOT EXISTS return_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id BIGINT UNSIGNED NOT NULL,
  transaction_item_id BIGINT UNSIGNED NOT NULL,
  product_serial_number_id BIGINT UNSIGNED NULL,
  quantity INT NOT NULL,
  reason VARCHAR(255) NULL,
  condition_note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_ri_return (return_id),
  KEY idx_ri_transaction_item (transaction_item_id),
  KEY idx_ri_product_serial (product_serial_number_id),
  CONSTRAINT fk_ri_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_item FOREIGN KEY (transaction_item_id) REFERENCES transaction_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ri_serial FOREIGN KEY (product_serial_number_id) REFERENCES product_serial_numbers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Legacy returns upgrade path
SET @returns_has_product_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND COLUMN_NAME = 'product_id'
);

-- Add missing new columns one-by-one (version-safe)
SET @returns_has_user_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND COLUMN_NAME = 'user_id'
);
SET @sql_add_returns_user_id := IF(@returns_has_user_id = 0,
  'ALTER TABLE returns ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER transaction_id',
  'SELECT 1');
PREPARE stmt FROM @sql_add_returns_user_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @returns_has_return_date := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND COLUMN_NAME = 'return_date'
);
SET @sql_add_returns_return_date := IF(@returns_has_return_date = 0,
  'ALTER TABLE returns ADD COLUMN return_date DATETIME NULL AFTER user_id',
  'SELECT 1');
PREPARE stmt FROM @sql_add_returns_return_date;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @returns_has_total_refund := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND COLUMN_NAME = 'total_refund_amount'
);
SET @sql_add_returns_total_refund := IF(@returns_has_total_refund = 0,
  'ALTER TABLE returns ADD COLUMN total_refund_amount DECIMAL(15,2) NULL AFTER return_date',
  'SELECT 1');
PREPARE stmt FROM @sql_add_returns_total_refund;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @returns_has_restocking_fee := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND COLUMN_NAME = 'restocking_fee'
);
SET @sql_add_returns_restocking_fee := IF(@returns_has_restocking_fee = 0,
  'ALTER TABLE returns ADD COLUMN restocking_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER total_refund_amount',
  'SELECT 1');
PREPARE stmt FROM @sql_add_returns_restocking_fee;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fallback_user_id := (SELECT id FROM users ORDER BY id LIMIT 1);

-- Backfill from legacy rows only when legacy shape is detected.
SET @sql_backfill_legacy_returns := IF(@returns_has_product_id > 0,
  'UPDATE returns
   SET user_id = COALESCE(user_id, created_by, @fallback_user_id),
       return_date = COALESCE(return_date, created_at, NOW()),
       total_refund_amount = COALESCE(total_refund_amount, refund_amount, 0.00),
       restocking_fee = COALESCE(restocking_fee, 0.00)',
  'SELECT 1');
PREPARE stmt FROM @sql_backfill_legacy_returns;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill return_items from legacy returns when mapped transaction item exists.
SET @sql_backfill_legacy_return_items := IF(@returns_has_product_id > 0,
  'INSERT INTO return_items (return_id, transaction_item_id, product_serial_number_id, quantity, reason, condition_note)
   SELECT
     r.id,
     (
       SELECT ti.id
       FROM transaction_items ti
       WHERE ti.transaction_id = r.transaction_id
         AND ti.product_id = r.product_id
       ORDER BY ti.id
       LIMIT 1
     ) AS transaction_item_id,
     NULL,
     COALESCE(r.quantity, 0),
     r.return_reason,
     r.notes
   FROM returns r
   LEFT JOIN return_items ri ON ri.return_id = r.id
   WHERE ri.id IS NULL
     AND EXISTS (
       SELECT 1
       FROM transaction_items ti2
       WHERE ti2.transaction_id = r.transaction_id
         AND ti2.product_id = r.product_id
     )',
  'SELECT 1');
PREPARE stmt FROM @sql_backfill_legacy_return_items;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop legacy FK/index/columns safely.
SET @fk_returns_product_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND CONSTRAINT_NAME = 'fk_returns_product'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_returns_product := IF(@fk_returns_product_exists > 0,
  'ALTER TABLE returns DROP FOREIGN KEY fk_returns_product',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_fk_returns_product;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_returns_created_by_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND CONSTRAINT_NAME = 'fk_returns_created_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_returns_created_by := IF(@fk_returns_created_by_exists > 0,
  'ALTER TABLE returns DROP FOREIGN KEY fk_returns_created_by',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_fk_returns_created_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_returns_product_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND INDEX_NAME = 'idx_returns_product'
);
SET @sql_drop_idx_returns_product := IF(@idx_returns_product_exists > 0,
  'ALTER TABLE returns DROP INDEX idx_returns_product',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_idx_returns_product;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_returns_status_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND INDEX_NAME = 'idx_returns_status'
);
SET @sql_drop_idx_returns_status := IF(@idx_returns_status_exists > 0,
  'ALTER TABLE returns DROP INDEX idx_returns_status',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_idx_returns_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_returns_created_by_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND INDEX_NAME = 'idx_returns_created_by'
);
SET @sql_drop_idx_returns_created_by := IF(@idx_returns_created_by_exists > 0,
  'ALTER TABLE returns DROP INDEX idx_returns_created_by',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_idx_returns_created_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop legacy columns one-by-one to avoid hard failure if missing.
SET @col_returns_return_number_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'return_number'
);
SET @sql_drop_col_return_number := IF(@col_returns_return_number_exists > 0,
  'ALTER TABLE returns DROP COLUMN return_number',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_return_number;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_returns_product_id_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'product_id'
);
SET @sql_drop_col_product_id := IF(@col_returns_product_id_exists > 0,
  'ALTER TABLE returns DROP COLUMN product_id',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_product_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_returns_quantity_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'quantity'
);
SET @sql_drop_col_quantity := IF(@col_returns_quantity_exists > 0,
  'ALTER TABLE returns DROP COLUMN quantity',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_quantity;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_returns_reason_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'return_reason'
);
SET @sql_drop_col_return_reason := IF(@col_returns_reason_exists > 0,
  'ALTER TABLE returns DROP COLUMN return_reason',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_return_reason;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_returns_refund_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'refund_amount'
);
SET @sql_drop_col_refund_amount := IF(@col_returns_refund_exists > 0,
  'ALTER TABLE returns DROP COLUMN refund_amount',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_refund_amount;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_returns_status_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'status'
);
SET @sql_drop_col_status := IF(@col_returns_status_exists > 0,
  'ALTER TABLE returns DROP COLUMN status',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_returns_notes_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'notes'
);
SET @sql_drop_col_notes := IF(@col_returns_notes_exists > 0,
  'ALTER TABLE returns DROP COLUMN notes',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_notes;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_returns_created_by_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'created_by'
);
SET @sql_drop_col_created_by := IF(@col_returns_created_by_exists > 0,
  'ALTER TABLE returns DROP COLUMN created_by',
  'SELECT 1');
PREPARE stmt FROM @sql_drop_col_created_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Final constraints for new shape.
ALTER TABLE returns
  MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL,
  MODIFY COLUMN return_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN total_refund_amount DECIMAL(15,2) NOT NULL;

SET @fk_returns_user_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'returns'
    AND CONSTRAINT_NAME = 'fk_returns_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_returns_user := IF(@fk_returns_user_exists = 0,
  'ALTER TABLE returns ADD CONSTRAINT fk_returns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql_fk_returns_user;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
