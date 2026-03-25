-- Migration: Add price_history table (idempotent)
CREATE TABLE IF NOT EXISTS price_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  old_price DECIMAL(15,2) DEFAULT NULL,
  new_price DECIMAL(15,2) DEFAULT NULL,
  price_type VARCHAR(32) NOT NULL,
  changed_by BIGINT UNSIGNED DEFAULT NULL,
  reason TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_price_history_product (product_id),
  KEY idx_price_history_changed_by (changed_by),
  CONSTRAINT fk_price_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
