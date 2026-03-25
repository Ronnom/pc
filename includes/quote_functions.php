<?php
/**
 * Quote System Verification & Utility Functions
 * Ensures all quotation features work correctly
 */

/**
 * Verify quote system is fully functional
 */
function verifyQuoteSystemSetup() {
    $db = getDB();
    $issues = [];
    
    // Check 1: Verify quotes table exists
    if (!_logTableExists('quotes')) {
        $issues[] = 'Quotes table does not exist. Run migration: 20260218_add_quotes_tables.sql';
    }
    
    // Check 2: Verify quote_items table exists
    if (!_logTableExists('quote_items')) {
        $issues[] = 'Quote items table does not exist. Run migration: 20260218_add_quotes_tables.sql';
    }
    
    // Check 3: Verify quote permissions exist
    if (_logTableExists('permissions')) {
        $requiredPerms = ['quotes.create', 'quotes.send', 'quotes.convert'];
        foreach ($requiredPerms as $perm) {
            $exists = $db->fetchOne(
                "SELECT 1 FROM permissions WHERE name = ? LIMIT 1",
                [$perm]
            );
            if (!$exists) {
                $issues[] = "Missing permission: {$perm}. Run migration: 20260218_add_quote_permissions.sql";
            }
        }
    }
    
    // Check 4: Verify quote columns
    if (_logTableExists('quotes')) {
        $requiredCols = ['id', 'quote_number', 'customer_id', 'created_by', 'status', 'valid_until', 
                         'subtotal', 'tax_amount', 'discount_amount', 'total_amount', 'notes',
                         'converted_at', 'converted_to_transaction_id'];
        foreach ($requiredCols as $col) {
            if (!tableColumnExists('quotes', $col)) {
                $issues[] = "Missing column 'quotes.{$col}'. Quote tables may be incomplete.";
            }
        }
    }
    
    // Check 5: Verify quote_items columns
    if (_logTableExists('quote_items')) {
        $requiredCols = ['id', 'quote_id', 'product_id', 'quantity', 'unit_price', 
                         'discount_amount', 'tax_amount', 'line_total'];
        foreach ($requiredCols as $col) {
            if (!tableColumnExists('quote_items', $col)) {
                $issues[] = "Missing column 'quote_items.{$col}'. Quote items table may be incomplete.";
            }
        }
    }
    
    // Check 6: Verify customer data for quotes
    if (_logTableExists('quotes') && _logTableExists('customers')) {
        $customerlessQuotes = $db->fetchOne(
            "SELECT COUNT(*) as cnt FROM quotes q 
             LEFT JOIN customers c ON c.id = q.customer_id 
             WHERE c.id IS NULL",
            []
        );
        if ($customerlessQuotes && (int)$customerlessQuotes['cnt'] > 0) {
            // This is not necessarily an error, but could indicate orphaned quotes
            // log for informational purposes
        }
    }
    
    return [
        'is_valid' => empty($issues),
        'issues' => $issues,
        'timestamp' => date(DATETIME_FORMAT)
    ];
}

/**
 * Get quote summary statistics
 */
function getQuoteSystemStats() {
    $db = getDB();
    $stats = [];
    
    if (!_logTableExists('quotes')) {
        return null;
    }
    
    $stats['total_quotes'] = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM quotes", [])['cnt'] ?? 0);
    $stats['draft_quotes'] = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM quotes WHERE status = 'draft'", [])['cnt'] ?? 0);
    $stats['sent_quotes'] = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM quotes WHERE status = 'sent'", [])['cnt'] ?? 0);
    $stats['converted_quotes'] = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM quotes WHERE status = 'converted'", [])['cnt'] ?? 0);
    $stats['total_quote_value'] = (float)($db->fetchOne("SELECT COALESCE(SUM(total_amount), 0) as total FROM quotes", [])['total'] ?? 0);
    $stats['converted_value'] = (float)($db->fetchOne("SELECT COALESCE(SUM(total_amount), 0) as total FROM quotes WHERE status = 'converted'", [])['total'] ?? 0);
    
    return $stats;
}

/**
 * Validate quote can be converted to sale
 */
