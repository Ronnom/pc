# Quotation System - Comprehensive Guide

## Overview

The Quotation System is a complete solution for creating, managing, tracking, and converting quotations to sales transactions in the PC POS system. It integrates seamlessly with the POS module and provides full audit trail capabilities.

## System Architecture

### Database Tables

#### `quotes` - Main quotation records
- `id` - Unique identifier
- `quote_number` - Auto-generated unique quote reference
- `customer_id` - Associated customer (required)
- `created_by` - User who created the quote
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp
- `valid_until` - Quote expiration date
- `status` - One of: draft, sent, accepted, expired, converted
- `subtotal` - Pre-tax sum of items
- `tax_amount` - Calculated tax
- `discount_amount` - Applied discount
- `total_amount` - Final total
- `notes` - Quote-specific notes
- `emailed_at` - When quote was sent to customer
- `converted_at` - When quote was converted to transaction
- `converted_to_transaction_id` - Link to sales transaction

#### `quote_items` - Line items in quotes
- `id` - Unique identifier
- `quote_id` - Parent quote reference
- `product_id` - Product being quoted
- `quantity` - Item quantity
- `unit_price` - Price per unit
- `discount_amount` - Line-level discount
- `tax_amount` - Line-level tax
- `line_total` - Calculated line total
- `created_at` - Creation timestamp

### Quote Status Flow

```
DRAFT → SENT → [ACCEPTED] → CONVERTED [or EXPIRED]
  ↑
  └─────────── DRAFT (manual reset) ─────────────┘
```

**Status Definitions:**
- **DRAFT** - Quote is being prepared, not yet sent
- **SENT** - Quote sent to customer email
- **ACCEPTED** - Customer acknowledged (optional)
- **CONVERTED** - Quote converted to sales transaction
- **EXPIRED** - Valid_until date has passed

## Features

### 1. Create Quotations
- Switch POS to "Quote Mode" 
- Select customer (required)
- Add products to quote
- Set valid_until date (default: +7 days)
- Add optional notes
- Save as draft

### 2. Quote Management
- View all quotations
- Filter by status
- Search by customer
- Edit draft quotes
- Clone/duplicate quotes

### 3. Send Quotations
- Email quote to customer via built-in email
- Automatically sets status to "sent"
- Quote date and reference included
- Professional HTML email format

### 4. Convert to Sale
- Convert accepted quote to POS sale
- Customer and items pre-populated
- Proceed to normal checkout
- Automatic transaction linkage
- Quote marked as "converted"

### 5. Tracking & Audit
- Complete quote history
- Track who created quote
- Record when sent to customer
- Link to resulting transaction
- View quote statistics

## Permissions

Three quote-specific permissions control access:

```
quotes.create  → Create, edit, save quotations
quotes.send    → Email quotations to customers
quotes.convert → Convert quotations to sales
```

All three permissions are automatically assigned to Administrator role.

## API Reference

### Verification Functions

```php
// Verify complete quote system setup
$result = verifyQuoteSystemSetup();
// Returns: ['is_valid' => bool, 'issues' => array, 'timestamp' => string]

// Get quote system statistics
$stats = getQuoteSystemStats();
// Returns: [
//   'total_quotes' => int,
//   'draft_quotes' => int,
//   'sent_quotes' => int,
//   'converted_quotes' => int,
//   'total_quote_value' => float,
//   'converted_value' => float
// ]
```

### Quote Conversion Functions

```php
// Validate if quote can be converted
$validation = validateQuoteForConversion($quoteId);
// Returns: ['valid' => bool, 'errors' => array, 'quote' => array, 'items_count' => int]

// Link a completed transaction to its source quote
linkTransactionToQuote($transactionId, $quoteId);
// Throws Exception on failure
```

### Quote Retrieval Functions

```php
// Get converted quotes with details
$convertedQuotes = getConvertedQuotes($limit = 100, $offset = 0);
// Returns array of quote records with customer and transaction info

// Get complete audit trail for a quote
$audit = getQuoteAuditTrail($quoteId);
// Returns: [
//   'quote_id' => int,
//   'quote_number' => string,
//   'created' => ['timestamp' => datetime, 'by_user' => int],
//   'sent' => ['timestamp' => datetime, ...],
//   'converted' => ['timestamp' => datetime, 'to_transaction_id' => int, ...]
// ]
```

### Maintenance Functions

```php
// Mark expired quotes based on valid_until date
cleanupExpiredQuotes();
// Returns: bool (success)
```

## User Workflows

### Workflow 1: Create and Send Quote

