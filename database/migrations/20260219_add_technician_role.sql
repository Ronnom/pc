-- Migration: Add Technician role and map repairs permissions (idempotent)

SET @roles_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
);
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

SET @roles_has_is_active := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
    AND COLUMN_NAME = 'is_active'
);

SET @sql_insert_role := IF(@roles_table_exists > 0,
  IF(@roles_has_is_active > 0,
    "INSERT IGNORE INTO roles (name, description, is_active) VALUES ('Technician','Service & repairs access',1)",
    "INSERT IGNORE INTO roles (name, description) VALUES ('Technician','Service & repairs access')"
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql_insert_role; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql_map_perms := IF(@roles_table_exists > 0 AND @permissions_table_exists > 0 AND @role_permissions_table_exists > 0,
  "INSERT IGNORE INTO role_permissions (role_id, permission_id)
   SELECT r.id, p.id
   FROM roles r
   INNER JOIN permissions p ON p.name IN ('repairs.view','repairs.create','repairs.edit','repairs.complete')
   WHERE r.name = 'Technician'",
  'SELECT 1'
);
PREPARE stmt FROM @sql_map_perms; EXECUTE stmt; DEALLOCATE PREPARE stmt;
