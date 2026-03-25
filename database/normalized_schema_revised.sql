-- Consolidated normalized schema for PC_POS
-- Generated: 2026-02-17
-- Best practices applied: normalization, FK constraints, indexes, audit columns,
-- soft deletes, DECIMAL for money, utf8mb4 charset, InnoDB engine.

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE;
SET SQL_MODE='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- Use the database name replacement or run with -D <db>
-- CREATE DATABASE IF NOT EXISTS `pc_pos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `pc_pos`;

-- Users table (authentication + basic profile)
CREATE TABLE IF NOT EXISTS users (
  id int(11) NOT NULL AUTO_INCREMENT,
  username varchar(100) NOT NULL,
  email varchar(255),
  password_hash varchar(255) NOT NULL,
  role_id int unsigned,
  full_name varchar(255),
  is_admin tinyint(1) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  last_login timestamp NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at timestamp NULL,
  PRIMARY KEY (id),
  KEY idx_users_role_id (role_id),
  UNIQUE KEY ux_users_username (username),
  UNIQUE KEY ux_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles (optional) - small lookup table for RBAC
CREATE TABLE IF NOT EXISTS roles (
  id int unsigned NOT NULL AUTO_INCREMENT,
  name varchar(50) NOT NULL,
  description text,
  is_active tinyint(1) DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions - granular access control
CREATE TABLE IF NOT EXISTS permissions (
  id int unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  description text,
  module varchar(50) NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY `name` (`name`),
  KEY module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role -> Permissions junction table
CREATE TABLE IF NOT EXISTS role_permissions (
  id int unsigned NOT NULL AUTO_INCREMENT,
  role_id int unsigned NOT NULL,
  permission_id int unsigned NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY role_permission (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_roleperm_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_roleperm_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users -> roles linkage
ALTER TABLE users
  ADD CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL;

-- Categories for products
CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suppliers
CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  contact_name VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(255) NULL,
  address TEXT NULL,
  bank_details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers
CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(150) NULL,
  last_name VARCHAR(150) NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  address TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products
-- Products
CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  category_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  brand VARCHAR(150) DEFAULT NULL,
  model VARCHAR(150) DEFAULT NULL,
  serial_number VARCHAR(255) DEFAULT NULL,
  location VARCHAR(255) DEFAULT NULL,
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  sell_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  selling_price DECIMAL(15,2) DEFAULT NULL,
  stock_quantity INT NOT NULL DEFAULT 0,
  min_stock_level INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  max_stock_level INT DEFAULT NULL,
  barcode VARCHAR(128) DEFAULT NULL,
  is_taxable TINYINT(1) NOT NULL DEFAULT 1,
  tax_rate DECIMAL(5,2) DEFAULT 0.00,
  markup_percentage DECIMAL(5,2) DEFAULT NULL,
  warranty_period INT NULL COMMENT 'Default warranty period in months',
  specs JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_products_sku (sku),
  KEY idx_products_category (category_id),
  KEY idx_products_supplier (supplier_id),
  KEY idx_products_name (name(191)),
  KEY idx_products_sku_idx (sku(64)),
  KEY idx_products_barcode (barcode(64)),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product specifications (key/value pairs)
CREATE TABLE IF NOT EXISTS product_specifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  spec_key VARCHAR(150) NOT NULL,
  spec_value TEXT NOT NULL,
  spec_group VARCHAR(100) DEFAULT 'General',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ps_product (product_id),
  CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product images
CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  image_path VARCHAR(1024) NOT NULL,
  alt_text VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_by int(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pi_product (product_id),
  CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product serial numbers (per-unit tracking)
CREATE TABLE IF NOT EXISTS product_serial_numbers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  serial_number VARCHAR(255) NOT NULL,
  status ENUM('in_stock','sold','returned','damaged','stolen') DEFAULT 'in_stock',
  location_id TINYINT UNSIGNED DEFAULT 1,
  transaction_id BIGINT UNSIGNED DEFAULT NULL,
  stocked_cost_price DECIMAL(15,2) NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_psn_serial (serial_number),
  KEY idx_psn_product (product_id),
  CONSTRAINT fk_psn_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions (sales)
CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_number VARCHAR(64) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  user_id int(11) NULL,
  transaction_date DATETIME NOT NULL,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(32) NOT NULL DEFAULT 'completed',
  payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_transactions_number (transaction_number),
  KEY idx_transactions_date (transaction_date),
  CONSTRAINT fk_transactions_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction items
CREATE TABLE IF NOT EXISTS transaction_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  product_serial_number_id BIGINT UNSIGNED NULL COMMENT 'Specific sold serial unit when item is serialized',
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  serial_cost_price DECIMAL(15,2) NULL COMMENT 'Cost snapshot from stocked serial at time of sale',
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_titems_tx (transaction_id),
  KEY idx_titems_serial (product_serial_number_id),
  CONSTRAINT fk_titems_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_titems_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_titems_serial FOREIGN KEY (product_serial_number_id) REFERENCES product_serial_numbers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Returns (header)
CREATE TABLE IF NOT EXISTS returns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  user_id int(11) NOT NULL COMMENT 'Staff who processed the return',
  return_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_refund_amount DECIMAL(15,2) NOT NULL,
  restocking_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_returns_transaction (transaction_id),
  KEY idx_returns_user (user_id),
  CONSTRAINT fk_returns_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_returns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Return line items
CREATE TABLE IF NOT EXISTS return_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id BIGINT UNSIGNED NOT NULL,
  transaction_item_id BIGINT UNSIGNED NOT NULL,
  product_serial_number_id BIGINT UNSIGNED NULL COMMENT 'Specific returned serial unit',
  quantity INT NOT NULL,
  reason VARCHAR(255) NULL,
  condition_note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_return_items_return (return_id),
  KEY idx_return_items_tx_item (transaction_item_id),
  KEY idx_return_items_serial (product_serial_number_id),
  CONSTRAINT fk_ri_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_item FOREIGN KEY (transaction_item_id) REFERENCES transaction_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ri_serial FOREIGN KEY (product_serial_number_id) REFERENCES product_serial_numbers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quotes (header)
CREATE TABLE IF NOT EXISTS quotes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_number VARCHAR(64) NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  valid_until DATE NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  notes TEXT NULL,
  emailed_at DATETIME NULL,
  converted_at DATETIME NULL,
  converted_to_transaction_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_quotes_number (quote_number),
  KEY idx_quotes_customer (customer_id),
  KEY idx_quotes_status (status),
  KEY idx_quotes_created_by (created_by),
  KEY idx_quotes_tx (converted_to_transaction_id),
  CONSTRAINT fk_quotes_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_quotes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_quotes_tx FOREIGN KEY (converted_to_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quote items (line rows)
CREATE TABLE IF NOT EXISTS quote_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qitems_quote (quote_id),
  KEY idx_qitems_product (product_id),
  CONSTRAINT fk_qitems_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_qitems_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warranties (plural) - normalized and audit-friendly
CREATE TABLE IF NOT EXISTS warranties (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_item_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  serial_number VARCHAR(255) NULL,
  customer_id BIGINT UNSIGNED NULL,
  warranty_start DATE NOT NULL,
  warranty_end DATE NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_by int(11) NULL,
  updated_by int(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_warranties_product (product_id),
  KEY idx_warranties_serial (serial_number),
  KEY idx_warranties_customer (customer_id),
  CONSTRAINT fk_warranties_transaction_item FOREIGN KEY (transaction_item_id) REFERENCES transaction_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_warranties_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_warranties_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_warranties_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_warranties_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warranty claims
CREATE TABLE IF NOT EXISTS warranty_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warranty_id BIGINT UNSIGNED NOT NULL,
  claim_date DATE NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_by int(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wclaims_warranty (warranty_id),
  CONSTRAINT fk_wclaims_warranty FOREIGN KEY (warranty_id) REFERENCES warranties(id) ON DELETE CASCADE,
  CONSTRAINT fk_wclaims_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repairs and repair_parts
CREATE TABLE IF NOT EXISTS repairs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  serial_number VARCHAR(255) NULL,
  warranty_id BIGINT UNSIGNED NULL,
  request_date DATE NOT NULL,
  diagnosis TEXT NULL,
  labor_charge DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(32) NOT NULL DEFAULT 'received',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_repairs_warranty (warranty_id),
  CONSTRAINT fk_repairs_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_repairs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_repairs_warranty FOREIGN KEY (warranty_id) REFERENCES warranties(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repair_parts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_rparts_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE,
  CONSTRAINT fk_rparts_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock movements (audit of stock changes)
CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('in','out') NOT NULL,
  quantity INT NOT NULL,
  reference_type VARCHAR(64) NULL,
  reference_id BIGINT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smov_product (product_id),
  CONSTRAINT fk_smov_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase orders
CREATE TABLE IF NOT EXISTS purchase_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_number VARCHAR(64) NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  order_date DATE NOT NULL,
  expected_date DATE NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_po_number (po_number),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity INT NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments (generic)
CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  amount DECIMAL(15,2) NOT NULL,
  method VARCHAR(64) NOT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_payments_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit / logs table (lightweight)
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id int(11) NULL,
  action VARCHAR(64) NOT NULL,
  module VARCHAR(64) NULL,
  entity_id BIGINT UNSIGNED NULL,
  description TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finalization
SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;

-- ============================================
-- Seed Data - Roles and Permissions
-- ============================================

INSERT IGNORE INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Administrator', 'Full system access'),
(2, 'Manager', 'Management and reporting access'),
(3, 'Cashier', 'Sales and POS access'),
(4, 'Inventory Clerk', 'Inventory management access');

INSERT IGNORE INTO `permissions` (`name`, `description`, `module`) VALUES
('users.view', 'View users', 'users'),
('users.create', 'Create users', 'users'),
('users.edit', 'Edit users', 'users'),
('users.delete', 'Delete users', 'users'),
('categories.view', 'View categories', 'categories'),
('categories.create', 'Create categories', 'categories'),
('categories.edit', 'Edit categories', 'categories'),
('categories.delete', 'Delete categories', 'categories'),
('products.view', 'View products', 'products'),
('products.create', 'Create products', 'products'),
('products.edit', 'Edit products', 'products'),
('products.delete', 'Delete products', 'products'),
('inventory.view', 'View inventory', 'inventory'),
('inventory.adjust', 'Adjust inventory', 'inventory'),
('sales.view', 'View sales', 'sales'),
('sales.create', 'Create sales', 'sales'),
('sales.refund', 'Process refunds', 'sales'),
('suppliers.view', 'View suppliers', 'suppliers'),
('suppliers.create', 'Create suppliers', 'suppliers'),
('suppliers.edit', 'Edit suppliers', 'suppliers'),
('suppliers.delete', 'Delete suppliers', 'suppliers'),
('transactions.view', 'View transactions', 'transactions'),
('transactions.void', 'Void transactions', 'transactions'),
('customers.view', 'View customers', 'customers'),
('customers.create', 'Create customers', 'customers'),
('customers.edit', 'Edit customers', 'customers'),
('customers.delete', 'Delete customers', 'customers'),
('warranty.view', 'View warranty', 'warranty'),
('warranty.claim', 'Create warranty claims', 'warranty'),
('warranty.process', 'Process warranty claims', 'warranty'),
('repairs.view', 'View repairs', 'repairs'),
('repairs.create', 'Create repairs', 'repairs'),
('repairs.edit', 'Edit repairs', 'repairs'),
('repairs.complete', 'Complete repairs', 'repairs'),
('quotes.create', 'Create and save quotations', 'quotes'),
('quotes.send', 'Send quotations to customers', 'quotes'),
('quotes.convert', 'Convert quotations to sales', 'quotes'),
('purchase.view', 'View purchase orders', 'purchase'),
('purchase.create', 'Create purchase orders', 'purchase'),
('purchase.approve', 'Approve purchase orders', 'purchase'),
('reports.view', 'View reports', 'reports'),
('settings.view', 'View settings', 'settings'),
('settings.edit', 'Edit settings', 'settings');

-- Assign all permissions to Administrator role
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`;

-- Notes:
-- - Use `ON DELETE SET NULL` for optional relationships to avoid accidental cascades.
-- - Use a migration tool for incremental updates (this file is canonical initial design).
-- - Consider creating full-text indexes on `products(name, description)` if supported by your server.
-- - CHECK constraints (enforcing `status` values) are helpful on MySQL 8+, but may be ignored on older MariaDB versions.
