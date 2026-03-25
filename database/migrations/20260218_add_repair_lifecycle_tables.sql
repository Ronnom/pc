-- Migration: Extend repairs lifecycle (intake, diagnostics, QC, labor, media)
-- Idempotent for existing installs.

SET @schema := DATABASE();

-- Add columns to repairs (if missing)
SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'device_type'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN device_type VARCHAR(50) NULL AFTER product_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'brand'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN brand VARCHAR(100) NULL AFTER device_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'model'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN model VARCHAR(100) NULL AFTER brand', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'imei'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN imei VARCHAR(50) NULL AFTER serial_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'preferred_contact'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN preferred_contact VARCHAR(20) NULL AFTER customer_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'pre_repair_inspection'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN pre_repair_inspection TEXT NULL AFTER imei', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'customer_reported_issue'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN customer_reported_issue TEXT NULL AFTER pre_repair_inspection', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'technician_diagnosis'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN technician_diagnosis TEXT NULL AFTER customer_reported_issue', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'warranty_status'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN warranty_status VARCHAR(20) NOT NULL DEFAULT \"billable\" AFTER technician_diagnosis', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'initial_quote'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN initial_quote DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER warranty_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'parts_total'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN parts_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER initial_quote', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'labor_total'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN labor_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER parts_total', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repairs' AND COLUMN_NAME = 'final_total'
);
SET @sql := IF(@cols = 0, 'ALTER TABLE repairs ADD COLUMN final_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER labor_total', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Accessories log
CREATE TABLE IF NOT EXISTS repair_accessories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  accessory_name VARCHAR(100) NOT NULL,
  present TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_ra_repair (repair_id),
  CONSTRAINT fk_repair_accessories_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Work log
CREATE TABLE IF NOT EXISTS repair_work_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rwl_repair (repair_id),
  CONSTRAINT fk_rwl_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC checklist
CREATE TABLE IF NOT EXISTS repair_qc (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  power_tested TINYINT(1) NOT NULL DEFAULT 0,
  stress_test_passed TINYINT(1) NOT NULL DEFAULT 0,
  exterior_cleaned TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_rqc_repair (repair_id),
  CONSTRAINT fk_rqc_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media uploads
CREATE TABLE IF NOT EXISTS repair_media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  media_type ENUM('before','after','internal') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rm_repair (repair_id),
  CONSTRAINT fk_rm_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data loss waiver
CREATE TABLE IF NOT EXISTS repair_waivers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  signed_by VARCHAR(255) NOT NULL,
  signature_text VARCHAR(255) NOT NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rw_repair (repair_id),
  CONSTRAINT fk_rw_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Revised quote approvals
CREATE TABLE IF NOT EXISTS repair_approvals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  revised_quote_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by BIGINT UNSIGNED NULL,
  decided_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ra_repair (repair_id),
  CONSTRAINT fk_repair_approvals_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parts orders with ETA tracking
CREATE TABLE IF NOT EXISTS repair_parts_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  eta_date DATE NULL,
  status ENUM('ordered','received','cancelled') NOT NULL DEFAULT 'ordered',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rpo_repair (repair_id),
  CONSTRAINT fk_repair_parts_orders_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QC extension (if columns missing)
SET @schema := DATABASE();
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repair_qc' AND COLUMN_NAME = 'wifi_tested');
SET @sql := IF(@cols = 0, 'ALTER TABLE repair_qc ADD COLUMN wifi_tested TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repair_qc' AND COLUMN_NAME = 'ports_tested');
SET @sql := IF(@cols = 0, 'ALTER TABLE repair_qc ADD COLUMN ports_tested TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repair_qc' AND COLUMN_NAME = 'stress_test_minutes');
SET @sql := IF(@cols = 0, 'ALTER TABLE repair_qc ADD COLUMN stress_test_minutes INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'repair_qc' AND COLUMN_NAME = 'cleaning_done');
SET @sql := IF(@cols = 0, 'ALTER TABLE repair_qc ADD COLUMN cleaning_done TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