1. Navigate to POS
2. Click "Quote Mode" button
3. Search and select customer
4. Add products to cart
5. Set "Valid Until" date (optional)
6. Add notes (optional)
7. Click "Save Quote"
8. Quote receives auto-generated number (e.g., Q-20260218-001)
9. To email: Click "Email" button → sent to customer email

### Workflow 2: Convert Quote to Sale

1. In "Recent Quotes" modal
2. Find quote to convert
3. Click "Convert" button
4. Automatically loads quote items and customer
5. Switches back to POS Mode
6. Proceed to checkout normally
7. Quote status automatically changes to "converted"
8. Transaction ID linked to original quote

### Workflow 3: Monitor Quotations

1. Navigate to Quote Management page
2. View statistics dashboard
3. Filter by status (Draft, Sent, Converted, etc.)
4. Click quote to view details
5. Access linked transaction if converted

## Integration Points

### POS Module (`pos.php`)
- Quote mode toggle
- Cart integration
- Customer selection
- Quote saving
- Quote loading
- Quote conversion

### Sales Module (`modules/sales.php`)
- Transaction creation
- Stock updates
- Quote linking
- Session cleanup

### Quote Display (`quote.php`)
- Standalone quote view
- Print-ready layout
- Email-ready format

### Checkout (`checkout.php`)
- Converted quote pre-population
- Payment processing
- Receipt generation

## Email Template

Quotations are sent as HTML emails including:
- Quote number and date
- Customer name and contact info
- Itemized product list with prices
- Subtotal, tax, discount, total
- Valid until date
- Company branding

## Troubleshooting

### Quote system appears incomplete

Check diagnostics page (`/quote_diagnostics.php`) for:
- Missing database tables
- Missing permissions
- File existence
- PHP extensions

### Quotes not appearing in list

- Verify customer relationship exists
- Check quote status filter
- Ensure quotes_table is accessible
- Verify user has `quotes.create` permission

### Cannot convert quote to sale

Run `validateQuoteForConversion($quoteId)` to check:
- Quote items still exist
- Products have sufficient stock
- Customer record still exists
- Quote hasn't already been converted

### Email not sending

Verify:
- PHP mail() function is enabled
- SMTP properly configured on server
- Customer email address is valid
- Server firewall allows outbound email

## Database Migrations

All required migrations are in `/database/migrations/`:

```
20260218_add_quotes_tables.sql
    → Creates quotes and quote_items tables

20260218_add_quote_permissions.sql
    → Adds quote-specific permissions

20260218_verify_quote_integrity.sql
    → Ensures table integrity and relationships
```

Run in order using your migration system.

## Performance Considerations

### Indexes
- `quotes.quote_number` - UNIQUE (fast lookups)
- `quotes.customer_id` - Fast customer filtering
- `quotes.status` - Status filtering
- `quotes.created_by` - User quote tracking
- `quote_items.quote_id` - Fast item retrieval

### Query Optimization
- Denormalized customer name/email for faster queries
- Precomputed totals avoid recalculation
- Indexes on foreign keys for joins

## Security Considerations

### Permission Enforcement
- All quote operations check `quotes.create`, `quotes.send`, `quotes.convert` permissions
- User must be authenticated
- Actions logged to audit trail

### Data Protection
- Quote numbers are unique to prevent duplication
- Customer ID required (quotes must be to known customers)
- Transaction linkage prevents orphaned quotes
- Audit trail tracks all conversions

## Future Enhancement Ideas

- **Quote Templates** - Save quote templates for common product sets
- **Bulk Quotes** - Create quotes for multiple customers at once
- **Quote Expiry Notifications** - Alert before quotes expire
- **Customer Acceptance** - Link to customer acceptance mechanism
- **Quote Analytics** - Conversion rate analysis
- **Multi-Currency** - Support international quotes
- **Digital Signatures** - Customer e-signature acceptance
- **Revision Tracking** - Track quote revisions
- **Discount Rules** - Quantity-based discount rules
- **Approval Workflows** - Manager approval before sending

## Support & Maintenance

### Regular Maintenance
- Run `cleanupExpiredQuotes()` weekly
- Review `quote_diagnostics.php` monthly
- Archive old converted quotes annually

### Monitoring
- Check quote_management.php dashboard regularly
- Monitor conversion rate (converted_quotes / total_quotes)
- Alert if quote email fails

### Backup Strategy
- Backup quotes table with other transactional data
- Ensure transactions table backups include all converted quotes
- Test restore procedures quarterly

---

**Last Updated:** February 18, 2026  
**Version:** 1.0  
**Status:** Production Ready
