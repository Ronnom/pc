# SALES TRANSACTION MODULE - DEPLOYMENT CHECKLIST

## Pre-Deployment Verification

### 1. Database Schema Verification
- [ ] `transactions` table exists with all required columns (status, payment_status, notes)
- [ ] `transaction_items` table properly linked to transactions
- [ ] `payments` table exists with payment_method, amount, reference_number
- [ ] `product_stock_adjustments` table exists with reference_id column
- [ ] `user_activity` table exists for audit logging
- [ ] All tables have proper indexes on commonly queried columns
- [ ] Foreign key constraints are in place

### 2. Required Functions Check
Verify these functions exist in `includes/functions.php` or `includes/init.php`:

```php
// Core functions
- getDB()                      // Database connection
- getCurrentUserId()           // Get logged-in user ID
- requireLogin()              // Auth check
- requirePermission()         // Permission check
- hasPermission()             // Permission test
- escape()                    // HTML/SQL escaping
- formatCurrency()            // Currency formatting
- formatDate()                // Date formatting
- formatDateTime()            // DateTime formatting
- formatDecimal()             // Decimal formatting
- getBaseUrl()                // Base URL for links
- setFlashMessage()           // Session flash messages
- redirect()                  // URL redirect
- logUserActivity()           // Activity logging
- getStatusBadge()            // Status display helper
- getConfigValue()            // Configuration values
```

### 3. Configuration Values Check
Required in configuration (config/config.php or database):

```php
'company_name'      // For invoice letterhead
'company_address'   // For invoice
'company_city'      // For invoice
'company_country'   // For invoice
'company_phone'     // For invoice
'company_email'     // For invoice
'company_tin'       // For invoice (optional)
```

### 4. JavaScript Libraries
- [ ] Chart.js 3.9.1+ loaded from CDN in templates/header.php
  ```html
  <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
  ```
- [ ] Bootstrap 4.6+ CSS/JS included
- [ ] jQuery (if used elsewhere in project)

### 5. File Permissions
- [ ] All PHP files readable by web server
- [ ] `uploads/` directory writable (if PDF storage needed)
- [ ] `logs/` directory writable for activity logs

## Installation Steps

### Step 1: Deploy Files
1. Copy/overwrite these files to production:
   ```
   - transaction_history.php
   - invoice.php
   - daily_sales.php
   - analytics.php
   - void_transaction.php
   - transactions.php (verify existing version)
   ```

2. Copy documentation:
   ```
   - SALES_TRANSACTION_MODULE.md
   - SALES_TRANSACTION_MODULE_QUICK_REF.md
   - SALES_TRANSACTION_MODULE_DEPLOYMENT.md (this file)
   ```

### Step 2: Database Migration
Run any pending database updates:
```sql
-- Verify transactions table has required columns
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'pending';
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_status VARCHAR(50) DEFAULT 'pending';
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS notes LONGTEXT NULL;

-- Verify product_stock_adjustments table exists
CREATE TABLE IF NOT EXISTS product_stock_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    adjustment_type VARCHAR(50),
    quantity INT,
    reason TEXT,
    reference_id INT,
    adjusted_by_user_id INT,
    adjusted_at DATETIME,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(transaction_date);
CREATE INDEX IF NOT EXISTS idx_transactions_status ON transactions(status);
CREATE INDEX IF NOT EXISTS idx_transaction_items_tx ON transaction_items(transaction_id);
CREATE INDEX IF NOT EXISTS idx_payments_tx ON payments(transaction_id);
CREATE INDEX IF NOT EXISTS idx_stock_adj_ref ON product_stock_adjustments(reference_id);
```

### Step 3: Verify Permissions
Ensure role-based permissions exist in database:
```sql
-- Check that these permissions are configured
SELECT * FROM permissions WHERE permission_key IN (
    'sales.view',
    'sales.create',
    'sales.export',
    'sales.admin', 
    'sales.void'
);

-- Assign permissions to roles
-- Example: Manager role should have all sales permissions
INSERT INTO role_permissions (role_id, permission_id) VALUES
    ((SELECT id FROM roles WHERE role = 'manager'), (SELECT id FROM permissions WHERE permission_key = 'sales.admin')),
    ((SELECT id FROM roles WHERE role = 'cashier'), (SELECT id FROM permissions WHERE permission_key = 'sales.create'));
```

### Step 4: Configuration Setup
Update configuration values:
```php
// In config/config.php or database settings table
define('COMPANY_NAME', 'Your Company Name');
define('COMPANY_ADDRESS', '123 Business Street');
define('COMPANY_CITY', 'City');
define('COMPANY_COUNTRY', 'Country');
define('COMPANY_PHONE', '+1 (0) 000-0000');
define('COMPANY_EMAIL', 'info@company.com');
define('COMPANY_TIN', 'TIN-1234567890');  // Optional
```

### Step 5: Test Deployment
- [ ] Access transaction_history.php and verify search form loads
- [ ] Access daily_sales.php and verify charts render
- [ ] Access analytics.php and verify analytics load
- [ ] Verify transaction recording (via transactions.php API)
- [ ] Test invoice.php display
- [ ] Test void_transaction.php (admin-only access)

## Post-Deployment Testing

### Functional Tests
- [ ] **Transaction Recording**
  - Create test transaction via transactions.php
  - Verify transaction_number generated correctly
  - Verify stock deducted from products
  - Verify activity log created

- [ ] **Transaction History**
  - Search by date range
  - Search by customer name
  - Search by amount range
  - Apply multiple filters
  - Export to CSV
  - Verify pagination works

- [ ] **Invoice Generation**
  - View invoice for existing transaction
  - Verify all details display correctly
  - Test PDF download button
  - Verify customer details (TIN, address)
  - Test email button (if configured)

