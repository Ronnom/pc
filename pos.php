<?php
require_once 'includes/init.php';
requireLogin();
$ajaxAction = $_GET['action'] ?? $_POST['action'] ?? '';
$isReturnsAjaxAction = isset($_GET['ajax']) && $_GET['ajax'] === '1' && in_array(
    $ajaxAction,
    ['search_transactions', 'get_transaction_items', 'process_return', 'process_exchange'],
    true
);
if ($isReturnsAjaxAction) {
    requirePermission('sales.refund');
} else {
    requirePermission('sales.create');
}

$pageTitle = 'Point of Sale';
$db = getDB();
$productsModule = new ProductsModuleEnhanced();
$categoriesModule = new CategoriesModule();
$customersModule = new CustomersModule();
$salesModule = new SalesModule();
$hasProductBarcode = tableColumnExists('products', 'barcode');

$_SESSION['pos_cart'] = $_SESSION['pos_cart'] ?? [];
$_SESSION['pos_settings'] = $_SESSION['pos_settings'] ?? [
    'tax_rate' => 0,
    'tax_inclusive' => false,
    'discount_type' => 'none',
    'discount_value' => 0,
    'discount_reason' => '',
    'promo_code' => '',
    'senior_pwd_type' => '',
    'senior_pwd_id' => '',
    'customer_id' => null
];
$_SESSION['pos_mode'] = $_SESSION['pos_mode'] ?? 'pos';
$_SESSION['converted_quote_id'] = $_SESSION['converted_quote_id'] ?? null;
$quoteLoadError = '';
$quoteLoadNotice = '';
$selectedCustomerName = 'Guest';
$selectedCustomerId = null;

$requestedPosMode = strtolower(trim((string)($_GET['mode'] ?? '')));
if (in_array($requestedPosMode, ['pos', 'quote'], true)) {
    $_SESSION['pos_mode'] = $requestedPosMode;
}

// This page is checkout-only; keep it in POS mode to ensure payment controls are available.
$_SESSION['pos_mode'] = 'pos';

// POS pricing rules disabled: force neutral pricing settings.
$_SESSION['pos_settings']['tax_rate'] = 0;
$_SESSION['pos_settings']['tax_inclusive'] = false;
$_SESSION['pos_settings']['discount_type'] = 'none';
$_SESSION['pos_settings']['discount_value'] = 0;
$_SESSION['pos_settings']['discount_reason'] = '';
$_SESSION['pos_settings']['promo_code'] = '';
$_SESSION['pos_settings']['senior_pwd_type'] = '';
$_SESSION['pos_settings']['senior_pwd_id'] = '';

// Load quote into POS cart when requested.
$quoteIdParam = (int)($_GET['quote_id'] ?? 0);
if ($quoteIdParam > 0) {
    try {
        $quoteWhere = "id = ? AND status NOT IN ('converted', 'expired')";
        if (tableColumnExists('quotes', 'deleted_at')) {
            $quoteWhere .= " AND deleted_at IS NULL";
        }
        $quote = $db->fetchOne("SELECT * FROM quotes WHERE {$quoteWhere} LIMIT 1", [$quoteIdParam]);
        if (!$quote) {
            throw new Exception('Quote not found or unavailable for conversion.');
        }

        $quoteItems = $db->fetchAll(
            "SELECT qi.*, p.sku, p.name, p.stock_quantity
             FROM quote_items qi
             INNER JOIN products p ON p.id = qi.product_id
             WHERE qi.quote_id = ?
             ORDER BY qi.id ASC",
            [$quoteIdParam]
        );

        if (empty($quoteItems)) {
            throw new Exception('Quote has no items to convert.');
        }

        $_SESSION['pos_cart'] = [];
        $adjusted = [];
        foreach ($quoteItems as $item) {
            $pid = (int)$item['product_id'];
            $qty = max(1, (int)$item['quantity']);
            $stockQty = (int)($item['stock_quantity'] ?? 0);
            if ($qty > $stockQty) {
                $adjusted[] = $item['name'];
            }
            if (!isset($_SESSION['pos_cart'][$pid])) {
                $img = $db->fetchOne("SELECT image_path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC,id ASC LIMIT 1", [$pid]);
                $_SESSION['pos_cart'][$pid] = [
                    'product_id' => $pid,
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'image' => $img['image_path'] ?? null,
                    'unit_price' => (float)$item['unit_price'],
                    'stock_quantity' => $stockQty,
                    'quantity' => 0,
                    'discount_type' => 'none',
                    'discount_value' => 0
                ];
            }
            $_SESSION['pos_cart'][$pid]['quantity'] += $qty;
        }

        if (empty($_SESSION['pos_cart'])) {
            throw new Exception('No quote items are available to convert.');
        }

        $_SESSION['pos_settings']['customer_id'] = $quote['customer_id'] ?? null;
        $selectedCustomerId = $_SESSION['pos_settings']['customer_id'] ?: null;
        if ($selectedCustomerId) {
            $cust = $customersModule->getCustomer($selectedCustomerId);
            if ($cust) {
                $selectedCustomerName = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')) ?: 'Guest';
            }
        }
        $_SESSION['converted_quote_id'] = $quoteIdParam;
        $quoteLoadNotice = 'Quote loaded into POS cart.';
        if (!empty($adjusted)) {
            $quoteLoadNotice = 'Quote loaded with stock warnings for: ' . implode(', ', array_unique($adjusted)) . '.';
        }
    } catch (Exception $e) {
        $quoteLoadError = $e->getMessage();
        $_SESSION['converted_quote_id'] = null;
    }
}

if (!$selectedCustomerId && !empty($_SESSION['pos_settings']['customer_id'])) {
    $selectedCustomerId = $_SESSION['pos_settings']['customer_id'];
    $cust = $customersModule->getCustomer($selectedCustomerId);
    if ($cust) {
        $selectedCustomerName = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')) ?: 'Guest';
    }
}

