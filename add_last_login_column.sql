-- Add last_login column if it doesn't exist
-- Run this if you get errors about last_login

-- Check if column exists first (run this to check)
-- SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = 'pc_pos' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login';

-- If the query above returns no results, run this:
ALTER TABLE `users` 
ADD COLUMN `last_login` timestamp NULL DEFAULT NULL AFTER `is_active`;

-- Verify
DESCRIBE `users`;

