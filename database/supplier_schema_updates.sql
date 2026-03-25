-- Supplier Management Schema Updates
-- Add missing columns to suppliers table

ALTER TABLE suppliers
ADD COLUMN supplier_code VARCHAR(50) UNIQUE AFTER id,
ADD COLUMN company_name VARCHAR(100) AFTER name,
ADD COLUMN business_reg VARCHAR(50) AFTER tax_id,
ADD COLUMN lead_time_days INT DEFAULT 0 AFTER payment_terms,
ADD COLUMN website VARCHAR(100) AFTER lead_time_days,
ADD COLUMN bank_details TEXT AFTER website,
ADD COLUMN rating TINYINT DEFAULT NULL AFTER bank_details,
ADD COLUMN notes TEXT AFTER rating;

-- Create supplier_ratings table for performance tracking
CREATE TABLE IF NOT EXISTS supplier_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_rating (supplier_id, user_id)
) ENGINE=InnoDB;

-- Update existing suppliers with supplier codes
SET @year = YEAR(NOW());
SET @count = 0;
UPDATE suppliers SET supplier_code = CONCAT('SUP-', @year, '-', LPAD(@count := @count + 1, 4, '0')) WHERE supplier_code IS NULL;

-- Update purchase_orders status enum to include more statuses
ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft','sent','confirmed','partially_received','completed','cancelled') NOT NULL DEFAULT 'draft';

-- Add approval fields to purchase_orders if needed
ALTER TABLE purchase_orders
ADD COLUMN approved_by INT DEFAULT NULL AFTER created_by,
ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by,
ADD COLUMN approval_required TINYINT(1) DEFAULT 0 AFTER approved_at;

-- Add foreign key for approved_by
ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- Add quality check fields to stock_receiving_items
ALTER TABLE stock_receiving_items
ADD COLUMN quality_check TEXT DEFAULT NULL AFTER expiry_date,
ADD COLUMN received_quantity INT DEFAULT 0 AFTER quantity;

-- Update stock_receiving to include goods receipt note
ALTER TABLE stock_receiving
ADD COLUMN grn_number VARCHAR(50) UNIQUE DEFAULT NULL AFTER receiving_number,
ADD COLUMN quality_approved TINYINT(1) DEFAULT 1 AFTER payment_status;</content>
<parameter name="filePath">c:\xampp\htdocs\pc_pos\database\supplier_schema_updates.sql