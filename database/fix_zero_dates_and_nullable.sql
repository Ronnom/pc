-- fix_zero_dates_and_nullable.sql
-- Purpose: Convert '0000-00-00' / '0000-00-00 00:00:00' values to NULL
-- and set nullable DATE/DATETIME/TIMESTAMP columns to NULL DEFAULT NULL.
--
-- USAGE: Backup your database first. Then run this script in MySQL
-- client (mysql CLI or phpMyAdmin). The script temporarily relaxes
-- NO_ZERO_DATE/NO_ZERO_IN_DATE for the session while it performs
-- updates and alters, and restores the original SQL_MODE at the end.
--
-- NOTE: This script creates a temporary stored procedure, executes it,
-- and then drops it. ALTER statements will change column definitions
-- to remove any NOT NULL/default-zero behavior for nullable columns.
--
-- BACKUP FIRST: mysqldump -u root -p your_db > backup.sql
-- RUN THIS FILE: mysql -u root -p your_db < fix_zero_dates_and_nullable.sql
--
-- ============================================================

SET @OLD_SQL_MODE=@@SQL_MODE;
SET @NEW_SQL_MODE = REPLACE(REPLACE(@@SQL_MODE,'NO_ZERO_DATE',''),'NO_ZERO_IN_DATE','');
SET SQL_MODE=@NEW_SQL_MODE;

SET SESSION group_concat_max_len = 1000000;

DELIMITER $$
DROP PROCEDURE IF EXISTS fix_zero_dates$$
CREATE PROCEDURE fix_zero_dates()
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE tname VARCHAR(128);
  DECLARE cname VARCHAR(128);
  DECLARE dtype VARCHAR(32);

  DECLARE cur1 CURSOR FOR
    SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND DATA_TYPE IN ('date','datetime','timestamp')
      AND IS_NULLABLE = 'YES'
    ORDER BY TABLE_NAME, ORDINAL_POSITION;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  OPEN cur1;
  read_loop: LOOP
    FETCH cur1 INTO tname, cname, dtype;
    IF done THEN
      LEAVE read_loop;
    END IF;

    -- Update zero-date values to NULL
    SET @u = CONCAT('UPDATE `', tname, '` SET `', cname,
                    '` = NULL WHERE `', cname,
                    '` IN (''0000-00-00'', ''0000-00-00 00:00:00'')');
    PREPARE psu FROM @u;
    -- Use silent handler to avoid stopping on errors for very small edge cases
    BEGIN
      DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
      EXECUTE psu;
    END;
    DEALLOCATE PREPARE psu;

    -- Ensure column default/nullable is NULL DEFAULT NULL
    SET @coltype = IF(dtype = 'timestamp', 'TIMESTAMP', 'DATETIME');
    SET @m = CONCAT('ALTER TABLE `', tname, '` MODIFY `', cname, '` ', @coltype, ' NULL DEFAULT NULL');
    PREPARE psm FROM @m;
    BEGIN
      DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
      EXECUTE psm;
    END;
    DEALLOCATE PREPARE psm;

  END LOOP;
  CLOSE cur1;
END$$
DELIMITER ;

-- Execute procedure
CALL fix_zero_dates();

-- Cleanup
DROP PROCEDURE IF EXISTS fix_zero_dates;

-- Restore original SQL mode
SET SQL_MODE=@OLD_SQL_MODE;

-- Done
SELECT 'fix_zero_dates_and_nullable.sql completed successfully' AS status;