function posJson($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function posPromo($code, $base) {
    $defs = [
        'WELCOME10' => ['type' => 'percent', 'value' => 10, 'min' => 0],
        'SAVE50' => ['type' => 'fixed', 'value' => 50, 'min' => 500]
    ];
    $code = strtoupper(trim((string)$code));
    if ($code === '' || !isset($defs[$code]) || $base < $defs[$code]['min']) return 0;
    return $defs[$code]['type'] === 'percent' ? $base * ($defs[$code]['value'] / 100) : $defs[$code]['value'];
}

function posHasSerialTracking($db) {
    return posTableExists($db, 'product_serial_numbers')
        && tableColumnExists('product_serial_numbers', 'product_id')
        && tableColumnExists('product_serial_numbers', 'serial_number')
        && tableColumnExists('product_serial_numbers', 'status');
}

function posFindAvailableSerialRow($db, $code) {
    if (!posHasSerialTracking($db)) {
        return null;
    }
    $code = trim((string)$code);
    if ($code === '') {
        return null;
    }
    return $db->fetchOne(
        "SELECT psn.id, psn.product_id, psn.serial_number, " .
        (tableColumnExists('product_serial_numbers', 'stocked_cost_price') ? "psn.stocked_cost_price, " : "") .
        "p.name, p.sku, p.stock_quantity, p.is_active
         FROM product_serial_numbers psn
         INNER JOIN products p ON p.id = psn.product_id
         WHERE psn.serial_number = ?
           AND psn.status IN ('in_stock', 'returned')
         LIMIT 1",
        [$code]
    );
}

function posGetAvailableSerialCount($db, $productId) {
    if (!posHasSerialTracking($db)) {
        return 0;
    }
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM product_serial_numbers
         WHERE product_id = ?
           AND status IN ('in_stock', 'returned')",
        [(int)$productId]
    );
    return (int)($row['cnt'] ?? 0);
}

function posTotals($cart, $settings) {
    $taxRate = max(0, (float)$settings['tax_rate']);
    $inclusive = !empty($settings['tax_inclusive']);
    $gross = 0; $lineDiscount = 0; $items = [];
    foreach ($cart as $line) {
        $qty = max(1, (int)$line['quantity']);
        $price = max(0, (float)$line['unit_price']);
        $lineGross = $qty * $price;
        $bulk = $qty >= 10 ? 10 : ($qty >= 5 ? 5 : 0);
        $bulkDiscount = $lineGross * ($bulk / 100);
        $manual = 0;
        if (($line['discount_type'] ?? 'none') === 'percent') $manual = $lineGross * (((float)$line['discount_value']) / 100);
        if (($line['discount_type'] ?? 'none') === 'fixed') $manual = (float)$line['discount_value'];
        $disc = min($lineGross, max(0, $bulkDiscount + $manual));
        $taxable = $lineGross - $disc;
        $tax = $inclusive ? ($taxRate > 0 ? $taxable * ($taxRate / (100 + $taxRate)) : 0) : ($taxable * ($taxRate / 100));
        $subtotal = $inclusive ? $taxable - $tax : $taxable;
        $total = $inclusive ? $taxable : $taxable + $tax;
        $gross += $lineGross; $lineDiscount += $disc;
        $items[] = [
            'product_id' => (int)$line['product_id'],
            'name' => $line['name'],
            'sku' => $line['sku'],
            'image' => $line['image'],
            'quantity' => $qty,
            'unit_price' => round($price, 2),
            'stock_quantity' => (int)$line['stock_quantity'],
            'has_serial_tracking' => !empty($line['has_serial_tracking']),
            'scanned_serials' => array_values(array_filter(array_map('strval', $line['scanned_serials'] ?? []))),
            'scanned_serial_ids' => array_values(array_map('intval', $line['scanned_serial_ids'] ?? [])),
            'line_discount' => round($disc, 2),
            'tax_amount' => round($tax, 2),
            'subtotal' => round($subtotal, 2),
            'total' => round($total, 2)
        ];
    }
    $base = max(0, $gross - $lineDiscount);
    $txn = 0;
    if (($settings['discount_type'] ?? 'none') === 'percent') $txn += $base * (((float)$settings['discount_value']) / 100);
    if (($settings['discount_type'] ?? 'none') === 'fixed') $txn += (float)$settings['discount_value'];
    $txn += posPromo($settings['promo_code'] ?? '', $base);
    if (in_array(strtolower((string)$settings['senior_pwd_type']), ['senior', 'pwd'], true) && preg_match('/^[A-Za-z0-9\-]{5,}$/', (string)$settings['senior_pwd_id'])) {
        $txn += $base * 0.20;
    }
    $txn = min($txn, $base);
    $final = $base - $txn;
    $tax = $inclusive ? ($taxRate > 0 ? $final * ($taxRate / (100 + $taxRate)) : 0) : ($final * ($taxRate / 100));
    $subtotal = $inclusive ? $final - $tax : $final;
    $total = $inclusive ? $final : $final + $tax;
    return [
        'items' => $items,
        'totals' => [
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'discount' => round($lineDiscount + $txn, 2),
            'total' => round($total, 2)
        ]
    ];
}