- [ ] **Daily Sales Dashboard**
  - Toggle between Daily/Weekly/Monthly views
  - Change date using picker
  - Verify metric cards calculate correctly
  - Verify charts render with correct data
  - Check cashier performance table
  - Verify top products list

- [ ] **Analytics**
  - Set date range
  - View category breakdown chart
  - View brand chart
  - View sales trend line
  - View peak hours chart
  - Test period comparison (optional)

- [ ] **Void Transaction**
  - Access void page as admin
  - Verify transaction details display
  - Verify items list for restoration
  - Complete void process
  - Verify status changed to 'voided'
  - Verify inventory restored
  - Check product_stock_adjustments table

### Permission Tests
- [ ] Non-admin user cannot access void_transaction.php
- [ ] User without sales.export cannot export CSV
- [ ] User without sales.view cannot access history/invoice
- [ ] User without sales.admin cannot email invoice
- [ ] Cashier can record transactions (sales.create)

### Data Integrity Tests
- [ ] Totals in invoice match transaction header
- [ ] CSV export includes all filtered records
- [ ] Chart data matches underlying queries
- [ ] Stock adjustments have correct reference_id
- [ ] Activity log shows all actions with timestamps
- [ ] Voided transaction has before/after inventory correct

### Browser Compatibility Tests
- [ ] Chrome/Chromium latest
- [ ] Firefox latest
- [ ] Safari latest
- [ ] Edge latest
- [ ] Mobile browsers (iOS Safari, Android Chrome)

### Performance Tests
- [ ] transaction_history.php loads < 2 seconds (with 1000+ records)
- [ ] Analytics page renders charts within 3 seconds
- [ ] Daily sales dashboard loads < 2 seconds
- [ ] CSV export processes < 5000 records in < 30 seconds
- [ ] No N+1 query problems in database logs

## Rollback Plan

If issues occur after deployment:

### Quick Rollback
```bash
# Restore from backup
cp backups/transaction_history.php.bak transaction_history.php
cp backups/invoice.php.bak invoice.php
cp backups/daily_sales.php.bak daily_sales.php
cp backups/analytics.php.bak analytics.php
cp backups/void_transaction.php.bak void_transaction.php
```

### Database Rollback
```sql
-- If modifications needed
-- Revert any schema changes if issues detected
-- Review backup before production deployment
```

### Notify Users
- [ ] Document known issues
- [ ] Provide alternative workflow if feature unavailable
- [ ] Set estimated recovery time

## Production Monitoring

### System Health Checks (Daily)
```sql
-- Monitor transaction recording
SELECT COUNT(*) as daily_transactions, 
       SUM(total_amount) as daily_revenue
FROM transactions 
WHERE DATE(transaction_date) = CURDATE();

-- Check for stuck pending transactions
SELECT COUNT(*) 
FROM transactions 
WHERE status = 'pending' 
AND DATE(transaction_date) < CURDATE() - INTERVAL 7 DAY;

-- Verify stock adjustments
SELECT COUNT(*) 
FROM product_stock_adjustments 
WHERE DATE(adjusted_at) = CURDATE();

-- Check activity logs
SELECT COUNT(*) 
FROM user_activity 
WHERE DATE(created_at) = CURDATE();
```

### Error Monitoring
- [ ] Monitor error logs for PHP warnings/notices
- [ ] Check browser console for JavaScript errors
- [ ] Review database query performance logs
- [ ] Monitor application response times

### User Feedback
- [ ] Collect user feedback on new features
- [ ] Track usage patterns (which features most used)
- [ ] Monitor support tickets for issues
- [ ] Plan improvements based on feedback

## Maintenance Tasks

### Weekly
- [ ] Review activity logs for unusual patterns
- [ ] Backup transaction data
- [ ] Check disk space for upload folders

### Monthly
- [ ] Archive old transactions (if needed)
- [ ] Review voided transaction reasons
- [ ] Reconcile discrepancies between sales and inventory

### Quarterly
- [ ] Performance optimization review
- [ ] Database maintenance (OPTIMIZE TABLES)
- [ ] Update Chart.js or Bootstrap if new versions available

## Documentation

### For Users
- [ ] Provide user guide for transaction history search
- [ ] Create quick reference for common tasks
- [ ] Document void transaction process
- [ ] Create invoice guide

### For Developers
- [ ] SALES_TRANSACTION_MODULE.md (feature overview)
- [ ] Code comments in complex functions
- [ ] Database schema documentation
- [ ] API endpoint documentation

### For System Admins
- [ ] Permission configuration guide
- [ ] Backup and recovery procedures
- [ ] Performance tuning guidelines
- [ ] Troubleshooting guide

## Success Criteria

✅ Module considered successfully deployed when:

1. All syntax checks pass:
   ```bash
   php -l transaction_history.php  # No errors
   php -l invoice.php              # No errors
   php -l daily_sales.php          # No errors
   php -l analytics.php            # No errors
   php -l void_transaction.php     # No errors
   ```

2. All functional tests pass without errors

3. All permission tests pass

4. Charts render correctly with data

5. CSV exports contain correct data

6. Inventory restoration works on void

7. No 500+ errors in logs for 24 hours

8. Users report positive feedback

## Sign-Off

- [ ] Database team: Schema validated
- [ ] Development team: Code reviewed and tested
- [ ] QA team: All tests passed
- [ ] Operations team: Deployment successful
- [ ] Product team: Features meet requirements

---

**Deployment Date:** ___________________

**Deployed By:** ___________________

**Reviewed By:** ___________________

---

For questions or issues contact: [Support Contact Info]
