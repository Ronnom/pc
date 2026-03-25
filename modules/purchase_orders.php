<?php
/**
 * Purchase Orders Module
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class PurchaseOrdersModule {
    private $db;
    private $poColumns = null;
    private $poiColumns = null;
    
    public function __construct() {
        $this->db = getDB();
    }

    private function loadColumns($table) {
        $rows = $this->db->fetchAll(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$table]
        );
        $set = [];
        foreach ($rows as $row) {
            $set[$row['COLUMN_NAME']] = true;
        }
        return $set;
    }

    private function poHasColumn($column) {
        if ($this->poColumns === null) {
            $this->poColumns = $this->loadColumns('purchase_orders');
        }
        return isset($this->poColumns[$column]);
    }

    private function poiHasColumn($column) {
        if ($this->poiColumns === null) {
            $this->poiColumns = $this->loadColumns('purchase_order_items');
        }
        return isset($this->poiColumns[$column]);
    }
    
    /**
     * Create purchase order
     */
    public function createPurchaseOrder($data) {
        $this->db->beginTransaction();
        
        try {
            // Calculate totals
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $unitCost = isset($item['unit_cost']) ? $item['unit_cost'] : ($item['unit_price'] ?? 0);
                $subtotal += $item['quantity'] * $unitCost;
            }
            
            $taxAmount = calculateTax($subtotal, $data['tax_rate'] ?? 0);
            $shippingCost = $data['shipping_cost'] ?? 0;
            $totalAmount = $subtotal + $taxAmount + $shippingCost;
            
            // Create PO
            $poData = [
                'po_number' => generatePONumber(),
                'supplier_id' => $data['supplier_id'],
                'order_date' => $data['order_date'] ?? date(DATE_FORMAT),
                'total_amount' => $totalAmount,
                'status' => $data['status'] ?? 'draft'
            ];
            if ($this->poHasColumn('expected_date')) {
                $poData['expected_date'] = $data['expected_date'] ?? $data['expected_delivery_date'] ?? null;
            }
            if ($this->poHasColumn('notes')) {
                $poData['notes'] = $data['notes'] ?? null;
            }
            if ($this->poHasColumn('terms')) {
                $poData['terms'] = $data['terms'] ?? null;
            }
            if ($this->poHasColumn('created_by')) {
                $poData['created_by'] = getCurrentUserId();
            }
            $poId = $this->db->insert('purchase_orders', $poData);
            
            // Create PO items
            foreach ($data['items'] as $item) {
                $unitCost = isset($item['unit_cost']) ? $item['unit_cost'] : ($item['unit_price'] ?? 0);
                $lineTotal = $item['quantity'] * $unitCost;
                $itemData = [
                    'purchase_order_id' => $poId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity']
                ];
                if ($this->poiHasColumn('unit_cost')) {
                    $itemData['unit_cost'] = $unitCost;
                }
                if ($this->poiHasColumn('unit_price')) {
                    $itemData['unit_price'] = $unitCost;
                }
                if ($this->poiHasColumn('total')) {
                    $itemData['total'] = $lineTotal;
                }
                if ($this->poiHasColumn('total_cost')) {
                    $itemData['total_cost'] = $lineTotal;
                }
                if ($this->poiHasColumn('subtotal')) {
                    $itemData['subtotal'] = $lineTotal;
                }
                if ($this->poiHasColumn('received_quantity')) {
                    $itemData['received_quantity'] = 0;
                }
                $this->db->insert('purchase_order_items', $itemData);
            }
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'create', 'purchase', "Created purchase order #{$poId}");
            
            return $poId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get purchase order by ID
     */
    public function getPurchaseOrder($id) {
        $createdByJoin = $this->poHasColumn('created_by') ? "LEFT JOIN users u ON po.created_by = u.id" : "";
        $createdBySelect = $this->poHasColumn('created_by') ? "u.username as created_by_name" : "NULL as created_by_name";
        return $this->db->fetchOne(
            "SELECT po.*, s.name as supplier_name, s.contact_name as contact_person, s.email as supplier_email, 
                    s.phone as supplier_phone, {$createdBySelect}
             FROM purchase_orders po 
             INNER JOIN suppliers s ON po.supplier_id = s.id 
             {$createdByJoin}
             WHERE po.id = ?",
            [$id]
        );
    }
    
    /**
     * Get purchase order items
     */
    public function getPurchaseOrderItems($poId) {
        return $this->db->fetchAll(
            "SELECT poi.*, p.name as product_name, p.sku 
             FROM purchase_order_items poi 
             INNER JOIN products p ON poi.product_id = p.id 
             WHERE poi.purchase_order_id = ?",
            [$poId]
        );
    }
    
    /**
     * Get purchase orders with filters
     */
    public function getPurchaseOrders($filters = [], $limit = ITEMS_PER_PAGE, $offset = 0) {
        $where = ["1=1"];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = "po.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $where[] = "po.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $createdByJoin = $this->poHasColumn('created_by') ? "LEFT JOIN users u ON po.created_by = u.id" : "";
        $createdBySelect = $this->poHasColumn('created_by') ? "u.username as created_by_name" : "NULL as created_by_name";
        $sql = "SELECT po.*, s.name as supplier_name, {$createdBySelect}
                FROM purchase_orders po 
                INNER JOIN suppliers s ON po.supplier_id = s.id 
                {$createdByJoin}
                WHERE {$whereClause} 
                ORDER BY po.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Receive purchase order items
     */
    public function receiveItems($poId, $items) {
        $po = $this->getPurchaseOrder($poId);
        if (!$po) {
            throw new Exception('Purchase order not found');
        }
        
        $this->db->beginTransaction();
        
        try {
            $productsModule = new ProductsModule();
            
            foreach ($items as $item) {
                $poItem = $this->db->fetchOne(
                    "SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?",
                    [$item['item_id'], $poId]
                );
                
                if (!$poItem) {
                    throw new Exception("PO item not found");
                }
                
                $receivedQty = $item['received_quantity'];
                $currentReceived = $this->poiHasColumn('received_quantity') ? (float)$poItem['received_quantity'] : 0.0;
                $maxQty = $poItem['quantity'] - $currentReceived;
                
                if ($receivedQty > $maxQty) {
                    throw new Exception("Cannot receive more than ordered quantity");
                }
                
                // Update received quantity
                if ($this->poiHasColumn('received_quantity')) {
                    $newReceivedQty = $currentReceived + $receivedQty;
                    $this->db->update('purchase_order_items',
                        ['received_quantity' => $newReceivedQty],
                        'id = ?',
                        [$item['item_id']]
                    );
                }
                
                // Update stock
                $productsModule->updateStock(
                    $poItem['product_id'],
                    $receivedQty,
                    'in',
                    'purchase_order',
                    $poId,
                    "Received from PO #{$poId}"
                );
            }
            
            // Check if all items received
            $allItems = $this->getPurchaseOrderItems($poId);
            $allReceived = true;
            foreach ($allItems as $item) {
                if ($this->poiHasColumn('received_quantity')) {
                    if ($item['received_quantity'] < $item['quantity']) {
                        $allReceived = false;
                        break;
                    }
                } else {
                    $allReceived = false;
                }
            }
            
            if ($allReceived) {
                $this->db->update('purchase_orders',
                    ['status' => 'received'],
                    'id = ?',
                    [$poId]
                );
            } else {
                $this->db->update('purchase_orders',
                    ['status' => 'confirmed'],
                    'id = ?',
                    [$poId]
                );
            }
            
            $this->db->commit();
            
            logUserActivity(getCurrentUserId(), 'receive', 'purchase', "Received items for PO #{$poId}");
            
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Update PO status
     */
    public function updateStatus($poId, $status) {
        $this->db->update('purchase_orders',
            ['status' => $status],
            'id = ?',
            [$poId]
        );
        
        logUserActivity(getCurrentUserId(), 'update_status', 'purchase', "Updated PO #{$poId} status to {$status}");
        
        return true;
    }
}

