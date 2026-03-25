-- Simple Admin Password Fix
-- Run this in phpMyAdmin or MySQL command line

-- First, check if admin exists
SELECT * FROM users WHERE username = 'admin';

-- If admin exists, update the password
-- This hash is for password: admin123
UPDATE users 
SET password_hash = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy',
    is_active = 1
WHERE username = 'admin' OR email = 'admin@pcpos.local';

-- Verify the update
SELECT id, username, email, is_active, LEFT(password_hash, 20) as hash_preview 
FROM users 
WHERE username = 'admin';

-- If admin doesn't exist, create it:
-- INSERT INTO users (username, email, password_hash, first_name, last_name, role_id, is_active) 
-- VALUES ('admin', 'admin@pcpos.local', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'System', 'Administrator', 1, 1);