function posReceiptHtml($salesModule, $txId) {
    $tx = $salesModule->getTransaction($txId);
    if (!$tx) return '<div class="alert alert-danger">Receipt not found.</div>';
    $items = $salesModule->getTransactionItems($txId);
    $payments = $salesModule->getPayments($txId);
    $paid = 0; foreach ($payments as $p) $paid += (float)$p['amount'];
    $customerName = trim((string)($tx['customer_name'] ?? ''));
    if ($customerName === '') {
        $first = trim((string)($tx['first_name'] ?? ''));
        $last = trim((string)($tx['last_name'] ?? ''));
        $customerName = trim($first . ' ' . $last);
    }
    if ($customerName === '') {
        $customerName = 'Guest';
    }
    ob_start(); ?>
    <div class="small">
        <h6 class="text-center mb-1"><?php echo escape(APP_NAME); ?></h6>
        <div>TX: <strong><?php echo escape($tx['transaction_number']); ?></strong></div>
        <div>Date: <?php echo escape(formatDateTime($tx['transaction_date'])); ?></div>
        <div>Cashier: <?php echo escape($tx['cashier_name']); ?></div>
        <div>Customer: <?php echo escape($customerName); ?></div>
        <hr>
        <?php foreach ($items as $it): ?>
            <div class="d-flex justify-content-between">
                <span>
                    <?php echo escape($it['product_name']); ?> x<?php echo (int)$it['quantity']; ?>
                    <?php if (!empty($it['serial_number'])): ?>
                        <br><small class="text-muted">SN: <?php echo escape($it['serial_number']); ?></small>
                    <?php endif; ?>
                </span>
                <span><?php echo number_format((float)$it['total'], 2); ?></span>
            </div>
        <?php endforeach; ?>
        <hr>
        <div class="d-flex justify-content-between"><span>Subtotal</span><span><?php echo number_format((float)$tx['subtotal'], 2); ?></span></div>
        <div class="d-flex justify-content-between"><span>Tax</span><span><?php echo number_format((float)$tx['tax_amount'], 2); ?></span></div>
        <div class="d-flex justify-content-between"><span>Discount</span><span><?php echo number_format((float)$tx['discount_amount'], 2); ?></span></div>
        <div class="d-flex justify-content-between fw-bold"><span>Total</span><span><?php echo number_format((float)$tx['total_amount'], 2); ?></span></div>
        <div class="d-flex justify-content-between"><span>Paid</span><span><?php echo number_format($paid, 2); ?></span></div>
        <div class="d-flex justify-content-between"><span>Change</span><span><?php echo number_format(max(0, $paid - (float)$tx['total_amount']), 2); ?></span></div>
    </div>
    <?php return ob_get_clean();
}

function posTableExists($db, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }
    $row = $db->fetchOne(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1",
        [$tableName]
    );
    $cache[$tableName] = !empty($row);
    return $cache[$tableName];
}

function posGetTransactionItemSerialPool($db, $transactionId, $transactionItemId, $productId) {
    if (
        posTableExists($db, 'transaction_items')
        && tableColumnExists('transaction_items', 'product_serial_number_id')
        && posTableExists($db, 'product_serial_numbers')
    ) {
        $row = $db->fetchOne(
            "SELECT psn.serial_number
             FROM transaction_items ti
             LEFT JOIN product_serial_numbers psn ON psn.id = ti.product_serial_number_id
             WHERE ti.id = ?
               AND ti.transaction_id = ?
             LIMIT 1",
            [$transactionItemId, $transactionId]
        );
        $serial = trim((string)($row['serial_number'] ?? ''));
        return $serial !== '' ? [$serial] : [];
    }

    return [];
}

