<?php
require_once 'includes/init.php';
requireLogin();

if (!hasAnyPermission(['quotes.create', 'quotes.send', 'quotes.convert'])) {
    http_response_code(403);
    die('Access denied. You do not have permission to view quotations.');
}

$db = getDB();
$quoteId = (int)($_GET['id'] ?? 0);

if ($quoteId <= 0) {
    http_response_code(400);
    die('Invalid quote ID.');
}

if (!_logTableExists('quotes') || !_logTableExists('quote_items')) {
    http_response_code(500);
    die('Quote tables are missing. Run quote migrations first.');
}

$userNameExpr = 'u.username';
if (tableColumnExists('users', 'full_name')) {
    $userNameExpr = "COALESCE(NULLIF(u.full_name, ''), u.username)";
}

$quote = $db->fetchOne(
    "SELECT
        q.*,
        c.first_name,
        c.last_name,
        c.email,
        c.phone,
        {$userNameExpr} AS created_by_name
     FROM quotes q
     LEFT JOIN customers c ON c.id = q.customer_id
     LEFT JOIN users u ON u.id = q.created_by
     WHERE q.id = ?",
    [$quoteId]
);

if (!$quote) {
    http_response_code(404);
    die('Quote not found.');
}

$items = $db->fetchAll(
    "SELECT qi.*, p.name, p.sku
     FROM quote_items qi
     LEFT JOIN products p ON p.id = qi.product_id
     WHERE qi.quote_id = ?
     ORDER BY qi.id ASC",
    [$quoteId]
);

$customerName = trim(((string)($quote['first_name'] ?? '')) . ' ' . ((string)($quote['last_name'] ?? '')));
if ($customerName === '') {
    $customerName = 'Guest';
}

if (strtolower((string)($_GET['format'] ?? '')) === 'pdf') {
    if (file_exists(APP_ROOT . '/vendor/autoload.php')) {
        require_once APP_ROOT . '/vendor/autoload.php';
    }
    if (class_exists('\Dompdf\Dompdf')) {
        $dompdf = new \Dompdf\Dompdf();
        ob_start();
        ?>
        <h2><?php echo escape(APP_NAME); ?> - Quotation</h2>
        <p><strong>Quote #:</strong> <?php echo escape($quote['quote_number']); ?></p>
        <p><strong>Customer:</strong> <?php echo escape($customerName ?? 'Guest'); ?></p>
        <table border="1" cellpadding="6" cellspacing="0" width="100%">
            <thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo escape((string)($item['name'] ?? ('Product #' . (int)$item['product_id']))); ?></td>
                    <td><?php echo escape((string)($item['sku'] ?? '')); ?></td>
                    <td><?php echo (int)$item['quantity']; ?></td>
                    <td><?php echo number_format((float)$item['unit_price'], 2); ?></td>
                    <td><?php echo number_format((float)$item['line_total'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><strong>Total:</strong> <?php echo number_format((float)$quote['total_amount'], 2); ?></p>
        <?php
        $pdfHtml = ob_get_clean();
        $dompdf->loadHtml($pdfHtml);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9\-_]/', '_', (string)$quote['quote_number']) . '.pdf"');
        echo $dompdf->output();
        exit;
    }

    http_response_code(501);
    die('PDF generation is not configured. Install Dompdf in vendor/autoload.php to enable.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo escape(APP_NAME); ?> | Quote <?php echo escape($quote['quote_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
        .quote-sheet {
            max-width: 900px;
            margin: 24px auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 8px 28px rgba(2, 6, 23, 0.06);
        }
        .quote-sheet .table th, .quote-sheet .table td { vertical-align: middle; }
        .monospace { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
        @media print {
            body { background: #fff; }
            .quote-sheet { border: none; box-shadow: none; margin: 0; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="quote-sheet">
    <div class="p-4 border-bottom">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h4 class="mb-1"><?php echo escape(APP_NAME); ?> Quotation</h4>
                <div class="text-muted small">Quote details and itemized estimate</div>
            </div>
            <div class="text-end">
                <div class="small text-muted">Quote #</div>
                <div class="fw-semibold monospace"><?php echo escape($quote['quote_number']); ?></div>
                <span class="badge bg-info text-dark mt-1"><?php echo escape(strtoupper((string)$quote['status'])); ?></span>
            </div>
        </div>
    </div>

    <div class="p-4 border-bottom">
        <div class="row g-3 small">
            <div class="col-md-6">
                <div class="text-muted">Customer</div>
                <div class="fw-semibold"><?php echo escape($customerName); ?></div>
                <?php if (!empty($quote['email'])): ?><div><?php echo escape($quote['email']); ?></div><?php endif; ?>
                <?php if (!empty($quote['phone'])): ?><div><?php echo escape($quote['phone']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6 text-md-end">
                <div><span class="text-muted">Created:</span> <?php echo escape(formatDateTime($quote['created_at'])); ?></div>
                <div><span class="text-muted">Valid Until:</span> <?php echo escape((string)($quote['valid_until'] ?: '-')); ?></div>
                <div><span class="text-muted">Prepared By:</span> <?php echo escape((string)($quote['created_by_name'] ?? '-')); ?></div>
            </div>
        </div>
    </div>

    <div class="p-4">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th>SKU</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Line Total</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No quote items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo escape((string)($item['name'] ?? ('Product #' . (int)$item['product_id']))); ?></td>
                            <td class="text-muted"><?php echo escape((string)($item['sku'] ?? '')); ?></td>
                            <td class="text-end"><?php echo (int)$item['quantity']; ?></td>
                            <td class="text-end"><?php echo number_format((float)$item['unit_price'], 2); ?></td>
                            <td class="text-end fw-semibold"><?php echo number_format((float)$item['line_total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="row justify-content-end">
            <div class="col-md-5">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Subtotal</td><td class="text-end"><?php echo number_format((float)$quote['subtotal'], 2); ?></td></tr>
                    <tr><td class="text-muted">Tax</td><td class="text-end"><?php echo number_format((float)$quote['tax_amount'], 2); ?></td></tr>
                    <tr><td class="text-muted">Discount</td><td class="text-end"><?php echo number_format((float)$quote['discount_amount'], 2); ?></td></tr>
                    <tr class="table-light"><td class="fw-semibold">Total</td><td class="text-end fw-bold"><?php echo number_format((float)$quote['total_amount'], 2); ?></td></tr>
                </table>
            </div>
        </div>

        <?php if (!empty($quote['notes'])): ?>
            <div class="mt-3 small">
                <div class="text-muted mb-1">Notes</div>
                <div class="border rounded p-2 bg-light"><?php echo nl2br(escape((string)$quote['notes'])); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="p-3 border-top d-flex justify-content-end gap-2 no-print">
        <a href="<?php echo escape(getBaseUrl()); ?>/pos.php" class="btn btn-outline-secondary btn-sm">Back to POS</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print Quote</button>
    </div>
</div>
</body>
</html>
