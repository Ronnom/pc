-- Migration: Add module-specific permissions (idempotent)

INSERT IGNORE INTO `permissions` (`name`, `description`, `module`) VALUES
('categories.view', 'View categories', 'categories'),
('categories.create', 'Create categories', 'categories'),
('categories.edit', 'Edit categories', 'categories'),
('categories.delete', 'Delete categories', 'categories'),
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
('repairs.complete', 'Complete repairs', 'repairs');
