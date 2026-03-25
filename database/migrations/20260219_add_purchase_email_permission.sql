-- Migration: Add purchase.email permission and map to Manager (idempotent)

SET @permissions_table_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions'
);
SET @roles_table_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'
);
SET @role_permissions_table_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions'
);

SET @sql_insert_perm := IF(@permissions_table_exists > 0,
  "INSERT INTO permissions (name, description, module)
   SELECT 'purchase.email', 'Email purchase orders to suppliers', 'purchase'
   WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'purchase.email')",
  'SELECT 1'
);
PREPARE stmt FROM @sql_insert_perm; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql_map_manager := IF(@roles_table_exists > 0 AND @permissions_table_exists > 0 AND @role_permissions_table_exists > 0,
  "INSERT IGNORE INTO role_permissions (role_id, permission_id)
   SELECT r.id, p.id
   FROM roles r
   INNER JOIN permissions p ON p.name = 'purchase.email'
   WHERE r.name = 'Manager'",
  'SELECT 1'
);
PREPARE stmt FROM @sql_map_manager; EXECUTE stmt; DEALLOCATE PREPARE stmt;
