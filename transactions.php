<?php
/**
 * Transaction Recording API
 * Handles transaction recording, auto-save, and status management
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.create');

header('Content-Type: application/json');
$db = getDB();
$pdo = $db->getConnection();

// Only allow POST for transaction save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate and sanitize input
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }

        // Required fields
        $items = $data['items'] ?? [];
        $payments = $data['payments'] ?? [];
        $customer_id = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
        $user_id = getCurrentUserId();
        $subtotal = (float)($data['subtotal'] ?? 0);
        $tax_amount = (float)($data['tax_amount'] ?? 0);
        $discount_amount = (float)($data['discount_amount'] ?? 0);
        $total_amount = (float)($data['total_amount'] ?? 0);
        
        // Validate status
        $valid_statuses = ['pending', 'completed', 'refunded', 'voided', 'on-hold'];
        $status = in_array(($data['status'] ?? 'completed'), $valid_statuses, true)
            ? $data['status']
            : 'completed';
        
        $payment_status = in_array(($data['payment_status'] ?? 'pending'), ['pending', 'partial', 'paid'], true)
            ? $data['payment_status']
            : 'pending';
        
        $notes = sanitize($data['notes'] ?? '');

        if (!$user_id || empty($items) || empty($payments)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }

        // Begin transaction
        $pdo->beginTransaction();

        // Generate transaction number
        $transaction_number = 'TXN-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));

        // Insert transaction
        $stmt = $pdo->prepare(
            "INSERT INTO transactions (transaction_number, customer_id, user_id, transaction_date, 
             subtotal, tax_amount, discount_amount, total_amount, status, payment_status, notes) 
             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $transaction_number,
            $customer_id,
            $user_id,
            $subtotal,
            $tax_amount,
            $discount_amount,
            $total_amount,
            $status,
            $payment_status,
            $notes
        ]);
        $transaction_id = (int)$pdo->lastInsertId();

        // Insert items
        $item_stmt = $pdo->prepare(
            "INSERT INTO transaction_items (transaction_id, product_id, quantity, unit_price, 
             discount_amount, tax_amount, total) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            $unitPrice = (float)($item['unit_price'] ?? 0);
            $itemDiscount = (float)($item['discount_amount'] ?? 0);
            $itemTax = (float)($item['tax_amount'] ?? 0);
            $itemTotal = (float)($item['total'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                throw new Exception('Invalid transaction item.');
            }

            $item_stmt->execute([
                $transaction_id,
                $productId,
                $quantity,
                $unitPrice,
                $itemDiscount,
                $itemTax,
                $itemTotal
            ]);

            // Deduct stock for completed transactions
            if ($status === 'completed') {
                $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?")
                    ->execute([$quantity, $productId]);

                // Record stock movement
                $db->insert('product_stock_adjustments', [
                    'product_id' => $productId,
                    'adjustment_type' => 'sale',
                    'quantity_change' => -$quantity,
                    'previous_quantity' => $quantity, // This would need to be fetched properly
                    'new_quantity' => 0, // This would be the new stock level
                    'reason' => 'POS sale transaction',
                    'reference_id' => $transaction_id,
                    'adjusted_by' => $user_id
                ]);
            }
        }

        // Insert payments
        $pay_stmt = $pdo->prepare(
            "INSERT INTO payments (transaction_id, payment_method, amount, reference_number, notes, created_by) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        foreach ($payments as $pay) {
            $paymentMethod = $pay['payment_method'] ?? 'cash';
            $amount = (float)($pay['amount'] ?? 0);
            $validMethods = ['cash', 'card', 'bank_transfer', 'check', 'other'];
            
            if (!in_array($paymentMethod, $validMethods, true) || $amount <= 0) {
                throw new Exception('Invalid payment data.');
            }

            $pay_stmt->execute([
                $transaction_id,
                $paymentMethod,
                $amount,
                sanitize($pay['reference_number'] ?? '') ?: null,
                sanitize($pay['notes'] ?? ''),
                $user_id
            ]);
        }

        // Commit transaction
        $pdo->commit();

        // Log activity
        logUserActivity($user_id, 'create_transaction', 'sales', "Transaction {$transaction_number} created. Amount: {$total_amount}", $transaction_id);

        echo json_encode([
            'success' => true,
            'transaction_id' => $transaction_id,
            'transaction_number' => $transaction_number,
            'status' => $status,
            'timestamp' => date('c')
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'error' => 'Transaction failed',
            'details' => $e->getMessage()
        ]);
    }
    exit;
}

// GET: Redirect to transaction history
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    redirect(getBaseUrl() . '/transaction_history.php');
}

// Method not allowed
http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'Method not allowed']);
