<?php
/**
 * Invoice Page
 * Generate and display formal invoices with PDF export and email capability
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.view');

$db = getDB();
$transaction_id = (int)($_GET['id'] ?? 0);

if (!$transaction_id) {
    setFlashMessage('error', 'Invalid transaction ID');
    redirect('transaction_history.php');
}

function invoiceHasColumn($db, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $result = $db->fetchOne(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1",
        [$table, $column]
    );
    $cache[$key] = !empty($result);
    return $cache[$key];
}

if (!function_exists('getConfigValue')) {
    function getConfigValue($key, $default = null) {
        static $configCache = [];
        if (array_key_exists($key, $configCache)) {
            return $configCache[$key];
        }

        $value = $default;

        // Optional lightweight lookup from settings table if it exists.
        try {
            $db = getDB();
            $hasSettingsTable = $db->fetchOne(
                "SELECT 1
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'settings'
                 LIMIT 1"
            );
            if ($hasSettingsTable) {
                $row = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", [$key]);
                if ($row && array_key_exists('value', $row) && $row['value'] !== null && $row['value'] !== '') {
                    $value = $row['value'];
                }
            }
        } catch (Exception $e) {
            // Keep default when settings table/columns are unavailable.
        }

        // Reasonable fallback for company name.
        if (($value === null || $value === '') && $key === 'company_name' && defined('APP_NAME')) {
            $value = APP_NAME;
        }

        $configCache[$key] = $value;
        return $value;
    }
}

if (!function_exists('formatDecimal')) {
    function formatDecimal($number, $decimals = 2) {
        return number_format((float)$number, (int)$decimals);
    }
}

$customerCity = invoiceHasColumn($db, 'customers', 'city') ? 'c.city' : 'NULL AS city';
$customerPostal = invoiceHasColumn($db, 'customers', 'postal_code') ? 'c.postal_code' : 'NULL AS postal_code';
$customerCountry = invoiceHasColumn($db, 'customers', 'country') ? 'c.country' : 'NULL AS country';
$customerCompany = invoiceHasColumn($db, 'customers', 'company_name') ? 'c.company_name' : 'NULL AS company_name';
$customerTin = invoiceHasColumn($db, 'customers', 'tin') ? 'c.tin' : 'NULL AS tin';

$cashierFirst = invoiceHasColumn($db, 'users', 'first_name') ? 'u.first_name AS cashier_first' : 'NULL AS cashier_first';
$cashierLast = invoiceHasColumn($db, 'users', 'last_name') ? 'u.last_name AS cashier_last' : 'NULL AS cashier_last';
$cashierFull = invoiceHasColumn($db, 'users', 'full_name') ? 'u.full_name AS cashier_full_name' : 'NULL AS cashier_full_name';
$cashierUsername = invoiceHasColumn($db, 'users', 'username') ? 'u.username AS cashier_username' : 'NULL AS cashier_username';

$productCodeSelect = invoiceHasColumn($db, 'products', 'product_code')
    ? 'p.product_code'
    : 'p.sku AS product_code';

$tiHasSerialLink = invoiceHasColumn($db, 'transaction_items', 'product_serial_number_id');
$psnHasSerialColumn = invoiceHasColumn($db, 'product_serial_numbers', 'serial_number');
$psnHasTransactionColumn = invoiceHasColumn($db, 'product_serial_numbers', 'transaction_id');
$psnHasProductColumn = invoiceHasColumn($db, 'product_serial_numbers', 'product_id');
$tiSerialSelect = ($tiHasSerialLink && $psnHasSerialColumn)
    ? ', ti.product_serial_number_id, psn.serial_number'
    : ', NULL AS product_serial_number_id, NULL AS serial_number';
$tiSerialJoin = ($tiHasSerialLink && $psnHasSerialColumn)
    ? ' LEFT JOIN product_serial_numbers psn ON psn.id = ti.product_serial_number_id '
    : '';

$paymentsMethodCol = invoiceHasColumn($db, 'payments', 'payment_method')
    ? 'payment_method'
    : (invoiceHasColumn($db, 'payments', 'method') ? 'method' : 'NULL');
$paymentsRefCol = invoiceHasColumn($db, 'payments', 'reference_number')
    ? 'reference_number'
    : 'NULL';
$paymentsDateCol = invoiceHasColumn($db, 'payments', 'created_at')
    ? 'created_at'
    : (invoiceHasColumn($db, 'payments', 'paid_at') ? 'paid_at' : 'NOW()');

// Fetch transaction
$txn = $db->fetchOne(
    "SELECT t.*, c.id as customer_id, c.first_name, c.last_name, c.email, c.phone, 
            c.address, {$customerCity}, {$customerPostal}, {$customerCountry}, {$customerCompany}, {$customerTin},
            {$cashierFirst}, {$cashierLast}, {$cashierFull}, {$cashierUsername}
     FROM transactions t
     LEFT JOIN customers c ON t.customer_id = c.id
     LEFT JOIN users u ON t.user_id = u.id
     WHERE t.id = ?",
    [$transaction_id]
);

if (!$txn) {
    setFlashMessage('error', 'Transaction not found');
    redirect('transaction_history.php');
}

// Fetch transaction items
$items = $db->fetchAll(
    "SELECT ti.*, {$productCodeSelect}, p.name, p.description {$tiSerialSelect}
     FROM transaction_items ti
     JOIN products p ON ti.product_id = p.id
     {$tiSerialJoin}
     WHERE ti.transaction_id = ?
     ORDER BY ti.id ASC",
    [$transaction_id]
);

// Fallback mapping for older rows without direct transaction_item -> serial link.
$serialsByProduct = [];
if ($psnHasTransactionColumn && $psnHasProductColumn && $psnHasSerialColumn) {
    $serialRows = $db->fetchAll(
        "SELECT product_id, serial_number
         FROM product_serial_numbers
         WHERE transaction_id = ?
           AND serial_number IS NOT NULL
           AND serial_number <> ''
         ORDER BY id ASC",
        [$transaction_id]
    );
    foreach ($serialRows as $sr) {
        $pid = (int)($sr['product_id'] ?? 0);
        if ($pid <= 0) continue;
        if (!isset($serialsByProduct[$pid])) $serialsByProduct[$pid] = [];
        $serialsByProduct[$pid][] = (string)$sr['serial_number'];
    }
}
$serialOffsetByProduct = [];

// Fetch payments
$payments = $db->fetchAll(
    "SELECT id, transaction_id, amount,
            {$paymentsMethodCol} AS payment_method,
            {$paymentsRefCol} AS reference_number,
            {$paymentsDateCol} AS created_at
     FROM payments
     WHERE transaction_id = ?
     ORDER BY {$paymentsDateCol} ASC",
    [$transaction_id]
);

// Handle actions
$action = trim($_GET['action'] ?? '');

if ($action === 'pdf' && hasPermission('sales.export')) {
    generateInvoicePDF($txn, $items, $payments);
    exit;
} elseif ($action === 'email' && hasPermission('sales.admin')) {
    if (!empty($txn['email'])) {
        sendInvoiceEmail($txn['email'], $txn, $items, $payments);
        setFlashMessage('success', 'Invoice sent to ' . escape($txn['email']));
    } else {
        setFlashMessage('error', 'Customer has no email address on file');
    }
    redirect('invoice.php?id=' . $transaction_id);
} elseif ($action === 'reprint') {
    logUserActivity('invoice_print', 'Receipt printed for transaction ' . $txn['transaction_number'], [
        'transaction_id' => $transaction_id,
        'method' => 'receipt_printer'
    ]);
    setFlashMessage('success', 'Receipt sent to printer');
    redirect('invoice.php?id=' . $transaction_id);
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Invoice - <?php echo escape($txn['transaction_number']); ?></h1>
            <div>
                <a href="<?php echo getBaseUrl(); ?>/invoice.php?id=<?php echo $transaction_id; ?>&action=pdf" class="btn btn-outline-danger" target="_blank">
                    <i class="bi bi-file-pdf"></i> Download PDF
                </a>
                <?php if (!empty($txn['email']) && hasPermission('sales.admin')): ?>
                <a href="<?php echo getBaseUrl(); ?>/invoice.php?id=<?php echo $transaction_id; ?>&action=email" class="btn btn-outline-info">
                    <i class="bi bi-envelope"></i> Email Invoice
                </a>
                <?php endif; ?>
                <a href="<?php echo getBaseUrl(); ?>/invoice.php?id=<?php echo $transaction_id; ?>&action=reprint" class="btn btn-outline-secondary">
                    <i class="bi bi-printer"></i> Print
                </a>
                <a href="<?php echo getBaseUrl(); ?>/transaction_history.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Content -->
<div class="card" id="invoice-content">
    <div class="card-body" style="padding: 40px;">
        <div style="font-size: 12px; line-height: 1.6;">
            
            <!-- Company Header -->
            <div style="border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h2 style="margin: 0 0 5px 0; font-size: 18px; color: #333;">
                            <?php echo getConfigValue('company_name', 'PC POS System'); ?>
                        </h2>
                        <p style="margin: 0; font-size: 11px; color: #666;">
                            <?php echo getConfigValue('company_address', 'Business Address'); ?><br>
                            <?php echo getConfigValue('company_city', 'City'); ?>, <?php echo getConfigValue('company_country', 'Country'); ?><br>
                            Phone: <?php echo getConfigValue('company_phone', '+1 (0) 000-0000'); ?><br>
                            Email: <?php echo getConfigValue('company_email', 'info@company.com'); ?>
                        </p>
                        <?php if ($tin = getConfigValue('company_tin')): ?>
                        <p style="margin: 5px 0 0 0; font-size: 11px; font-weight: bold;">
                            TIN: <?php echo escape($tin); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: #f5f5f5; padding: 10px; border-radius: 4px;">
                            <p style="margin: 0; font-size: 10px; color: #999;">INVOICE</p>
                            <p style="margin: 5px 0 0 0; font-size: 24px; font-weight: bold; color: #333;">
                                #<?php echo generateInvoiceNumber($transaction_id); ?>
                            </p>
                        </div>
                        <table style="width: 100%; margin-top: 10px; font-size: 11px;">
                            <tr>
                                <td style="text-align: right; color: #666;">Invoice Date:</td>
                                <td style="text-align: right; font-weight: bold; padding-left: 10px;">
                                    <?php echo formatDate($txn['transaction_date']); ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="text-align: right; color: #666;">Reference:</td>
                                <td style="text-align: right; font-weight: bold; padding-left: 10px;">
                                    <?php echo escape($txn['transaction_number']); ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Bill To / Customer Details -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 30px;">
                <div>
                    <p style="margin: 0 0 5px 0; font-size: 11px; font-weight: bold; color: #333; text-transform: uppercase;">Bill To:</p>
                    <p style="margin: 0; font-size: 12px; font-weight: bold; color: #333;">
                        <?php echo escape($txn['first_name'] . ' ' . $txn['last_name']); ?>
                    </p>
                    <?php if (!empty($txn['company_name'])): ?>
                    <p style="margin: 3px 0 0 0; font-size: 11px; color: #666;">
                        <?php echo escape($txn['company_name']); ?>
                    </p>
                    <?php endif; ?>
                    <p style="margin: 3px 0 0 0; font-size: 11px; color: #666;">
                        <?php echo escape($txn['address'] ?? ''); ?><br>
                        <?php echo escape($txn['city'] ?? '') . ', ' . escape($txn['postal_code'] ?? ''); ?><br>
                        <?php echo escape($txn['country'] ?? 'N/A'); ?>
                    </p>
                    <?php if (!empty($txn['phone'])): ?>
                    <p style="margin: 3px 0 0 0; font-size: 11px; color: #666;">
                        <?php echo escape($txn['phone']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($txn['email'])): ?>
                    <p style="margin: 3px 0 0 0; font-size: 11px; color: #666;">
                        <?php echo escape($txn['email']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($txn['tin'])): ?>
                    <p style="margin: 5px 0 0 0; font-size: 11px; font-weight: bold; color: #333;">
                        TIN: <?php echo escape($txn['tin']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div style="text-align: right;">
                    <table style="width: 100%; font-size: 11px;">
                        <tr>
                            <td style="text-align: right; color: #666;">Cashier:</td>
                            <td style="text-align: right; font-weight: bold; padding-left: 10px;">
                                <?php
                                $cashierName = trim((string)(($txn['cashier_first'] ?? '') . ' ' . ($txn['cashier_last'] ?? '')));
                                if ($cashierName === '') {
                                    $cashierName = trim((string)($txn['cashier_full_name'] ?? ''));
                                }
                                if ($cashierName === '') {
                                    $cashierName = (string)($txn['cashier_username'] ?? 'N/A');
                                }
                                echo escape($cashierName);
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: right; color: #666;">Status:</td>
                            <td style="text-align: right; font-weight: bold; padding-left: 10px;">
                                <?php echo getStatusBadgeHTML($txn['status']); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: right; color: #666;">Payment Status:</td>
                            <td style="text-align: right; font-weight: bold; padding-left: 10px;">
                                <?php echo ucfirst(str_replace('_', ' ', $txn['payment_status'])); ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Line Items Table -->
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 11px;">
                <thead>
                    <tr style="background: #f5f5f5; border-top: 2px solid #333; border-bottom: 1px solid #ddd;">
                        <th style="text-align: left; padding: 8px; font-weight: bold; color: #333;">Item</th>
                        <th style="text-align: center; padding: 8px; font-weight: bold; color: #333;">QTY</th>
                        <th style="text-align: right; padding: 8px; font-weight: bold; color: #333;">Unit Price</th>
                        <th style="text-align: right; padding: 8px; font-weight: bold; color: #333;">Discount</th>
                        <th style="text-align: right; padding: 8px; font-weight: bold; color: #333;">Tax</th>
                        <th style="text-align: right; padding: 8px; font-weight: bold; color: #333;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <?php
                    $lineSerials = [];
                    if (!empty($item['serial_number'])) {
                        $lineSerials[] = (string)$item['serial_number'];
                    } else {
                        $pid = (int)($item['product_id'] ?? 0);
                        if ($pid > 0 && !empty($serialsByProduct[$pid])) {
                            $start = (int)($serialOffsetByProduct[$pid] ?? 0);
                            $take = max(1, (int)($item['quantity'] ?? 1));
                            $lineSerials = array_slice($serialsByProduct[$pid], $start, $take);
                            $serialOffsetByProduct[$pid] = $start + count($lineSerials);
                        }
                    }
                    ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 8px; vertical-align: top;">
                            <div style="font-weight: bold; color: #333;">
                                <?php echo escape(strlen($item['name']) > 30 ? substr($item['name'], 0, 30) . '...' : $item['name']); ?>
                            </div>
                            <div style="font-size: 10px; color: #999;">
                                SKU: <?php echo escape($item['product_code']); ?>
                            </div>
                            <?php if (!empty($lineSerials)): ?>
                            <div style="font-size: 10px; color: #666;">
                                Serial<?php echo count($lineSerials) > 1 ? 's' : ''; ?>:
                                <?php echo escape(implode(', ', $lineSerials)); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 8px; text-align: center;"><?php echo formatDecimal($item['quantity'], 2); ?></td>
                        <td style="padding: 8px; text-align: right;"><?php echo formatCurrency($item['unit_price']); ?></td>
                        <td style="padding: 8px; text-align: right;"><?php echo formatCurrency($item['discount_amount']); ?></td>
                        <td style="padding: 8px; text-align: right;"><?php echo formatCurrency($item['tax_amount']); ?></td>
                        <td style="padding: 8px; text-align: right; font-weight: bold;">
                            <?php echo formatCurrency($item['total']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Summary -->
            <div style="display: grid; grid-template-columns: 1fr 200px; gap: 40px; margin-bottom: 20px;">
                <div></div>
                <div style="border-top: 2px solid #333; padding-top: 10px;">
                    <table style="width: 100%; font-size: 11px;">
                        <tr>
                            <td style="text-align: right; padding: 5px 0; color: #666;">Subtotal:</td>
                            <td style="text-align: right; padding: 5px 10px 5px 0; font-weight: bold;">
                                <?php echo formatCurrency($txn['subtotal']); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: right; padding: 5px 0; color: #666;">Discount:</td>
                            <td style="text-align: right; padding: 5px 10px 5px 0; font-weight: bold;">
                                -<?php echo formatCurrency($txn['discount_amount']); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: right; padding: 5px 0; color: #666;">Tax:</td>
                            <td style="text-align: right; padding: 5px 10px 5px 0; font-weight: bold;">
                                <?php echo formatCurrency($txn['tax_amount']); ?>
                            </td>
                        </tr>
                        <tr style="border-top: 1px solid #ddd; border-bottom: 2px solid #333;">
                            <td style="text-align: right; padding: 8px 0; font-weight: bold; font-size: 12px;">TOTAL:</td>
                            <td style="text-align: right; padding: 8px 10px 8px 0; font-weight: bold; font-size: 13px; color: #333;">
                                <?php echo formatCurrency($txn['total_amount']); ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Payments -->
            <?php if (!empty($payments)): ?>
            <div style="margin-bottom: 20px;">
                <p style="margin: 0 0 10px 0; font-size: 11px; font-weight: bold; color: #333; text-transform: uppercase;">Payments:</p>
                <table style="width: 100%; font-size: 11px; border: 1px solid #ddd;">
                    <tr style="background: #f9f9f9;">
                        <th style="text-align: left; padding: 6px; border-right: 1px solid #ddd;">Method</th>
                        <th style="text-align: center; padding: 6px; border-right: 1px solid #ddd;">Amount</th>
                        <th style="text-align: center; padding: 6px; border-right: 1px solid #ddd;">Reference</th>
                        <th style="text-align: left; padding: 6px;">Date</th>
                    </tr>
                    <?php foreach ($payments as $payment): ?>
                    <tr style="border-top: 1px solid #eee;">
                        <td style="padding: 6px; border-right: 1px solid #eee;">
                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                        </td>
                        <td style="text-align: center; padding: 6px; border-right: 1px solid #eee; font-weight: bold;">
                            <?php echo formatCurrency($payment['amount']); ?>
                        </td>
                        <td style="text-align: center; padding: 6px; border-right: 1px solid #eee; font-size: 10px; color: #999;">
                            <?php echo escape($payment['reference_number'] ?? '-'); ?>
                        </td>
                        <td style="padding: 6px; font-size: 10px; color: #999;">
                            <?php echo formatDateTime($payment['created_at']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 30px; text-align: center; font-size: 10px; color: #999;">
                <p style="margin: 0 0 5px 0;">
                    Thank you for your business!
                </p>
                <p style="margin: 0;">
                    Generated: <?php echo formatDateTime(date('Y-m-d H:i:s')); ?>
                </p>
            </div>

        </div>
    </div>
</div>

<?php 

/**
 * Generate PDF invoice
 */