function posGetReturnableTransactionItems($db, $transactionId) {
    $rows = $db->fetchAll(
        "SELECT
            ti.id AS transaction_item_id,
            ti.product_id,
            " . (tableColumnExists('transaction_items', 'product_serial_number_id') ? "ti.product_serial_number_id," : "NULL AS product_serial_number_id,") . "
            " . (tableColumnExists('transaction_items', 'serial_cost_price') ? "ti.serial_cost_price," : "NULL AS serial_cost_price,") . "
            COALESCE(p.name, CONCAT('Product #', ti.product_id)) AS item_name,
            COALESCE(p.name, CONCAT('Product #', ti.product_id)) AS product_name,
            COALESCE(p.sku, '') AS sku,
            ti.quantity AS original_qty,
            ti.unit_price,
            ti.total
            " . (tableColumnExists('transaction_items', 'product_serial_number_id') ? ", psn.serial_number" : ", NULL AS serial_number") . "
         FROM transaction_items ti
         LEFT JOIN products p ON p.id = ti.product_id
         " . (tableColumnExists('transaction_items', 'product_serial_number_id') ? "LEFT JOIN product_serial_numbers psn ON psn.id = ti.product_serial_number_id" : "") . "
         WHERE ti.transaction_id = ?
         ORDER BY ti.id ASC",
        [$transactionId]
    );

    if (empty($rows)) {
        return [];
    }

    $returnedMap = [];
    if (posTableExists($db, 'returns') && posTableExists($db, 'return_items')) {
        try {
            $ret = $db->fetchAll(
                "SELECT ri.transaction_item_id, COALESCE(SUM(ri.quantity),0) AS returned_qty
                 FROM return_items ri
                 INNER JOIN returns r ON ri.return_id = r.id
                 WHERE r.transaction_id = ?
                 GROUP BY ri.transaction_item_id",
                [$transactionId]
            );
            foreach ($ret as $r) {
                $returnedMap[(int)$r['transaction_item_id']] = (int)($r['returned_qty'] ?? 0);
            }
        } catch (Exception $e) {
            // Keep return flow functional even on partially migrated schemas.
            $returnedMap = [];
        }
    }

    foreach ($rows as &$row) {
        $alreadyReturned = $returnedMap[(int)$row['transaction_item_id']] ?? 0;
        $row['already_returned_qty'] = $alreadyReturned;
        $row['returnable_qty'] = max(0, (int)$row['original_qty'] - $alreadyReturned);
        $lineQty = max(1, (int)$row['original_qty']);
        $row['unit_refund_amount'] = round(((float)$row['total']) / $lineQty, 2);
        $serialPool = posGetTransactionItemSerialPool(
            $db,
            $transactionId,
            (int)$row['transaction_item_id'],
            (int)$row['product_id']
        );
        $row['serial_numbers'] = $serialPool;
        $row['requires_serial_scan'] = !empty($serialPool);
    }
    unset($row);

    return $rows;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    try {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if (in_array($action, ['add_to_cart','update_cart','remove_cart_item','clear_cart','apply_settings','set_customer','quick_add_customer','checkout','process_return','process_exchange'], true)) validateCSRF();


        if ($action === 'search_products') {
            $filters = ['search' => trim((string)($_GET['search'] ?? ''))];
            if (($_GET['category_id'] ?? '') !== '') $filters['category_id'] = (int)$_GET['category_id'];
            $page = max(1, (int)($_GET['page'] ?? 1)); $per = 18; $offset = ($page - 1) * $per;
            $products = $productsModule->getProducts($filters, $per, $offset, 'name', 'ASC');
            $count = $productsModule->getProductCount($filters);
            foreach ($products as &$p) {
                $img = $db->fetchOne("SELECT image_path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC,id ASC LIMIT 1", [$p['id']]);
                $p['image'] = $img['image_path'] ?? null;
                $p['available_serial_count'] = posGetAvailableSerialCount($db, (int)$p['id']);
                $p['requires_serial_scan'] = $p['available_serial_count'] > 0;
            }
            posJson(['success' => true, 'products' => $products, 'page' => $page, 'total_pages' => (int)ceil(((int)$count) / $per)]);
        }
        if ($action === 'scan_code') {
            $code = trim((string)($_GET['code'] ?? ''));
            $serialRow = posFindAvailableSerialRow($db, $code);
            if ($serialRow && (int)($serialRow['is_active'] ?? 0) === 1) {
                $_POST['product_id'] = (int)$serialRow['product_id'];
                $_POST['quantity'] = 1;
                $_POST['product_serial_number_id'] = (int)$serialRow['id'];
                $_POST['serial_number'] = (string)$serialRow['serial_number'];
                $action = 'add_to_cart';
            }
            if ($hasProductBarcode) {
                $row = $db->fetchOne("SELECT * FROM products WHERE is_active = 1 AND (sku = ? OR barcode = ?) LIMIT 1", [$code, $code]);
            } else {
                $row = $db->fetchOne("SELECT * FROM products WHERE is_active = 1 AND sku = ? LIMIT 1", [$code]);
            }
            if ($action !== 'add_to_cart') {
                if (!$row) posJson(['success' => false, 'message' => 'No product or serial matched the scan.'], 404);
                $_POST['product_id'] = $row['id']; $_POST['quantity'] = 1; $action = 'add_to_cart';
            }
        }
        if ($action === 'add_to_cart') {
            $pid = (int)($_POST['product_id'] ?? 0); $qty = max(1, (int)($_POST['quantity'] ?? 1));
            $serialId = (int)($_POST['product_serial_number_id'] ?? 0);
            $serialNumber = trim((string)($_POST['serial_number'] ?? ''));
            $p = $productsModule->getProduct($pid, true);
            if (!$p || (int)$p['is_active'] !== 1) posJson(['success' => false, 'message' => 'Product unavailable.'], 404);
            $availableSerialCount = posGetAvailableSerialCount($db, $pid);
            $requiresSerialScan = $availableSerialCount > 0;
            if ($requiresSerialScan && $serialId <= 0) {
                posJson(['success' => false, 'message' => 'This product requires serial scanning. Scan the unit serial number to add it to checkout.'], 422);
            }
            if (!isset($_SESSION['pos_cart'][$pid])) {
                $img = $db->fetchOne("SELECT image_path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC,id ASC LIMIT 1", [$pid]);
                $_SESSION['pos_cart'][$pid] = ['product_id' => $pid, 'name' => $p['name'], 'sku' => $p['sku'], 'image' => $img['image_path'] ?? null, 'unit_price' => getProductPriceValue($p), 'stock_quantity' => (int)$p['stock_quantity'], 'quantity' => 0, 'discount_type' => 'none', 'discount_value' => 0, 'has_serial_tracking' => $requiresSerialScan, 'scanned_serial_ids' => [], 'scanned_serials' => []];
            }
            $_SESSION['pos_cart'][$pid]['has_serial_tracking'] = $requiresSerialScan;
            if ($requiresSerialScan) {
                $serialRow = $db->fetchOne(
                    "SELECT id, product_id, serial_number
                     FROM product_serial_numbers
                     WHERE id = ?
                       AND product_id = ?
                       AND status IN ('in_stock', 'returned')
                     LIMIT 1",
                    [$serialId, $pid]
                );
                if (!$serialRow) {
                    posJson(['success' => false, 'message' => 'Serial number is no longer available.'], 422);
                }
                foreach ($_SESSION['pos_cart'] as $line) {
                    if (in_array((int)$serialRow['id'], array_map('intval', $line['scanned_serial_ids'] ?? []), true)) {
                        posJson(['success' => false, 'message' => 'That serial number is already in the checkout.'], 422);
                    }
                }
                $_SESSION['pos_cart'][$pid]['scanned_serial_ids'][] = (int)$serialRow['id'];
                $_SESSION['pos_cart'][$pid]['scanned_serials'][] = (string)($serialNumber !== '' ? $serialNumber : $serialRow['serial_number']);
                $_SESSION['pos_cart'][$pid]['quantity'] = count($_SESSION['pos_cart'][$pid]['scanned_serial_ids']);
            } else {
                $newQty = $_SESSION['pos_cart'][$pid]['quantity'] + $qty;
                if ($newQty > (int)$p['stock_quantity']) posJson(['success' => false, 'message' => 'Insufficient stock.'], 422);
                $_SESSION['pos_cart'][$pid]['quantity'] = $newQty;
            }
            posJson(['success' => true, 'cart' => posTotals($_SESSION['pos_cart'], $_SESSION['pos_settings'])]);
        }
        if ($action === 'update_cart') {
            $pid = (int)($_POST['product_id'] ?? 0); $qty = (int)($_POST['quantity'] ?? 1);
            if (!isset($_SESSION['pos_cart'][$pid])) posJson(['success' => false, 'message' => 'Item not found.'], 404);
            if (!empty($_SESSION['pos_cart'][$pid]['has_serial_tracking'])) {
                posJson(['success' => false, 'message' => 'Serialized items must be added by scanning each serial number.'], 422);
            }
            if ($qty <= 0) unset($_SESSION['pos_cart'][$pid]); else {
                if ($qty > (int)$_SESSION['pos_cart'][$pid]['stock_quantity']) posJson(['success' => false, 'message' => 'Quantity exceeds stock.'], 422);
                $_SESSION['pos_cart'][$pid]['quantity'] = $qty;
                $_SESSION['pos_cart'][$pid]['discount_type'] = $_POST['discount_type'] ?? $_SESSION['pos_cart'][$pid]['discount_type'];
                $_SESSION['pos_cart'][$pid]['discount_value'] = (float)($_POST['discount_value'] ?? $_SESSION['pos_cart'][$pid]['discount_value']);
            }
            posJson(['success' => true, 'cart' => posTotals($_SESSION['pos_cart'], $_SESSION['pos_settings'])]);
        }
        if ($action === 'remove_cart_item') { unset($_SESSION['pos_cart'][(int)($_POST['product_id'] ?? 0)]); posJson(['success' => true, 'cart' => posTotals($_SESSION['pos_cart'], $_SESSION['pos_settings'])]); }
        if ($action === 'clear_cart') { $_SESSION['pos_cart'] = []; posJson(['success' => true, 'cart' => posTotals($_SESSION['pos_cart'], $_SESSION['pos_settings'])]); }
        if ($action === 'get_cart') posJson([
            'success' => true,
            'cart' => posTotals($_SESSION['pos_cart'], $_SESSION['pos_settings']),
            'settings' => $_SESSION['pos_settings'],
            'mode' => $_SESSION['pos_mode'] ?? 'pos',
            'converted_quote_id' => $_SESSION['converted_quote_id'] ?? null
        ]);

        if ($action === 'apply_settings') {
            posJson(['success' => false, 'message' => 'Pricing rules are disabled in this POS configuration.'], 403);
        }
        if ($action === 'search_customers') { posJson(['success' => true, 'customers' => $customersModule->getCustomers(['search' => trim((string)($_GET['search'] ?? ''))], 20, 0)]); }
        if ($action === 'set_customer') {
            $cid = (int)($_POST['customer_id'] ?? 0);
            if ($cid <= 0) {
                $_SESSION['pos_settings']['customer_id'] = null;
                posJson(['success' => true, 'customer' => null]);
            }
            $c = $customersModule->getCustomer($cid);
            if (!$c) {
                posJson(['success' => false, 'message' => 'Customer not found.'], 404);
            }
            if (array_key_exists('is_active', $c) && (int)$c['is_active'] !== 1) {
                posJson(['success' => false, 'message' => 'Customer is inactive.'], 422);
            }
            $_SESSION['pos_settings']['customer_id'] = $cid;
            posJson(['success' => true, 'customer' => $c]);
        }
        if ($action === 'quick_add_customer') {
            $fn = trim((string)($_POST['first_name'] ?? '')); $ln = trim((string)($_POST['last_name'] ?? ''));
            if ($fn === '' || $ln === '') posJson(['success' => false, 'message' => 'First and last name are required.'], 422);
            $id = $customersModule->createCustomer(['first_name' => $fn, 'last_name' => $ln, 'phone' => trim((string)($_POST['phone'] ?? '')) ?: null, 'email' => trim((string)($_POST['email'] ?? '')) ?: null, 'is_active' => 1]);
            $_SESSION['pos_settings']['customer_id'] = $id; posJson(['success' => true, 'customer' => $customersModule->getCustomer($id)]);
        }
        if ($action === 'checkout') {
            if (empty($_SESSION['pos_cart'])) posJson(['success' => false, 'message' => 'Cart is empty.'], 422);
            $computed = posTotals($_SESSION['pos_cart'], $_SESSION['pos_settings']);
            $payments = $_POST['payments'] ?? []; if (is_string($payments)) $payments = json_decode($payments, true) ?: [];
            if (empty($payments)) {
                $payments = [[
                    'method' => 'cash',
                    'amount' => (float)$computed['totals']['total'],
                    'reference' => null
                ]];
            }
            foreach ($payments as &$paymentRow) {
                $paymentRow['method'] = 'cash';
                $paymentRow['amount'] = max(0, (float)($paymentRow['amount'] ?? 0));
                if (!isset($paymentRow['reference'])) {
                    $paymentRow['reference'] = null;
                }
            }
            unset($paymentRow);
            $paid = 0; foreach ($payments as $p) $paid += max(0, (float)($p['amount'] ?? 0));
            if ($paid < (float)$computed['totals']['total']) posJson(['success' => false, 'message' => 'Insufficient payment.'], 422);
            $items = [];
            foreach ($computed['items'] as $it) {
                $source = $_SESSION['pos_cart'][$it['product_id']] ?? [];
                $items[] = [
                    'product_id' => $it['product_id'],
                    'quantity' => $it['quantity'],
                    'unit_price' => $it['unit_price'],
                    'discount_amount' => $it['line_discount'],
                    'tax_amount' => $it['tax_amount'],
                    'subtotal' => $it['subtotal'],
                    'total' => $it['total'],
                    'serial_ids' => array_values(array_map('intval', $source['scanned_serial_ids'] ?? [])),
                    'serial_numbers' => array_values(array_filter(array_map('strval', $source['scanned_serials'] ?? [])))
                ];
            }
            $txId = $salesModule->createTransaction([
                'use_precomputed_totals' => true,
                'transaction_number' => 'INV-' . date('Ymd') . '-' . str_pad((string)(((int)($db->fetchOne("SELECT COUNT(*) as c FROM transactions WHERE DATE(transaction_date)=?", [date('Y-m-d')])['c'] ?? 0)) + 1), 5, '0', STR_PAD_LEFT),
                'customer_id' => $_SESSION['pos_settings']['customer_id'] ?: null,
                'subtotal' => $computed['totals']['subtotal'],
                'tax_amount' => $computed['totals']['tax'],
                'discount_amount' => $computed['totals']['discount'],
                'total_amount' => $computed['totals']['total'],
                'items' => $items,
                'payments' => $payments,
                'notes' => $_POST['notes'] ?? null
            ]);
            $quoteLinkWarning = null;
            $convertedQuoteId = (int)($_SESSION['converted_quote_id'] ?? 0);
            if ($convertedQuoteId > 0) {
                try {
                    linkTransactionToQuote($txId, $convertedQuoteId);
                    $_SESSION['converted_quote_id'] = null;
                } catch (Exception $e) {
                    $quoteLinkWarning = $e->getMessage();
                }
            }
            if (!empty($_POST['email_receipt']) && $_SESSION['pos_settings']['customer_id']) { $c = $customersModule->getCustomer($_SESSION['pos_settings']['customer_id']); if ($c && !empty($c['email'])) @mail($c['email'], 'Receipt', 'Transaction #' . $txId); }
            $_SESSION['pos_cart'] = [];
            posJson(['success' => true, 'transaction_id' => $txId, 'change' => round(max(0, $paid - (float)$computed['totals']['total']), 2), 'receipt_html' => posReceiptHtml($salesModule, $txId), 'quote_link_warning' => $quoteLinkWarning]);
        }
        if ($action === 'get_receipt') { posJson(['success' => true, 'receipt_html' => posReceiptHtml($salesModule, (int)($_GET['transaction_id'] ?? 0))]); }
        if ($action === 'search_transactions') {
            $search = trim((string)($_GET['search'] ?? '')); $params = []; $where = "1=1";
            if ($search !== '') {
                $where = "(
                    t.transaction_number LIKE ?
                    OR c.first_name LIKE ?
                    OR c.last_name LIKE ?
                    OR c.phone LIKE ?
                    OR EXISTS (
                        SELECT 1
                        FROM transaction_items ti
                        INNER JOIN products p ON p.id = ti.product_id
                        WHERE ti.transaction_id = t.id
                          AND (p.sku LIKE ? OR p.name LIKE ?)
                    )
                )";
                $like = '%' . $search . '%';
                $params = [$like, $like, $like, $like, $like, $like];
            }
            $rows = $db->fetchAll(
                "SELECT t.id,t.transaction_number,t.transaction_date,t.total_amount,c.first_name,c.last_name
                 FROM transactions t
                 LEFT JOIN customers c ON t.customer_id=c.id
                 WHERE {$where}
                 ORDER BY t.id DESC
                 LIMIT 30",
                $params
            );
            posJson(['success' => true, 'transactions' => $rows]);
        }
        if ($action === 'get_transaction_items') {
            $tx = (int)($_GET['transaction_id'] ?? 0);
            if ($tx <= 0) posJson(['success' => false, 'message' => 'Invalid transaction ID.'], 422);
            $items = posGetReturnableTransactionItems($db, $tx);
            posJson(['success' => true, 'items' => $items]);
        }
        if ($action === 'process_return') {
            $tx = (int)($_POST['transaction_id'] ?? 0); $items = $_POST['items'] ?? []; if (is_string($items)) $items = json_decode($items, true) ?: [];
            if ($tx <= 0 || empty($items)) posJson(['success' => false, 'message' => 'Invalid return request.'], 422);
            $ids = []; $refund = 0;
            foreach ($items as $it) {
                $qty = max(0, (int)($it['quantity'] ?? 0)); if ($qty <= 0) continue;

                $productId = (int)($it['product_id'] ?? 0);
                $transactionItemId = (int)($it['transaction_item_id'] ?? 0);

                if ($transactionItemId <= 0 && $productId > 0) {
                    $ti = $db->fetchOne(
                        "SELECT id, product_id
                         FROM transaction_items
                         WHERE transaction_id = ? AND product_id = ?
                         ORDER BY id ASC
                         LIMIT 1",
                        [$tx, $productId]
                    );
                    $transactionItemId = (int)($ti['id'] ?? 0);
                } elseif ($transactionItemId > 0 && $productId <= 0) {
                    $ti = $db->fetchOne(
                        "SELECT id, product_id
                         FROM transaction_items
                         WHERE id = ? AND transaction_id = ?
                         LIMIT 1",
                        [$transactionItemId, $tx]
                    );
                    $productId = (int)($ti['product_id'] ?? 0);
                }

                if ($productId <= 0 || $transactionItemId <= 0) {
                    posJson(['success' => false, 'message' => 'Invalid return item payload. Missing product/line reference.'], 422);
                }

                $serialPool = posGetTransactionItemSerialPool($db, $tx, $transactionItemId, $productId);
                $rawScanned = $it['scanned_serials'] ?? [];
                if (is_string($rawScanned)) {
                    $rawScanned = preg_split('/[\s,]+/', trim($rawScanned)) ?: [];
                }
                $scannedSerials = [];
                foreach ((array)$rawScanned as $serial) {
                    $value = trim((string)$serial);
                    if ($value !== '') {
                        $scannedSerials[] = $value;
                    }
                }

                if (!empty($serialPool)) {
                    if (count($scannedSerials) !== $qty) {
                        posJson(['success' => false, 'message' => 'Serial scan count must match return quantity.'], 422);
                    }

                    $normalizedPool = [];
                    foreach ($serialPool as $serial) {
                        $normalizedPool[strtolower($serial)] = $serial;
                    }
                    $seen = [];
                    foreach ($scannedSerials as $serial) {
                        $key = strtolower($serial);
                        if (!isset($normalizedPool[$key])) {
                            posJson(['success' => false, 'message' => "Scanned serial '{$serial}' does not match sold serials for this item."], 422);
                        }
                        if (isset($seen[$key])) {
                            posJson(['success' => false, 'message' => "Duplicate scanned serial '{$serial}' is not allowed."], 422);
                        }
                        $seen[$key] = true;
                    }
                }

                $serialNote = !empty($scannedSerials) ? ('serials=' . implode('|', $scannedSerials)) : '';
                $rid = $salesModule->processReturn(
                    $tx,
                    $productId,
                    $qty,
                    trim((string)($it['reason'] ?? 'change_mind')),
                    [
                        'transaction_item_id' => $transactionItemId,
                        'product_serial_number_id' => (int)($it['product_serial_number_id'] ?? 0) ?: null,
                        'serial_return_status' => in_array(strtolower(trim((string)($it['reason'] ?? ''))), ['defective', 'damaged'], true) ? 'damaged' : 'returned',
                        'refund_method' => strtolower(trim((string)($it['refund_method'] ?? 'cash'))),
                        'refund_mode' => strtolower(trim((string)($it['refund_mode'] ?? 'original'))),
                        'restocking_fee_percent' => (float)($it['restocking_fee_percent'] ?? 0),
                        'restocking_fee_amount' => (float)($it['restocking_fee_amount'] ?? 0),
                        'notes' => $serialNote
                    ]
                );
                $row = $db->fetchOne("SELECT total_refund_amount FROM returns WHERE id = ?", [$rid]); $refund += (float)($row['total_refund_amount'] ?? 0); $ids[] = $rid;
            }
            posJson(['success' => true, 'return_ids' => $ids, 'refund_total' => round($refund, 2)]);
        }
        if ($action === 'process_exchange') {
            $tx = (int)($_POST['transaction_id'] ?? 0);
            $returnItems = $_POST['return_items'] ?? [];
            $newItems = $_POST['new_items'] ?? [];
            $payments = $_POST['payments'] ?? [];
            if (is_string($returnItems)) $returnItems = json_decode($returnItems, true) ?: [];
            if (is_string($newItems)) $newItems = json_decode($newItems, true) ?: [];
            if (is_string($payments)) $payments = json_decode($payments, true) ?: [];
            if ($tx <= 0 || empty($returnItems) || empty($newItems)) posJson(['success' => false, 'message' => 'Invalid exchange request.'], 422);

            $credit = 0;
            foreach ($returnItems as $it) {
                $qty = max(0, (int)($it['quantity'] ?? 0));
                if ($qty <= 0) continue;
                $rid = $salesModule->processReturn($tx, (int)$it['product_id'], $qty, trim((string)($it['reason'] ?? 'exchange')), [
                    'transaction_item_id' => (int)($it['transaction_item_id'] ?? 0),
                    'product_serial_number_id' => (int)($it['product_serial_number_id'] ?? 0) ?: null,
                    'serial_return_status' => 'returned',
                    'refund_method' => 'store_credit',
                    'refund_mode' => 'original',
                    'restocking_fee_percent' => 0,
                    'restocking_fee_amount' => 0,
                    'notes' => 'exchange_return'
                ]);
                $row = $db->fetchOne("SELECT total_refund_amount FROM returns WHERE id = ?", [$rid]);
                $credit += (float)($row['total_refund_amount'] ?? 0);
            }

            $exchangeCart = [];
            foreach ($newItems as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $qty = max(1, (int)($it['quantity'] ?? 1));
                $p = $productsModule->getProduct($pid, true);
                if (!$p || (int)$p['is_active'] !== 1) posJson(['success' => false, 'message' => 'Exchange product unavailable.'], 422);
                if ($qty > (int)$p['stock_quantity']) posJson(['success' => false, 'message' => 'Exchange quantity exceeds stock.'], 422);
                $exchangeCart[] = ['product_id' => $pid, 'name' => $p['name'], 'sku' => $p['sku'], 'image' => null, 'unit_price' => getProductPriceValue($p), 'stock_quantity' => (int)$p['stock_quantity'], 'quantity' => $qty, 'discount_type' => 'none', 'discount_value' => 0];
            }
            $computed = posTotals($exchangeCart, ['tax_rate' => 12, 'tax_inclusive' => false, 'discount_type' => 'none', 'discount_value' => 0]);
            $due = max(0, (float)$computed['totals']['total'] - $credit);

            $paid = 0;
            foreach ((array)$payments as $p) $paid += max(0, (float)($p['amount'] ?? 0));
            if ($due > 0 && $paid < $due) posJson(['success' => false, 'message' => 'Additional payment required for exchange.'], 422);

            $txItems = [];
            foreach ($computed['items'] as $it) {
                $txItems[] = ['product_id' => $it['product_id'], 'quantity' => $it['quantity'], 'unit_price' => $it['unit_price'], 'discount_amount' => $it['line_discount'], 'tax_amount' => $it['tax_amount'], 'subtotal' => $it['subtotal'], 'total' => $it['total']];
            }
            $orig = $salesModule->getTransaction($tx);
            $newTxId = $salesModule->createTransaction([
                'use_precomputed_totals' => true,
                'transaction_number' => 'INV-' . date('Ymd') . '-' . str_pad((string)(((int)($db->fetchOne("SELECT COUNT(*) as c FROM transactions WHERE DATE(transaction_date)=?", [date('Y-m-d')])['c'] ?? 0)) + 1), 5, '0', STR_PAD_LEFT),
                'customer_id' => $orig['customer_id'] ?? null,
                'subtotal' => $computed['totals']['subtotal'],
                'tax_amount' => $computed['totals']['tax'],
                'discount_amount' => $computed['totals']['discount'],
                'total_amount' => $computed['totals']['total'],
                'items' => $txItems,
                'notes' => "Exchange from transaction #{$tx}"
            ]);

            if ($due > 0) {
                foreach ((array)$payments as $p) {
                    $amt = max(0, (float)($p['amount'] ?? 0));
                    if ($amt > 0) $salesModule->addPayment($newTxId, $p);
                }
            } else {
                $salesModule->addPayment($newTxId, ['method' => 'other', 'amount' => (float)$computed['totals']['total'], 'reference' => 'STORE-CREDIT', 'notes' => 'Paid via exchange credit']);
            }

            posJson([
                'success' => true,
                'exchange_transaction_id' => $newTxId,
                'credit_used' => round(min($credit, (float)$computed['totals']['total']), 2),
                'additional_due' => round($due, 2),
                'store_credit_balance' => round(max(0, $credit - (float)$computed['totals']['total']), 2)
            ]);
        }

        posJson(['success' => false, 'message' => 'Invalid action.'], 404);
    } catch (Exception $e) {
        posJson(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

$categories = $categoriesModule->getCategories();
include 'templates/header.php';
?>
<style>
    .pos-modern { --pos-bg:#ffffff; --pos-muted:#f8fafc; --pos-border:#e5e7eb; --pos-text:#0f172a; --pos-sub:#64748b; --pos-accent:#3b82f6; --pos-dark:#1e293b; }
    .pos-modern { color: var(--pos-text); }
    .pos-modern .pos-shell { display:grid; grid-template-columns: minmax(0,65fr) minmax(340px,35fr); gap:16px; }
    .pos-modern .pos-products, .pos-modern .pos-checkout { border:1px solid var(--pos-border); border-radius:12px; background:#fff; }
    .pos-modern .pos-products { min-height: calc(100vh - 170px); }
    .pos-modern .pos-products .card-header { border-bottom:1px solid var(--pos-border); background:#fff; }
    .pos-modern .scan-btn { border-left:0; }
    .pos-modern #product-grid .card { border:1px solid var(--pos-border); border-radius:12px; overflow:hidden; }
    .pos-modern #product-grid .card-img-wrap { height:140px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; }
    .pos-modern #product-grid .card-img-wrap img { width:100%; height:100%; object-fit:cover; }
    .pos-modern .stock-badge { font-size:.7rem; border-radius:999px; padding:.25rem .5rem; }
    .pos-modern .stock-badge.in { background:#dcfce7; color:#166534; }
    .pos-modern .stock-badge.out { background:#fee2e2; color:#991b1b; }
    .pos-modern .pos-checkout { background:var(--pos-muted); position:sticky; top:78px; height:calc(100vh - 94px); display:flex; flex-direction:column; }
    .pos-modern .pos-checkout .checkout-body { padding:12px; overflow:auto; }
    .pos-modern #cart-items .pos-cart-item { background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 2px 8px rgba(15,23,42,.05); cursor:pointer; transition:all .15s ease; }
    .pos-modern #cart-items .pos-cart-item:hover { border-color:#cbd5e1; box-shadow:0 6px 16px rgba(15,23,42,.08); transform:translateY(-1px); }
    .pos-modern .checkout-total { margin-top:auto; background:var(--pos-dark); color:#fff; border-radius:0 0 12px 12px; padding:14px; }
    .pos-modern .checkout-total .amount { font-size:2rem; font-weight:800; letter-spacing:.02em; }
    .pos-modern .pay-actions .btn { min-height:44px; font-weight:700; border-radius:10px; }
    .pos-modern .pay-actions .btn-pay-cash { background:#22c55e; border-color:#22c55e; color:#fff; }
    @media (max-width: 1200px) { .pos-modern .pos-shell { grid-template-columns: 1fr; } .pos-modern .pos-checkout { position:static; height:auto; } }
</style>
<input type="hidden" id="pos-csrf" value="<?php echo escape(generateCSRFToken()); ?>">
<div class="pos-modern" id="pos-app">
<?php if (!empty($quoteLoadError) || !empty($quoteLoadNotice)): ?>
    <div class="alert alert-<?php echo !empty($quoteLoadError) ? 'danger' : 'info'; ?> mb-2 pos-quote-alert" role="alert">
        <?php echo escape(!empty($quoteLoadError) ? $quoteLoadError : $quoteLoadNotice); ?>
    </div>
<?php endif; ?>
    <div class="col-12">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary" href="<?php echo getBaseUrl(); ?>/returns.php">Returns/Exchange</a>
            </div>
        </div>
    </div>
    <div class="pos-shell">
    <section class="pos-products card">
            <div class="card-header">
                <div class="row g-2 align-items-center">
                    <div class="col-md-8">
                        <div class="input-group">
                            <input id="product-search" class="form-control form-control-lg" placeholder="Search products, SKU, barcode, or scan serial number">
                            <button id="scan-barcode" class="btn btn-outline-secondary scan-btn" type="button" title="Scan Barcode">
                                <i class="bi bi-upc-scan"></i>
                            </button>
                        </div>
                        <div class="small text-muted mt-1">Serialized products must be added by scanning their exact serial number in the search box.</div>
                    </div>
                    <div class="col-md-3"><select id="category-filter" class="form-select"><option value="">All Categories</option><?php foreach ($categories as $cat): ?><option value="<?php echo (int)$cat['id']; ?>"><?php echo escape($cat['name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-1 d-grid"><button id="refresh-products" class="btn btn-outline-primary"><i class="bi bi-arrow-clockwise"></i></button></div>
                </div>
            </div>
            <div class="card-body"><div id="product-grid" class="row g-3"></div></div>
            <div class="card-footer d-flex justify-content-between"><button id="prev-page" class="btn btn-outline-secondary btn-sm">Previous</button><span id="page-indicator" class="small text-muted"></span><button id="next-page" class="btn btn-outline-secondary btn-sm">Next</button></div>
    </section>
    <aside class="pos-checkout card" id="cart-panel">
            <div class="card-header d-flex justify-content-between"><h6 class="mb-0">Checkout</h6><span class="text-muted small">Cash Only</span></div>
            <div class="checkout-body">
                <input id="customer-search" class="form-control form-control-sm" placeholder="Search customer">
                <div id="customer-results" class="list-group mt-1 d-none"></div>
                <div id="selected-customer" class="small mt-2" data-customer-id="<?php echo $selectedCustomerId ? (int)$selectedCustomerId : ''; ?>">
                    Customer: <?php echo escape($selectedCustomerName); ?>
                </div>
                <button id="quick-customer-btn" class="btn btn-link btn-sm p-0">Quick register customer</button>
                <div id="cart-items" class="mt-2" style="max-height:280px;overflow-y:auto;"></div>
                <hr>
                <div class="small"><div class="d-flex justify-content-between"><span>Subtotal</span><span id="subtotal">0.00</span></div><div class="d-flex justify-content-between"><span>Tax</span><span id="tax">0.00</span></div><div class="d-flex justify-content-between"><span>Discount</span><span id="discount">0.00</span></div></div>
            </div>
            <div class="checkout-total">
                <div class="d-flex justify-content-between align-items-end mb-2">
                    <span class="small text-uppercase text-white-50">Total Amount</span>
                    <span class="amount" id="total">0.00</span>
                </div>
                <div class="pay-actions mb-2">
                    <button type="button" class="btn btn-pay-cash w-100" disabled>CASH PAYMENT</button>
                </div>
                <div class="mb-2">
                    <label for="payment-amount" class="form-label small text-white-50">Cash Received</label>
                    <input type="number" min="0" step="0.01" id="payment-amount" class="form-control form-control-sm" placeholder="Enter amount (leave blank for exact)">
                </div>
                <div id="pos-actions" class="d-grid">
                    <button id="checkout-btn" class="btn btn-light fw-bold">Finalize Transaction</button>
                </div>
            </div>
    </aside>
    </div>
</div>
<div class="modal fade" id="receiptModal" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Receipt</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="receipt-body"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary" id="print-receipt">Print</button></div></div></div></div>
<script>window.POS_CONFIG={endpoint:"<?php echo escape(getBaseUrl()); ?>/pos.php?ajax=1",base_url:"<?php echo escape(getBaseUrl()); ?>",csrf:"<?php echo escape(generateCSRFToken()); ?>",mode:"<?php echo escape($_SESSION['pos_mode']); ?>"};</script>
<?php include 'templates/footer.php'; ?>
<script src="<?php echo getBaseUrl(); ?>/assets/js/pos.js"></script>





