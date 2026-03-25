<?php
// Transaction Void Handler (admin only)
require_once __DIR__ . '/../includes/init.php';
requireLogin();
requirePermission('sales.refund');

if (!isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $transaction_id = (int)($_POST['transaction_id'] ?? 0);
    $void_reason = trim($_POST['void_reason'] ?? '');
    $admin_id = getCurrentUserId();
    if ($transaction_id <= 0 || !$void_reason || !$admin_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields.']);
        exit;
    }
    try {
        $db->beginTransaction();

        $transaction = $db->fetchOne(
            "SELECT id, status, notes FROM transactions WHERE id = ? FOR UPDATE",
            [$transaction_id]
        );
        if (!$transaction) {
            throw new Exception('Transaction not found.');
        }
        if (!in_array($transaction['status'], ['pending', 'completed'], true)) {
            throw new Exception('Only pending/completed transactions can be voided.');
        }

        // Restore inventory for each item
        $items = $db->fetchAll(
            "SELECT product_id, quantity FROM transaction_items WHERE transaction_id = ?",
            [$transaction_id]
        );
        foreach ($items as $item) {
            // Update stock (increase by quantity sold)
            $db->query(
                "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?",
                [$item['quantity'], $item['product_id']]
            );

            $db->insert('stock_movements', [
                'product_id' => (int)$item['product_id'],
                'movement_type' => 'in',
                'quantity' => (int)$item['quantity'],
                'reference_type' => 'transaction_void',
                'reference_id' => $transaction_id,
                'notes' => 'Stock restored after void: ' . $void_reason,
                'created_by' => $admin_id
            ]);
        }

        // If payment exists, mark as refunded. Otherwise cancel.
        $paymentCountRow = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM payments WHERE transaction_id = ?",
            [$transaction_id]
        );
        $hasPayments = (int)($paymentCountRow['cnt'] ?? 0) > 0;

        $newStatus = $hasPayments ? 'refunded' : 'cancelled';
        $newPaymentStatus = $hasPayments ? 'paid' : 'pending';

        $db->query(
            "UPDATE transactions
             SET status = ?, payment_status = ?, notes = CONCAT(IFNULL(notes, ''), '\nVOIDED: ', ?)
             WHERE id = ?",
            [$newStatus, $newPaymentStatus, $void_reason, $transaction_id]
        );

        logUserActivity($admin_id, 'void', 'sales', "Voided transaction #{$transaction_id}. Reason: {$void_reason}");

        $db->commit();
        echo json_encode([
            'success' => true,
            'transaction_id' => $transaction_id,
            'new_status' => $newStatus
        ]);
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->rollback();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Void failed', 'details' => $e->getMessage()]);
    }
    exit;
}
http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['error' => 'Method not allowed']);
