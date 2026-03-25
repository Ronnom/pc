-- Migration: Enhance quote financial fields + reservations (idempotent)

-- quotes table enhancements
ALTER TABLE quotes
  ADD COLUMN IF NOT EXISTS discount_type VARCHAR(16) NOT NULL DEFAULT 'percent' AFTER discount_amount,
  ADD COLUMN IF NOT EXISTS discount_value DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER discount_type,
  ADD COLUMN IF NOT EXISTS reserve_serials TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- quote_items table enhancements
ALTER TABLE quote_items
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER product_id,
  ADD COLUMN IF NOT EXISTS is_backorder TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity;

-- Optional serial reservations table (soft reservation until quote expiry)
CREATE TABLE IF NOT EXISTS quote_serial_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_id BIGINT UNSIGNED NOT NULL,
  product_serial_number_id BIGINT UNSIGNED NOT NULL,
  reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_quote_serial_unique (quote_id, product_serial_number_id),
  KEY idx_qsr_quote (quote_id),
  KEY idx_qsr_serial (product_serial_number_id),
  CONSTRAINT fk_qsr_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_qsr_serial FOREIGN KEY (product_serial_number_id) REFERENCES product_serial_numbers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
