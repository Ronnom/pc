<?php
/**
 * Sales/POS Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class SalesModule {
    private $db;
    private $tableExistsCache = [];
    
    public function __construct() {
        $this->db = getDB();
    }

    private function getProductPriceColumn() {
        return getProductPriceColumnName();
    }

    private function paymentsHasColumn($columnName) {
        return tableColumnExists('payments', $columnName);
    }

    private function tableExists($tableName) {
        if (isset($this->tableExistsCache[$tableName])) {
            return $this->tableExistsCache[$tableName];
        }
        $row = $this->db->fetchOne(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$tableName]
        );
        $this->tableExistsCache[$tableName] = !empty($row);
        return $this->tableExistsCache[$tableName];
    }

    private function hasSerialTracking() {
        return $this->tableExists('product_serial_numbers')
            && tableColumnExists('product_serial_numbers', 'product_id')
            && tableColumnExists('product_serial_numbers', 'status');
    }

    private function getAvailableSerialRows($productId, $quantity) {
        if (!$this->hasSerialTracking()) {
            return [];
        }
        $limit = max(1, (int)$quantity);
        $sql = "SELECT id, serial_number" .
            (tableColumnExists('product_serial_numbers', 'stocked_cost_price') ? ", stocked_cost_price" : "") .
            " FROM product_serial_numbers
              WHERE product_id = ?
                AND status IN ('in_stock', 'returned')
              ORDER BY id ASC
              LIMIT {$limit}";
        return $this->db->fetchAll($sql, [$productId]);
    }

    /**
     * Generate POS invoice number format: INV-YYYYMMDD-XXXXX
     */
    private function generateInvoiceNumber() {
        $today = date('Y-m-d');
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count
             FROM transactions
             WHERE DATE(transaction_date) = ?",
            [$today]
        );
        $sequence = ((int)($result['count'] ?? 0)) + 1;
        return 'INV-' . date('Ymd') . '-' . str_pad((string)$sequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Map POS payment method to schema enum values
     */
    private function normalizePaymentMethod($method) {
        $normalized = strtolower(trim((string)$method));
        $allowed = ['cash', 'card', 'bank_transfer', 'check', 'other'];
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }
        return 'other';
    }

    /**
     * Register warranty records for sold units based on product warranty_period (months).
     */
    private function registerWarrantiesForTransactionItem($transactionItemId, $product, $transaction, $quantity, $customerId = null, $serialNumber = null) {
        $months = (int)($product['warranty_period'] ?? 0);
        if ($months <= 0 || $quantity <= 0) {
            return;
        }

        $startDate = date('Y-m-d', strtotime((string)$transaction['transaction_date']));
        $endDate = date('Y-m-d', strtotime($startDate . " +{$months} months"));

        try {
            // One warranty row per sold unit for serial-level tracking.
            for ($i = 0; $i < (int)$quantity; $i++) {
                $this->db->insert('warranties', [
                    'transaction_item_id' => $transactionItemId,
                    'product_id' => $product['id'],
                    'serial_number' => $serialNumber,
                    'customer_id' => $customerId,
                    'warranty_start' => $startDate,
                    'warranty_end' => $endDate,
                    'status' => 'active'
                ]);
            }
        } catch (Exception $e) {
            // Keep checkout flow resilient on partially migrated databases.
            error_log('Warranty registration skipped: ' . $e->getMessage());
        }
    }
    
    /**
     * Create transaction
     */
    public function createTransaction($data) {
        $this->db->beginTransaction();
        
        try {
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception('Transaction must contain at least one item');
            }

            $serialTrackingEnabled = $this->hasSerialTracking();

            // Validate stock first
            foreach ($data['items'] as $item) {
                $product = $this->db->fetchOne("SELECT * FROM products WHERE id = ? AND is_active = 1", [$item['product_id']]);
                
                if (!$product) {
                    throw new Exception("Product ID {$item['product_id']} not found");
                }
                
                if ($product['stock_quantity'] < $item['quantity']) {
                    throw new Exception("Insufficient stock for {$product['name']}");
                }

                if ($serialTrackingEnabled) {
                    $serialRows = $this->getAvailableSerialRows((int)$item['product_id'], (int)$item['quantity']);
                    $hasSerialInventory = !empty($serialRows);
                    if ($hasSerialInventory && count($serialRows) < (int)$item['quantity']) {
                        throw new Exception("Insufficient serial-numbered stock for {$product['name']}");
                    }
                }
            }

            $usePrecomputedTotals = !empty($data['use_precomputed_totals']);
            if ($usePrecomputedTotals) {
                $subtotal = (float)($data['subtotal'] ?? 0);
                $taxAmount = (float)($data['tax_amount'] ?? 0);
                $discountAmount = (float)($data['discount_amount'] ?? 0);
                $totalAmount = (float)($data['total_amount'] ?? 0);
            } else {
                // Calculate totals
                $subtotal = 0;
                $taxAmount = 0;
                $discountAmount = (float)($data['discount_amount'] ?? 0);
                foreach ($data['items'] as $item) {
                    $itemSubtotal = $item['quantity'] * $item['unit_price'];
                    $itemDiscount = $item['discount_amount'] ?? 0;
                    $itemTaxRate = isset($item['tax_rate']) ? (float)$item['tax_rate'] : 0;
                    $itemTax = calculateTax($itemSubtotal - $itemDiscount, $itemTaxRate);

                    $subtotal += $itemSubtotal;
                    $taxAmount += $itemTax;
                    $discountAmount += $itemDiscount;
                }
                $totalAmount = $subtotal + $taxAmount - $discountAmount;
            }
            
            // Create transaction
            $transactionId = $this->db->insert('transactions', [
                'transaction_number' => $data['transaction_number'] ?? $this->generateInvoiceNumber(),
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => getCurrentUserId(),
                'transaction_date' => date(DATETIME_FORMAT),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'status' => 'completed',
                'payment_status' => 'pending',
                'notes' => $data['notes'] ?? null
            ]);
            
            // Create transaction items and update stock
            $productsModule = new ProductsModule();
            foreach ($data['items'] as $item) {
                if ($usePrecomputedTotals) {
                    $itemSubtotal = (float)($item['subtotal'] ?? ($item['quantity'] * $item['unit_price']));
                    $itemDiscount = (float)($item['discount_amount'] ?? 0);
                    $itemTax = (float)($item['tax_amount'] ?? 0);
                    $itemTotal = (float)($item['total'] ?? ($itemSubtotal + $itemTax - $itemDiscount));
                } else {
                    $itemSubtotal = $item['quantity'] * $item['unit_price'];
                    $itemDiscount = (float)($item['discount_amount'] ?? 0);
                    $itemTaxRate = isset($item['tax_rate']) ? (float)$item['tax_rate'] : 0;
                    $itemTax = calculateTax($itemSubtotal - $itemDiscount, $itemTaxRate);
                    $itemTotal = $itemSubtotal + $itemTax - $itemDiscount;
                }
                
                $lineProduct = $this->db->fetchOne("SELECT * FROM products WHERE id = ?", [$item['product_id']]);
                $quantity = max(1, (int)$item['quantity']);
                $serialRows = $serialTrackingEnabled ? $this->getAvailableSerialRows((int)$item['product_id'], $quantity) : [];
                $useSerialSale = !empty($serialRows);

                if ($useSerialSale) {
                    $perUnitSubtotal = round(((float)$itemSubtotal) / $quantity, 2);
                    $perUnitDiscount = round(((float)$itemDiscount) / $quantity, 2);
                    $perUnitTax = round(((float)$itemTax) / $quantity, 2);
                    $perUnitTotal = round(((float)$itemTotal) / $quantity, 2);

                    foreach ($serialRows as $serialRow) {
                        $serialCost = isset($serialRow['stocked_cost_price']) ? (float)$serialRow['stocked_cost_price'] : null;
                        $transactionItemId = $this->db->insert('transaction_items', [
                            'transaction_id' => $transactionId,
                            'product_id' => $item['product_id'],
                            'product_serial_number_id' => (int)$serialRow['id'],
                            'serial_cost_price' => $serialCost,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'discount_amount' => $perUnitDiscount,
                            'tax_amount' => $perUnitTax,
                            'subtotal' => $perUnitSubtotal,
                            'total' => $perUnitTotal
                        ]);

                        $this->db->update(
                            'product_serial_numbers',
                            ['status' => 'sold', 'transaction_id' => $transactionId],
                            'id = ?',
                            [(int)$serialRow['id']]
                        );

                        $this->registerWarrantiesForTransactionItem(
                            $transactionItemId,
                            $lineProduct,
                            ['transaction_date' => date(DATETIME_FORMAT)],
                            1,
                            $data['customer_id'] ?? null,
                            $serialRow['serial_number'] ?? null
                        );
                    }
                } else {
                    // Insert single aggregate item for non-serialized sale.
                    $transactionItemId = $this->db->insert('transaction_items', [
                        'transaction_id' => $transactionId,
                        'product_id' => $item['product_id'],
                        'quantity' => $quantity,
                        'unit_price' => $item['unit_price'],
                        'discount_amount' => $itemDiscount,
                        'tax_amount' => $itemTax,
                        'subtotal' => $itemSubtotal,
                        'total' => $itemTotal
                    ]);

                    $this->registerWarrantiesForTransactionItem(
                        $transactionItemId,
                        $lineProduct,
                        ['transaction_date' => date(DATETIME_FORMAT)],
                        $quantity,
                        $data['customer_id'] ?? null
                    );
                }
                
                // Update stock
                $productsModule->updateStock(
                    $item['product_id'],
                    $item['quantity'],
                    'out',
                    'transaction',
                    $transactionId,
                    "Sale transaction #{$transactionId}"
                );
            }
            
            // Process payment(s) if provided
            if (!empty($data['payments']) && is_array($data['payments'])) {
                foreach ($data['payments'] as $paymentRow) {
                    $this->addPayment($transactionId, $paymentRow);
                }
            } elseif (!empty($data['payment'])) {
                $this->addPayment($transactionId, $data['payment']);
            }
            
            $this->db->commit();
            
            // Link converted quote if present in session
            if (!empty($_SESSION['converted_quote_id'])) {
                $convertedQuoteId = (int)$_SESSION['converted_quote_id'];
                $this->db->update(
                    'quotes',
                    ['status' => 'converted', 'converted_at' => date(DATETIME_FORMAT), 'converted_to_transaction_id' => $transactionId],
                    'id = ?',
                    [$convertedQuoteId]
                );
                unset($_SESSION['converted_quote_id']);
            }
            
            logUserActivity(getCurrentUserId(), 'create', 'sales', "Created transaction #{$transactionId}");
            
            return $transactionId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get transaction by ID
     */
    public function getTransaction($id) {
        return $this->db->fetchOne(
            "SELECT t.*, c.first_name, c.last_name, c.email, c.phone, u.username as cashier_name 
             FROM transactions t 
             LEFT JOIN customers c ON t.customer_id = c.id 
             LEFT JOIN users u ON t.user_id = u.id 
             WHERE t.id = ?",
            [$id]
        );
    }
    
    /**
     * Get transaction items
     */
    public function getTransactionItems($transactionId) {
        $hasSerialLink = tableColumnExists('transaction_items', 'product_serial_number_id');
        return $this->db->fetchAll(
            "SELECT ti.*, p.name as product_name, p.sku " .
            ($hasSerialLink ? ", psn.serial_number" : "") . "
             FROM transaction_items ti 
             INNER JOIN products p ON ti.product_id = p.id 
             " . ($hasSerialLink ? "LEFT JOIN product_serial_numbers psn ON psn.id = ti.product_serial_number_id" : "") . "
             WHERE ti.transaction_id = ?",
            [$transactionId]
        );
    }
    
    /**
     * Get transactions with filters
     */
    public function getTransactions($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["1=1"];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = "t.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(t.transaction_date) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(t.transaction_date) <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['customer_id'])) {
            $where[] = "t.customer_id = ?";
            $params[] = $filters['customer_id'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT t.*, c.first_name, c.last_name, u.username as cashier_name 
                FROM transactions t 
                LEFT JOIN customers c ON t.customer_id = c.id 
                LEFT JOIN users u ON t.user_id = u.id 
                WHERE {$whereClause} 
                ORDER BY t.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Add payment to transaction
     */
    public function addPayment($transactionId, $paymentData) {
        $originalMethod = strtolower(trim((string)($paymentData['method'] ?? 'other')));
        $method = $this->normalizePaymentMethod($originalMethod);
        $notes = $paymentData['notes'] ?? null;
        if ($method === 'other' && $originalMethod !== 'other') {
            $mappedNote = 'Original method: ' . $originalMethod;
            $notes = $notes ? ($mappedNote . ' | ' . $notes) : $mappedNote;
        }

        $insertData = [
            'transaction_id' => $transactionId,
            'amount' => $paymentData['amount'],
            'notes' => $notes
        ];

        if ($this->paymentsHasColumn('payment_method')) {
            $insertData['payment_method'] = $method;
        } elseif ($this->paymentsHasColumn('method')) {
            $insertData['method'] = $method;
        }

        if ($this->paymentsHasColumn('reference_number')) {
            $insertData['reference_number'] = $paymentData['reference'] ?? null;
        }

        if ($this->paymentsHasColumn('created_by')) {
            $insertData['created_by'] = getCurrentUserId();
        }

        $paymentId = $this->db->insert('payments', $insertData);
        
        // Update payment status
        $transaction = $this->getTransaction($transactionId);
        $totalPaid = $this->getTotalPaid($transactionId);
        
        if ($totalPaid >= $transaction['total_amount']) {
            $paymentStatus = 'paid';
        } elseif ($totalPaid > 0) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'pending';
        }
        
        $this->db->update('transactions', 
            ['payment_status' => $paymentStatus],
            'id = ?',
            [$transactionId]
        );
        
        return $paymentId;
    }
    
    /**
     * Get total paid for transaction
     */
    public function getTotalPaid($transactionId) {
        $result = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE transaction_id = ?",
            [$transactionId]
        );
        
        return $result['total'];
    }
    
    /**
     * Get payments for transaction
     */
    public function getPayments($transactionId) {
        if ($this->paymentsHasColumn('created_by')) {
            return $this->db->fetchAll(
                "SELECT p.*, u.username as created_by_name 
                 FROM payments p 
                 LEFT JOIN users u ON p.created_by = u.id 
                 WHERE p.transaction_id = ? 
                 ORDER BY " . ($this->paymentsHasColumn('created_at') ? "p.created_at" : "p.paid_at"),
                [$transactionId]
            );
        }

        return $this->db->fetchAll(
            "SELECT p.* 
             FROM payments p 
             WHERE p.transaction_id = ? 
             ORDER BY " . ($this->paymentsHasColumn('created_at') ? "p.created_at" : "p.paid_at"),
            [$transactionId]
        );
    }
    
    /**
     * Process return
     */
    public function processReturn($transactionId, $productId, $quantity, $reason, $options = []) {
        if (!$this->tableExists('returns') || !$this->tableExists('return_items')) {
            throw new Exception("Returns tables are missing. Run migration: database/migrations/20260218_add_returns_table.sql");
        }

        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('Transaction not found');
        }
        
        $transactionItemId = (int)($options['transaction_item_id'] ?? 0);
        if ($transactionItemId > 0) {
            $transactionItem = $this->db->fetchOne(
                "SELECT * FROM transaction_items WHERE id = ? AND transaction_id = ?",
                [$transactionItemId, $transactionId]
            );
            if ($transactionItem && (int)$transactionItem['product_id'] !== (int)$productId) {
                throw new Exception('Transaction item does not match selected product');
            }
        } else {
            $transactionItem = $this->db->fetchOne(
                "SELECT * FROM transaction_items WHERE transaction_id = ? AND product_id = ?",
                [$transactionId, $productId]
            );
        }
        
        if (!$transactionItem) {
            throw new Exception('Product not found in transaction');
        }

        $returned = $this->db->fetchOne(
            "SELECT COALESCE(SUM(ri.quantity), 0) as total_returned
             FROM return_items ri
             INNER JOIN returns r ON ri.return_id = r.id
             WHERE r.transaction_id = ?
               AND ri.transaction_item_id = ?",
            [$transactionId, (int)$transactionItem['id']]
        );
        $alreadyReturned = (int)($returned['total_returned'] ?? 0);
        $remaining = (int)$transactionItem['quantity'] - $alreadyReturned;

        if ($quantity > $remaining) {
            throw new Exception('Return quantity cannot exceed remaining unreturned quantity');
        }
        
        $this->db->beginTransaction();
        
        try {
            $refundMode = strtolower((string)($options['refund_mode'] ?? 'original'));
            $restockingFeePercent = max(0, (float)($options['restocking_fee_percent'] ?? 0));
            $restockingFeeAmount = max(0, (float)($options['restocking_fee_amount'] ?? 0));

            // Calculate refund amount
            if ($refundMode === 'current') {
                $priceColumn = $this->getProductPriceColumn();
                $product = $this->db->fetchOne("SELECT {$priceColumn} FROM products WHERE id = ?", [$productId]);
                $unitAmount = (float)($product[$priceColumn] ?? 0);
            } else {
                $unitAmount = ((float)$transactionItem['total']) / max(1, (int)$transactionItem['quantity']);
            }
            $refundAmount = $unitAmount * $quantity;

            if ($restockingFeePercent > 0) {
                $refundAmount -= ($refundAmount * ($restockingFeePercent / 100));
            }
            if ($restockingFeeAmount > 0) {
                $refundAmount -= $restockingFeeAmount;
            }
            if ($refundAmount < 0) {
                $refundAmount = 0;
            }
            $grossBeforeFees = $unitAmount * $quantity;
            $restockingFeeValue = max(0, $grossBeforeFees - $refundAmount);

            $conditionNote = trim((string)($options['notes'] ?? ''));
             
            // Create return header
            $returnData = [
                'transaction_id' => $transactionId,
                'user_id' => getCurrentUserId(),
                'total_refund_amount' => $refundAmount,
                'restocking_fee' => $restockingFeeValue
            ];
            $returnId = $this->db->insert('returns', $returnData);

            // Create return detail
            $this->db->insert('return_items', [
                'return_id' => $returnId,
                'transaction_item_id' => (int)$transactionItem['id'],
                'product_serial_number_id' => $options['product_serial_number_id'] ?? null,
                'quantity' => $quantity,
                'reason' => $reason,
                'condition_note' => $conditionNote
            ]);
            
            $serialReturnStatus = strtolower(trim((string)($options['serial_return_status'] ?? '')));
            if ($serialReturnStatus === '') {
                $serialReturnStatus = in_array(strtolower($reason), ['defective', 'damaged'], true) ? 'damaged' : 'returned';
            }

            $isDamaged = $serialReturnStatus === 'damaged';
            if (!$isDamaged) {
                // Restore stock only for resellable returned units.
                $productsModule = new ProductsModule();
                $productsModule->updateStock(
                    $productId,
                    $quantity,
                    'return',
                    'return',
                    $returnId,
                    "Return for transaction #{$transactionId}"
                );
            }

            if (
                tableColumnExists('transaction_items', 'product_serial_number_id')
                && !empty($transactionItem['product_serial_number_id'])
                && $this->tableExists('product_serial_numbers')
            ) {
                $this->db->update(
                    'product_serial_numbers',
                    [
                        'status' => $serialReturnStatus,
                        'transaction_id' => null
                    ],
                    'id = ?',
                    [(int)$transactionItem['product_serial_number_id']]
                );
            }
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'return', 'sales', "Processed return for transaction #{$transactionId}");
            
            return $returnId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
