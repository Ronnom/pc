-- Fix Admin Password
-- Run this SQL to set the correct password hash for admin123

UPDATE `users` 
SET `password_hash` = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy' 
WHERE `username` = 'admin' OR `email` = 'admin@pcpos.local';

-- The password hash above is for: admin123
-- Generated using PHP password_hash('admin123', PASSWORD_DEFAULT)