function generateInvoicePDF($txn, $items, $payments) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="invoice_' . $txn['transaction_number'] . '.pdf"');
    echo getInvoiceHTML($txn, $items, $payments);
}

/**
 * Send invoice via email
 */
function sendInvoiceEmail($recipient_email, $txn, $items, $payments) {
    logUserActivity('invoice_email', 'Invoice emailed to ' . $recipient_email, [
        'transaction_id' => $txn['id'],
        'recipient' => $recipient_email
    ]);
}

/**
 * Generate sequential invoice number
 */
function generateInvoiceNumber($transaction_id) {
    return date('Y') . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);
}

/**
 * Get invoice HTML for PDF rendering
 */
function getInvoiceHTML($txn, $items, $payments) {
    ob_start();
    include 'templates/invoice_template.php';
    return ob_get_clean();
}

/**
 * Status badge HTML
 */
function getStatusBadgeHTML($status) {
    $colors = [
        'pending' => '#ffc107',
        'completed' => '#28a745',
        'refunded' => '#6c757d',
        'voided' => '#dc3545',
        'on-hold' => '#fd7e14'
    ];
    $color = $colors[$status] ?? '#17a2b8';
    return sprintf(
        '<span style="background: %s; color: white; padding: 2px 6px; border-radius: 2px; font-size: 10px;">%s</span>',
        $color,
        ucfirst(str_replace('-', ' ', $status))
    );
}

include 'templates/footer.php'; 
?>