function validateQuoteForConversion($quoteId) {
    $db = getDB();
    $errors = [];
    
    if (!_logTableExists('quotes') || !_logTableExists('quote_items')) {
        return ['valid' => false, 'errors' => ['Quote tables do not exist']];
    }
    
    $quote = $db->fetchOne("SELECT * FROM quotes WHERE id = ?", [$quoteId]);
    if (!$quote) {
        return ['valid' => false, 'errors' => ['Quote not found']];
    }
    
    // Check quote status
    if ($quote['status'] === 'converted') {
        $errors[] = "Quote is already converted to transaction {$quote['converted_to_transaction_id']}";
    }
    
    if ($quote['status'] === 'expired') {
        $errors[] = 'Quote is expired';
    }
    
    // Check customer exists
    if (!_logTableExists('customers')) {
        $errors[] = 'Customers table not found';
    } else {
        $customer = $db->fetchOne("SELECT id FROM customers WHERE id = ?", [$quote['customer_id']]);
        if (!$customer) {
            $errors[] = 'Customer for quote no longer exists';
        }
    }
    
    // Check quote items
    $items = $db->fetchAll("SELECT * FROM quote_items WHERE quote_id = ?", [$quoteId]);
    if (empty($items)) {
        $errors[] = 'Quote has no items';
    } else {
        // Verify product stock
        foreach ($items as $item) {
            $product = $db->fetchOne(
                "SELECT id, stock_quantity FROM products WHERE id = ?",
                [$item['product_id']]
            );
            if (!$product) {
                $errors[] = "Product for quote item not found";
            } elseif ((int)$product['stock_quantity'] < (int)$item['quantity']) {
                $errors[] = "Insufficient stock for quote item";
            }
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'quote' => $quote,
        'items_count' => count($items ?? [])
    ];
}

/**
 * Link a completed transaction back to its source quote
 */
function linkTransactionToQuote($transactionId, $quoteId) {
    $db = getDB();
    
    if (!_logTableExists('quotes')) {
        throw new Exception('Quotes table does not exist');
    }
    
    // Verify both records exist
    $transaction = $db->fetchOne("SELECT id FROM transactions WHERE id = ?", [$transactionId]);
    $quote = $db->fetchOne("SELECT id FROM quotes WHERE id = ?", [$quoteId]);
    
    if (!$transaction || !$quote) {
        throw new Exception('Transaction or quote not found');
    }
    
    // Update quote with transaction link
    $updateData = [
        'status' => 'converted',
        'converted_at' => date(DATETIME_FORMAT),
        'converted_to_transaction_id' => $transactionId
    ];
    // Soft-archive converted quotes when the column exists.
    if (function_exists('tableColumnExists') && tableColumnExists('quotes', 'deleted_at')) {
        $updateData['deleted_at'] = date(DATETIME_FORMAT);
    }

    $db->update('quotes', $updateData, 'id = ?', [$quoteId]);
    
    return true;
}

/**
 * Get quotes that have been converted to transactions
 */
function getConvertedQuotes($limit = 100, $offset = 0) {
    $db = getDB();
    
    if (!_logTableExists('quotes')) {
        return [];
    }
    
    return $db->fetchAll(
        "SELECT q.*, 
                c.first_name, c.last_name, c.email,
                u.username as created_by_name,
                t.transaction_number
         FROM quotes q
         LEFT JOIN customers c ON c.id = q.customer_id
         LEFT JOIN users u ON u.id = q.created_by
         LEFT JOIN transactions t ON t.id = q.converted_to_transaction_id
         WHERE q.status = 'converted'
         ORDER BY q.converted_at DESC
         LIMIT ? OFFSET ?",
        [$limit, $offset]
    );
}

/**
 * Clean up expired quotes (mark as expired if valid_until has passed)
 */
function cleanupExpiredQuotes() {
    $db = getDB();
    
    if (!_logTableExists('quotes')) {
        return 0;
    }
    
    $result = $db->query(
        "UPDATE quotes 
         SET status = 'expired' 
         WHERE status NOT IN ('converted', 'accepted') 
         AND valid_until IS NOT NULL 
         AND valid_until < CURDATE()"
    );
    
    return $result ? true : false;
}

/**
 * Get quote audit trail - trace conversions and interactions
 */
function getQuoteAuditTrail($quoteId) {
    $db = getDB();
    
    if (!_logTableExists('quotes')) {
        return [];
    }
    
    $quote = $db->fetchOne("SELECT * FROM quotes WHERE id = ?", [$quoteId]);
    if (!$quote) {
        return null;
    }
    
    $audit = [
        'quote_id' => $quoteId,
        'quote_number' => $quote['quote_number'],
        'created' => [
            'timestamp' => $quote['created_at'],
            'by_user' => $quote['created_by']
        ],
        'updated' => [
            'timestamp' => $quote['updated_at']
        ],
        'sent' => [
            'timestamp' => $quote['emailed_at'],
            'status_changed_to' => 'sent'
        ],
        'converted' => null
    ];
    
    if ($quote['status'] === 'converted' && $quote['converted_to_transaction_id']) {
        $transaction = $db->fetchOne(
            "SELECT id, transaction_number, transaction_date, user_id FROM transactions WHERE id = ?",
            [$quote['converted_to_transaction_id']]
        );
        if ($transaction) {
            $audit['converted'] = [
                'timestamp' => $quote['converted_at'],
                'to_transaction_id' => $transaction['id'],
                'transaction_number' => $transaction['transaction_number'],
                'by_user' => $transaction['user_id']
            ];
        }
    }
    
    return $audit;
}
