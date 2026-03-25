-- Migration: Seed quote permissions and map to admin roles (idempotent)
-- Adds:
--   quotes.create
--   quotes.send
--   quotes.convert

SET @permissions_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'permissions'
);

SET @role_permissions_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'role_permissions'
);

SET @roles_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
);

-- Insert permission rows if permissions table exists.
SET @sql_insert_quote_permissions := IF(@permissions_table_exists > 0,
  "INSERT INTO permissions (name, description, module)
   SELECT 'quotes.create', 'Create and save quotations', 'quotes'
   WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'quotes.create')",
  "SELECT 1");
PREPARE stmt FROM @sql_insert_quote_permissions;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_insert_quote_send := IF(@permissions_table_exists > 0,
  "INSERT INTO permissions (name, description, module)
   SELECT 'quotes.send', 'Send quotations to customers', 'quotes'
   WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'quotes.send')",
  "SELECT 1");
PREPARE stmt FROM @sql_insert_quote_send;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_insert_quote_convert := IF(@permissions_table_exists > 0,
  "INSERT INTO permissions (name, description, module)
   SELECT 'quotes.convert', 'Convert quotations to sales', 'quotes'
   WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'quotes.convert')",
  "SELECT 1");
PREPARE stmt FROM @sql_insert_quote_convert;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Map new permissions to administrator role(s) if RBAC tables exist.
SET @sql_map_quote_permissions_admin := IF(@permissions_table_exists > 0 AND @role_permissions_table_exists > 0 AND @roles_table_exists > 0,
  "INSERT INTO role_permissions (role_id, permission_id)
   SELECT r.id, p.id
   FROM roles r
   INNER JOIN permissions p
     ON p.name IN ('quotes.create', 'quotes.send', 'quotes.convert')
   LEFT JOIN role_permissions rp
     ON rp.role_id = r.id
    AND rp.permission_id = p.id
   WHERE rp.id IS NULL
     AND (LOWER(r.name) = 'administrator' OR r.id = 1)",
  "SELECT 1");
PREPARE stmt FROM @sql_map_quote_permissions_admin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
