-- Migration: Warranty Claims Management enhancements (idempotent)
-- Adds claim_type, warranty validation, void checklist, RMA tracking, cost tracking, and inventory linkage.

SET @schema := DATABASE();

-- Ensure warranty_claims table exists
CREATE TABLE IF NOT EXISTS warranty_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warranty_id BIGINT UNSIGNED NOT NULL,
  claim_date DATE NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_warranty_claims_warranty (warranty_id),
  KEY idx_warranty_claims_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add columns (if missing)
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'original_invoice_id');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN original_invoice_id VARCHAR(64) NULL AFTER claim_type", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'original_purchase_date');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN original_purchase_date DATE NULL AFTER original_invoice_id", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'in_warranty');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN in_warranty TINYINT(1) NOT NULL DEFAULT 0 AFTER original_purchase_date", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Void checklist flags
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'void_liquid_damage');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN void_liquid_damage TINYINT(1) NOT NULL DEFAULT 0 AFTER in_warranty", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'void_tampered_seal');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN void_tampered_seal TINYINT(1) NOT NULL DEFAULT 0 AFTER void_liquid_damage", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'void_physical_damage');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN void_physical_damage TINYINT(1) NOT NULL DEFAULT 0 AFTER void_tampered_seal", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'void_serial_label');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN void_serial_label TINYINT(1) NOT NULL DEFAULT 0 AFTER void_physical_damage", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- RMA tracking
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'manufacturer_case_number');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN manufacturer_case_number VARCHAR(100) NULL AFTER void_serial_label", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'outbound_tracking_number');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN outbound_tracking_number VARCHAR(100) NULL AFTER manufacturer_case_number", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'inbound_tracking_number');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN inbound_tracking_number VARCHAR(100) NULL AFTER outbound_tracking_number", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Financial handling
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'zero_cost');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN zero_cost TINYINT(1) NOT NULL DEFAULT 1 AFTER inbound_tracking_number", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'internal_labor_cost');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN internal_labor_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER zero_cost", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'internal_parts_cost');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN internal_parts_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER internal_labor_cost", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'internal_total_cost');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN internal_total_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER internal_parts_cost", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Inventory link
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'replacement_serial_id');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN replacement_serial_id BIGINT UNSIGNED NULL AFTER internal_total_cost", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'return_defective_required');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN return_defective_required TINYINT(1) NOT NULL DEFAULT 1 AFTER replacement_serial_id", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'return_defective_due');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN return_defective_due DATE NULL AFTER return_defective_required", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Claim details
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'claim_reason');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN claim_reason TEXT NULL AFTER return_defective_due", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'symptom_category');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN symptom_category VARCHAR(50) NULL AFTER claim_reason", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'resolution_action');
SET @sql := IF(@cols = 0, "ALTER TABLE warranty_claims ADD COLUMN resolution_action VARCHAR(32) NULL AFTER symptom_category", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop claim_type if present (single shop warranty only)
SET @cols := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'warranty_claims' AND COLUMN_NAME = 'claim_type');
SET @sql := IF(@cols > 0, "ALTER TABLE warranty_claims DROP COLUMN claim_type", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Inventory movement log
CREATE TABLE IF NOT EXISTS warranty_claim_inventory_moves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warranty_claim_id BIGINT UNSIGNED NOT NULL,
  product_serial_id BIGINT UNSIGNED NULL,
  from_location VARCHAR(100) NULL,
  to_location VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wcim_claim (warranty_claim_id),
  KEY idx_wcim_serial (product_serial_id),
  CONSTRAINT fk_wcim_claim FOREIGN KEY (warranty_claim_id) REFERENCES warranty_claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
